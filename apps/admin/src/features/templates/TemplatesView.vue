<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useApi } from '@/api';
import PreviewFrame from '@/components/preview/PreviewFrame.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SideSheet from '@/components/ui/SideSheet.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwcCard from '@/components/ui/SwcCard.vue';
import { toApiError, useAsyncData } from '@/composables/useAsyncData';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import { useLookupsStore } from '@/stores/lookups';
import type {
  DisplayMode,
  EditorMode,
  TemplateKind,
  TemplatePreset,
  TemplatePreviewResult,
  TemplateSummary,
} from '@/types/api';

const { t } = useI18n();
const fmt = useFormat();
const api = useApi();
const notify = useNotify();
const router = useRouter();
const lookups = useLookupsStore();

const KINDS: TemplateKind[] = ['container', 'event', 'detail'];
const list = useAsyncData(() => api.templates.list());
const presets = useAsyncData(() => api.templates.presets());
const items = computed(() => list.data.value?.items ?? []);
const grouped = computed(() =>
  KINDS.map((kind) => ({ kind, items: items.value.filter((tpl) => tpl.kind === kind) })),
);

function newTemplate(kind: TemplateKind, mode: EditorMode): void {
  void router.push({ path: '/templates/new', query: { kind, mode } });
}

/* duplicate */
const duplicating = ref<number | null>(null);
async function duplicate(tpl: TemplateSummary): Promise<void> {
  duplicating.value = tpl.id;
  try {
    const full = await api.templates.get(tpl.id);
    const copy = await api.templates.create({
      name: t('templates.copyName', { name: full.name }).slice(0, 190),
      kind: full.kind,
      editor_mode: full.editor_mode,
      body: full.body,
      css: full.css,
      visual_schema: full.editor_mode === 'visual' ? full.visual_schema : null,
    });
    notify.success(t('templates.duplicated'));
    lookups.invalidate();
    await router.push(`/templates/${copy.id}`);
  } catch (e) {
    notify.error(e);
  } finally {
    duplicating.value = null;
  }
}

/* delete */
const toDelete = ref<TemplateSummary | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);
const deleteError = ref<string | null>(null);
function askDelete(tpl: TemplateSummary): void {
  toDelete.value = tpl;
  deleteError.value = null;
  deleteOpen.value = true;
}
async function doDelete(): Promise<void> {
  if (!toDelete.value) return;
  deleting.value = true;
  try {
    await api.templates.remove(toDelete.value.id);
    notify.success(t('templates.deleted'));
    deleteOpen.value = false;
    lookups.invalidate();
    await list.load();
  } catch (e) {
    const err = toApiError(e);
    if (err.status === 409) deleteError.value = `${err.message} ${t('templates.inUse')}`;
    else notify.error(e);
  } finally {
    deleting.value = false;
  }
}

/* presets */
const installing = ref<string | null>(null);
async function install(p: TemplatePreset): Promise<void> {
  installing.value = p.key;
  try {
    const created = await api.templates.installPreset(p.key);
    notify.success(
      t('templates.presetInstalled', { name: p.name, n: created.length }, created.length),
    );
    lookups.invalidate();
    await list.load();
  } catch (e) {
    notify.error(e);
  } finally {
    installing.value = null;
  }
}
const previewOpen = ref(false);
const previewPreset = ref<TemplatePreset | null>(null);
const previewResult = ref<TemplatePreviewResult | null>(null);
const previewLoading = ref(false);
async function showPreset(p: TemplatePreset): Promise<void> {
  previewPreset.value = p;
  previewResult.value = null;
  previewOpen.value = true;
  previewLoading.value = true;
  const mode: DisplayMode = p.mode === 'cards' || p.mode === 'month' ? p.mode : 'list';
  try {
    previewResult.value = await api.templates.preview({
      mode,
      container: p.templates.container,
      event: p.templates.event,
      detail: p.templates.detail,
    });
  } catch (e) {
    notify.error(e);
  } finally {
    previewLoading.value = false;
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('nav.templates')" :subtitle="t('templates.subtitle')">
      <q-btn-dropdown unelevated no-caps color="primary" icon="r_add" :label="t('templates.add')">
        <q-list dense>
          <template v-for="kind in KINDS" :key="kind">
            <q-item-label header>{{ t(`templates.kinds.${kind}`) }}</q-item-label>
            <q-item v-close-popup clickable @click="newTemplate(kind, 'visual')">
              <q-item-section avatar><q-icon name="r_dashboard_customize" /></q-item-section>
              <q-item-section>{{ t('templates.newVisual') }}</q-item-section>
            </q-item>
            <q-item v-close-popup clickable @click="newTemplate(kind, 'html')">
              <q-item-section avatar><q-icon name="r_code" /></q-item-section>
              <q-item-section>{{ t('templates.newHtml') }}</q-item-section>
            </q-item>
          </template>
        </q-list>
      </q-btn-dropdown>
    </PageHeader>

    <DataState
      :loading="list.loading.value"
      :error="list.error.value"
      :empty="!list.data.value"
      @retry="list.load()"
    >
      <div class="swc-stack">
        <SwcCard
          v-for="group in grouped"
          :key="group.kind"
          :title="t(`templates.kinds.${group.kind}`)"
          :subtitle="t(`templates.kindHints.${group.kind}`)"
          :icon="
            group.kind === 'container'
              ? 'r_crop_free'
              : group.kind === 'event'
                ? 'r_event'
                : 'r_article'
          "
          :padding="false"
        >
          <EmptyState
            v-if="group.items.length === 0"
            compact
            illustration="list"
            :title="t('templates.emptyKind')"
          />
          <div v-else class="swc-table-wrap">
            <table class="swc-table">
              <thead>
                <tr>
                  <th scope="col">{{ t('common.name') }}</th>
                  <th scope="col">{{ t('templates.editorMode') }}</th>
                  <th scope="col">{{ t('templates.usedBy') }}</th>
                  <th scope="col">{{ t('common.updated') }}</th>
                  <th scope="col">
                    <span class="swc-sr-only">{{ t('common.actions') }}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="tpl in group.items" :key="tpl.id">
                  <td :data-label="t('common.name')">
                    <router-link :to="`/templates/${tpl.id}`" class="swc-tl-name">{{
                      tpl.name
                    }}</router-link>
                    <span v-if="tpl.preset_key" class="swc-muted swc-tl-preset">{{
                      t('templates.fromPreset', { key: tpl.preset_key })
                    }}</span>
                  </td>
                  <td :data-label="t('templates.editorMode')">
                    <StatusChip
                      :kind="tpl.editor_mode === 'visual' ? 'primary' : 'neutral'"
                      :icon="tpl.editor_mode === 'visual' ? 'r_dashboard_customize' : 'r_code'"
                      :label="t(`templates.modes.${tpl.editor_mode}`)"
                      dense
                    />
                  </td>
                  <td :data-label="t('templates.usedBy')" class="swc-num">
                    {{
                      tpl.used_by.length > 0
                        ? t('templates.usedByViews', { n: tpl.used_by.length }, tpl.used_by.length)
                        : '–'
                    }}
                  </td>
                  <td :data-label="t('common.updated')">
                    {{ tpl.updated_at ? fmt.relative(tpl.updated_at) : '–' }}
                  </td>
                  <td data-label="" class="swc-tl-actions">
                    <q-btn
                      flat
                      round
                      dense
                      icon="r_edit"
                      :to="`/templates/${tpl.id}`"
                      :aria-label="t('common.editNamed', { name: tpl.name })"
                    >
                      <q-tooltip>{{ t('common.edit') }}</q-tooltip>
                    </q-btn>
                    <q-btn
                      flat
                      round
                      dense
                      icon="r_content_copy"
                      :loading="duplicating === tpl.id"
                      :aria-label="t('templates.duplicateNamed', { name: tpl.name })"
                      @click="duplicate(tpl)"
                    >
                      <q-tooltip>{{ t('common.duplicate') }}</q-tooltip>
                    </q-btn>
                    <q-btn
                      flat
                      round
                      dense
                      color="negative"
                      icon="r_delete"
                      :aria-label="t('common.deleteNamed', { name: tpl.name })"
                      @click="askDelete(tpl)"
                    >
                      <q-tooltip>{{ t('common.delete') }}</q-tooltip>
                    </q-btn>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </SwcCard>

        <SwcCard
          :title="t('templates.presetGallery')"
          :subtitle="t('templates.presetGalleryHint')"
          icon="r_auto_awesome"
        >
          <DataState
            :loading="presets.loading.value"
            :error="presets.error.value"
            :empty="(presets.data.value?.length ?? 0) === 0"
            compact-error
            variant="cards"
            :rows="2"
            @retry="presets.load()"
          >
            <ul class="swc-grid swc-tl-presets">
              <li v-for="p in presets.data.value ?? []" :key="p.key" class="swc-tl-preset">
                <h3>{{ p.name }}</h3>
                <p class="swc-muted">{{ p.description }}</p>
                <span class="swc-eyebrow">{{ t(`views.modes.${p.mode}`) }}</span>
                <div class="swc-row">
                  <q-btn
                    flat
                    dense
                    no-caps
                    icon="r_preview"
                    :label="t('templates.previewPreset')"
                    @click="showPreset(p)"
                  />
                  <q-btn
                    unelevated
                    dense
                    no-caps
                    color="primary"
                    icon="r_download"
                    :label="t('templates.installPreset')"
                    :aria-label="t('templates.installPresetNamed', { name: p.name })"
                    :loading="installing === p.key"
                    @click="install(p)"
                  />
                </div>
              </li>
            </ul>
          </DataState>
        </SwcCard>
      </div>
    </DataState>

    <SideSheet
      v-model="previewOpen"
      :title="previewPreset?.name ?? ''"
      :subtitle="t('templates.presetPreviewHint')"
      :width="900"
    >
      <PreviewFrame
        :html="previewResult?.html ?? ''"
        :css="previewResult?.css ?? ''"
        :title="t('templates.previewFrameTitle', { name: previewPreset?.name ?? '' })"
        :loading="previewLoading"
        :height="680"
      />
    </SideSheet>

    <ConfirmDialog
      v-model="deleteOpen"
      :title="t('templates.deleteTitle')"
      :target="toDelete ? `${toDelete.name} (${t(`templates.kinds.${toDelete.kind}`)})` : undefined"
      :consequences="[t('templates.deleteConsequence1'), t('common.irreversible')]"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      :error="deleteError"
      @confirm="doDelete"
    />
  </div>
</template>

<style scoped>
.swc-tl-name {
  font-weight: 600;
}
.swc-tl-preset {
  display: block;
  font-size: 12px;
}
.swc-tl-actions {
  white-space: nowrap;
  text-align: right;
}
.swc-tl-presets {
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
}
.swc-tl-preset {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 14px;
  border: 1px solid var(--swc-border);
  border-radius: var(--swc-radius-sm);
  background: var(--swc-gradient-soft);
}
.swc-tl-preset h3 {
  font-size: 16px;
  font-weight: 700;
}
.swc-tl-preset p {
  font-size: 14px;
  flex: 1;
}
</style>
