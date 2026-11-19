<template>
  <div class="login-wrapper">
    <div class="card login-card">
      <div class="login-header">
        <span class="logo-icon"><BrandMark /></span>
        <h2>{{ t('auth.login_title') }}</h2>
        <p class="tagline">{{ t('app.tagline') }}</p>
      </div>

      <div v-if="googleError" class="alert alert-danger" role="alert">{{ googleError }}</div>

      <template v-if="googleLogin">
        <!-- Sign in with Google (#244): Google's neutral button with the standard "G" mark. -->
        <a href="/auth/google/redirect" class="gsi-button">
          <svg class="gsi-mark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="18" height="18" aria-hidden="true" focusable="false"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
          <span>{{ t('auth.google_login') }}</span>
        </a>
        <div class="divider"><span>{{ t('auth.or_email') }}</span></div>
      </template>

      <form @submit.prevent="handleLogin" class="login-form">
        <div v-if="error" class="alert alert-danger">{{ error }}</div>

        <div class="form-group">
          <label class="form-label">{{ t('auth.email') }}</label>
          <input type="email" v-model="email" required class="form-control" placeholder="admin@statusconnect.local" />
        </div>

        <div class="form-group">
          <label class="form-label">{{ t('auth.password') }}</label>
          <input type="password" v-model="password" required class="form-control" placeholder="••••••••" />
        </div>

        <button type="submit" class="btn btn-primary btn-block" :disabled="loading">
          {{ loading ? '...' : t('auth.login_btn') }}
        </button>

        <div class="account-links">
          <a href="/esqueci-senha">{{ t('auth.forgot_password') }}</a>
          <span v-if="signupOpen">{{ t('auth.no_account') }} <a href="/cadastro">{{ t('auth.create_account') }}</a></span>
        </div>

        <router-link v-if="setupRequired" to="/setup" class="btn btn-secondary btn-block setup-link">
          {{ t('setup.link') }}
        </router-link>

        <div class="divider"><span>OR</span></div>

        <a href="/auth/grandpasson/login/grandpasson" class="btn btn-secondary btn-block">
          {{ t('auth.grandpasson_login') }}
        </a>
      </form>
    </div>
  </div>
</template>

<script setup>
import BrandMark from '../components/BrandMark.vue';
import { ref, onMounted } from 'vue';
import { apiRequest } from '../api/client';
import { useRouter, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';

const { t } = useI18n();
const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const email = ref('');
const password = ref('');
const error = ref('');
const loading = ref(false);
const setupRequired = ref(false);
const signupOpen = ref(false);
const googleLogin = ref(false);

// The Google callback returns here with ?google_error=<reason> when it refuses (#244).
const GOOGLE_ERRORS = ['failed', 'cancelled', 'email_unverified', 'no_account', 'pending', 'mismatch', 'blocked'];
const googleError = (() => {
  const reason = route.query.google_error;
  if (typeof reason !== 'string' || reason === '') return '';
  return t(`auth.google_error.${GOOGLE_ERRORS.includes(reason) ? reason : 'failed'}`);
})();

onMounted(async () => {
  try {
    const res = await apiRequest('/v1/setup');
    setupRequired.value = !!res?.data?.setup_required;
    signupOpen.value = !!res?.data?.signup_open;
    googleLogin.value = !!res?.data?.google_login;
  } catch (e) {
    setupRequired.value = false;
  }
});

async function handleLogin() {
  error.value = '';
  loading.value = true;
  try {
    await auth.login(email.value, password.value);
    router.push('/dashboard');
  } catch (e) {
    // The API says why: wrong password, email not confirmed, or account awaiting approval.
    error.value = e.data?.errors?.email?.[0] || t('auth.invalid_credentials');
  } finally {
    loading.value = false;
  }
}
</script>

<style scoped>
.account-links { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.75rem; font-size: 0.9rem; }
.login-wrapper {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  background: var(--bg-primary);
}
.login-card {
  width: 100%;
  max-width: 420px;
}
.login-header {
  text-align: center;
  margin-bottom: 2rem;
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
  font-size: 0.85rem;
  color: var(--text-secondary);
  margin-top: 0.25rem;
}
.btn-block {
  width: 100%;
  margin-top: 0.5rem;
}
.divider {
  display: flex;
  align-items: center;
  text-align: center;
  margin: 1.5rem 0;
  color: var(--text-muted);
  font-size: 0.75rem;
}
.divider::before, .divider::after {
  content: '';
  flex: 1;
  border-bottom: 1px solid var(--border-color);
}
.divider span {
  padding: 0 0.75rem;
}
.alert-danger {
  background: var(--status-down-bg);
  color: var(--status-down);
  border: 1px solid var(--status-down);
  padding: 0.75rem;
  border-radius: var(--radius-sm);
  font-size: 0.85rem;
  margin-bottom: 1rem;
}
.gsi-button {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  width: 100%;
  min-height: 40px;
  padding: 0 12px;
  border: 1px solid #747775;
  border-radius: var(--radius-sm);
  background: #FFFFFF;
  color: #1F1F1F;
  font-weight: 500;
  font-size: 0.9rem;
  text-decoration: none;
}
.gsi-button:hover { background: #F2F2F2; color: #1F1F1F; }
.gsi-button:focus-visible { outline: 2px solid var(--border-color-focus); outline-offset: 2px; }
.gsi-mark { flex: none; }
@media (prefers-color-scheme: dark) {
  .gsi-button { background: #131314; border-color: #8E918F; color: #E3E3E3; }
  .gsi-button:hover { background: #1F1F21; color: #E3E3E3; }
}
.setup-link {
  margin-top: 0.75rem;
}
</style>
