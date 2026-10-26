<script setup lang="ts">
withDefaults(
  defineProps<{
    label: string;
    value?: string;
    hint?: string;
    icon?: string;
    loading?: boolean;
    tone?: 'default' | 'success' | 'warning' | 'danger';
  }>(),
  { value: undefined, hint: undefined, icon: undefined, loading: false, tone: 'default' },
);
</script>

<template>
  <div class="swc-stat" :class="`swc-stat--${tone}`">
    <div class="swc-stat__top">
      <span class="swc-stat__label">{{ label }}</span>
      <q-icon v-if="icon" :name="icon" size="20px" class="swc-stat__icon" aria-hidden="true" />
    </div>
    <template v-if="loading">
      <q-skeleton type="text" width="60%" height="32px" />
      <q-skeleton type="text" width="40%" />
    </template>
    <template v-else>
      <div class="swc-stat__value swc-num">
        <slot>{{ value ?? '–' }}</slot>
      </div>
      <div v-if="hint || $slots.hint" class="swc-stat__hint">
        <slot name="hint">{{ hint }}</slot>
      </div>
    </template>
  </div>
</template>

<style scoped>
.swc-stat {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 18px 20px;
  background: var(--swc-surface);
  border: 1px solid var(--swc-border);
  border-radius: var(--swc-radius);
  box-shadow: var(--swc-shadow-1);
  min-width: 0;
}
.swc-stat__top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}
.swc-stat__label {
  font-size: 13px;
  font-weight: 600;
  color: var(--swc-muted);
}
.swc-stat__icon {
  color: var(--swc-primary);
}
.swc-stat__value {
  font-size: 26px;
  font-weight: 700;
  line-height: 1.2;
  letter-spacing: -0.01em;
  overflow-wrap: anywhere;
}
.swc-stat__hint {
  font-size: 13px;
  color: var(--swc-muted);
}
.swc-stat--success .swc-stat__icon {
  color: var(--swc-success);
}
.swc-stat--warning .swc-stat__icon {
  color: var(--swc-warning);
}
.swc-stat--danger .swc-stat__icon {
  color: var(--swc-danger);
}
</style>
