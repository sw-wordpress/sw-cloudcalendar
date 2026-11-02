import { flushPromises, mount } from '@vue/test-utils';
import { CompletionContext } from '@codemirror/autocomplete';
import { EditorState } from '@codemirror/state';
import { EditorView } from '@codemirror/view';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CodeEditor from '../src/components/editor/CodeEditor.vue';
import { placeholderCompletions } from '../src/components/editor/placeholderCompletion';

const placeholders = [
  { name: 'event.title', type: 'text', description: 'Title' },
  { name: 'event.attachments', type: 'html', description: 'Files' },
];

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

describe('placeholder completion', () => {
  it('only opens on placeholders and keeps HTML out of sections', () => {
    const source = placeholderCompletions(() => placeholders);
    expect(source(new CompletionContext(EditorState.create({ doc: 'plain' }), 5, true))).toBeNull();
    const result = source(new CompletionContext(EditorState.create({ doc: '{{#ev' }), 5, true))!;
    expect(result.options.map((option) => option.label)).toEqual(['event.title']);
  });
  it.each(['{{ev}}', '{{#ev}}', '{{^ev}}', '{{/ev}}'])(
    'replaces %s without duplicating delimiters',
    (doc) => {
      const pos = doc.length - 2;
      const view = new EditorView({ state: EditorState.create({ doc }), parent: document.body });
      const result = placeholderCompletions(() => placeholders)(
        new CompletionContext(view.state, pos, true),
      )!;
      const completion = result.options[0]!;
      if (typeof completion.apply !== 'function') throw new Error('Expected custom completion');
      completion.apply(view, completion, result.from, pos);
      expect(view.state.doc.toString()).toBe(
        doc.includes('#') || doc.includes('^')
          ? `${doc.slice(0, 3)}event.title}}{{/event.title}}`
          : `${doc.slice(0, doc.includes('/') ? 3 : 2)}event.title}}`,
      );
      view.destroy();
    },
  );
});

describe('CodeMirror integration', () => {
  it('synchronizes edits, placeholders, markers and readonly state', async () => {
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe() {}
        unobserve() {}
        disconnect() {}
      },
    );
    const wrapper = mount(CodeEditor, {
      props: { modelValue: '<p>Title</p>', language: 'html', label: 'Template', placeholders },
      attachTo: document.body,
    });
    wrapper.vm.insertPlaceholder('event.title');
    await flushPromises();
    expect(wrapper.emitted('update:modelValue')!.at(-1)![0]).toContain('{{event.title}}');
    await wrapper.setProps({
      modelValue: 'first\nsecond',
      readonly: true,
      markers: [{ line: 2, column: 2, message: 'Invalid', severity: 'warning' }],
    });
    const view = EditorView.findFromDOM(wrapper.get<HTMLElement>('.cm-editor').element)!;
    expect(view.state.doc.toString()).toBe('first\nsecond');
    expect(view.state.readOnly).toBe(true);
    wrapper.vm.goTo(2, 2);
    expect(view.state.selection.main.from).toBe(7);
    await wrapper.setProps({ readonly: false });
    expect(view.state.readOnly).toBe(false);
    wrapper.unmount();
  });
});
