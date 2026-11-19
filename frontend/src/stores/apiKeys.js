import { defineStore } from 'pinia';
import { apiRequest } from '../api/client';

export const useApiKeysStore = defineStore('apiKeys', {
  state: () => ({
    keys: [],
    loading: false,
  }),
  actions: {
    async fetchKeys(tenantId, environmentId) {
      this.loading = true;
      try {
        const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/api-keys`);
        this.keys = res.data || [];
      } finally {
        this.loading = false;
      }
    },
    async createKey(tenantId, environmentId, data) {
      const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/api-keys`, {
        method: 'POST',
        body: JSON.stringify(data),
      });
      await this.fetchKeys(tenantId, environmentId);
      return res.data;
    },
    async revokeKey(tenantId, environmentId, apiKeyId) {
      await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/api-keys/${apiKeyId}`, {
        method: 'DELETE',
      });
      await this.fetchKeys(tenantId, environmentId);
    },
  },
});
