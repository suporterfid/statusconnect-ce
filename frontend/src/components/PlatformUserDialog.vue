<template>
  <div v-if="userId" class="dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="platform-user-title" @keydown.esc="close">
    <div class="card dialog">
      <header class="dialog-head">
        <h2 id="platform-user-title" class="dialog-title">{{ user ? user.name : t('admin.loading') }}</h2>
        <button type="button" class="btn btn-secondary btn-sm" @click="close">{{ t('admin.close') }}</button>
      </header>

      <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>

      <template v-if="user">
        <div class="badges">
          <span v-if="user.blocked" class="status-badge down">{{ t('admin.blocked') }}</span>
          <span v-else class="status-badge up">{{ t('admin.active') }}</span>
          <span v-if="user.is_platform_admin" class="chip">{{ t('users.platform_admin') }}</span>
          <span v-if="!user.email_verified" class="chip">{{ t('admin.email_unverified') }}</span>
          <span v-if="user.id === auth.user?.id" class="chip">{{ t('users.you') }}</span>
        </div>

        <dl class="facts">
          <div><dt>{{ t('users.email') }}</dt><dd>{{ user.email }}</dd></div>
          <div><dt>{{ t('admin.created_at') }}</dt><dd>{{ formatTime(user.created_at) }}</dd></div>
          <div><dt>{{ t('admin.last_login') }}</dt><dd>{{ user.last_login_at ? formatTime(user.last_login_at) : t('admin.never') }}<span v-if="user.last_login_method" class="text-muted"> · {{ t(`admin.methods.${user.last_login_method}`) }}</span></dd></div>
          <div><dt>{{ t('admin.email_verified_at') }}</dt><dd>{{ user.email_verified_at ? formatTime(user.email_verified_at) : t('admin.not_verified') }}</dd></div>
          <div><dt>{{ t('admin.sign_in_methods') }}</dt><dd>{{ user.sign_in_methods.map((m) => t(`admin.methods.${m}`)).join(', ') }}</dd></div>
          <div v-if="user.blocked_at"><dt>{{ t('admin.blocked_at') }}</dt><dd>{{ formatTime(user.blocked_at) }}</dd></div>
        </dl>

        <section class="section" aria-labelledby="user-orgs-title">
          <h3 id="user-orgs-title" class="section-title">{{ t('users.organizations') }}</h3>
          <ul v-if="user.memberships.length" class="plain-list">
            <li v-for="m in user.memberships" :key="m.id || m.tenant.id">
              <button type="button" class="link-button" @click="$emit('open-org', m.tenant.id)">{{ m.tenant.name }}</button>
              <span class="text-muted"> · {{ t(`users.roles.${m.role}`) }}</span>
            </li>
          </ul>
          <p v-else class="text-muted">{{ t('admin.no_orgs') }}</p>
        </section>

        <section class="section" aria-labelledby="user-edit-title">
          <h3 id="user-edit-title" class="section-title">{{ t('admin.edit_user') }}</h3>
          <form @submit.prevent="save">
            <div class="grid-cols-2">
              <div class="form-group">
                <label class="form-label" for="user-name">{{ t('users.name') }}</label>
                <input id="user-name" v-model="form.name" required maxlength="255" class="form-control" />
              </div>
              <div class="form-group">
                <label class="form-label" for="user-email">{{ t('users.email') }}</label>
                <input id="user-email" v-model="form.email" type="email" required maxlength="255" class="form-control" :disabled="user.id === auth.user?.id" />
              </div>
            </div>
            <p v-if="emailChanged" class="alert alert-warning">{{ t('admin.email_change_hint') }}</p>
            <button type="submit" class="btn btn-primary" :disabled="busy || !dirty">{{ t('admin.save') }}</button>
          </form>
        </section>

        <section class="section" aria-labelledby="user-access-title">
          <h3 id="user-access-title" class="section-title">{{ t('admin.access') }}</h3>
          <div class="actions">
            <button v-if="!user.blocked" type="button" class="btn btn-danger" :disabled="busy || user.id === auth.user?.id" @click="block">{{ t('admin.block') }}</button>
            <button v-else type="button" class="btn btn-primary" :disabled="busy" @click="unblock">{{ t('admin.unblock') }}</button>
            <button type="button" class="btn btn-secondary" :disabled="busy" @click="endSessions">{{ t('admin.end_sessions') }}</button>
            <button type="button" class="btn btn-secondary" :disabled="busy" @click="$emit('reset-password', user)">{{ t('users.reset_password') }}</button>
          </div>
          <p class="text-muted small">{{ t('admin.block_hint') }}</p>
        </section>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePlatformStore } from '../stores/platform';
import { useAuthStore } from '../stores/auth';
import { useUiStore } from '../stores/ui';

// Detail and management of one user (#248).
const props = defineProps({ userId: { type: String, default: null } });
const emit = defineEmits(['close', 'open-org', 'reset-password', 'changed']);

const { t, locale } = useI18n();
const store = usePlatformStore();
const auth = useAuthStore();
const uiStore = useUiStore();

const user = ref(null);
const error = ref('');
const busy = ref(false);
const form = ref({ name: '', email: '' });

const emailChanged = computed(() => user.value && form.value.email.trim().toLowerCase() !== user.value.email);
const dirty = computed(() => user.value && (form.value.name.trim() !== user.value.name || emailChanged.value));

function errorText(e) {
  const fieldErrors = e.data?.errors ? Object.values(e.data.errors).flat() : [];
  return fieldErrors.length ? fieldErrors.join(' ') : (e.data?.message || e.message);
}

function formatTime(iso) {
  return iso ? new Date(iso).toLocaleString(locale.value) : '—';
}

function show(data) {
  user.value = data;
  form.value = { name: data.name, email: data.email };
}

async function load() {
  user.value = null;
  error.value = '';
  if (!props.userId) return;
  try {
    show(await store.fetchUser(props.userId));
  } catch (e) {
    error.value = errorText(e);
  }
}
watch(() => props.userId, load, { immediate: true });

function close() {
  emit('close');
}

async function run(action, success) {
  busy.value = true;
  error.value = '';
  try {
    const data = await action();
    if (data && data.id) show(data);
    if (success) uiStore.notify(success, 'success');
    emit('changed');
  } catch (e) {
    error.value = errorText(e);
  } finally {
    busy.value = false;
  }
}

async function save() {
  const data = {};
  if (form.value.name.trim() !== user.value.name) data.name = form.value.name.trim();
  if (emailChanged.value) {
    if (!(await uiStore.confirm(t('admin.email_change_confirm', { from: user.value.email, to: form.value.email.trim() })))) return;
    data.email = form.value.email.trim();
  }
  await run(() => store.updateUser(user.value.id, data), t('admin.user_saved'));
}

async function block() {
  const ok = await uiStore.confirm(t('admin.block_confirm', { email: user.value.email }), {
    typed: user.value.email,
    typedLabel: t('admin.type_to_confirm', { text: user.value.email }),
  });
  if (!ok) return;
  await run(() => store.blockUser(user.value.id, true), t('admin.user_blocked'));
}

async function unblock() {
  if (!(await uiStore.confirm(t('admin.unblock_confirm', { email: user.value.email })))) return;
  await run(() => store.blockUser(user.value.id, false), t('admin.user_unblocked'));
}

async function endSessions() {
  if (!(await uiStore.confirm(t('admin.end_sessions_confirm', { email: user.value.email })))) return;
  await run(async () => { await store.endSessions(user.value.id); return null; }, t('admin.sessions_ended'));
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
  max-width: 720px;
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
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
.plain-list { list-style: none; padding: 0; margin: 0; }
.plain-list li { padding: 0.25rem 0; }
.link-button {
  background: none;
  border: 0;
  padding: 0;
  color: var(--text-link);
  text-decoration: underline;
  cursor: pointer;
  font: inherit;
  min-height: 32px;
}
.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}
.grid-cols-2 { gap: 0 1rem; }
.small { font-size: 0.8125rem; }
.btn-sm {
  min-height: 36px;
  padding: 0.3rem 0.8rem;
  font-size: 0.875rem;
}
</style>
