<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BlockFields from './BlockFields.vue';
import { BLOCKS_BY_KIND, createBlock, moveBlock } from './visualBuilder';
import type { Block, BlockType } from './visualBuilder';
import type { TemplateKind } from '@/types/api';

/**
 * Ordered list of visual blocks. Reordering uses up/down buttons (keyboard operable; focus
 * follows the moved block). Containers render a nested list (one level).
 */
const model = defineModel<Block[]>({ required: true });
const props = withDefaults(defineProps<{ kind: TemplateKind; nested?: boolean }>(), {
  nested: false,
});
const { t } = useI18n();
const expanded = ref<Record<string, boolean>>({});
const root = ref<HTMLElement | null>(null);

const addable = computed<BlockType[]>(() =>
  BLOCKS_BY_KIND[props.kind].filter((type) => !(props.nested && type === 'container')),
);

function update(list: Block[]): void {
  model.value = list;
}
function touch(): void {
  model.value = [...model.value];
}
function add(type: BlockType): void {
  const block = createBlock(type, props.kind);
  update([...model.value, block]);
  expanded.value = { ...expanded.value, [block.id]: true };
  void nextTick(() =>
    root.value?.querySelector<HTMLElement>(`[data-block-head="${block.id}"]`)?.focus(),
  );
}
async function move(index: number, delta: -1 | 1): Promise<void> {
  const block = model.value[index];
  if (!block) return;
  update(moveBlock(model.value, index, delta));
  await nextTick();
  const selector = `[data-move="${block.id}:${delta}"]`;
  const button = root.value?.querySelector<HTMLElement>(selector);
  if (button && !button.hasAttribute('disabled')) button.focus();
  else root.value?.querySelector<HTMLElement>(`[data-block-head="${block.id}"]`)?.focus();
}
function remove(index: number): void {
  const copy = [...model.value];
  copy.splice(index, 1);
  update(copy);
}
function toggleVisible(block: Block): void {
  block.visible = !block.visible;
  touch();
}
function label(block: Block): string {
  return t(`builder.blocks.${block.type}`);
}
</script>

<template>
  <div ref="root" class="swc-bl" :class="{ 'swc-bl--nested': nested }">
    <ol
      class="swc-bl__list"
      :aria-label="nested ? t('builder.nestedList') : t('builder.blockList')"
    >
      <li
        v-for="(block, index) in model"
        :key="block.id"
        class="swc-bl__item"
        :class="{ 'is-hidden': !block.visible }"
      >
        <div class="swc-bl__head">
          <button
            type="button"
            class="swc-bl__toggle"
            :data-block-head="block.id"
            :aria-expanded="!!expanded[block.id]"
            @click="expanded = { ...expanded, [block.id]: !expanded[block.id] }"
          >
            <q-icon
              :name="expanded[block.id] ? 'r_expand_more' : 'r_chevron_right'"
              size="20px"
              aria-hidden="true"
            />
            <span class="swc-bl__pos swc-num" aria-hidden="true">{{ index + 1 }}</span>
            <span class="swc-bl__name">{{ label(block) }}</span>
            <span v-if="block.cssClass" class="swc-bl__cls swc-mono"
              >.{{ block.cssClass.split(' ')[0] }}</span
            >
            <span v-if="!block.visible" class="swc-bl__hiddenlbl">{{ t('builder.hidden') }}</span>
          </button>
          <div class="swc-bl__tools">
            <q-btn
              flat
              round
              dense
              size="sm"
              :icon="block.visible ? 'r_visibility' : 'r_visibility_off'"
              :aria-pressed="block.visible"
              :aria-label="t('builder.toggleVisible', { name: label(block), n: index + 1 })"
              @click="toggleVisible(block)"
            />
            <q-btn
              flat
              round
              dense
              size="sm"
              icon="r_arrow_upward"
              :data-move="`${block.id}:-1`"
              :disable="index === 0"
              :aria-label="t('builder.moveUp', { name: label(block), n: index + 1 })"
              @click="move(index, -1)"
            />
            <q-btn
              flat
              round
              dense
              size="sm"
              icon="r_arrow_downward"
              :data-move="`${block.id}:1`"
              :disable="index === model.length - 1"
              :aria-label="t('builder.moveDown', { name: label(block), n: index + 1 })"
              @click="move(index, 1)"
            />
            <q-btn
              flat
              round
              dense
              size="sm"
              color="negative"
              icon="r_delete"
              :aria-label="t('builder.remove', { name: label(block), n: index + 1 })"
              @click="remove(index)"
            />
          </div>
        </div>
        <div v-if="expanded[block.id]" class="swc-bl__body">
          <BlockFields :block="block" :kind="kind" @change="touch" />
          <div v-if="block.type === 'container'" class="swc-bl__children">
            <p class="swc-eyebrow">{{ t('builder.children') }}</p>
            <BlockList
              :model-value="block.children"
              :kind="kind"
              nested
              @update:model-value="
                (list: Block[]) => {
                  block.children = list;
                  touch();
                }
              "
            />
          </div>
        </div>
      </li>
    </ol>
    <p v-if="model.length === 0" class="swc-hint swc-bl__empty">{{ t('builder.empty') }}</p>
    <q-btn-dropdown
      flat
      no-caps
      dense
      icon="r_add"
      :label="nested ? t('builder.addChild') : t('builder.addBlock')"
      class="swc-bl__add"
    >
      <q-list dense>
        <q-item v-for="type in addable" :key="type" v-close-popup clickable @click="add(type)">
          <q-item-section>
            <q-item-label>{{ t(`builder.blocks.${type}`) }}</q-item-label>
            <q-item-label caption>{{ t(`builder.blockHints.${type}`) }}</q-item-label>
          </q-item-section>
        </q-item>
      </q-list>
    </q-btn-dropdown>
  </div>
</template>

<style scoped>
.swc-bl__list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.swc-bl__item {
  border: 1px solid var(--swc-border);
  border-radius: var(--swc-radius-sm);
  background: var(--swc-surface);
}
.swc-bl--nested .swc-bl__item {
  background: var(--swc-surface-2);
}
.swc-bl__item.is-hidden {
  opacity: 0.65;
  border-style: dashed;
}
.swc-bl__head {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 4px 6px;
}
.swc-bl__toggle {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px;
  border: 0;
  border-radius: 8px;
  background: none;
  color: inherit;
  text-align: left;
  cursor: pointer;
  font: inherit;
}
.swc-bl__toggle:hover {
  background: var(--swc-surface-3);
}
.swc-bl__pos {
  display: inline-grid;
  place-items: center;
  min-width: 22px;
  height: 22px;
  border-radius: 6px;
  font-size: 12px;
  background: var(--swc-primary-bg);
  color: var(--swc-primary-text);
}
.swc-bl__name {
  font-weight: 600;
}
.swc-bl__cls {
  color: var(--swc-muted);
  overflow: hidden;
  text-overflow: ellipsis;
}
.swc-bl__hiddenlbl {
  font-size: 12px;
  color: var(--swc-muted);
}
.swc-bl__tools {
  display: flex;
  flex: none;
}
.swc-bl__body {
  padding: 0 12px 12px;
  border-top: 1px solid var(--swc-border);
}
.swc-bl__children {
  margin-top: 10px;
  padding-left: 12px;
  border-left: 3px solid var(--swc-primary-bg);
}
.swc-bl__empty {
  padding: 8px 0;
}
.swc-bl__add {
  margin-top: 8px;
}
</style>
