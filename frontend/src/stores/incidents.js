import { defineStore } from 'pinia';
import { apiRequest } from '../api/client';

export const useIncidentsStore = defineStore('incidents', {
  state: () => ({
    incidents: [],
    loading: false,
  }),
  actions: {
    async fetchIncidents(tenantId, environmentId) {
      this.loading = true;
      try {
        const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/incidents`);
        this.incidents = res.data || [];
      } finally {
        this.loading = false;
      }
    },
    async createIncident(tenantId, environmentId, data) {
      const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/incidents`, {
        method: 'POST',
        body: JSON.stringify(data),
      });
      await this.fetchIncidents(tenantId, environmentId);
      return res.data;
    },
    async resolveIncident(tenantId, environmentId, incidentId) {
      const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/incidents/${incidentId}/resolve`, {
        method: 'POST',
      });
      await this.fetchIncidents(tenantId, environmentId);
      return res.data;
    }
  }
});
