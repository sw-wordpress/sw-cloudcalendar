<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import DataState from '@/components/ui/DataState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwcCard from '@/components/ui/SwcCard.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useFormat } from '@/composables/useFormat';
import { healthIssues, overallHealth } from './health';
import EncryptionKeyDialog from '@/features/diagnostics/EncryptionKeyDialog.vue';

const { t } = useI18n();
const fmt = useFormat();
const api = useApi();

const diag = useAsyncData(() => api.system.diagnostics());
const keyDialog = ref(false);
const d = computed(() => diag.data.value);
const issues = computed(() => (d.value ? healthIssues(d.value) : []));
const level = computed(() => overallHealth(issues.value));

const activeSources = computed(
  () => d.value?.sources.filter((s) => s.status === 'active').length ?? 0,
);
const occurrences = computed(() => d.value?.sources.reduce((n, s) => n + s.occurrences, 0) ?? 0);
const connectedCount = computed(
  () => d.value?.connections.filter((c) => c.status === 'connected').length ?? 0,
);
const quotaPercent = computed(() => {
  if (!d.value || d.value.storage.quota_mb <= 0) return 0;
  return d.value.storage.bytes / (d.value.storage.quota_mb * 1024 * 1024);
});

const quickLinks = [
  { to: '/connections', icon: 'r_add_link', key: 'dashboard.quick.connection' },
  { to: '/sources/new', icon: 'r_event_note', key: 'dashboard.quick.source' },
  { to: '/views/new', icon: 'r_view_quilt', key: 'dashboard.quick.view' },
  { to: '/templates', icon: 'r_code', key: 'dashboard.quick.templates' },
  { to: '/sync', icon: 'r_sync', key: 'dashboard.quick.sync' },
  { to: '/diagnostics', icon: 'r_monitor_heart', key: 'dashboard.quick.diagnostics' },
] as const;

function issueText(key: string, params?: Record<string, string | number>): string {
  const n = typeof params?.n === 'number' ? params.n : 1;
  return t(`health.issues.${key}`, params ?? {}, n);
}
</script>

<template>
  <div>
    <PageHeader :title="t('nav.dashboard')" :subtitle="t('dashboard.subtitle')">
      <q-btn
        flat
        no-caps
        icon="r_refresh"
        :label="t('common.refresh')"
        :loading="diag.loading.value"
        @click="diag.load()"
      />
    </PageHeader>

    <DataState
      :loading="diag.loading.value"
      :error="diag.error.value"
      :empty="!d"
      variant="cards"
      :rows="3"
      @retry="diag.load()"
    >
      <template v-if="d">
        <SwcCard class="swc-dash-hero" :class="`is-${level}`" accent>
          <div class="swc-dash-hero__head">
            <span class="swc-dash-hero__icon" aria-hidden="true">
              <q-icon
                :name="
                  level === 'ok' ? 'r_verified' : level === 'warning' ? 'r_warning' : 'r_error'
                "
                size="30px"
              />
            </span>
            <div>
              <h2 class="swc-dash-hero__title">{{ t(`health.title.${level}`) }}</h2>
              <p class="swc-muted">
                {{ t('dashboard.generatedAt', { time: fmt.dateTime(d.generated_at) }) }}
              </p>
            </div>
            <StatusChip domain="health" :status="level" class="swc-dash-hero__chip" />
          </div>
          <ul v-if="issues.length > 0" class="swc-dash-issues">
            <li v-for="issue in issues" :key="issue.key" :class="`is-${issue.level}`">
              <q-icon
                :name="issue.level === 'error' ? 'r_error' : 'r_warning'"
                size="20px"
                aria-hidden="true"
              />
              <span class="swc-sr-only">{{ t(`health.level.${issue.level}`) }}:</span>
              <span>{{ issueText(issue.key, issue.params) }}</span>
              <q-btn
                v-if="issue.key === 'encryptionFallback'"
                flat
                dense
                no-caps
                color="primary"
                class="swc-dash-issues__link"
                :label="t('diagnostics.showSolution')"
                @click="keyDialog = true"
              />
              <router-link v-else-if="issue.to" :to="issue.to" class="swc-dash-issues__link">{{
                t('dashboard.resolve')
              }}</router-link>
            </li>
          </ul>
          <p v-else class="swc-dash-hero__ok">{{ t('health.allGood') }}</p>
        </SwcCard>

        <div class="swc-grid swc-dash-stats">
          <StatTile
            :label="t('dashboard.stats.connections')"
            :value="`${connectedCount} / ${d.connections.length}`"
            :hint="t('dashboard.stats.connectionsHint')"
            icon="r_link"
          />
          <StatTile
            :label="t('dashboard.stats.sources')"
            :value="`${activeSources} / ${d.sources.length}`"
            :hint="t('dashboard.stats.sourcesHint')"
            icon="r_event_note"
          />
          <StatTile
            :label="t('dashboard.stats.events')"
            :value="fmt.number(occurrences)"
            :hint="t('dashboard.stats.eventsHint')"
            icon="r_event"
          />
          <StatTile
            :label="t('dashboard.stats.storage')"
            :value="fmt.bytes(d.storage.bytes)"
            :hint="
              t('dashboard.stats.storageHint', {
                quota: fmt.bytes(d.storage.quota_mb * 1024 * 1024),
                percent: fmt.percent(quotaPercent),
              })
            "
            icon="r_folder_zip"
            :tone="!d.storage.safe ? 'warning' : quotaPercent > 0.9 ? 'danger' : 'default'"
          />
        </div>

        <div class="swc-dash-cols">
          <SwcCard :title="t('dashboard.sourcesTitle')" icon="r_event_note" :padding="false">
            <template #actions>
              <q-btn flat dense no-caps to="/sources" :label="t('common.showAll')" />
            </template>
            <EmptyState
              v-if="d.sources.length === 0"
              compact
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
            <table v-else class="swc-table">
              <thead>
                <tr>
                  <th scope="col">{{ t('common.name') }}</th>
                  <th scope="col">{{ t('common.status') }}</th>
                  <th scope="col">{{ t('sources.lastSync') }}</th>
                  <th scope="col">{{ t('sources.nextRun') }}</th>
                  <th scope="col">{{ t('sources.lastError') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in d.sources" :key="s.id">
                  <td :data-label="t('common.name')">
                    <router-link :to="`/sources/${s.id}`">{{ s.name }}</router-link>
                  </td>
                  <td :data-label="t('common.status')">
                    <span class="swc-row swc-dash-chips">
                      <StatusChip domain="source" :status="s.status" dense />
                      <StatusChip domain="visibility" :status="s.visibility" dense />
                    </span>
                  </td>
                  <td :data-label="t('sources.lastSync')">
                    <span :title="fmt.dateTime(s.last_success_at)">{{
                      s.last_success_at ? fmt.relative(s.last_success_at) : t('common.never')
                    }}</span>
                  </td>
                  <td :data-label="t('sources.nextRun')">
                    {{ s.next_attempt_at ? fmt.relative(s.next_attempt_at) : '–' }}
                  </td>
                  <td :data-label="t('sources.lastError')">
                    <span v-if="s.last_error_code" class="swc-mono swc-dash-err">
                      {{ s.last_error_code }}
                      <template v-if="s.failures > 0">
                        ({{ t('sources.failures', { n: s.failures }, s.failures) }})</template
                      >
                    </span>
                    <span v-else class="swc-muted">–</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </SwcCard>

          <div class="swc-stack">
            <SwcCard :title="t('dashboard.quickLinks')" icon="r_bolt">
              <ul class="swc-dash-links">
                <li v-for="link in quickLinks" :key="link.to">
                  <router-link :to="link.to" class="swc-dash-link">
                    <q-icon :name="link.icon" size="20px" aria-hidden="true" />
                    <span>{{ t(link.key) }}</span>
                    <q-icon name="r_chevron_right" size="18px" aria-hidden="true" />
                  </router-link>
                </li>
              </ul>
            </SwcCard>
            <SwcCard :title="t('dashboard.cronTitle')" icon="r_schedule">
              <dl class="swc-dl">
                <div>
                  <dt>{{ t('settings.cronMode') }}</dt>
                  <dd>{{ t(`settings.cronModes.${d.cron.mode}`) }}</dd>
                </div>
                <div>
                  <dt>{{ t('diagnostics.lastTick') }}</dt>
                  <dd :title="fmt.dateTime(d.cron.last_tick)">
                    {{ d.cron.last_tick ? fmt.relative(d.cron.last_tick) : t('common.never') }}
                  </dd>
                </div>
                <div>
                  <dt>{{ t('diagnostics.nextTick') }}</dt>
                  <dd>{{ d.cron.next_tick ? fmt.relative(d.cron.next_tick) : '–' }}</dd>
                </div>
              </dl>
            </SwcCard>
          </div>
        </div>

        <SwcCard :title="t('dashboard.recentErrors')" icon="r_report" :padding="false">
          <EmptyState
            v-if="d.recent_errors.length === 0"
            compact
            illustration="search"
            :title="t('dashboard.noErrors')"
          />
          <ul v-else class="swc-dash-errors">
            <li v-for="e in d.recent_errors.slice(0, 8)" :key="e.id">
              <span class="swc-muted swc-num" :title="fmt.dateTime(e.created_at)">{{
                fmt.relative(e.created_at)
              }}</span>
              <code>{{ e.code }}</code>
              <span>{{ e.message }}</span>
            </li>
          </ul>
        </SwcCard>
      </template>
    </DataState>
    <EncryptionKeyDialog v-model="keyDialog" />
  </div>
</template>

<style scoped>
.swc-dash-hero {
  margin-bottom: 16px;
}
.swc-dash-hero__head {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}
.swc-dash-hero__icon {
  display: grid;
  place-items: center;
  width: 52px;
  height: 52px;
  border-radius: 14px;
  color: var(--swc-success-text);
  background: var(--swc-success-bg);
}
.is-warning .swc-dash-hero__icon {
  color: var(--swc-warning-text);
  background: var(--swc-warning-bg);
}
.is-error .swc-dash-hero__icon {
  color: var(--swc-danger-text);
  background: var(--swc-danger-bg);
}
.swc-dash-hero__title {
  font-size: 20px;
  font-weight: 700;
}
.swc-dash-hero__chip {
  margin-left: auto;
}
.swc-dash-hero__ok {
  margin-top: 12px;
  color: var(--swc-muted);
}
.swc-dash-issues {
  margin-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.swc-dash-issues li {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: var(--swc-radius-sm);
  background: var(--swc-warning-bg);
  color: var(--swc-warning-text);
  flex-wrap: wrap;
}
.swc-dash-issues li.is-error {
  background: var(--swc-danger-bg);
  color: var(--swc-danger-text);
}
.swc-dash-issues__link {
  margin-left: auto;
  font-weight: 600;
  color: inherit !important;
  text-decoration: underline !important;
}
.swc-dash-stats {
  margin-bottom: 16px;
}
.swc-dash-cols {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
  gap: 16px;
  margin-bottom: 16px;
  align-items: start;
}
@media (max-width: 1100px) {
  .swc-dash-cols {
    grid-template-columns: minmax(0, 1fr);
  }
}
.swc-dash-chips {
  gap: 6px;
}
.swc-dash-err {
  color: var(--swc-danger-text);
}
.swc-dash-links {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.swc-dash-link {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-radius: var(--swc-radius-sm);
  color: var(--swc-text) !important;
  text-decoration: none !important;
  font-weight: 600;
}
.swc-dash-link span {
  flex: 1;
}
.swc-dash-link:hover {
  background: var(--swc-surface-3);
}
.swc-dash-errors {
  display: flex;
  flex-direction: column;
}
.swc-dash-errors li {
  display: grid;
  grid-template-columns: 120px auto minmax(0, 1fr);
  gap: 12px;
  padding: 10px 16px;
  border-bottom: 1px solid var(--swc-border);
  font-size: 14px;
  align-items: baseline;
}
.swc-dash-errors li:last-child {
  border-bottom: 0;
}
@media (max-width: 760px) {
  .swc-dash-errors li {
    grid-template-columns: minmax(0, 1fr);
    gap: 4px;
  }
}
</style>
