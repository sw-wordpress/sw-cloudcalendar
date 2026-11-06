<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import ProviderIcon from '@/components/ProviderIcon.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwcCard from '@/components/ui/SwcCard.vue';
import { toApiError, useAsyncData } from '@/composables/useAsyncData';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import { useLookupsStore } from '@/stores/lookups';
import type { Source, SourceUpdateInput } from '@/types/api';
import type { ConfirmationFlag } from './sourceForm';
import { attachmentTotals, requiredConfirmations } from './sourceForm';

const { t } = useI18n();
const fmt = useFormat();
const api = useApi();
const notify = useNotify();
const lookups = useLookupsStore();

const list = useAsyncData(() => api.sources.list());
const items = computed(() => list.data.value?.items ?? []);
const syncing = ref<number | null>(null);

type BulkAction = '' | 'active' | 'paused' | 'public' | 'authenticated';
const selected = ref<number[]>([]);
const action = ref<BulkAction>('');
const bulkBusy = ref(false);
const publicationOpen = ref(false);
const confirmationFlags = ref<ConfirmationFlag[]>([]);
const grantedFlags = ref<ConfirmationFlag[]>([]);
const bulkError = ref('');
const locked = computed(() => bulkBusy.value || publicationOpen.value);
const selectedItems = computed(() => items.value.filter((s) => selected.value.includes(s.id)));
const allSelected = computed(
  () => items.value.length > 0 && selectedItems.value.length === items.value.length,
);
watch(items, () => {
  selected.value = selected.value.filter((id) => items.value.some((s) => s.id === id));
});

async function applyBulk(flags: ConfirmationFlag[] = []): Promise<void> {
  if (bulkBusy.value || !action.value || selectedItems.value.length === 0) return;
  bulkBusy.value = true;
  bulkError.value = '';
  const patch: SourceUpdateInput =
    action.value === 'active' || action.value === 'paused'
      ? { status: action.value }
      : { visibility: action.value, roles: [] };
  try {
    for (const source of selectedItems.value) {
      try {
        await api.sources.update(source.id, {
          ...patch,
          ...(flags.includes('confirm_publication') ? { confirm_publication: true } : {}),
          ...(flags.includes('confirm_private_attachments')
            ? { confirm_private_attachments: true }
            : {}),
        });
        selected.value = selected.value.filter((id) => id !== source.id);
        lookups.invalidate();
      } catch (e) {
        const error = toApiError(e);
        const needed = requiredConfirmations(error)?.filter((flag) => !flags.includes(flag));
        if (needed?.length) {
          confirmationFlags.value = needed;
          grantedFlags.value = [...flags];
          publicationOpen.value = true;
        } else {
          bulkError.value = `${source.name}: ${error.message}`;
          notify.error(e);
        }
        return;
      }
    }
    notify.success(t('sources.bulkUpdated'));
    action.value = '';
  } finally {
    await list.load();
    bulkBusy.value = false;
  }
}

function confirmBulk(): void {
  const flag = confirmationFlags.value[0];
  if (!flag) return;
  publicationOpen.value = false;
  void applyBulk([...grantedFlags.value, flag]);
}

const toDelete = ref<Source | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);

async function syncNow(s: Source, full = false): Promise<void> {
  syncing.value = s.id;
  try {
    await api.sources.sync(s.id, full);
    notify.success(t(full ? 'sources.fullSyncQueued' : 'sources.syncQueued', { name: s.name }));
    void list.load();
  } catch (e) {
    notify.error(e);
  } finally {
    syncing.value = null;
  }
}

function askDelete(s: Source): void {
  toDelete.value = s;
  deleteOpen.value = true;
}
async function doDelete(): Promise<void> {
  if (!toDelete.value) return;
  deleting.value = true;
  try {
    await api.sources.remove(toDelete.value.id);
    notify.success(t('sources.deleted'));
    deleteOpen.value = false;
    lookups.invalidate();
    await list.load();
  } catch (e) {
    notify.error(e);
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('nav.sources')" :subtitle="t('sources.subtitle')">
      <q-btn
        flat
        no-caps
        icon="r_refresh"
        :label="t('common.refresh')"
        :loading="list.loading.value"
        :disable="locked"
        @click="list.load()"
      />
      <q-btn
        unelevated
        no-caps
        color="primary"
        icon="r_add"
        to="/sources/new"
        :label="t('sources.add')"
      />
    </PageHeader>

    <DataState
      :loading="list.loading.value"
      :error="list.error.value"
      :empty="items.length === 0"
      @retry="list.load()"
    >
      <template #empty>
        <SwcCard>
          <EmptyState
            illustration="calendar"
            :title="t('sources.emptyTitle')"
            :text="t('sources.emptyText')"
          >
            <q-btn
              unelevated
              no-caps
              color="primary"
              icon="r_add"
              to="/sources/new"
              :label="t('sources.add')"
            />
          </EmptyState>
        </SwcCard>
      </template>
      <div class="swc-row swc-src-bulk">
        <label>
          <span class="swc-sr-only">{{ t('sources.bulkAction') }}</span>
          <select v-model="action" :disabled="locked" class="swc-src-select">
            <option value="">{{ t('sources.bulkAction') }}</option>
            <option value="active">{{ t('sources.bulkActivate') }}</option>
            <option value="paused">{{ t('sources.bulkPause') }}</option>
            <optgroup :label="t('sources.visibility')">
              <option value="public">{{ t('sources.bulkPublic') }}</option>
              <option value="authenticated">{{ t('sources.bulkAuthenticated') }}</option>
            </optgroup>
          </select>
        </label>
        <q-btn
          unelevated
          no-caps
          color="primary"
          :label="t('sources.bulkApply')"
          :loading="bulkBusy"
          :disable="locked || !action || selectedItems.length === 0 || list.loading.value"
          @click="applyBulk()"
        />
        <span role="status">{{ t('sources.selectedSources', { n: selectedItems.length }) }}</span>
      </div>
      <p v-if="bulkError" class="swc-callout is-danger" role="alert">{{ bulkError }}</p>
      <SwcCard :padding="false">
        <div class="swc-table-wrap">
          <table class="swc-table">
            <thead>
              <tr>
                <th scope="col">
                  <input
                    type="checkbox"
                    :checked="allSelected"
                    :indeterminate="selectedItems.length > 0 && !allSelected"
                    :disabled="locked"
                    :aria-label="t('sources.selectAllSources')"
                    @change="selected = allSelected ? [] : items.map((s) => s.id)"
                  />
                </th>
                <th scope="col">{{ t('common.name') }}</th>
                <th scope="col">{{ t('sources.visibility') }}</th>
                <th scope="col">{{ t('common.status') }}</th>
                <th scope="col">{{ t('sources.lastSync') }}</th>
                <th scope="col">{{ t('sources.nextRun') }}</th>
                <th scope="col">{{ t('sources.events') }}</th>
                <th scope="col">{{ t('sources.files') }}</th>
                <th scope="col">
                  <span class="swc-sr-only">{{ t('common.actions') }}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in items" :key="s.id">
                <td :data-label="t('sources.selection')">
                  <input
                    v-model="selected"
                    type="checkbox"
                    :value="s.id"
                    :disabled="locked"
                    :aria-label="t('sources.selectSource', { name: s.name })"
                  />
                </td>
                <td :data-label="t('common.name')">
                  <span class="swc-src-name">
                    <span class="swc-swatch" :style="{ background: s.color }" aria-hidden="true" />
                    <ProviderIcon :provider="s.provider" :size="26" />
                    <router-link :to="`/sources/${s.id}`" class="swc-truncate">{{
                      s.name
                    }}</router-link>
                  </span>
                </td>
                <td :data-label="t('sources.visibility')">
                  <StatusChip domain="visibility" :status="s.visibility" dense />
                </td>
                <td :data-label="t('common.status')">
                  <span class="swc-row swc-src-status">
                    <StatusChip domain="source" :status="s.status" dense />
                    <StatusChip
                      v-if="s.syncing || s.sync_queued"
                      kind="running"
                      icon="r_sync"
                      :label="s.syncing ? t('sources.syncing') : t('sources.queued')"
                      dense
                    />
                  </span>
                  <code v-if="s.last_error_code" class="swc-src-err">{{ s.last_error_code }}</code>
                </td>
                <td :data-label="t('sources.lastSync')">
                  <span :title="fmt.dateTime(s.last_success_at)">{{
                    s.local
                      ? t('sources.localLive')
                      : s.last_success_at
                        ? fmt.relative(s.last_success_at)
                        : t('common.never')
                  }}</span>
                </td>
                <td :data-label="t('sources.nextRun')">
                  {{ s.next_attempt_at ? fmt.relative(s.next_attempt_at) : '–' }}
                </td>
                <td :data-label="t('sources.events')" class="swc-num">
                  {{ s.local ? t('sources.localLive') : fmt.number(s.occurrence_count) }}
                </td>
                <td :data-label="t('sources.files')" class="swc-num">
                  <template v-if="s.settings.attachments.sync_attachments">
                    {{ attachmentTotals(s.attachments).stored }} /
                    {{ attachmentTotals(s.attachments).total }}
                    <span class="swc-muted">· {{ fmt.bytes(s.attachments.bytes) }}</span>
                  </template>
                  <span v-else class="swc-muted">{{ t('sources.filesOff') }}</span>
                </td>
                <td data-label="" class="swc-src-actions">
                  <q-btn
                    v-if="!s.local"
                    flat
                    round
                    dense
                    icon="r_sync"
                    :loading="syncing === s.id"
                    :disable="locked"
                    :aria-label="t('sources.syncNowNamed', { name: s.name })"
                    @click="syncNow(s)"
                    ><q-tooltip>{{ t('sources.syncNow') }}</q-tooltip></q-btn
                  >
                  <q-btn
                    flat
                    round
                    dense
                    icon="r_edit"
                    :to="`/sources/${s.id}`"
                    :aria-label="t('common.editNamed', { name: s.name })"
                    ><q-tooltip>{{ t('common.edit') }}</q-tooltip></q-btn
                  >
                  <q-btn
                    flat
                    round
                    dense
                    color="negative"
                    icon="r_delete"
                    :aria-label="t('common.deleteNamed', { name: s.name })"
                    :disable="locked"
                    @click="askDelete(s)"
                    ><q-tooltip>{{ t('common.delete') }}</q-tooltip></q-btn
                  >
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </SwcCard>
    </DataState>

    <ConfirmDialog
      v-model="publicationOpen"
      :title="
        confirmationFlags[0] === 'confirm_publication'
          ? t('sources.confirmPublicationTitle')
          : t('sources.confirmFilesTitle')
      "
      :message="
        confirmationFlags[0] === 'confirm_publication'
          ? t('sources.confirmPublicationText')
          : t('sources.confirmFilesText')
      "
      :target="selectedItems.map((s) => s.name).join(', ')"
      :consequences="
        confirmationFlags[0] === 'confirm_publication'
          ? [
              t('sources.confirmPublicationC1'),
              t('sources.confirmPublicationC2'),
              t('sources.confirmRecorded'),
            ]
          : [t('sources.confirmFilesC1'), t('sources.confirmFilesC2'), t('sources.confirmRecorded')]
      "
      :checkbox-label="
        confirmationFlags[0] === 'confirm_publication'
          ? t('sources.confirmPublicationCheck')
          : t('sources.confirmFilesCheck')
      "
      :confirm-label="t('sources.confirmAndSave')"
      @confirm="confirmBulk"
    />
    <ConfirmDialog
      v-model="deleteOpen"
      :title="t('sources.deleteTitle')"
      :target="toDelete?.name"
      :consequences="[
        t('sources.deleteConsequence1'),
        t('sources.deleteConsequence2'),
        t('common.irreversible'),
      ]"
      :require-text="toDelete?.name"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      @confirm="doDelete"
    />
  </div>
</template>

<style scoped>
.swc-src-bulk {
  margin-bottom: 12px;
}
.swc-src-select {
  padding: 8px 12px;
  color: var(--swc-text);
  background: var(--swc-surface);
  border: 1px solid var(--swc-border);
  border-radius: var(--swc-radius-sm);
  font: inherit;
}
.swc-src-name {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  max-width: 280px;
  font-weight: 600;
}
.swc-src-status {
  gap: 6px;
}
.swc-src-err {
  display: inline-block;
  margin-top: 4px;
  color: var(--swc-danger-text);
}
.swc-src-actions {
  white-space: nowrap;
  text-align: right;
}
</style>
