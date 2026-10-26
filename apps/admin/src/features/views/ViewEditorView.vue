<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useApi } from '@/api';
import type { ApiError } from '@/api/client';
import CopyButton from '@/components/CopyButton.vue';
import TimezoneSelect from '@/components/TimezoneSelect.vue';
import PreviewFrame from '@/components/preview/PreviewFrame.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataState from '@/components/ui/DataState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwcCard from '@/components/ui/SwcCard.vue';
import { toApiError, useAsyncData } from '@/composables/useAsyncData';
import { useFieldErrors } from '@/composables/useFieldErrors';
import { useNotify } from '@/composables/useNotify';
import { useLookupsStore } from '@/stores/lookups';
import type { CalendarView, DisplayMode, RenderResult, TemplateKind, ViewInput } from '@/types/api';
import {
  DISPLAY_MODES,
  blankView,
  cleanColorRules,
  fromFilterForm,
  toFilterForm,
  toViewInput,
} from './viewForm';
import type { FilterForm, TriState } from './viewForm';

const props = defineProps<{ id?: string }>();
const { t } = useI18n();
const api = useApi();
const notify = useNotify();
const router = useRouter();
const lookups = useLookupsStore();

const viewId = computed(() => (props.id ? Number(props.id) : null));
const isEdit = computed(() => viewId.value !== null);

const stored = ref<CalendarView | null>(null);
const form = ref<ViewInput>(blankView());
const filters = ref<FilterForm>(toFilterForm({}));
const dirty = ref(false);

const loader = useAsyncData(
  async () => {
    const [meta, sources, templates] = await Promise.all([
      lookups.loadMeta(),
      lookups.loadSources(true),
      lookups.loadTemplates(true),
    ]);
    const view = viewId.value ? await api.views.get(viewId.value) : null;
    return { meta, sources, templates, view };
  },
  { immediate: false },
);
const meta = computed(() => loader.data.value?.meta ?? null);
const sources = computed(() => loader.data.value?.sources ?? []);
const templates = computed(() => loader.data.value?.templates ?? []);

async function load(): Promise<void> {
  const result = await loader.load();
  if (!result) return;
  stored.value = result.view;
  form.value = result.view ? toViewInput(result.view) : blankView();
  filters.value = toFilterForm(form.value.default_filters);
  await nextTick();
  dirty.value = false;
  if (result.view) void renderPreview();
}
onMounted(load);
watch(viewId, load);
watch([form, filters], () => (dirty.value = true), { deep: true });

/* ---------- options ---------- */
const sourceOptions = computed(() =>
  sources.value.map((s) => ({
    value: s.id,
    label: s.name,
    color: s.color,
    visibility: s.visibility,
  })),
);
const modeOptions = computed(() =>
  DISPLAY_MODES.map((m) => ({ value: m, label: t(`views.modes.${m}`) })),
);
function templateOptions(kind: TemplateKind) {
  return [
    { value: null as number | null, label: t('views.presetDefault') },
    ...templates.value
      .filter((tpl) => tpl.kind === kind)
      .map((tpl) => ({ value: tpl.id as number | null, label: tpl.name })),
  ];
}
const eventTemplateChoices = computed(() =>
  templates.value
    .filter((tpl) => tpl.kind === 'event')
    .map((tpl) => ({ value: tpl.id, label: tpl.name })),
);
const triOptions = computed(() =>
  (['default', 'yes', 'no'] as TriState[]).map((v) => ({ value: v, label: t(`views.tri.${v}`) })),
);
const sortOptions = computed(() => [
  { value: '', label: t('views.tri.default') },
  { value: 'asc', label: t('views.sortAsc') },
  { value: 'desc', label: t('views.sortDesc') },
]);
const selectedSources = computed(() =>
  sources.value.filter((s) => form.value.source_ids.includes(s.id)),
);
const hasNonPublic = computed(() => selectedSources.value.some((s) => s.visibility !== 'public'));

function toggleMode(mode: DisplayMode, on: boolean): void {
  const set = new Set(form.value.allowed_modes);
  if (on) set.add(mode);
  else set.delete(mode);
  form.value.allowed_modes = DISPLAY_MODES.filter((m) => set.has(m));
}
watch(
  () => form.value.display_mode,
  (mode) => {
    if (!form.value.allowed_modes.includes(mode)) toggleMode(mode, true);
  },
);
function setColor(sourceId: number, color: string | null): void {
  const rules = { ...form.value.color_rules };
  if (color === null) delete rules[String(sourceId)];
  else rules[String(sourceId)] = color;
  form.value.color_rules = rules;
}

/* ---------- save ---------- */
const saving = ref(false);
const formError = ref<ApiError | null>(null);
const fields = useFieldErrors(formError);
const required = (v: unknown): true | string =>
  (typeof v === 'string' ? v.trim() !== '' : true) || t('common.required');

function buildBody(): ViewInput {
  return {
    ...form.value,
    name: form.value.name.trim(),
    allowed_modes: form.value.allowed_modes.includes(form.value.display_mode)
      ? form.value.allowed_modes
      : [...form.value.allowed_modes, form.value.display_mode],
    default_filters: fromFilterForm(filters.value),
    color_rules: cleanColorRules(form.value.color_rules, form.value.source_ids),
  };
}

async function save(): Promise<void> {
  saving.value = true;
  formError.value = null;
  const body = buildBody();
  try {
    const saved = stored.value
      ? await api.views.update(stored.value.id, body)
      : await api.views.create(body);
    lookups.invalidate();
    notify.success(t('views.saved'));
    if (!stored.value) {
      await router.replace(`/views/${saved.id}`);
      return;
    }
    stored.value = saved;
    form.value = toViewInput(saved);
    filters.value = toFilterForm(saved.default_filters);
    await nextTick();
    dirty.value = false;
    void renderPreview();
  } catch (e) {
    const err = toApiError(e);
    if (err.status === 400) formError.value = err;
    notify.error(e);
  } finally {
    saving.value = false;
  }
}

/* ---------- delete ---------- */
const deleteOpen = ref(false);
const deleting = ref(false);
async function doDelete(): Promise<void> {
  if (!stored.value) return;
  deleting.value = true;
  try {
    await api.views.remove(stored.value.id);
    notify.success(t('views.deleted'));
    deleteOpen.value = false;
    await router.push('/views');
  } catch (e) {
    notify.error(e);
  } finally {
    deleting.value = false;
  }
}

/* ---------- preview (current form state, admin preview route) ---------- */
const previewMode = ref<DisplayMode | null>(null);
const preview = ref<RenderResult | null>(null);
const previewError = ref<ApiError | null>(null);
const previewLoading = ref(false);
const previewModes = computed(() =>
  (form.value.allowed_modes.length ? form.value.allowed_modes : DISPLAY_MODES).map((m) => ({
    value: m,
    label: t(`views.modes.${m}`),
  })),
);
const canPreview = computed(
  () => form.value.name.trim() !== '' && form.value.source_ids.length > 0,
);
const previewedState = ref('');
const previewStale = computed(
  () => preview.value !== null && previewedState.value !== JSON.stringify(buildBody()),
);
async function renderPreview(): Promise<void> {
  if (!canPreview.value) return;
  previewLoading.value = true;
  previewError.value = null;
  try {
    const body = buildBody();
    previewedState.value = JSON.stringify(body);
    preview.value = await api.views.preview({
      id: stored.value?.id,
      view: body,
      filters: { view: previewMode.value ?? form.value.display_mode },
    });
  } catch (e) {
    previewError.value = toApiError(e);
  } finally {
    previewLoading.value = false;
  }
}
watch(previewMode, () => void renderPreview());
</script>

<template>
  <div>
    <PageHeader
      :title="isEdit ? stored?.name || t('views.editTitle') : t('views.createTitle')"
      :subtitle="t('views.editorSubtitle')"
      back-to="/views"
      :back-label="t('nav.views')"
    >
      <q-btn
        v-if="stored"
        flat
        no-caps
        color="negative"
        icon="r_delete"
        :label="t('common.delete')"
        @click="deleteOpen = true"
      />
    </PageHeader>

    <DataState
      :loading="loader.loading.value"
      :error="loader.error.value"
      :empty="!loader.data.value"
      variant="block"
      @retry="load"
    >
      <div class="swc-ve">
        <q-form class="swc-stack swc-ve__form" greedy @submit="save">
          <div v-if="formError" class="swc-callout is-danger" role="alert">
            <q-icon name="r_error" size="20px" aria-hidden="true" />
            <div>
              <strong>{{ formError.message }}</strong>
              <ul>
                <li v-for="(code, field) in formError.params" :key="field">
                  <code>{{ field }}</code> {{ fields.message(code) }}
                </li>
              </ul>
            </div>
          </div>

          <SwcCard :title="t('views.sectionGeneral')" icon="r_tune">
            <div class="swc-form-grid">
              <q-input
                v-model="form.name"
                outlined
                dense
                maxlength="190"
                :label="t('common.name')"
                :rules="[required]"
                :error="!!fields.field('name')"
                :error-message="fields.field('name')"
              />
              <q-toggle v-model="form.enabled" :label="t('views.enabled')" />
            </div>
            <div v-if="stored" class="swc-ve__sc">
              <span class="swc-eyebrow">{{ t('views.shortcode') }}</span>
              <code>{{ stored.shortcode }}</code>
              <CopyButton :text="stored.shortcode" :label="t('views.copyShortcode')" />
              <StatusChip
                v-if="stored.dynamic"
                kind="info"
                icon="r_bolt"
                :label="t('views.dynamic')"
                dense
              />
            </div>
          </SwcCard>

          <SwcCard :title="t('views.sources')" icon="r_event_note">
            <q-select
              v-model="form.source_ids"
              outlined
              dense
              multiple
              use-chips
              emit-value
              map-options
              :options="sourceOptions"
              :label="t('views.sources')"
              :error="!!fields.field('source_ids')"
              :error-message="fields.field('source_ids')"
            >
              <template #option="scope">
                <q-item v-bind="scope.itemProps">
                  <q-item-section avatar>
                    <span
                      class="swc-swatch"
                      :style="{ background: scope.opt.color }"
                      aria-hidden="true"
                    />
                  </q-item-section>
                  <q-item-section>
                    <q-item-label>{{ scope.opt.label }}</q-item-label>
                    <q-item-label caption>{{
                      t(`status.visibility.${scope.opt.visibility}`)
                    }}</q-item-label>
                  </q-item-section>
                </q-item>
              </template>
            </q-select>
            <p v-if="hasNonPublic" class="swc-callout is-info">{{ t('views.dynamicHint') }}</p>
            <div v-if="selectedSources.length > 0">
              <p class="swc-eyebrow">{{ t('views.colorOverrides') }}</p>
              <ul class="swc-ve__colors">
                <li v-for="s in selectedSources" :key="s.id">
                  <span
                    class="swc-swatch"
                    :style="{ background: form.color_rules[String(s.id)] ?? s.color }"
                    aria-hidden="true"
                  />
                  <span class="swc-ve__cname">{{ s.name }}</span>
                  <input
                    type="color"
                    class="swc-ve__picker"
                    :value="form.color_rules[String(s.id)] ?? s.color"
                    :aria-label="t('views.colorFor', { name: s.name })"
                    @input="setColor(s.id, ($event.target as HTMLInputElement).value)"
                  />
                  <q-btn
                    v-if="form.color_rules[String(s.id)]"
                    flat
                    dense
                    no-caps
                    :label="t('views.useSourceColor')"
                    @click="setColor(s.id, null)"
                  />
                </li>
              </ul>
            </div>
          </SwcCard>

          <SwcCard :title="t('views.sectionDisplay')" icon="r_view_quilt">
            <SegmentedControl
              v-model="form.display_mode"
              :options="modeOptions"
              :legend="t('views.displayMode')"
            />
            <fieldset class="swc-ve__fs">
              <legend>{{ t('views.allowedModes') }}</legend>
              <q-checkbox
                v-for="m in DISPLAY_MODES"
                :key="m"
                :model-value="form.allowed_modes.includes(m)"
                :disable="m === form.display_mode"
                :label="t(`views.modes.${m}`)"
                @update:model-value="(v: boolean) => toggleMode(m, v)"
              />
              <p class="swc-hint">{{ t('views.allowedModesHint') }}</p>
            </fieldset>
            <div class="swc-form-grid">
              <q-select
                v-model="form.container_template_id"
                outlined
                dense
                emit-value
                map-options
                :options="templateOptions('container')"
                :label="t('views.containerTemplate')"
                :error="!!fields.field('container_template_id')"
                :error-message="fields.field('container_template_id')"
              />
              <q-select
                v-model="form.event_template_id"
                outlined
                dense
                emit-value
                map-options
                :options="templateOptions('event')"
                :label="t('views.eventTemplate')"
                :error="!!fields.field('event_template_id')"
                :error-message="fields.field('event_template_id')"
              />
              <q-select
                v-model="form.detail_template_id"
                outlined
                dense
                emit-value
                map-options
                :options="templateOptions('detail')"
                :label="t('views.detailTemplate')"
                :error="!!fields.field('detail_template_id')"
                :error-message="fields.field('detail_template_id')"
              />
            </div>
            <q-select
              v-model="form.allowed_template_ids"
              outlined
              dense
              multiple
              use-chips
              emit-value
              map-options
              :options="eventTemplateChoices"
              :label="t('views.allowedTemplates')"
              :hint="t('views.allowedTemplatesHint')"
              :error="!!fields.field('allowed_template_ids')"
              :error-message="fields.field('allowed_template_ids')"
            />
          </SwcCard>

          <SwcCard :title="t('views.sectionFormat')" icon="r_schedule">
            <div class="swc-form-grid">
              <TimezoneSelect
                v-model="form.timezone"
                :timezones="meta?.timezones ?? []"
                allow-empty
                :empty-label="t('views.siteTimezone', { tz: meta?.site_timezone ?? 'UTC' })"
                :label="t('views.timezone')"
                :error="fields.field('timezone')"
              />
              <q-input
                v-model="form.language"
                outlined
                dense
                maxlength="20"
                :label="t('views.language')"
                :hint="t('views.languageHint')"
                :error="!!fields.field('language')"
                :error-message="fields.field('language')"
              />
              <q-input
                v-model="form.date_format"
                outlined
                dense
                maxlength="40"
                :label="t('views.dateFormat')"
                :placeholder="meta?.date_format"
                :hint="t('views.formatHint', { format: meta?.date_format ?? '' })"
                :error="!!fields.field('date_format')"
                :error-message="fields.field('date_format')"
              />
              <q-input
                v-model="form.time_format"
                outlined
                dense
                maxlength="40"
                :label="t('views.timeFormat')"
                :placeholder="meta?.time_format"
                :hint="t('views.formatHint', { format: meta?.time_format ?? '' })"
                :error="!!fields.field('time_format')"
                :error-message="fields.field('time_format')"
              />
            </div>
            <q-toggle v-model="form.html_cache" :label="t('views.htmlCache')" />
            <p class="swc-hint">{{ t('views.htmlCacheHint') }}</p>
          </SwcCard>

          <SwcCard
            :title="t('views.sectionFilters')"
            :subtitle="t('views.filtersHint')"
            icon="r_filter_alt"
          >
            <div class="swc-form-grid">
              <q-input
                v-model="filters.limit"
                outlined
                dense
                type="number"
                min="1"
                max="100"
                :label="t('views.filters.limit')"
                :error="!!fields.field('default_filters.limit')"
                :error-message="fields.field('default_filters.limit')"
              />
              <q-input
                v-model="filters.days"
                outlined
                dense
                type="number"
                min="1"
                max="1095"
                :label="t('views.filters.days')"
                :error="!!fields.field('default_filters.days')"
                :error-message="fields.field('default_filters.days')"
              />
              <q-input
                v-model="filters.start"
                outlined
                dense
                type="date"
                stack-label
                :label="t('views.filters.start')"
                :error="!!fields.field('default_filters.start')"
                :error-message="fields.field('default_filters.start')"
              />
              <q-input
                v-model="filters.end"
                outlined
                dense
                type="date"
                stack-label
                :label="t('views.filters.end')"
                :error="!!fields.field('default_filters.end')"
                :error-message="fields.field('default_filters.end')"
              />
              <q-select
                v-model="filters.past"
                outlined
                dense
                emit-value
                map-options
                :options="triOptions"
                :label="t('views.filters.past')"
              />
              <q-select
                v-model="filters.sort"
                outlined
                dense
                emit-value
                map-options
                :options="sortOptions"
                :label="t('views.filters.sort')"
              />
              <q-input
                v-model="filters.search"
                outlined
                dense
                maxlength="150"
                :label="t('views.filters.search')"
                :error="!!fields.field('default_filters.search')"
                :error-message="fields.field('default_filters.search')"
              />
              <q-input
                v-model="filters.location"
                outlined
                dense
                maxlength="150"
                :label="t('views.filters.location')"
                :error="!!fields.field('default_filters.location')"
                :error-message="fields.field('default_filters.location')"
              />
              <q-select
                v-model="filters.show_images"
                outlined
                dense
                emit-value
                map-options
                :options="triOptions"
                :label="t('views.filters.showImages')"
              />
              <q-select
                v-model="filters.show_attachments"
                outlined
                dense
                emit-value
                map-options
                :options="triOptions"
                :label="t('views.filters.showFiles')"
              />
            </div>
            <p class="swc-hint">{{ t('views.filtersPrecedence') }}</p>
          </SwcCard>

          <div class="swc-ve__footer">
            <q-badge
              v-if="dirty && isEdit"
              color="warning"
              text-color="dark"
              :label="t('common.unsaved')"
            />
            <q-btn flat no-caps to="/views" :label="t('common.cancel')" />
            <q-btn
              unelevated
              no-caps
              color="primary"
              type="submit"
              icon="r_save"
              :loading="saving"
              :label="t('common.save')"
            />
          </div>
        </q-form>

        <aside class="swc-ve__preview">
          <SwcCard :title="t('views.preview')" :subtitle="t('views.previewHint')" icon="r_preview">
            <template v-if="canPreview" #actions>
              <q-btn
                flat
                round
                dense
                icon="r_refresh"
                :aria-label="t('views.refreshPreview')"
                :loading="previewLoading"
                @click="renderPreview"
              />
            </template>
            <p v-if="!canPreview" class="swc-hint">{{ t('views.previewAfterSave') }}</p>
            <template v-else>
              <SegmentedControl
                v-if="previewModes.length > 1"
                :model-value="previewMode ?? form.display_mode"
                :options="previewModes"
                :legend="t('views.previewMode')"
                @update:model-value="(v: DisplayMode | null) => (previewMode = v)"
              />
              <p v-if="previewStale" class="swc-callout is-warning">
                {{ t('views.previewStale') }}
              </p>
              <p v-if="previewError" class="swc-callout is-danger" role="alert">
                {{ previewError.message }} <code>{{ previewError.code }}</code>
              </p>
              <PreviewFrame
                v-else
                :html="preview?.html ?? ''"
                :css="preview?.css ?? ''"
                :title="t('views.previewFrameTitle', { name: form.name })"
                :loading="previewLoading"
                :height="640"
              />
              <p v-if="preview" class="swc-hint">
                {{ t('views.previewTotal', { n: preview.total }, preview.total) }}
              </p>
            </template>
          </SwcCard>
        </aside>
      </div>
    </DataState>

    <ConfirmDialog
      v-model="deleteOpen"
      :title="t('views.deleteTitle')"
      :target="stored ? `${stored.name} ${stored.shortcode}` : undefined"
      :consequences="[t('views.deleteConsequence1'), t('common.irreversible')]"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      @confirm="doDelete"
    />
  </div>
</template>

<style scoped>
.swc-ve {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}
@media (max-width: 1180px) {
  .swc-ve {
    grid-template-columns: minmax(0, 1fr);
  }
}
.swc-ve__form :deep(.swc-card__body),
.swc-ve__preview :deep(.swc-card__body) {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.swc-ve__preview {
  position: sticky;
  top: calc(var(--wp-admin--admin-bar--height, 32px) + 16px);
}
.swc-ve__sc {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.swc-ve__colors {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: 6px;
}
.swc-ve__colors li {
  display: flex;
  align-items: center;
  gap: 10px;
}
.swc-ve__cname {
  flex: 1;
  min-width: 0;
}
.swc-ve__picker {
  width: 36px;
  height: 28px;
  padding: 0;
  border: 1px solid var(--swc-border);
  border-radius: 6px;
  background: none;
  cursor: pointer;
}
.swc-ve__fs {
  border: 1px solid var(--swc-border);
  border-radius: var(--swc-radius-sm);
  padding: 8px 12px;
  margin: 0;
}
.swc-ve__fs legend {
  font-size: 13px;
  font-weight: 600;
  padding: 0 4px;
}
.swc-ve__footer {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 8px;
  position: sticky;
  bottom: 0;
  padding: 12px 0;
  background: var(--swc-bg);
  z-index: 2;
}
</style>
