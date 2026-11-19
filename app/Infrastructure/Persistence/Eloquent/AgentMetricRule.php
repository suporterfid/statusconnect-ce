<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Monitoring\Agent\MetricThresholdRule;
use App\Domain\Monitoring\Agent\MissingPolicy;
use App\Domain\Monitoring\Agent\RuleOperator;
use App\Domain\Monitoring\Agent\RuleSeverity;
use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class AgentMetricRule extends Model
{
    use HasPublicId;

    protected $table = 'agent_metric_rules';

    protected $fillable = ['public_id', 'tenant_id', 'monitor_id', 'metric_key', 'operator', 'expected', 'severity', 'missing_policy'];

    protected function publicIdPrefix(): string
    {
        return 'amr_';
    }

    public function toDomain(): MetricThresholdRule
    {
        return new MetricThresholdRule(
            $this->metric_key,
            RuleOperator::from($this->operator),
            $this->expected,
            RuleSeverity::from($this->severity),
            MissingPolicy::from($this->missing_policy),
        );
    }
}
