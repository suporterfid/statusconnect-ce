<template>
  <div class="notifications-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">{{ t('app.notifications') }}</h1>
      </div>
      <div class="header-actions">
        <button v-if="canWrite && telegramEnabled" class="btn btn-secondary" @click="telegramOpen = true" :disabled="!hasContext" data-connect-telegram>
          {{ t('notifications.connect_telegram') }}
        </button>
        <button v-if="canWrite && whatsappEnabled" class="btn btn-secondary" @click="whatsappOpen = true" :disabled="!hasContext" data-connect-whatsapp>
          {{ t('notifications.connect_whatsapp') }}
        </button>
        <button v-if="canWrite" class="btn btn-primary" @click="showForm = !showForm" :disabled="!hasContext">
          {{ t('notifications.new_channel') }}
        </button>
      </div>
    </div>

    <div v-if="!hasContext" class="card">
      <p class="text-muted">{{ t('notifications.no_context') }}</p>
    </div>

    <div v-if="showForm" class="card form-card">
      <form @submit.prevent="handleCreateChannel">
        <label class="form-label">{{ t('notifications.name') }}</label>
        <input v-model="newChannel.name" class="form-input" placeholder="Ops Team Email" required />

        <label class="form-label">{{ t('notifications.type') }}</label>
        <select v-model="newChannel.type" class="form-input">
          <option value="email">Email</option>
          <option value="webhook">Webhook</option>
          <option value="taskconnect">TaskConnect</option>
        </select>

        <template v-if="newChannel.type === 'email'">
          <label class="form-label">{{ t('notifications.email_address') }}</label>
          <input v-model="newChannel.config.email" type="email" class="form-input" placeholder="ops@acme.test" required />
        </template>
        <template v-else>
          <label class="form-label">{{ t('notifications.webhook_url') }}</label>
          <input v-model="newChannel.config.url" type="url" class="form-input" placeholder="https://hooks.slack.com/services/..." required />
        </template>
        <template v-if="newChannel.type === 'taskconnect'">
          <label class="form-label">{{ t('notifications.api_key') }}</label>
          <input v-model="newChannel.config.api_key" class="form-input" placeholder="tc_..." />
        </template>

        <button type="submit" class="btn btn-primary" :disabled="creating">
          {{ creating ? '...' : t('notifications.create_channel') }}
        </button>
      </form>
    </div>

    <!-- Notification Channels Table -->
    <div class="card table-card">
      <div class="card-header">
        <h3>{{ t('notifications.channels') }}</h3>
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>{{ t('notifications.channel_name') }}</th>
              <th>{{ t('notifications.type') }}</th>
              <th>{{ t('notifications.status') }}</th>
              <th>{{ t('notifications.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="ch in channelsStore.channels" :key="ch.public_id">
              <td>
                <strong>{{ ch.name }}</strong>
                <div v-if="ch.type === 'telegram' && ch.config_json?.chat_title" class="text-muted chat-title">
                  {{ t('notifications.telegram_chat', { title: ch.config_json.chat_title }) }}
                </div>
                <div v-if="ch.type === 'whatsapp' && ch.config_json?.phone" class="text-muted chat-title" data-whatsapp-number>
                  {{ t('notifications.whatsapp_number', { number: '+' + ch.config_json.phone }) }}
                </div>
              </td>
              <td><span class="kind-tag">{{ ch.type }}</span></td>
              <td><StatusBadge :state="ch.enabled ? 'up' : 'down'" /></td>
              <td>
                <button v-if="canWrite" class="btn btn-secondary btn-sm" @click="handleTestSend(ch.public_id)">
                  {{ t('notifications.send_test') }}
                </button>
                <button v-if="canWrite" class="btn btn-danger btn-sm" @click="handleDelete(ch.public_id)">
                  {{ t('notifications.delete') }}
                </button>
              </td>
            </tr>
            <tr v-if="!channelsStore.loading && channelsStore.channels.length === 0">
              <td colspan="4" class="text-center text-muted">{{ t('notifications.empty') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <TelegramLinkDialog
      v-if="hasContext"
      :open="telegramOpen"
      :tenant-id="tenantId"
      :environment-id="envId"
      @close="telegramOpen = false"
      @linked="handleTelegramLinked"
    />
    <component
      :is="WhatsAppLinkDialog"
      v-if="WhatsAppLinkDialog && hasContext && whatsappEnabled"
      :open="whatsappOpen"
      :tenant-id="tenantId"
      :environment-id="envId"
      @close="whatsappOpen = false"
      @linked="handleWhatsAppLinked"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useTenantStore } from '../stores/tenant';
import { useI18n } from 'vue-i18n';
import StatusBadge from '../components/StatusBadge.vue';
import TelegramLinkDialog from '../components/TelegramLinkDialog.vue';
import { enterpriseComponent } from '../enterprise';
import { apiRequest } from '../api/client';
import { useAuthStore } from '../stores/auth';
import { useNotificationChannelsStore } from '../stores/notificationChannels';
import { useUiStore } from '../stores/ui';

// Viewers are read-only (#160).
const canWrite = computed(() => useTenantStore().canWrite);

const { t } = useI18n();
const channelsStore = useNotificationChannelsStore();
const uiStore = useUiStore();

const tenantId = localStorage.getItem('current_tenant_id');
const envId = localStorage.getItem('current_environment_id');
const hasContext = computed(() => !!tenantId && !!envId);

// Offered only when the installation has a Telegram bot (#190, #191).
const telegramEnabled = computed(() => !!useAuthStore().features?.telegram);
const telegramOpen = ref(false);

// Offered when the installation has a WhatsApp sender (#215) and the plan includes messages.
const whatsappInPlan = ref(false);
// The linking dialog is part of the enterprise edition (#250); without it there is no WhatsApp.
const WhatsAppLinkDialog = enterpriseComponent('WhatsAppLinkDialog');
const whatsappEnabled = computed(() => !!WhatsAppLinkDialog && !!useAuthStore().features?.whatsapp && whatsappInPlan.value);
const whatsappOpen = ref(false);

async function loadWhatsAppPlan() {
  if (!useAuthStore().features?.whatsapp) return;
  try {
    const res = await apiRequest(`/v1/tenants/${tenantId}/plan`);
    const plan = res?.data?.plan ?? res?.plan;
    whatsappInPlan.value = plan?.whatsapp_messages !== 0 && plan?.whatsapp_messages !== undefined;
  } catch {
    // Without the plan the button stays hidden; the API enforces the plan anyway.
  }
}

const showForm = ref(false);
const creating = ref(false);
const newChannel = ref({ name: '', type: 'email', config: { email: '', url: '', api_key: '' } });

onMounted(() => {
  if (hasContext.value) {
    channelsStore.fetchChannels(tenantId, envId);
    loadWhatsAppPlan();
  }
});

async function handleCreateChannel() {
  creating.value = true;
  try {
    const config = newChannel.value.type === 'email'
      ? { email: newChannel.value.config.email }
      : newChannel.value.type === 'taskconnect'
        ? { url: newChannel.value.config.url, api_key: newChannel.value.config.api_key }
        : { url: newChannel.value.config.url };

    await channelsStore.createChannel(tenantId, envId, {
      name: newChannel.value.name,
      type: newChannel.value.type,
      config_json: config,
      enabled: true,
    });
    newChannel.value = { name: '', type: 'email', config: { email: '', url: '', api_key: '' } };
    showForm.value = false;
  } catch (e) {
    uiStore.notify(t('notifications.create_failed', { error: e.message }), 'error');
  } finally {
    creating.value = false;
  }
}

async function handleTestSend(channelId) {
  try {
    await channelsStore.testChannel(tenantId, envId, channelId);
    uiStore.notify(t('notifications.test_sent'), 'success');
  } catch (e) {
    uiStore.notify(t('notifications.test_failed', { error: e.message }), 'error');
  }
}

async function handleDelete(channelId) {
  if (await uiStore.confirm(t('notifications.delete_confirm'))) {
    try {
      await channelsStore.deleteChannel(tenantId, envId, channelId);
    } catch (e) {
      uiStore.notify(t('notifications.delete_failed', { error: e.message }), 'error');
    }
  }
}

async function handleTelegramLinked(channel) {
  telegramOpen.value = false;
  uiStore.notify(t('notifications.telegram_linked', { title: channel?.config_json?.chat_title ?? channel?.name ?? '' }), 'success');
  await channelsStore.fetchChannels(tenantId, envId);
}

async function handleWhatsAppLinked(channel) {
  whatsappOpen.value = false;
  const phone = channel?.config_json?.phone;
  uiStore.notify(t('notifications.whatsapp_linked', { title: phone ? '+' + phone : channel?.name ?? '' }), 'success');
  await channelsStore.fetchChannels(tenantId, envId);
}
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
}
.header-actions {
  display: flex;
  gap: 0.5rem;
}
.chat-title {
  font-size: 0.75rem;
}
.table-card {
  padding: 0;
}
.card-header {
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid var(--border-color);
}
.kind-tag {
  background: var(--bg-hover);
  padding: 0.15rem 0.5rem;
  border-radius: var(--radius-sm);
  font-size: 0.75rem;
  font-family: monospace;
}
.btn-sm {
  padding: 0.25rem 0.6rem;
  font-size: 0.75rem;
  margin-right: 0.4rem;
}
.form-card {
  margin-bottom: 1rem;
}
.form-label {
  display: block;
  margin-top: 0.75rem;
  margin-bottom: 0.25rem;
  font-size: 0.85rem;
}
.form-input {
  width: 100%;
  padding: 0.5rem;
}
</style>
