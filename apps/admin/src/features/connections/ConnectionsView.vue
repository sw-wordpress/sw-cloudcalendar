<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
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
import type { Connection, ConnectionTestResult } from '@/types/api';
import ConnectionSheet from './ConnectionSheet.vue';

const { t } = useI18n();
const fmt = useFormat();
const api = useApi();
const notify = useNotify();
const route = useRoute();
const router = useRouter();
const lookups = useLookupsStore();

const list = useAsyncData(() => api.connections.list());
const items = computed(() => list.data.value?.items ?? []);

const sheetOpen = ref(false);
const editing = ref<Connection | null>(null);
const busy = ref<{ id: number; action: string } | null>(null);
const results = ref<Record<number, ConnectionTestResult>>({});
const completing = ref(false);

const toDelete = ref<Connection | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);
const deleteError = ref<string | null>(null);
const toDisconnect = ref<Connection | null>(null);
const disconnectOpen = ref(false);

function isBusy(c: Connection, action: string): boolean {
  return busy.value?.id === c.id && busy.value.action === action;
}

function openCreate(): void {
  editing.value = null;
  sheetOpen.value = true;
}
function openEdit(c: Connection): void {
  editing.value = c;
  sheetOpen.value = true;
}
function onSaved(c: Connection, created: boolean): void {
  lookups.invalidate();
  void list.load();
  if (created && c.auth_mode === 'oauth') notify.info(t('connections.authorizeNext'));
}

async function authorize(c: Connection): Promise<void> {
  busy.value = { id: c.id, action: 'authorize' };
  try {
    const { url } = await api.connections.authorize(c.id);
    const parsed = new URL(url);
    if (parsed.protocol !== 'https:') throw new Error(t('connections.unsafeAuthorizeUrl'));
    window.location.assign(parsed.toString());
  } catch (e) {
    notify.error(e);
    busy.value = null;
  }
}

async function test(c: Connection): Promise<void> {
  busy.value = { id: c.id, action: 'test' };
  try {
    results.value = { ...results.value, [c.id]: await api.connections.test(c.id) };
    void list.load();
  } catch (e) {
    const err = toApiError(e);
    results.value = {
      ...results.value,
      [c.id]: { ok: false, message: err.message, error_code: err.code },
    };
  } finally {
    busy.value = null;
  }
}

function askDisconnect(c: Connection): void {
  toDisconnect.value = c;
  disconnectOpen.value = true;
}
async function doDisconnect(): Promise<void> {
  const c = toDisconnect.value;
  if (!c) return;
  busy.value = { id: c.id, action: 'disconnect' };
  try {
    await api.connections.disconnect(c.id);
    notify.success(t('connections.disconnected'));
    disconnectOpen.value = false;
    await list.load();
  } catch (e) {
    notify.error(e);
  } finally {
    busy.value = null;
  }
}

function askDelete(c: Connection): void {
  toDelete.value = c;
  deleteError.value = null;
  deleteOpen.value = true;
}
async function doDelete(): Promise<void> {
  if (!toDelete.value) return;
  deleting.value = true;
  try {
    await api.connections.remove(toDelete.value.id);
    notify.success(t('connections.deleted'));
    deleteOpen.value = false;
    lookups.invalidate();
    await list.load();
  } catch (e) {
    const err = toApiError(e);
    if (err.status === 409 || err.status === 400) deleteError.value = err.message;
    else notify.error(e);
  } finally {
    deleting.value = false;
  }
}

/**
 * OAuth return: the provider redirects to `/oauth/callback`, which sends the admin back to
 * `#/connections?oauth_state=…` (or `?oauth=denied|invalid_state`). The URL is cleaned first
 * so a reload cannot resubmit the single-use state.
 */
async function handleOAuthReturn(): Promise<void> {
  const state = typeof route.query.oauth_state === 'string' ? route.query.oauth_state : '';
  const outcome = typeof route.query.oauth === 'string' ? route.query.oauth : '';
  if (state === '' && outcome === '') return;
  await router.replace({ path: '/connections', query: {} });
  if (outcome !== '') {
    notify.error(
      new Error(
        outcome === 'denied' ? t('connections.oauthDenied') : t('connections.oauthInvalidState'),
      ),
    );
    return;
  }
  completing.value = true;
  try {
    const c = await api.connections.completeOAuth(state);
    notify.success(t('connections.oauthCompleted', { name: c.name }));
  } catch (e) {
    notify.error(e, t('connections.oauthFailed'));
  } finally {
    completing.value = false;
    void list.load();
  }
}

onMounted(() => {
  void lookups.loadMeta().catch(() => undefined);
  void handleOAuthReturn();
});
</script>

<template>
  <div>
    <PageHeader :title="t('nav.connections')" :subtitle="t('connections.subtitle')">
      <q-btn
        unelevated
        no-caps
        color="primary"
        icon="r_add"
        :label="t('connections.add')"
        @click="openCreate"
      />
    </PageHeader>

    <div v-if="completing" class="swc-callout is-info swc-oauth-progress" role="status">
      <q-spinner size="18px" /> {{ t('connections.oauthCompleting') }}
    </div>

    <DataState
      :loading="list.loading.value"
      :error="list.error.value"
      :empty="items.length === 0"
      variant="cards"
      @retry="list.load()"
    >
      <template #empty>
        <SwcCard>
          <EmptyState
            illustration="connections"
            :title="t('connections.emptyTitle')"
            :text="t('connections.emptyText')"
          >
            <q-btn
              unelevated
              no-caps
              color="primary"
              icon="r_add"
              :label="t('connections.add')"
              @click="openCreate"
            />
          </EmptyState>
        </SwcCard>
      </template>
      <ul class="swc-grid swc-grid--wide">
        <li v-for="c in items" :key="c.id">
          <SwcCard tag="article" :aria-label="c.name" accent>
            <div class="swc-cc__head">
              <ProviderIcon :provider="c.provider" />
              <div class="swc-cc__titles">
                <h2 class="swc-cc__name swc-truncate">{{ c.name }}</h2>
                <p class="swc-muted swc-cc__sub">
                  {{ t(`providers.${c.provider}`) }} ·
                  {{ t(`connections.authModes.${c.auth_mode}`) }}
                </p>
              </div>
              <StatusChip domain="connection" :status="c.status" dense />
            </div>
            <dl class="swc-dl swc-cc__facts">
              <div>
                <dt>{{ t('connections.account') }}</dt>
                <dd class="swc-truncate" :title="c.account_ref ?? ''">
                  {{ c.account_ref || '–' }}
                </dd>
              </div>
              <div>
                <dt>{{ t('connections.sources') }}</dt>
                <dd class="swc-num">{{ fmt.number(c.source_count) }}</dd>
              </div>
              <div>
                <dt>{{ t('common.updated') }}</dt>
                <dd>{{ c.updated_at ? fmt.relative(c.updated_at) : '–' }}</dd>
              </div>
            </dl>
            <div class="swc-row swc-cc__badges">
              <StatusChip
                v-for="key in c.secrets_set"
                :key="key"
                kind="success"
                icon="r_lock"
                :label="t('connections.secretSetNamed', { name: t(`connections.secrets.${key}`) })"
                dense
              />
              <StatusChip
                v-if="c.auth_mode === 'oauth'"
                :kind="c.authorized ? 'success' : 'warning'"
                :icon="c.authorized ? 'r_verified_user' : 'r_gpp_maybe'"
                :label="c.authorized ? t('connections.authorized') : t('connections.notAuthorized')"
                dense
              />
              <StatusChip
                v-if="c.settings.attachment_access"
                kind="info"
                icon="r_attach_file"
                :label="t('connections.attachmentAccessShort')"
                dense
              />
            </div>
            <div
              v-if="results[c.id]"
              class="swc-callout"
              :class="results[c.id]?.ok ? 'is-success' : 'is-danger'"
              role="status"
            >
              <q-icon
                :name="results[c.id]?.ok ? 'r_check_circle' : 'r_error'"
                size="18px"
                aria-hidden="true"
              />
              <span>
                {{ results[c.id]?.ok ? t('connections.testOk') : t('connections.testFailed') }}
                <template v-if="results[c.id]?.message"> – {{ results[c.id]?.message }}</template>
                <code v-if="results[c.id]?.error_code" class="swc-mono">
                  {{ results[c.id]?.error_code }}</code
                >
              </span>
            </div>
            <template #footer>
              <div class="swc-cc__actions">
                <q-btn
                  v-if="c.auth_mode === 'oauth'"
                  unelevated
                  no-caps
                  dense
                  color="primary"
                  icon="r_login"
                  class="swc-cc__primary"
                  :label="c.authorized ? t('connections.reauthorize') : t('connections.authorize')"
                  :loading="isBusy(c, 'authorize')"
                  @click="authorize(c)"
                />
                <q-btn
                  flat
                  no-caps
                  dense
                  icon="r_network_check"
                  :label="t('connections.test')"
                  :loading="isBusy(c, 'test')"
                  @click="test(c)"
                />
                <q-btn
                  v-if="c.auth_mode === 'oauth' && c.authorized"
                  flat
                  no-caps
                  dense
                  icon="r_link_off"
                  :label="t('connections.disconnect')"
                  @click="askDisconnect(c)"
                />
                <span class="swc-cc__spacer" />
                <q-btn
                  flat
                  round
                  dense
                  icon="r_edit"
                  :aria-label="t('common.editNamed', { name: c.name })"
                  @click="openEdit(c)"
                  ><q-tooltip>{{ t('common.edit') }}</q-tooltip></q-btn
                >
                <q-btn
                  flat
                  round
                  dense
                  color="negative"
                  icon="r_delete"
                  :aria-label="t('common.deleteNamed', { name: c.name })"
                  @click="askDelete(c)"
                  ><q-tooltip>{{ t('common.delete') }}</q-tooltip></q-btn
                >
              </div>
            </template>
          </SwcCard>
        </li>
      </ul>
    </DataState>

    <ConnectionSheet v-model="sheetOpen" :connection="editing" @saved="onSaved" />

    <ConfirmDialog
      v-model="disconnectOpen"
      :title="t('connections.disconnectTitle')"
      :target="toDisconnect?.name"
      :consequences="[
        t('connections.disconnectConsequence1'),
        t('connections.disconnectConsequence2'),
      ]"
      :confirm-label="t('connections.disconnect')"
      :loading="toDisconnect ? isBusy(toDisconnect, 'disconnect') : false"
      @confirm="doDisconnect"
    />
    <ConfirmDialog
      v-model="deleteOpen"
      :title="t('connections.deleteTitle')"
      :target="toDelete ? `${toDelete.name} (${t(`providers.${toDelete.provider}`)})` : undefined"
      :consequences="[
        t('connections.deleteConsequence1'),
        t(
          'connections.deleteConsequence2',
          { n: toDelete?.source_count ?? 0 },
          toDelete?.source_count ?? 0,
        ),
        t('common.irreversible'),
      ]"
      :require-text="toDelete && toDelete.source_count > 0 ? toDelete.name : undefined"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      :error="deleteError"
      @confirm="doDelete"
    />
  </div>
</template>

<style scoped>
.swc-oauth-progress {
  margin-bottom: 16px;
}
.swc-cc__head {
  display: flex;
  align-items: center;
  gap: 12px;
}
.swc-cc__titles {
  flex: 1;
  min-width: 0;
}
.swc-cc__name {
  font-size: 17px;
  font-weight: 700;
}
.swc-cc__sub {
  font-size: 13px;
}
.swc-cc__facts {
  margin-top: 14px;
}
.swc-cc__badges {
  margin-top: 12px;
  gap: 6px;
}
.swc-cc__actions {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
}
.swc-cc__primary {
  padding-inline: 10px;
}
.swc-cc__spacer {
  flex: 1;
}
</style>
