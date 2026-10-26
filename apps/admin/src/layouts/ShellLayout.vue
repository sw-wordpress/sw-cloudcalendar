<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { getConfig } from '@/config';
import { useCapabilities } from '@/composables/useCapabilities';
import { NAV_ITEMS } from '@/router';
import { useAppStore } from '@/stores/app';
import NoAccess from '@/layouts/NoAccess.vue';

const { t } = useI18n();
const route = useRoute();
const app = useAppStore();
const { can } = useCapabilities();
const config = getConfig();
const main = ref<HTMLElement | null>(null);
const allowed = computed(() => can('manage_sw_calendar'));
const navItems = computed(() => NAV_ITEMS.filter((i) => !i.cap || can(i.cap)));

function focusMain(): void {
  main.value?.focus();
}

const transitionKey = computed(() => String(route.name ?? ''));
</script>

<template>
  <div class="swc-shell" :class="{ 'swc-shell--collapsed': app.railCollapsed }">
    <a href="#swc-main" class="swc-skip" @click.prevent="focusMain">{{ t('app.skipToContent') }}</a>

    <aside class="swc-rail">
      <div class="swc-rail__brand">
        <span class="swc-rail__logo" aria-hidden="true"
          ><q-icon name="r_calendar_month" size="22px"
        /></span>
        <span class="swc-rail__name">
          <strong>SW CloudCalendar</strong>
          <small v-if="config.version" class="swc-num">v{{ config.version }}</small>
        </span>
      </div>

      <nav v-if="allowed" class="swc-rail__nav" :aria-label="t('app.mainNavigation')">
        <ul>
          <li v-for="item in navItems" :key="item.name">
            <router-link
              :to="item.to"
              class="swc-nav-link"
              :active-class="item.to === '/' ? '' : 'swc-nav-link--active'"
              exact-active-class="swc-nav-link--active"
            >
              <q-icon :name="item.icon" size="22px" aria-hidden="true" />
              <span class="swc-nav-link__label">{{ t(item.labelKey) }}</span>
              <q-tooltip
                v-if="app.railCollapsed"
                anchor="center right"
                self="center left"
                :offset="[8, 0]"
                >{{ t(item.labelKey) }}</q-tooltip
              >
            </router-link>
          </li>
        </ul>
      </nav>

      <div class="swc-rail__tools">
        <q-btn
          flat
          round
          dense
          :icon="app.isDark ? 'r_light_mode' : 'r_dark_mode'"
          :aria-label="app.isDark ? t('app.switchToLight') : t('app.switchToDark')"
          :aria-pressed="app.isDark"
          @click="app.toggleTheme()"
        >
          <q-tooltip>{{ app.isDark ? t('app.switchToLight') : t('app.switchToDark') }}</q-tooltip>
        </q-btn>
        <q-btn
          class="swc-rail__collapse"
          flat
          round
          dense
          :icon="
            app.railCollapsed ? 'r_keyboard_double_arrow_right' : 'r_keyboard_double_arrow_left'
          "
          :aria-label="app.railCollapsed ? t('app.expandNav') : t('app.collapseNav')"
          :aria-expanded="!app.railCollapsed"
          @click="app.railCollapsed = !app.railCollapsed"
        />
      </div>
    </aside>

    <main id="swc-main" ref="main" class="swc-main" tabindex="-1">
      <NoAccess v-if="!allowed" />
      <router-view v-else v-slot="{ Component }">
        <transition name="swc-fade" mode="out-in">
          <component :is="Component" :key="transitionKey" />
        </transition>
      </router-view>
    </main>

    <div class="swc-sr-only" role="status" aria-live="polite" aria-atomic="true">
      {{ app.announcement }}
    </div>
  </div>
</template>

<style scoped>
.swc-shell {
  --rail-w: 244px;
  --bar-h: var(--wp-admin--admin-bar--height, 32px);
  display: grid;
  grid-template-columns: var(--rail-w) minmax(0, 1fr);
  min-height: calc(100vh - var(--bar-h));
  background: var(--swc-bg);
  position: relative;
}
.swc-shell--collapsed {
  --rail-w: 76px;
}
.swc-skip {
  position: absolute;
  left: 12px;
  top: -60px;
  z-index: 100;
  padding: 8px 14px;
  border-radius: 8px;
  background: var(--swc-primary);
  color: #fff !important;
  font-weight: 600;
}
.swc-skip:focus {
  top: 10px;
}
.swc-rail {
  position: sticky;
  top: var(--bar-h);
  align-self: start;
  height: calc(100vh - var(--bar-h));
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 18px 14px;
  background: var(--swc-rail-bg);
  border-right: 1px solid var(--swc-border);
  transition: width 0.2s ease;
}
.swc-rail__brand {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 4px 8px 16px;
  min-width: 0;
}
.swc-rail__logo {
  display: grid;
  place-items: center;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  color: #fff;
  background: var(--swc-gradient);
  box-shadow: 0 6px 16px rgba(79, 70, 229, 0.35);
}
.swc-rail__name {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
  min-width: 0;
}
.swc-rail__name strong {
  font-size: 16px;
  letter-spacing: -0.01em;
}
.swc-rail__name small {
  color: var(--swc-muted);
  font-size: 12px;
}
.swc-rail__nav {
  flex: 1;
  overflow-y: auto;
}
.swc-rail__nav ul {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.swc-nav-link {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 12px;
  color: var(--swc-muted) !important;
  font-weight: 600;
  text-decoration: none !important;
  transition:
    background-color 0.15s ease,
    color 0.15s ease;
}
.swc-nav-link:hover {
  background: var(--swc-surface-3);
  color: var(--swc-text) !important;
}
.swc-nav-link--active {
  background: var(--swc-primary-bg);
  color: var(--swc-primary-text) !important;
}
.swc-nav-link--active::before {
  content: '';
  position: absolute;
  left: -14px;
  top: 10px;
  bottom: 10px;
  width: 4px;
  border-radius: 0 4px 4px 0;
  background: var(--swc-gradient);
}
.swc-nav-link__badge {
  margin-left: auto;
  min-width: 22px;
  padding: 0 7px;
  border-radius: 999px;
  text-align: center;
  font-size: 12px;
  line-height: 22px;
  color: #fff;
  background: var(--swc-gradient);
}
.swc-rail__tools {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 4px 0;
  border-top: 1px solid var(--swc-border);
  color: var(--swc-muted);
}
.swc-shell--collapsed .swc-rail__name,
.swc-shell--collapsed .swc-nav-link__label {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
}
.swc-shell--collapsed .swc-nav-link {
  justify-content: center;
}
.swc-shell--collapsed .swc-nav-link__badge {
  position: absolute;
  top: 2px;
  right: 2px;
  margin: 0;
  min-width: 18px;
  line-height: 18px;
  font-size: 11px;
  padding: 0 5px;
}
.swc-shell--collapsed .swc-rail__tools {
  flex-direction: column;
  gap: 4px;
}
.swc-shell--collapsed .swc-rail__brand {
  justify-content: center;
  padding-inline: 0;
}
.swc-main {
  min-width: 0;
  padding: 28px clamp(16px, 3vw, 40px) 48px;
  outline: none;
}
.swc-main > * {
  max-width: 1320px;
  margin-inline: auto;
}

@media (max-width: 860px) {
  .swc-shell,
  .swc-shell--collapsed {
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: auto 1fr;
  }
  .swc-rail {
    position: sticky;
    z-index: 20;
    height: auto;
    flex-direction: row;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    border-right: 0;
    border-bottom: 1px solid var(--swc-border);
  }
  .swc-rail__brand {
    padding: 0;
  }
  .swc-rail__name {
    display: none;
  }
  .swc-rail__nav {
    flex: 1;
    min-width: 0;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
  }
  .swc-rail__nav ul {
    flex-direction: row;
  }
  .swc-nav-link {
    flex-direction: column;
    gap: 2px;
    padding: 6px 10px;
    font-size: 11px;
  }
  .swc-shell--collapsed .swc-nav-link__label {
    position: static;
    width: auto;
    height: auto;
    clip: auto;
  }
  .swc-nav-link--active::before {
    left: 10px;
    right: 10px;
    top: auto;
    bottom: 0;
    width: auto;
    height: 3px;
    border-radius: 3px 3px 0 0;
  }
  .swc-nav-link__badge {
    position: absolute;
    top: 0;
    right: 2px;
    margin: 0;
    min-width: 18px;
    line-height: 18px;
    font-size: 11px;
    padding: 0 5px;
  }
  .swc-rail__tools {
    border-top: 0;
    padding: 0;
    flex-direction: row;
  }
  .swc-rail__collapse {
    display: none;
  }
  .swc-main {
    padding-top: 20px;
  }
}
</style>
