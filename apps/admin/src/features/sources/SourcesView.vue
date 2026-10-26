<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import ProviderIcon from '@/components/ProviderIcon.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwcCard from '@/components/ui/SwcCard.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import { useLookupsStore } from '@/stores/lookups';
import type { Source } from '@/types/api';
import { attachmentTotals } from './sourceForm';

const { t } = useI18n();
const fmt = useFormat();
const api = useApi();
const notify = useNotify();
const lookups = useLookupsStore();

const list = useAsyncData(() => api.sources.list());
const items = computed(() => list.data.value?.items ?? []);
const syncing = ref<number | null>(null);

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
      <SwcCard :padding="false">
        <div class="swc-table-wrap">
          <table class="swc-table">
            <thead>
              <tr>
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
                    s.last_success_at ? fmt.relative(s.last_success_at) : t('common.never')
                  }}</span>
                </td>
                <td :data-label="t('sources.nextRun')">
                  {{ s.next_attempt_at ? fmt.relative(s.next_attempt_at) : '–' }}
                </td>
                <td :data-label="t('sources.events')" class="swc-num">
                  {{ fmt.number(s.occurrence_count) }}
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
                    flat
                    round
                    dense
                    icon="r_sync"
                    :loading="syncing === s.id"
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
