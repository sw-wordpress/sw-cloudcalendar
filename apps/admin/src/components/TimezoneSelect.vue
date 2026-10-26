<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

/** Searchable IANA time zone select. `allowEmpty` adds an entry for "use default". */
const model = defineModel<string>({ required: true });
const props = withDefaults(
  defineProps<{
    timezones: string[];
    label: string;
    hint?: string;
    allowEmpty?: boolean;
    emptyLabel?: string;
    error?: string;
  }>(),
  { hint: undefined, allowEmpty: false, emptyLabel: undefined, error: undefined },
);
const { t } = useI18n();
const needle = ref('');

const all = computed(() => {
  const list = props.timezones.length > 0 ? props.timezones : ['UTC'];
  return list.includes(model.value) || model.value === '' ? list : [model.value, ...list];
});
const options = computed(() => {
  const q = needle.value.toLowerCase();
  const filtered = q === '' ? all.value : all.value.filter((z) => z.toLowerCase().includes(q));
  const mapped = filtered.slice(0, 400).map((z) => ({ label: z, value: z }));
  return props.allowEmpty
    ? [{ label: props.emptyLabel ?? t('common.default'), value: '' }, ...mapped]
    : mapped;
});

function filter(value: string, update: (fn: () => void) => void): void {
  update(() => {
    needle.value = value;
  });
}
</script>

<template>
  <q-select
    v-model="model"
    outlined
    dense
    use-input
    fill-input
    hide-selected
    input-debounce="0"
    emit-value
    map-options
    :options="options"
    :label="label"
    :hint="hint"
    :error="!!error"
    :error-message="error"
    @filter="filter"
  />
</template>
