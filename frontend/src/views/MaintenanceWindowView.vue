<template>
  <div class="maintenance-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">{{ t('maintenance.title') }}</h1>
      </div>
      <button v-if="canWrite" class="btn btn-primary" @click="showForm = !showForm" :disabled="!hasContext">
        {{ t('maintenance.new_window') }}
      </button>
    </div>

    <div v-if="!hasContext" class="card">
      <p class="text-muted">No tenant/environment context resolved yet. Log out and back in.</p>
    </div>

    <div v-if="showForm" class="card form-card">
      <form @submit.prevent="handleCreateWindow">
        <label class="form-label">{{ t('maintenance.name') }}</label>
        <input v-model="newWindow.name" class="form-input" placeholder="Weekly DB maintenance" required />

        <label class="form-label">Description</label>
        <input v-model="newWindow.description" class="form-input" placeholder="Optional" />

        <div class="grid-cols-2">
          <div>
            <label class="form-label">{{ t('maintenance.starts_at') }}</label>
            <input v-model="newWindow.starts_at" type="datetime-local" class="form-input" required />
          </div>
          <div>
            <label class="form-label">{{ t('maintenance.ends_at') }}</label>
            <input v-model="newWindow.ends_at" type="datetime-local" class="form-input" required />
          </div>
        </div>

        <label class="checkbox-label">
          <input type="checkbox" v-model="newWindow.suppress_notifications" />
          Suppress notifications during this window
        </label>
        <label class="checkbox-label">
          <input type="checkbox" v-model="newWindow.recurring_weekly" />
          {{ t('maintenance.recurring') }} (weekly)
        </label>

        <label class="form-label">Monitors</label>
        <div class="monitor-checklist">
          <label v-for="m in monitorsStore.monitors" :key="m.public_id" class="checkbox-label">
            <input type="checkbox" :value="m.public_id" v-model="newWindow.monitor_ids" />
            {{ m.name }}
          </label>
          <p v-if="monitorsStore.monitors.length === 0" class="text-muted">No monitors available.</p>
        </div>

        <button type="submit" class="btn btn-primary" :disabled="creating">
          {{ creating ? '...' : 'Create Window' }}
        </button>
      </form>
    </div>

    <!-- Maintenance Windows Table -->
    <div class="card table-card">
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>{{ t('maintenance.name') }}</th>
              <th>{{ t('maintenance.starts_at') }}</th>
              <th>{{ t('maintenance.ends_at') }}</th>
              <th>{{ t('maintenance.recurring') }}</th>
              <th>State</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="mw in windowsStore.windows" :key="mw.public_id">
              <td><strong>{{ mw.name }}</strong></td>
              <td>{{ mw.starts_at }}</td>
              <td>{{ mw.ends_at }}</td>
              <td>{{ mw.recurring_weekly ? 'Yes (Weekly)' : 'One-off' }}</td>
              <td><StatusBadge state="maintenance" /></td>
              <td>
                <button v-if="canWrite" class="btn btn-danger btn-sm" @click="handleDelete(mw.public_id)">Delete</button>
              </td>
            </tr>
            <tr v-if="!windowsStore.loading && windowsStore.windows.length === 0">
              <td colspan="6" class="text-center text-muted">No scheduled maintenance windows.</td>
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
import { useMaintenanceWindowsStore } from '../stores/maintenanceWindows';
import { useMonitorsStore } from '../stores/monitors';
import { useUiStore } from '../stores/ui';

// Viewers are read-only (#160).
const canWrite = computed(() => useTenantStore().canWrite);

const { t } = useI18n();
const windowsStore = useMaintenanceWindowsStore();
const monitorsStore = useMonitorsStore();
const uiStore = useUiStore();

const tenantId = localStorage.getItem('current_tenant_id');
const envId = localStorage.getItem('current_environment_id');
const hasContext = computed(() => !!tenantId && !!envId);

const showForm = ref(false);
const creating = ref(false);
const newWindow = ref({
  name: '',
  description: '',
  starts_at: '',
  ends_at: '',
  suppress_notifications: true,
  recurring_weekly: false,
  monitor_ids: [],
});

onMounted(() => {
  if (hasContext.value) {
    windowsStore.fetchWindows(tenantId, envId);
    monitorsStore.fetchMonitors(tenantId, envId);
  }
});

async function handleCreateWindow() {
  creating.value = true;
  try {
    await windowsStore.createWindow(tenantId, envId, {
      ...newWindow.value,
      starts_at: new Date(newWindow.value.starts_at).toISOString(),
      ends_at: new Date(newWindow.value.ends_at).toISOString(),
    });
    newWindow.value = {
      name: '', description: '', starts_at: '', ends_at: '',
      suppress_notifications: true, recurring_weekly: false, monitor_ids: [],
    };
    showForm.value = false;
  } catch (e) {
    uiStore.notify(`Failed to create maintenance window: ${e.message}`, 'error');
  } finally {
    creating.value = false;
  }
}

async function handleDelete(windowId) {
  if (await uiStore.confirm('Delete this maintenance window?')) {
    try {
      await windowsStore.deleteWindow(tenantId, envId, windowId);
    } catch (e) {
      uiStore.notify(`Failed to delete maintenance window: ${e.message}`, 'error');
    }
  }
}
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
}
.table-card {
  padding: 0;
}
.btn-sm {
  padding: 0.25rem 0.6rem;
  font-size: 0.75rem;
}
.form-card {
  margin-bottom: 1rem;
}
.form-label {
  display: block;
  margin-top: 0.75rem;
  margin-bottom: 0.25rem;
  font-size: 0.85rem;
}
.form-input {
  width: 100%;
  padding: 0.5rem;
}
.checkbox-label {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-top: 0.5rem;
  font-size: 0.85rem;
}
.monitor-checklist {
  max-height: 160px;
  overflow-y: auto;
  margin-bottom: 1rem;
}
</style>
