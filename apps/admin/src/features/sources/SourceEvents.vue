<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import DataState from '@/components/ui/DataState.vue';
import SwcCard from '@/components/ui/SwcCard.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useFormat } from '@/composables/useFormat';

const props = defineProps<{ sourceId: number }>();
const { t, locale } = useI18n();
const api = useApi();
const fmt = useFormat();
const now = new Date();
const month = ref(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`);
const page = ref(1);
const validMonth = computed(() => /^[1-9]\d{3}-(0[1-9]|1[0-2])$/.test(month.value));
const events = useAsyncData(() =>
  validMonth.value
    ? api.sources.events(props.sourceId, { month: month.value, page: page.value, per_page: 20 })
    : Promise.resolve(null),
);
watch(month, () => {
  page.value = 1;
});
watch([month, page], () => void events.load());

function date(value: string, exclusiveEnd = false): string {
  const day = new Date(value.slice(0, 10) + 'T00:00:00Z');
  if (exclusiveEnd) day.setUTCDate(day.getUTCDate() - 1);
  return day.toLocaleDateString(locale.value, { timeZone: 'UTC' });
}
</script>

<template>
  <SwcCard :title="t('sources.availableEvents')" icon="r_event">
    <div class="swc-row">
      <label>{{ t('sources.eventsMonth') }} <input v-model="month" type="month" required /></label>
      <q-btn
        flat
        no-caps
        icon="r_refresh"
        :label="t('common.refresh')"
        :disable="!validMonth"
        :loading="events.loading.value"
        @click="events.load()"
      />
    </div>
    <DataState
      :loading="events.loading.value"
      :error="events.error.value"
      :empty="!events.data.value?.items.length"
      @retry="events.load()"
    >
      <template #empty
        ><p class="swc-hint">{{ t('sources.noEventsInMonth') }}</p></template
      >
      <div class="swc-table-wrap">
        <table class="swc-table">
          <thead>
            <tr>
              <th scope="col">{{ t('sources.eventTitle') }}</th>
              <th scope="col">{{ t('sources.eventStart') }}</th>
              <th scope="col">{{ t('sources.eventEnd') }}</th>
              <th scope="col">{{ t('sources.eventLocation') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(event, index) in events.data.value?.items ?? []" :key="index">
              <td :data-label="t('sources.eventTitle')">
                {{ event.title }}
                <span v-if="event.all_day" class="swc-muted">({{ t('sources.eventAllDay') }})</span>
              </td>
              <td :data-label="t('sources.eventStart')">
                {{ event.all_day ? date(event.start) : fmt.dateTime(event.start) }}
              </td>
              <td :data-label="t('sources.eventEnd')">
                {{ event.all_day ? date(event.end, true) : fmt.dateTime(event.end) }}
              </td>
              <td :data-label="t('sources.eventLocation')">{{ event.location || '–' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar
        v-if="events.data.value"
        v-model:page="page"
        :pages="events.data.value.pages"
        :total="events.data.value.total"
      />
    </DataState>
  </SwcCard>
</template>
