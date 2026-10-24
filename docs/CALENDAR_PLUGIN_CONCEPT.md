# WordPress Calendar Integration – Implementierungskonzept

**Status:** Entwurf 1.0 · **Sprache der Oberfläche:** übersetzbar (Deutsch/Englisch) · **Ziel:** Read-only-Darstellung externer Kalender in WordPress.

## 1. Ziel und Abgrenzung

Ein WordPress-Plugin nach dem Grundprinzip von Simple Calendar, das Google Calendar, Microsoft 365/Exchange Online und ICS-Feeds einbindet. Termine werden extern gepflegt und lokal synchronisiert. Kalender können öffentlich oder auf WordPress-Benutzer/Rollen beschränkt sein. **Einzelne Termine haben keine eigenen Zugriffsrechte.** Ein beim Provider privater Kalender kann nach ausdrücklicher Konfiguration in WordPress öffentlich veröffentlicht werden.

Nicht Bestandteil von 1.0: Terminbearbeitung oder Rückschreiben an Provider, Buchungen, Teilnehmerverwaltung, JavaScript in Templates, per Event differenzierte Rechte, Exchange On-Premises.

## 2. Begriffe und Domänenmodell

- **Connection:** OAuth-Verbindung oder ICS-Konfiguration; Geheimnisse und Token werden getrennt von sichtbaren Einstellungen gespeichert.
- **Calendar Source:** Ein externer Kalender mit Provider-ID, Verbindung, Sync-Status, Zugriffsregel, Attachment-Policy, Zeitzone und Sync-Fenster.
- **Event:** Normalisierter Termin inklusive Provider-ID, Serien-ID, Instanz-ID, Titel, Beschreibung, Ort, Start/Ende, Zeitzone, Ganztagsflag, Status, Original-URL und Änderungszeit.
- **Attachment:** Provider-Referenz, Dateiname, MIME-Typ, Größe, Version, lokaler Pfad, Prüfsumme, Downloadstatus und Freigabestatus.
- **Calendar View:** Gespeicherte Zusammenstellung von Quellen, Filtern, Ansicht, Limit, Sortierung und Template-Zuweisungen.
- **Template:** Versioniertes Layout für Kalendercontainer, Event-Karte/-Zeile und Event-Details.

## 3. Funktionsumfang und Priorität

### P0 – Version 1.0

1. **Provider:** Google Calendar API, Microsoft Graph für Microsoft 365 und ICS-URL; mehrere Verbindungen und Quellen.
2. **Authentifizierung:** OAuth 2.0 mit sicherem State/PKCE soweit unterstützt, Token-Erneuerung, Verbindungstest, Trennung und Fehlerstatus; für öffentliche Google-Kalender ggf. API-Key-Modus. Microsoft-Berechtigungen nach Least-Privilege, klare Unterscheidung delegiert/App-only.
3. **Synchronisierung:** initialer und regelmäßiger Import, manuelle Aktualisierung, inkrementelle Abfragen wo möglich, Paging, Retry/Backoff, Zeitfenster, gelöschte/abgesagte Termine und Serienausnahmen.
4. **Anzeigen:** Monatsansicht, responsive Agenda/Liste, Event-Details, Kalenderfarben, Zeitzonen und Lokalisierung; kombinierte Quellen.
5. **Einbindung:** Gutenberg-Block und Shortcode; mehrere Views pro Website; Live-Vorschau im Backend.
6. **Filter:** Quelle, Zeitraum, kommende Tage, Text, Ort, Kategorie/Tag (falls gepflegt), Limit, Sortierung, vergangene Termine, Template und Sichtbarkeit von Bildern/Dateiliste.
7. **Templates:** Presets (Classic List, Cards, Month, Timeline-artige Liste), visueller Editor, Custom-HTML-Blöcke und eigenständiger HTML-Editor, Live-Vorschau, CSS pro Template.
8. **Attachments:** Dateien synchronisieren und lokal speichern; Bilder als Galerie/Inline-Bilder, übrige Dateitypen als Liste mit Download, Dateiicons, Name und Größe. Auslieferung ausschließlich über WordPress-kontrollierte URLs/Endpunkte.
9. **Zugriff:** Kalender öffentlich, nur angemeldete Benutzer oder ausgewählte Rollen; keine individuellen Eventrechte. Filter und Shortcodes dürfen Zugriffsrechte nicht erweitern.
10. **Cache:** persistente Event-Daten, Cache für geparste Templates und optionaler gerenderter HTML-Cache; gezielte Invalidierung und Statusanzeige.
11. **Betrieb:** WP-Cron, optional echter Cron/WP-CLI, Logging, Sync-Status, Health-Checks, Migrationen, Deinstallation mit Datenlöschoption.

### P1 – nach 1.0

- Wochen-/Tagesansicht, Mini-Kalender und Sidebar-Widget.
- Template-Import/-Export, Versionshistorie, weitere Presets.
- Webhook-/Push-basierte Synchronisierung zusätzlich zum Polling.
- ICS-Export der zusammengeführten freigegebenen Kalender.
- Bildoptimierung und alternative Galerie-Layouts.
- Detailfilter, Suchoberfläche, strukturierte Daten (schema.org/Event), PDF-Vorschau.

## 4. Zugriffs- und Veröffentlichungsmodell

- **Provider-Privatheit ≠ WordPress-Sichtbarkeit.** Ein privat angebundener Kalender kann öffentlich in WordPress erscheinen, wenn die Quelle ausdrücklich auf `public` gesetzt wird.
- Zugriff gilt für **gesamte Quellen**, nicht pro Termin. Bei Views mit gemischten Quellen werden unberechtigte Quellen vollständig ausgeschlossen; wenn keine Quellen übrig bleiben, keine Daten ausgeben.
- Drei Modi: `public`, `authenticated`, `roles` (explizite Rollenliste). Zugriff zentral im Service prüfen, auch in REST, AJAX, Shortcode, Feeds und Downloads.
- Im Backend vor Veröffentlichung privater Provider-Kalender eine deutliche Bestätigung anzeigen.
- **Attachment-Policy pro Source:** `sync_attachments` (bool), `public_only` (bool, standardmäßig true), maximaler Dateiumfang, zulässige MIME-Typen, Bilder anzeigen, Dateiliste anzeigen.
- `public_only` bedeutet: Nur nachweislich öffentlich zugängliche Dateien synchronisieren. **Unbekannte Freigabe = nicht öffentlich**. Die Ermittlung ist providerabhängig; Kalender-Sichtbarkeit oder ein teilbarer Link allein sind kein Beweis. Falls keine verlässliche Prüfung möglich ist, den Anhang überspringen und protokollieren.
- Ist `public_only=false`, dürfen authentifiziert abrufbare Anhänge lokal synchronisiert werden. Ihre WordPress-Auslieferung richtet sich nach dem Source-Zugriff. Bei `public` muss die Veröffentlichung privater Dateien separat explizit bestätigt werden.
- Für private Quellen keine öffentlichen HTML-Caches; auch bei Cache-Hit Zugriff **vor** Auslieferung prüfen. Öffentliche Seiten dürfen keine geheimen Download-Tokens enthalten.

## 5. Attachment-Pipeline

1. Event synchronisieren, Anhangsmetadaten und Provider-Referenzen erfassen.
2. Freigabe gemäß Source-Policy überprüfen; unterstützte Typen/Größen prüfen.
3. Download serverseitig über authentifizierte Provider-Adapter; bei Cloud-Links erforderliche Drive-/OneDrive-/SharePoint-Rechte getrennt berücksichtigen. Google Workspace-Dateien ggf. exportieren.
4. Temporär schreiben, tatsächlichen MIME-Typ und Größe prüfen, Prüfsumme bilden, atomar in privaten Storage verschieben.
5. Metadaten/Versionen persistieren; bei unveränderten Dateien erneuten Download vermeiden.
6. Bilder: Vorschaubilder über WordPress-Image-Editor erzeugen, EXIF-Orientierung beachten; SVG nicht inline rendern. Andere Dateien: Downloadliste.
7. Auslieferung per Controller mit Source-Zugriffsprüfung, sicheren `Content-Type`, `Content-Disposition`, `X-Content-Type-Options: nosniff`, geeigneten Cache-Headern und Streaming; Range-Requests optional.
8. Bei Entfernung, Widerruf, Quellendeaktivierung oder Policy-Änderung Zugriff sofort sperren; lokale Dateien kontrolliert bereinigen.

**Speicherort:** separater, gegen direkten Webzugriff geschützter Bereich; nicht ungeschützt in `/uploads/`. Bei Shared Hosting Schutz tatsächlich testen und ansonsten sicher fehlschlagen. Keine regulären Mediathek-Einträge notwendig. Gleiche Dateien dürfen optional per Hash dedupliziert werden, ohne Zugriffsgrenzen zu vermischen.

## 6. Template-System

- Templates als HTML mit einfacher, dokumentierter Mustache-artiger Syntax; **keine Twig- oder PHP-Ausführung**.
- Platzhalter z. B. `{{event.title}}`, `{{event.start_date}}`, `{{event.location}}`, `{{calendar.title}}`.
- Truthy-Abschnitte: `{{#event.location}}...{{/event.location}}`; optional invertierte Abschnitte. Keine beliebigen Funktionsaufrufe, DB-Zugriffe oder freie Skripte.
- Plugin-kontrollierte Renderer für `{{event.attachments.gallery}}`, `{{event.attachments.file_list}}` und Event-Listen; sichere HTML-Ausgabe nur für intern freigegebene Komponenten.
- HTML-Editor mit Syntax-Highlighting, Platzhalter-Hilfe, Validierung und Vorschau. Visual Editor mit Komponenten und Custom-HTML-Block. Eigenständige HTML-Templates müssen **nicht** verlustfrei in den visuellen Editor zurückkonvertierbar sein.
- HTML/CSS gemäß WordPress-Berechtigungen (`unfiltered_html`) und Allowlist validieren; JavaScript und Event-Handler standardmäßig unterbinden. Kontextbezogenes Escaping (HTML-Text, Attribute, URL) und Sanitizing externer Beschreibungen.
- Geparstes Template als serialisierbarer AST, **kein generierter PHP-Code**. Cache-Key aus Template-ID, Inhalts-Hash, Engine-Version und ggf. Locale. Bei Änderungen invalidieren.

Beispiel:

```html
<article class="calendar-event">
  <h3>{{event.title}}</h3>
  <time>{{event.start_date}}</time>
  {{#event.location}}<p>{{event.location}}</p>{{/event.location}}
  {{event.attachments.gallery}}
  {{event.attachments.file_list}}
</article>
```

## 7. Shortcodes und Blockattribute

```text
[sw_calendar id="12"]
[sw_calendar id="12" view="list" limit="5" days="30"]
[sw_calendar id="12" source="2,3" search="Gottesdienst"]
[sw_calendar id="12" start="2026-11-01" end="2026-12-31" location="Kirche"]
[sw_calendar id="12" template="7" show_images="true" show_attachments="false"]
```

- Erlaubte Parameter über Schema validieren und typisieren, maximale Zeitfenster und Limits begrenzen.
- `source` darf nur Quellen **einschränken**, nicht nicht zugewiesene Quellen ergänzen; ebenso darf `template` nur zulässige Templates auswählen.
- Filter gelten serverseitig vor Rendering; sie sind keine Sicherheitsgrenze.
- Für Listenansichten deterministische Sortierung nach Startzeit und Event-ID.
- Filterwerte werden normalisiert und gehen in Render-Cache-Keys ein.

## 8. Datenhaltung

Empfohlen: eigene Tabellen (Präfix dynamisch) für `connections`, `sources`, `events`, `event_occurrences` (wenn benötigt), `attachments`, `views`, `templates`, `sync_runs` und ggf. `render_cache_index`.

Indizes mindestens auf `(source_id, external_id)`, `(source_id, start_utc)`, `(start_utc, end_utc)`, `(event_id, provider_attachment_id)`; Serieninstanzen benötigen stabile zusammengesetzte IDs. Secrets verschlüsselt bzw. durch geeignete Key-Management-Strategie geschützt speichern; WordPress-Salts allein ersetzen keinen dedizierten Secrets-Schutz bei Datenbank- und Dateisystemkompromittierung.

Wiederholungen mit Ausnahmen, abgesagte Einzelinstanzen, Sommerzeitwechsel, ganztägige Termine (Ende oft exklusiv) und Zeitzonen explizit modellieren. Keine unbeschränkte Expansion endloser Serien: nur konfiguriertes Sync-/Abfragefenster.

## 9. Synchronisierungsarchitektur

- Adapterinterface: `connect/test`, `listCalendars`, `fetchChanges`, `fetchAttachmentMetadata`, `downloadAttachment`, `checkPublicAccess` soweit technisch verfügbar.
- Provider-spezifische Fähigkeiten deklarieren; nicht jede Quelle kann alle Methoden zuverlässig anbieten.
- Google: Events API mit Pagination und Sync-Token, einschließlich Full-Resync bei ungültigem Token; Drive API für Dateiinhalte/-Freigaben.
- Microsoft: Graph Calendar View/Delta entsprechend API-Fähigkeiten; Attachments und Referenzanhänge getrennt behandeln, OneDrive/SharePoint bei Bedarf.
- ICS: ETag/Last-Modified und periodischer Abruf, vollständiger Vergleich anhand UID + RECURRENCE-ID; keine verlässliche providerübergreifende inkrementelle API.
- Synchronisierung transaktional bzw. mit Staging: erst nach erfolgreichem vollständigem Lauf gelöschte Events entfernen. Bei Fehlern letzte gültige Daten behalten.
- Hintergrundjobs idempotent, Lock pro Quelle, Retry mit exponentiellem Backoff, 429/Retry-After respektieren, Rate Limits und maximale Laufzeiten berücksichtigen.
- Attachments asynchron und in begrenzten Batches verarbeiten; Sync darf Frontend-Anfragen nicht blockieren.
- WP-CLI: `wp sw-cloudcalendar sync [--source=<id>]`, `wp sw-cloudcalendar attachments sync`, `wp sw-cloudcalendar cache clear`.

## 10. Cache-Strategie

1. **Provider → WordPress:** Event-Daten dauerhaft in Tabellen, Anhänge im geschützten Dateispeicher. Sync-Intervall je Quelle (z. B. 15 Minuten); keine API-Aufrufe im Frontend.
2. **Template-AST:** persistenter Cache nach Template-Hash und Engine-Version; Speicherung über Transients oder geeigneten persistenten Store, bei Größen-/Lastgrenzen ggf. eigene Tabelle.
3. **Gerendertes HTML:** optional pro View, normalisierten Filtern, Quellenrevisionen, Template-Version, Locale, Zeitzone und Access-Scope. Nur bei identischer Autorisierung wiederverwenden.

Invalidierung bei Sync-Änderungen, Template-/View-/Policy-Änderungen und Ablauf zeitabhängiger Ansichten. `next events` darf nach Eventbeginn/-ende nicht veraltet bleiben. Bei Berechtigungsänderung sofortiger Zugriffsentzug vor Cache-Lookup. Externe Full-Page-Caches können rollenbeschränkte Ansichten dennoch offenlegen: entsprechende Seiten von öffentlichen Seiten-Caches ausnehmen oder dynamisch geschützt nachladen.

## 11. Administration und UX

Menü **Kalender** mit Bereichen: Verbindungen, Quellen, Ansichten, Templates, Synchronisierung, Einstellungen, Diagnose.

**Quellenformular:** Provider, Kalender-ID, OAuth-Verbindung, Sichtbarkeit (öffentlich / angemeldet / Rollen), Sync-Intervall, Zeitfenster, Farben, Anhänge synchronisieren, nur öffentliche Anhänge, Maximalgröße, erlaubte Typen, explizite Veröffentlichung privater Dateien, Test und Jetzt synchronisieren.

**View-Editor:** Quellenauswahl, Filterdefaults, Layout, Template, Datum/Uhrzeit, Farben, Shortcode kopieren, Live-Vorschau.

**Template-Editor:** Preset wählen, duplizieren, Visual/HTML-Modus, CSS, dynamische Felder, Testdatensätze, Vorschau, Validierung.

**Diagnose:** Letzter Sync, neue/geänderte/gelöschte Termine, Attachment-Fehler, belegter Speicher, API-Fehlercodes ohne Token, Cache-Status, WP-Cron-Status.

## 12. Sicherheits- und Qualitätsanforderungen

- Nonces und Capabilities für Admin-Aktionen; alle Eingaben validieren, Ausgaben escapen; Prepared Statements.
- OAuth-State prüfen, Tokenzugriff minimieren, Logs ohne Secrets; bei Trennung Tokens widerrufen soweit unterstützt.
- ICS-URL-Abruf gegen SSRF absichern: erlaubte Schemes, DNS/IP-Prüfung auch nach Redirects, Timeouts, Größenlimits, keine internen Netze.
- Attachment-Downloads gegen Path Traversal, MIME-Spoofing, ZIP-Bombs, unsichere SVG/HTML-Auslieferung und öffentliche Direktzugriffe schützen.
- Keine providerseitigen Freigaberechte automatisch auf WordPress übertragen: Veröffentlichung ist eine bewusste administrative Entscheidung.
- Datenschutz: Aufbewahrung, Löschen, Export, Verbindungsentfernung und Logs dokumentieren.
- Tests: Unit-Tests für Parser, Filter, ACL und Recurrence; Integrations-Tests für Provider-Mocks, Sync/Retry, Download; E2E für Admin und Frontend.

## 13. Abnahmekriterien 1.0

1. Google-, M365- und ICS-Quellen können eingerichtet, getestet und regelmäßig synchronisiert werden.
2. Monats- und Listenansicht zeigen kombinierte Kalender einschließlich Serienausnahmen und Zeitzonen korrekt.
3. Views funktionieren als Gutenberg-Block und Shortcode; Filter ändern nur erlaubte Daten.
4. Visuelle Presets und eigenes HTML mit Platzhaltern, Bedingungen, CSS und Vorschau funktionieren.
5. Anhänge werden lokal heruntergeladen; Bilder erscheinen inline/als Galerie, andere Dateien in einer Liste und werden durch WordPress ausgeliefert.
6. `public_only` überspringt Anhänge ohne verlässlich bestätigte öffentliche Freigabe; private Downloads erfordern korrekte Source-Autorisierung.
7. Nach Sperrung einer Quelle sind deren Events und Downloads sofort unzugänglich, auch bei vorhandenem Cache.
8. Ein Provider-Ausfall lässt die zuletzt gültigen synchronisierten Daten verfügbar; Fehler werden diagnostizierbar protokolliert.
9. Template-Parsing und HTML-Rendering sind gecacht; Cache wird bei Änderungen und Zeitgrenzen korrekt invalidiert.
10. Keine externen API-Anfragen bei regulären Frontend-Seitenaufrufen.

## 14. Empfohlene Implementierungsreihenfolge

1. Plugin-Bootstrap, Datenbankschema, Capabilities, Providerinterfaces.
2. ICS-Adapter und normalisierte Events inklusive Wiederholungen.
3. View-Modell, REST-Lese-API, Shortcode, Monats-/Listenansicht.
4. Template-Parser/AST, Presets, HTML-Editor und Vorschau.
5. Google- und Microsoft-Adapter mit OAuth und Sync-Strategien.
6. Attachment-Pipeline inklusive Freigabeprüfung, lokaler Speicherung und geschützter Auslieferung.
7. Visueller Template-Editor, Gutenberg-Block, Caches und Invalidierung.
8. WP-CLI, Diagnose, Migrationen, Security-Tests und Release-Vorbereitung.

## 15. Offene Entscheidungen vor Implementierung

- Plugin-Slug und PHP-Namespace (`sw-cloudcalendar` als Arbeitstitel).
- Mindestversionen für WordPress/PHP und unterstützte Hosting-Umgebungen.
- FullCalendar oder alternative Frontend-Bibliothek (Lizenz und Bundle-Größe prüfen).
- Umfang der OAuth-Registrierung: eigene App-Credentials pro Installation oder zentraler Auth-Broker.
- Welche Provider-Anhangstypen und öffentlichen Freigaben lassen sich verlässlich prüfen? Fehlende Fähigkeiten müssen in der UI transparent werden.
- Maximale Downloadgrößen und Speicherquoten; automatische Bereinigung nach Quellentrennung.
- Definition lokaler Kategorien und Zuordnung von Provider-Metadaten.

**Leitprinzip:** Externe Kalender read-only synchronisieren; in WordPress flexibel darstellen, aber Veröffentlichung und Dateizugriff konsequent auf Ebene der Kalenderquelle absichern.
