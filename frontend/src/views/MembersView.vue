<template>
  <div class="members-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">{{ t('users.members_title') }}</h1>
        <p class="text-muted subtitle">{{ t('users.members_subtitle', { org: tenantName }) }}</p>
      </div>
      <button v-if="canAddAny" class="btn btn-primary" @click="showForm = !showForm">＋ {{ t('users.add_member') }}</button>
    </div>

    <div v-if="!tenantId" class="card"><p class="text-muted">No organization context resolved yet. Log out and back in.</p></div>

    <template v-else>
      <div v-if="showForm" class="card form-card">
        <form @submit.prevent="handleAdd">
          <div class="grid-cols-3">
            <div class="form-group">
              <label class="form-label" for="member-email">{{ t('users.email') }}</label>
              <input id="member-email" v-model="form.email" type="email" required maxlength="255" class="form-control" />
            </div>
            <div class="form-group">
              <label class="form-label" for="member-name">{{ t('users.name_optional') }}</label>
              <input id="member-name" v-model="form.name" maxlength="255" class="form-control" />
            </div>
            <div class="form-group">
              <label class="form-label" for="member-role">{{ t('users.role') }}</label>
              <select id="member-role" v-model="form.role" class="form-control">
                <option v-for="r in grantableRoles" :key="r" :value="r">{{ t(`users.roles.${r}`) }}</option>
              </select>
            </div>
          </div>
          <p class="text-muted hint">{{ t('users.add_hint') }}</p>
          <div class="actions">
            <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? '...' : t('users.add_member') }}</button>
            <button type="button" class="btn btn-secondary" @click="showForm = false">{{ t('status_page.cancel') }}</button>
          </div>
        </form>
      </div>

      <div class="card table-card">
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>{{ t('users.name') }}</th>
                <th>{{ t('users.email') }}</th>
                <th>{{ t('users.role') }}</th>
                <th><span class="visually-hidden">{{ t('users.actions') }}</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in store.members" :key="m.id">
                <td><strong>{{ m.user.name }}</strong> <span v-if="m.user.id === auth.user?.id" class="text-muted">({{ t('users.you') }})</span></td>
                <td>{{ m.user.email }}</td>
                <td>
                  <select
                    v-if="store.canManage(m)"
                    class="form-control role-select"
                    :value="m.role"
                    :aria-label="t('users.role_of', { name: m.user.name })"
                    @change="handleRole(m, $event)"
                  >
                    <option v-for="r in roles" :key="r" :value="r" :disabled="!store.canGrant(r)">{{ t(`users.roles.${r}`) }}</option>
                  </select>
                  <span v-else class="role-badge">{{ t(`users.roles.${m.role}`) }}</span>
                </td>
                <td class="text-right">
                  <button v-if="store.canManage(m)" class="btn btn-danger btn-sm" @click="handleRemove(m)">{{ t('users.remove') }}</button>
                </td>
              </tr>
              <tr v-if="!store.loading && store.members.length === 0">
                <td colspan="4" class="text-center text-muted">{{ t('users.no_members') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <p class="text-muted hint">{{ t('users.roles_help') }}</p>
    </template>

    <TemporaryPasswordDialog :password="tempPassword" :email="tempEmail" @close="tempPassword = null" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import TemporaryPasswordDialog from '../components/TemporaryPasswordDialog.vue';
import { useMembersStore } from '../stores/members';
import { useAuthStore } from '../stores/auth';
import { useTenantStore } from '../stores/tenant';
import { useUiStore } from '../stores/ui';

const { t } = useI18n();
const store = useMembersStore();
const auth = useAuthStore();
const tenantStore = useTenantStore();
const uiStore = useUiStore();

const tenantId = localStorage.getItem('current_tenant_id');
const tenantName = computed(() => tenantStore.tenant?.name || '');
const roles = ['owner', 'admin', 'viewer'];
const grantableRoles = computed(() => roles.filter((r) => store.canGrant(r)));
const canAddAny = computed(() => !!tenantId && grantableRoles.value.length > 0);

const showForm = ref(false);
const saving = ref(false);
const form = ref({ email: '', name: '', role: 'viewer' });
const tempPassword = ref(null);
const tempEmail = ref('');

onMounted(async () => {
  if (!tenantId) return;
  try {
    await store.fetch(tenantId);
  } catch (e) {
    uiStore.notify(e.message, 'error');
  }
});

function errorText(e) {
  const fieldErrors = e.data?.errors ? Object.values(e.data.errors).flat() : [];
  return fieldErrors.length ? fieldErrors.join(' ') : (e.data?.message || e.message);
}

async function handleAdd() {
  saving.value = true;
  try {
    const res = await store.add(tenantId, form.value);
    if (res.temporary_password) {
      tempEmail.value = res.data.user.email;
      tempPassword.value = res.temporary_password;
    } else {
      uiStore.notify(t('users.added_existing', { email: res.data.user.email }), 'success');
    }
    form.value = { email: '', name: '', role: 'viewer' };
    showForm.value = false;
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  } finally {
    saving.value = false;
  }
}

async function handleRole(member, event) {
  const role = event.target.value;
  try {
    await store.changeRole(tenantId, member.id, role);
    uiStore.notify(t('users.role_changed'), 'success');
  } catch (e) {
    event.target.value = member.role;
    uiStore.notify(errorText(e), 'error');
  }
}

async function handleRemove(member) {
  if (!(await uiStore.confirm(t('users.remove_confirm', { name: member.user.name, org: tenantName.value })))) return;
  try {
    await store.remove(tenantId, member.id);
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  }
}
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  flex-wrap: wrap;
  margin-bottom: 1.5rem;
}
.subtitle {
  margin: 0.25rem 0 0;
}
.form-card {
  margin-bottom: 1rem;
}
.grid-cols-3 {
  display: grid;
  grid-template-columns: 2fr 2fr 1fr;
  gap: 1rem;
}
@media (max-width: 1024px) {
  .grid-cols-3 {
    grid-template-columns: 1fr;
  }
}
.hint {
  font-size: 0.85rem;
  margin: 0.5rem 0 1rem;
}
.actions {
  display: flex;
  gap: 0.5rem;
}
.table-card {
  padding: 0;
}
.role-select {
  width: auto;
  padding: 0.3rem 0.6rem;
  font-size: 0.85rem;
}
.role-badge {
  font-size: 0.85rem;
}
.text-right {
  text-align: right;
}
.btn-sm {
  padding: 0.25rem 0.6rem;
  font-size: 0.75rem;
}
.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}
</style>
