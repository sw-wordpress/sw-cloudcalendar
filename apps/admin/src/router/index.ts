import { createRouter, createWebHashHistory } from 'vue-router';
import type { RouteRecordRaw } from 'vue-router';
import type { Capability } from '@/types/config';

export interface NavItem {
  name: string;
  to: string;
  icon: string;
  labelKey: string;
  /** UX-only: hide the entry without this capability. */
  cap?: Capability;
}

/** Same order as the WordPress submenu (AdminPage::add_menu). */
export const NAV_ITEMS: NavItem[] = [
  { name: 'dashboard', to: '/', icon: 'r_space_dashboard', labelKey: 'nav.dashboard' },
  { name: 'connections', to: '/connections', icon: 'r_link', labelKey: 'nav.connections' },
  { name: 'sources', to: '/sources', icon: 'r_event_note', labelKey: 'nav.sources' },
  { name: 'views', to: '/views', icon: 'r_view_quilt', labelKey: 'nav.views' },
  { name: 'templates', to: '/templates', icon: 'r_code', labelKey: 'nav.templates' },
  { name: 'sync', to: '/sync', icon: 'r_sync', labelKey: 'nav.sync' },
  { name: 'settings', to: '/settings', icon: 'r_settings', labelKey: 'nav.settings' },
  {
    name: 'diagnostics',
    to: '/diagnostics',
    icon: 'r_monitor_heart',
    labelKey: 'nav.diagnostics',
  },
];

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'dashboard',
    component: () => import('@/features/dashboard/DashboardView.vue'),
  },
  {
    path: '/connections',
    name: 'connections',
    component: () => import('@/features/connections/ConnectionsView.vue'),
  },
  {
    path: '/sources',
    name: 'sources',
    component: () => import('@/features/sources/SourcesView.vue'),
  },
  {
    path: '/sources/new',
    name: 'source-new',
    component: () => import('@/features/sources/SourceEditorView.vue'),
  },
  {
    path: '/sources/:id(\\d+)',
    name: 'source-edit',
    component: () => import('@/features/sources/SourceEditorView.vue'),
    props: true,
  },
  { path: '/views', name: 'views', component: () => import('@/features/views/ViewsView.vue') },
  {
    path: '/views/new',
    name: 'view-new',
    component: () => import('@/features/views/ViewEditorView.vue'),
  },
  {
    path: '/views/:id(\\d+)',
    name: 'view-edit',
    component: () => import('@/features/views/ViewEditorView.vue'),
    props: true,
  },
  {
    path: '/templates',
    name: 'templates',
    component: () => import('@/features/templates/TemplatesView.vue'),
  },
  {
    path: '/templates/new',
    name: 'template-new',
    component: () => import('@/features/templates/TemplateEditorView.vue'),
  },
  {
    path: '/templates/:id(\\d+)',
    name: 'template-edit',
    component: () => import('@/features/templates/TemplateEditorView.vue'),
    props: true,
  },
  { path: '/sync', name: 'sync', component: () => import('@/features/sync/SyncView.vue') },
  {
    path: '/settings',
    name: 'settings',
    component: () => import('@/features/settings/SettingsView.vue'),
  },
  {
    path: '/diagnostics',
    name: 'diagnostics',
    component: () => import('@/features/diagnostics/DiagnosticsView.vue'),
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
];

export function createAppRouter() {
  return createRouter({
    history: createWebHashHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
  });
}
