<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Monitoring\MonitorService;
use App\Domain\Monitoring\Agent\MetricThresholdRule;
use App\Domain\Monitoring\Agent\MissingPolicy;
use App\Domain\Monitoring\Agent\RuleOperator;
use App\Domain\Monitoring\Agent\RuleSeverity;
use App\Domain\Monitoring\MonitorKind;
use App\Http\Controllers\Concerns\EnsuresWriteRole;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\AgentMetricRule;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Operator API for `agent` monitors (agent-ingestion addendum §8): latest
 * metric values (any member) and threshold rules (owners and admins write).
 * Every route answers 404 while AGENT_INGEST_ENABLED is off.
 */
class AgentMonitorController extends Controller
{
    use EnsuresWriteRole;

    public function __construct(private readonly MonitorService $monitors)
    {
    }

    public function metrics(Request $request, string $tenantId, string $environmentId, string $monitorId): JsonResponse
    {
        $monitor = $this->agentMonitor($request, $monitorId);
        $perPage = max(1, min((int) $request->query('per_page', 50), 200));
        $search = trim((string) $request->query('search', ''));

        $page = DB::table('agent_metric_latest')
            ->where('monitor_id', $monitor->id)
            // Explicit ESCAPE: SQLite has no default LIKE escape character, MySQL's backslash default differs.
            ->when($search !== '', fn ($q) => $q->whereRaw("metric_key LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%']))
            ->orderBy('metric_key')
            ->paginate($perPage, ['metric_key', 'value', 'sampled_at', 'received_at']);

        $now = now();

        return response()->json([
            'data' => collect($page->items())->map(fn (object $row): array => [
                'metric_key' => $row->metric_key,
                'value' => (float) $row->value,
                'sampled_at' => \App\Http\Support\ApiDate::format($row->sampled_at),
                'received_at' => \App\Http\Support\ApiDate::format($row->received_at),
                'age_seconds' => max(0, $now->getTimestamp() - strtotime((string) $row->received_at.' UTC')),
            ])->all(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'last_ingest_at' => \App\Http\Support\ApiDate::format($monitor->last_ingest_at),
                'max_metrics' => (int) config('agent.max_metrics_per_monitor', 200),
            ],
        ]);
    }

    public function rules(Request $request, string $tenantId, string $environmentId, string $monitorId): JsonResponse
    {
        $monitor = $this->agentMonitor($request, $monitorId);

        return response()->json([
            'data' => AgentMetricRule::query()->where('monitor_id', $monitor->id)->orderBy('id')->get()->map(fn (AgentMetricRule $r) => $this->present($r))->all(),
        ]);
    }

    public function storeRule(Request $request, string $tenantId, string $environmentId, string $monitorId): JsonResponse
    {
        $monitor = $this->agentMonitor($request, $monitorId);
        $this->ensureCanWrite($request);
        $data = $this->validateRule($request, true);

        if (AgentMetricRule::query()->where('monitor_id', $monitor->id)->count() >= (int) config('agent.max_rules_per_monitor', 50)) {
            throw ValidationException::withMessages(['metric_key' => [__('messages.agent_rule_limit')]]);
        }

        $rule = AgentMetricRule::query()->create([
            'tenant_id' => $monitor->tenant_id,
            'monitor_id' => $monitor->id,
            'severity' => 'down',
            'missing_policy' => 'ignore',
            ...$data,
        ]);
        $this->touch($monitor);

        return response()->json(['data' => $this->present($rule)], 201);
    }

    public function updateRule(Request $request, string $tenantId, string $environmentId, string $monitorId, string $ruleId): JsonResponse
    {
        $monitor = $this->agentMonitor($request, $monitorId);
        $this->ensureCanWrite($request);
        $rule = $this->rule($monitor, $ruleId);
        $data = $this->validateRule($request, false, $rule);

        $rule->fill($data)->save();
        $this->touch($monitor);

        return response()->json(['data' => $this->present($rule->fresh())]);
    }

    public function destroyRule(Request $request, string $tenantId, string $environmentId, string $monitorId, string $ruleId): JsonResponse
    {
        $monitor = $this->agentMonitor($request, $monitorId);
        $this->ensureCanWrite($request);
        $this->rule($monitor, $ruleId)->delete();
        $this->touch($monitor);

        return response()->json(null, 204);
    }

    private function validateRule(Request $request, bool $creating, ?AgentMetricRule $existing = null): array
    {
        $presence = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'metric_key' => [$presence, 'string', 'max:'.(int) config('agent.metric_key_max_length', 255)],
            'operator' => [$presence, Rule::enum(RuleOperator::class)],
            'expected' => [$presence, 'string', 'max:64'],
            'severity' => ['sometimes', Rule::enum(RuleSeverity::class)],
            'missing_policy' => ['sometimes', Rule::enum(MissingPolicy::class)],
        ]);

        $operator = RuleOperator::from($data['operator'] ?? $existing?->operator);
        $expected = (string) ($data['expected'] ?? $existing?->expected);
        if (! MetricThresholdRule::isValidExpectation($operator, $expected)) {
            throw ValidationException::withMessages(['expected' => [$operator === RuleOperator::BETWEEN ? __('messages.agent_rule_range') : __('messages.agent_rule_number')]]);
        }

        return $data;
    }

    private function agentMonitor(Request $request, string $monitorId): Monitor
    {
        abort_unless(config('agent.enabled'), 404);
        $monitor = $this->monitors->getMonitor($request->attributes->get('tenant'), $request->attributes->get('environment'), $monitorId);
        abort_unless($monitor->kind === MonitorKind::AGENT, 404);

        return $monitor;
    }

    private function rule(Monitor $monitor, string $ruleId): AgentMetricRule
    {
        return AgentMetricRule::query()->where('monitor_id', $monitor->id)->where('public_id', $ruleId)->firstOr(fn () => abort(404));
    }

    /**
     * Rule changes take effect on the next tick; make the monitor due now.
     */
    private function touch(Monitor $monitor): void
    {
        DB::table('monitors')->where('id', $monitor->id)->update(['next_check_at' => now(), 'updated_at' => now()]);
    }

    private function present(AgentMetricRule $rule): array
    {
        return [
            'id' => $rule->public_id,
            'metric_key' => $rule->metric_key,
            'operator' => $rule->operator,
            'expected' => $rule->expected,
            'severity' => $rule->severity,
            'missing_policy' => $rule->missing_policy,
            'updated_at' => \App\Http\Support\ApiDate::format($rule->updated_at),
        ];
    }
}
