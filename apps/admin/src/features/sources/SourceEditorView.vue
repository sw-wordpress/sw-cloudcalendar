<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useApi } from '@/api';
import type { ApiError } from '@/api/client';
import ProviderIcon from '@/components/ProviderIcon.vue';
import TimezoneSelect from '@/components/TimezoneSelect.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwcCard from '@/components/ui/SwcCard.vue';
import { toApiError, useAsyncData } from '@/composables/useAsyncData';
import { useFieldErrors } from '@/composables/useFieldErrors';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import { useLookupsStore } from '@/stores/lookups';
import RunsTable from '@/features/sync/RunsTable.vue';
import type {
  Connection,
  EditableSourceStatus,
  ProviderCalendar,
  Source,
  SourceSettings,
  Visibility,
} from '@/types/api';
import {
  ICS_EXTERNAL_ID,
  attachmentTotals,
  connectionIsPrivate,
  defaultSourceSettings,
  requiredConfirmations,
  writableSettings,
} from './sourceForm';
import type { ConfirmationFlag } from './sourceForm';

const props = defineProps<{ id?: string }>();
const { t } = useI18n();
const fmt = useFormat();
const api = useApi();
const notify = useNotify();
const router = useRouter();
const lookups = useLookupsStore();

const sourceId = computed(() => (props.id ? Number(props.id) : null));
const isEdit = computed(() => sourceId.value !== null);

/* ---------- loading ---------- */
const source = ref<Source | null>(null);
const loader = useAsyncData(
  async () => {
    const [meta, connections] = await Promise.all([
      lookups.loadMeta(),
      lookups.loadConnections(true),
    ]);
    const s = sourceId.value ? await api.sources.get(sourceId.value) : null;
    return { meta, connections, source: s };
  },
  { immediate: false },
);
const meta = computed(() => loader.data.value?.meta ?? lookups.meta);
const connections = computed<Connection[]>(() => loader.data.value?.connections ?? []);

/* ---------- form state ---------- */
const connectionId = ref<number | null>(null);
const externalId = ref('');
const name = ref('');
const color = ref('#3366cc');
const timezone = ref('UTC');
const visibility = ref<Visibility>('authenticated');
const roles = ref<string[]>([]);
const status = ref<EditableSourceStatus | 'error'>('draft');
const settings = ref<SourceSettings>(defaultSourceSettings(null));
const dirty = ref(false);

function fill(s: Source | null): void {
  connectionId.value = s?.connection_id ?? null;
  externalId.value = s?.external_id ?? '';
  name.value = s?.name ?? '';
  color.value = s?.color ?? '#3366cc';
  timezone.value = s?.timezone ?? meta.value?.site_timezone ?? 'UTC';
  visibility.value = s?.visibility ?? 'authenticated';
  roles.value = [...(s?.roles ?? [])];
  status.value = s?.status ?? 'draft';
  settings.value = s
    ? {
        ...defaultSourceSettings(meta.value),
        ...s.settings,
        attachments: {
          ...defaultSourceSettings(meta.value).attachments,
          ...s.settings.attachments,
        },
      }
    : defaultSourceSettings(meta.value);
  void nextTick(() => (dirty.value = false));
}

async function load(): Promise<void> {
  const result = await loader.load();
  if (!result) return;
  source.value = result.source;
  fill(result.source);
  if (!result.source) {
    const first = result.connections[0];
    if (result.connections.length === 1 && first) connectionId.value = first.id;
  }
}
onMounted(load);
watch(sourceId, load);
watch([name, color, timezone, visibility, roles, status, settings], () => (dirty.value = true), {
  deep: true,
});

/* ---------- step 1: connection + calendar (create only) ---------- */
const connection = computed(
  () =>
    connections.value.find((c) => c.id === (source.value?.connection_id ?? connectionId.value)) ??
    null,
);
const calendars = ref<ProviderCalendar[]>([]);
const calendarsLoading = ref(false);
const calendarsError = ref<ApiError | null>(null);
const manualId = ref(false);

const canList = computed(
  () => !!connection.value?.capabilities.list_calendars && connection.value.provider !== 'ics',
);

watch(connectionId, async (id) => {
  if (isEdit.value) return;
  calendars.value = [];
  calendarsError.value = null;
  externalId.value = '';
  manualId.value = false;
  const c = connections.value.find((x) => x.id === id);
  if (!c) return;
  if (c.provider === 'ics') {
    externalId.value = ICS_EXTERNAL_ID;
    if (name.value === '') name.value = c.name;
    return;
  }
  if (!canList.value) {
    manualId.value = true;
    externalId.value = c.settings.test_calendar_id ?? '';
    return;
  }
  calendarsLoading.value = true;
  try {
    calendars.value = (await api.connections.calendars(c.id)).items;
  } catch (e) {
    calendarsError.value = toApiError(e);
    manualId.value = true;
  } finally {
    calendarsLoading.value = false;
  }
});

function pickCalendar(cal: ProviderCalendar): void {
  externalId.value = cal.external_id;
  if (name.value === '' || calendars.value.some((c) => c.name === name.value)) {
    name.value = cal.name;
  }
  if (cal.color && /^#[0-9a-f]{6}$/i.test(cal.color)) color.value = cal.color.toLowerCase();
  if (cal.timezone && (meta.value?.timezones ?? []).includes(cal.timezone)) {
    timezone.value = cal.timezone;
  }
}

/* ---------- options ---------- */
const roleOptions = computed(() =>
  Object.entries(meta.value?.roles ?? {}).map(([value, label]) => ({ value, label })),
);
const mimeOptions = computed(() =>
  (meta.value?.supported_mime_types ?? settings.value.attachments.allowed_mime_types).map((m) => ({
    value: m,
    label: m,
  })),
);
const eventTypes = computed(() => meta.value?.event_types ?? []);
const maxWindow = computed(() => meta.value?.max_window_days ?? 1095);
const storageSafe = computed(() => source.value?.storage_safe ?? meta.value?.storage_safe ?? true);
const privateAtProvider = computed(
  () => source.value?.requires_confirmation ?? connectionIsPrivate(connection.value),
);
const statusOptions = computed(() => {
  const list: Array<EditableSourceStatus | 'error'> = ['draft', 'active', 'paused', 'disabled'];
  if (source.value?.status === 'error') list.unshift('error');
  return list.map((s) => ({
    value: s,
    label: t(`status.source.${s}`),
    disable: s === 'error',
  }));
});
const visibilityOptions = (['public', 'authenticated', 'roles'] as Visibility[]).map((v) => ({
  value: v,
}));
const neverVerifiable = computed(
  () =>
    connection.value?.provider === 'ics' ||
    connection.value?.provider === 'microsoft' ||
    connection.value?.capabilities.public_attachment_verification === false,
);

function toggleEventType(type: string, excluded: boolean): void {
  const set = new Set(settings.value.excluded_event_types);
  if (excluded) set.add(type);
  else set.delete(type);
  settings.value.excluded_event_types = [...set];
}
function resetMimes(): void {
  settings.value.attachments.allowed_mime_types = [...(meta.value?.default_mime_types ?? [])];
}

/* ---------- saving + confirmations ---------- */
const saving = ref(false);
const formError = ref<ApiError | null>(null);
const fields = useFieldErrors(formError);
const pendingFlags = ref<ConfirmationFlag[]>([]);
const grantedFlags = ref<ConfirmationFlag[]>([]);
const confirmOpen = ref(false);
const currentFlag = computed<ConfirmationFlag | null>(() => pendingFlags.value[0] ?? null);

const required = (v: unknown): true | string =>
  (typeof v === 'string' ? v.trim() !== '' : v !== null && v !== undefined) || t('common.required');
const intIn =
  (min: number, max: number) =>
  (v: unknown): true | string => {
    const n = Number(v);
    return (Number.isInteger(n) && n >= min && n <= max) || t('common.range', { min, max });
  };

async function save(flags: ConfirmationFlag[] = []): Promise<void> {
  saving.value = true;
  formError.value = null;
  const common = {
    name: name.value.trim(),
    color: color.value.toLowerCase(),
    timezone: timezone.value,
    visibility: visibility.value,
    roles: visibility.value === 'roles' ? roles.value : [],
    settings: writableSettings(settings.value),
    ...(flags.includes('confirm_publication') ? { confirm_publication: true as const } : {}),
    ...(flags.includes('confirm_private_attachments')
      ? { confirm_private_attachments: true as const }
      : {}),
  };
  try {
    let saved: Source;
    if (source.value) {
      const statusChanged = status.value !== source.value.status && status.value !== 'error';
      saved = await api.sources.update(source.value.id, {
        ...common,
        ...(statusChanged ? { status: status.value as EditableSourceStatus } : {}),
      });
    } else {
      if (connectionId.value === null) throw new Error(t('sources.pickConnectionFirst'));
      saved = await api.sources.create({
        ...common,
        connection_id: connectionId.value,
        external_id: externalId.value.trim(),
      });
    }
    grantedFlags.value = [];
    lookups.invalidate();
    notify.success(isEdit.value ? t('sources.saved') : t('sources.created'));
    if (!isEdit.value) {
      await router.replace(`/sources/${saved.id}`);
      return;
    }
    source.value = saved;
    fill(saved);
    void runs.load();
  } catch (e) {
    const err = toApiError(e);
    const needed = requiredConfirmations(err);
    if (needed) {
      pendingFlags.value = needed.filter((f) => !flags.includes(f));
      grantedFlags.value = [...flags];
      if (pendingFlags.value.length > 0) confirmOpen.value = true;
      return;
    }
    if (err.status === 400 || err.status === 409) {
      formError.value = err;
      notify.error(e);
    } else notify.error(e);
  } finally {
    saving.value = false;
  }
}

function onConfirm(): void {
  const flag = currentFlag.value;
  if (!flag) return;
  grantedFlags.value = [...grantedFlags.value, flag];
  pendingFlags.value = pendingFlags.value.slice(1);
  if (pendingFlags.value.length > 0) {
    // Next confirmation: close and reopen so the dialog state (checkbox, typed name) resets.
    confirmOpen.value = false;
    window.setTimeout(() => (confirmOpen.value = true), 150);
    return;
  }
  confirmOpen.value = false;
  void save(grantedFlags.value);
}

/* ---------- actions (edit) ---------- */
const actionBusy = ref<'sync' | 'full' | null>(null);
async function sync(full: boolean): Promise<void> {
  if (!source.value) return;
  actionBusy.value = full ? 'full' : 'sync';
  try {
    await api.sources.sync(source.value.id, full);
    notify.success(
      t(full ? 'sources.fullSyncQueued' : 'sources.syncQueued', { name: source.value.name }),
    );
    source.value = await api.sources.get(source.value.id);
  } catch (e) {
    notify.error(e);
  } finally {
    actionBusy.value = null;
    fullOpen.value = false;
  }
}
const fullOpen = ref(false);
const deleteOpen = ref(false);
const deleting = ref(false);
async function doDelete(): Promise<void> {
  if (!source.value) return;
  deleting.value = true;
  try {
    await api.sources.remove(source.value.id);
    notify.success(t('sources.deleted'));
    lookups.invalidate();
    deleteOpen.value = false;
    await router.push('/sources');
  } catch (e) {
    notify.error(e);
  } finally {
    deleting.value = false;
  }
}

/* ---------- runs ---------- */
const runsPage = ref(1);
const runs = useAsyncData(
  () =>
    sourceId.value
      ? api.sources.runs(sourceId.value, { page: runsPage.value, per_page: 10 })
      : Promise.resolve(null),
  { immediate: false },
);
watch(
  sourceId,
  (id) => {
    runsPage.value = 1;
    if (id) void runs.load();
  },
  { immediate: true },
);
watch(runsPage, () => void runs.load());

const totals = computed(() => attachmentTotals(source.value?.attachments));
const confirmation = computed(() => source.value?.settings.publication_confirmation ?? null);
const fileConfirmation = computed(
  () => source.value?.settings.attachments.publish_private_attachments_confirmation ?? null,
);
</script>

<template>
  <div>
    <PageHeader
      :title="isEdit ? source?.name || t('sources.editTitle') : t('sources.createTitle')"
      :subtitle="isEdit ? t('sources.editSubtitle') : t('sources.createSubtitle')"
      back-to="/sources"
      :back-label="t('nav.sources')"
    >
      <template v-if="source">
        <q-btn
          flat
          no-caps
          icon="r_sync"
          :label="t('sources.syncNow')"
          :loading="actionBusy === 'sync'"
          @click="sync(false)"
        />
        <q-btn
          flat
          no-caps
          icon="r_restart_alt"
          :label="t('sources.fullResync')"
          :loading="actionBusy === 'full'"
          @click="fullOpen = true"
        />
        <q-btn
          flat
          no-caps
          color="negative"
          icon="r_delete"
          :label="t('common.delete')"
          @click="deleteOpen = true"
        />
      </template>
    </PageHeader>

    <DataState
      :loading="loader.loading.value"
      :error="loader.error.value"
      :empty="!loader.data.value"
      variant="block"
      @retry="load"
    >
      <template v-if="!isEdit && connections.length === 0">
        <SwcCard>
          <EmptyState
            illustration="connections"
            :title="t('sources.noConnectionsTitle')"
            :text="t('sources.noConnectionsText')"
          >
            <q-btn
              unelevated
              no-caps
              color="primary"
              icon="r_add_link"
              to="/connections"
              :label="t('connections.add')"
            />
          </EmptyState>
        </SwcCard>
      </template>
      <div v-else class="swc-se">
        <q-form class="swc-stack" greedy @submit="save()">
          <!-- status strip (edit) -->
          <SwcCard v-if="source" :padding="true">
            <div class="swc-row swc-se__strip">
              <ProviderIcon :provider="source.provider" :size="32" />
              <StatusChip domain="source" :status="source.status" />
              <StatusChip domain="visibility" :status="source.visibility" />
              <StatusChip
                v-if="source.syncing || source.sync_queued"
                kind="running"
                icon="r_sync"
                :label="source.syncing ? t('sources.syncing') : t('sources.queued')"
              />
              <span class="swc-muted">
                {{ t('sources.lastSync') }}:
                {{
                  source.last_success_at ? fmt.relative(source.last_success_at) : t('common.never')
                }}
              </span>
              <span class="swc-muted">
                {{ t('sources.nextRun') }}:
                {{ source.next_attempt_at ? fmt.relative(source.next_attempt_at) : '–' }}
              </span>
              <span class="swc-muted">
                {{ t('sources.events') }}: {{ fmt.number(source.occurrence_count) }}
              </span>
              <code v-if="source.last_error_code" class="swc-se__err">
                {{ source.last_error_code }}
                <template v-if="source.failures"
                  >· {{ t('sources.failures', { n: source.failures }, source.failures) }}</template
                >
              </code>
            </div>
          </SwcCard>

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

          <!-- calendar selection -->
          <SwcCard :title="t('sources.sectionCalendar')" icon="r_event">
            <template v-if="!isEdit">
              <q-select
                v-model="connectionId"
                outlined
                dense
                emit-value
                map-options
                :options="
                  connections.map((c) => ({
                    value: c.id,
                    label: `${c.name} (${t(`providers.${c.provider}`)})`,
                  }))
                "
                :label="t('sources.connection')"
                :rules="[required]"
                :error="!!fields.field('connection_id')"
                :error-message="fields.field('connection_id')"
              />
              <p
                v-if="connection && connection.auth_mode === 'oauth' && !connection.authorized"
                class="swc-callout is-warning"
              >
                {{ t('sources.connectionNotAuthorized') }}
                <router-link to="/connections">{{ t('nav.connections') }}</router-link>
              </p>
              <template v-if="connection">
                <p v-if="connection.provider === 'ics'" class="swc-hint">
                  {{ t('sources.icsExternalHint') }}
                </p>
                <template v-else-if="canList && !manualId">
                  <q-linear-progress v-if="calendarsLoading" indeterminate />
                  <p v-else-if="calendars.length === 0" class="swc-hint">
                    {{ t('sources.noCalendars') }}
                  </p>
                  <div
                    v-else
                    role="radiogroup"
                    :aria-label="t('sources.pickCalendar')"
                    class="swc-se__cals"
                  >
                    <label
                      v-for="cal in calendars"
                      :key="cal.external_id"
                      class="swc-se__cal"
                      :class="{ 'is-on': externalId === cal.external_id }"
                    >
                      <input
                        type="radio"
                        class="swc-sr-only"
                        name="swc-calendar"
                        :value="cal.external_id"
                        :checked="externalId === cal.external_id"
                        @change="pickCalendar(cal)"
                      />
                      <span
                        class="swc-swatch"
                        :style="{ background: cal.color ?? 'var(--swc-muted)' }"
                        aria-hidden="true"
                      />
                      <span class="swc-se__cal-name">{{ cal.name }}</span>
                      <span class="swc-muted swc-se__cal-meta">{{ cal.timezone ?? '' }}</span>
                      <StatusChip
                        v-if="cal.public_at_provider !== null"
                        :kind="cal.public_at_provider ? 'warning' : 'neutral'"
                        :icon="cal.public_at_provider ? 'r_public' : 'r_lock'"
                        :label="
                          cal.public_at_provider
                            ? t('sources.publicAtProvider')
                            : t('sources.privateAtProvider')
                        "
                        dense
                      />
                    </label>
                  </div>
                  <q-btn
                    flat
                    dense
                    no-caps
                    :label="t('sources.enterIdManually')"
                    @click="manualId = true"
                  />
                </template>
                <template v-if="connection.provider !== 'ics' && (manualId || !canList)">
                  <p v-if="calendarsError" class="swc-callout is-warning" role="status">
                    {{ t('sources.calendarsFailed') }} {{ calendarsError.message }}
                  </p>
                  <p v-else-if="!canList" class="swc-hint">{{ t('sources.listingUnsupported') }}</p>
                  <q-input
                    v-model="externalId"
                    outlined
                    dense
                    autocomplete="off"
                    :label="t('sources.externalId')"
                    :hint="t(`sources.externalIdHint.${connection.provider}`)"
                    :rules="[required]"
                    :error="!!fields.field('external_id')"
                    :error-message="fields.field('external_id')"
                  />
                  <q-btn
                    v-if="canList"
                    flat
                    dense
                    no-caps
                    :label="t('sources.backToList')"
                    @click="manualId = false"
                  />
                </template>
              </template>
            </template>
            <dl v-else-if="source" class="swc-dl">
              <div>
                <dt>{{ t('sources.connection') }}</dt>
                <dd>{{ connection?.name ?? `#${source.connection_id}` }}</dd>
              </div>
              <div>
                <dt>{{ t('sources.externalId') }}</dt>
                <dd class="swc-mono">{{ source.external_id }}</dd>
              </div>
              <div>
                <dt>{{ t('sources.revision') }}</dt>
                <dd class="swc-num">{{ source.revision }}</dd>
              </div>
            </dl>
          </SwcCard>

          <!-- general -->
          <SwcCard :title="t('sources.sectionGeneral')" icon="r_tune">
            <div class="swc-form-grid">
              <q-input
                v-model="name"
                outlined
                dense
                maxlength="190"
                :label="t('common.name')"
                :rules="[required]"
                :error="!!fields.field('name')"
                :error-message="fields.field('name')"
              />
              <q-input
                v-model="color"
                outlined
                dense
                :label="t('sources.color')"
                :rules="[(v: string) => /^#[0-9a-fA-F]{6}$/.test(v) || t('validation.invalid')]"
                :error="!!fields.field('color')"
                :error-message="fields.field('color')"
              >
                <template #prepend>
                  <input
                    v-model="color"
                    type="color"
                    class="swc-se__color"
                    :aria-label="t('sources.pickColor')"
                  />
                </template>
              </q-input>
              <TimezoneSelect
                v-model="timezone"
                :timezones="meta?.timezones ?? []"
                :label="t('sources.timezone')"
                :hint="t('sources.timezoneHint')"
                :error="fields.field('timezone')"
              />
              <q-select
                v-if="isEdit"
                v-model="status"
                outlined
                dense
                emit-value
                map-options
                :options="statusOptions"
                :label="t('common.status')"
                :hint="t('sources.statusHint')"
                :error="!!fields.field('status')"
                :error-message="fields.field('status')"
              />
            </div>
            <p v-if="!isEdit" class="swc-callout is-info">{{ t('sources.draftHint') }}</p>
          </SwcCard>

          <!-- visibility -->
          <SwcCard :title="t('sources.visibility')" icon="r_visibility">
            <div role="radiogroup" :aria-label="t('sources.visibility')" class="swc-se__vis">
              <label
                v-for="opt in visibilityOptions"
                :key="opt.value"
                class="swc-se__vis-opt"
                :class="{ 'is-on': visibility === opt.value }"
              >
                <input v-model="visibility" type="radio" class="swc-sr-only" :value="opt.value" />
                <q-icon
                  :name="
                    opt.value === 'public'
                      ? 'r_public'
                      : opt.value === 'roles'
                        ? 'r_group'
                        : 'r_lock'
                  "
                  size="22px"
                  aria-hidden="true"
                />
                <span>
                  <strong>{{ t(`status.visibility.${opt.value}`) }}</strong>
                  <small>{{ t(`sources.visibilityHints.${opt.value}`) }}</small>
                </span>
              </label>
            </div>
            <q-select
              v-if="visibility === 'roles'"
              v-model="roles"
              outlined
              dense
              multiple
              use-chips
              emit-value
              map-options
              :options="roleOptions"
              :label="t('sources.roles')"
              :rules="[(v: string[]) => v.length > 0 || t('common.required')]"
              :error="!!fields.field('roles')"
              :error-message="fields.field('roles')"
            />
            <div
              v-if="visibility === 'public'"
              class="swc-callout is-warning swc-se__warn"
              role="note"
            >
              <q-icon name="r_warning" size="22px" aria-hidden="true" />
              <div>
                <strong>{{ t('sources.publicWarningTitle') }}</strong>
                <p>{{ t('sources.publicWarning') }}</p>
                <p v-if="privateAtProvider">{{ t('sources.publicConfirmHint') }}</p>
                <p v-if="confirmation">
                  {{
                    t('sources.confirmedAt', {
                      time: fmt.dateTime(confirmation.confirmed_at),
                      user: confirmation.user_id,
                      revision: confirmation.source_revision,
                    })
                  }}
                </p>
              </div>
            </div>
          </SwcCard>

          <!-- sync -->
          <SwcCard :title="t('sources.sectionSync')" icon="r_sync">
            <q-toggle v-model="settings.sync_enabled" :label="t('sources.syncEnabled')" />
            <div class="swc-form-grid">
              <q-input
                v-model.number="settings.sync_interval_hours"
                outlined
                dense
                type="number"
                min="1"
                max="48"
                :label="t('sources.interval')"
                :hint="t('sources.intervalHint')"
                :suffix="t('common.hoursShort')"
                :rules="[intIn(1, 48)]"
                :error="!!fields.field('settings.sync_interval_hours')"
                :error-message="fields.field('settings.sync_interval_hours')"
              />
              <q-input
                v-model.number="settings.past_days"
                outlined
                dense
                type="number"
                min="0"
                :max="maxWindow"
                :label="t('sources.pastDays')"
                :suffix="t('common.daysShort')"
                :rules="[intIn(0, maxWindow)]"
                :error="!!fields.field('settings.past_days')"
                :error-message="fields.field('settings.past_days')"
              />
              <q-input
                v-model.number="settings.future_days"
                outlined
                dense
                type="number"
                min="1"
                :max="maxWindow"
                :label="t('sources.futureDays')"
                :suffix="t('common.daysShort')"
                :rules="[intIn(1, maxWindow)]"
                :error="!!fields.field('settings.future_days')"
                :error-message="fields.field('settings.future_days')"
              />
            </div>
            <p class="swc-hint">{{ t('sources.windowHint', { max: maxWindow }) }}</p>
            <q-toggle
              v-model="settings.exclude_private_events"
              :label="t('sources.excludePrivate')"
            />
            <fieldset v-if="eventTypes.length > 0" class="swc-se__types">
              <legend>{{ t('sources.excludedTypes') }}</legend>
              <q-checkbox
                v-for="type in eventTypes"
                :key="type"
                :model-value="settings.excluded_event_types.includes(type)"
                :label="t(`sources.eventTypes.${type}`)"
                @update:model-value="(v: boolean) => toggleEventType(type, v)"
              />
              <p class="swc-hint">{{ t('sources.excludedTypesHint') }}</p>
            </fieldset>
          </SwcCard>

          <!-- attachments -->
          <SwcCard :title="t('sources.sectionAttachments')" icon="r_attach_file">
            <div v-if="!storageSafe" class="swc-callout is-danger" role="alert">
              <q-icon name="r_gpp_bad" size="20px" aria-hidden="true" />
              <span>{{ t('sources.storageUnsafe') }}</span>
            </div>
            <q-toggle
              v-model="settings.attachments.sync_attachments"
              :disable="!storageSafe && !settings.attachments.sync_attachments"
              :label="t('sources.syncAttachments')"
            />
            <p
              v-if="fields.field('settings.attachments.sync_attachments')"
              class="swc-callout is-danger"
            >
              {{ fields.field('settings.attachments.sync_attachments') }}
            </p>
            <template v-if="settings.attachments.sync_attachments">
              <q-toggle
                v-model="settings.attachments.public_only"
                :label="t('sources.publicOnly')"
              />
              <div class="swc-callout is-info">
                <q-icon name="r_info" size="20px" aria-hidden="true" />
                <div>
                  <p>{{ t('sources.publicOnlyExplain') }}</p>
                  <p v-if="neverVerifiable">{{ t('sources.neverVerifiable') }}</p>
                </div>
              </div>
              <div
                v-if="!settings.attachments.public_only && visibility === 'public'"
                class="swc-callout is-warning"
              >
                <q-icon name="r_warning" size="20px" aria-hidden="true" />
                <span>{{ t('sources.privateFilesPublicWarning') }}</span>
              </div>
              <p v-if="fileConfirmation" class="swc-hint">
                {{
                  t('sources.filesConfirmedAt', {
                    time: fmt.dateTime(fileConfirmation.confirmed_at),
                    user: fileConfirmation.user_id,
                  })
                }}
              </p>
              <div class="swc-form-grid">
                <q-input
                  v-model.number="settings.attachments.max_file_size_mb"
                  outlined
                  dense
                  type="number"
                  min="1"
                  max="200"
                  suffix="MB"
                  :label="t('sources.maxFileSize')"
                  :rules="[intIn(1, 200)]"
                  :error="!!fields.field('settings.attachments.max_file_size_mb')"
                  :error-message="fields.field('settings.attachments.max_file_size_mb')"
                />
                <q-input
                  v-model.number="settings.attachments.quota_mb"
                  outlined
                  dense
                  type="number"
                  min="1"
                  suffix="MB"
                  :label="t('sources.quota')"
                  :rules="[intIn(1, 100000)]"
                  :error="!!fields.field('settings.attachments.quota_mb')"
                  :error-message="fields.field('settings.attachments.quota_mb')"
                />
                <q-input
                  v-model.number="settings.attachments.refresh_hours"
                  outlined
                  dense
                  type="number"
                  min="1"
                  max="720"
                  :suffix="t('common.hoursShort')"
                  :label="t('sources.refreshHours')"
                  :rules="[intIn(1, 720)]"
                  :error="!!fields.field('settings.attachments.refresh_hours')"
                  :error-message="fields.field('settings.attachments.refresh_hours')"
                />
              </div>
              <q-select
                v-model="settings.attachments.allowed_mime_types"
                outlined
                dense
                multiple
                use-chips
                emit-value
                map-options
                :options="mimeOptions"
                :label="t('sources.mimeTypes')"
                :error="!!fields.field('settings.attachments.allowed_mime_types')"
                :error-message="fields.field('settings.attachments.allowed_mime_types')"
              >
                <template #after>
                  <q-btn
                    flat
                    dense
                    no-caps
                    :label="t('common.resetDefaults')"
                    @click="resetMimes"
                  />
                </template>
              </q-select>
              <div class="swc-row">
                <q-toggle
                  v-model="settings.attachments.generate_image_previews"
                  :label="t('sources.previews')"
                />
                <q-toggle
                  v-model="settings.attachments.show_images"
                  :label="t('sources.showImages')"
                />
                <q-toggle
                  v-model="settings.attachments.show_file_list"
                  :label="t('sources.showFileList')"
                />
              </div>
              <dl v-if="source" class="swc-dl">
                <div>
                  <dt>{{ t('sources.storedFiles') }}</dt>
                  <dd class="swc-num">{{ totals.stored }} / {{ totals.total }}</dd>
                </div>
                <div>
                  <dt>{{ t('sources.storedBytes') }}</dt>
                  <dd>{{ fmt.bytes(source.attachments.bytes) }}</dd>
                </div>
              </dl>
              <ul
                v-if="source && source.attachments.counts.length > 0"
                class="swc-row swc-se__counts"
              >
                <li
                  v-for="row in source.attachments.counts"
                  :key="`${row.sync_state}-${row.publication_state}`"
                >
                  <StatusChip
                    domain="publication"
                    :status="row.publication_state"
                    :label="`${row.sync_state} · ${row.publication_state}: ${row.files}`"
                    dense
                  />
                </li>
              </ul>
              <div v-if="source && source.attachments.last_errors.length > 0">
                <p class="swc-eyebrow">{{ t('sources.attachmentErrors') }}</p>
                <ul class="swc-se__errs">
                  <li v-for="err in source.attachments.last_errors" :key="err">
                    <code>{{ err }}</code>
                  </li>
                </ul>
              </div>
            </template>
          </SwcCard>

          <div class="swc-se__footer">
            <q-badge
              v-if="dirty && isEdit"
              color="warning"
              text-color="dark"
              :label="t('common.unsaved')"
            />
            <q-btn flat no-caps to="/sources" :label="t('common.cancel')" />
            <q-btn
              unelevated
              no-caps
              color="primary"
              type="submit"
              icon="r_save"
              :loading="saving"
              :disable="!isEdit && (!connectionId || externalId.trim() === '')"
              :label="isEdit ? t('common.save') : t('sources.createDraft')"
            />
          </div>
        </q-form>

        <SwcCard v-if="source" :title="t('sources.runHistory')" icon="r_history" :padding="false">
          <template #actions>
            <q-btn
              flat
              round
              dense
              icon="r_refresh"
              :aria-label="t('common.refresh')"
              @click="runs.load()"
            />
          </template>
          <DataState
            :loading="runs.loading.value"
            :error="runs.error.value"
            :empty="(runs.data.value?.items.length ?? 0) === 0"
            compact-error
            @retry="runs.load()"
          >
            <template #empty>
              <EmptyState compact illustration="sync" :title="t('sync.noRuns')" />
            </template>
            <RunsTable :runs="runs.data.value?.items ?? []" />
            <PaginationBar
              v-if="runs.data.value"
              v-model:page="runsPage"
              :pages="runs.data.value.pages"
              :total="runs.data.value.total"
            />
          </DataState>
        </SwcCard>
      </div>
    </DataState>

    <ConfirmDialog
      v-model="confirmOpen"
      :danger="true"
      :title="
        currentFlag === 'confirm_publication'
          ? t('sources.confirmPublicationTitle')
          : t('sources.confirmFilesTitle')
      "
      :message="
        currentFlag === 'confirm_publication'
          ? t('sources.confirmPublicationText')
          : t('sources.confirmFilesText')
      "
      :target="name"
      :consequences="
        currentFlag === 'confirm_publication'
          ? [
              t('sources.confirmPublicationC1'),
              t('sources.confirmPublicationC2'),
              t('sources.confirmRecorded'),
            ]
          : [t('sources.confirmFilesC1'), t('sources.confirmFilesC2'), t('sources.confirmRecorded')]
      "
      :checkbox-label="
        currentFlag === 'confirm_publication'
          ? t('sources.confirmPublicationCheck')
          : t('sources.confirmFilesCheck')
      "
      :require-text="name.trim()"
      :confirm-label="t('sources.confirmAndSave')"
      :loading="saving"
      @confirm="onConfirm"
    />
    <ConfirmDialog
      v-model="fullOpen"
      :danger="false"
      :title="t('sources.fullResyncTitle')"
      :target="source?.name"
      :consequences="[t('sources.fullResyncC1'), t('sources.fullResyncC2')]"
      :confirm-label="t('sources.fullResync')"
      :loading="actionBusy === 'full'"
      @confirm="sync(true)"
    />
    <ConfirmDialog
      v-model="deleteOpen"
      :title="t('sources.deleteTitle')"
      :target="source?.name"
      :consequences="[
        t('sources.deleteConsequence1'),
        t('sources.deleteConsequence2'),
        t('common.irreversible'),
      ]"
      :require-text="source?.name"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      @confirm="doDelete"
    />
  </div>
</template>

<style scoped>
.swc-se {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.swc-se :deep(.swc-card__body) {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.swc-se__strip {
  gap: 10px 16px;
  font-size: 14px;
}
.swc-se__err {
  color: var(--swc-danger-text);
}
.swc-se__cals {
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-height: 340px;
  overflow-y: auto;
}
.swc-se__cal {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border: 1px solid var(--swc-border);
  border-radius: var(--swc-radius-sm);
  cursor: pointer;
  margin: 0;
  font-weight: 400;
}
.swc-se__cal:focus-within {
  outline: 3px solid var(--swc-focus);
  outline-offset: 2px;
}
.swc-se__cal.is-on {
  border-color: var(--swc-primary);
  background: var(--swc-primary-soft);
}
.swc-se__cal-name {
  flex: 1;
  min-width: 0;
  font-weight: 600;
}
.swc-se__cal-meta {
  font-size: 13px;
}
.swc-se__color {
  width: 26px;
  height: 26px;
  padding: 0;
  border: 0;
  background: none;
  cursor: pointer;
  min-height: 0;
}
.swc-se__vis {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
}
.swc-se__vis-opt {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  padding: 12px;
  border: 1px solid var(--swc-border);
  border-radius: var(--swc-radius-sm);
  cursor: pointer;
  margin: 0;
  font-weight: 400;
}
.swc-se__vis-opt:focus-within {
  outline: 3px solid var(--swc-focus);
  outline-offset: 2px;
}
.swc-se__vis-opt.is-on {
  border-color: var(--swc-primary);
  background: var(--swc-primary-soft);
}
.swc-se__vis-opt span {
  display: flex;
  flex-direction: column;
}
.swc-se__vis-opt small {
  color: var(--swc-muted);
  font-size: 13px;
}
.swc-se__warn p + p {
  margin-top: 6px;
}
.swc-se__types {
  border: 1px solid var(--swc-border);
  border-radius: var(--swc-radius-sm);
  padding: 8px 12px;
  margin: 0;
}
.swc-se__types legend {
  font-size: 13px;
  font-weight: 600;
  padding: 0 4px;
}
.swc-se__counts {
  gap: 6px;
}
.swc-se__errs {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 13px;
}
.swc-se__footer {
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
