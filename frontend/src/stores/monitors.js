import { defineStore } from 'pinia';
import { apiRequest } from '../api/client';
import { useAgentsStore } from './agents';

export const useMonitorsStore = defineStore('monitors', {
  state: () => ({
    monitors: [],
    currentMonitor: null,
    checkResults: [],
    loading: false,
  }),
  actions: {
    async fetchCheckResults(tenantId, environmentId, monitorId, limit = 50) {
      const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/monitors/${monitorId}/check-results?limit=${limit}`);
      this.checkResults = res.data || [];
      return this.checkResults;
    },
    async fetchMonitors(tenantId, environmentId) {
      this.loading = true;
      try {
        const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/monitors`);
        this.monitors = res.data || [];
      } finally {
        this.loading = false;
      }
    },
    async fetchMonitor(tenantId, environmentId, monitorId) {
      this.loading = true;
      try {
        const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/monitors/${monitorId}`);
        this.currentMonitor = res.data;
        return res.data;
      } finally {
        this.loading = false;
      }
    },
    async createMonitor(tenantId, environmentId, data) {
      const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/monitors`, {
        method: 'POST',
        body: JSON.stringify(data),
      });
      // Agent monitors return their ingestion token once, in meta.
      useAgentsStore().rememberToken(res.data?.public_id, res.meta);
      await this.fetchMonitors(tenantId, environmentId);
      return res.data;
    },
    async updateMonitor(tenantId, environmentId, monitorId, data) {
      const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/monitors/${monitorId}`, {
        method: 'PUT',
        body: JSON.stringify(data),
      });
      await this.fetchMonitors(tenantId, environmentId);
      return res.data;
    },
    async rotateHeartbeatToken(tenantId, environmentId, monitorId) {
      const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/monitors/${monitorId}/rotate-heartbeat-token`, {
        method: 'POST',
      });
      if (this.currentMonitor && this.currentMonitor.public_id === monitorId) {
        this.currentMonitor = res.data;
      }
      await this.fetchMonitors(tenantId, environmentId);
      return res.data;
    }
  }
});
