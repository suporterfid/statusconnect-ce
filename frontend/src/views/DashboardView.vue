<template>
  <div class="dashboard-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">{{ t('app.dashboard') }}</h1>
        <p class="page-subtitle">{{ t('app.tagline') }}</p>
      </div>
      <div class="header-actions">
        <router-link v-if="canWrite" to="/monitors/create" class="btn btn-primary">
          {{ t('dashboard.create_monitor') }}
        </router-link>
      </div>
    </div>

    <!-- Warnings & Heartbeat Freshness Banner (§8.6, §14.2) -->
    <div v-if="tenantStore.fallingBehind" class="alert alert-warning">
      {{ t('dashboard.checks_falling_behind') }}
    </div>

    <div class="freshness-banner card">
      <div class="freshness-info">
        <span class="freshness-label">{{ t('dashboard.scheduler_freshness') }}:</span>
        <span :class="['freshness-badge', tenantStore.schedulerFreshness]">
          {{ tenantStore.schedulerFreshness === 'healthy' ? t('dashboard.healthy') : t('dashboard.stale') }}
        </span>
      </div>
      <div class="quick-links">
        <router-link to="/monitors" class="btn btn-secondary btn-sm">View All Monitors</router-link>
      </div>
    </div>

    <!-- Metrics Cards Grid -->
    <div class="grid-cols-4 metrics-grid">
      <div class="card metric-card">
        <div class="metric-title">{{ t('dashboard.total_monitors') }}</div>
        <div class="metric-value">{{ monitorsStore.monitors.length }}</div>
      </div>
      <div class="card metric-card">
        <div class="metric-title">{{ t('dashboard.active_incidents') }}</div>
        <div class="metric-value text-danger">{{ openIncidentsCount }}</div>
      </div>
      <div class="card metric-card">
        <div class="metric-title">{{ t('dashboard.overall_uptime') }}</div>
        <div class="metric-value text-success">{{ uptimeDisplay }}</div>
      </div>
      <div class="card metric-card">
        <div class="metric-title">{{ t('dashboard.active_maintenance') }}</div>
        <div class="metric-value text-info">{{ tenantStore.dashboardSummary?.active_maintenance_windows ?? '—' }}</div>
      </div>
    </div>

    <!-- Active Monitors Overview Table -->
    <div class="card table-card">
      <div class="card-header">
        <h3>Recent Monitors</h3>
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>{{ t('monitors.name') }}</th>
              <th>{{ t('monitors.kind') }}</th>
              <th>{{ t('monitors.state') }}</th>
              <th>{{ t('monitors.last_checked') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in monitorsStore.monitors.slice(0, 5)" :key="m.public_id">
              <td>
                <router-link :to="`/monitors/${m.public_id}`" class="monitor-link">{{ m.name }}</router-link>
              </td>
              <td><span class="kind-tag">{{ m.kind }}</span></td>
              <td><StatusBadge :state="m.current_state" /></td>
              <td>{{ m.last_checked_at || 'Just now' }}</td>
            </tr>
            <tr v-if="monitorsStore.monitors.length === 0">
              <td colspan="4" class="text-center text-muted">No monitors created yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import StatusBadge from '../components/StatusBadge.vue';
import { useMonitorsStore } from '../stores/monitors';
import { useIncidentsStore } from '../stores/incidents';
import { useTenantStore } from '../stores/tenant';

// Viewers are read-only (#160).
const canWrite = computed(() => useTenantStore().canWrite);

const { t } = useI18n();
const monitorsStore = useMonitorsStore();
const incidentsStore = useIncidentsStore();
const tenantStore = useTenantStore();

const openIncidentsCount = computed(() => {
  return incidentsStore.incidents.filter(i => !i.resolved_flag).length;
});

const uptimeDisplay = computed(() => {
  const pct = tenantStore.dashboardSummary?.uptime_24h_percent;
  return pct === null || pct === undefined ? 'No data yet' : `${pct}%`;
});

onMounted(() => {
  tenantStore.fetchHealth();

  const tenantId = localStorage.getItem('current_tenant_id');
  const envId = localStorage.getItem('current_environment_id');
  if (tenantId && envId) {
    monitorsStore.fetchMonitors(tenantId, envId);
    incidentsStore.fetchIncidents(tenantId, envId);
    tenantStore.fetchDashboardSummary(tenantId, envId);
  }
});
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
}
.page-title {
  font-size: 1.75rem;
  font-weight: 700;
}
.page-subtitle {
  color: var(--text-secondary);
  font-size: 0.9rem;
}
.freshness-banner {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
  padding: 1rem 1.5rem;
}
.freshness-badge {
  padding: 0.2rem 0.6rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 700;
  margin-left: 0.5rem;
}
.freshness-badge.healthy {
  background: var(--status-up-bg);
  color: var(--status-up);
}
.freshness-badge.stale {
  background: var(--status-down-bg);
  color: var(--status-down);
}
.metrics-grid {
  margin-bottom: 1.5rem;
}
.metric-card {
  display: flex;
  flex-direction: column;
  justify-content: center;
}
.metric-title {
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--text-secondary);
}
.metric-value {
  font-size: 2rem;
  font-weight: 700;
  letter-spacing: -0.02em;
  font-variant-numeric: tabular-nums;
  margin-top: 0.25rem;
}
.text-danger { color: var(--status-down-fg); }
.text-success { color: var(--status-up-fg); }
.text-info { color: var(--status-maintenance); }
.table-card {
  padding: 0;
  overflow: hidden;
}
.card-header {
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid var(--border-color);
}
.monitor-link {
  font-weight: 600;
  color: var(--text-primary);
}
.kind-tag {
  background: var(--bg-hover);
  padding: 0.15rem 0.5rem;
  border-radius: var(--radius-sm);
  font-size: 0.75rem;
  font-family: monospace;
}
.alert-warning {
  background: var(--status-degraded-bg);
  color: var(--status-degraded);
  border: 1px solid var(--status-degraded);
  padding: 1rem;
  border-radius: var(--radius-sm);
  margin-bottom: 1.5rem;
  font-weight: 600;
}
</style>
