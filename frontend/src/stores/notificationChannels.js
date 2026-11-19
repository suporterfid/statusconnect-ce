import { defineStore } from 'pinia';
import { apiRequest } from '../api/client';

export const useNotificationChannelsStore = defineStore('notificationChannels', {
  state: () => ({
    channels: [],
    loading: false,
  }),
  actions: {
    async fetchChannels(tenantId, environmentId) {
      this.loading = true;
      try {
        const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/notification-channels`);
        this.channels = res.data || [];
      } finally {
        this.loading = false;
      }
    },
    async createChannel(tenantId, environmentId, data) {
      const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/notification-channels`, {
        method: 'POST',
        body: JSON.stringify(data),
      });
      await this.fetchChannels(tenantId, environmentId);
      return res.data;
    },
    async deleteChannel(tenantId, environmentId, channelId) {
      await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/notification-channels/${channelId}`, {
        method: 'DELETE',
      });
      await this.fetchChannels(tenantId, environmentId);
    },
    // Telegram linking (#190, #191): a one-time t.me link, then its status.
    async createTelegramLink(tenantId, environmentId) {
      const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/notification-channels/telegram-links`, {
        method: 'POST',
      });
      return res.data;
    },
    async getTelegramLink(tenantId, environmentId, linkId) {
      const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/notification-channels/telegram-links/${linkId}`);
      return res.data;
    },
    // WhatsApp linking (#215): a one-time wa.me link, then its status.
    async createWhatsAppLink(tenantId, environmentId) {
      const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/notification-channels/whatsapp-links`, {
        method: 'POST',
      });
      return res.data;
    },
    async getWhatsAppLink(tenantId, environmentId, linkId) {
      const res = await apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/notification-channels/whatsapp-links/${linkId}`);
      return res.data;
    },
    async testChannel(tenantId, environmentId, channelId) {
      return apiRequest(`/v1/tenants/${tenantId}/environments/${environmentId}/notification-channels/${channelId}/test`, {
        method: 'POST',
      });
    },
  },
});
