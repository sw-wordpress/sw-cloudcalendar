# Templates

Templates change presentation, not provider access or source visibility. Open **Calendar → Templates** to use the preset gallery or create/edit a template.

## Start with a preset

The repository includes Classic List, Image Right List, Monthly List, Date Badge List, Event Cards, and Month Grid presets. Use the preset gallery to create its templates, then select the matching container/event templates in **Calendar → Views → Display & templates**. Select its detail template if required. Keeping **Preset (default)** instead uses the display mode's built-in preset.

- **Container**: the outer frame, events and navigation.
- **Event**: one event, including an event inside a month day cell.
- **Detail**: the opened event details, independent of display mode.

The default event details are a centred, scrollable dialog, with a two-column description/image/date layout on larger screens and one column on mobile. Existing custom detail templates are not replaced automatically.

## Visual editor

1. Create a template of the needed kind and give it a name.
2. Use the visual block list to arrange its supported content. Adjust block options and CSS class settings instead of editing generated markup.
3. Check **Preview** in the relevant display mode. Template previews use example data; a view's live preview uses real accessible events.
4. Resolve validation errors, **Save**, then select the template in a view and check the actual page.

Use the CSS tab for presentation and the reference panel for available fields. The view's primary and source colours are exposed as CSS variables, including `--swc-primary` and `--swc-event-color`; the editor and [technical template reference](https://github.com/sw-wordpress/sw-cloudcalendar/blob/master/docs/templates.md) describe them.

## HTML editor and limits

Switching to HTML shows a confirmation; a saved template can also be duplicated as HTML before customising it. Arbitrary HTML changes cannot be converted back to visual blocks. Returning to visual mode is possible only while the markup still matches its visual schema; otherwise create a new visual template.

The language is deliberately limited: registered placeholders and conditional sections, no PHP/JavaScript, loops, partials or function calls. Unknown placeholders, unsafe HTML and forbidden CSS are validation errors. Do not paste scripts, external font imports or CSS `url()` rules. Use the editor's line/column errors and placeholder reference rather than bypassing validation. The existing [syntax and placeholder documentation](https://github.com/sw-wordpress/sw-cloudcalendar/blob/master/docs/templates.md) is the technical source of truth.

For templates created from presets, resetting markup or CSS restores that part from the preset in the editor. Review and save to retain the reset; preserve your customisation separately if needed. Templates still referenced by views cannot be deleted.

## Which template is used?

The event-template precedence is: approved embedding-time `template`, mode-specific template, view-wide template, built-in preset for that mode. Container templates have no embedding-time override; detail templates are shared across modes.

A template chosen for the entire view stays selected when visitors switch modes. Use **Templates per display mode** for a different Cards layout while List/Month inherit the common settings. **Alternative event templates** only grants additional choices to the embed's `template` parameter, not a visitor-facing template selector.
