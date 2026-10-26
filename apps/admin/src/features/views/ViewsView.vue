<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import CopyButton from '@/components/CopyButton.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwcCard from '@/components/ui/SwcCard.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useNotify } from '@/composables/useNotify';
import { useLookupsStore } from '@/stores/lookups';
import type { CalendarView } from '@/types/api';

const { t } = useI18n();
const api = useApi();
const notify = useNotify();
const lookups = useLookupsStore();

const list = useAsyncData(() => api.views.list());
const items = computed(() => list.data.value?.items ?? []);

const toDelete = ref<CalendarView | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);

function askDelete(v: CalendarView): void {
  toDelete.value = v;
  deleteOpen.value = true;
}
async function doDelete(): Promise<void> {
  if (!toDelete.value) return;
  deleting.value = true;
  try {
    await api.views.remove(toDelete.value.id);
    notify.success(t('views.deleted'));
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
    <PageHeader :title="t('nav.views')" :subtitle="t('views.subtitle')">
      <q-btn
        unelevated
        no-caps
        color="primary"
        icon="r_add"
        to="/views/new"
        :label="t('views.add')"
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
            illustration="list"
            :title="t('views.emptyTitle')"
            :text="t('views.emptyText')"
          >
            <q-btn
              unelevated
              no-caps
              color="primary"
              icon="r_add"
              to="/views/new"
              :label="t('views.add')"
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
                <th scope="col">{{ t('common.status') }}</th>
                <th scope="col">{{ t('views.displayMode') }}</th>
                <th scope="col">{{ t('views.sources') }}</th>
                <th scope="col">{{ t('views.shortcode') }}</th>
                <th scope="col">
                  <span class="swc-sr-only">{{ t('common.actions') }}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="v in items" :key="v.id">
                <td :data-label="t('common.name')">
                  <router-link :to="`/views/${v.id}`" class="swc-vw-name">{{ v.name }}</router-link>
                </td>
                <td :data-label="t('common.status')">
                  <span class="swc-row swc-vw-chips">
                    <StatusChip
                      :kind="v.enabled ? 'success' : 'neutral'"
                      :icon="v.enabled ? 'r_check_circle' : 'r_block'"
                      :label="v.enabled ? t('common.enabled') : t('common.disabled')"
                      dense
                    />
                    <StatusChip
                      v-if="v.dynamic"
                      kind="info"
                      icon="r_bolt"
                      :label="t('views.dynamic')"
                      dense
                    >
                    </StatusChip>
                  </span>
                </td>
                <td :data-label="t('views.displayMode')">
                  {{ t(`views.modes.${v.display_mode}`) }}
                </td>
                <td :data-label="t('views.sources')" class="swc-num">{{ v.source_ids.length }}</td>
                <td :data-label="t('views.shortcode')">
                  <span class="swc-vw-sc">
                    <code>{{ v.shortcode }}</code>
                    <CopyButton
                      :text="v.shortcode"
                      :label="t('views.copyShortcodeNamed', { name: v.name })"
                    />
                  </span>
                </td>
                <td data-label="" class="swc-vw-actions">
                  <q-btn
                    flat
                    round
                    dense
                    icon="r_edit"
                    :to="`/views/${v.id}`"
                    :aria-label="t('common.editNamed', { name: v.name })"
                    ><q-tooltip>{{ t('common.edit') }}</q-tooltip></q-btn
                  >
                  <q-btn
                    flat
                    round
                    dense
                    color="negative"
                    icon="r_delete"
                    :aria-label="t('common.deleteNamed', { name: v.name })"
                    @click="askDelete(v)"
                    ><q-tooltip>{{ t('common.delete') }}</q-tooltip></q-btn
                  >
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </SwcCard>
      <p class="swc-hint swc-vw-note">{{ t('views.dynamicHint') }}</p>
    </DataState>

    <ConfirmDialog
      v-model="deleteOpen"
      :title="t('views.deleteTitle')"
      :target="toDelete ? `${toDelete.name} ${toDelete.shortcode}` : undefined"
      :consequences="[t('views.deleteConsequence1'), t('common.irreversible')]"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      @confirm="doDelete"
    />
  </div>
</template>

<style scoped>
.swc-vw-name {
  font-weight: 600;
}
.swc-vw-chips {
  gap: 6px;
}
.swc-vw-sc {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.swc-vw-actions {
  white-space: nowrap;
  text-align: right;
}
.swc-vw-note {
  margin-top: 12px;
}
</style>
