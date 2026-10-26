<script setup lang="ts">
defineProps<{ title: string; subtitle?: string; backTo?: string; backLabel?: string }>();
</script>

<template>
  <header class="swc-page-header">
    <div class="swc-page-header__text">
      <router-link v-if="backTo" :to="backTo" class="swc-page-header__back">
        <q-icon name="r_arrow_back" size="18px" aria-hidden="true" />
        {{ backLabel }}
      </router-link>
      <h1 class="swc-page-header__title">{{ title }}</h1>
      <p v-if="subtitle" class="swc-page-header__subtitle">{{ subtitle }}</p>
    </div>
    <div v-if="$slots.default" class="swc-page-header__actions"><slot /></div>
  </header>
</template>

<style scoped>
.swc-page-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 24px;
}
.swc-page-header__title {
  font-size: 26px;
  line-height: 1.2;
  font-weight: 750;
  letter-spacing: -0.02em;
}
.swc-page-header__subtitle {
  margin-top: 4px;
  color: var(--swc-muted);
  max-width: 62ch;
}
.swc-page-header__back {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-bottom: 6px;
  font-size: 13px;
  font-weight: 600;
}
.swc-page-header__actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
}
@media (max-width: 520px) {
  .swc-page-header__title {
    font-size: 22px;
  }
}
</style>
