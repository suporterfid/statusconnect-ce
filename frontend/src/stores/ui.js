import { defineStore } from 'pinia';

let nextToastId = 1;

export const useUiStore = defineStore('ui', {
  state: () => ({
    toasts: [],
    confirmRequest: null,
  }),
  actions: {
    notify(message, type = 'info', durationMs = 4000) {
      const id = nextToastId++;
      this.toasts.push({ id, message, type });
      setTimeout(() => this.dismissToast(id), durationMs);
    },
    dismissToast(id) {
      this.toasts = this.toasts.filter(t => t.id !== id);
    },
    // options.typed (#248): the person must type this text before Confirm works.
    confirm(message, options = {}) {
      return new Promise((resolve) => {
        this.confirmRequest = {
          message,
          typed: options.typed || null,
          typedLabel: options.typedLabel || '',
          resolve: (result) => {
            this.confirmRequest = null;
            resolve(result);
          },
        };
      });
    },
  },
});
