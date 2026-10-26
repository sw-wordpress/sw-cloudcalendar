<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import SwcCard from '@/components/ui/SwcCard.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useFormat } from '@/composables/useFormat';
import type { AuditEntry } from '@/types/api';

const { t, te } = useI18n();
const fmt = useFormat();
const api = useApi();
const page = ref(1);
const audit = useAsyncData(() => api.system.audit({ page: page.value, per_page: 20 }));
watch(page, () => void audit.load());
const open = ref<Record<number, boolean>>({});

function action(a: string): string {
  return te(`audit.actions.${a}`) ? t(`audit.actions.${a}`) : a;
}
function hasContext(e: AuditEntry): boolean {
  return Array.isArray(e.context) ? e.context.length > 0 : Object.keys(e.context ?? {}).length > 0;
}
</script>

<template>
  <SwcCard
    :title="t('audit.title')"
    :subtitle="t('audit.subtitle')"
    icon="r_policy"
    :padding="false"
  >
    <template #actions>
      <q-btn
        flat
        round
        dense
        icon="r_refresh"
        :aria-label="t('common.refresh')"
        @click="audit.load()"
      />
    </template>
    <DataState
      :loading="audit.loading.value"
      :error="audit.error.value"
      :empty="(audit.data.value?.items.length ?? 0) === 0"
      compact-error
      @retry="audit.load()"
    >
      <template #empty>
        <EmptyState compact illustration="list" :title="t('audit.empty')" />
      </template>
      <div class="swc-table-wrap">
        <table class="swc-table">
          <thead>
            <tr>
              <th scope="col">{{ t('audit.time') }}</th>
              <th scope="col">{{ t('audit.user') }}</th>
              <th scope="col">{{ t('audit.action') }}</th>
              <th scope="col">{{ t('audit.object') }}</th>
              <th scope="col">{{ t('audit.details') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="e in audit.data.value?.items ?? []" :key="e.id">
              <td :data-label="t('audit.time')">
                <span :title="fmt.dateTime(e.created_at)">{{ fmt.relative(e.created_at) }}</span>
              </td>
              <td :data-label="t('audit.user')">
                {{ e.user_name ?? (e.user_id ? `#${e.user_id}` : t('audit.system')) }}
              </td>
              <td :data-label="t('audit.action')">{{ action(e.action) }}</td>
              <td :data-label="t('audit.object')">
                <span class="swc-mono"
                  >{{ e.object_type
                  }}<template v-if="e.object_id"> #{{ e.object_id }}</template></span
                >
              </td>
              <td :data-label="t('audit.details')">
                <template v-if="hasContext(e)">
                  <q-btn
                    flat
                    dense
                    no-caps
                    size="sm"
                    :label="open[e.id] ? t('common.hide') : t('common.show')"
                    :aria-expanded="!!open[e.id]"
                    @click="open = { ...open, [e.id]: !open[e.id] }"
                  />
                  <pre v-if="open[e.id]" class="swc-ctx swc-mono">{{
                    JSON.stringify(e.context, null, 2)
                  }}</pre>
                </template>
                <span v-else class="swc-muted">–</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar
        v-if="audit.data.value"
        v-model:page="page"
        :pages="audit.data.value.pages"
        :total="audit.data.value.total"
      />
    </DataState>
  </SwcCard>
</template>

<style scoped>
.swc-ctx {
  white-space: pre-wrap;
  font-size: 12px;
  max-width: 420px;
  margin-top: 4px;
  text-align: left;
}
</style>
