<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { Block } from './visualBuilder';
import type { TemplateKind } from '@/types/api';

/** Type specific settings of one visual block (mutates the given block object). */
const props = defineProps<{ block: Block; kind: TemplateKind }>();
const emit = defineEmits<{ (e: 'change'): void }>();
const { t } = useI18n();

function set<K extends string>(key: K, value: unknown): void {
  (props.block as unknown as Record<string, unknown>)[key] = value;
  emit('change');
}
</script>

<template>
  <div class="swc-bf">
    <template v-if="block.type === 'title'">
      <q-select
        :model-value="block.level"
        outlined
        dense
        emit-value
        map-options
        :options="[2, 3, 4].map((l) => ({ value: l, label: `H${l}` }))"
        :label="t('builder.fields.level')"
        @update:model-value="(v: number) => set('level', v)"
      />
      <q-toggle
        v-if="kind !== 'container'"
        :model-value="block.linkDetail"
        :label="t('builder.fields.linkDetail')"
        @update:model-value="(v: boolean) => set('linkDetail', v)"
      />
    </template>
    <q-select
      v-else-if="block.type === 'datetime' && kind !== 'container'"
      :model-value="block.format"
      outlined
      dense
      emit-value
      map-options
      :options="
        (['range', 'start', 'date'] as const).map((v) => ({
          value: v,
          label: t(`builder.formats.${v}`),
        }))
      "
      :label="t('builder.fields.format')"
      @update:model-value="(v: string) => set('format', v)"
    />
    <q-select
      v-else-if="block.type === 'description'"
      :model-value="block.variant"
      outlined
      dense
      emit-value
      map-options
      :options="
        (['excerpt', 'text', 'html'] as const).map((v) => ({
          value: v,
          label: t(`builder.variants.${v}`),
        }))
      "
      :label="t('builder.fields.variant')"
      @update:model-value="(v: string) => set('variant', v)"
    />
    <q-input
      v-else-if="block.type === 'location'"
      :model-value="block.label"
      outlined
      dense
      maxlength="80"
      :label="t('builder.fields.label')"
      :hint="t('builder.fields.labelHint')"
      @update:model-value="(v: string | number | null) => set('label', String(v ?? ''))"
    />
    <template v-else-if="block.type === 'link'">
      <q-input
        :model-value="block.label"
        outlined
        dense
        maxlength="80"
        :label="t('builder.fields.linkLabel')"
        :hint="t('builder.fields.linkLabelHint')"
        @update:model-value="(v: string | number | null) => set('label', String(v ?? ''))"
      />
      <q-toggle
        :model-value="block.newTab"
        :label="t('builder.fields.newTab')"
        @update:model-value="(v: boolean) => set('newTab', v)"
      />
    </template>
    <q-input
      v-else-if="block.type === 'attachments'"
      :model-value="block.heading"
      outlined
      dense
      maxlength="80"
      :label="t('builder.fields.heading')"
      @update:model-value="(v: string | number | null) => set('heading', String(v ?? ''))"
    />
    <q-select
      v-else-if="block.type === 'container'"
      :model-value="block.tag"
      outlined
      dense
      emit-value
      map-options
      :options="
        (['div', 'section', 'header', 'footer', 'aside'] as const).map((v) => ({
          value: v,
          label: `<${v}>`,
        }))
      "
      :label="t('builder.fields.tag')"
      @update:model-value="(v: string) => set('tag', v)"
    />
    <template v-else-if="block.type === 'custom_html'">
      <q-input
        :model-value="block.html"
        outlined
        type="textarea"
        autogrow
        class="swc-bf__html"
        :label="t('builder.fields.html')"
        :hint="t('builder.fields.htmlHint')"
        input-class="swc-mono"
        @update:model-value="(v: string | number | null) => set('html', String(v ?? ''))"
      />
    </template>
    <template v-else-if="block.type === 'event_list'">
      <q-toggle
        :model-value="block.navigation"
        :label="t('builder.fields.navigation')"
        @update:model-value="(v: boolean) => set('navigation', v)"
      />
      <q-input
        :model-value="block.emptyText"
        outlined
        dense
        maxlength="120"
        :label="t('builder.fields.emptyText')"
        @update:model-value="(v: string | number | null) => set('emptyText', String(v ?? ''))"
      />
    </template>
    <q-input
      :model-value="block.cssClass"
      outlined
      dense
      maxlength="120"
      :label="t('builder.fields.cssClass')"
      :hint="t('builder.fields.cssClassHint')"
      @update:model-value="(v: string | number | null) => set('cssClass', String(v ?? ''))"
    />
  </div>
</template>

<style scoped>
.swc-bf {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 10px 0 4px;
}
</style>
