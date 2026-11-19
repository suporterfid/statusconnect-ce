<template>
  <div class="toast-container">
    <div v-for="toast in uiStore.toasts" :key="toast.id" :class="['toast', `toast-${toast.type}`]">
      <span>{{ toast.message }}</span>
      <button class="toast-close" @click="uiStore.dismissToast(toast.id)">✕</button>
    </div>
  </div>
</template>

<script setup>
import { useUiStore } from '../stores/ui';

const uiStore = useUiStore();
</script>

<style scoped>
.toast-container {
  position: fixed;
  top: 1rem;
  right: 1rem;
  z-index: 1000;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  max-width: 360px;
}
.toast {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
  border-radius: var(--radius-sm);
  background: var(--card-bg, #1e293b);
  border: 1px solid var(--border-color, #334155);
  color: var(--text-primary, #f1f5f9);
  font-size: 0.9375rem;
  box-shadow: var(--shadow-2);
  border-left-width: 4px;
}
.toast-success {
  border-color: var(--status-up, #22c55e);
}
.toast-error {
  border-color: var(--status-down, #ef4444);
}
.toast-close {
  background: none;
  border: none;
  color: inherit;
  cursor: pointer;
  opacity: 0.6;
  font-size: 0.8rem;
}
.toast-close:hover {
  opacity: 1;
}
</style>
