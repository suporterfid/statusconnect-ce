import { defineStore } from 'pinia';
import { apiRequest } from '../api/client';

export const useMaintenanceWindowsStore = defineStore('maintenanceWindows', {
  state: () => ({
    windows: [],
    loading: false,
  }),
  actions: {
    async fetchWindows(tenantId, environmentId) {
      this.loading = true;
      try {
        const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/maintenance-windows`);
        this.windows = res.data || [];
      } finally {
        this.loading = false;
      }
    },
    async createWindow(tenantId, environmentId, data) {
      const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/maintenance-windows`, {
        method: 'POST',
        body: JSON.stringify(data),
      });
      await this.fetchWindows(tenantId, environmentId);
      return res.data;
    },
    async deleteWindow(tenantId, environmentId, windowId) {
      await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/maintenance-windows/${windowId}`, {
        method: 'DELETE',
      });
      await this.fetchWindows(tenantId, environmentId);
    },
  },
});
