<template>
  <div class="platform-page">
    <header class="platform-head">
      <div>
        <h1 class="page-title">{{ t('users.platform_title') }}</h1>
        <p class="platform-lead">{{ t('users.platform_lead') }}</p>
      </div>
      <span class="platform-tag">{{ t('users.platform_tag') }}</span>
    </header>

    <div v-if="!auth.user?.is_platform_admin" class="card"><p class="text-muted">{{ t('users.platform_only') }}</p></div>

    <template v-else>
      <div class="tabs" role="tablist">
        <button role="tab" :aria-selected="tab === 'users'" :class="['tab', { active: tab === 'users' }]" @click="tab = 'users'">{{ t('users.tab_users') }}</button>
        <button role="tab" :aria-selected="tab === 'orgs'" :class="['tab', { active: tab === 'orgs' }]" @click="tab = 'orgs'">{{ t('users.tab_orgs') }}</button>
        <button role="tab" :aria-selected="tab === 'checker'" :class="['tab', { active: tab === 'checker' }]" @click="tab = 'checker'">{{ t('checker.tab') }}</button>
      </div>

      <section v-if="tab === 'users'">
        <div class="toolbar">
          <form class="search" role="search" @submit.prevent="store.fetchUsers(search)">
            <input v-model="search" type="search" class="form-control" :placeholder="t('users.search_placeholder')" :aria-label="t('users.search_placeholder')" />
            <button type="submit" class="btn btn-secondary">{{ t('users.search') }}</button>
          </form>
          <button type="button" class="btn btn-primary" :aria-expanded="showNewUser" @click="toggleNewUser">{{ t('admin.new_user') }}</button>
        </div>

        <div v-if="showNewUser" class="card form-card">
          <h2 class="section-title">{{ t('admin.new_user') }}</h2>
          <form @submit.prevent="handleCreateUser">
            <div class="grid-cols-2">
              <div class="form-group">
                <label class="form-label" for="new-user-name">{{ t('users.name') }}</label>
                <input id="new-user-name" v-model="userForm.name" required maxlength="255" class="form-control" />
              </div>
              <div class="form-group">
                <label class="form-label" for="new-user-email">{{ t('users.email') }}</label>
                <input id="new-user-email" v-model="userForm.email" type="email" required maxlength="255" class="form-control" />
              </div>
              <div class="form-group">
                <label class="form-label" for="new-user-org">{{ t('admin.org_optional') }}</label>
                <select id="new-user-org" v-model="userForm.tenant_id" class="form-control">
                  <option value="">{{ t('admin.no_org') }}</option>
                  <option v-for="o in store.tenants" :key="o.id" :value="o.id">{{ o.name }}</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label" for="new-user-role">{{ t('users.role') }}</label>
                <select id="new-user-role" v-model="userForm.role" class="form-control" :disabled="!userForm.tenant_id">
                  <option v-for="r in ['owner', 'admin', 'viewer']" :key="r" :value="r">{{ t(`users.roles.${r}`) }}</option>
                </select>
              </div>
            </div>
            <p class="text-muted small">{{ t('admin.new_user_hint') }}</p>
            <button type="submit" class="btn btn-primary" :disabled="creatingUser">{{ creatingUser ? '...' : t('admin.create_user') }}</button>
          </form>
        </div>
        <div class="card table-card">
          <div class="table-responsive">
            <table class="data-table">
              <thead>
                <tr>
                  <th>{{ t('users.name') }}</th>
                  <th>{{ t('users.organizations') }}</th>
                  <th>{{ t('users.platform_admin') }}</th>
                  <th><span class="visually-hidden">{{ t('users.actions') }}</span></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="u in store.users" :key="u.id">
                  <td>
                    <strong>{{ u.name }}</strong>
                    <span v-if="u.blocked" class="status-badge down tiny">{{ t('admin.blocked') }}</span>
                    <div class="text-muted small">{{ u.email }}</div>
                  </td>
                  <td class="small">
                    <span v-for="m in u.memberships" :key="m.tenant.id" class="org-chip">{{ m.tenant.name }} · {{ t(`users.roles.${m.role}`) }}</span>
                    <span v-if="u.memberships.length === 0" class="text-muted">—</span>
                  </td>
                  <td>
                    <label class="checkbox-label">
                      <input type="checkbox" :checked="u.is_platform_admin" :disabled="u.id === auth.user.id" @change="handleAdmin(u, $event)" />
                      <span class="visually-hidden">{{ t('users.platform_admin') }}</span>
                    </label>
                  </td>
                  <td class="text-right">
                    <div class="row-actions">
                      <button class="btn btn-secondary btn-sm" @click="openUser = u.id">{{ t('admin.manage') }}</button>
                      <button class="btn btn-secondary btn-sm" @click="handleReset(u)">{{ t('users.reset_password') }}</button>
                    </div>
                  </td>
                </tr>
                <tr v-if="!store.loading && store.users.length === 0">
                  <td colspan="4" class="text-center text-muted">{{ t('users.no_users') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section v-else-if="tab === 'checker'" aria-labelledby="checker-title">
        <div v-if="store.checker" class="card checker-card">
          <h2 id="checker-title" class="section-title">{{ t('checker.title') }}</h2>
          <p v-if="store.checker.saturation.saturated" class="checker-alert" role="status">
            {{ t('checker.saturated', { since: formatTime(store.checker.saturation.since), ticks: store.checker.saturation.consecutive_ticks }) }}
          </p>
          <dl class="checker-grid">
            <div>
              <dt>{{ t('checker.demand') }}</dt>
              <dd>{{ t('checker.per_minute', { n: formatNumber(store.checker.demand_per_minute) }) }}</dd>
            </div>
            <div>
              <dt>{{ t('checker.capacity') }}</dt>
              <dd v-if="store.checker.capacity_per_minute">{{ t('checker.per_tick', { n: formatNumber(store.checker.capacity_per_minute) }) }}</dd>
              <dd v-else class="text-muted">{{ t('checker.not_measured') }}</dd>
            </div>
            <div v-if="store.checker.utilization !== null">
              <dt>{{ t('checker.utilization') }}</dt>
              <dd>
                <meter class="checker-meter" min="0" max="1" low="0.6" high="0.8" optimum="0" :value="Math.min(1, store.checker.utilization)">{{ formatPercent(store.checker.utilization) }}</meter>
                {{ formatPercent(store.checker.utilization) }}
              </dd>
            </div>
            <div>
              <dt>{{ t('checker.concurrency') }}</dt>
              <dd>{{ store.checker.concurrency }}</dd>
            </div>
            <div>
              <dt>{{ t('checker.last_tick') }}</dt>
              <dd v-if="store.checker.last_tick">
                {{ t('checker.last_tick_detail', {
                  at: formatTime(store.checker.last_tick.at),
                  seconds: formatNumber(store.checker.last_tick.duration_ms / 1000),
                  executed: store.checker.last_tick.executed,
                  deferred: store.checker.last_tick.deferred,
                }) }}
              </dd>
              <dd v-else class="text-muted">{{ t('checker.never') }}</dd>
            </div>
          </dl>
          <p class="text-muted checker-hint">{{ t('checker.hint', { seconds: store.checker.saturation.duration_threshold_seconds, ticks: store.checker.saturation.ticks_threshold }) }}</p>
        </div>
      </section>

      <section v-else>
        <div class="card form-card">
          <h2 class="section-title">{{ t('users.new_org') }}</h2>
          <form @submit.prevent="handleCreateOrg">
            <div class="grid-cols-2">
              <div class="form-group">
                <label class="form-label" for="org-name">{{ t('setup.org') }}</label>
                <input id="org-name" v-model="orgForm.name" required maxlength="255" class="form-control" @input="suggestSlug" />
              </div>
              <div class="form-group">
                <label class="form-label" for="org-slug">{{ t('setup.slug') }}</label>
                <input id="org-slug" v-model="orgForm.slug" required maxlength="120" pattern="[a-z0-9]+(-[a-z0-9]+)*" class="form-control" @input="slugTouched = true" />
                <small class="text-muted">/status/{{ orgForm.slug || '…' }}</small>
              </div>
              <div class="form-group">
                <label class="form-label" for="org-owner">{{ t('users.owner_email') }}</label>
                <input id="org-owner" v-model="orgForm.owner_email" type="email" required class="form-control" />
              </div>
              <div class="form-group">
                <label class="form-label" for="org-owner-name">{{ t('users.name_optional') }}</label>
                <input id="org-owner-name" v-model="orgForm.owner_name" maxlength="255" class="form-control" />
              </div>
            </div>
            <button type="submit" class="btn btn-primary" :disabled="creating">{{ creating ? '...' : t('users.create_org') }}</button>
          </form>
        </div>

        <div class="card table-card">
          <div class="table-responsive">
            <table class="data-table">
              <thead>
                <tr>
                  <th>{{ t('users.organization') }}</th>
                  <th>{{ t('users.approval') }}</th>
                  <th>{{ t('users.plan') }}</th>
                  <th class="num">{{ t('users.members_count') }}</th>
                  <th class="num">{{ t('users.monitors_count') }}</th>
                  <th>{{ t('app.status_page') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="o in store.tenants" :key="o.id">
                  <td>
                    <strong>{{ o.name }}</strong>
                    <div class="text-muted small">{{ o.slug }}</div>
                    <button type="button" class="btn btn-secondary btn-sm manage" @click="openOrg = o.id">{{ t('admin.manage') }}</button>
                  </td>
                  <td>
                    <div class="approval">
                      <span :class="['status-badge', o.approved ? 'up' : 'pending']">{{ o.approved ? t('users.approved') : t('users.pending_approval') }}</span>
                      <button v-if="!o.approved" class="btn btn-primary btn-sm" @click="handleApprove(o)">{{ t('users.approve') }}</button>
                      <button v-else class="btn btn-secondary btn-sm" @click="handleSuspend(o)">{{ t('users.suspend') }}</button>
                    </div>
                  </td>
                  <td>
                    <select class="form-control form-control-sm" :value="o.plan" :aria-label="t('users.plan') + ' ' + o.name" @change="handlePlan(o, $event)">
                      <option v-for="p in planOptions" :key="p" :value="p">{{ p }}</option>
                    </select>
                    <div v-if="o.billing" class="text-muted small">{{ t('users.billing') }}: {{ o.billing.plan }} · {{ t(`plan.status.${o.billing.status}`) }}</div>
                  </td>
                  <td class="num">{{ o.members }}</td>
                  <td class="num">{{ o.monitors }}</td>
                  <td>
                    <a v-for="s in o.status_pages" :key="s" :href="`/status/${s}`" target="_blank" rel="noopener">/status/{{ s }}</a>
                    <span v-if="o.status_pages.length === 0" class="text-muted">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </template>

    <PlatformUserDialog :user-id="openUser" @close="openUser = null" @open-org="switchToOrg" @reset-password="handleReset" @changed="refreshList" />
    <PlatformOrgDialog :tenant-id="openOrg" @close="openOrg = null" @open-user="switchToUser" @temporary-password="showTemporaryPassword" @changed="refreshList" />
    <TemporaryPasswordDialog :password="tempPassword" :email="tempEmail" @close="tempPassword = null" />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import TemporaryPasswordDialog from '../components/TemporaryPasswordDialog.vue';
import PlatformUserDialog from '../components/PlatformUserDialog.vue';
import PlatformOrgDialog from '../components/PlatformOrgDialog.vue';
import { usePlatformStore } from '../stores/platform';
import { useAuthStore } from '../stores/auth';
import { useUiStore } from '../stores/ui';

const { t, locale } = useI18n();
const store = usePlatformStore();
const auth = useAuthStore();
const uiStore = useUiStore();

const tab = ref('users');
const search = ref('');
const tempPassword = ref(null);
const tempEmail = ref('');
const creating = ref(false);
const slugTouched = ref(false);
const orgForm = ref({ name: '', slug: '', owner_email: '', owner_name: '' });
// One user or organization open in a dialog (#248).
const openUser = ref(null);
const openOrg = ref(null);
const showNewUser = ref(false);
const creatingUser = ref(false);
const userForm = ref({ name: '', email: '', tenant_id: '', role: 'viewer' });

function errorText(e) {
  const fieldErrors = e.data?.errors ? Object.values(e.data.errors).flat() : [];
  return fieldErrors.length ? fieldErrors.join(' ') : (e.data?.message || e.message);
}

async function load() {
  if (!auth.user?.is_platform_admin) return;
  try {
    if (tab.value === 'users') await store.fetchUsers(search.value);
    else if (tab.value === 'orgs') await store.fetchTenants();
    else await store.fetchChecker();
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  }
}
onMounted(load);
watch(tab, load);

function formatNumber(n) {
  return new Intl.NumberFormat(locale.value, { maximumFractionDigits: 1 }).format(n);
}
function formatPercent(n) {
  return new Intl.NumberFormat(locale.value, { style: 'percent', maximumFractionDigits: 1 }).format(n);
}
function formatTime(iso) {
  return iso ? new Date(iso).toLocaleString(locale.value) : '';
}

function suggestSlug() {
  if (slugTouched.value) return;
  orgForm.value.slug = orgForm.value.name
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
    .slice(0, 120);
}

async function handleAdmin(user, event) {
  const value = event.target.checked;
  const question = value ? t('users.grant_admin_confirm', { email: user.email }) : t('users.revoke_admin_confirm', { email: user.email });
  if (!(await uiStore.confirm(question))) {
    event.target.checked = !value;
    return;
  }
  try {
    await store.setPlatformAdmin(user.id, value);
  } catch (e) {
    event.target.checked = !value;
    uiStore.notify(errorText(e), 'error');
  }
}

async function handleReset(user) {
  if (!(await uiStore.confirm(t('users.reset_confirm', { email: user.email })))) return;
  try {
    tempEmail.value = user.email;
    tempPassword.value = await store.resetPassword(user.id);
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  }
}

const planOptions = computed(() => store.planIds);

async function handleApprove(org) {
  if (!(await uiStore.confirm(t('users.approve_confirm', { name: org.name })))) return;
  try {
    await store.updateTenant(org.id, { approved: true });
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  }
}

// Suspension (#218): members cannot sign in and monitors stop; "Approve" lifts it.
async function handleSuspend(org) {
  if (!(await uiStore.confirm(t('users.suspend_confirm', { name: org.name })))) return;
  try {
    await store.updateTenant(org.id, { approved: false });
    uiStore.notify(t('users.suspended'), 'success');
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  }
}

async function handlePlan(org, event) {
  const plan = event.target.value;
  if (!(await uiStore.confirm(t('users.plan_confirm', { name: org.name, plan })))) {
    event.target.value = org.plan;
    return;
  }
  try {
    await store.updateTenant(org.id, { plan });
    uiStore.notify(t('users.plan_changed'), 'success');
  } catch (e) {
    event.target.value = org.plan;
    uiStore.notify(errorText(e), 'error');
  }
}

function switchToOrg(id) {
  openUser.value = null;
  openOrg.value = id;
}
function switchToUser(id) {
  openOrg.value = null;
  openUser.value = id;
}
function showTemporaryPassword({ email, password }) {
  tempEmail.value = email;
  tempPassword.value = password;
}
function refreshList() {
  load();
}

async function toggleNewUser() {
  showNewUser.value = !showNewUser.value;
  if (showNewUser.value && store.tenants.length === 0) {
    try {
      await store.fetchTenants();
    } catch (e) {
      uiStore.notify(errorText(e), 'error');
    }
  }
}

async function handleCreateUser() {
  creatingUser.value = true;
  try {
    const data = { name: userForm.value.name, email: userForm.value.email };
    if (userForm.value.tenant_id) {
      data.tenant_id = userForm.value.tenant_id;
      data.role = userForm.value.role;
    }
    const user = await store.createUser(data);
    uiStore.notify(t('admin.user_created', { email: user.email }), 'success');
    userForm.value = { name: '', email: '', tenant_id: '', role: 'viewer' };
    showNewUser.value = false;
    await store.fetchUsers(search.value);
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  } finally {
    creatingUser.value = false;
  }
}

async function handleCreateOrg() {
  creating.value = true;
  try {
    const res = await store.createTenant(orgForm.value);
    if (res.temporary_password) {
      tempEmail.value = orgForm.value.owner_email;
      tempPassword.value = res.temporary_password;
    } else {
      uiStore.notify(t('users.org_created', { name: res.data.name }), 'success');
    }
    orgForm.value = { name: '', slug: '', owner_email: '', owner_name: '' };
    slugTouched.value = false;
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  } finally {
    creating.value = false;
  }
}
</script>

<style scoped>
/* Platform area: the head is a station sign, so it reads as a different place from tenant screens. */
.platform-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px 24px;
  flex-wrap: wrap;
  margin-bottom: 1.5rem;
  padding: 20px 24px;
  border-radius: var(--radius-md);
  background: var(--sign-bg);
  color: var(--sign-text);
  box-shadow: inset 0 0 0 1px var(--sign-rule);
}
.platform-head .page-title { color: var(--sign-text); }
.platform-lead { margin-top: 4px; color: var(--sign-muted); font-size: .9375rem; }
.platform-tag {
  padding: 4px 12px;
  border-radius: 9999px;
  box-shadow: inset 0 0 0 1px var(--sign-muted);
  color: var(--sign-text);
  font-size: .8125rem;
  font-weight: 600;
}
.tabs {
  display: flex;
  gap: 0.25rem;
  border-bottom: 1px solid var(--border-color);
  margin-bottom: 1.25rem;
}
.tab {
  min-height: 44px;
  background: none;
  border: 0;
  border-bottom: 3px solid transparent;
  margin-bottom: -1px;
  color: var(--text-secondary);
  padding: 0.6rem 1rem;
  cursor: pointer;
  font: inherit;
  font-weight: 500;
}
.tab:hover { color: var(--text-primary); }
/* The active tab is marked with the metro line, not with the action blue. */
.tab.active {
  color: var(--text-primary);
  font-weight: 600;
  border-bottom-color: var(--line-ink);
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  margin-bottom: 1rem;
}
.search {
  display: flex;
  gap: 0.5rem;
  flex: 1 1 280px;
  max-width: 480px;
}
.tiny { font-size: 0.75rem; margin-inline-start: 0.35rem; }
.row-actions {
  display: inline-flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.5rem;
}
.manage { display: block; margin-top: 0.35rem; }
.form-card {
  margin-bottom: 1.5rem;
}
.section-title {
  font-size: 1.125rem;
  margin: 0 0 1rem;
}
.grid-cols-2 {
  gap: 0 1rem;
}
.checker-card { max-inline-size: 760px; }
.checker-alert {
  margin: 0 0 16px;
  padding: 10px 14px;
  border-inline-start: 4px solid var(--status-down);
  background: var(--status-down-bg);
  color: var(--text-primary);
}
.checker-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px 24px;
  margin: 0;
}
.checker-grid dt { font-size: 0.8125rem; color: var(--text-secondary); }
.checker-grid dd { margin: 4px 0 0; font-weight: 600; font-variant-numeric: tabular-nums; }
.checker-meter { inline-size: 120px; vertical-align: middle; margin-inline-end: 8px; }
.checker-hint { margin: 20px 0 0; font-size: 0.875rem; }
.table-card {
  padding: 0;
  overflow: hidden;
}
.small {
  font-size: 0.8125rem;
}
.org-chip {
  display: inline-block;
  margin: 0 0.35rem 0.25rem 0;
  padding: 0.1rem 0.6rem;
  border: 1px solid var(--border-color);
  border-radius: 9999px;
}
.approval {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.form-control-sm {
  width: auto;
  min-width: 140px;
  min-height: 36px;
  padding: 0.3rem 0.6rem;
}
.checkbox-label {
  display: inline-flex;
  align-items: center;
  min-height: 44px;
}
.checkbox-label input { width: 18px; height: 18px; }
.text-right {
  text-align: right;
}
.btn-sm {
  min-height: 36px;
  padding: 0.3rem 0.8rem;
  font-size: 0.875rem;
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
