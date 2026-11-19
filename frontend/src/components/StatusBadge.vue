<template>
  <span :class="['status-badge', stateClass]">
    <span class="status-label">{{ labelText }}</span>
  </span>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
  state: {
    type: String,
    required: true,
  }
});

const { t } = useI18n();

const stateNormalized = computed(() => (props.state || 'pending').toLowerCase());

const stateClass = computed(() => {
  switch (stateNormalized.value) {
    case 'up': case 'operational': return 'up';
    case 'degraded': return 'degraded';
    case 'down': case 'major_outage': return 'down';
    case 'blocked': return 'blocked';
    case 'maintenance': return 'maintenance';
    default: return 'pending';
  }
});

const labelText = computed(() => {
  switch (stateNormalized.value) {
    case 'up': case 'operational': return t('status.up');
    case 'degraded': return t('status.degraded');
    case 'down': case 'major_outage': return t('status.down');
    case 'blocked': return t('status.blocked');
    case 'maintenance': return t('status.maintenance');
    case 'paused': return t('status.paused');
    default: return t('status.pending');
  }
});
</script>

