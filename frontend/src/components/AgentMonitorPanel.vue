<template>
  <div class="agent-panel">
    <div v-if="issued" class="card token-card" role="region" :aria-label="t('agents.token_title')">
      <div class="panel-header">
        <h3>{{ t('agents.token_title') }}</h3>
        <button class="btn btn-secondary btn-sm" @click="agents.issuedToken = null">{{ t('agents.token_hide') }}</button>
      </div>
      <p class="text-muted hint">{{ t('agents.token_once') }}</p>
      <div class="token-row">
        <code class="token-code">{{ issued.ingestUrl }}</code>
        <button class="btn btn-primary btn-sm" @click="copy(issued.ingestUrl)">{{ t('agents.copy') }}</button>
      </div>
      <div class="snippet-tabs" role="tablist">
        <button v-for="kind in ['telegraf', 'otel']" :key="kind" type="button" role="tab" class="btn btn-sm"
          :class="snippetKind === kind ? 'btn-primary' : 'btn-secondary'" :aria-selected="snippetKind === kind"
          @click="snippetKind = kind">{{ t(`agents.snippet_${kind}`) }}</button>
      </div>
      <h4 class="snippet-title">{{ snippetKind === 'otel' ? 'otelcol config.yaml' : 'telegraf.conf' }}</h4>
      <pre class="snippet"><code>{{ snippet }}</code></pre>
      <button class="btn btn-secondary btn-sm" @click="copy(snippet)">{{ t('agents.copy_snippet') }}</button>
    </div>

    <div class="card section">
      <div class="panel-header">
        <div>
          <h3>{{ t('agents.metrics_title') }}</h3>
          <p class="text-muted hint">
            <template v-if="agents.metricsMeta?.last_ingest_at">{{ t('agents.last_ingest', { at: formatDate(agents.metricsMeta.last_ingest_at) }) }} · </template>
            {{ t('agents.metric_count', { count: agents.metricsMeta?.total ?? 0, max: agents.metricsMeta?.max_metrics ?? 0 }) }}
          </p>
        </div>
        <div class="actions">
          <input v-model="search" type="search" class="form-control search" :placeholder="t('agents.search')" :aria-label="t('agents.search')" @input="debouncedLoad" />
          <button v-if="canWrite" class="btn btn-secondary btn-sm" @click="handleRotate">{{ t('agents.rotate') }}</button>
        </div>
      </div>
      <div v-if="agents.metrics.length === 0" class="empty">
        <p>{{ t('agents.waiting') }}</p>
        <p class="text-muted hint">{{ t('agents.waiting_hint') }}</p>
      </div>
      <div v-else class="table-responsive">
        <table class="data-table">
          <thead>
            <tr><th>{{ t('agents.metric') }}</th><th class="num">{{ t('agents.value') }}</th><th class="num">{{ t('agents.age') }}</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="m in agents.metrics" :key="m.metric_key">
              <td><code class="metric-key">{{ m.metric_key }}</code></td>
              <td class="num">{{ formatValue(m.value) }}</td>
              <td class="num" :class="{ stale: m.age_seconds > staleAfter }">{{ formatAge(m.age_seconds) }}</td>
              <td><button v-if="canWrite" class="btn btn-secondary btn-sm" @click="startRule(m.metric_key)">＋ {{ t('agents.add_rule') }}</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card section">
      <div class="panel-header">
        <div>
          <h3>{{ t('agents.rules_title') }}</h3>
          <p class="text-muted hint">{{ t('agents.rules_hint') }}</p>
        </div>
        <button v-if="!editor && canWrite" class="btn btn-primary btn-sm" @click="startRule('')">＋ {{ t('agents.add_rule') }}</button>
      </div>

      <form v-if="editor" class="editor" @submit.prevent="saveRule">
        <div class="form-group">
          <label class="form-label" for="rule-key">{{ t('agents.metric') }}</label>
          <input id="rule-key" v-model="editor.metric_key" list="agent-metric-keys" required maxlength="255" class="form-control" placeholder="mem.used_percent{host=web1}" />
          <datalist id="agent-metric-keys">
            <option v-for="m in agents.metrics" :key="m.metric_key" :value="m.metric_key" />
          </datalist>
        </div>
        <div class="grid-4">
          <div class="form-group">
            <label class="form-label" for="rule-op">{{ t('agents.operator') }}</label>
            <select id="rule-op" v-model="editor.operator" class="form-control">
              <option v-for="op in operators" :key="op" :value="op">{{ t(`agents.operators.${op}`) }}</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="rule-expected">{{ t('agents.expected') }}</label>
            <input id="rule-expected" v-model="editor.expected" required maxlength="64" class="form-control" :placeholder="editor.operator === 'between' ? '10..20' : '90'" />
          </div>
          <div class="form-group">
            <label class="form-label" for="rule-severity">{{ t('agents.severity') }}</label>
            <select id="rule-severity" v-model="editor.severity" class="form-control">
              <option value="down">{{ t('agents.severities.down') }}</option>
              <option value="degraded">{{ t('agents.severities.degraded') }}</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="rule-missing">{{ t('agents.missing') }}</label>
            <select id="rule-missing" v-model="editor.missing_policy" class="form-control">
              <option value="ignore">{{ t('agents.missing_policies.ignore') }}</option>
              <option value="fail">{{ t('agents.missing_policies.fail') }}</option>
            </select>
          </div>
        </div>
        <div class="actions">
          <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? '...' : t('agents.save_rule') }}</button>
          <button type="button" class="btn btn-secondary" @click="editor = null">{{ t('agents.cancel') }}</button>
        </div>
      </form>

      <ul class="rule-list">
        <li v-for="r in agents.rules" :key="r.id" class="rule-row">
          <div>
            <span class="badge" :class="`badge-${r.severity}`">{{ t(`agents.severities.${r.severity}`) }}</span>
            <code class="metric-key">{{ r.metric_key }}</code>
            <strong>{{ t(`agents.operators.${r.operator}`) }} {{ r.expected }}</strong>
            <span v-if="r.missing_policy === 'fail'" class="text-muted"> · {{ t('agents.missing_policies.fail') }}</span>
          </div>
          <div class="actions">
            <button v-if="canWrite" class="btn btn-secondary btn-sm" @click="editor = { ...r }">{{ t('agents.edit') }}</button>
            <button v-if="canWrite" class="btn btn-danger btn-sm" @click="removeRule(r)">{{ t('agents.delete') }}</button>
          </div>
        </li>
        <li v-if="agents.rules.length === 0" class="text-muted empty">{{ t('agents.no_rules') }}</li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useTenantStore } from '../stores/tenant';
import { useI18n } from 'vue-i18n';
import { useAgentsStore } from '../stores/agents';
import { useUiStore } from '../stores/ui';

// Viewers are read-only (#160).
const canWrite = computed(() => useTenantStore().canWrite);

const props = defineProps({ monitor: { type: Object, required: true } });
const { t } = useI18n();
const agents = useAgentsStore();
const uiStore = useUiStore();
const tenantId = localStorage.getItem('current_tenant_id');
const envId = localStorage.getItem('current_environment_id');

const operators = ['gt', 'gte', 'lt', 'lte', 'between', 'equals'];
const search = ref('');
const editor = ref(null);
const saving = ref(false);
let timer = null;
let debounce = null;

const issued = computed(() => (agents.issuedToken?.monitorId === props.monitor.public_id ? agents.issuedToken : null));
const staleAfter = computed(() => (props.monitor.interval_seconds || 60) + (props.monitor.agent_grace_seconds ?? 180));
const snippetKind = ref('telegraf');
const snippet = computed(() => {
  const url = issued.value?.ingestUrl || '';
  const interval = `${props.monitor.interval_seconds || 60}s`;
  // otlphttp appends /v1/metrics to the endpoint, so the same ingestion URL serves OTLP.
  if (snippetKind.value === 'otel') return `receivers:
  hostmetrics:
    collection_interval: ${interval}
    scrapers:
      cpu:
        metrics:
          system.cpu.time: { enabled: false }
          system.cpu.utilization: { enabled: true }
      memory:
        metrics:
          system.memory.utilization: { enabled: true }
      load:
      filesystem:
        include_mount_points: { mount_points: ["/"], match_type: strict }
        metrics:
          system.filesystem.utilization: { enabled: true }
processors:
  resourcedetection:
    detectors: [system]
exporters:
  otlphttp/statusconnect:
    endpoint: ${url}
    encoding: json
    compression: gzip
service:
  pipelines:
    metrics:
      receivers: [hostmetrics]
      processors: [resourcedetection]
      exporters: [otlphttp/statusconnect]`;
  return `[agent]
  interval = "${interval}"
  flush_interval = "${interval}"

[[inputs.cpu]]
  percpu = false
[[inputs.mem]]
[[inputs.disk]]
  mount_points = ["/"]
[[inputs.system]]

[[outputs.http]]
  url = "${url}"
  method = "POST"
  data_format = "influx"
  content_encoding = "gzip"
  timeout = "10s"
  [outputs.http.headers]
    Content-Type = "text/plain; charset=utf-8"`;
});

async function load() {
  try {
    await Promise.all([
      agents.fetchMetrics(tenantId, envId, props.monitor.public_id, search.value),
      agents.fetchRules(tenantId, envId, props.monitor.public_id),
    ]);
  } catch (e) {
    uiStore.notify(e.message, 'error');
  }
}

function debouncedLoad() {
  clearTimeout(debounce);
  debounce = setTimeout(load, 300);
}

function startRule(key) {
  editor.value = { id: null, metric_key: key, operator: 'gt', expected: '', severity: 'down', missing_policy: 'ignore' };
}

function errorText(e) {
  if (e.status === 403) return t('agents.read_only');
  const fieldErrors = e.data?.errors ? Object.values(e.data.errors).flat() : [];
  return fieldErrors.length ? fieldErrors.join(' ') : e.message;
}

async function saveRule() {
  saving.value = true;
  try {
    await agents.saveRule(tenantId, envId, props.monitor.public_id, editor.value);
    editor.value = null;
    uiStore.notify(t('agents.rule_saved'), 'success');
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  } finally {
    saving.value = false;
  }
}

async function removeRule(rule) {
  if (!(await uiStore.confirm(t('agents.delete_confirm')))) return;
  try {
    await agents.deleteRule(tenantId, envId, props.monitor.public_id, rule.id);
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  }
}

async function handleRotate() {
  if (!(await uiStore.confirm(t('agents.rotate_confirm')))) return;
  try {
    await agents.rotateToken(tenantId, envId, props.monitor.public_id);
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  }
}

function copy(text) {
  navigator.clipboard.writeText(text);
  uiStore.notify(t('agents.copied'), 'success');
}

function formatValue(v) {
  return Number.isInteger(v) ? v.toLocaleString() : Number(v).toLocaleString(undefined, { maximumFractionDigits: 3 });
}

function formatAge(s) {
  if (s < 60) return `${s}s`;
  if (s < 3600) return `${Math.floor(s / 60)} min`;
  return `${Math.floor(s / 3600)} h`;
}

function formatDate(v) {
  return new Date(v).toLocaleString();
}

onMounted(() => {
  load();
  timer = setInterval(load, 30000);
});
onBeforeUnmount(() => {
  clearInterval(timer);
  clearTimeout(debounce);
});
</script>

<style scoped>
.section, .token-card { margin-bottom: 1.5rem; }
.token-card { background: var(--accent-soft); border-color: var(--accent-primary); }
.panel-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.75rem; }
.panel-header h3 { margin: 0; font-size: 1.05rem; }
.hint { font-size: 0.8rem; margin: 0.25rem 0 0; }
.token-row { display: flex; gap: 0.75rem; align-items: center; margin: 0.75rem 0; flex-wrap: wrap; }
.token-code { background: var(--bg-primary); border: 1px solid var(--border-color); padding: 0.5rem 0.75rem; border-radius: var(--radius-sm); font-size: 0.85rem; flex: 1; overflow-wrap: anywhere; }
.snippet-tabs { display: flex; gap: 0.5rem; margin-top: 1rem; }
.snippet-title { margin: 1rem 0 0.5rem; font-size: 0.9rem; }
.snippet { background: var(--bg-primary); border: 1px solid var(--border-color); padding: 0.75rem 1rem; border-radius: var(--radius-sm); font-size: 0.8rem; overflow-x: auto; margin-bottom: 0.5rem; }
.actions { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.search { width: 220px; padding: 0.35rem 0.6rem; font-size: 0.85rem; }
.empty { padding: 1rem 0; text-align: center; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.stale { color: var(--warning); }
.metric-key { font-size: 0.8rem; overflow-wrap: anywhere; }
.editor { border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1rem; margin-bottom: 1rem; }
.grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; }
@media (max-width: 900px) { .grid-4 { grid-template-columns: repeat(2, 1fr); } }
.rule-list { list-style: none; padding: 0; margin: 0; }
.rule-row { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 0.6rem 0; border-top: 1px solid var(--border-color); flex-wrap: wrap; }
.badge { display: inline-block; font-size: 0.7rem; font-weight: 600; padding: 0.1rem 0.5rem; border-radius: 999px; margin-right: 0.4rem; }
.badge-down { background: var(--status-down-bg); color: var(--status-down-fg); }
.badge-degraded { background: var(--status-degraded-bg); color: var(--status-degraded-fg); }
.btn-sm { padding: 0.25rem 0.6rem; font-size: 0.75rem; }
</style>
