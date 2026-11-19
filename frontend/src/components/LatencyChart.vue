<template>
  <div class="latency-chart-container">
    <svg class="latency-svg" viewBox="0 0 500 120" preserveAspectRatio="none">
      <defs>
        <linearGradient id="latencyGradient" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" style="stop-color: var(--line-ink); stop-opacity: 0.14" />
          <stop offset="100%" style="stop-color: var(--line-ink); stop-opacity: 0" />
        </linearGradient>
      </defs>
      <polygon :points="areaPoints" fill="url(#latencyGradient)" />
      <polyline :points="polylinePoints" fill="none" style="stroke: var(--line-ink)" stroke-width="2.5" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
    </svg>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  data: {
    type: Array,
    default: () => [12, 15, 14, 28, 45, 18, 14, 12, 13, 16, 22, 18, 14, 15, 12]
  }
});

const polylinePoints = computed(() => {
  if (!props.data || props.data.length === 0) return '0,100 500,100';
  const max = Math.max(...props.data, 50);
  const min = Math.min(...props.data, 0);
  const width = 500;
  const height = 100;
  const step = width / (props.data.length - 1 || 1);

  return props.data
    .map((val, i) => {
      const x = i * step;
      const y = height - ((val - min) / (max - min || 1)) * (height - 20) - 10;
      return `${x},${y}`;
    })
    .join(' ');
});

const areaPoints = computed(() => {
  return `0,120 ${polylinePoints.value} 500,120`;
});
</script>

<style scoped>
.latency-chart-container {
  width: 100%;
  height: 120px;
  background: var(--bg-primary);
  border: 1px solid var(--border-color);
  border-radius: var(--radius-sm);
  padding: 0.5rem 0;
  overflow: hidden;
}
.latency-svg {
  width: 100%;
  height: 100%;
}
</style>
