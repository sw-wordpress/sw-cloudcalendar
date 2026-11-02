<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { uid } from '@/utils/ids';

withDefaults(
  defineProps<{
    topic?: 'mode' | 'allowedModes' | 'templates' | 'overview';
    viewId?: number | null;
  }>(),
  {
    topic: 'overview',
    viewId: null,
  },
);
const { t } = useI18n();
const open = ref(false);
const titleId = uid('swc-display-help');
</script>

<template>
  <q-btn
    flat
    dense
    no-caps
    :round="topic !== 'overview'"
    icon="r_help_outline"
    :label="topic === 'overview' ? t('views.displayHelp.open') : undefined"
    :aria-label="t('views.displayHelp.labels.' + topic)"
    aria-haspopup="dialog"
    :aria-expanded="open"
    @click="open = true"
  >
    <q-tooltip max-width="320px">{{ t('views.displayHelp.tips.' + topic) }}</q-tooltip>
  </q-btn>
  <q-dialog v-model="open" :aria-labelledby="titleId">
    <q-card class="swc-display-help">
      <q-card-section class="swc-row">
        <h2 :id="titleId" class="swc-display-help__title">{{ t('views.displayHelp.title') }}</h2>
        <q-btn
          flat
          round
          dense
          icon="r_close"
          :aria-label="t('common.close')"
          @click="open = false"
        />
      </q-card-section>
      <q-card-section class="swc-display-help__body">
        <p>{{ t('views.displayHelp.intro') }}</p>
        <h3>{{ t('views.defaultDisplayMode') }}</h3>
        <table class="swc-display-help__modes">
          <tbody>
            <tr v-for="mode in ['list', 'cards', 'month']" :key="mode">
              <th scope="row">{{ t('views.modes.' + mode) }}</th>
              <td>{{ t('views.displayHelp.modes.' + mode) }}</td>
            </tr>
          </tbody>
        </table>
        <h3>{{ t('views.displayHelp.templatesTitle') }}</h3>
        <dl>
          <template v-for="kind in ['container', 'event', 'detail']" :key="kind">
            <dt>{{ t('views.' + kind + 'Template') }}</dt>
            <dd>{{ t('views.displayHelp.templates.' + kind) }}</dd>
          </template>
        </dl>
        <p class="swc-callout is-info">{{ t('views.displayHelp.fixedTemplates') }}</p>
        <h3>{{ t('views.allowedModes') }}</h3>
        <p>{{ t('views.displayHelp.allowedModes') }}</p>
        <h3>{{ t('views.allowedTemplates') }}</h3>
        <p>{{ t('views.displayHelp.allowedTemplates') }}</p>
        <h3>{{ t('views.displayHelp.exampleTitle') }}</h3>
        <p>{{ t('views.displayHelp.example') }}</p>
        <code class="swc-display-help__example"
          >[sw_calendar id="{{ viewId ?? 12 }}" view="cards" template="7"]</code
        >
        <p>{{ t('views.displayHelp.noSwitcher') }}</p>
        <p>{{ t('views.displayHelp.presets') }}</p>
      </q-card-section>
      <q-card-actions align="right">
        <q-btn
          unelevated
          no-caps
          color="primary"
          :label="t('common.close')"
          @click="open = false"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.swc-display-help {
  width: 720px;
  max-width: calc(100vw - 32px);
  max-height: 85vh;
  display: flex;
  flex-direction: column;
}
.swc-display-help__title {
  flex: 1;
  font-size: 20px;
  margin: 0;
}
.swc-display-help__body {
  overflow-y: auto;
  line-height: 1.6;
}
.swc-display-help__body h3 {
  font-size: 16px;
  margin: 20px 0 8px;
}
.swc-display-help__body h3:first-of-type {
  margin-top: 12px;
}
.swc-display-help__body dt {
  font-weight: 600;
}
.swc-display-help__body dd {
  margin: 0 0 8px;
}
.swc-display-help__modes {
  width: 100%;
  border-collapse: collapse;
}
.swc-display-help__modes th,
.swc-display-help__modes td {
  padding: 8px;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid var(--swc-border);
}
.swc-display-help__modes th {
  white-space: nowrap;
}
.swc-display-help__example {
  display: block;
  overflow-wrap: anywhere;
  white-space: normal;
  margin: 8px 0;
}
</style>
