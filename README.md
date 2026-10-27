# SW CloudCalendar

WordPress-Plugin zur **read-only** Einbindung von Google Calendar, Microsoft 365 (Exchange Online) und ICS-Feeds.
Termine werden im Hintergrund lokal synchronisiert und mit frei gestaltbaren Vorlagen als Liste, Karten oder
Monatsraster angezeigt – per Shortcode oder Gutenberg-Block. Anhänge werden auf Wunsch lokal gespeichert und nur
von WordPress ausgeliefert. Sichtbarkeit gilt immer für eine ganze Kalenderquelle: öffentlich, angemeldete Benutzer
oder ausgewählte Rollen.

- Spezifikation: `docs/SPECIFICATIONS.md`, Konzept: `docs/CALENDAR_PLUGIN_CONCEPT.md`
- Entscheidungen und offene Punkte: `docs/decisions.md`
- REST-API: `docs/rest-api.md`, Template-Sprache: `docs/templates.md`
- Entwicklung/Tests: `docs/development.md`, `docs/testing.md`

## Voraussetzungen

WordPress 6.5+, PHP 8.2+ mit `sodium` (Pflicht), `fileinfo` (für Anhänge dringend empfohlen), GD oder Imagick
(Vorschaubilder), MySQL 8 / MariaDB 10.6 mit InnoDB.

## Admin-Oberfläche bauen

Im Plugin-Verzeichnis `make build` ausführen (benötigt Make, Node.js >=18.18 und npm).
Beim ersten Build und nach Änderungen an `apps/admin/package.json` oder der zugehörigen
Lockdatei werden die Abhängigkeiten automatisch mit `npm ci --include=dev` installiert.
Der bestehende Build prüft TypeScript und schreibt die Admin-Assets nach `assets/admin/`.

## Empfohlene Konfiguration (`wp-config.php`)

```php
// Eigener Schlüssel für gespeicherte Zugangsdaten (32 Byte, base64 oder beliebige lange Zeichenkette).
define( 'SW_CALENDAR_ENCRYPTION_KEY', '…' );
// Ablage für Anhänge AUSSERHALB des Webroots.
define( 'SW_CALENDAR_PRIVATE_STORAGE_PATH', '/var/lib/wordpress-private/sw-cloudcalendar' );
// Optional: größeres Synchronisationsfenster (Tage je Richtung, Standard 1095).
// define( 'SW_CALENDAR_MAX_WINDOW_DAYS', 1095 );
```

Ohne eigenen Schlüssel wird ein Schlüssel aus den WordPress-Salts abgeleitet (Warnung in der Diagnose). Ohne
Speicherpfad legt das Plugin ein zufälliges Verzeichnis unter `uploads/` an und akzeptiert es nur, wenn ein
HTTP-Test beweist, dass Dateien darin nicht abrufbar sind (unter Nginx in der Regel eine eigene `location`-Regel
nötig); andernfalls bleibt der Anhangs-Sync gesperrt.

## Einrichtung

1. **Verbindung anlegen** (Kalender → Verbindungen)
   - *ICS*: Feed-URL (HTTPS; `webcal://` wird umgewandelt), optional Authorization-Header.
   - *Google*: In der Google Cloud Console ein OAuth-Client vom Typ „Webanwendung“ anlegen, Calendar API (und für
     Anhänge die Drive API) aktivieren, die angezeigte Redirect-URI eintragen; Client-ID und -Secret im Plugin
     speichern und „Autorisieren“. Alternativ API-Schlüssel für bei Google öffentliche Kalender.
     `drive.readonly` ist ein eingeschränkter Scope und kann eine App-Verifizierung erfordern.
   - *Microsoft 365*: In Entra ID eine App-Registrierung (Web) mit der Redirect-URI anlegen, delegierte
     Berechtigungen `Calendars.Read`, `User.Read`, `offline_access`, Client-Secret erzeugen; Tenant, Client-ID und
     Secret im Plugin speichern und „Autorisieren“. Freigegebene Postfächer und Gruppenkalender sind in V1 nicht
     unterstützt.
2. **Quelle anlegen**: Kalender wählen, Sichtbarkeit festlegen, Zeitfenster und Intervall, ggf. Anhänge. Neue Quellen
   sind Entwürfe; erst „Aktiv“ synchronisiert und zeigt sie an. Einen beim Anbieter privaten Kalender öffentlich zu
   zeigen erfordert eine ausdrückliche Bestätigung (wird protokolliert).
3. **Ansicht anlegen**: Quellen, Darstellung, Vorlagen und Filtervorgaben; den Shortcode kopieren oder den Block
   „Kalender (SW CloudCalendar)“ verwenden.

## Shortcode

```text
[sw_calendar id="12"]
[sw_calendar id="12" view="list" limit="5" days="30"]
[sw_calendar id="12" source="2,3" search="Gottesdienst"]
[sw_calendar id="12" start="2026-11-01" end="2026-12-31" location="Kirche"]
[sw_calendar id="12" template="7" show_images="true" show_attachments="false"]
[sw_calendar id="12" navigation="false"]
```

Navigation: In der Monatsansicht „Vorheriger/Nächster Monat“, bei Liste und Karten „Frühere/Weitere Termine“
(frühere = bereits beendete Termine vor dem Anzeigezeitraum). Pro Ansicht abschaltbar, per `navigation` überschreibbar.

Filter können eine Ansicht nur einschränken, nie erweitern. Ansichten mit nicht-öffentlichen Quellen werden per
JavaScript nachgeladen und nie in (evtl. öffentlich gecachtes) Seiten-HTML geschrieben.

## Betrieb

- Jede Quelle wird standardmäßig alle 6 Stunden synchronisiert (einstellbar 1–48 Stunden, „Jetzt synchronisieren“
  jederzeit). Die Warteschlange prüft über WP-Cron alle 5 Minuten, ob etwas fällig ist. Für Seiten mit wenig Besuchern oder `DISABLE_WP_CRON` in den
  Einstellungen „System-Cron“ wählen und z. B. `* * * * * wp sw-cloudcalendar queue run` einrichten.
- WP-CLI: `wp sw-cloudcalendar sources list | connections test --id=1 | sync --source=3 | sync --all --full |
  attachments sync --source=3 | attachments prune --dry-run | cache clear | diagnostics | queue run`.
- Deaktivieren behält alle Daten. Beim Löschen des Plugins werden Daten nur entfernt, wenn „Daten bei Deinstallation
  löschen“ aktiviert ist (Google-Tokens werden dabei widerrufen).

## SonarQube Cloud

The test workflow uploads merged PHP Clover coverage and analyzes same-repository PRs
and pushes to the default branch. Configure the `SONAR_TOKEN` Actions secret and
`SONAR_ORGANIZATION` Actions variable (organization-level bindings may be reused).
Project mapping and source paths are in `sonar-project.properties`. Fork PRs run tests
without access to the scanner token. Coverage uses the existing PHPUnit scopes;
unmeasured application code is not excluded from SonarQube. Frontend tests and builds
run separately; JavaScript/TypeScript coverage is not collected by this PHP rollout.
Generated assets and third-party dependencies are not application analysis inputs.
