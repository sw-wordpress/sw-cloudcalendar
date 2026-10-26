<script setup lang="ts">
import { uid } from '@/utils/ids';

defineProps<{ title: string; description?: string }>();
const id = uid('swc-fs');
</script>

<template>
  <section class="swc-fs" :aria-labelledby="id">
    <div class="swc-fs__intro">
      <h3 :id="id" class="swc-fs__title">{{ title }}</h3>
      <p v-if="description" class="swc-fs__desc">{{ description }}</p>
    </div>
    <div class="swc-fs__fields"><slot /></div>
  </section>
</template>

<style scoped>
.swc-fs {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);
  gap: 12px 32px;
  padding: 22px 0;
  border-bottom: 1px solid var(--swc-border);
}
.swc-fs:last-child {
  border-bottom: 0;
}
.swc-fs__title {
  font-size: 15px;
  font-weight: 650;
}
.swc-fs__desc {
  margin-top: 4px;
  font-size: 13px;
  color: var(--swc-muted);
}
.swc-fs__fields {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}
@media (max-width: 820px) {
  .swc-fs {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
