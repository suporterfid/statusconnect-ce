import { defineStore } from 'pinia';
import { apiRequest } from '../api/client';

export const useTenantStore = defineStore('tenant', {
  state: () => ({
    tenant: null,
    environment: null,
    // Every organization the user can act in, each with its environments
    // (GET /v1/me/memberships). Drives the header switcher.
    memberships: [],
    schedulerFreshness: 'healthy',
    fallingBehind: false,
    dashboardSummary: null,
  }),
  getters: {
    // Role in the current organization (GET /v1/me/memberships): owner, admin, viewer or platform_admin.
    role(state) {
      const id = state.tenant?.id || state.tenant?.public_id;
      return state.memberships.find((m) => m?.tenant?.id === id)?.role || null;
    },
    // Viewers are read-only (#154); the API refuses their writes, so the UI hides write controls (#160).
    canWrite() {
      return this.role !== 'viewer';
    },
  },
  actions: {
    async fetchDashboardSummary(tenantId, environmentId) {
      try {
        const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/dashboard-summary`);
        this.dashboardSummary = res.data || null;
      } catch (e) {
        this.dashboardSummary = null;
      }
    },
    // The stored selection when the user still has access to it, otherwise
    // the first organization/environment. A selection that disappeared (access
    // revoked, environment archived) must never leave the SPA without context.
    resolveContext(tenantId, environmentId) {
      const valid = this.memberships.filter((m) => m?.tenant && m.environments?.length);
      const membership = valid.find((m) => m.tenant.id === tenantId) || valid[0];
      if (!membership) return null;
      const environment = membership.environments.find((e) => e.id === environmentId) || membership.environments[0];
      return { tenant: membership.tenant, environment };
    },
    // Views read the tenant/environment ids once at setup, so a switch reloads
    // the SPA instead of trying to refetch every open view in place.
    switchContext(tenantId, environmentId) {
      const context = this.resolveContext(tenantId, environmentId);
      if (!context) return;
      this.setContext(context.tenant, context.environment);
      window.location.reload();
    },
    setContext(tenant, environment) {
      this.tenant = tenant;
      this.environment = environment;
      // GET /v1/me/memberships (MeMembershipsController) returns each
      // tenant/environment's public identifier as `id`, not `public_id` —
      // this used to only check `public_id`, which is never present here,
      // so the condition silently never matched: no error, no warning,
      // localStorage just never got current_tenant_id/current_environment_id
      // written, and every tenant-scoped view stayed permanently empty.
      const tenantId = tenant?.id || tenant?.public_id;
      const environmentId = environment?.id || environment?.public_id;
      if (tenantId) localStorage.setItem('current_tenant_id', tenantId);
      if (environmentId) localStorage.setItem('current_environment_id', environmentId);
    },
    async fetchHealth() {
      try {
        const res = await apiRequest('/v1/platform/health');
        if (res) {
          this.schedulerFreshness = res.scheduler_freshness || 'healthy';
          this.fallingBehind = !!res.checks_falling_behind;
        }
      } catch (e) {
        // Fallback
      }
    }
  }
});
