<template>
  <div v-if="open" class="dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="telegram-link-title">
    <div class="card dialog">
      <h2 id="telegram-link-title" class="dialog-title">{{ t('notifications.telegram_title') }}</h2>

      <p v-if="loading" class="text-muted">{{ t('notifications.telegram_creating') }}</p>

      <template v-else-if="link && status === 'pending'">
        <p class="text-muted">{{ t('notifications.telegram_intro') }}</p>
        <div class="link-actions">
          <a class="btn btn-primary" :href="link.start_url" target="_blank" rel="noopener" data-telegram-start>{{ t('notifications.telegram_open_private') }}</a>
          <a class="btn btn-secondary" :href="link.startgroup_url" target="_blank" rel="noopener" data-telegram-group>{{ t('notifications.telegram_open_group') }}</a>
        </div>
        <div class="qr" aria-hidden="true" v-html="qrSvg"></div>
        <p class="text-muted qr-hint">{{ t('notifications.telegram_qr_hint') }}</p>
        <p class="waiting" role="status">{{ t('notifications.telegram_waiting', { time: remaining }) }}</p>
      </template>

      <template v-else-if="status === 'expired'">
        <p class="alert alert-warning">{{ t('notifications.telegram_expired') }}</p>
        <button type="button" class="btn btn-primary" @click="start">{{ t('notifications.telegram_new_link') }}</button>
      </template>

      <p v-if="error" class="alert alert-danger">{{ error }}</p>

      <button type="button" class="btn btn-secondary btn-block close" @click="close">{{ t('notifications.close') }}</button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import qrcode from 'qrcode-generator';
import { useNotificationChannelsStore } from '../stores/notificationChannels';

const props = defineProps({
  open: { type: Boolean, default: false },
  tenantId: { type: String, required: true },
  environmentId: { type: String, required: true },
});
const emit = defineEmits(['close', 'linked']);

const POLL_MS = 3000;

const { t } = useI18n();
const store = useNotificationChannelsStore();

const link = ref(null);
const status = ref(null);
const loading = ref(false);
const error = ref('');
const now = ref(Date.now());
let pollTimer = null;
let clockTimer = null;

const qrSvg = computed(() => {
  if (!link.value) return '';
  const qr = qrcode(0, 'M');
  qr.addData(link.value.start_url);
  qr.make();
  return qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
});

const remaining = computed(() => {
  if (!link.value) return '';
  const seconds = Math.max(0, Math.floor((Date.parse(link.value.expires_at) - now.value) / 1000));
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
});

function stopTimers() {
  clearTimeout(pollTimer);
  clearInterval(clockTimer);
  pollTimer = null;
  clockTimer = null;
}

async function poll() {
  if (!props.open || !link.value) return;
  try {
    const data = await store.getTelegramLink(props.tenantId, props.environmentId, link.value.link_id);
    status.value = data.status;
    if (data.status === 'linked') {
      stopTimers();
      emit('linked', data.channel);
      return;
    }
    if (data.status === 'expired') {
      stopTimers();
      return;
    }
  } catch (e) {
    // A failed poll is retried on the next tick.
  }
  pollTimer = setTimeout(poll, POLL_MS);
}

async function start() {
  stopTimers();
  error.value = '';
  link.value = null;
  status.value = null;
  loading.value = true;
  try {
    link.value = await store.createTelegramLink(props.tenantId, props.environmentId);
    status.value = 'pending';
    now.value = Date.now();
    clockTimer = setInterval(() => { now.value = Date.now(); }, 1000);
    pollTimer = setTimeout(poll, POLL_MS);
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

function close() {
  stopTimers();
  emit('close');
}

watch(() => props.open, (isOpen) => {
  if (isOpen) {
    start();
  } else {
    stopTimers();
  }
}, { immediate: true });

onBeforeUnmount(stopTimers);
</script>

<style scoped>
.dialog-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  z-index: 50;
}
.dialog {
  width: 100%;
  max-width: 460px;
}
.dialog-title {
  font-size: 1.1rem;
  margin: 0 0 0.5rem;
}
.link-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin: 1rem 0;
}
.qr {
  width: 180px;
  margin: 0 auto;
  background: #fff;
  padding: 0.25rem;
  border-radius: var(--radius-sm);
}
.qr :deep(svg) {
  display: block;
  width: 100%;
  height: auto;
}
.qr-hint {
  text-align: center;
  font-size: 0.8rem;
  margin-top: 0.4rem;
}
.waiting {
  text-align: center;
  font-size: 0.85rem;
}
.close {
  margin-top: 1rem;
}
</style>
