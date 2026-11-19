<template>
  <div class="setup-wrapper">
    <div class="card setup-card">
      <div class="setup-header">
        <span class="logo-icon"><BrandMark /></span>
        <h2>{{ t('setup.title') }}</h2>
        <p class="tagline">{{ t('setup.intro') }}</p>
      </div>

      <div v-if="checking" class="text-muted text-center">...</div>

      <div v-else-if="!required" class="text-center">
        <p>{{ t('setup.not_required') }}</p>
        <router-link to="/login" class="btn btn-primary btn-block">{{ t('setup.back_to_login') }}</router-link>
      </div>

      <form v-else @submit.prevent="handleSubmit" class="setup-form">
        <div v-if="restricted" class="alert alert-info">{{ t('setup.restricted') }}</div>
        <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>

        <div class="form-group">
          <label class="form-label" for="setup-name">{{ t('setup.your_name') }}</label>
          <input id="setup-name" v-model="form.name" required maxlength="255" class="form-control" autocomplete="name" />
        </div>
        <div class="form-group">
          <label class="form-label" for="setup-email">{{ t('setup.email') }}</label>
          <input id="setup-email" v-model="form.email" type="email" required class="form-control" autocomplete="email" />
        </div>
        <div class="grid-cols-2">
          <div class="form-group">
            <label class="form-label" for="setup-password">{{ t('setup.password') }}</label>
            <input id="setup-password" v-model="form.password" type="password" required minlength="12" class="form-control" autocomplete="new-password" />
          </div>
          <div class="form-group">
            <label class="form-label" for="setup-password2">{{ t('setup.password_confirmation') }}</label>
            <input id="setup-password2" v-model="form.password_confirmation" type="password" required minlength="12" class="form-control" autocomplete="new-password" />
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="setup-org">{{ t('setup.org') }}</label>
          <input id="setup-org" v-model="form.org" required maxlength="255" class="form-control" @input="suggestSlug" />
        </div>
        <div class="form-group">
          <label class="form-label" for="setup-slug">{{ t('setup.slug') }}</label>
          <input id="setup-slug" v-model="form.slug" required maxlength="120" pattern="[a-z0-9]+(-[a-z0-9]+)*" class="form-control" @input="slugTouched = true" />
          <small class="text-muted">{{ t('setup.slug_hint', { slug: form.slug || 'slug' }) }}</small>
        </div>
        <div class="grid-cols-2">
          <div class="form-group">
            <label class="form-label" for="setup-title">{{ t('setup.page_title') }}</label>
            <input id="setup-title" v-model="form.title" maxlength="255" class="form-control" :placeholder="form.org" />
          </div>
          <div class="form-group">
            <label class="form-label" for="setup-locale">{{ t('setup.locale') }}</label>
            <select id="setup-locale" v-model="form.locale" class="form-control">
              <option value="pt_BR">Português (Brasil)</option>
              <option value="en">English</option>
            </select>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block" :disabled="submitting">
          {{ submitting ? '...' : t('setup.submit') }}
        </button>
        <router-link to="/login" class="back-link">{{ t('setup.back_to_login') }}</router-link>
      </form>
    </div>
  </div>
</template>

<script setup>
import BrandMark from '../components/BrandMark.vue';
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { apiRequest } from '../api/client';
import { useAuthStore } from '../stores/auth';
import { useUiStore } from '../stores/ui';

const { t, locale } = useI18n();
const router = useRouter();
const auth = useAuthStore();
const uiStore = useUiStore();

const checking = ref(true);
const required = ref(false);
const restricted = ref(false);
const submitting = ref(false);
const error = ref('');
const slugTouched = ref(false);
const form = ref({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  org: '',
  slug: '',
  title: '',
  locale: locale.value === 'en' ? 'en' : 'pt_BR',
  timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
});

onMounted(async () => {
  try {
    const res = await apiRequest('/v1/setup');
    required.value = !!res?.data?.setup_required;
    restricted.value = !!res?.data?.restricted;
  } catch (e) {
    required.value = false;
  } finally {
    checking.value = false;
  }
});

function suggestSlug() {
  if (slugTouched.value) return;
  form.value.slug = form.value.org
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
    .slice(0, 120);
}

async function handleSubmit() {
  error.value = '';
  submitting.value = true;
  try {
    const payload = { ...form.value };
    if (!payload.title) delete payload.title;
    await apiRequest('/v1/setup', { method: 'POST', body: JSON.stringify(payload) });
    await auth.fetchMe();
    uiStore.notify(t('setup.done'), 'success');
    router.push('/status-page');
  } catch (e) {
    const fieldErrors = e.data?.errors ? Object.values(e.data.errors).flat() : [];
    error.value = fieldErrors.length ? fieldErrors.join(' ') : (e.data?.message || e.message);
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.setup-wrapper {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  padding: 1rem;
  background: var(--bg-primary);
}
.setup-card {
  width: 100%;
  max-width: 560px;
}
.setup-header {
  text-align: center;
  margin-bottom: 1.5rem;
}
.logo-icon {
  display: inline-grid;
  place-items: center;
  width: 56px;
  height: 56px;
  margin-bottom: 0.75rem;
  border-radius: 12px;
  background: var(--sign-bg);
  color: var(--sign-text);
}
.logo-icon svg { width: 36px; height: 36px; }
.tagline {
  color: var(--text-secondary);
  font-size: 0.9rem;
}
.grid-cols-2 {
  gap: 0.75rem;
}
.back-link {
  display: block;
  text-align: center;
  margin-top: 1rem;
  font-size: 0.85rem;
}
</style>
