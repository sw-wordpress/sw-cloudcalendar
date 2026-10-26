import { shallowRef } from 'vue';
import { defineStore } from 'pinia';
import { useApi } from '@/api';
import type { Connection, Meta, Source, TemplateReference, TemplateSummary } from '@/types/api';

/**
 * Small cached lookups shared by several screens (meta, connection/source/template pickers,
 * placeholder reference). `invalidate()` after mutations; pass `force` to reload.
 */
export const useLookupsStore = defineStore('lookups', () => {
  const meta = shallowRef<Meta | null>(null);
  const connections = shallowRef<Connection[]>([]);
  const sources = shallowRef<Source[]>([]);
  const templates = shallowRef<TemplateSummary[]>([]);
  const reference = shallowRef<TemplateReference | null>(null);
  const loaded = { connections: false, sources: false, templates: false };
  let metaPromise: Promise<Meta> | null = null;

  async function loadMeta(force = false): Promise<Meta> {
    if (meta.value && !force) return meta.value;
    if (!metaPromise || force) {
      metaPromise = useApi()
        .system.meta()
        .then((m) => {
          meta.value = m;
          return m;
        })
        .finally(() => {
          metaPromise = null;
        });
    }
    return metaPromise;
  }
  async function loadConnections(force = false): Promise<Connection[]> {
    if (!loaded.connections || force) {
      connections.value = (await useApi().connections.list()).items;
      loaded.connections = true;
    }
    return connections.value;
  }
  async function loadSources(force = false): Promise<Source[]> {
    if (!loaded.sources || force) {
      sources.value = (await useApi().sources.list()).items;
      loaded.sources = true;
    }
    return sources.value;
  }
  async function loadTemplates(force = false): Promise<TemplateSummary[]> {
    if (!loaded.templates || force) {
      templates.value = (await useApi().templates.list()).items;
      loaded.templates = true;
    }
    return templates.value;
  }
  async function loadReference(): Promise<TemplateReference> {
    if (!reference.value) reference.value = await useApi().templates.reference();
    return reference.value;
  }
  function invalidate(): void {
    loaded.connections = false;
    loaded.sources = false;
    loaded.templates = false;
  }

  return {
    meta,
    connections,
    sources,
    templates,
    reference,
    loadMeta,
    loadConnections,
    loadSources,
    loadTemplates,
    loadReference,
    invalidate,
  };
});
