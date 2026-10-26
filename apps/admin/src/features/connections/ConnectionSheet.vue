<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import CopyButton from '@/components/CopyButton.vue';
import FormSection from '@/components/ui/FormSection.vue';
import FormSheet from '@/components/ui/FormSheet.vue';
import SecretInput from '@/components/ui/SecretInput.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import ProviderSetupDialog from './ProviderSetupDialog.vue';
import { useFieldErrors } from '@/composables/useFieldErrors';
import { useFormError } from '@/composables/useFormError';
import { useNotify } from '@/composables/useNotify';
import { useLookupsStore } from '@/stores/lookups';
import type { AuthMode, Connection, ConnectionSettings, Provider, SecretKey } from '@/types/api';

const open = defineModel<boolean>({ required: true });
const props = defineProps<{ connection: Connection | null }>();
const emit = defineEmits<{ (e: 'saved', connection: Connection, created: boolean): void }>();

const { t } = useI18n();
const api = useApi();
const notify = useNotify();
const lookups = useLookupsStore();
const formError = useFormError();
const fields = useFieldErrors(formError.error);
const saving = ref(false);
const guideOpen = ref(false);

const FALLBACK_MODES: Record<Provider, AuthMode[]> = {
  google: ['oauth', 'api_key'],
  microsoft: ['oauth'],
  ics: ['none', 'http_header'],
};

const provider = ref<Provider>('ics');
const authMode = ref<AuthMode>('none');
const name = ref('');
const settings = ref<Required<ConnectionSettings>>(blankSettings());
/** Write-only secrets. Never prefilled from the server, wiped after saving and on close. */
const secrets = ref<Record<SecretKey, string>>({ client_secret: '', api_key: '', auth_header: '' });

function blankSettings(): Required<ConnectionSettings> {
  return {
    url: '',
    allow_http: false,
    max_feed_mb: 10,
    client_id: '',
    attachment_access: false,
    test_calendar_id: '',
    tenant_id: '',
  };
}
function wipeSecrets(): void {
  secrets.value = { client_secret: '', api_key: '', auth_header: '' };
}

const isEdit = computed(() => props.connection !== null);
const modes = computed<AuthMode[]>(
  () => lookups.meta?.auth_modes[provider.value] ?? FALLBACK_MODES[provider.value],
);
const redirectUri = computed(
  () => props.connection?.redirect_uri ?? lookups.meta?.redirect_uri ?? '',
);
const secretsSet = computed(() => new Set(props.connection?.secrets_set ?? []));

const providerOptions = computed(() =>
  (['google', 'microsoft', 'ics'] as Provider[]).map((p) => ({
    value: p,
    label: t(`providers.${p}`),
  })),
);
const modeOptions = computed(() =>
  modes.value.map((m) => ({ value: m, label: t(`connections.authModes.${m}`) })),
);

watch(provider, (p) => {
  if (!isEdit.value && !modes.value.includes(authMode.value)) {
    authMode.value = modes.value[0] ?? FALLBACK_MODES[p][0] ?? 'none';
  }
});

watch(open, (isOpen) => {
  formError.clear();
  wipeSecrets();
  if (!isOpen) return;
  void lookups.loadMeta().catch(() => undefined);
  const c = props.connection;
  provider.value = c?.provider ?? 'ics';
  authMode.value = c?.auth_mode ?? 'none';
  name.value = c?.name ?? '';
  settings.value = { ...blankSettings(), ...(c?.settings ?? {}) };
});

function payloadSettings(): ConnectionSettings {
  const s = settings.value;
  switch (provider.value) {
    case 'ics':
      return { url: s.url.trim(), allow_http: s.allow_http, max_feed_mb: Number(s.max_feed_mb) };
    case 'google':
      return {
        client_id: s.client_id.trim(),
        attachment_access: s.attachment_access,
        test_calendar_id: s.test_calendar_id.trim(),
      };
    case 'microsoft':
      return {
        client_id: s.client_id.trim(),
        tenant_id: s.tenant_id.trim(),
        attachment_access: s.attachment_access,
      };
  }
}

function payloadSecrets(): Partial<Record<SecretKey, string>> {
  const out: Partial<Record<SecretKey, string>> = {};
  const allowed: SecretKey[] =
    authMode.value === 'oauth'
      ? ['client_secret']
      : authMode.value === 'api_key'
        ? ['api_key']
        : authMode.value === 'http_header'
          ? ['auth_header']
          : [];
  for (const key of allowed) {
    const value = secrets.value[key].trim();
    if (value !== '') out[key] = value;
  }
  return out;
}

async function submit(): Promise<void> {
  saving.value = true;
  formError.clear();
  try {
    const body = {
      name: name.value.trim(),
      settings: payloadSettings(),
      secrets: payloadSecrets(),
    };
    const saved = props.connection
      ? await api.connections.update(props.connection.id, body)
      : await api.connections.create({
          ...body,
          provider: provider.value,
          auth_mode: authMode.value,
        });
    wipeSecrets();
    notify.success(t('connections.saved'));
    emit('saved', saved, !isEdit.value);
    open.value = false;
  } catch (e) {
    formError.handle(e);
  } finally {
    saving.value = false;
  }
}

const required = (v: unknown): true | string =>
  (typeof v === 'string' ? v.trim() !== '' : v !== null && v !== undefined) || t('common.required');
</script>

<template>
  <FormSheet
    v-model="open"
    :title="isEdit ? t('connections.editTitle') : t('connections.createTitle')"
    :subtitle="connection?.name"
    :loading="saving"
    :error="formError.error.value"
    :width="840"
    @submit="submit"
  >
    <div class="swc-stack">
      <FormSection :title="t('connections.provider')" :description="t('connections.providerHint')">
        <SegmentedControl
          v-model="provider"
          :options="providerOptions"
          :legend="t('connections.provider')"
          :disabled="isEdit"
        />
        <SegmentedControl
          v-if="modeOptions.length > 1"
          v-model="authMode"
          :options="modeOptions"
          :legend="t('connections.authMode')"
          :disabled="isEdit"
        />
        <p v-if="isEdit" class="swc-muted swc-hint">{{ t('connections.immutableHint') }}</p>
        <p class="swc-hint">{{ t(`connections.modeHints.${authMode}`) }}</p>
        <q-input
          v-model="name"
          outlined
          dense
          :label="t('common.name')"
          maxlength="190"
          :rules="[required]"
          :error="!!fields.field('name')"
          :error-message="fields.field('name')"
        />
      </FormSection>

      <!-- ICS -->
      <FormSection
        v-if="provider === 'ics'"
        :title="t('connections.ics.title')"
        :description="t('connections.ics.hint')"
      >
        <q-input
          v-model="settings.url"
          outlined
          dense
          type="url"
          inputmode="url"
          autocomplete="off"
          :label="t('connections.ics.url')"
          :hint="t('connections.ics.urlHint')"
          :rules="[required]"
          :error="!!fields.field('settings.url')"
          :error-message="fields.field('settings.url')"
        />
        <q-toggle v-model="settings.allow_http" :label="t('connections.ics.allowHttp')" />
        <p v-if="settings.allow_http" class="swc-callout is-warning">
          {{ t('connections.ics.allowHttpWarning') }}
        </p>
        <q-input
          v-model.number="settings.max_feed_mb"
          outlined
          dense
          type="number"
          min="1"
          max="50"
          :label="t('connections.ics.maxFeedMb')"
          :error="!!fields.field('settings.max_feed_mb')"
          :error-message="fields.field('settings.max_feed_mb')"
        />
        <SecretInput
          v-if="authMode === 'http_header'"
          v-model="secrets.auth_header"
          :label="t('connections.ics.authHeader')"
          :is-set="secretsSet.has('auth_header')"
          :required="!isEdit"
        />
        <p v-if="authMode === 'http_header'" class="swc-hint">
          {{ t('connections.ics.authHeaderHint') }}
        </p>
      </FormSection>

      <!-- Google / Microsoft -->
      <FormSection
        v-else
        :title="t(`connections.${provider}.title`)"
        :description="t(`connections.${provider}.hint`)"
      >
        <q-input
          v-if="provider === 'microsoft'"
          v-model="settings.tenant_id"
          outlined
          dense
          autocomplete="off"
          :label="t('connections.microsoft.tenantId')"
          :hint="t('connections.microsoft.tenantHint')"
          :rules="[required]"
          :error="!!fields.field('settings.tenant_id')"
          :error-message="fields.field('settings.tenant_id')"
        />
        <template v-if="authMode === 'oauth'">
          <q-btn
            outline
            no-caps
            color="primary"
            icon="r_menu_book"
            class="self-start"
            :label="t('connections.openGuide')"
            @click="guideOpen = true"
          />
          <q-input
            v-model="settings.client_id"
            outlined
            dense
            autocomplete="off"
            :label="t('connections.clientId')"
            :rules="[required]"
            :error="!!fields.field('settings.client_id')"
            :error-message="fields.field('settings.client_id')"
          />
          <SecretInput
            v-model="secrets.client_secret"
            :label="t('connections.clientSecret')"
            :is-set="secretsSet.has('client_secret')"
          />
          <q-toggle
            v-model="settings.attachment_access"
            :label="t('connections.attachmentAccess')"
          />
          <p class="swc-hint">{{ t(`connections.${provider}.attachmentHint`) }}</p>
          <p v-if="isEdit" class="swc-callout is-info">{{ t('connections.reauthHint') }}</p>
        </template>
        <template v-if="authMode === 'api_key'">
          <SecretInput
            v-model="secrets.api_key"
            :label="t('connections.google.apiKey')"
            :is-set="secretsSet.has('api_key')"
            :required="!isEdit"
          />
          <q-input
            v-model="settings.test_calendar_id"
            outlined
            dense
            autocomplete="off"
            :label="t('connections.google.testCalendarId')"
            :hint="t('connections.google.testCalendarHint')"
          />
          <p class="swc-callout is-info">{{ t('connections.google.apiKeyHint') }}</p>
        </template>
      </FormSection>

      <FormSection
        v-if="authMode === 'oauth'"
        :title="t('connections.redirectUri')"
        :description="t('connections.redirectUriHint')"
      >
        <div class="swc-redirect">
          <code class="swc-mono">{{ redirectUri || '–' }}</code>
          <CopyButton
            v-if="redirectUri"
            :text="redirectUri"
            :label="t('connections.copyRedirectUri')"
          />
        </div>
      </FormSection>
    </div>
    <ProviderSetupDialog
      v-if="provider !== 'ics'"
      v-model="guideOpen"
      :provider="provider"
      :redirect-uri="redirectUri"
      :attachment-access="settings.attachment_access"
    />
  </FormSheet>
</template>

<style scoped>
.swc-hint {
  font-size: 13px;
  color: var(--swc-muted);
}
.swc-redirect {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  border-radius: var(--swc-radius-sm);
  background: var(--swc-surface-2);
  border: 1px solid var(--swc-border);
}
.swc-redirect code {
  flex: 1;
  min-width: 0;
  overflow-wrap: anywhere;
  background: none;
  padding: 0;
}
</style>
