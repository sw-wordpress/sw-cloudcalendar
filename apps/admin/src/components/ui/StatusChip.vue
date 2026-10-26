<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

export type ChipKind =
  'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'running' | 'primary';
export type ChipDomain =
  'connection' | 'source' | 'run' | 'visibility' | 'health' | 'level' | 'publication';

interface Meta {
  kind: ChipKind;
  icon: string;
}

const MAP: Record<ChipDomain, Record<string, Meta>> = {
  connection: {
    disconnected: { kind: 'neutral', icon: 'r_link_off' },
    connecting: { kind: 'running', icon: 'r_autorenew' },
    connected: { kind: 'success', icon: 'r_check_circle' },
    reauth_required: { kind: 'warning', icon: 'r_lock_reset' },
    failed: { kind: 'danger', icon: 'r_error' },
    disabled: { kind: 'neutral', icon: 'r_block' },
  },
  source: {
    draft: { kind: 'neutral', icon: 'r_edit_note' },
    active: { kind: 'success', icon: 'r_check_circle' },
    paused: { kind: 'warning', icon: 'r_pause_circle' },
    error: { kind: 'danger', icon: 'r_error' },
    disabled: { kind: 'neutral', icon: 'r_block' },
  },
  run: {
    running: { kind: 'running', icon: 'r_sync' },
    success: { kind: 'success', icon: 'r_check_circle' },
    failed: { kind: 'danger', icon: 'r_error' },
  },
  visibility: {
    public: { kind: 'warning', icon: 'r_public' },
    authenticated: { kind: 'info', icon: 'r_lock' },
    roles: { kind: 'primary', icon: 'r_group' },
  },
  health: {
    ok: { kind: 'success', icon: 'r_check_circle' },
    warning: { kind: 'warning', icon: 'r_warning' },
    error: { kind: 'danger', icon: 'r_error' },
  },
  level: {
    debug: { kind: 'neutral', icon: 'r_bug_report' },
    info: { kind: 'info', icon: 'r_info' },
    warning: { kind: 'warning', icon: 'r_warning' },
    error: { kind: 'danger', icon: 'r_error' },
  },
  publication: {
    PUBLIC_VERIFIED: { kind: 'success', icon: 'r_verified' },
    PRIVATE: { kind: 'neutral', icon: 'r_lock' },
    UNKNOWN: { kind: 'warning', icon: 'r_help' },
  },
};

const props = withDefaults(
  defineProps<{
    domain?: ChipDomain;
    status?: string;
    kind?: ChipKind;
    icon?: string;
    label?: string;
    dense?: boolean;
  }>(),
  {
    domain: undefined,
    status: undefined,
    kind: undefined,
    icon: undefined,
    label: undefined,
    dense: false,
  },
);

const { t, te } = useI18n();

const meta = computed<Meta>(() => {
  const fromMap = props.domain && props.status ? MAP[props.domain][props.status] : undefined;
  return {
    kind: props.kind ?? fromMap?.kind ?? 'neutral',
    icon: props.icon ?? fromMap?.icon ?? 'r_circle',
  };
});

const text = computed(() => {
  if (props.label) return props.label;
  if (props.domain && props.status) {
    const key = `status.${props.domain}.${props.status}`;
    return te(key) ? t(key) : props.status;
  }
  return '';
});
</script>

<template>
  <span class="swc-chip" :class="[`swc-chip--${meta.kind}`, { 'swc-chip--dense': dense }]">
    <q-icon :name="meta.icon" class="swc-chip__icon" size="1.15em" aria-hidden="true" />
    <span class="swc-chip__text">{{ text }}</span>
  </span>
</template>

<style scoped>
.swc-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px 3px 8px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.4;
  white-space: nowrap;
  max-width: 100%;
  background: var(--swc-neutral-bg);
  color: var(--swc-neutral-text);
  border: 1px solid transparent;
}
.swc-chip--dense {
  font-size: 12px;
  padding: 1px 8px 1px 6px;
}
.swc-chip__text {
  overflow: hidden;
  text-overflow: ellipsis;
}
.swc-chip--success {
  background: var(--swc-success-bg);
  color: var(--swc-success-text);
}
.swc-chip--warning {
  background: var(--swc-warning-bg);
  color: var(--swc-warning-text);
}
.swc-chip--danger {
  background: var(--swc-danger-bg);
  color: var(--swc-danger-text);
}
.swc-chip--info {
  background: var(--swc-info-bg);
  color: var(--swc-info-text);
}
.swc-chip--primary,
.swc-chip--running {
  background: var(--swc-primary-bg);
  color: var(--swc-primary-text);
}
.swc-chip--running .swc-chip__icon {
  animation: swc-spin 1.6s linear infinite;
}
</style>
