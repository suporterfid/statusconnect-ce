import { defineStore } from 'pinia';
import { apiRequest } from '../api/client';

const base = (tenantId, environmentId) => `/v1/tenants/${tenantId}/environments/${environmentId}/status-page`;

export const useStatusPageStore = defineStore('statusPage', {
  state: () => ({
    page: null,
    components: [],
    updates: [],
    loading: false,
  }),
  actions: {
    async fetchAll(tenantId, environmentId) {
      this.loading = true;
      try {
        const [page, components, updates] = await Promise.all([
          apiRequest(base(tenantId, environmentId)),
          apiRequest(`${base(tenantId, environmentId)}/components`),
          apiRequest(`${base(tenantId, environmentId)}/updates`),
        ]);
        this.page = page?.data || null;
        this.components = components?.data || [];
        this.updates = updates?.data || [];
      } finally {
        this.loading = false;
      }
    },
    async savePage(tenantId, environmentId, data) {
      const res = await apiRequest(base(tenantId, environmentId), {
        method: 'PUT',
        body: JSON.stringify(data),
      });
      this.page = res.data;
      return res.data;
    },
    async uploadLogo(tenantId, environmentId, file) {
      const body = new FormData();
      body.append('logo', file);
      const res = await apiRequest(`${base(tenantId, environmentId)}/logo`, { method: 'POST', body });
      this.page = res.data;
      return res.data;
    },
    async removeLogo(tenantId, environmentId) {
      const res = await apiRequest(`${base(tenantId, environmentId)}/logo`, { method: 'DELETE' });
      this.page = res.data;
      return res.data;
    },
    async createComponent(tenantId, environmentId, data) {
      const res = await apiRequest(`${base(tenantId, environmentId)}/components`, {
        method: 'POST',
        body: JSON.stringify(data),
      });
      this.components.push(res.data);
      return res.data;
    },
    async updateComponent(tenantId, environmentId, id, data) {
      const res = await apiRequest(`${base(tenantId, environmentId)}/components/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
      });
      this.components = this.components.map((c) => (c.id === id ? res.data : c));
      return res.data;
    },
    async deleteComponent(tenantId, environmentId, id) {
      await apiRequest(`${base(tenantId, environmentId)}/components/${id}`, { method: 'DELETE' });
      this.components = this.components.filter((c) => c.id !== id);
    },
    async publishUpdate(tenantId, environmentId, data) {
      const res = await apiRequest(`${base(tenantId, environmentId)}/updates`, {
        method: 'POST',
        body: JSON.stringify(data),
      });
      this.updates.unshift(res.data);
      return res.data;
    },
    async editUpdate(tenantId, environmentId, id, data) {
      const res = await apiRequest(`${base(tenantId, environmentId)}/updates/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
      });
      this.updates = this.updates.map((u) => (u.id === id ? res.data : u));
      return res.data;
    },
    async deleteUpdate(tenantId, environmentId, id) {
      await apiRequest(`${base(tenantId, environmentId)}/updates/${id}`, { method: 'DELETE' });
      this.updates = this.updates.filter((u) => u.id !== id);
    },
  },
});
