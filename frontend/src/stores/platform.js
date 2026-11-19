import { defineStore } from 'pinia';
import { apiRequest } from '../api/client';

export const usePlatformStore = defineStore('platform', {
  state: () => ({
    users: [],
    tenants: [],
    planIds: ['free', 'pro', 'business', 'unlimited'],
    checker: null,
    loading: false,
  }),
  actions: {
    async fetchUsers(q = '') {
      this.loading = true;
      try {
        const res = await apiRequest(`/v1/platform/users${q ? `?q=${encodeURIComponent(q)}` : ''}`);
        this.users = res.data || [];
      } finally {
        this.loading = false;
      }
    },
    async fetchChecker() {
      const res = await apiRequest('/v1/platform/checker');
      this.checker = res.data || null;
    },
    async fetchTenants() {
      const res = await apiRequest('/v1/platform/tenants');
      this.tenants = res.data || [];
      // Plans of this edition (#250); the Community Edition has only `unlimited`.
      if (Array.isArray(res.meta?.plans)) this.planIds = res.meta.plans;
    },
    async setPlatformAdmin(userId, value) {
      const res = await apiRequest(`/v1/platform/users/${userId}`, { method: 'PATCH', body: JSON.stringify({ is_platform_admin: value }) });
      this.users = this.users.map((u) => (u.id === userId ? res.data : u));
    },
    async resetPassword(userId) {
      const res = await apiRequest(`/v1/platform/users/${userId}/reset-password`, { method: 'POST' });
      return res.data.temporary_password;
    },
    async updateTenant(tenantId, data) {
      const res = await apiRequest(`/v1/platform/tenants/${tenantId}`, { method: 'PATCH', body: JSON.stringify(data) });
      await this.fetchTenants();
      return res;
    },
    // One user or organization (#248).
    async fetchUser(userId) {
      const res = await apiRequest(`/v1/platform/users/${userId}`);
      return res.data;
    },
    async createUser(data) {
      const res = await apiRequest('/v1/platform/users', { method: 'POST', body: JSON.stringify(data) });
      return res.data;
    },
    async updateUser(userId, data) {
      const res = await apiRequest(`/v1/platform/users/${userId}`, { method: 'PATCH', body: JSON.stringify(data) });
      this.replaceUser(res.data);
      return res.data;
    },
    async blockUser(userId, blocked) {
      const res = await apiRequest(`/v1/platform/users/${userId}/${blocked ? 'block' : 'unblock'}`, { method: 'POST' });
      this.replaceUser(res.data);
      return res.data;
    },
    async endSessions(userId) {
      const res = await apiRequest(`/v1/platform/users/${userId}/sessions`, { method: 'DELETE' });
      return res.data;
    },
    replaceUser(user) {
      this.users = this.users.map((u) => (u.id === user.id ? { ...u, ...user } : u));
    },
    async fetchTenant(tenantId) {
      const res = await apiRequest(`/v1/platform/tenants/${tenantId}`);
      return res.data;
    },
    async addMember(tenantId, data) {
      return apiRequest(`/v1/platform/tenants/${tenantId}/members`, { method: 'POST', body: JSON.stringify(data) });
    },
    async updateMember(tenantId, membershipId, role) {
      return apiRequest(`/v1/platform/tenants/${tenantId}/members/${membershipId}`, { method: 'PATCH', body: JSON.stringify({ role }) });
    },
    async removeMember(tenantId, membershipId) {
      return apiRequest(`/v1/platform/tenants/${tenantId}/members/${membershipId}`, { method: 'DELETE' });
    },
    async transferOwnership(tenantId, membershipId) {
      return apiRequest(`/v1/platform/tenants/${tenantId}/transfer-ownership`, { method: 'POST', body: JSON.stringify({ membership_id: membershipId }) });
    },
    async createTenant(data) {
      const res = await apiRequest('/v1/platform/tenants', { method: 'POST', body: JSON.stringify(data) });
      await this.fetchTenants();
      return res;
    },
  },
});
