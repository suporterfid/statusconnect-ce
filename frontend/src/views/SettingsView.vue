<template>
  <div class="settings-page">
    <div class="page-header">
      <h1 class="page-title">{{ t('settings.title') }}</h1>
    </div>

    <div class="card settings-card">
      <form @submit.prevent="handleSave">
        <div class="form-group">
          <label class="form-label">{{ t('settings.language') }}</label>
          <select v-model="selectedLanguage" class="form-control">
            <option value="en">English (United States)</option>
            <option value="pt-BR">Português (Brasil)</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">{{ t('settings.timezone') }}</label>
          <select v-model="selectedTimezone" class="form-control">
            <option value="UTC">UTC (Coordinated Universal Time)</option>
            <option value="America/Sao_Paulo">America/Sao_Paulo (BRT / UTC-3)</option>
            <option value="America/New_York">America/New_York (EST / UTC-5)</option>
            <option value="Europe/London">Europe/London (GMT / UTC+0)</option>
          </select>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">
            {{ t('settings.save') }}
          </button>
        </div>
      </form>
    </div>

    <div class="card settings-card password-card">
      <h2 class="section-title">{{ t('password.title') }}</h2>
      <form @submit.prevent="handleChangePassword">
        <div class="form-group">
          <label class="form-label" for="pw-current">{{ t('password.current') }}</label>
          <input id="pw-current" v-model="passwordForm.current_password" type="password" required class="form-control" autocomplete="current-password" />
        </div>
        <div class="form-group">
          <label class="form-label" for="pw-new">{{ t('password.new') }}</label>
          <input id="pw-new" v-model="passwordForm.password" type="password" required minlength="12" class="form-control" autocomplete="new-password" />
        </div>
        <div class="form-group">
          <label class="form-label" for="pw-confirm">{{ t('password.confirm') }}</label>
          <input id="pw-confirm" v-model="passwordForm.password_confirmation" type="password" required minlength="12" class="form-control" autocomplete="new-password" />
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary" :disabled="changingPassword">
            {{ changingPassword ? '...' : t('password.save') }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useUiStore } from '../stores/ui';
import { apiRequest } from '../api/client';

const { t, locale } = useI18n();
const uiStore = useUiStore();

const selectedLanguage = ref(locale.value);
const selectedTimezone = ref(localStorage.getItem('user_timezone') || 'UTC');

const passwordForm = ref({ current_password: '', password: '', password_confirmation: '' });
const changingPassword = ref(false);

async function handleChangePassword() {
  changingPassword.value = true;
  try {
    await apiRequest('/v1/me/password', { method: 'PUT', body: JSON.stringify(passwordForm.value) });
    passwordForm.value = { current_password: '', password: '', password_confirmation: '' };
    uiStore.notify(t('password.saved'), 'success');
  } catch (e) {
    const fieldErrors = e.data?.errors ? Object.values(e.data.errors).flat() : [];
    uiStore.notify(fieldErrors.length ? fieldErrors.join(' ') : e.message, 'error');
  } finally {
    changingPassword.value = false;
  }
}

function handleSave() {
  locale.value = selectedLanguage.value;
  localStorage.setItem('user_locale', selectedLanguage.value);
  localStorage.setItem('user_timezone', selectedTimezone.value);
  uiStore.notify('Preferences saved successfully!', 'success');
}
</script>

<style scoped>
.page-header {
  margin-bottom: 1.5rem;
}
.settings-card {
  max-width: 600px;
}
.form-actions {
  margin-top: 1.5rem;
}
.password-card {
  margin-top: 1.5rem;
}
.section-title {
  font-size: 1.1rem;
  margin: 0 0 1rem;
}
</style>
