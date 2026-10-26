<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { useApi } from '@/api';
import type { ApiError } from '@/api/client';
import CodeEditor from '@/components/editor/CodeEditor.vue';
import type { EditorMarker } from '@/components/editor/CodeEditor.vue';
import PreviewFrame from '@/components/preview/PreviewFrame.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataState from '@/components/ui/DataState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwcCard from '@/components/ui/SwcCard.vue';
import { toApiError, useAsyncData } from '@/composables/useAsyncData';
import { useNotify } from '@/composables/useNotify';
import { useLookupsStore } from '@/stores/lookups';
import type {
  DisplayMode,
  EditorMode,
  Template,
  TemplateKind,
  TemplatePreviewResult,
  TemplateSyntaxError,
  TemplateValidation,
} from '@/types/api';
import BlockList from './BlockList.vue';
import PlaceholderPanel from './PlaceholderPanel.vue';
import { canEditVisually, defaultSchema, generateHtml, parseSchema } from './visualBuilder';
import type { VisualSchema } from './visualBuilder';

const props = defineProps<{ id?: string }>();
const { t } = useI18n();
const api = useApi();
const notify = useNotify();
const route = useRoute();
const router = useRouter();
const lookups = useLookupsStore();

const KINDS: TemplateKind[] = ['container', 'event', 'detail'];
const templateId = computed(() => (props.id ? Number(props.id) : null));
const isEdit = computed(() => templateId.value !== null);

/* ---------- state ---------- */
const stored = ref<Template | null>(null);
const name = ref('');
const kind = ref<TemplateKind>('event');
const mode = ref<EditorMode>('html');
const body = ref('');
const css = ref('');
const schema = ref<VisualSchema | null>(null);
const dirty = ref(false);
const tab = ref<'markup' | 'css'>('markup');
const sideTab = ref<'preview' | 'reference'>('preview');
const htmlEditor = ref<InstanceType<typeof CodeEditor> | null>(null);

const loader = useAsyncData(
  async () => {
    const reference = await lookups.loadReference().catch(() => null);
    const tpl = templateId.value ? await api.templates.get(templateId.value) : null;
    return { reference, tpl };
  },
  { immediate: false },
);

function queryKind(): TemplateKind {
  const k = route.query.kind;
  return typeof k === 'string' && (KINDS as string[]).includes(k) ? (k as TemplateKind) : 'event';
}

async function load(): Promise<void> {
  const result = await loader.load();
  if (!result) return;
  const tpl = result.tpl;
  stored.value = tpl;
  if (tpl) {
    name.value = tpl.name;
    kind.value = tpl.kind;
    body.value = tpl.body;
    css.value = tpl.css;
    schema.value = parseSchema(tpl.visual_schema, tpl.kind);
    mode.value =
      tpl.editor_mode === 'visual' && schema.value && canEditVisually(schema.value, tpl.body)
        ? 'visual'
        : 'html';
  } else {
    kind.value = queryKind();
    name.value = '';
    css.value = '';
    mode.value = route.query.mode === 'html' ? 'html' : 'visual';
    schema.value = mode.value === 'visual' ? defaultSchema(kind.value) : null;
    body.value = schema.value ? generateHtml(schema.value) : starterHtml(kind.value);
  }
  previewMode.value = 'list';
  await nextTick();
  dirty.value = false;
  scheduleChecks(0);
}
onMounted(load);
watch(templateId, load);

function starterHtml(k: TemplateKind): string {
  if (k === 'container') {
    return '<section class="my-calendar">\n  <h2>{{calendar.title}}</h2>\n  {{calendar.navigation}}\n  {{calendar.events}}\n</section>\n';
  }
  return '<article class="my-event">\n  <h3>{{event.title}}</h3>\n  <time datetime="{{event.start_iso}}">{{event.date_range}}</time>\n  {{#event.location}}<p>{{event.location}}</p>{{/event.location}}\n</article>\n';
}

/* Visual mode: the body is always generated from the block list. */
watch(
  schema,
  (value) => {
    if (mode.value === 'visual' && value) body.value = generateHtml(value);
  },
  { deep: true },
);
watch([name, body, css], () => (dirty.value = true));

const placeholders = computed(() => loader.data.value?.reference?.[kind.value] ?? []);
const visualPossible = computed(() => canEditVisually(schema.value, body.value));

/* ---------- validation + preview (debounced, aborted when superseded) ---------- */
const validation = ref<TemplateValidation | null>(null);
const validating = ref(false);
const previewMode = ref<DisplayMode>('list');
const preview = ref<TemplatePreviewResult | null>(null);
const previewLoading = ref(false);
const previewFailure = ref<ApiError | null>(null);
let timer: number | undefined;
let controller: AbortController | null = null;

function scheduleChecks(delay = 600): void {
  window.clearTimeout(timer);
  timer = window.setTimeout(() => void runChecks(), delay);
}
async function runChecks(): Promise<void> {
  controller?.abort();
  const ctl = new AbortController();
  controller = ctl;
  validating.value = true;
  previewLoading.value = true;
  const part = { body: body.value, css: css.value };
  const [v, p] = await Promise.allSettled([
    api.templates.validate({ kind: kind.value, body: part.body, css: part.css }, ctl.signal),
    api.templates.preview({ mode: previewMode.value, [kind.value]: part }, ctl.signal),
  ]);
  if (ctl.signal.aborted) return;
  if (v.status === 'fulfilled') validation.value = v.value;
  else if (!toApiError(v.reason).isAborted) notify.error(v.reason);
  if (p.status === 'fulfilled') {
    preview.value = p.value;
    previewFailure.value = null;
  } else {
    const err = toApiError(p.reason);
    if (!err.isAborted) previewFailure.value = err;
  }
  validating.value = false;
  previewLoading.value = false;
}
watch([body, css], () => scheduleChecks());
watch(previewMode, () => scheduleChecks(0));
onBeforeUnmount(() => {
  window.clearTimeout(timer);
  controller?.abort();
});

const errors = computed<TemplateSyntaxError[]>(() => validation.value?.errors ?? []);
const markers = computed<EditorMarker[]>(() =>
  errors.value.map((e) => ({ line: e.line, column: e.column, message: e.message })),
);
const previewErrors = computed(() => {
  const raw = preview.value?.errors;
  if (!raw || Array.isArray(raw)) return [];
  return Object.entries(raw).flatMap(([k, list]) =>
    (list ?? []).map((e) => ({ kind: k, message: e.message ?? e.code ?? '' })),
  );
});

/* ---------- mode switching ---------- */
const toHtmlOpen = ref(false);
const toVisualBlockedOpen = ref(false);
function requestMode(next: EditorMode | null): void {
  if (!next || next === mode.value) return;
  if (next === 'html') {
    toHtmlOpen.value = true;
    return;
  }
  if (visualPossible.value) {
    mode.value = 'visual';
    return;
  }
  toVisualBlockedOpen.value = true;
}
function switchToHtml(): void {
  mode.value = 'html';
  toHtmlOpen.value = false;
}
const duplicating = ref(false);
async function duplicateAsHtml(): Promise<void> {
  duplicating.value = true;
  try {
    const copy = await api.templates.create({
      name: t('templates.htmlCopyName', { name: name.value || t('templates.untitled') }).slice(
        0,
        190,
      ),
      kind: kind.value,
      editor_mode: 'html',
      body: body.value,
      css: css.value,
      visual_schema: null,
    });
    notify.success(t('templates.duplicated'));
    toHtmlOpen.value = false;
    lookups.invalidate();
    await router.push(`/templates/${copy.id}`);
  } catch (e) {
    notify.error(e);
  } finally {
    duplicating.value = false;
  }
}
function newVisual(): void {
  toVisualBlockedOpen.value = false;
  void router.push({ path: '/templates/new', query: { kind: kind.value, mode: 'visual' } });
}

/* ---------- save / delete ---------- */
const saving = ref(false);
const saveError = ref<ApiError | null>(null);
async function save(): Promise<void> {
  if (name.value.trim() === '') {
    saveError.value = null;
    notify.warning(t('templates.nameRequired'));
    return;
  }
  saving.value = true;
  saveError.value = null;
  // HTML that still equals the generated markup stays a visual template.
  const asVisual = schema.value !== null && (mode.value === 'visual' || visualPossible.value);
  const payload = {
    name: name.value.trim(),
    editor_mode: (asVisual ? 'visual' : 'html') as EditorMode,
    body: body.value,
    css: css.value,
    visual_schema: asVisual ? schema.value : null,
  };
  try {
    const saved = stored.value
      ? await api.templates.update(stored.value.id, payload)
      : await api.templates.create({ ...payload, kind: kind.value });
    lookups.invalidate();
    notify.success(t('templates.saved'));
    if (!stored.value) {
      await router.replace(`/templates/${saved.id}`);
      return;
    }
    stored.value = saved;
    await nextTick();
    dirty.value = false;
  } catch (e) {
    const err = toApiError(e);
    saveError.value = err;
    const v = err.extra.validation as TemplateValidation | undefined;
    if (v && Array.isArray(v.errors)) validation.value = v;
    notify.error(e);
  } finally {
    saving.value = false;
  }
}

const deleteOpen = ref(false);
const deleting = ref(false);
const deleteError = ref<string | null>(null);
async function doDelete(): Promise<void> {
  if (!stored.value) return;
  deleting.value = true;
  deleteError.value = null;
  try {
    await api.templates.remove(stored.value.id);
    notify.success(t('templates.deleted'));
    lookups.invalidate();
    deleteOpen.value = false;
    await router.push('/templates');
  } catch (e) {
    const err = toApiError(e);
    if (err.status === 409) deleteError.value = `${err.message} ${t('templates.inUse')}`;
    else notify.error(e);
  } finally {
    deleting.value = false;
  }
}

function insertPlaceholder(placeholder: string, sigil: '' | '#' | '^'): void {
  tab.value = 'markup';
  void nextTick(() => htmlEditor.value?.insertPlaceholder(placeholder, sigil));
}
function goToError(e: TemplateSyntaxError): void {
  if (mode.value !== 'html') return;
  tab.value = 'markup';
  void nextTick(() => htmlEditor.value?.goTo(e.line, e.column));
}

const modeOptions = computed(() => [
  { value: 'visual' as EditorMode, label: t('templates.modes.visual') },
  { value: 'html' as EditorMode, label: t('templates.modes.html') },
]);
const previewModes = computed(() =>
  (['list', 'cards', 'month'] as DisplayMode[]).map((m) => ({
    value: m,
    label: t(`views.modes.${m}`),
  })),
);
</script>

<template>
  <div>
    <PageHeader
      :title="isEdit ? stored?.name || t('templates.editTitle') : t('templates.createTitle')"
      :subtitle="t(`templates.kindHints.${kind}`)"
      back-to="/templates"
      :back-label="t('nav.templates')"
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
      <q-btn
        unelevated
        no-caps
        color="primary"
        icon="r_save"
        :loading="saving"
        :label="t('common.save')"
        @click="save"
      />
    </PageHeader>

    <DataState
      :loading="loader.loading.value"
      :error="loader.error.value"
      :empty="!loader.data.value"
      variant="block"
      @retry="load"
    >
      <div class="swc-stack">
        <SwcCard>
          <div class="swc-te__meta">
            <q-input
              v-model="name"
              outlined
              dense
              maxlength="190"
              class="swc-te__name"
              :label="t('common.name')"
              :error="saveError?.params.name !== undefined"
              :error-message="t('validation.invalid')"
            />
            <div class="swc-te__kind">
              <span class="swc-eyebrow">{{ t('templates.kind') }}</span>
              <StatusChip kind="primary" icon="r_category" :label="t(`templates.kinds.${kind}`)" />
            </div>
            <div>
              <span class="swc-eyebrow">{{ t('templates.editorMode') }}</span>
              <SegmentedControl
                :model-value="mode"
                :options="modeOptions"
                :legend="t('templates.editorMode')"
                @update:model-value="requestMode"
              />
            </div>
            <q-badge v-if="dirty" color="warning" text-color="dark" :label="t('common.unsaved')" />
          </div>
          <p v-if="mode === 'html' && schema && !visualPossible" class="swc-hint swc-te__note">
            {{ t('templates.visualLocked') }}
          </p>
        </SwcCard>

        <div v-if="saveError" class="swc-callout is-danger" role="alert">
          <q-icon name="r_error" size="20px" aria-hidden="true" />
          <div>
            <strong>{{ saveError.message }}</strong>
            <code class="swc-mono"> {{ saveError.code }}</code>
          </div>
        </div>

        <div class="swc-te">
          <SwcCard class="swc-te__editor" :padding="true">
            <q-tabs
              v-model="tab"
              dense
              no-caps
              align="left"
              class="swc-te__tabs"
              :aria-label="t('templates.editorTabs')"
            >
              <q-tab
                name="markup"
                :label="mode === 'visual' ? t('templates.tabBlocks') : t('templates.tabMarkup')"
              />
              <q-tab name="css" :label="t('templates.tabCss')" />
            </q-tabs>
            <div v-show="tab === 'markup'" class="swc-te__pane">
              <template v-if="mode === 'visual' && schema">
                <div class="swc-te__root">
                  <q-input
                    v-model="schema.root.cssClass"
                    outlined
                    dense
                    maxlength="120"
                    :label="t('builder.rootClass')"
                    :hint="t('builder.fields.cssClassHint')"
                  />
                  <q-toggle
                    v-if="kind !== 'container'"
                    v-model="schema.root.colorAccent"
                    :label="t('builder.colorAccent')"
                  />
                </div>
                <BlockList v-model="schema.blocks" :kind="kind" />
                <q-expansion-item
                  dense
                  switch-toggle-side
                  icon="r_code"
                  :label="t('builder.generated')"
                >
                  <pre class="swc-te__generated swc-mono">{{ body }}</pre>
                </q-expansion-item>
              </template>
              <CodeEditor
                v-else
                ref="htmlEditor"
                v-model="body"
                language="html"
                :label="t('templates.markupEditor')"
                :placeholders="placeholders"
                :markers="markers"
                :min-height="420"
              />
              <p class="swc-hint">{{ t('templates.syntaxHint') }}</p>
            </div>
            <div v-show="tab === 'css'" class="swc-te__pane">
              <CodeEditor
                v-model="css"
                language="css"
                :label="t('templates.cssEditor')"
                :min-height="300"
              />
              <p class="swc-hint">{{ t('templates.cssHint') }}</p>
            </div>

            <section class="swc-te__validation" :aria-label="t('templates.validation')">
              <div class="swc-row">
                <strong>{{ t('templates.validation') }}</strong>
                <q-spinner v-if="validating" size="16px" />
                <StatusChip
                  v-else-if="validation"
                  :kind="validation.valid ? 'success' : 'danger'"
                  :icon="validation.valid ? 'r_check_circle' : 'r_error'"
                  :label="
                    validation.valid
                      ? t('templates.valid')
                      : t(
                          'templates.invalid',
                          { n: errors.length + (validation.css_error ? 1 : 0) },
                          errors.length + (validation.css_error ? 1 : 0),
                        )
                  "
                  dense
                />
              </div>
              <ul
                v-if="errors.length > 0 || validation?.css_error"
                class="swc-te__errors"
                aria-live="polite"
              >
                <li v-for="(e, i) in errors" :key="i">
                  <button
                    type="button"
                    class="swc-te__err"
                    :disabled="mode !== 'html'"
                    @click="goToError(e)"
                  >
                    <span class="swc-mono swc-num">{{
                      t('templates.lineCol', { line: e.line, column: e.column })
                    }}</span>
                    <span>{{ e.message }}</span>
                    <code>{{ e.code }}</code>
                  </button>
                </li>
                <li v-if="validation?.css_error" class="swc-te__err is-css">
                  <span class="swc-mono">CSS</span> <span>{{ validation.css_error }}</span>
                </li>
              </ul>
            </section>
          </SwcCard>

          <SwcCard class="swc-te__side">
            <q-tabs
              v-model="sideTab"
              dense
              no-caps
              align="left"
              :aria-label="t('templates.sideTabs')"
            >
              <q-tab name="preview" icon="r_preview" :label="t('templates.preview')" />
              <q-tab name="reference" icon="r_data_object" :label="t('templates.reference')" />
            </q-tabs>
            <div v-show="sideTab === 'preview'" class="swc-te__pane">
              <SegmentedControl
                v-model="previewMode"
                :options="previewModes"
                :legend="t('templates.previewMode')"
              />
              <p class="swc-hint">{{ t('templates.previewHint') }}</p>
              <p v-if="previewFailure" class="swc-callout is-danger" role="alert">
                {{ previewFailure.message }}
              </p>
              <ul
                v-if="previewErrors.length > 0"
                class="swc-callout is-danger swc-te__perrs"
                role="alert"
              >
                <li v-for="(e, i) in previewErrors" :key="i">
                  <strong>{{ t(`templates.kinds.${e.kind}`) }}:</strong> {{ e.message }}
                </li>
              </ul>
              <PreviewFrame
                :html="preview?.html ?? ''"
                :css="preview?.css ?? ''"
                :title="t('templates.previewFrameTitle', { name: name || t('templates.untitled') })"
                :loading="previewLoading"
                :height="620"
              />
            </div>
            <div v-show="sideTab === 'reference'" class="swc-te__pane">
              <p v-if="mode === 'visual'" class="swc-callout is-info">
                {{ t('templates.referenceVisual') }}
              </p>
              <PlaceholderPanel
                :placeholders="placeholders"
                :disabled="mode !== 'html'"
                @insert="insertPlaceholder"
              />
            </div>
          </SwcCard>
        </div>
      </div>
    </DataState>

    <ConfirmDialog
      v-model="toHtmlOpen"
      :danger="false"
      :title="t('templates.toHtmlTitle')"
      :message="t('templates.toHtmlText')"
      :target="name || t('templates.untitled')"
      :consequences="[t('templates.toHtmlC1'), t('templates.toHtmlC2')]"
      :confirm-label="t('templates.switchToHtml')"
      @confirm="switchToHtml"
    >
      <q-btn
        v-if="stored"
        outline
        no-caps
        color="primary"
        icon="r_content_copy"
        :loading="duplicating"
        :label="t('templates.duplicateAsHtml')"
        @click="duplicateAsHtml"
      />
    </ConfirmDialog>
    <ConfirmDialog
      v-model="toVisualBlockedOpen"
      :danger="false"
      :title="t('templates.toVisualTitle')"
      :message="t('templates.toVisualText')"
      :target="name || t('templates.untitled')"
      :consequences="[t('templates.toVisualC1')]"
      :confirm-label="t('templates.newVisualTemplate')"
      @confirm="newVisual"
    />
    <ConfirmDialog
      v-model="deleteOpen"
      :title="t('templates.deleteTitle')"
      :target="stored ? `${stored.name} (${t(`templates.kinds.${stored.kind}`)})` : undefined"
      :consequences="[t('templates.deleteConsequence1'), t('common.irreversible')]"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      :error="deleteError"
      @confirm="doDelete"
    />
  </div>
</template>

<style scoped>
.swc-te__meta {
  display: flex;
  align-items: flex-end;
  gap: 16px;
  flex-wrap: wrap;
}
.swc-te__name {
  flex: 1 1 260px;
}
.swc-te__kind {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.swc-te__note {
  margin-top: 8px;
}
.swc-te {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}
@media (max-width: 1180px) {
  .swc-te {
    grid-template-columns: minmax(0, 1fr);
  }
}
.swc-te__side {
  position: sticky;
  top: calc(var(--wp-admin--admin-bar--height, 32px) + 16px);
}
.swc-te__pane {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding-top: 12px;
}
.swc-te__root {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: center;
}
.swc-te__root > :first-child {
  flex: 1 1 220px;
}
.swc-te__generated {
  white-space: pre-wrap;
  font-size: 12px;
  padding: 10px;
  border-radius: 8px;
  background: var(--swc-surface-2);
  max-height: 320px;
  overflow: auto;
}
.swc-te__validation {
  margin-top: 16px;
  padding-top: 12px;
  border-top: 1px solid var(--swc-border);
}
.swc-te__errors {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-top: 8px;
}
.swc-te__err {
  display: flex;
  gap: 10px;
  align-items: baseline;
  width: 100%;
  padding: 6px 10px;
  border: 0;
  border-radius: 8px;
  background: var(--swc-danger-bg);
  color: var(--swc-danger-text);
  text-align: left;
  font: inherit;
  font-size: 13px;
  cursor: pointer;
}
.swc-te__err:disabled {
  cursor: default;
}
.swc-te__err code {
  margin-left: auto;
  background: none;
}
.swc-te__perrs {
  flex-direction: column;
}
</style>
