<template>
  <div v-if="tenantId" class="dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="platform-org-title" @keydown.esc="close">
    <div class="card dialog">
      <header class="dialog-head">
        <h2 id="platform-org-title" class="dialog-title">{{ org ? org.name : t('admin.loading') }}</h2>
        <button type="button" class="btn btn-secondary btn-sm" @click="close">{{ t('admin.close') }}</button>
      </header>

      <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>

      <template v-if="org">
        <div class="badges">
          <span :class="['status-badge', org.approved ? 'up' : 'pending']">{{ org.approved ? t('users.approved') : t('admin.pending_or_suspended') }}</span>
          <span class="chip">{{ t('users.plan') }}: {{ org.plan }}</span>
          <span v-if="org.trial.active" class="chip">{{ t('admin.in_trial') }}</span>
        </div>

        <dl class="facts">
          <div><dt>{{ t('admin.created_at') }}</dt><dd>{{ formatTime(org.created_at) }}</dd></div>
          <div><dt>{{ t('admin.signed_up_at') }}</dt><dd>{{ formatTime(org.signed_up_at) }}</dd></div>
          <div><dt>{{ t('admin.approved_at') }}</dt><dd>{{ formatTime(org.approved_at) }}</dd></div>
          <div><dt>{{ t('admin.trial') }}</dt><dd>{{ trialText }}</dd></div>
        </dl>

        <section class="section" aria-labelledby="org-edit-title">
          <h3 id="org-edit-title" class="section-title">{{ t('admin.edit_org') }}</h3>
          <form @submit.prevent="save">
            <div class="grid-cols-2">
              <div class="form-group">
                <label class="form-label" for="org-edit-name">{{ t('setup.org') }}</label>
                <input id="org-edit-name" v-model="form.name" required maxlength="255" class="form-control" />
              </div>
              <div class="form-group">
                <label class="form-label" for="org-edit-slug">{{ t('setup.slug') }}</label>
                <input id="org-edit-slug" v-model="form.slug" required maxlength="120" pattern="[a-z0-9]+(-[a-z0-9]+)*" class="form-control" autocapitalize="off" spellcheck="false" />
                <small class="text-muted">/status/{{ form.slug || '…' }}</small>
              </div>
            </div>
            <p v-if="slugChanged" class="alert alert-warning">{{ t('admin.slug_change_hint', { from: org.slug }) }}</p>
            <button type="submit" class="btn btn-primary" :disabled="busy || !dirty">{{ t('admin.save') }}</button>
          </form>
        </section>

        <section class="section" aria-labelledby="org-members-title">
          <h3 id="org-members-title" class="section-title">{{ t('users.members_title') }} ({{ org.members.length }})</h3>
          <ul class="member-list">
            <li v-for="m in org.members" :key="m.id" class="member">
              <div class="member-who">
                <button type="button" class="link-button" @click="$emit('open-user', m.user.id)">{{ m.user.name }}</button>
                <span v-if="m.user.blocked" class="status-badge down tiny">{{ t('admin.blocked') }}</span>
                <div class="text-muted small">{{ m.user.email }}</div>
              </div>
              <div class="member-actions">
                <select class="form-control form-control-sm" :value="m.role" :aria-label="t('users.role_of', { name: m.user.name })" :disabled="busy" @change="changeRole(m, $event)">
                  <option v-for="r in roles" :key="r" :value="r">{{ t(`users.roles.${r}`) }}</option>
                </select>
                <button v-if="!(m.role === 'owner' && owners === 1)" type="button" class="btn btn-secondary btn-sm" :disabled="busy" @click="makeOwner(m)">{{ t('admin.make_sole_owner') }}</button>
                <button type="button" class="btn btn-secondary btn-sm" :disabled="busy || (m.role === 'owner' && owners === 1)" @click="removeMember(m)">{{ t('users.remove') }}</button>
              </div>
            </li>
          </ul>
          <form class="add-member" @submit.prevent="addMember">
            <div class="form-group grow">
              <label class="form-label" for="org-add-email">{{ t('admin.add_member_email') }}</label>
              <input id="org-add-email" v-model="memberForm.email" type="email" required maxlength="255" class="form-control" />
            </div>
            <div class="form-group">
              <label class="form-label" for="org-add-role">{{ t('users.role') }}</label>
              <select id="org-add-role" v-model="memberForm.role" class="form-control">
                <option v-for="r in roles" :key="r" :value="r">{{ t(`users.roles.${r}`) }}</option>
              </select>
            </div>
            <button type="submit" class="btn btn-primary" :disabled="busy">{{ t('users.add_member') }}</button>
          </form>
          <p class="text-muted small">{{ t('users.add_hint') }} {{ t('users.roles_help') }}</p>
        </section>

        <section class="section" aria-labelledby="org-monitors-title">
          <h3 id="org-monitors-title" class="section-title">{{ t('admin.monitors_title', { total: org.monitors.total, enabled: org.monitors.enabled, paused: org.monitors.paused }) }}</h3>
          <table v-if="org.monitors.by_kind.length" class="data-table compact">
            <thead><tr><th>{{ t('admin.kind') }}</th><th class="num">{{ t('admin.enabled') }}</th><th class="num">{{ t('admin.paused') }}</th></tr></thead>
            <tbody>
              <tr v-for="k in org.monitors.by_kind" :key="k.kind"><td>{{ k.kind }}</td><td class="num">{{ k.enabled }}</td><td class="num">{{ k.paused }}</td></tr>
            </tbody>
          </table>
          <p v-else class="text-muted">{{ t('admin.no_monitors') }}</p>
        </section>

        <section class="section" aria-labelledby="org-pages-title">
          <h3 id="org-pages-title" class="section-title">{{ t('app.status_page') }}</h3>
          <ul class="plain-list">
            <li v-for="p in org.status_pages" :key="p.slug">
              <a :href="p.url" target="_blank" rel="noopener">{{ p.url }}</a>
              <span class="text-muted"> · {{ p.environment }} · {{ p.visibility }}</span>
              <span v-if="p.custom_domain" class="text-muted"> · {{ p.custom_domain }}</span>
              <div v-if="p.old_slugs.length" class="text-muted small">{{ t('admin.old_slugs', { slugs: p.old_slugs.join(', ') }) }}</div>
            </li>
          </ul>
          <p v-if="org.status_pages.length === 0" class="text-muted">—</p>
        </section>

        <section class="section" aria-labelledby="org-billing-title">
          <h3 id="org-billing-title" class="section-title">{{ t('users.billing') }}</h3>
          <p v-if="org.subscription">
            {{ org.subscription.provider }} · {{ org.subscription.plan }} ({{ org.subscription.cycle }}) · {{ t(`plan.status.${org.subscription.status}`) }}
            <span v-if="org.subscription.paid_until" class="text-muted"> · {{ t('admin.paid_until', { date: formatTime(org.subscription.paid_until) }) }}</span>
          </p>
          <p v-else class="text-muted">{{ t('admin.no_subscription') }}</p>
          <table v-if="org.invoices.length" class="data-table compact">
            <caption class="text-muted small">{{ t('admin.invoices') }}</caption>
            <thead><tr><th>{{ t('admin.invoice_number') }}</th><th>{{ t('admin.invoice_date') }}</th><th class="num">{{ t('admin.invoice_value') }}</th><th>{{ t('admin.invoice_status') }}</th></tr></thead>
            <tbody>
              <tr v-for="(i, idx) in org.invoices" :key="idx">
                <td><a v-if="i.pdf_url" :href="i.pdf_url" target="_blank" rel="noopener">{{ i.number || '—' }}</a><span v-else>{{ i.number || '—' }}</span></td>
                <td>{{ i.effective_date || '—' }}</td>
                <td class="num">{{ formatMoney(i.value_cents) }}</td>
                <td>{{ i.status }}</td>
              </tr>
            </tbody>
          </table>
        </section>

        <section class="section" aria-labelledby="org-audit-title">
          <h3 id="org-audit-title" class="section-title">{{ t('admin.audit') }}</h3>
          <ol v-if="org.audit.length" class="audit">
            <li v-for="(a, idx) in org.audit" :key="idx">
              <code>{{ a.action }}</code>
              <span class="text-muted small"> · {{ formatTime(a.created_at) }}<span v-if="a.actor_email"> · {{ a.actor_email }}</span></span>
            </li>
          </ol>
          <p v-else class="text-muted">—</p>
        </section>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePlatformStore } from '../stores/platform';
import { useUiStore } from '../stores/ui';

// Detail and management of one organization (#248).
const props = defineProps({ tenantId: { type: String, default: null } });
const emit = defineEmits(['close', 'open-user', 'temporary-password', 'changed']);

const { t, locale } = useI18n();
const store = usePlatformStore();
const uiStore = useUiStore();

const roles = ['owner', 'admin', 'viewer'];
const org = ref(null);
const error = ref('');
const busy = ref(false);
const form = ref({ name: '', slug: '' });
const memberForm = ref({ email: '', role: 'viewer' });

const owners = computed(() => (org.value ? org.value.members.filter((m) => m.role === 'owner').length : 0));
const slugChanged = computed(() => org.value && form.value.slug.trim() !== org.value.slug);
const dirty = computed(() => org.value && (form.value.name.trim() !== org.value.name || slugChanged.value));
const trialText = computed(() => {
  const trial = org.value?.trial;
  if (!trial || !trial.started_at) return t('admin.no_trial');
  if (trial.active) return t('admin.trial_until', { date: formatTime(trial.ends_at) });
  return t('admin.trial_ended', { date: formatTime(trial.ended_at || trial.ends_at) });
});

function errorText(e) {
  const fieldErrors = e.data?.errors ? Object.values(e.data.errors).flat() : [];
  return fieldErrors.length ? fieldErrors.join(' ') : (e.data?.message || e.message);
}
function formatTime(iso) {
  return iso ? new Date(iso).toLocaleString(locale.value) : '—';
}
function formatMoney(cents) {
  return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'BRL' }).format((cents || 0) / 100);
}

async function load() {
  error.value = '';
  if (!props.tenantId) {
    org.value = null;
    return;
  }
  try {
    const data = await store.fetchTenant(props.tenantId);
    org.value = data;
    form.value = { name: data.name, slug: data.slug };
  } catch (e) {
    error.value = errorText(e);
  }
}
watch(() => props.tenantId, () => { org.value = null; load(); }, { immediate: true });

function close() {
  emit('close');
}

async function run(action, success) {
  busy.value = true;
  error.value = '';
  try {
    const result = await action();
    if (success) uiStore.notify(success, 'success');
    await load();
    emit('changed');
    return result;
  } catch (e) {
    error.value = errorText(e);
    return null;
  } finally {
    busy.value = false;
  }
}

async function save() {
  const data = {};
  if (form.value.name.trim() !== org.value.name) data.name = form.value.name.trim();
  if (slugChanged.value) {
    const slug = form.value.slug.trim();
    const ok = await uiStore.confirm(t('admin.slug_change_confirm', { from: org.value.slug, to: slug }), {
      typed: slug,
      typedLabel: t('admin.type_to_confirm', { text: slug }),
    });
    if (!ok) return;
    data.slug = slug;
  }
  await run(() => store.updateTenant(org.value.id, data), t('admin.org_saved'));
}

async function changeRole(member, event) {
  const role = event.target.value;
  if (!(await uiStore.confirm(t('admin.role_confirm', { name: member.user.name, role: t(`users.roles.${role}`) })))) {
    event.target.value = member.role;
    return;
  }
  const result = await run(() => store.updateMember(org.value.id, member.id, role), t('users.role_changed'));
  if (result === null) event.target.value = member.role;
}

async function makeOwner(member) {
  if (!(await uiStore.confirm(t('admin.transfer_confirm', { name: member.user.name, org: org.value.name })))) return;
  await run(() => store.transferOwnership(org.value.id, member.id), t('admin.ownership_transferred'));
}

async function removeMember(member) {
  if (!(await uiStore.confirm(t('users.remove_confirm', { name: member.user.name, org: org.value.name })))) return;
  await run(() => store.removeMember(org.value.id, member.id), t('admin.member_removed'));
}

async function addMember() {
  const email = memberForm.value.email.trim();
  const res = await run(() => store.addMember(org.value.id, { email, role: memberForm.value.role }), null);
  if (!res) return;
  memberForm.value = { email: '', role: 'viewer' };
  if (res.temporary_password) {
    emit('temporary-password', { email, password: res.temporary_password });
  } else {
    uiStore.notify(t('users.added_existing', { email }), 'success');
  }
}
</script>

<style scoped>
.dialog-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 1rem;
  overflow-y: auto;
  z-index: 50;
}
.dialog {
  width: 100%;
  max-width: 760px;
  margin: auto 0;
}
.dialog-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.75rem;
}
.dialog-title {
  font-size: 1.15rem;
  margin: 0;
  overflow-wrap: anywhere;
}
.badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 1rem;
}
.chip {
  display: inline-block;
  padding: 0.1rem 0.6rem;
  border: 1px solid var(--border-color);
  border-radius: 9999px;
  font-size: 0.8125rem;
}
.facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px 24px;
  margin: 0 0 1rem;
}
.facts dt { font-size: 0.8125rem; color: var(--text-secondary); }
.facts dd { margin: 2px 0 0; overflow-wrap: anywhere; }
.section {
  border-top: 1px solid var(--border-color);
  padding-top: 1rem;
  margin-top: 1rem;
}
.section-title {
  font-size: 1rem;
  margin: 0 0 0.75rem;
}
.plain-list, .member-list, .audit { list-style: none; padding: 0; margin: 0; }
.plain-list li, .audit li { padding: 0.25rem 0; overflow-wrap: anywhere; }
.member {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  padding: 0.5rem 0;
  border-bottom: 1px solid var(--border-color);
}
.member-who { min-width: 0; overflow-wrap: anywhere; }
.member-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}
.add-member {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0 0.75rem;
  margin-top: 1rem;
}
.add-member .grow { flex: 1 1 220px; }
.add-member .btn { margin-bottom: 1rem; }
.link-button {
  background: none;
  border: 0;
  padding: 0;
  color: var(--text-link);
  text-decoration: underline;
  cursor: pointer;
  font: inherit;
  min-height: 32px;
  text-align: start;
}
.tiny { font-size: 0.75rem; margin-inline-start: 0.35rem; }
.data-table.compact { width: 100%; margin-top: 0.5rem; }
.data-table caption { text-align: start; padding-bottom: 0.25rem; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.grid-cols-2 { gap: 0 1rem; }
.small { font-size: 0.8125rem; }
.form-control-sm {
  width: auto;
  min-width: 120px;
  min-height: 36px;
  padding: 0.3rem 0.6rem;
}
.btn-sm {
  min-height: 36px;
  padding: 0.3rem 0.8rem;
  font-size: 0.875rem;
}
</style>
