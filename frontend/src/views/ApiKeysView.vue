<template>
  <div class="api-keys-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">{{ t('app.api_keys') }}</h1>
      </div>
      <button v-if="canWrite" class="btn btn-primary" @click="showForm = !showForm" :disabled="!hasContext">
        Generate New Key
      </button>
    </div>

    <div v-if="!hasContext" class="card">
      <p class="text-muted">No tenant/environment context resolved yet. Log out and back in, or contact an admin to be added to a tenant.</p>
    </div>

    <div v-if="newPlaintextKey" class="card new-key-banner">
      <strong>Copy this secret now — it will not be shown again:</strong>
      <code>{{ newPlaintextKey }}</code>
      <button class="btn btn-secondary btn-sm" @click="newPlaintextKey = null">Dismiss</button>
    </div>

    <div v-if="showForm" class="card form-card">
      <form @submit.prevent="handleCreateKey">
        <label class="form-label">Name</label>
        <input v-model="newKeyName" class="form-input" placeholder="CI/CD Ingestion Key" required />
        <label class="form-label">Permissions</label>
        <div class="permissions-grid">
          <label v-for="perm in availablePermissions" :key="perm" class="permission-checkbox">
            <input type="checkbox" :value="perm" v-model="newKeyPermissions" />
            {{ perm }}
          </label>
        </div>
        <button type="submit" class="btn btn-primary" :disabled="creating || newKeyPermissions.length === 0">
          {{ creating ? '...' : 'Create Key' }}
        </button>
      </form>
    </div>

    <!-- API Keys Table -->
    <div class="card table-card">
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Prefix</th>
              <th>Permissions</th>
              <th>Created At</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="key in apiKeysStore.keys" :key="key.id">
              <td><strong>{{ key.name }}</strong></td>
              <td><code>{{ key.prefix }}...</code></td>
              <td>{{ (key.permissions || []).join(', ') }}</td>
              <td>{{ key.created_at }}</td>
              <td>
                <button v-if="canWrite" class="btn btn-danger btn-sm" @click="handleRevokeKey(key.id)">Revoke</button>
              </td>
            </tr>
            <tr v-if="!apiKeysStore.loading && apiKeysStore.keys.length === 0">
              <td colspan="5" class="text-center text-muted">No active API keys found.</td>
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
import { useApiKeysStore } from '../stores/apiKeys';
import { useUiStore } from '../stores/ui';
import { ApiKeyService } from '../constants/apiKeyPermissions';

// Viewers are read-only (#160).
const canWrite = computed(() => useTenantStore().canWrite);

const { t } = useI18n();
const apiKeysStore = useApiKeysStore();
const uiStore = useUiStore();

const tenantId = localStorage.getItem('current_tenant_id');
const envId = localStorage.getItem('current_environment_id');
const hasContext = computed(() => !!tenantId && !!envId);

const availablePermissions = ApiKeyService.ALLOWED_PERMISSIONS;
const showForm = ref(false);
const creating = ref(false);
const newKeyName = ref('');
const newKeyPermissions = ref([]);
const newPlaintextKey = ref(null);

onMounted(() => {
  if (hasContext.value) {
    apiKeysStore.fetchKeys(tenantId, envId);
  }
});

async function handleCreateKey() {
  creating.value = true;
  try {
    const created = await apiKeysStore.createKey(tenantId, envId, {
      name: newKeyName.value,
      permissions: newKeyPermissions.value,
    });
    newPlaintextKey.value = created.plaintext;
    newKeyName.value = '';
    newKeyPermissions.value = [];
    showForm.value = false;
  } catch (e) {
    uiStore.notify(`Failed to create API key: ${e.message}`, 'error');
  } finally {
    creating.value = false;
  }
}

async function handleRevokeKey(id) {
  if (await uiStore.confirm('Revoke this API key? Applications using it will lose access immediately.')) {
    try {
      await apiKeysStore.revokeKey(tenantId, envId, id);
    } catch (e) {
      uiStore.notify(`Failed to revoke API key: ${e.message}`, 'error');
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
.new-key-banner {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1rem;
}
.new-key-banner code {
  flex: 1;
  word-break: break-all;
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
.permissions-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.35rem;
  margin-bottom: 1rem;
}
.permission-checkbox {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.85rem;
}
</style>
