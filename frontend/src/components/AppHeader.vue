<template>
  <header class="top-header">
    <div class="header-left">
      <label v-if="contextOptions.length > 1" class="context-switcher">
        <span class="visually-hidden">{{ t('app.switch_context') }}</span>
        <select class="form-control context-select" :value="currentContextKey" @change="handleSwitch" :aria-label="t('app.switch_context')">
          <option v-for="option in contextOptions" :key="option.key" :value="option.key">{{ option.label }}</option>
        </select>
      </label>
      <template v-else-if="tenantStore.tenant">
        <span class="tenant-name">{{ tenantStore.tenant.name }}</span>
        <span class="environment-pill">{{ tenantStore.environment?.name }}</span>
      </template>
      <span v-if="tenantStore.tenant && !tenantStore.canWrite" class="readonly-pill" :title="t('app.read_only_hint')">{{ t('app.read_only') }}</span>
    </div>
    <div class="header-right">
      <!-- Language Switcher -->
      <select v-model="currentLocale" @change="changeLocale" class="form-control locale-select">
        <option value="en">English (US)</option>
        <option value="pt-BR">Português (BR)</option>
      </select>

      <div class="user-profile" v-if="auth.user">
        <span class="user-email">{{ auth.user.email }}</span>
        <button class="btn btn-secondary btn-sm" @click="handleLogout">{{ t('app.logout') }}</button>
      </div>
    </div>
  </header>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useTenantStore } from '../stores/tenant';

const auth = useAuthStore();
const router = useRouter();
const { t, locale } = useI18n();

const currentLocale = ref(locale.value);
const tenantStore = useTenantStore();

// One option per organization/environment pair; the environment is only named
// when an organization has more than one.
const contextOptions = computed(() => tenantStore.memberships
  .filter((m) => m?.tenant && m.environments?.length)
  .flatMap((m) => m.environments.map((e) => ({
    key: `${m.tenant.id}|${e.id}`,
    label: m.environments.length > 1 ? `${m.tenant.name} · ${e.name}` : m.tenant.name,
  }))));

const currentContextKey = computed(() => `${tenantStore.tenant?.id}|${tenantStore.environment?.id}`);

function handleSwitch(event) {
  const [tenantId, environmentId] = event.target.value.split('|');
  tenantStore.switchContext(tenantId, environmentId);
}

function changeLocale() {
  locale.value = currentLocale.value;
  localStorage.setItem('user_locale', currentLocale.value);
}

async function handleLogout() {
  await auth.logout();
  router.push({ name: 'login' });
}
</script>

<style scoped>
.header-right {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem 1rem;
}
.environment-pill {
  color: var(--text-primary);
  box-shadow: inset 0 0 0 1px var(--border-strong);
  padding: 0.15rem 0.6rem;
  border-radius: 9999px;
  font-size: 0.8125rem;
  font-weight: 600;
}
.readonly-pill {
  color: var(--text-secondary);
  background: var(--bg-subtle, transparent);
  box-shadow: inset 0 0 0 1px var(--border-strong);
  padding: 0.15rem 0.6rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.header-left {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}
.tenant-name {
  font-weight: 600;
  font-size: 0.9rem;
}
.context-select {
  width: auto;
  max-width: 320px;
  min-height: 36px;
  padding: 0.3rem 0.75rem;
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
.locale-select {
  width: auto;
  min-height: 36px;
  padding: 0.3rem 0.75rem;
  font-size: 0.875rem;
}
.user-profile {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  font-size: 0.85rem;
  color: var(--text-secondary);
}
.btn-sm {
  min-height: 36px;
  padding: 0.3rem 0.8rem;
  font-size: 0.875rem;
}
</style>
