import { defineStore } from 'pinia';
import { apiRequest } from '../api/client';
import { useTenantStore } from './tenant';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    features: {},
    token: localStorage.getItem('auth_token') || null,
    loading: false,
  }),
  getters: {
    isAuthenticated: (state) => !!state.user || !!state.token,
  },
  actions: {
    async login(email, password) {
      this.loading = true;
      try {
        const res = await apiRequest('/v1/auth/login', {
          method: 'POST',
          body: JSON.stringify({ email, password }),
        });
        if (res?.token) {
          this.token = res.token;
          localStorage.setItem('auth_token', res.token);
        }
        await this.fetchMe();
      } finally {
        this.loading = false;
      }
    },
    async fetchMe() {
      try {
        const res = await apiRequest('/v1/me');
        this.user = res.user || res.data || res;
        this.features = res.meta?.features || {};
        if (this.user?.locale) {
          localStorage.setItem('user_locale', this.user.locale);
        }
        await this.fetchTenantContext();
      } catch (e) {
        this.logout();
      }
    },
    async fetchTenantContext() {
      const tenantStore = useTenantStore();
      try {
        const res = await apiRequest('/v1/me/memberships');
        const memberships = res.data || [];
        tenantStore.memberships = memberships;
        const context = tenantStore.resolveContext(
          localStorage.getItem('current_tenant_id'),
          localStorage.getItem('current_environment_id'),
        );
        if (context) {
          tenantStore.setContext(context.tenant, context.environment);
        } else {
          console.warn('[auth] /v1/me/memberships returned no usable tenant/environment', res);
        }
      } catch (e) {
        // Logged (not just swallowed) so a real failure is diagnosable in
        // devtools instead of silently leaving every tenant-scoped view empty.
        console.error('[auth] fetchTenantContext failed', e);
      }
    },
    async logout() {
      try {
        await apiRequest('/v1/auth/logout', { method: 'POST' });
      } catch (e) {
        // Session may already be invalid server-side; clear local state regardless.
      }
      this.user = null;
      this.token = null;
      localStorage.removeItem('auth_token');
      localStorage.removeItem('current_tenant_id');
      localStorage.removeItem('current_environment_id');
      useTenantStore().setContext(null, null);
    }
  }
});
