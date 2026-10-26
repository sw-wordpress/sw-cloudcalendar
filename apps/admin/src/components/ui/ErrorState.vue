<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { ApiError } from '@/api/client';
import EmptyState from './EmptyState.vue';

defineProps<{ error: ApiError; compact?: boolean }>();
const emit = defineEmits<{ (e: 'retry'): void }>();
const { t } = useI18n();
</script>

<template>
  <div role="alert">
    <EmptyState
      illustration="error"
      :title="t('errors.loadFailed')"
      :text="error.message"
      :compact="compact"
    >
      <q-btn
        color="primary"
        unelevated
        no-caps
        icon="r_refresh"
        :label="t('common.retry')"
        @click="emit('retry')"
      />
      <span class="swc-muted swc-mono"
        >{{ error.code }}<template v-if="error.status"> · {{ error.status }}</template></span
      >
    </EmptyState>
  </div>
</template>
