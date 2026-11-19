<template>
  <div class="monitor-list-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">{{ t('monitors.title') }}</h1>
      </div>
      <router-link v-if="canWrite" to="/monitors/create" class="btn btn-primary">
        {{ t('monitors.new_monitor') }}
      </router-link>
    </div>

    <!-- Filters & Search Bar -->
    <div class="card filter-card">
      <div class="filter-controls">
        <input type="text" v-model="searchQuery" class="form-control search-input" :placeholder="t('monitors.search_placeholder')" />
        <select v-model="selectedState" class="form-control state-select">
          <option value="all">{{ t('monitors.all_states') }}</option>
          <option value="up">Up</option>
          <option value="degraded">Degraded</option>
          <option value="down">Down</option>
          <option value="blocked">Blocked</option>
          <option value="maintenance">Maintenance</option>
          <option value="paused">Paused</option>
        </select>
      </div>
    </div>

    <!-- Monitor List Table -->
    <div class="card table-card">
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>{{ t('monitors.name') }}</th>
              <th>{{ t('monitors.kind') }}</th>
              <th>{{ t('monitors.target') }}</th>
              <th>{{ t('monitors.state') }}</th>
              <th>{{ t('monitors.interval') }}</th>
              <th>{{ t('monitors.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in filteredMonitors" :key="m.public_id">
              <td>
                <router-link :to="`/monitors/${m.public_id}`" class="monitor-title">{{ m.name }}</router-link>
              </td>
              <td><span class="kind-tag">{{ m.kind }}</span></td>
              <td class="target-cell"><code>{{ m.target }}</code></td>
              <td>
                <StatusBadge :state="m.current_state" />
                <div v-if="m.current_state === 'blocked'" class="blocked-hint">
                  {{ t('monitors.blocked_reason') }}
                </div>
              </td>
              <td>{{ m.interval_seconds }}s</td>
              <td>
                <router-link :to="`/monitors/${m.public_id}`" class="btn btn-secondary btn-sm">View</router-link>
              </td>
            </tr>
            <tr v-if="filteredMonitors.length === 0">
              <td colspan="6" class="text-center text-muted">No monitors found matching your criteria.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useTenantStore } from '../stores/tenant';
import { useI18n } from 'vue-i18n';
import StatusBadge from '../components/StatusBadge.vue';
import { useMonitorsStore } from '../stores/monitors';

// Viewers are read-only (#160).
const canWrite = computed(() => useTenantStore().canWrite);

const { t } = useI18n();
const monitorsStore = useMonitorsStore();

const searchQuery = ref('');
const selectedState = ref('all');

onMounted(() => {
  const tenantId = localStorage.getItem('current_tenant_id');
  const envId = localStorage.getItem('current_environment_id');
  if (tenantId && envId) {
    monitorsStore.fetchMonitors(tenantId, envId);
  }
});

const filteredMonitors = computed(() => {
  return monitorsStore.monitors.filter(m => {
    const matchesSearch = !searchQuery.value || 
      m.name.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
      (m.target && m.target.toLowerCase().includes(searchQuery.value.toLowerCase()));
      
    const matchesState = selectedState.value === 'all' || 
      (m.current_state || 'pending').toLowerCase() === selectedState.value;

    return matchesSearch && matchesState;
  });
});
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
}
.filter-card {
  margin-bottom: 1.5rem;
  padding: 1rem 1.5rem;
}
.filter-controls {
  display: flex;
  gap: 1rem;
}
.search-input {
  flex: 1;
}
.state-select {
  width: 200px;
}
.table-card {
  padding: 0;
}
.monitor-title {
  font-weight: 600;
  color: var(--text-primary);
}
.target-cell code {
  font-size: 0.8rem;
  color: var(--text-secondary);
}
.blocked-hint {
  font-size: 0.75rem;
  color: var(--status-blocked);
  margin-top: 0.2rem;
}
.kind-tag {
  background: var(--bg-hover);
  padding: 0.15rem 0.5rem;
  border-radius: var(--radius-sm);
  font-size: 0.75rem;
  font-family: monospace;
}
.btn-sm {
  padding: 0.25rem 0.6rem;
  font-size: 0.75rem;
}
</style>
