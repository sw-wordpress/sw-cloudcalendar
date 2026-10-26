<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import type { ApiError } from '@/api/client';
import CopyButton from '@/components/CopyButton.vue';
import TimezoneSelect from '@/components/TimezoneSelect.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataState from '@/components/ui/DataState.vue';
import FormSection from '@/components/ui/FormSection.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import SwcCard from '@/components/ui/SwcCard.vue';
import { toApiError, useAsyncData } from '@/composables/useAsyncData';
import { useFieldErrors } from '@/composables/useFieldErrors';
import { useNotify } from '@/composables/useNotify';
import { useLookupsStore } from '@/stores/lookups';
import type { CronMode, LogLevel, Settings } from '@/types/api';
import AuditCard from './AuditCard.vue';
import LogsCard from './LogsCard.vue';

const CLI_COMMAND = 'wp sw-cloudcalendar queue run';

const { t } = useI18n();
const api = useApi();
const notify = useNotify();
const lookups = useLookupsStore();

const loader = useAsyncData(async () => {
  const [settings, meta] = await Promise.all([api.system.settings(), lookups.loadMeta()]);
  return { settings, meta };
});
const form = ref<Settings | null>(null);
const dirty = ref(false);
watch(
  () => loader.data.value,
  async (data) => {
    if (!data) return;
    form.value = { ...data.settings };
    await nextTick();
    dirty.value = false;
  },
);
watch(form, () => (dirty.value = true), { deep: true });

const saving = ref(false);
const formError = ref<ApiError | null>(null);
const fields = useFieldErrors(formError);
const uninstallConfirmOpen = ref(false);

const cronOptions = computed(() =>
  (['wp', 'system'] as CronMode[]).map((m) => ({ value: m, label: t(`settings.cronModes.${m}`) })),
);
const levelOptions = computed(() =>
  (['error', 'warning', 'info', 'debug'] as LogLevel[]).map((l) => ({
    value: l,
    label: t(`status.level.${l}`),
  })),
);
const intIn =
  (min: number, max: number) =>
  (v: unknown): true | string => {
    const n = Number(v);
    return (Number.isInteger(n) && n >= min && n <= max) || t('common.range', { min, max });
  };

function requestSave(): void {
  if (!form.value || !loader.data.value) return;
  // Enabling data deletion on uninstall needs an explicit confirmation.
  if (form.value.delete_data_on_uninstall && !loader.data.value.settings.delete_data_on_uninstall) {
    uninstallConfirmOpen.value = true;
    return;
  }
  void save();
}

async function save(): Promise<void> {
  if (!form.value) return;
  uninstallConfirmOpen.value = false;
  saving.value = true;
  formError.value = null;
  const f = form.value;
  try {
    const saved = await api.system.updateSettings({
      html_cache: f.html_cache,
      delete_data_on_uninstall: f.delete_data_on_uninstall,
      cron_mode: f.cron_mode,
      log_level: f.log_level,
      log_retention_days: Number(f.log_retention_days),
      run_retention_days: Number(f.run_retention_days),
      global_quota_mb: Number(f.global_quota_mb),
      revoked_file_grace_hours: Number(f.revoked_file_grace_hours),
      public_rate_limit: Number(f.public_rate_limit),
      default_view_timezone: f.default_view_timezone,
    });
    if (loader.data.value) loader.data.value.settings = saved;
    form.value = { ...saved };
    await nextTick();
    dirty.value = false;
    void lookups.loadMeta(true).catch(() => undefined);
    notify.success(t('settings.saved'));
  } catch (e) {
    const err = toApiError(e);
    if (err.status === 400) formError.value = err;
    notify.error(e);
  } finally {
    saving.value = false;
  }
}

const clearing = ref(false);
async function clearCache(): Promise<void> {
  clearing.value = true;
  try {
    await api.system.clearCache('all');
    notify.success(t('settings.cacheCleared'));
  } catch (e) {
    notify.error(e);
  } finally {
    clearing.value = false;
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('nav.settings')" :subtitle="t('settings.subtitle')" />

    <DataState
      :loading="loader.loading.value"
      :error="loader.error.value"
      :empty="!form"
      variant="block"
      @retry="loader.load()"
    >
      <div v-if="form" class="swc-stack">
        <SwcCard :title="t('settings.general')" icon="r_settings">
          <q-form class="swc-stack" greedy @submit="requestSave">
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

            <FormSection
              :title="t('settings.rendering')"
              :description="t('settings.renderingHint')"
            >
              <q-toggle v-model="form.html_cache" :label="t('settings.htmlCache')" />
              <TimezoneSelect
                v-model="form.default_view_timezone"
                :timezones="loader.data.value?.meta.timezones ?? []"
                allow-empty
                :empty-label="t('settings.wpTimezone')"
                :label="t('settings.defaultTimezone')"
                :error="fields.field('default_view_timezone')"
              />
              <q-input
                v-model.number="form.public_rate_limit"
                outlined
                dense
                type="number"
                min="0"
                max="10000"
                :label="t('settings.rateLimit')"
                :hint="t('settings.rateLimitHint')"
                :rules="[intIn(0, 10000)]"
                :error="!!fields.field('public_rate_limit')"
                :error-message="fields.field('public_rate_limit')"
              />
              <div class="swc-row">
                <q-btn
                  outline
                  no-caps
                  icon="r_cleaning_services"
                  :label="t('settings.clearCache')"
                  :loading="clearing"
                  @click="clearCache"
                />
                <span class="swc-hint">{{ t('settings.clearCacheHint') }}</span>
              </div>
            </FormSection>

            <FormSection :title="t('settings.cron')" :description="t('settings.cronHint')">
              <SegmentedControl
                v-model="form.cron_mode"
                :options="cronOptions"
                :legend="t('settings.cronMode')"
              />
              <p class="swc-hint">{{ t(`settings.cronModeHints.${form.cron_mode}`) }}</p>
              <div v-if="form.cron_mode === 'system'" class="swc-cli">
                <code class="swc-mono">{{ CLI_COMMAND }}</code>
                <CopyButton :text="CLI_COMMAND" :label="t('settings.copyCommand')" />
              </div>
              <p v-if="form.cron_mode === 'system'" class="swc-hint">
                {{ t('settings.cronExample') }}
              </p>
            </FormSection>

            <FormSection :title="t('settings.storage')" :description="t('settings.storageHint')">
              <div class="swc-form-grid">
                <q-input
                  v-model.number="form.global_quota_mb"
                  outlined
                  dense
                  type="number"
                  min="1"
                  suffix="MB"
                  :label="t('settings.globalQuota')"
                  :rules="[intIn(1, 1048576)]"
                  :error="!!fields.field('global_quota_mb')"
                  :error-message="fields.field('global_quota_mb')"
                />
                <q-input
                  v-model.number="form.revoked_file_grace_hours"
                  outlined
                  dense
                  type="number"
                  min="0"
                  max="720"
                  :suffix="t('common.hoursShort')"
                  :label="t('settings.graceHours')"
                  :hint="t('settings.graceHoursHint')"
                  :rules="[intIn(0, 720)]"
                  :error="!!fields.field('revoked_file_grace_hours')"
                  :error-message="fields.field('revoked_file_grace_hours')"
                />
              </div>
            </FormSection>

            <FormSection :title="t('settings.logging')" :description="t('settings.loggingHint')">
              <div class="swc-form-grid">
                <q-select
                  v-model="form.log_level"
                  outlined
                  dense
                  emit-value
                  map-options
                  :options="levelOptions"
                  :label="t('settings.logLevel')"
                />
                <q-input
                  v-model.number="form.log_retention_days"
                  outlined
                  dense
                  type="number"
                  min="1"
                  max="3650"
                  :suffix="t('common.daysShort')"
                  :label="t('settings.logRetention')"
                  :rules="[intIn(1, 3650)]"
                  :error="!!fields.field('log_retention_days')"
                  :error-message="fields.field('log_retention_days')"
                />
                <q-input
                  v-model.number="form.run_retention_days"
                  outlined
                  dense
                  type="number"
                  min="1"
                  max="3650"
                  :suffix="t('common.daysShort')"
                  :label="t('settings.runRetention')"
                  :rules="[intIn(1, 3650)]"
                  :error="!!fields.field('run_retention_days')"
                  :error-message="fields.field('run_retention_days')"
                />
              </div>
            </FormSection>

            <FormSection
              :title="t('settings.uninstall')"
              :description="t('settings.uninstallHint')"
            >
              <q-toggle
                v-model="form.delete_data_on_uninstall"
                :label="t('settings.deleteOnUninstall')"
              />
              <div v-if="form.delete_data_on_uninstall" class="swc-callout is-danger" role="note">
                <q-icon name="r_warning" size="20px" aria-hidden="true" />
                <span>{{ t('settings.deleteOnUninstallWarning') }}</span>
              </div>
            </FormSection>

            <div class="swc-settings__footer">
              <q-badge
                v-if="dirty"
                color="warning"
                text-color="dark"
                :label="t('common.unsaved')"
              />
              <q-btn
                unelevated
                no-caps
                color="primary"
                type="submit"
                icon="r_save"
                :loading="saving"
                :label="t('common.save')"
              />
            </div>
          </q-form>
        </SwcCard>

        <AuditCard />
        <LogsCard />
      </div>
    </DataState>

    <ConfirmDialog
      v-model="uninstallConfirmOpen"
      :title="t('settings.deleteOnUninstallTitle')"
      :target="t('settings.allPluginData')"
      :consequences="[
        t('settings.uninstallC1'),
        t('settings.uninstallC2'),
        t('common.irreversible'),
      ]"
      :checkbox-label="t('settings.uninstallCheck')"
      :confirm-label="t('settings.enableAndSave')"
      :loading="saving"
      @confirm="save"
    />
  </div>
</template>

<style scoped>
.swc-cli {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  border-radius: var(--swc-radius-sm);
  background: var(--swc-surface-2);
  border: 1px solid var(--swc-border);
}
.swc-cli code {
  flex: 1;
  background: none;
  padding: 0;
}
.swc-settings__footer {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 8px;
}
</style>
