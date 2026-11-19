<template>
  <div class="terms-wrapper">
    <main class="card terms-card" aria-labelledby="terms-title">
      <h1 id="terms-title">{{ t('terms.title') }}</h1>
      <p>{{ t('terms.intro') }}</p>
      <ul class="docs">
        <li><a :href="terms?.terms_url" target="_blank" rel="noopener">{{ t('terms.terms_link') }}</a></li>
        <li><a :href="terms?.privacy_url" target="_blank" rel="noopener">{{ t('terms.privacy_link') }}</a></li>
      </ul>
      <p class="text-muted small">{{ t('terms.version', { version: terms?.version }) }}</p>

      <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>

      <form @submit.prevent="handleAccept">
        <label class="checkbox-label accept">
          <input v-model="accepted" type="checkbox" required />
          <span>{{ t('terms.checkbox') }}</span>
        </label>
        <div class="actions">
          <button type="submit" class="btn btn-primary" :disabled="!accepted || saving">{{ saving ? '...' : t('terms.accept') }}</button>
          <button type="button" class="btn btn-secondary" @click="handleLogout">{{ t('app.logout') }}</button>
        </div>
      </form>
    </main>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter, useRoute } from 'vue-router';
import { apiRequest } from '../api/client';
import { useAuthStore } from '../stores/auth';

const { t } = useI18n();
const router = useRouter();
const route = useRoute();
const auth = useAuthStore();

const terms = computed(() => auth.user?.terms);
const accepted = ref(false);
const saving = ref(false);
const error = ref('');

async function handleAccept() {
  saving.value = true;
  error.value = '';
  try {
    const res = await apiRequest('/v1/me/terms-acceptance', {
      method: 'POST',
      body: JSON.stringify({ accept: true, version: terms.value?.version }),
    });
    auth.user = { ...auth.user, terms: res.data };
    const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : '/dashboard';
    router.replace(next);
  } catch (e) {
    const fieldErrors = e.data?.errors ? Object.values(e.data.errors).flat() : [];
    error.value = fieldErrors.length ? fieldErrors.join(' ') : (e.data?.message || e.message);
    if (e.data?.errors?.version) {
      await auth.fetchMe();
    }
  } finally {
    saving.value = false;
  }
}

async function handleLogout() {
  try {
    await apiRequest('/v1/auth/logout', { method: 'POST' });
  } catch (e) {
    // Signing out locally is enough.
  }
  auth.logout();
  router.replace('/login');
}
</script>

<style scoped>
.terms-wrapper { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; background: var(--bg-primary); }
.terms-card { width: 100%; max-width: 520px; }
h1 { font-size: 1.35rem; margin: 0 0 0.75rem; }
.docs { margin: 0 0 0.75rem; padding-left: 1.2rem; }
.docs li { margin: 0.25rem 0; }
.accept { display: flex; gap: 0.5rem; align-items: flex-start; margin: 1rem 0 0; }
.accept input { margin-top: 0.3rem; }
.actions { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 1rem; }
</style>
