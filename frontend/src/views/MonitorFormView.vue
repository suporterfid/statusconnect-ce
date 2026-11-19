<template>
  <div class="monitor-form-page">
    <div class="page-header">
      <h1 class="page-title">{{ isEdit ? t('monitors.edit_monitor') : t('monitors.new_monitor') }}</h1>
    </div>

    <div class="card form-card">
      <form @submit.prevent="handleSubmit">
        <div class="form-group">
          <label class="form-label">{{ t('monitors.name') }}</label>
          <input type="text" v-model="form.name" required class="form-control" placeholder="Production API Gateway" />
        </div>

        <div class="grid-cols-2">
          <div class="form-group">
            <label class="form-label">{{ t('monitors.kind') }}</label>
            <select v-model="form.kind" class="form-control">
              <option value="http">{{ t('monitors.kind_http') }}</option>
              <option value="tcp">{{ t('monitors.kind_tcp') }}</option>
              <option value="heartbeat">{{ t('monitors.kind_heartbeat') }}</option>
              <option value="mqtt">{{ t('monitors.kind_mqtt') }}</option>
              <option v-if="agentEnabled || form.kind === 'agent'" value="agent" :disabled="isEdit && form.kind !== 'agent'">{{ t('monitors.kind_agent') }}</option>
            </select>
            <p v-if="form.kind === 'agent'" class="form-hint">{{ t('monitors.kind_agent_hint') }}</p>
          </div>

          <div class="form-group" v-if="form.kind === 'agent'">
            <label class="form-label">{{ t('monitors.agent_interval') }}</label>
            <input type="number" v-model.number="form.interval_seconds" required class="form-control" :min="Math.max(60, minInterval)" max="86400" />
            <p v-if="planHint" class="form-hint">{{ planHint }}</p>
          </div>
          <div class="form-group" v-else-if="form.kind !== 'heartbeat'">
            <label class="form-label">{{ t('monitors.check_interval') }}</label>
            <input type="number" v-model.number="form.interval_seconds" required class="form-control" :min="Math.max(5, minInterval)" max="3600" />
            <p v-if="planHint" class="form-hint">{{ planHint }}</p>
          </div>
          <div class="form-group" v-else>
            <label class="form-label">{{ t('monitors.heartbeat_interval') }}</label>
            <input type="number" v-model.number="form.interval_seconds" required class="form-control" :min="Math.max(10, minInterval)" max="86400" />
            <p v-if="planHint" class="form-hint">{{ planHint }}</p>
          </div>
        </div>

        <div class="form-group" v-if="form.kind === 'agent'">
          <label class="form-label">{{ t('monitors.agent_grace') }}</label>
          <input type="number" v-model.number="form.agent_grace_seconds" required class="form-control" min="0" max="86400" />
        </div>

        <div class="form-group" v-if="form.kind === 'agent'">
          <label class="form-label" for="agent-latency-metric">{{ t('monitors.agent_latency_metric') }}</label>
          <input id="agent-latency-metric" type="text" v-model="form.agent_latency_metric_key" class="form-control" maxlength="255" list="agent-latency-metric-keys" placeholder="app_latency.query_p95_ms{host=acme}" autocomplete="off" />
          <datalist id="agent-latency-metric-keys">
            <option v-for="key in metricKeys" :key="key" :value="key" />
          </datalist>
          <p class="form-hint">{{ t('monitors.agent_latency_metric_hint') }}</p>
        </div>

        <div class="form-group" v-if="!['heartbeat', 'agent', 'mqtt'].includes(form.kind)">
          <label class="form-label">{{ t('monitors.target') }}</label>
          <input type="url" v-model="form.target" required class="form-control" placeholder="https://api.example.com/health" />
        </div>

        <template v-if="form.kind === 'mqtt'">
          <div class="form-group">
            <label class="form-label">{{ t('monitors.mqtt_target') }}</label>
            <input type="text" v-model="form.target" required class="form-control" placeholder="mqtts://broker.example.com:8883" pattern="mqtts?://.+" />
            <p class="form-hint">{{ t('monitors.mqtt_target_hint') }}</p>
          </div>
          <div class="grid-cols-2">
            <div class="form-group">
              <label class="form-label">{{ t('monitors.mqtt_username') }}</label>
              <input type="text" v-model="form.mqtt_username" class="form-control" autocomplete="off" />
            </div>
            <div class="form-group">
              <label class="form-label">{{ t('monitors.mqtt_password') }}</label>
              <input type="password" v-model="form.mqtt_password" class="form-control" autocomplete="new-password"
                :placeholder="passwordSet ? t('monitors.mqtt_password_kept') : ''" />
            </div>
          </div>
          <div class="grid-cols-2">
            <div class="form-group">
              <label class="form-label">{{ t('monitors.mqtt_client_id') }}</label>
              <input type="text" v-model="form.mqtt_client_id" class="form-control" maxlength="23" pattern="[A-Za-z0-9_-]{1,23}" />
              <p class="form-hint">{{ t('monitors.mqtt_client_id_hint') }}</p>
            </div>
            <div class="form-group">
              <label class="form-label checkbox-label">
                <input type="checkbox" v-model="form.verify_tls" /> {{ t('monitors.verify_tls') }}
              </label>
              <p class="form-hint">{{ t('monitors.mqtt_verify_hint') }}</p>
            </div>
          </div>
        </template>

        <div class="form-group" v-if="!['heartbeat', 'agent'].includes(form.kind)">
          <label class="form-label">{{ t('monitors.egress_profile') }}</label>
          <select v-model="form.egress_profile" class="form-control">
            <option value="public-crawl">{{ t('monitors.egress_public') }}</option>
            <option value="api">{{ t('monitors.egress_api') }}</option>
            <option value="internal">{{ t('monitors.egress_internal') }}</option>
          </select>
          <p class="form-hint">{{ t('monitors.egress_hint') }}</p>
        </div>

        <!-- Probe region (#222): only when checks run on the probe, there is a choice, and the plan allows it. -->
        <div class="form-group" v-if="showRegion">
          <label class="form-label">{{ t('monitors.check_region') }}</label>
          <select v-model="form.check_region" class="form-control">
            <option v-for="region in regions" :key="region.key" :value="region.default ? '' : region.key">
              {{ region.default ? t('monitors.check_region_default', { region: region.label }) : region.label }}
            </option>
          </select>
          <p class="form-hint">{{ t('monitors.check_region_hint') }}</p>
        </div>

        <div class="form-group" v-if="form.kind === 'heartbeat'">
          <label class="form-label">{{ t('monitors.heartbeat_grace') }}</label>
          <input type="number" v-model.number="form.heartbeat_grace_seconds" required class="form-control" min="0" max="3600" />
        </div>

        <div class="form-group">
          <label class="form-label">{{ t('monitors.confirmation_threshold') }}</label>
          <input type="number" v-model.number="form.confirmation_threshold" required class="form-control" min="1" max="10" />
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary" :disabled="loading">
            {{ loading ? t('monitors.saving') : t('monitors.save') }}
          </button>
          <router-link to="/monitors" class="btn btn-secondary">{{ t('monitors.cancel') }}</router-link>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useMonitorsStore } from '../stores/monitors';
import { useUiStore } from '../stores/ui';
import { useAuthStore } from '../stores/auth';
import { apiRequest } from '../api/client';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const monitorsStore = useMonitorsStore();
const uiStore = useUiStore();

const isEdit = computed(() => !!route.params.id);
const agentEnabled = computed(() => !!useAuthStore().features?.agent_ingest);
const loading = ref(false);
// Smallest interval the organization's plan allows (#179); 0 until the plan loads.
const minInterval = ref(0);
const planName = ref('');
const planHint = computed(() => (minInterval.value > 60
  ? t('monitors.interval_floor_hint', { plan: planName.value, seconds: minInterval.value })
  : ''));
// Probe regions (#222), from the plan endpoint.
const regions = ref([]);
const regionsSelectable = ref(false);
const showRegion = computed(() => form.value.kind === 'http' && regionsSelectable.value);

const form = ref({
  name: '',
  kind: 'http',
  target: 'https://api.example.com/health',
  egress_profile: 'public-crawl',
  interval_seconds: 60,
  heartbeat_grace_seconds: 120,
  agent_grace_seconds: 180,
  agent_latency_metric_key: '',
  confirmation_threshold: 2,
  mqtt_username: '',
  mqtt_password: '',
  mqtt_client_id: '',
  verify_tls: true,
  check_region: '',
});
// An MQTT password is never sent back by the API; empty on save keeps it (#200).
const passwordSet = ref(false);

// Latest metric keys of an agent monitor, offered as latency metric suggestions (#242).
const metricKeys = ref([]);
async function loadMetricKeys(tenantId, envId, monitorId) {
  try {
    const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${envId}/monitors/${monitorId}/agent-metrics?per_page=200`);
    metricKeys.value = (res?.data ?? []).map((m) => m.metric_key);
  } catch {
    // Suggestions only: the field still takes any key.
  }
}

async function loadPlanFloor(tenantId) {
  // Only editions with the Plan endpoint (#250); the API enforces the plan either way.
  if (!useAuthStore().features?.plans) return;
  try {
    const res = await apiRequest(`/v1/tenants/${tenantId}/plan`);
    const plan = res?.data?.plan ?? res?.plan;
    minInterval.value = Number(plan?.min_interval_seconds) || 0;
    planName.value = plan?.name ?? '';
    const checkRegions = res?.data?.check_regions;
    regions.value = Array.isArray(checkRegions?.regions) ? checkRegions.regions : [];
    regionsSelectable.value = checkRegions?.execution === 'probe' && regions.value.length > 1 && !!plan?.check_regions;
  } catch {
    // Without the plan the API still enforces the floor and explains it on save.
  }
}

onMounted(async () => {
  const tenantId = localStorage.getItem('current_tenant_id');
  if (!isEdit.value) {
    await loadPlanFloor(tenantId);
    form.value.interval_seconds = Math.max(form.value.interval_seconds, minInterval.value);
    return;
  }
  loadPlanFloor(tenantId);

  const envId = localStorage.getItem('current_environment_id');
  loading.value = true;
  try {
    const monitor = await monitorsStore.fetchMonitor(tenantId, envId, route.params.id);
    if (monitor) {
      form.value = {
        name: monitor.name,
        kind: monitor.kind,
        target: monitor.target,
        egress_profile: monitor.egress_profile || 'public-crawl',
        interval_seconds: monitor.interval_seconds,
        heartbeat_grace_seconds: monitor.heartbeat_grace_seconds,
        agent_grace_seconds: monitor.agent_grace_seconds ?? 180,
        agent_latency_metric_key: monitor.agent_latency_metric_key ?? '',
        confirmation_threshold: monitor.confirmation_threshold,
        mqtt_username: monitor.mqtt_username ?? '',
        mqtt_password: '',
        mqtt_client_id: monitor.mqtt_client_id ?? '',
        verify_tls: monitor.verify_tls ?? true,
        check_region: monitor.check_region ?? '',
      };
      passwordSet.value = !!monitor.mqtt_password_set;
      if (monitor.kind === 'agent') loadMetricKeys(tenantId, envId, monitor.public_id ?? route.params.id);
    }
  } finally {
    loading.value = false;
  }
});

async function handleSubmit() {
  loading.value = true;
  try {
    const tenantId = localStorage.getItem('current_tenant_id');
    const envId = localStorage.getItem('current_environment_id');
    
    const payload = { ...form.value };
    // The region is sent only when the form offers it; '' is the default region.
    if (showRegion.value) {
      payload.check_region = payload.check_region || null;
    } else {
      delete payload.check_region;
    }
    if (payload.kind === 'agent') {
      delete payload.target;
      delete payload.egress_profile;
      payload.agent_latency_metric_key = payload.agent_latency_metric_key?.trim() || null;
    } else {
      delete payload.agent_latency_metric_key;
    }
    if (payload.kind !== 'mqtt') {
      for (const key of ['mqtt_username', 'mqtt_password', 'mqtt_client_id']) delete payload[key];
    } else {
      if (!payload.mqtt_password) delete payload.mqtt_password;
      if (!payload.mqtt_client_id) delete payload.mqtt_client_id;
    }
    if (isEdit.value) {
      await monitorsStore.updateMonitor(tenantId, envId, route.params.id, payload);
      router.push('/monitors');
    } else {
      const created = await monitorsStore.createMonitor(tenantId, envId, payload);
      // Agent monitors open on their page, where the one-time token and Telegraf snippet are shown.
      router.push(payload.kind === 'agent' && created?.public_id ? `/monitors/${created.public_id}` : '/monitors');
    }
  } catch (e) {
    uiStore.notify(e.message, 'error');
  } finally {
    loading.value = false;
  }
}
</script>

<style scoped>
.page-header {
  margin-bottom: 1.5rem;
}
.form-card {
  max-width: 700px;
}
.form-actions {
  display: flex;
  gap: 1rem;
  margin-top: 1.5rem;
}
.checkbox-label {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-top: 1.8rem;
}
.form-hint {
  font-size: 0.75rem;
  color: var(--text-secondary);
  margin-top: 0.35rem;
}
</style>
