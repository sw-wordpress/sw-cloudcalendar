<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import DataState from '@/components/ui/DataState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwcCard from '@/components/ui/SwcCard.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import type { StorageCheck } from '@/types/api';
import { attachmentTotals } from '@/features/sources/sourceForm';

const { t } = useI18n();
const fmt = useFormat();
const api = useApi();
const notify = useNotify();

const diag = useAsyncData(() => api.system.diagnostics());
const d = computed(() => diag.data.value);
const checking = ref(false);
const check = ref<StorageCheck | null>(null);

const environment = computed(() => {
  const e = d.value?.environment;
  if (!e) return [];
  return (['sodium', 'fileinfo', 'gd', 'imagick', 'intl', 'curl'] as const).map((key) => ({
    key,
    ok: e[key],
  }));
});
const jobs = computed<Record<string, number>>(() => {
  const raw = d.value?.cron.jobs;
  return raw && !Array.isArray(raw) ? raw : {};
});
const hitRate = computed(() => {
  const c = d.value?.cache;
  if (!c || c.hits + c.misses === 0) return null;
  return c.hits / (c.hits + c.misses);
});

async function storageCheck(): Promise<void> {
  checking.value = true;
  try {
    check.value = await api.system.storageCheck();
    notify[check.value.safe ? 'success' : 'warning'](
      check.value.safe ? t('diagnostics.storageSafe') : t('diagnostics.storageUnsafe'),
    );
    void diag.load();
  } catch (e) {
    notify.error(e);
  } finally {
    checking.value = false;
  }
}

/** Downloads the diagnostics object (contains states and codes only, no secrets). */
function exportJson(): void {
  if (!d.value) return;
  const blob = new Blob([JSON.stringify(d.value, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  const stamp = d.value.generated_at.replace(/[:]/g, '-');
  a.href = url;
  a.download = `sw-cloudcalendar-diagnostics-${stamp}.json`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
</script>

<template>
  <div>
    <PageHeader :title="t('nav.diagnostics')" :subtitle="t('diagnostics.subtitle')">
      <q-btn
        flat
        no-caps
        icon="r_refresh"
        :label="t('common.refresh')"
        :loading="diag.loading.value"
        @click="diag.load()"
      />
      <q-btn
        unelevated
        no-caps
        color="primary"
        icon="r_download"
        :disable="!d"
        :label="t('diagnostics.export')"
        @click="exportJson"
      />
    </PageHeader>

    <DataState
      :loading="diag.loading.value"
      :error="diag.error.value"
      :empty="!d"
      variant="cards"
      @retry="diag.load()"
    >
      <div v-if="d" class="swc-diag">
        <SwcCard :title="t('diagnostics.versions')" icon="r_info">
          <dl class="swc-dl">
            <div>
              <dt>{{ t('diagnostics.plugin') }}</dt>
              <dd>{{ d.versions.plugin }}</dd>
            </div>
            <div>
              <dt>{{ t('diagnostics.dbSchema') }}</dt>
              <dd class="swc-num">{{ d.versions.db_schema }}</dd>
            </div>
            <div>
              <dt>WordPress</dt>
              <dd>{{ d.versions.wordpress }}</dd>
            </div>
            <div>
              <dt>PHP</dt>
              <dd>{{ d.versions.php }}</dd>
            </div>
            <div>
              <dt>{{ t('diagnostics.generated') }}</dt>
              <dd>{{ fmt.dateTime(d.generated_at) }}</dd>
            </div>
          </dl>
        </SwcCard>

        <SwcCard :title="t('diagnostics.environment')" icon="r_memory">
          <ul class="swc-row swc-diag__chips">
            <li v-for="item in environment" :key="item.key">
              <StatusChip
                :kind="
                  item.ok
                    ? 'success'
                    : item.key === 'sodium' || item.key === 'fileinfo'
                      ? 'danger'
                      : 'neutral'
                "
                :icon="item.ok ? 'r_check_circle' : 'r_cancel'"
                :label="`${item.key}: ${item.ok ? t('diagnostics.available') : t('diagnostics.missing')}`"
                dense
              />
            </li>
          </ul>
          <p
            v-if="d.environment.missing_tables.length > 0"
            class="swc-callout is-danger"
            role="alert"
          >
            {{
              t('diagnostics.missingTables', { tables: d.environment.missing_tables.join(', ') })
            }}
          </p>
          <p v-else class="swc-hint">{{ t('diagnostics.tablesOk') }}</p>
        </SwcCard>

        <SwcCard :title="t('diagnostics.security')" icon="r_shield">
          <dl class="swc-dl">
            <div>
              <dt>{{ t('diagnostics.encryptionKey') }}</dt>
              <dd>
                <StatusChip
                  :kind="d.security.encryption_key === 'dedicated_key' ? 'success' : 'warning'"
                  :icon="d.security.encryption_key === 'dedicated_key' ? 'r_key' : 'r_warning'"
                  :label="t(`diagnostics.keys.${d.security.encryption_key}`)"
                  dense
                />
              </dd>
            </div>
            <div>
              <dt>{{ t('diagnostics.capabilityHolders') }}</dt>
              <dd>{{ d.security.capability_holders.join(', ') || '–' }}</dd>
            </div>
          </dl>
          <p
            v-if="d.security.encryption_key === 'wp_salts_fallback'"
            class="swc-callout is-warning"
          >
            {{ t('diagnostics.fallbackHint') }}
          </p>
        </SwcCard>

        <SwcCard :title="t('diagnostics.storage')" icon="r_folder_special">
          <template #actions>
            <q-btn
              flat
              dense
              no-caps
              icon="r_fact_check"
              :label="t('diagnostics.recheck')"
              :loading="checking"
              @click="storageCheck"
            />
          </template>
          <dl class="swc-dl">
            <div>
              <dt>{{ t('common.status') }}</dt>
              <dd>
                <StatusChip
                  :kind="d.storage.safe ? 'success' : 'danger'"
                  :icon="d.storage.safe ? 'r_verified_user' : 'r_gpp_bad'"
                  :label="
                    d.storage.safe ? t('diagnostics.storageSafe') : t('diagnostics.storageUnsafe')
                  "
                  dense
                />
              </dd>
            </div>
            <div>
              <dt>{{ t('diagnostics.configured') }}</dt>
              <dd>{{ t(`diagnostics.storageModes.${d.storage.configured}`) }}</dd>
            </div>
            <div>
              <dt>{{ t('diagnostics.method') }}</dt>
              <dd class="swc-mono">{{ d.storage.method || '–' }}</dd>
            </div>
            <div>
              <dt>{{ t('diagnostics.checkedAt') }}</dt>
              <dd>{{ fmt.dateTime(d.storage.checked_at) }}</dd>
            </div>
            <div>
              <dt>{{ t('diagnostics.used') }}</dt>
              <dd>
                {{ fmt.bytes(d.storage.bytes) }} / {{ fmt.bytes(d.storage.quota_mb * 1024 * 1024) }}
              </dd>
            </div>
          </dl>
          <p
            v-if="d.storage.reason"
            class="swc-callout"
            :class="d.storage.safe ? 'is-info' : 'is-danger'"
          >
            {{ d.storage.reason }}
          </p>
          <p v-if="!d.storage.safe" class="swc-hint">{{ t('diagnostics.storageHint') }}</p>
          <p v-if="check" class="swc-hint" role="status">
            {{
              t('diagnostics.lastCheck', {
                time: fmt.dateTime(check.checked_at),
                method: check.method,
              })
            }}
          </p>
        </SwcCard>

        <SwcCard :title="t('diagnostics.cron')" icon="r_schedule">
          <dl class="swc-dl">
            <div>
              <dt>{{ t('settings.cronMode') }}</dt>
              <dd>{{ t(`settings.cronModes.${d.cron.mode}`) }}</dd>
            </div>
            <div>
              <dt>DISABLE_WP_CRON</dt>
              <dd>{{ d.cron.wp_cron_disabled ? t('common.yes') : t('common.no') }}</dd>
            </div>
            <div>
              <dt>{{ t('diagnostics.lastTick') }}</dt>
              <dd>{{ fmt.dateTime(d.cron.last_tick) }}</dd>
            </div>
            <div>
              <dt>{{ t('diagnostics.nextTick') }}</dt>
              <dd>{{ fmt.dateTime(d.cron.next_tick) }}</dd>
            </div>
            <div>
              <dt>{{ t('diagnostics.lastMaintenance') }}</dt>
              <dd>{{ fmt.dateTime(d.cron.last_maintenance) }}</dd>
            </div>
            <div>
              <dt>{{ t('diagnostics.nextDaily') }}</dt>
              <dd>{{ fmt.dateTime(d.cron.next_daily) }}</dd>
            </div>
          </dl>
          <p class="swc-eyebrow swc-diag__sub">{{ t('diagnostics.jobs') }}</p>
          <ul class="swc-row swc-diag__chips">
            <li v-for="(n, state) in jobs" :key="state">
              <StatusChip
                :kind="state === 'failed' && n > 0 ? 'danger' : 'neutral'"
                :label="`${state}: ${n}`"
                dense
              />
            </li>
            <li v-if="Object.keys(jobs).length === 0" class="swc-muted">–</li>
          </ul>
          <p v-if="d.cron.mode === 'wp' && d.cron.wp_cron_disabled" class="swc-callout is-danger">
            {{ t('diagnostics.cronConflict') }}
          </p>
        </SwcCard>

        <SwcCard :title="t('diagnostics.cache')" icon="r_bolt">
          <dl class="swc-dl">
            <div>
              <dt>{{ t('settings.htmlCache') }}</dt>
              <dd>{{ d.cache.html_cache_enabled ? t('common.enabled') : t('common.disabled') }}</dd>
            </div>
            <div>
              <dt>{{ t('diagnostics.generation') }}</dt>
              <dd class="swc-num">{{ d.cache.generation }}</dd>
            </div>
            <div>
              <dt>{{ t('diagnostics.hits') }}</dt>
              <dd class="swc-num">{{ fmt.number(d.cache.hits) }}</dd>
            </div>
            <div>
              <dt>{{ t('diagnostics.misses') }}</dt>
              <dd class="swc-num">{{ fmt.number(d.cache.misses) }}</dd>
            </div>
            <div>
              <dt>{{ t('diagnostics.hitRate') }}</dt>
              <dd>{{ hitRate === null ? '–' : fmt.percent(hitRate) }}</dd>
            </div>
            <div>
              <dt>{{ t('diagnostics.objectCache') }}</dt>
              <dd>{{ d.cache.persistent_object_cache ? t('common.yes') : t('common.no') }}</dd>
            </div>
          </dl>
        </SwcCard>

        <SwcCard
          :title="t('diagnostics.connections')"
          icon="r_link"
          :padding="false"
          class="swc-diag__wide"
        >
          <div class="swc-table-wrap">
            <table class="swc-table">
              <thead>
                <tr>
                  <th scope="col">{{ t('common.name') }}</th>
                  <th scope="col">{{ t('connections.provider') }}</th>
                  <th scope="col">{{ t('connections.authMode') }}</th>
                  <th scope="col">{{ t('common.status') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="c in d.connections" :key="c.id">
                  <td :data-label="t('common.name')">{{ c.name }}</td>
                  <td :data-label="t('connections.provider')">
                    {{ t(`providers.${c.provider}`) }}
                  </td>
                  <td :data-label="t('connections.authMode')">
                    {{ t(`connections.authModes.${c.auth_mode}`) }}
                  </td>
                  <td :data-label="t('common.status')">
                    <StatusChip domain="connection" :status="c.status" dense />
                  </td>
                </tr>
                <tr v-if="d.connections.length === 0">
                  <td colspan="4" class="swc-muted">–</td>
                </tr>
              </tbody>
            </table>
          </div>
        </SwcCard>

        <SwcCard
          :title="t('diagnostics.sources')"
          icon="r_event_note"
          :padding="false"
          class="swc-diag__wide"
        >
          <div class="swc-table-wrap">
            <table class="swc-table">
              <thead>
                <tr>
                  <th scope="col">{{ t('common.name') }}</th>
                  <th scope="col">{{ t('common.status') }}</th>
                  <th scope="col">{{ t('sources.lastSync') }}</th>
                  <th scope="col">{{ t('sources.nextRun') }}</th>
                  <th scope="col">{{ t('diagnostics.cursor') }}</th>
                  <th scope="col">{{ t('sources.events') }}</th>
                  <th scope="col">{{ t('sources.files') }}</th>
                  <th scope="col">{{ t('sources.lastError') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in d.sources" :key="s.id">
                  <td :data-label="t('common.name')">
                    <router-link :to="`/sources/${s.id}`">{{ s.name }}</router-link>
                  </td>
                  <td :data-label="t('common.status')">
                    <span class="swc-row swc-diag__chips">
                      <StatusChip domain="source" :status="s.status" dense />
                      <StatusChip domain="visibility" :status="s.visibility" dense />
                    </span>
                  </td>
                  <td :data-label="t('sources.lastSync')">{{ fmt.dateTime(s.last_success_at) }}</td>
                  <td :data-label="t('sources.nextRun')">{{ fmt.dateTime(s.next_attempt_at) }}</td>
                  <td :data-label="t('diagnostics.cursor')">
                    {{ t(`diagnostics.cursors.${s.cursor}`) }}
                  </td>
                  <td :data-label="t('sources.events')" class="swc-num">
                    {{ fmt.number(s.occurrences) }}
                  </td>
                  <td :data-label="t('sources.files')" class="swc-num">
                    {{ attachmentTotals(s.attachments).stored }} /
                    {{ attachmentTotals(s.attachments).total }} ·
                    {{ fmt.bytes(s.attachments.bytes) }}
                  </td>
                  <td :data-label="t('sources.lastError')">
                    <code v-if="s.last_error_code">{{ s.last_error_code }}</code>
                    <span v-else class="swc-muted">–</span>
                    <span v-if="s.failures > 0" class="swc-muted">
                      ({{ t('sources.failures', { n: s.failures }, s.failures) }})</span
                    >
                    <ul v-if="s.attachments.last_errors.length > 0" class="swc-diag__errs">
                      <li v-for="e in s.attachments.last_errors" :key="e">
                        <code>{{ e }}</code>
                      </li>
                    </ul>
                  </td>
                </tr>
                <tr v-if="d.sources.length === 0">
                  <td colspan="8" class="swc-muted">–</td>
                </tr>
              </tbody>
            </table>
          </div>
        </SwcCard>

        <SwcCard
          :title="t('dashboard.recentErrors')"
          icon="r_report"
          :padding="false"
          class="swc-diag__wide"
        >
          <div class="swc-table-wrap">
            <table class="swc-table">
              <thead>
                <tr>
                  <th scope="col">{{ t('audit.time') }}</th>
                  <th scope="col">{{ t('logs.code') }}</th>
                  <th scope="col">{{ t('logs.message') }}</th>
                  <th scope="col">{{ t('sync.source') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="e in d.recent_errors" :key="e.id">
                  <td :data-label="t('audit.time')">{{ fmt.dateTime(e.created_at) }}</td>
                  <td :data-label="t('logs.code')">
                    <code>{{ e.code }}</code>
                  </td>
                  <td :data-label="t('logs.message')">{{ e.message }}</td>
                  <td :data-label="t('sync.source')">
                    {{ e.source_id ? `#${e.source_id}` : '–' }}
                  </td>
                </tr>
                <tr v-if="d.recent_errors.length === 0">
                  <td colspan="4" class="swc-muted">{{ t('dashboard.noErrors') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </SwcCard>
      </div>
    </DataState>
  </div>
</template>

<style scoped>
.swc-diag {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 380px), 1fr));
  gap: 16px;
  align-items: start;
}
.swc-diag__wide {
  grid-column: 1 / -1;
}
.swc-diag :deep(.swc-card__body) {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.swc-diag__chips {
  gap: 6px;
}
.swc-diag__sub {
  margin-top: 4px;
}
.swc-diag__errs {
  margin-top: 4px;
  font-size: 12px;
}
</style>
