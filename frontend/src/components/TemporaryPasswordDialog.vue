<template>
  <div v-if="password" class="dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="temp-password-title">
    <div class="card dialog">
      <h2 id="temp-password-title" class="dialog-title">{{ t('users.temp_password_title') }}</h2>
      <p class="text-muted">{{ t('users.temp_password_hint', { email }) }}</p>
      <div class="password-row">
        <code class="password">{{ password }}</code>
        <button type="button" class="btn btn-secondary btn-sm" @click="copy">{{ copied ? t('users.copied') : t('users.copy') }}</button>
      </div>
      <p class="alert alert-warning">{{ t('users.temp_password_once') }}</p>
      <button type="button" class="btn btn-primary btn-block" @click="$emit('close')">{{ t('users.done') }}</button>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({ password: { type: String, default: null }, email: { type: String, default: '' } });
defineEmits(['close']);
const { t } = useI18n();
const copied = ref(false);

watch(() => props.password, () => { copied.value = false; });

async function copy() {
  try {
    await navigator.clipboard.writeText(props.password);
    copied.value = true;
  } catch (e) {
    copied.value = false;
  }
}
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
.password-row {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  margin: 1rem 0;
}
.password {
  flex: 1;
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--border-color);
  border-radius: var(--radius-sm);
  font-size: 1rem;
  word-break: break-all;
  user-select: all;
}
.btn-sm {
  padding: 0.3rem 0.7rem;
  font-size: 0.8rem;
}
</style>
