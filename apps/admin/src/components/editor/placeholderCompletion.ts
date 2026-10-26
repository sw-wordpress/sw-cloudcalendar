import type { Completion, CompletionContext, CompletionResult } from '@codemirror/autocomplete';
import type { EditorView } from '@codemirror/view';
import type { PlaceholderInfo } from '@/types/api';

/** Placeholder types that may open a section (`{{#…}}` / `{{^…}}`): all except HTML components. */
export function canBeSection(info: PlaceholderInfo): boolean {
  return info.type !== 'html';
}

/** Text inserted for a placeholder, e.g. `{{event.title}}` or `{{#event.location}}{{/event.location}}`. */
export function placeholderSnippet(name: string, sigil: '' | '#' | '^' = ''): string {
  return sigil === '' ? `{{${name}}}` : `{{${sigil}${name}}}{{/${name}}}`;
}

/**
 * CodeMirror completion source for `{{name}}`, `{{#name}}`, `{{^name}}` and `{{/name}}`.
 * Sections get their closing tag with the cursor placed in between.
 */
export function placeholderCompletions(getList: () => PlaceholderInfo[]) {
  return (context: CompletionContext): CompletionResult | null => {
    const match = context.matchBefore(/\{\{[#^/]?[\w.]*/);
    if (!match) return null;
    const sigil = match.text.charAt(2);
    const hasSigil = sigil === '#' || sigil === '^' || sigil === '/';
    const from = match.from + 2 + (hasSigil ? 1 : 0);
    const after = context.state.sliceDoc(context.pos, context.pos + 2);
    const closeAfter = after === '}}';
    const list = getList().filter((info) => !hasSigil || sigil === '/' || canBeSection(info));
    const options: Completion[] = list.map((info) => ({
      label: info.name,
      detail: info.type,
      info: info.description,
      type: info.type === 'html' ? 'class' : info.type === 'flag' ? 'keyword' : 'variable',
      apply: (view: EditorView, _completion: Completion, start: number, end: number) => {
        const to = closeAfter ? end + 2 : end;
        if (sigil === '#' || sigil === '^') {
          const open = `${info.name}}}`;
          const insert = `${open}{{/${info.name}}}`;
          view.dispatch({
            changes: { from: start, to, insert },
            selection: { anchor: start + open.length },
          });
          return;
        }
        const insert = `${info.name}}}`;
        view.dispatch({
          changes: { from: start, to, insert },
          selection: { anchor: start + insert.length },
        });
      },
    }));
    return { from, options, validFor: /^[\w.]*$/ };
  };
}

/** Converts a 1-based line/column (server validation) into a document offset. */
export function offsetFor(
  lines: string[],
  line: number,
  column: number,
): { from: number; to: number } {
  const safeLine = Math.min(Math.max(1, Math.floor(line) || 1), Math.max(1, lines.length));
  let offset = 0;
  for (let i = 0; i < safeLine - 1; i += 1) offset += (lines[i] ?? '').length + 1;
  const text = lines[safeLine - 1] ?? '';
  const col = Math.min(Math.max(1, Math.floor(column) || 1), text.length + 1);
  const from = offset + col - 1;
  const to = Math.min(offset + text.length, from + 1);
  return { from, to: Math.max(from, to) };
}
