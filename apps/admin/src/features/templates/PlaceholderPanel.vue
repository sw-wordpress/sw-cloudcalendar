<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { canBeSection, placeholderSnippet } from '@/components/editor/placeholderCompletion';
import type { PlaceholderInfo } from '@/types/api';

/** Variable reference with insert buttons (value, section, inverted section). */
const props = defineProps<{ placeholders: PlaceholderInfo[]; disabled?: boolean }>();
const emit = defineEmits<{ (e: 'insert', name: string, sigil: '' | '#' | '^'): void }>();
const { t } = useI18n();
const query = ref('');

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase();
  return q === ''
    ? props.placeholders
    : props.placeholders.filter(
        (p) => p.name.toLowerCase().includes(q) || p.description.toLowerCase().includes(q),
      );
});
</script>

<template>
  <div class="swc-pp">
    <q-input
      v-model="query"
      outlined
      dense
      clearable
      type="search"
      :label="t('templates.searchPlaceholders')"
    >
      <template #prepend><q-icon name="r_search" aria-hidden="true" /></template>
    </q-input>
    <p class="swc-hint">{{ t('templates.referenceHint') }}</p>
    <ul class="swc-pp__list">
      <li v-for="p in filtered" :key="p.name" class="swc-pp__item">
        <div class="swc-pp__top">
          <code class="swc-pp__name">{{ placeholderSnippet(p.name) }}</code>
          <span class="swc-pp__type">{{ t(`templates.types.${p.type}`) }}</span>
        </div>
        <p class="swc-pp__desc">{{ p.description }}</p>
        <div class="swc-pp__actions">
          <q-btn
            flat
            dense
            no-caps
            size="sm"
            icon="r_add"
            :disable="disabled"
            :label="t('templates.insertValue')"
            :aria-label="t('templates.insertNamed', { name: p.name })"
            @click="emit('insert', p.name, '')"
          />
          <template v-if="canBeSection(p)">
            <q-btn
              flat
              dense
              no-caps
              size="sm"
              :disable="disabled"
              :label="t('templates.insertSection')"
              :aria-label="t('templates.insertSectionNamed', { name: p.name })"
              @click="emit('insert', p.name, '#')"
            />
            <q-btn
              flat
              dense
              no-caps
              size="sm"
              :disable="disabled"
              :label="t('templates.insertInverted')"
              :aria-label="t('templates.insertInvertedNamed', { name: p.name })"
              @click="emit('insert', p.name, '^')"
            />
          </template>
        </div>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.swc-pp {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.swc-pp__list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-height: 520px;
  overflow-y: auto;
}
.swc-pp__item {
  padding: 8px 10px;
  border: 1px solid var(--swc-border);
  border-radius: var(--swc-radius-sm);
}
.swc-pp__top {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  align-items: baseline;
}
.swc-pp__name {
  font-size: 12.5px;
  overflow-wrap: anywhere;
}
.swc-pp__type {
  flex: none;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--swc-muted);
}
.swc-pp__desc {
  font-size: 13px;
  color: var(--swc-muted);
  margin-top: 2px;
}
.swc-pp__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
  margin-top: 4px;
}
</style>
