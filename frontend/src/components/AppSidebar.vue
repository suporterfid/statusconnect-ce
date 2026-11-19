<template>
  <aside class="sidebar">
    <div class="sidebar-brand">
      <BrandMark />
      <span class="logo-text">StatusConnect</span>
    </div>
    <nav class="sidebar-nav">
      <router-link to="/dashboard" class="nav-item" active-class="active">
        <span>{{ t('app.dashboard') }}</span>
      </router-link>
      <router-link to="/monitors" class="nav-item" active-class="active">
        <span>{{ t('app.monitors') }}</span>
      </router-link>
      <router-link to="/incidents" class="nav-item" active-class="active">
        <span>{{ t('app.incidents') }}</span>
      </router-link>
      <router-link to="/maintenance-windows" class="nav-item" active-class="active">
        <span>{{ t('app.maintenance') }}</span>
      </router-link>
      <router-link to="/notification-channels" class="nav-item" active-class="active">
        <span>{{ t('app.notifications') }}</span>
      </router-link>
      <router-link to="/status-page" class="nav-item" active-class="active">
        <span>{{ t('app.status_page') }}</span>
      </router-link>
      <router-link to="/members" class="nav-item" active-class="active">
        <span>{{ t('app.members') }}</span>
      </router-link>
      <router-link v-if="showPlan" to="/plan" class="nav-item" active-class="active">
        <span>{{ t('app.plan') }}</span>
      </router-link>
      <router-link to="/api-keys" class="nav-item" active-class="active">
        <span>{{ t('app.api_keys') }}</span>
      </router-link>
      <router-link v-if="auth.user?.is_platform_admin" to="/administration" class="nav-item" active-class="active">
        <span>{{ t('app.administration') }}</span>
      </router-link>
      <router-link to="/settings" class="nav-item" active-class="active">
        <span>{{ t('app.settings') }}</span>
      </router-link>
    </nav>
  </aside>
</template>

<script setup>
import { useI18n } from 'vue-i18n';
import BrandMark from './BrandMark.vue';
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
const { t } = useI18n();
const auth = useAuthStore();
const router = useRouter();
// The Plan page is part of the enterprise edition (#250).
const showPlan = computed(() => router.hasRoute('plan') && !!auth.features?.plans);
</script>

