<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwcCard from '@/components/ui/SwcCard.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useFormat } from '@/composables/useFormat';
import type { LogEntry } from '@/types/api';

const { t } = useI18n();
const fmt = useFormat();
const api = useApi();
const page = ref(1);
const level = ref<string>('');
const logs = useAsyncData(() =>
  api.system.logs({ page: page.value, per_page: 25, level: level.value || undefined }),
);
watch(page, () => void logs.load());
watch(level, () => {
  if (page.value !== 1) page.value = 1;
  else void logs.load();
});
const levelOptions = computed(() => [
  { value: '', label: t('logs.allLevels') },
  ...(['error', 'warning', 'info', 'debug'] as const).map((l) => ({
    value: l,
    label: t(`status.level.${l}`),
  })),
]);
const open = ref<Record<number, boolean>>({});
function hasContext(e: LogEntry): boolean {
  return Array.isArray(e.context) ? e.context.length > 0 : Object.keys(e.context ?? {}).length > 0;
}
</script>

<template>
  <SwcCard
    :title="t('logs.title')"
    :subtitle="t('logs.subtitle')"
    icon="r_receipt_long"
    :padding="false"
  >
    <template #actions>
      <q-select
        v-model="level"
        outlined
        dense
        emit-value
        map-options
        options-dense
        class="swc-logs__level"
        :options="levelOptions"
        :label="t('logs.level')"
      />
      <q-btn
        flat
        round
        dense
        icon="r_refresh"
        :aria-label="t('common.refresh')"
        @click="logs.load()"
      />
    </template>
    <DataState
      :loading="logs.loading.value"
      :error="logs.error.value"
      :empty="(logs.data.value?.items.length ?? 0) === 0"
      compact-error
      @retry="logs.load()"
    >
      <template #empty>
        <EmptyState compact illustration="search" :title="t('logs.empty')" />
      </template>
      <div class="swc-table-wrap">
        <table class="swc-table">
          <thead>
            <tr>
              <th scope="col">{{ t('audit.time') }}</th>
              <th scope="col">{{ t('logs.level') }}</th>
              <th scope="col">{{ t('logs.code') }}</th>
              <th scope="col">{{ t('logs.message') }}</th>
              <th scope="col">{{ t('sync.source') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="e in logs.data.value?.items ?? []" :key="e.id">
              <td :data-label="t('audit.time')">
                <span :title="fmt.dateTime(e.created_at)">{{ fmt.dateTime(e.created_at) }}</span>
              </td>
              <td :data-label="t('logs.level')">
                <StatusChip domain="level" :status="e.level" dense />
              </td>
              <td :data-label="t('logs.code')">
                <code>{{ e.code }}</code>
              </td>
              <td :data-label="t('logs.message')">
                {{ e.message }}
                <template v-if="hasContext(e)">
                  <q-btn
                    flat
                    dense
                    no-caps
                    size="sm"
                    :label="open[e.id] ? t('common.hide') : t('logs.context')"
                    :aria-expanded="!!open[e.id]"
                    @click="open = { ...open, [e.id]: !open[e.id] }"
                  />
                  <pre v-if="open[e.id]" class="swc-ctx swc-mono">{{
                    JSON.stringify(e.context, null, 2)
                  }}</pre>
                </template>
              </td>
              <td :data-label="t('sync.source')">
                <router-link v-if="e.source_id" :to="`/sources/${e.source_id}`"
                  >#{{ e.source_id }}</router-link
                >
                <span v-else class="swc-muted">–</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar
        v-if="logs.data.value"
        v-model:page="page"
        :pages="logs.data.value.pages"
        :total="logs.data.value.total"
      />
    </DataState>
  </SwcCard>
</template>

<style scoped>
.swc-logs__level {
  min-width: 160px;
}
.swc-ctx {
  white-space: pre-wrap;
  font-size: 12px;
  max-width: 480px;
  margin-top: 4px;
  text-align: left;
}
</style>
