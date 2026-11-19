import { defineStore } from 'pinia';
import { apiRequest } from '../api/client';

export const useMembersStore = defineStore('members', {
  state: () => ({
    members: [],
    actorRole: null,
    loading: false,
  }),
  getters: {
    // Mirrors App\Domain\Shared\MembershipRules so the UI only offers allowed actions.
    effectiveRole: (state) => (state.actorRole === 'platform_admin' ? 'owner' : state.actorRole),
    canGrant() {
      return (role) => this.effectiveRole === 'owner' || (this.effectiveRole === 'admin' && role !== 'owner');
    },
    canManage() {
      return (member) => this.effectiveRole === 'owner' || (this.effectiveRole === 'admin' && member.role !== 'owner');
    },
  },
  actions: {
    async fetch(tenantId) {
      this.loading = true;
      try {
        const res = await apiRequest(`/v1/tenants/${tenantId}/members`);
        this.members = res.data || [];
        this.actorRole = res.meta?.actor_role || null;
      } finally {
        this.loading = false;
      }
    },
    async add(tenantId, data) {
      const res = await apiRequest(`/v1/tenants/${tenantId}/members`, { method: 'POST', body: JSON.stringify(data) });
      await this.fetch(tenantId);
      return res;
    },
    async changeRole(tenantId, membershipId, role) {
      await apiRequest(`/v1/tenants/${tenantId}/members/${membershipId}`, { method: 'PATCH', body: JSON.stringify({ role }) });
      await this.fetch(tenantId);
    },
    async remove(tenantId, membershipId) {
      await apiRequest(`/v1/tenants/${tenantId}/members/${membershipId}`, { method: 'DELETE' });
      await this.fetch(tenantId);
    },
  },
});
