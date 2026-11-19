<template>
  <div class="monitor-detail-page" v-if="monitor">
    <div class="page-header">
      <div>
        <div class="header-title-row">
          <h1 class="page-title">{{ monitor.name }}</h1>
          <StatusBadge :state="monitor.current_state" />
        </div>
        <p v-if="monitor.kind !== 'agent'" class="target-sub"><code>{{ monitor.target }}</code></p>
        <p v-else class="target-sub">{{ t('monitors.kind_agent') }} · {{ monitor.interval_seconds }}s</p>
      </div>
      <div class="header-actions">
        <router-link v-if="canWrite" :to="`/monitors/${monitor.public_id}/edit`" class="btn btn-secondary">
          Edit
        </router-link>
      </div>
    </div>

    <!-- Heartbeat Token Banner if kind === heartbeat -->
    <div v-if="monitor.kind === 'heartbeat'" class="card token-card">
      <div class="token-header">
        <h4>{{ t('monitors.heartbeat_token') }}</h4>
        <button v-if="canWrite" class="btn btn-secondary btn-sm" @click="handleRotateToken">
          {{ t('monitors.rotate_token') }}
        </button>
      </div>
      <div class="token-body">
        <code class="token-code">{{ pingUrl }}</code>
        <button class="btn btn-primary btn-sm" @click="copyPingUrl">Copy URL</button>
      </div>
    </div>

    <AgentMonitorPanel v-if="monitor.kind === 'agent'" :monitor="monitor" />

    <!-- Interval Observed vs Configured & Latency Chart -->
    <div v-if="hasNetworkResult" class="grid-cols-2 chart-grid">
      <div class="card metric-box">
        <h4>Configured Interval vs Observed Execution</h4>
        <div class="interval-comparison">
          <div class="interval-item">
            <span class="label">Configured:</span>
            <span class="val">{{ monitor.interval_seconds }}s</span>
          </div>
          <div class="interval-item">
            <span class="label">Observed Average Latency:</span>
            <span class="val text-success">{{ observedAverageLatency !== null ? `${observedAverageLatency} ms` : '—' }}</span>
          </div>
        </div>
      </div>
      <div class="card chart-box">
        <h4>Latency Timeline (recent checks)</h4>
        <LatencyChart v-if="latencyData.length > 0" :data="latencyData" />
        <p v-else class="text-muted">No check results yet.</p>
      </div>
    </div>

    <!-- Check Results History -->
    <div class="card table-card">
      <div class="card-header">
        <h3>Recent Check Results History</h3>
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>Status</th>
              <th v-if="hasNetworkResult">Latency</th>
              <th v-if="hasNetworkResult">HTTP / Result Code</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in monitorsStore.checkResults" :key="r.public_id">
              <td>{{ r.checked_at }}</td>
              <td><StatusBadge :state="r.state" /></td>
              <td v-if="hasNetworkResult">{{ r.latency_ms }} ms</td>
              <td v-if="hasNetworkResult"><code>{{ r.status_code || '—' }}</code></td>
              <td class="text-muted">
                {{ r.failure_reason || 'OK' }}
                <div v-if="r.location" class="confirmation-note">{{ t('monitors.checked_from', { location: r.location }) }}</div>
                <div v-if="r.confirmation_state" class="confirmation-note">{{ confirmationLabel(r) }}</div>
              </td>
            </tr>
            <tr v-if="monitorsStore.checkResults.length === 0">
              <td :colspan="hasNetworkResult ? 5 : 3" class="text-center text-muted">No check results yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div v-else class="loading-state card">
    Loading monitor details...
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useTenantStore } from '../stores/tenant';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import StatusBadge from '../components/StatusBadge.vue';
import LatencyChart from '../components/LatencyChart.vue';
import AgentMonitorPanel from '../components/AgentMonitorPanel.vue';
import { useMonitorsStore } from '../stores/monitors';
import { useUiStore } from '../stores/ui';

// Viewers are read-only (#160).
const canWrite = computed(() => useTenantStore().canWrite);

const { t } = useI18n();
const route = useRoute();
const monitorsStore = useMonitorsStore();
const uiStore = useUiStore();

const monitor = computed(() => monitorsStore.currentMonitor || monitorsStore.monitors.find(m => m.public_id === route.params.id));

// Heartbeat and agent checks are database comparisons: their stored latency
// and status code are placeholders, so the chart and history leave them out.
const hasNetworkResult = computed(() => !['agent', 'heartbeat'].includes(monitor.value?.kind));

// Second-location confirmation (#128): what another location saw before an incident opened.
function confirmationLabel(r) {
  const where = r.confirmation_location ? ` (${r.confirmation_location})` : '';
  switch (r.confirmation_state) {
    case 'confirmed': return `Failure confirmed from a second location${where}.`;
    case 'not_confirmed': return `Not confirmed: up from a second location${where}. No incident opened.`;
    case 'unavailable': return 'Second location did not answer; incident opened without confirmation.';
    default: return '';
  }
}
// checkResults come back newest-first from the API; the chart reads left-to-right oldest-first.
const latencyData = computed(() => [...monitorsStore.checkResults].reverse().map(r => r.latency_ms));

const observedAverageLatency = computed(() => {
  if (monitorsStore.checkResults.length === 0) return null;
  const sum = monitorsStore.checkResults.reduce((acc, r) => acc + (r.latency_ms || 0), 0);
  return Math.round(sum / monitorsStore.checkResults.length);
});

const pingUrl = computed(() => {
  if (!monitor.value || !monitor.value.heartbeat_token) return '';
  return `${window.location.origin}/ping/${monitor.value.heartbeat_token}`;
});

async function handleRotateToken() {
  if (await uiStore.confirm('Are you sure you want to rotate this heartbeat token? Existing pings will fail until updated.')) {
    const tenantId = localStorage.getItem('current_tenant_id');
    const envId = localStorage.getItem('current_environment_id');
    await monitorsStore.rotateHeartbeatToken(tenantId, envId, route.params.id);
  }
}

function copyPingUrl() {
  navigator.clipboard.writeText(pingUrl.value);
  uiStore.notify('Heartbeat ping URL copied to clipboard!', 'success');
}

onMounted(async () => {
  const tenantId = localStorage.getItem('current_tenant_id');
  const envId = localStorage.getItem('current_environment_id');
  await monitorsStore.fetchMonitor(tenantId, envId, route.params.id);
  await monitorsStore.fetchCheckResults(tenantId, envId, route.params.id);
});
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
}
.header-title-row {
  display: flex;
  align-items: center;
  gap: 1rem;
}
.target-sub {
  color: var(--text-secondary);
  font-size: 0.85rem;
  margin-top: 0.25rem;
}
.token-card {
  margin-bottom: 1.5rem;
  background: var(--accent-soft);
  border-color: var(--accent-primary);
}
.token-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.75rem;
}
.token-body {
  display: flex;
  gap: 1rem;
  align-items: center;
}
.token-code {
  background: var(--bg-primary); border: 1px solid var(--border-color);
  padding: 0.5rem 1rem;
  border-radius: var(--radius-sm);
  font-size: 0.9rem;
  flex: 1;
}
.chart-grid {
  margin-bottom: 1.5rem;
}
.interval-comparison {
  display: flex;
  gap: 2rem;
  margin-top: 1rem;
}
.interval-item {
  display: flex;
  flex-direction: column;
}
.interval-item .label {
  font-size: 0.8rem;
  color: var(--text-secondary);
}
.interval-item .val {
  font-size: 1.5rem;
  font-weight: 700;
}
.table-card {
  padding: 0;
}
.card-header {
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid var(--border-color);
}
.btn-sm {
  padding: 0.25rem 0.6rem;
  font-size: 0.75rem;
}
.confirmation-note { margin-top: 4px; font-size: .85em; }
</style>
