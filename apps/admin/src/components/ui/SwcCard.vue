<script setup lang="ts">
withDefaults(
  defineProps<{
    title?: string;
    subtitle?: string;
    icon?: string;
    padding?: boolean;
    interactive?: boolean;
    tag?: string;
    accent?: boolean;
  }>(),
  {
    title: undefined,
    subtitle: undefined,
    icon: undefined,
    padding: true,
    interactive: false,
    tag: 'section',
    accent: false,
  },
);
</script>

<template>
  <component
    :is="tag"
    class="swc-card"
    :class="{ 'swc-card--interactive': interactive, 'swc-card--accent': accent }"
  >
    <header v-if="title || $slots.header || $slots.actions" class="swc-card__header">
      <slot name="header">
        <div class="swc-card__heading">
          <span v-if="icon" class="swc-card__icon" aria-hidden="true"
            ><q-icon :name="icon" size="20px"
          /></span>
          <div class="swc-card__titles">
            <h2 class="swc-card__title">{{ title }}</h2>
            <p v-if="subtitle" class="swc-card__subtitle">{{ subtitle }}</p>
          </div>
        </div>
      </slot>
      <div v-if="$slots.actions" class="swc-card__actions"><slot name="actions" /></div>
    </header>
    <div class="swc-card__body" :class="{ 'swc-card__body--flush': !padding }"><slot /></div>
    <footer v-if="$slots.footer" class="swc-card__footer"><slot name="footer" /></footer>
  </component>
</template>

<style scoped>
.swc-card {
  position: relative;
  background: var(--swc-surface);
  border: 1px solid var(--swc-border);
  border-radius: var(--swc-radius);
  box-shadow: var(--swc-shadow-1);
  min-width: 0;
  transition:
    box-shadow 0.2s ease,
    transform 0.2s ease,
    border-color 0.2s ease;
}
.swc-card--interactive:hover {
  box-shadow: var(--swc-shadow-2);
  transform: translateY(-1px);
}
.swc-card--accent::before {
  content: '';
  position: absolute;
  inset: 0 0 auto 0;
  height: 3px;
  border-radius: var(--swc-radius) var(--swc-radius) 0 0;
  background: var(--swc-gradient);
}
.swc-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 18px 22px 0;
}
.swc-card__heading {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.swc-card__icon {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  flex: none;
  border-radius: 10px;
  color: var(--swc-primary);
  background: var(--swc-gradient-soft);
}
.swc-card__title {
  font-size: 16px;
  font-weight: 650;
  line-height: 1.3;
}
.swc-card__subtitle {
  font-size: 13px;
  color: var(--swc-muted);
}
.swc-card__actions {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}
.swc-card__body {
  padding: 18px 22px 22px;
}
.swc-card__body--flush {
  padding: 12px 0 0;
}
.swc-card__footer {
  padding: 14px 22px;
  border-top: 1px solid var(--swc-border);
}
@media (max-width: 520px) {
  .swc-card__header {
    padding: 16px 16px 0;
  }
  .swc-card__body {
    padding: 14px 16px 18px;
  }
}
</style>
