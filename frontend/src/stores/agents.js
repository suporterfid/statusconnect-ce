import { defineStore } from 'pinia';
import { apiRequest } from '../api/client';

const base = (t, e, m) => `/v1/tenants/${t}/environments/${e}/monitors/${m}`;

export const useAgentsStore = defineStore('agents', {
  state: () => ({
    metrics: [],
    metricsMeta: null,
    rules: [],
    // Plaintext token returned once by create/rotate; never fetched again.
    issuedToken: null,
  }),
  actions: {
    rememberToken(monitorId, meta) {
      if (meta?.agent_token) {
        this.issuedToken = { monitorId, token: meta.agent_token, ingestUrl: meta.ingest_url };
      }
    },
    async fetchMetrics(t, e, m, search = '') {
      const q = new URLSearchParams({ per_page: '200' });
      if (search) q.set('search', search);
      const res = await apiRequest(`${base(t, e, m)}/agent-metrics?${q}`);
      this.metrics = res.data || [];
      this.metricsMeta = res.meta || null;
    },
    async fetchRules(t, e, m) {
      const res = await apiRequest(`${base(t, e, m)}/agent-rules`);
      this.rules = res.data || [];
    },
    async saveRule(t, e, m, rule) {
      const { id, ...body } = rule;
      const res = await apiRequest(id ? `${base(t, e, m)}/agent-rules/${id}` : `${base(t, e, m)}/agent-rules`, {
        method: id ? 'PUT' : 'POST',
        body: JSON.stringify(body),
      });
      await this.fetchRules(t, e, m);
      return res.data;
    },
    async deleteRule(t, e, m, id) {
      await apiRequest(`${base(t, e, m)}/agent-rules/${id}`, { method: 'DELETE' });
      this.rules = this.rules.filter((r) => r.id !== id);
    },
    async rotateToken(t, e, m) {
      const res = await apiRequest(`${base(t, e, m)}/rotate-agent-token`, { method: 'POST' });
      this.rememberToken(m, res.meta);
      return res.meta;
    },
  },
});
