<template>
  <div class="status-page-admin">
    <div class="page-header">
      <h1 class="page-title">{{ t('status_page.title') }}</h1>
      <a v-if="store.page" :href="store.page.public_url" target="_blank" rel="noopener" class="btn btn-secondary">
        ↗ {{ t('status_page.open_public') }}
      </a>
    </div>

    <div v-if="!hasContext" class="card">
      <p class="text-muted">No tenant/environment context resolved yet. Log out and back in.</p>
    </div>

    <template v-else>
      <div class="card section-card">
        <h2 class="section-title">{{ t('status_page.settings') }}</h2>
        <p v-if="!store.loading && !store.page" class="alert alert-info">{{ t('status_page.none_yet') }}</p>

        <form @submit.prevent="handleSavePage">
          <fieldset class="form-fieldset" :disabled="!canWrite">
          <div class="grid-cols-2">
            <div class="form-group">
              <label class="form-label" for="sp-title">{{ t('status_page.page_title') }}</label>
              <input id="sp-title" v-model="pageForm.title" required maxlength="255" class="form-control" />
            </div>
            <div class="form-group">
              <label class="form-label" for="sp-slug">{{ t('status_page.slug') }}</label>
              <input id="sp-slug" v-model="pageForm.slug" required maxlength="120" pattern="[a-z0-9]+(-[a-z0-9]+)*" class="form-control" />
              <small class="text-muted">/status/{{ pageForm.slug || '…' }}</small>
            </div>
          </div>
          <div class="grid-cols-3">
            <div class="form-group">
              <label class="form-label" for="sp-visibility">{{ t('status_page.visibility') }}</label>
              <select id="sp-visibility" v-model="pageForm.visibility" class="form-control">
                <option value="public">{{ t('status_page.public') }}</option>
                <option value="unlisted">{{ t('status_page.unlisted') }}</option>
                <option value="private">{{ t('status_page.private') }}</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="sp-locale">{{ t('status_page.locale') }}</label>
              <select id="sp-locale" v-model="pageForm.locale" class="form-control">
                <option value="pt_BR">Português (Brasil)</option>
                <option value="en">English</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="sp-timezone">{{ t('status_page.timezone') }}</label>
              <select id="sp-timezone" v-model="pageForm.timezone" class="form-control">
                <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" for="sp-domain">{{ t('status_page.custom_domain') }}</label>
            <input id="sp-domain" v-model="pageForm.custom_domain" maxlength="253" placeholder="status.example.com" class="form-control" />
            <small class="text-muted">
              {{ t('status_page.custom_domain_hint', { host: appHost }) }}
              <a v-if="store.page?.custom_url" :href="store.page.custom_url" target="_blank" rel="noopener">{{ store.page.custom_url }}</a>
            </small>
          </div>
          <button v-if="canWrite" type="submit" class="btn btn-primary" :disabled="savingPage">
            {{ savingPage ? '...' : t('status_page.save') }}
          </button>
          </fieldset>
        </form>
      </div>

      <div v-if="store.page" class="card section-card">
        <h2 class="section-title">{{ t('status_page.branding') }}</h2>
        <p class="text-muted hint">{{ t('status_page.branding_hint') }}</p>

        <div class="form-group">
          <span class="form-label">{{ t('status_page.logo') }}</span>
          <div class="logo-row">
            <div class="logo-preview" :style="brandForm.use_accent ? { background: brandForm.accent_color } : {}">
              <img v-if="store.page.logo_url" :src="store.page.logo_url" :alt="store.page.title" />
              <span v-else class="logo-placeholder">{{ store.page.title }}</span>
            </div>
            <div v-if="canWrite" class="actions">
              <label class="btn btn-secondary btn-sm file-button">
                {{ uploadingLogo ? '...' : t('status_page.upload_logo') }}
                <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" :disabled="uploadingLogo" @change="handleLogo" />
              </label>
              <button v-if="store.page.logo_url" type="button" class="btn btn-danger btn-sm" @click="handleRemoveLogo">{{ t('status_page.remove_logo') }}</button>
            </div>
          </div>
          <small class="text-muted">{{ t('status_page.logo_hint') }}</small>
        </div>

        <form @submit.prevent="handleSaveBranding">
          <fieldset class="form-fieldset" :disabled="!canWrite">
          <div class="form-group">
            <label class="checkbox-label">
              <input type="checkbox" v-model="brandForm.use_accent" />
              {{ t('status_page.use_accent') }}
            </label>
            <div v-if="brandForm.use_accent" class="color-row">
              <input id="sp-accent" v-model="brandForm.accent_color" type="color" :aria-label="t('status_page.accent_color')" />
              <code>{{ brandForm.accent_color }}</code>
            </div>
          </div>
          <div class="grid-cols-2-fixed">
            <div class="form-group">
              <label class="form-label" for="sp-website">{{ t('status_page.website_url') }}</label>
              <input id="sp-website" v-model="brandForm.website_url" type="url" maxlength="255" placeholder="https://" class="form-control" />
            </div>
            <div class="form-group">
              <label class="form-label" for="sp-privacy">{{ t('status_page.privacy_url') }}</label>
              <input id="sp-privacy" v-model="brandForm.privacy_url" type="url" maxlength="255" placeholder="https://" class="form-control" />
            </div>
          </div>
          <div class="form-group">
            <label class="checkbox-label">
              <input type="checkbox" v-model="brandForm.show_powered_by" />
              {{ t('status_page.show_powered_by') }}
            </label>
          </div>
          <div class="form-group">
            <label class="checkbox-label">
              <input type="checkbox" v-model="brandForm.subscriptions_enabled" aria-describedby="sp-subs-hint" />
              {{ t('status_page.subscriptions_enabled') }}
            </label>
            <small id="sp-subs-hint" class="text-muted">
              {{ t('status_page.subscriptions_hint') }}
              <template v-if="store.page.subscriptions_enabled"> {{ t('status_page.subscriber_count', { n: store.page.subscriber_count ?? 0 }) }}</template>
            </small>
          </div>
          <button v-if="canWrite" type="submit" class="btn btn-primary" :disabled="savingBranding">
            {{ savingBranding ? '...' : t('status_page.save_branding') }}
          </button>
          </fieldset>
        </form>
      </div>

      <div v-if="store.page" class="card section-card">
        <div class="section-header">
          <div>
            <h2 class="section-title">{{ t('status_page.components') }}</h2>
            <p class="text-muted hint">{{ t('status_page.components_hint') }}</p>
          </div>
          <button v-if="!editor && canWrite" class="btn btn-primary" @click="openEditor()">＋ {{ t('status_page.add_component') }}</button>
        </div>

        <form v-if="editor" class="editor" @submit.prevent="handleSaveComponent">
          <div class="form-group">
            <label class="form-label" for="sp-component-name">{{ t('status_page.component_name') }}</label>
            <input id="sp-component-name" v-model="editor.name" required maxlength="255" class="form-control" />
          </div>
          <fieldset class="form-group">
            <legend class="form-label">{{ t('status_page.monitors') }}</legend>
            <div class="monitor-checklist">
              <label v-for="m in monitorsStore.monitors" :key="m.public_id" class="checkbox-label">
                <input type="checkbox" :value="m.public_id" v-model="editor.monitor_ids" />
                {{ m.name }} <span class="text-muted">({{ m.kind }})</span>
              </label>
              <p v-if="monitorsStore.monitors.length === 0" class="text-muted">{{ t('status_page.no_monitors') }}</p>
            </div>
          </fieldset>
          <div class="actions">
            <button type="submit" class="btn btn-primary" :disabled="savingComponent">
              {{ savingComponent ? '...' : t('status_page.save_component') }}
            </button>
            <button type="button" class="btn btn-secondary" @click="editor = null">{{ t('status_page.cancel') }}</button>
          </div>
        </form>

        <ul class="component-list">
          <li v-for="c in store.components" :key="c.id" class="component-row">
            <div>
              <strong>{{ c.name }}</strong>
              <div class="text-muted monitor-names">{{ monitorNames(c.monitor_ids) || '—' }}</div>
            </div>
            <div class="actions">
              <button v-if="canWrite" class="btn btn-secondary btn-sm" @click="openEditor(c)">{{ t('status_page.edit') }}</button>
              <button v-if="canWrite" class="btn btn-danger btn-sm" @click="handleDelete(c)">{{ t('status_page.delete') }}</button>
            </div>
          </li>
          <li v-if="store.components.length === 0" class="text-muted text-center empty">{{ t('status_page.no_components') }}</li>
        </ul>
      </div>
      <div v-if="store.page" class="card section-card">
        <div class="section-header">
          <div>
            <h2 class="section-title">{{ t('status_page.updates') }}</h2>
            <p class="text-muted hint">{{ t('status_page.updates_hint') }}</p>
          </div>
          <button v-if="!updateEditor && canWrite" class="btn btn-primary" @click="openUpdateEditor()">＋ {{ t('status_page.add_update') }}</button>
        </div>

        <form v-if="updateEditor" class="editor" @submit.prevent="handleSaveUpdate">
          <div class="form-group">
            <label class="form-label" for="sp-update-title">{{ t('status_page.update_title') }}</label>
            <input id="sp-update-title" v-model="updateEditor.title" required maxlength="255" class="form-control" />
          </div>
          <div class="grid-cols-2-fixed">
            <div class="form-group">
              <label class="form-label" for="sp-update-status">{{ t('status_page.update_status') }}</label>
              <select id="sp-update-status" v-model="updateEditor.status" class="form-control">
                <option v-for="s in updateStatuses" :key="s" :value="s">{{ t(`status_page.statuses.${s}`) }}</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="sp-update-impact">{{ t('status_page.update_impact') }}</label>
              <select id="sp-update-impact" v-model="updateEditor.impact" class="form-control">
                <option v-for="i in updateImpacts" :key="i" :value="i">{{ t(`status_page.impacts.${i}`) }}</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" for="sp-update-body">{{ t('status_page.update_body') }}</label>
            <textarea id="sp-update-body" v-model="updateEditor.body" rows="4" maxlength="5000" class="form-control"></textarea>
          </div>
          <div class="actions">
            <button type="submit" class="btn btn-primary" :disabled="savingUpdate">
              {{ savingUpdate ? '...' : updateEditor.id ? t('status_page.save_update') : t('status_page.publish') }}
            </button>
            <button type="button" class="btn btn-secondary" @click="updateEditor = null">{{ t('status_page.cancel') }}</button>
          </div>
        </form>

        <ul class="component-list">
          <li v-for="u in store.updates" :key="u.id" class="component-row">
            <div class="update-summary">
              <div>
                <span class="badge" :class="`badge-${u.status === 'resolved' ? 'resolved' : u.impact}`">{{ t(`status_page.statuses.${u.status}`) }}</span>
                <strong>{{ u.title }}</strong>
              </div>
              <div v-if="u.body" class="update-body">{{ u.body }}</div>
              <div class="text-muted monitor-names">
                {{ formatDate(u.published_at) }}
                <template v-if="isEdited(u)"> · {{ t('status_page.edited') }} {{ formatDate(u.updated_at) }}</template>
              </div>
            </div>
            <div class="actions">
              <button v-if="canWrite" class="btn btn-secondary btn-sm" @click="openUpdateEditor(u)">{{ t('status_page.edit') }}</button>
              <button v-if="canWrite" class="btn btn-danger btn-sm" @click="handleDeleteUpdate(u)">{{ t('status_page.delete') }}</button>
            </div>
          </li>
          <li v-if="store.updates.length === 0" class="text-muted text-center empty">{{ t('status_page.no_updates') }}</li>
        </ul>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useTenantStore } from '../stores/tenant';
import { useI18n } from 'vue-i18n';
import { useStatusPageStore } from '../stores/statusPage';
import { useMonitorsStore } from '../stores/monitors';
import { useUiStore } from '../stores/ui';

// Viewers are read-only (#160).
const canWrite = computed(() => useTenantStore().canWrite);

const { t } = useI18n();
// The CNAME target of a custom domain is this installation's own host (#251).
const appHost = window.location.hostname;
const store = useStatusPageStore();
const monitorsStore = useMonitorsStore();
const uiStore = useUiStore();

const tenantId = localStorage.getItem('current_tenant_id');
const envId = localStorage.getItem('current_environment_id');
const hasContext = computed(() => !!tenantId && !!envId);

const timezones = ['America/Sao_Paulo', 'UTC', 'America/New_York', 'Europe/London', 'Europe/Lisbon'];
const pageForm = ref({ title: '', slug: '', custom_domain: '', visibility: 'public', locale: 'pt_BR', timezone: 'America/Sao_Paulo' });
const savingPage = ref(false);
const brandForm = ref({ use_accent: false, accent_color: '#252525', website_url: '', privacy_url: '', show_powered_by: true, subscriptions_enabled: false });
const savingBranding = ref(false);
const uploadingLogo = ref(false);
const editor = ref(null);
const savingComponent = ref(false);
const updateStatuses = ['investigating', 'identified', 'monitoring', 'resolved', 'scheduled', 'notice'];
const updateImpacts = ['none', 'degraded', 'major_outage', 'maintenance'];
const updateEditor = ref(null);
const savingUpdate = ref(false);

onMounted(async () => {
  if (!hasContext.value) return;
  monitorsStore.fetchMonitors(tenantId, envId);
  try {
    await store.fetchAll(tenantId, envId);
    if (store.page) {
      const { title, slug, visibility, locale, timezone, custom_domain } = store.page;
      pageForm.value = { title, slug, visibility, locale, timezone, custom_domain: custom_domain || '' };
      fillBrandForm(store.page);
    }
  } catch (e) {
    uiStore.notify(e.message, 'error');
  }
});

function errorText(e) {
  if (e.status === 403) return t('status_page.read_only');
  const fieldErrors = e.data?.errors ? Object.values(e.data.errors).flat() : [];
  return fieldErrors.length ? fieldErrors.join(' ') : e.message;
}

function monitorNames(ids) {
  return ids
    .map((id) => monitorsStore.monitors.find((m) => m.public_id === id)?.name)
    .filter(Boolean)
    .join(', ');
}

async function handleSavePage() {
  savingPage.value = true;
  try {
    await store.savePage(tenantId, envId, { ...pageForm.value, custom_domain: pageForm.value.custom_domain.trim() || null });
    pageForm.value.custom_domain = store.page.custom_domain || '';
    uiStore.notify(t('status_page.saved'), 'success');
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  } finally {
    savingPage.value = false;
  }
}

function fillBrandForm(page) {
  brandForm.value = {
    use_accent: !!page.accent_color,
    accent_color: page.accent_color || '#252525',
    website_url: page.website_url || '',
    privacy_url: page.privacy_url || '',
    show_powered_by: page.show_powered_by !== false,
    subscriptions_enabled: !!page.subscriptions_enabled,
  };
}

async function handleSaveBranding() {
  savingBranding.value = true;
  try {
    const f = brandForm.value;
    await store.savePage(tenantId, envId, {
      accent_color: f.use_accent ? f.accent_color : null,
      website_url: f.website_url || null,
      privacy_url: f.privacy_url || null,
      show_powered_by: f.show_powered_by,
      subscriptions_enabled: f.subscriptions_enabled,
    });
    fillBrandForm(store.page);
    uiStore.notify(t('status_page.saved'), 'success');
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  } finally {
    savingBranding.value = false;
  }
}

async function handleLogo(event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file) return;
  uploadingLogo.value = true;
  try {
    await store.uploadLogo(tenantId, envId, file);
    uiStore.notify(t('status_page.logo_saved'), 'success');
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  } finally {
    uploadingLogo.value = false;
  }
}

async function handleRemoveLogo() {
  try {
    await store.removeLogo(tenantId, envId);
    uiStore.notify(t('status_page.logo_saved'), 'success');
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  }
}

function openEditor(component = null) {
  editor.value = component
    ? { id: component.id, name: component.name, monitor_ids: [...component.monitor_ids] }
    : { id: null, name: '', monitor_ids: [] };
}

async function handleSaveComponent() {
  savingComponent.value = true;
  try {
    const { id, name, monitor_ids } = editor.value;
    if (id) {
      await store.updateComponent(tenantId, envId, id, { name, monitor_ids });
    } else {
      await store.createComponent(tenantId, envId, { name, monitor_ids });
    }
    editor.value = null;
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  } finally {
    savingComponent.value = false;
  }
}

function openUpdateEditor(update = null) {
  updateEditor.value = update
    ? { id: update.id, title: update.title, status: update.status, impact: update.impact, body: update.body || '' }
    : { id: null, title: '', status: 'investigating', impact: 'degraded', body: '' };
}

async function handleSaveUpdate() {
  savingUpdate.value = true;
  try {
    const { id, title, status, impact, body } = updateEditor.value;
    const data = { title, status, impact, body: body || null };
    if (id) {
      await store.editUpdate(tenantId, envId, id, data);
      uiStore.notify(t('status_page.update_saved'), 'success');
    } else {
      await store.publishUpdate(tenantId, envId, data);
      uiStore.notify(t('status_page.update_published'), 'success');
    }
    updateEditor.value = null;
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  } finally {
    savingUpdate.value = false;
  }
}

async function handleDeleteUpdate(update) {
  if (!(await uiStore.confirm(t('status_page.update_delete_confirm')))) return;
  try {
    await store.deleteUpdate(tenantId, envId, update.id);
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  }
}

function formatDate(value) {
  return value ? new Date(value).toLocaleString() : '';
}

function isEdited(update) {
  return update.updated_at && new Date(update.updated_at) - new Date(update.published_at) > 60000;
}

async function handleDelete(component) {
  if (!(await uiStore.confirm(t('status_page.delete_confirm')))) return;
  try {
    await store.deleteComponent(tenantId, envId, component.id);
  } catch (e) {
    uiStore.notify(errorText(e), 'error');
  }
}
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  margin-bottom: 1.5rem;
}
.section-card {
  margin-bottom: 1.5rem;
}
.section-title {
  font-size: 1.1rem;
  margin: 0 0 1rem;
}
.section-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  flex-wrap: wrap;
}
.hint {
  margin: -0.5rem 0 1rem;
  font-size: 0.85rem;
}
.grid-cols-2 {
  gap: 1rem;
}
.grid-cols-3 {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
}
@media (max-width: 1024px) {
  .grid-cols-3 {
    grid-template-columns: 1fr;
  }
}
fieldset {
  border: 0;
  padding: 0;
  margin: 0;
}
.editor {
  border: 1px solid var(--border-color);
  border-radius: var(--radius-md);
  padding: 1rem;
  margin-bottom: 1rem;
}
.monitor-checklist {
  max-height: 200px;
  overflow-y: auto;
}
.checkbox-label {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-top: 0.4rem;
  font-size: 0.9rem;
}
.actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}
.component-list {
  list-style: none;
  padding: 0;
  margin: 0;
}
.component-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--border-color);
}
.monitor-names {
  font-size: 0.8rem;
}
.empty {
  padding: 1rem 0;
}
.logo-row {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  margin: 0.4rem 0;
}
.logo-preview {
  background: var(--sign-bg);
  border-radius: var(--radius-md);
  padding: 0.75rem 1rem;
  min-height: 72px;
  min-width: 160px;
  display: flex;
  align-items: center;
}
.logo-preview img {
  max-height: 48px;
  max-width: 240px;
}
.logo-placeholder {
  color: var(--sign-text);
  font-weight: 700;
}
.file-button {
  position: relative;
  overflow: hidden;
  cursor: pointer;
}
.file-button input {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
}
.color-row {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin-top: 0.5rem;
}
.color-row input[type='color'] {
  width: 3rem;
  height: 2rem;
  border: 0;
  padding: 0;
  background: none;
}
.grid-cols-2-fixed {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1rem;
}
@media (max-width: 640px) {
  .grid-cols-2-fixed {
    grid-template-columns: 1fr;
  }
}
.update-summary {
  min-width: 0;
}
.update-body {
  white-space: pre-line;
  font-size: 0.9rem;
  margin: 0.25rem 0;
  overflow-wrap: anywhere;
}
.badge {
  display: inline-block;
  font-size: 0.7rem;
  font-weight: 600;
  padding: 0.1rem 0.5rem;
  border-radius: 999px;
  margin-right: 0.4rem;
  background: var(--border-color);
}
.badge-degraded { background: var(--status-degraded-bg); color: var(--status-degraded-fg); }
.badge-major_outage { background: var(--status-down-bg); color: var(--status-down-fg); }
.badge-maintenance { background: var(--status-maintenance-bg); color: var(--text-primary); }
.badge-resolved { background: var(--status-up-bg); color: var(--status-up-fg); }
.btn-sm {
  padding: 0.25rem 0.6rem;
  font-size: 0.75rem;
}
</style>
