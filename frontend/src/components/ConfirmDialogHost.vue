<template>
  <div v-if="uiStore.confirmRequest" class="confirm-overlay" @click.self="handleCancel">
    <div class="confirm-dialog card" role="alertdialog" aria-modal="true" aria-labelledby="confirm-message">
      <p id="confirm-message">{{ uiStore.confirmRequest.message }}</p>
      <!-- Typed confirmation (#248): Confirm stays off until the text matches. -->
      <div v-if="uiStore.confirmRequest.typed" class="form-group typed">
        <label class="form-label" for="confirm-typed">{{ uiStore.confirmRequest.typedLabel }}</label>
        <input id="confirm-typed" v-model="typed" class="form-control" autocomplete="off" autocapitalize="off" spellcheck="false" :placeholder="uiStore.confirmRequest.typed" @keydown.enter.prevent="matches && handleConfirm()" />
      </div>
      <div class="confirm-actions">
        <button class="btn btn-secondary" @click="handleCancel">Cancel</button>
        <button class="btn btn-danger" :disabled="!matches" @click="handleConfirm">Confirm</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useUiStore } from '../stores/ui';

const uiStore = useUiStore();
const typed = ref('');

watch(() => uiStore.confirmRequest, () => { typed.value = ''; });

const matches = computed(() => {
  const expected = uiStore.confirmRequest?.typed;
  return !expected || typed.value.trim() === expected;
});

function handleConfirm() {
  if (!matches.value) return;
  uiStore.confirmRequest?.resolve(true);
}

function handleCancel() {
  uiStore.confirmRequest?.resolve(false);
}
</script>

<style scoped>
.confirm-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  z-index: 1100;
}
.confirm-dialog {
  max-width: 420px;
  width: 100%;
  padding: 1.5rem;
}
.confirm-dialog p {
  margin-bottom: 1.25rem;
  font-size: 0.9rem;
  overflow-wrap: anywhere;
}
.typed { margin-bottom: 1.25rem; }
.typed .form-label { font-size: 0.875rem; overflow-wrap: anywhere; }
.confirm-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
}
</style>
