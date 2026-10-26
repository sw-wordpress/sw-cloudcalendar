# Template-Sprache

Templates sind HTML mit einer bewusst kleinen, Mustache-ähnlichen Syntax. Es gibt **kein** PHP, kein JavaScript,
keine Schleifen, Partials, Filter oder Funktionsaufrufe. Templates werden beim Speichern vollständig validiert und in
einen serialisierbaren AST übersetzt (kein generierter Code).

## Syntax

| Form | Bedeutung |
|---|---|
| `{{event.title}}` | Variable; wird passend zum Kontext escaped |
| `{{#event.location}}…{{/event.location}}` | Abschnitt, wenn der Wert nicht leer/wahr ist |
| `{{^event.location}}…{{/event.location}}` | invertierter Abschnitt (Wert leer/falsch) |

Regeln:

- Nur registrierte Platzhalter (Liste unten). Unbekannte Namen, `{{{…}}}`, `{{> …}}`, `{{! …}}` sind Fehler.
- Abschnitte müssen innerhalb desselben Elements geschlossen werden (`{{#a}}<div>{{/a}}</div>` ist ungültig).
- Erlaubte Elemente: `div span p a strong em b i u s small br hr wbr h1–h6 ul ol li dl dt dd article section header
  footer aside nav main figure figcaption img time address blockquote q cite abbr mark sup sub code table thead tbody
  tfoot tr th td caption details summary`. Alle Elemente müssen korrekt geschlossen sein.
- Erlaubte Attribute: global `class id title lang dir role hidden tabindex style aria-* data-*`; `a`: `href target rel
  hreflang`; `img`: `src alt width height loading decoding`; `time`: `datetime`; Tabellen: `colspan rowspan headers
  scope`; `ol`: `start reversed type`; `details`: `open`; `blockquote/q`: `cite`. Event-Handler (`on…`) sind verboten.
- **Kontexte:** In Textknoten und normalen Attributen wird HTML-escaped. `href`/`src`/`cite` enthalten entweder eine
  statische sichere URL (`http`, `https`, `mailto`, `tel`, relativ) oder **genau einen** URL-Platzhalter. Im
  `style`-Attribut sind nur Farbplatzhalter (`{{event.color}}`) erlaubt, das statische CSS wird geprüft.
  HTML-Komponenten (`…gallery`, `…file_list`, `calendar.events`, `event.description_html`) nur als Textknoten.
- `target="_blank"` bekommt automatisch `rel="noopener noreferrer"`. HTML-Kommentare werden entfernt.
- CSS: keine `url()`, `@import`, `@font-face`, `expression()`, Escapes oder `<`. Alle Selektoren werden auf die
  Klasse des Templates (`.swc-tpl-{id}`) begrenzt; `@media`, `@supports`, `@container`, `@keyframes` sind erlaubt.

## Platzhalter

Alle Arten: `calendar.title`, `calendar.timezone`, `calendar.range_label`, `calendar.count`, `calendar.has_events` (Flag).

Container zusätzlich: `calendar.events` (gerenderte Liste/Karten/Monatsraster), `calendar.navigation` (Navigation:
„Vorheriger/Nächster Monat“ im Monatsraster, „Frühere/Weitere Termine“ bei Liste und Karten; leer, wenn die Navigation
in der Ansicht oder per `navigation="false"` ausgeschaltet ist). Enthält ein Container-Template den Platzhalter nicht,
fügt das Plugin die Navigation automatisch ein (über dem Monatsraster, unter Listen und Karten).

Event und Detail zusätzlich:

| Platzhalter | Typ |
|---|---|
| `event.id`, `event.title`, `event.description`, `event.excerpt`, `event.location`, `event.calendar_name` | Text |
| `event.start_date`, `event.start_time`, `event.end_date`, `event.end_time`, `event.date_range`, `event.day`, `event.month_short`, `event.weekday` | Text (lokalisiert, Zeitzone der Ansicht; Zeiten leer bei ganztägigen Terminen; Enddatum inklusiv) |
| `event.start_iso`, `event.end_iso` | Text (ISO 8601 bzw. Datum bei ganztägigen Terminen) – für `<time datetime>` |
| `event.url` (Link beim Anbieter), `event.detail_url`, `event.image` (lokales Vorschaubild) | URL |
| `event.color` | Farbe `#rrggbb` |
| `event.all_day`, `event.multi_day`, `event.tentative`, `event.has_images`, `event.has_files` | Flag |
| `event.description_html`, `event.attachments.gallery`, `event.attachments.file_list` | HTML-Komponente |

## Beispiel

```html
<article class="swc-event">
  <h3><a href="{{event.detail_url}}" data-swc-detail="">{{event.title}}</a></h3>
  <time datetime="{{event.start_iso}}">{{event.date_range}}</time>
  {{#event.location}}<p>{{event.location}}</p>{{/event.location}}
  {{^event.location}}<p>Ort wird bekanntgegeben</p>{{/event.location}}
  {{event.attachments.gallery}}
  {{event.attachments.file_list}}
</article>
```

`data-swc-detail` auf einem Link öffnet die Detailansicht im Dialog (mit JavaScript), sonst navigiert er zur Detailseite.

## Cache

Der AST wird unter `swc:tpl:{id}:{sha256(body|css|engine|allowlist)}` gespeichert; jede Änderung an Body, CSS, Engine-
oder Allowlist-Version ergibt einen neuen Schlüssel. Ein beschädigter Eintrag führt zu einer Neukompilierung.
