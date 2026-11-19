<template>
  <div class="incidents-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">{{ t('incidents.title') }}</h1>
      </div>
      <button v-if="canWrite" class="btn btn-primary" @click="showCreateModal = true">
        {{ t('incidents.new_incident') }}
      </button>
    </div>

    <!-- Open Incidents -->
    <div class="card table-card section-card">
      <div class="card-header">
        <h3>{{ t('incidents.open_incidents') }}</h3>
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Severity</th>
              <th>State</th>
              <th>{{ t('incidents.started_at') }}</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="inc in openIncidents" :key="inc.id">
              <td><strong>{{ inc.title || inc.summary }}</strong></td>
              <td><span class="severity-tag">{{ inc.severity || 'major' }}</span></td>
              <td><StatusBadge :state="inc.resolved_flag ? 'up' : 'down'" /></td>
              <td>{{ inc.started_at || inc.created_at }}</td>
              <td>
                <button v-if="canWrite" class="btn btn-secondary btn-sm" @click="handleResolve(inc.public_id)">
                  {{ t('incidents.resolve') }}
                </button>
              </td>
            </tr>
            <tr v-if="openIncidents.length === 0">
              <td colspan="5" class="text-center text-muted">No open incidents. All systems operational.</td>
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
import { useIncidentsStore } from '../stores/incidents';

// Viewers are read-only (#160).
const canWrite = computed(() => useTenantStore().canWrite);

const { t } = useI18n();
const incidentsStore = useIncidentsStore();
const showCreateModal = ref(false);

const openIncidents = computed(() => incidentsStore.incidents.filter(i => !i.resolved_flag));

async function handleResolve(incidentId) {
  const tenantId = localStorage.getItem('current_tenant_id');
  const envId = localStorage.getItem('current_environment_id');
  await incidentsStore.resolveIncident(tenantId, envId, incidentId);
}

onMounted(() => {
  const tenantId = localStorage.getItem('current_tenant_id');
  const envId = localStorage.getItem('current_environment_id');
  incidentsStore.fetchIncidents(tenantId, envId);
});
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
}
.section-card {
  padding: 0;
  margin-bottom: 1.5rem;
}
.card-header {
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid var(--border-color);
}
.severity-tag {
  background: var(--status-down-bg);
  color: var(--status-down-fg);
  padding: 0.15rem 0.5rem;
  border-radius: var(--radius-sm);
  font-size: 0.8125rem;
  font-weight: 600;
}
.btn-sm {
  padding: 0.25rem 0.6rem;
  font-size: 0.75rem;
}
</style>
