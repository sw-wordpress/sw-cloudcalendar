<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import CopyButton from '@/components/CopyButton.vue';
import { getConfig } from '@/config';
import type { Provider } from '@/types/api';
import { uid } from '@/utils/ids';
import { setupLinks, setupValues } from './providerSetup';

/**
 * Step by step guide for creating the OAuth client (client ID + secret) at Google or Microsoft, with every value the
 * administrator has to enter, computed for this site.
 */
const open = defineModel<boolean>({ required: true });
const props = defineProps<{
  provider: Exclude<Provider, 'ics'>;
  redirectUri: string;
  attachmentAccess: boolean;
}>();
const { t, tm } = useI18n();
const titleId = uid('swc-guide');

const values = computed(() =>
  setupValues(
    props.provider,
    props.redirectUri,
    getConfig().siteUrl,
    props.attachmentAccess,
  ).filter((v) => v.value !== ''),
);
const links = computed(() => setupLinks(props.provider, props.attachmentAccess));
const steps = computed(() => {
  const raw = tm(`connections.guide.${props.provider}.steps`);
  return Array.isArray(raw) ? raw.length : 0;
});
const notes = computed(() => {
  const raw = tm(`connections.guide.${props.provider}.notes`);
  return Array.isArray(raw) ? raw.length : 0;
});
</script>

<template>
  <q-dialog v-model="open" :aria-labelledby="titleId">
    <q-card class="swc-guide">
      <q-card-section class="swc-guide__head">
        <span class="swc-guide__icon" aria-hidden="true"
          ><q-icon name="r_menu_book" size="24px"
        /></span>
        <div>
          <h2 :id="titleId" class="swc-guide__title">
            {{ t(`connections.guide.${provider}.title`) }}
          </h2>
          <p class="swc-guide__msg">{{ t(`connections.guide.${provider}.intro`) }}</p>
        </div>
      </q-card-section>

      <q-card-section class="swc-guide__body">
        <nav class="swc-guide__links" :aria-label="t('connections.guide.linksTitle')">
          <q-btn
            v-for="link in links"
            :key="link.key"
            :href="link.href"
            target="_blank"
            rel="noopener noreferrer"
            outline
            no-caps
            dense
            color="primary"
            icon-right="r_open_in_new"
            :label="t(`connections.guide.links.${link.key}`)"
            :aria-label="`${t(`connections.guide.links.${link.key}`)} (${t('connections.guide.newTab')})`"
          />
        </nav>
        <h3 class="swc-guide__sub">{{ t('connections.guide.valuesTitle') }}</h3>
        <dl class="swc-guide__values">
          <div v-for="v in values" :key="v.key" class="swc-guide__value">
            <dt>{{ t(`connections.guide.values.${v.key}`) }}</dt>
            <dd>
              <code v-if="v.copy" class="swc-mono">{{ v.value }}</code>
              <span v-else>{{ v.value }}</span>
              <CopyButton
                v-if="v.copy"
                :text="v.value"
                :label="
                  t('connections.guide.copyValue', { name: t(`connections.guide.values.${v.key}`) })
                "
              />
            </dd>
          </div>
        </dl>

        <h3 class="swc-guide__sub">{{ t('connections.guide.stepsTitle') }}</h3>
        <ol class="swc-guide__steps">
          <li v-for="n in steps" :key="n">
            {{ t(`connections.guide.${provider}.steps[${n - 1}]`) }}
          </li>
        </ol>

        <div class="swc-callout is-info">
          <ul class="swc-guide__notes">
            <li v-for="n in notes" :key="n">
              {{ t(`connections.guide.${provider}.notes[${n - 1}]`) }}
            </li>
          </ul>
        </div>
      </q-card-section>

      <q-card-actions align="right" class="swc-guide__actions">
        <q-btn flat no-caps :label="t('common.close')" data-autofocus @click="open = false" />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.swc-guide {
  width: min(760px, calc(100vw - 24px));
  max-width: none !important;
}
.swc-guide__head {
  display: flex;
  gap: 14px;
  align-items: flex-start;
}
.swc-guide__icon {
  display: grid;
  place-items: center;
  flex: none;
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: var(--swc-primary-bg);
  color: var(--swc-primary-text);
}
.swc-guide__title {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.3;
}
.swc-guide__msg {
  margin-top: 4px;
  color: var(--swc-muted);
}
.swc-guide__body {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding-top: 0;
}
.swc-guide__links {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.swc-guide__links .q-btn {
  padding: 4px 10px;
}
.swc-guide__sub {
  font-size: 14px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--swc-muted);
}
.swc-guide__values {
  display: flex;
  flex-direction: column;
  border: 1px solid var(--swc-border);
  border-radius: var(--swc-radius-sm);
  overflow: hidden;
}
.swc-guide__value {
  display: grid;
  grid-template-columns: minmax(150px, 30%) 1fr;
  gap: 12px;
  padding: 8px 12px;
  font-size: 14px;
}
.swc-guide__value + .swc-guide__value {
  border-top: 1px solid var(--swc-border);
}
.swc-guide__value dt {
  font-weight: 600;
}
.swc-guide__value dd {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  min-width: 0;
  margin: 0;
}
.swc-guide__value code {
  flex: 1;
  min-width: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  background: none;
  padding: 0;
}
.swc-guide__steps {
  list-style: decimal;
  margin: 0 0 0 22px;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 14px;
}
.swc-guide__notes {
  list-style: disc;
  margin: 0 0 0 18px;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.swc-guide__actions {
  padding: 8px 16px 16px;
}
@media (max-width: 600px) {
  .swc-guide__value {
    grid-template-columns: 1fr;
    gap: 2px;
  }
}
</style>
