<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { autocompletion, closeBrackets, completionKeymap } from '@codemirror/autocomplete';
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands';
import { css } from '@codemirror/lang-css';
import { html } from '@codemirror/lang-html';
import { bracketMatching, HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { lintGutter, setDiagnostics } from '@codemirror/lint';
import type { Diagnostic } from '@codemirror/lint';
import { Compartment, EditorState } from '@codemirror/state';
import {
  EditorView,
  drawSelection,
  highlightActiveLine,
  highlightActiveLineGutter,
  keymap,
  lineNumbers,
} from '@codemirror/view';
import { tags } from '@lezer/highlight';
import type { PlaceholderInfo } from '@/types/api';
import { offsetFor, placeholderCompletions, placeholderSnippet } from './placeholderCompletion';

export interface EditorMarker {
  line: number;
  column: number;
  message: string;
  severity?: 'error' | 'warning';
}

/**
 * CodeMirror 6 editor (HTML or CSS) with placeholder autocompletion and server validation
 * markers. Tab is NOT captured (keyboard users can leave the editor); indentation uses spaces
 * typed by the user. Colours come from CSS variables so light/dark themes both work.
 */
const model = defineModel<string>({ required: true });
const props = withDefaults(
  defineProps<{
    language: 'html' | 'css';
    label: string;
    placeholders?: PlaceholderInfo[];
    markers?: EditorMarker[];
    readonly?: boolean;
    minHeight?: number;
  }>(),
  { placeholders: () => [], markers: () => [], readonly: false, minHeight: 320 },
);

const host = ref<HTMLElement | null>(null);
let view: EditorView | null = null;
const readOnly = new Compartment();

const highlight = HighlightStyle.define([
  { tag: tags.tagName, color: 'var(--swc-cm-tag)' },
  { tag: tags.attributeName, color: 'var(--swc-cm-attr)' },
  { tag: [tags.attributeValue, tags.string], color: 'var(--swc-cm-string)' },
  { tag: tags.comment, color: 'var(--swc-cm-comment)', fontStyle: 'italic' },
  { tag: [tags.keyword, tags.definitionKeyword], color: 'var(--swc-cm-keyword)' },
  { tag: [tags.propertyName], color: 'var(--swc-cm-attr)' },
  { tag: [tags.number, tags.unit, tags.color], color: 'var(--swc-cm-number)' },
  { tag: [tags.className, tags.variableName], color: 'var(--swc-cm-tag)' },
  { tag: [tags.angleBracket, tags.bracket, tags.punctuation], color: 'var(--swc-cm-punct)' },
  { tag: tags.invalid, color: 'var(--swc-danger)' },
]);

const theme = EditorView.theme({
  '&': {
    color: 'var(--swc-text)',
    backgroundColor: 'var(--swc-surface)',
    border: '1px solid var(--swc-border)',
    borderRadius: '10px',
    fontSize: '13px',
  },
  '&.cm-focused': { outline: '3px solid var(--swc-focus)', outlineOffset: '2px' },
  '.cm-scroller': { fontFamily: 'var(--swc-mono)', lineHeight: '1.6' },
  '.cm-content': { caretColor: 'var(--swc-primary)', padding: '8px 0' },
  '.cm-gutters': {
    backgroundColor: 'var(--swc-surface-2)',
    color: 'var(--swc-muted)',
    borderRight: '1px solid var(--swc-border)',
    borderRadius: '10px 0 0 10px',
  },
  '.cm-activeLine': { backgroundColor: 'var(--swc-cm-active)' },
  '.cm-activeLineGutter': { backgroundColor: 'var(--swc-cm-active)' },
  '&.cm-focused .cm-selectionBackground, .cm-selectionBackground': {
    backgroundColor: 'var(--swc-cm-selection) !important',
  },
  '.cm-tooltip': {
    backgroundColor: 'var(--swc-surface)',
    color: 'var(--swc-text)',
    border: '1px solid var(--swc-border)',
    borderRadius: '8px',
  },
  '.cm-tooltip-autocomplete ul li[aria-selected]': {
    backgroundColor: 'var(--swc-primary-bg)',
    color: 'var(--swc-primary-text)',
  },
  '.cm-completionDetail': { color: 'var(--swc-muted)', marginLeft: '8px' },
  '.cm-completionInfo': { maxWidth: '320px', padding: '6px 10px' },
});

function diagnostics(): Diagnostic[] {
  if (!view) return [];
  const lines = view.state.doc.toString().split('\n');
  return props.markers.map((m) => {
    const { from, to } = offsetFor(lines, m.line, m.column);
    return { from, to, severity: m.severity ?? 'error', message: m.message };
  });
}

function applyMarkers(): void {
  if (view) view.dispatch(setDiagnostics(view.state, diagnostics()));
}

onMounted(() => {
  if (!host.value) return;
  const extensions = [
    lineNumbers(),
    highlightActiveLineGutter(),
    highlightActiveLine(),
    history(),
    drawSelection(),
    bracketMatching(),
    closeBrackets(),
    syntaxHighlighting(highlight),
    lintGutter(),
    keymap.of([...defaultKeymap, ...historyKeymap, ...completionKeymap]),
    props.language === 'html' ? html({ autoCloseTags: true, matchClosingTags: true }) : css(),
    autocompletion(
      props.language === 'html'
        ? { override: [placeholderCompletions(() => props.placeholders)], icons: false }
        : { icons: false },
    ),
    EditorView.lineWrapping,
    theme,
    readOnly.of(EditorState.readOnly.of(props.readonly)),
    EditorView.contentAttributes.of({ 'aria-label': props.label, 'aria-multiline': 'true' }),
    EditorView.updateListener.of((update) => {
      if (update.docChanged) model.value = update.state.doc.toString();
    }),
  ];
  view = new EditorView({
    parent: host.value,
    state: EditorState.create({ doc: model.value, extensions }),
  });
  applyMarkers();
});

watch(model, (value) => {
  if (view && value !== view.state.doc.toString()) {
    view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: value } });
  }
});
watch(() => props.markers, applyMarkers, { deep: true });
watch(
  () => props.readonly,
  (value) => view?.dispatch({ effects: readOnly.reconfigure(EditorState.readOnly.of(value)) }),
);

onBeforeUnmount(() => {
  view?.destroy();
  view = null;
});

/** Inserts text at the cursor (used by the placeholder reference panel). */
function insert(text: string): void {
  if (!view) {
    model.value += text;
    return;
  }
  const { from, to } = view.state.selection.main;
  view.dispatch({
    changes: { from, to, insert: text },
    selection: { anchor: from + text.length },
    scrollIntoView: true,
  });
  view.focus();
}

function insertPlaceholder(name: string, sigil: '' | '#' | '^' = ''): void {
  insert(placeholderSnippet(name, sigil));
}

/** Moves the cursor to a line/column (error list). */
function goTo(line: number, column: number): void {
  if (!view) return;
  const { from } = offsetFor(view.state.doc.toString().split('\n'), line, column);
  view.dispatch({ selection: { anchor: from }, scrollIntoView: true });
  view.focus();
}

defineExpose({ insert, insertPlaceholder, goTo });
</script>

<template>
  <div ref="host" class="swc-code" :style="{ '--swc-code-min': `${minHeight}px` }" />
</template>

<style scoped>
.swc-code :deep(.cm-editor) {
  min-height: var(--swc-code-min);
}
.swc-code :deep(.cm-scroller) {
  min-height: var(--swc-code-min);
  max-height: 70vh;
}
</style>
