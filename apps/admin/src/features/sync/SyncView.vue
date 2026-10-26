<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import StatTile from '@/components/ui/StatTile.vue';
import SwcCard from '@/components/ui/SwcCard.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import { useLookupsStore } from '@/stores/lookups';
import RunsTable from './RunsTable.vue';

const REFRESH_MS = 10_000;
const JOB_STATES = ['queued', 'running', 'done', 'failed'] as const;

const { t } = useI18n();
const fmt = useFormat();
const api = useApi();
const notify = useNotify();
const lookups = useLookupsStore();

const page = ref(1);
const runs = useAsyncData(() => api.system.runs({ page: page.value, per_page: 25 }));
const diag = useAsyncData(() => api.system.diagnostics());
watch(page, () => void runs.load());

const sourceNames = computed<Record<number, string>>(() =>
  Object.fromEntries(lookups.sources.map((s) => [s.id, s.name])),
);
const jobs = computed<Record<string, number>>(() => {
  const raw = diag.data.value?.cron.jobs;
  return raw && !Array.isArray(raw) ? raw : {};
});
const otherJobStates = computed(() =>
  Object.keys(jobs.value).filter((k) => !(JOB_STATES as readonly string[]).includes(k)),
);

const scheduling = ref(false);
async function queueDue(): Promise<void> {
  scheduling.value = true;
  try {
    const { queued } = await api.system.schedule();
    notify.success(t('sync.queuedDue', { n: queued }, queued));
    void refresh();
  } catch (e) {
    notify.error(e);
  } finally {
    scheduling.value = false;
  }
}

/* Auto refresh every 10 s while the tab is visible. */
const autoRefresh = ref(true);
const lastRefresh = ref<string | null>(null);
let interval: number | undefined;
async function refresh(): Promise<void> {
  await Promise.all([runs.load(), diag.load()]);
  lastRefresh.value = new Date().toISOString();
}
function tick(): void {
  if (autoRefresh.value && document.visibilityState === 'visible') void refresh();
}
function onVisibility(): void {
  if (document.visibilityState === 'visible') tick();
}
onMounted(() => {
  void lookups.loadSources().catch(() => undefined);
  lastRefresh.value = new Date().toISOString();
  interval = window.setInterval(tick, REFRESH_MS);
  document.addEventListener('visibilitychange', onVisibility);
});
onBeforeUnmount(() => {
  window.clearInterval(interval);
  document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
  <div>
    <PageHeader :title="t('nav.sync')" :subtitle="t('sync.subtitle')">
      <q-toggle v-model="autoRefresh" :label="t('sync.autoRefresh')" />
      <q-btn flat no-caps icon="r_refresh" :label="t('common.refresh')" @click="refresh" />
      <q-btn
        unelevated
        no-caps
        color="primary"
        icon="r_playlist_add"
        :label="t('sync.queueDue')"
        :loading="scheduling"
        @click="queueDue"
      />
    </PageHeader>

    <div class="swc-grid swc-sync-stats">
      <StatTile
        v-for="state in JOB_STATES"
        :key="state"
        :label="t(`sync.jobs.${state}`)"
        :value="fmt.number(jobs[state] ?? 0)"
        :loading="diag.loading.value && !diag.data.value"
        :icon="
          state === 'failed'
            ? 'r_error'
            : state === 'running'
              ? 'r_sync'
              : state === 'done'
                ? 'r_check_circle'
                : 'r_hourglass_empty'
        "
        :tone="state === 'failed' && (jobs[state] ?? 0) > 0 ? 'danger' : 'default'"
      />
    </div>
    <p v-if="otherJobStates.length > 0" class="swc-hint">
      <span v-for="s in otherJobStates" :key="s">{{ s }}: {{ jobs[s] }} </span>
    </p>
    <p class="swc-hint swc-sync-note" aria-live="off">
      {{ t('sync.jobsHint') }}
      <template v-if="lastRefresh">
        · {{ t('sync.lastRefresh', { time: fmt.time(lastRefresh) }) }}</template
      >
    </p>

    <SwcCard :title="t('sync.runsTitle')" icon="r_history" :padding="false">
      <DataState
        :loading="runs.loading.value"
        :error="runs.error.value"
        :empty="(runs.data.value?.items.length ?? 0) === 0"
        @retry="runs.load()"
      >
        <template #empty>
          <EmptyState illustration="sync" :title="t('sync.noRuns')" :text="t('sync.noRunsText')" />
        </template>
        <RunsTable :runs="runs.data.value?.items ?? []" :source-names="sourceNames" show-source />
        <PaginationBar
          v-if="runs.data.value"
          v-model:page="page"
          :pages="runs.data.value.pages"
          :total="runs.data.value.total"
        />
      </DataState>
    </SwcCard>
  </div>
</template>

<style scoped>
.swc-sync-stats {
  margin-bottom: 8px;
}
.swc-sync-note {
  margin-bottom: 16px;
}
</style>
