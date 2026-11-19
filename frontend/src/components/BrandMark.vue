<template>
  <!-- StatusConnect mark: an S drawn as a metro line between two stations (assets/brand/mark.svg). -->
  <svg viewBox="0 0 32 32" aria-hidden="true" focusable="false" class="brand-mark">
    <path d="M25 6H15A5 5 0 0 0 15 16H17A5 5 0 0 1 17 26H7" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" />
    <circle cx="25" cy="6" r="3.6" :style="{ fill: hole }" stroke="currentColor" stroke-width="2.4" />
    <circle cx="7" cy="26" r="4.2" style="fill: var(--status-up)" :style="{ stroke: hole }" stroke-width="1.6" />
  </svg>
</template>

<script setup>
defineProps({
  // Colour behind the mark, used to cut the stations out of the line.
  hole: { type: String, default: 'var(--sign-bg)' },
});
</script>
