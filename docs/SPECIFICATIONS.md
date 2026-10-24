# SPECIFICATIONS.md — SW Calendar Integration

**Version:** 1.0.0-draft  
**Stand:** 2026-10-24  
**Status:** Implementierungsvorgabe / initiales Pflichtenheft  
**Bezug:** `CALENDAR_PLUGIN_CONCEPT.md`, `CODE-STYLE.md`
**Arbeitstitel:** SW CloudCalendar  
**Plugin-Slug:** `sw-cloudcalendar`
**PHP-Namespace:** `SW\Calendar`
**Zielsprache der UI:** Deutsch, vollständig internationalisierbar  
**Umsetzung:** WordPress-Plugin; read-only Integration von Google Calendar, Microsoft 365 und ICS

> **Normative Begriffe:** **MUSS** = zwingend, **SOLL** = regulär vorgesehen, **KANN** = optional. Die mit **[V1]** markierten Anforderungen sind für die Freigabe von Version 1.0 abzunehmen. Beispiele sind Verträge zur Implementierung, nicht ungeprüft produktionsfertiger Code.

## 1. Produktziel und Grenzen

Ein WordPress-Plugin, das externe Kalender periodisch synchronisiert, lokal speichert und in frei gestaltbaren WordPress-Kalenderansichten darstellt. Es bietet Gutenberg-Block, Shortcode, vorgefertigte Design-Presets, visuellen Template-Editor und direkt bearbeitbares HTML mit sicheren Platzhaltern. Unterstützte Dateianhänge werden **serverseitig synchronisiert, lokal gespeichert und durch WordPress bereitgestellt**. Bilder erscheinen direkt; andere Anhänge als Downloadliste.

**Explizite Entscheidungen:**

1. Es gibt **keine Berechtigungen pro Termin**. Sichtbarkeit gilt pro gesamter Kalenderquelle (`public`, `authenticated`, `roles`). Ein extern privater Kalender darf nach ausdrücklicher Bestätigung WordPress-öffentlich sein.
2. Ein Shortcode oder Block darf Quellen aus einer View **filtern, niemals unberechtigt hinzufügen**. Filter beeinflussen Darstellung, nicht Berechtigungen.
3. Anhänge werden nur lokal ausgeliefert; keine Cloud-Datei-URLs für öffentliche Downloads oder Bilder im Frontend. Die Quelloption **„Nur öffentliche Anhänge“** (`public_only`) ist unabhängig von der Kalender-Sichtbarkeit.
4. Template-Syntax ist bewusst eingeschränkt und Mustache-ähnlich, **ohne Twig, PHP, JavaScript oder beliebige Funktionen**. HTML wird im Editor direkt unterstützt.
5. Kein Live-Abruf der Provider-APIs während gewöhnlicher Frontend-Requests. Bei Providerausfall bleiben zuletzt erfolgreich synchronisierte Daten nutzbar.
6. Für öffentliche Ansichten darf gerendertes HTML global geteilt werden. Beschränkte Quellen müssen vor **jedem** Rendering, HTML-Cache-Hit und Download erneut autorisiert werden.

**Nicht in V1:** lokale Terminbearbeitung, Rückschreiben an Provider, Buchungssystem, individuelle Benutzerfilter innerhalb einer Quelle, Kalender-Webhooks, Exchange On-Premises, öffentliche ICS-Feeds, eigene Endbenutzer-OAuth-Verbindungen, beliebiges Skripting in Templates.

## 2. Technische Basis und Lieferobjekte

### 2.1 Festgelegte Mindestanforderungen

| Eigenschaft | Festlegung für das Projekt |
|---|---|
| WordPress | 6.5+ (Annahme; CI-Matrix um aktuelle stabile Version erweitern) |
| PHP | 8.2+; `strict_types=1` in eigenen Klassen |
| Datenbank | MySQL 8.0+ oder MariaDB 10.6+, WordPress-kompatibles `$wpdb` |
| Webserver | Apache und Nginx, auch Shared Hosting, sofern privater Dateispeicher zuverlässig abgesichert werden kann |
| PHP-Bibliotheken | Composer, namespaced bzw. projektisolierte Abhängigkeiten |
| Frontend | WordPress Block API + bevorzugt Vanilla JS; FullCalendar nur nach Lizenz-/Bundleprüfung |
| Admin | WordPress-Komponenten/React für den Block-Editor und Template-Builder, möglichst keine zweite große UI-Laufzeit |
| Zeitzone | Datenbank UTC, Darstellung anhand einer IANA-Zeitzone |
| Datenspeicher | separate Custom Tables für Eventdaten; eigene persistente Dateien für Attachments |

Die konkreten Mindestversionen sind bewusst Projektentscheidungen und keine Behauptungen über Mindestanforderungen externer APIs. Vor Release wird die Kompatibilitätsmatrix durch CI belegt.

### 2.2 Repository und Artefakte

```text
sw-cloudcalendar/
├── sw-cloudcalendar.php
├── uninstall.php
├── composer.json
├── package.json
├── readme.txt
├── LICENSE
├── SPECIFICATIONS.md
├── CALENDAR_PLUGIN_CONCEPT.md
├── src/
│   ├── Application/{Sync,Render,Attachments,Access,Cache}/
│   ├── Domain/{Connection,Source,Event,Attachment,View,Template}/
│   ├── Infrastructure/{Database,Storage,Provider,OAuth,Queue}/
│   ├── Presentation/{Admin,Rest,Shortcode,Block,Download,Cli}/
│   └── Support/
├── assets/{js,css}/
├── blocks/calendar/
├── templates/presets/
├── languages/
└── tests/{Unit,Integration,E2E,Fixtures}/
```

**Release-ZIP:** Ein Stammordner `sw-cloudcalendar/`, ohne `.git`, `node_modules`, Tests und Entwicklungsdateien; sämtliche für den Betrieb benötigten Composer-Abhängigkeiten und gebauten Assets sind enthalten. Keine Composer-Installation auf dem Zielserver erforderlich. WordPress Coding Standards, PHPCS, PHPStan, PHPUnit und End-to-End-Tests sind CI-Gates.

## 3. Domänenobjekte und fachliche Regeln

### 3.1 Connection

Verbindung zu Google, Microsoft 365 oder ICS; kann mehrere Kalenderquellen bereitstellen. Felder: `id`, `provider`, `name`, `auth_mode`, `status`, `provider_account_id`, `tenant_id`, `settings_json`, `credentials_ref`, `created_at`, `updated_at`. OAuth-Geheimnisse sind **nicht** Teil öffentlicher Source-/REST-Antworten.

Zustände: `disconnected`, `connecting`, `connected`, `reauth_required`, `failed`, `disabled`.

### 3.2 Calendar Source

Eine einzelne Provider-Kalender-ID mit `connection_id`, `external_calendar_id`, `title`, `color`, `timezone`, `visibility`, `allowed_roles`, `sync_enabled`, `sync_interval_minutes`, `past_days`, `future_days`, `attachment_policy`, `data_revision`, `last_success_at`, `status`.

Zustände: `draft`, `active`, `paused`, `error`, `disabled`. Beim Anlegen zunächst `draft`, bevor eine Quelle sichtbar wird.

**Sichtbarkeit:** `public` / `authenticated` / `roles`. `roles` enthält eine validierte, nicht leere Rollenliste. Bei Rollenänderung oder Deaktivierung sofortige Zugriffssperre und Revisionserhöhung.

### 3.3 Event / Occurrence

Für das Frontend ist jede konkrete Instanz eines Termins eine **Occurrence** mit stabiler Provider-Identität. Auch wiederkehrende Termine werden innerhalb eines begrenzten Sync-Fensters als Instanzen gespeichert.

Felder: `source_id`, `provider_event_id`, `series_id`, `recurrence_id`, `occurrence_key`, `title`, `description_html`, `description_text`, `location`, `external_url`, `start_utc`, `end_utc`, `original_tz`, `all_day`, `status`, `provider_updated_at`, `payload_hash`.

- Eine `occurrence_key` ist stabil für dasselbe Quellereignis und seine Wiederholungsinstanz, auch wenn deren Uhrzeit verschoben wird.
- Ganztägige Intervalle sind `[start, end)`, also inklusive Start / exklusivem Ende.
- Gelöschte Instanzen dürfen nach erfolgreichem Sync nicht mehr angezeigt werden.
- Wiederholungsregeln werden serverseitig in einem **begrenzten** Fenster expandiert; `EXDATE`, `RDATE`, `RECURRENCE-ID`, Änderungen einzelner Instanzen und Zeitzonen müssen berücksichtigt werden.
- Provider-spezifische Event-Arten, die keine veröffentlichbaren Termine darstellen, sind konfigurierbar auszuschließen. Bei Microsoft 365 dürfen private `sensitivity`-Eigenschaften nicht stillschweigend als WordPress-Sichtbarkeitsregeln interpretiert werden.

### 3.4 Calendar View

Konfiguration: `id`, `name`, `enabled`, `source_ids[]`, `default_filters`, `display_mode`, `list_template_id`, `event_template_id`, `detail_template_id`, `timezone`, `language`, `color_rules`, `data_revision`. `source_ids` bezeichnet die **maximal zugelassene** Quellenmenge der View.

### 3.5 Calendar Template

`id`, `name`, `kind` (`container`, `event`, `detail`), `editor_mode` (`visual`, `html`), `body`, `css`, `visual_schema_json`, `revision`, `updated_at`. Templates können von mehreren Views wiederverwendet werden. Visual und HTML sind gleichwertige Bearbeitungsarten, aber eine beliebige HTML-Definition muss nicht verlustfrei in visuelle Bausteine rückkonvertiert werden.

### 3.6 Attachment

Anhang einer Occurrence: `source_id`, `occurrence_id`, `provider_attachment_id`, `name`, `provider_mime`, `detected_mime`, `size_bytes`, `provider_version`, `sha256`, `storage_key`, `preview_key`, `is_image`, `publication_state`, `sync_state`, `last_checked_at`, `last_error`.

**Kein** regulärer Mediathek-Eintrag; Datei-IDs und Download-URLs dürfen keine internen Storage-Pfade verraten.

## 4. Datenbankschema [V1]

Tabellennamen werden ausschließlich über `$wpdb->prefix` gebildet. Migrationen mit `dbDelta()` und explizit versionierten Datenmigrationen; installierte Schemas werden über `sw_calendar_db_version` verfolgt. Alle Zeitfelder UTC in `DATETIME`; IDs `BIGINT UNSIGNED`. MySQL/MariaDB `utf8mb4`/WordPress-Collation. Keine SQL-Fremdschlüssel notwendig; referenzielle Konsistenz in Repositories/Services erzwingen.

### 4.1 Tabellen und Pflichtspalten

| Tabelle (`{prefix}` + ...) | Spalten zusätzlich zu `id` | Indizes |
|---|---|---|
| `swc_connections` | `provider VARCHAR(24)`, `name VARCHAR(190)`, `auth_mode VARCHAR(24)`, `status VARCHAR(24)`, `account_ref VARCHAR(190) NULL`, `settings_json LONGTEXT`, `created_at DATETIME`, `updated_at DATETIME` | `(provider,status)` |
| `swc_secrets` | `connection_id BIGINT`, `ciphertext LONGTEXT`, `key_version INT`, `updated_at DATETIME` | `UNIQUE(connection_id)` |
| `swc_sources` | `connection_id BIGINT`, `external_id VARCHAR(512)`, `name VARCHAR(190)`, `color CHAR(7)`, `timezone VARCHAR(64)`, `visibility VARCHAR(20)`, `roles_json TEXT`, `settings_json LONGTEXT`, `revision BIGINT`, `status VARCHAR(20)`, `last_success_at DATETIME NULL`, `created_at DATETIME`, `updated_at DATETIME` | `UNIQUE(connection_id, external_id(190))` nur falls Indexlängen/Kollisionen geprüft; bevorzugt `external_id_hash BINARY(32)` + `UNIQUE(connection_id,external_id_hash)`; `(status,visibility)` |
| `swc_occurrences` | `source_id BIGINT`, `occurrence_key CHAR(64)`, `provider_event_id VARCHAR(512)`, `series_id VARCHAR(512) NULL`, `recurrence_id VARCHAR(190) NULL`, `title TEXT`, `description_html LONGTEXT`, `description_text LONGTEXT`, `location TEXT`, `external_url TEXT`, `start_utc DATETIME`, `end_utc DATETIME`, `timezone VARCHAR(64)`, `all_day TINYINT`, `status VARCHAR(20)`, `payload_hash CHAR(64)`, `seen_generation BIGINT`, `updated_at DATETIME` | `UNIQUE(source_id,occurrence_key)`, `(source_id,start_utc)`, `(start_utc,end_utc)`, `(source_id,seen_generation)` |
| `swc_attachments` | `source_id BIGINT`, `occurrence_id BIGINT`, `provider_ref_hash CHAR(64)`, `provider_id VARCHAR(512)`, `filename VARCHAR(255)`, `mime VARCHAR(190)`, `size_bytes BIGINT`, `version_token VARCHAR(190) NULL`, `sha256 CHAR(64) NULL`, `storage_key VARCHAR(512) NULL`, `preview_key VARCHAR(512) NULL`, `is_image TINYINT`, `publication_state VARCHAR(24)`, `sync_state VARCHAR(24)`, `last_checked_at DATETIME NULL`, `last_error TEXT NULL` | `UNIQUE(occurrence_id,provider_ref_hash)`, `(source_id,sync_state)`, `(source_id,publication_state)` |
| `swc_views` | `name VARCHAR(190)`, `enabled TINYINT`, `config_json LONGTEXT`, `revision BIGINT`, `created_at DATETIME`, `updated_at DATETIME` | `(enabled)` |
| `swc_templates` | `name VARCHAR(190)`, `kind VARCHAR(24)`, `editor_mode VARCHAR(24)`, `body LONGTEXT`, `css LONGTEXT`, `schema_json LONGTEXT NULL`, `revision BIGINT`, `updated_at DATETIME` | `(kind)` |
| `swc_sync_state` | `source_id BIGINT`, `sync_mode VARCHAR(24)`, `cursor_ciphertext LONGTEXT NULL`, `window_start_utc DATETIME`, `window_end_utc DATETIME`, `generation BIGINT`, `status VARCHAR(24)`, `locked_until DATETIME NULL`, `next_attempt_at DATETIME NULL`, `last_success_at DATETIME NULL`, `last_error_code VARCHAR(80) NULL` | `UNIQUE(source_id)`, `(next_attempt_at)` |
| `swc_sync_runs` | `source_id BIGINT`, `started_at DATETIME`, `finished_at DATETIME NULL`, `status VARCHAR(24)`, `created_count INT`, `updated_count INT`, `deleted_count INT`, `attachment_count INT`, `error_code VARCHAR(80) NULL`, `message TEXT NULL` | `(source_id,started_at)` |
| `swc_jobs` | `source_id BIGINT NULL`, `job_type VARCHAR(48)`, `payload_json LONGTEXT`, `status VARCHAR(20)`, `attempts INT`, `run_after DATETIME`, `lock_until DATETIME NULL`, `idempotency_key CHAR(64)`, `last_error TEXT NULL` | `UNIQUE(idempotency_key)`, `(status,run_after)` |

**Datenbankkonventionen:** JSON-Schemata und max. Feldlängen vollständig validieren. Bei `external_id` stets voller Wert + SHA-256 für eindeutigen Index; nie stille Trunkierung. Große Textfelder nicht in jedem Listen-Query laden. `seen_generation` nur im erfolgreich validierten Snapshot aktualisieren. Datenlöschung per Repository-Kaskade in Transaktionen/Batches.

### 4.2 Event-Abfragevertrag

Die lokale Event-Abfrage MUSS **überlappende** Events finden, nicht nur solche mit Start im Fenster:

```sql
SELECT ... FROM wp_swc_occurrences
WHERE source_id IN (... erlaubte Source-IDs ...)
  AND start_utc < :range_end
  AND end_utc > :range_start
  AND status = 'confirmed'
ORDER BY start_utc ASC, source_id ASC, id ASC
LIMIT :validated_limit;
```

Für die aktuelle WordPress-Installation wird `wp_` durch `$wpdb->prefix` ersetzt; benutzerdefinierte Werte immer parameterisiert. Für ganztägige Events Start/Ende konsistent UTC-konvertieren, Anzeige im Kalender-Kontext berechnen. Halboffene Intervalle erlauben korrekte Tagesgrenzen.

## 5. PHP-Serviceverträge [V1]

```php
<?php
declare(strict_types=1);

namespace SW\Calendar\Domain\Provider;

interface CalendarProviderInterface {
    public function capabilities(): ProviderCapabilities;
    public function testConnection(Connection $connection): ConnectionTestResult;
    /** @return CalendarDefinition[] */
    public function listCalendars(Connection $connection): array;
    public function fetchChanges(Source $source, SyncCursor $cursor, SyncWindow $window): SyncPage;
    /** @return ProviderAttachmentDefinition[] */
    public function listAttachments(Source $source, ProviderEventReference $event): array;
    public function inspectAttachment(Source $source, ProviderAttachmentReference $attachment): AttachmentInspection;
    public function downloadAttachment(Source $source, ProviderAttachmentReference $attachment, DownloadTarget $target): DownloadResult;
}

interface SourceAccessInterface {
    public function canReadSource(int $sourceId, ?int $wpUserId): bool;
    /** @param int[] $sourceIds @return int[] */
    public function filterAllowedSources(array $sourceIds, ?int $wpUserId): array;
}

interface TemplateCompilerInterface {
    public function compile(string $template, string $kind): CompiledTemplate;
}

interface AttachmentStorageInterface {
    public function putFromTemp(string $temporaryFile, string $storageKey): StoredObject;
    public function openReadStream(string $storageKey): mixed;
    public function delete(string $storageKey): void;
}
```

Dies sind logische API-Verträge; die konkrete Platzierung von DTOs und Interfaces ist frei, solange Adapter und Services konsequent darauf testen. Provider-Capabilities müssen z. B. `incrementalSync`, `publicAttachmentVerification`, `cloudReferenceDownloads`, `fileAttachments`, `etag`, `recurrence` ausdrücken. Fehlende Capability darf nicht als erfolgreich implementierte Funktion erscheinen.

**Ausnahmen/Fehlercodes:** `AUTH_REQUIRED`, `TOKEN_EXPIRED`, `RATE_LIMITED`, `SYNC_CURSOR_INVALID`, `FETCH_FAILED`, `ATTACHMENT_UNSUPPORTED`, `PUBLIC_STATUS_UNKNOWN`, `DOWNLOAD_TOO_LARGE`, `STORAGE_UNSAFE`, `INVALID_TEMPLATE`, `ACCESS_DENIED`, `PROVIDER_UNSUPPORTED`. APIs verwenden maschinenlesbaren Fehlercode plus übersetzbare UI-Nachricht.

## 6. Provider-Implementierung und OAuth [V1]

### 6.1 Google Calendar

- Zugang per OAuth 2.0 Authorization Code mit State und PKCE, soweit für den verwendeten Clienttyp unterstützt. Für öffentliche Kalender zusätzlich optional Google-API-Key-Modus.
- Kalenderliste über CalendarList, Eventabruf über Calendar Events API; Pagination bis einschließlich finaler Seite verarbeiten.
- Inkrementeller Sync: `nextSyncToken` am **Ende** eines vollständigen Laufs persistieren; bei `410 Gone` vollständiger Neuaufbau. Mit `syncToken` sind insbesondere `timeMin`, `timeMax`, `q`, `orderBy`, `updatedMin` nicht zulässig. Implementierung MUSS deshalb **separate Strategie für einen dauerhaft gültigen Sync-Token und begrenzte lokale Expansion** besitzen, oder beim veränderlichen Sync-Zeitfenster kontrollierten Full-Rebuild wählen. Nicht denselben Cursor unzulässig für wechselnde Fenster verwenden.
- Geplante Referenzstrategie V1: Event-Master und Änderungen ohne zeitabhängige Serverfilter synchronisieren, wiederkehrende Serien lokal bis zum konfigurierten Sichtfenster expandieren; bei zu großen Kalendern vorher nachgewiesene begrenzte Alternative mit dokumentierter Full-Resync-Strategie einsetzen. Grenzen konfigurierbar und Fehler sichtbar, keine heimliche Verkürzung.
- Anhänge: Calendar liefert Metadaten; Google-Drive-Dateien über Drive API abrufen. Google-native Formate nach konfiguriertem Format exportieren (standardmäßig PDF), sofern möglich. Providerfremde URL-Anhänge ohne verifizierbare sichere Download-Methode als `unsupported` markieren.
- `public_only`: öffentliche Freigabe nachweisen (z. B. passende Drive-Permissions und tatsächliche Zugänglichkeit entsprechend Policy); weder freigegebene Kalender noch bloße `fileUrl` beweisen Öffentlichkeit.

### 6.2 Microsoft 365 / Microsoft Graph

- OAuth 2.0 Authorization Code mit PKCE für delegierte Berechtigungen; feste Redirect-URI, State-Validierung und Refresh-Token-Verwaltung. Eigenes App-Registrierungsmodell: Admin trägt Tenant/Client-ID ein; Client-Secret bzw. Public-Client-Modell gemäß sicher dokumentiertem Flow. V1 verlangt **keinen zentralen Cloud-Broker**.
- Lesen via Microsoft Graph v1.0. Benutzer- und Kalenderidentitäten explizit speichern; keine Verwechslung von `/me` mit fremden Mailboxen. Minimal erforderliche Calendar-Scopes je Zugriffsszenario; zusätzliche Datei-Scopes nur bei aktiviertem Attachment-Sync beantragen.
- Event-Instanzen über `calendarView`; inkrementell über `calendarView/delta`, wenn für den Zielkalender unterstützt. **`@odata.nextLink` und `@odata.deltaLink` als opake, unveränderte Fortsetzungs-URLs behandeln**, nicht selbst Tokens bauen. Das abgefragte Zeitfenster ist im Token gebunden: Bei Fensteränderung neuen Delta-Track beginnen und lokal vollständig abgleichen.
- Kalendergruppen, Shared Mailboxes und App-only-Zugriffe nur freigeben, wenn implementierte Scopes/Tenant-Regeln und Tests diese Fälle ausdrücklich abdecken. Nicht pauschal behaupten, jede M365-Kalenderart sei unterstützt.
- `fileAttachment`: lokal über Graph herunterladen; `referenceAttachment`: Verweis zu OneDrive/SharePoint mit separater Identifikation/Berechtigungsprüfung. `itemAttachment`: nur wenn sichere Abbildung auf unterstützten Download-Typ getestet ist; sonst klar `unsupported`.
- `public_only` für M365-Cloud-Referenzen nur bei zuverlässig nachweisbarem anonymen Zugriff. Bei unklarer Providerfähigkeit wird Datei ausgelassen. Ein fileAttachment in einer privaten Mailbox ist ohne weitere Freigabe nicht automatisch öffentlich.

### 6.3 ICS / iCalendar

- Lesende URL mit `HTTPS` als Default; optional Auth-Header nur wenn separat geschützt und explizit konfiguriert. Keine unsicheren Redirects.
- HTTP `ETag`, `Last-Modified`, `304` unterstützen, sofern vom Server geliefert.
- Vollständige Datenauswertung: `UID`, `DTSTART`, `DTEND`/`DURATION`, `RRULE`, `RDATE`, `EXDATE`, `RECURRENCE-ID`, `STATUS`, `TZID`, `VTIMEZONE`, `DESCRIPTION`, `LOCATION`, `URL`, `ATTACH`.
- ICS-ATTACH kann Inline-Binärdaten oder externe URLs enthalten; nur nach Allowlist, Größe, geprüftem MIME und Download-Policy übernehmen. `public_only` darf eine frei abrufbare Datei-URL nicht ohne sichere Prüfung akzeptieren.
- RFC5545-konforme Parser-Bibliothek, keine eigene ad-hoc-RRULE-Engine. Maximalzahlen für Feed-Größe, VEVENT-Anzahl, Recurrence-Expansion und Laufzeit.
- `UID + RECURRENCE-ID` bestimmt Instanzidentität; abgesagte Ausnahmen richtig behandeln. Erfolgreiches Vollsnapshot gegen lokale Occurrences diffen.

## 7. Synchronisationsmaschine [V1]

### 7.1 Ablauf

1. Quelle und Verbindung prüfen, Quell-Lock atomar erwerben; ohne Lock kein Parallel-Sync derselben Quelle.
2. Cursor, Fenster und Providerfähigkeiten lesen, passenden Full-/Delta-Pfad wählen.
3. **Alle** Seiten vollständig abrufen, Rohdaten validieren, normalisieren und in ein isoliertes Staging/temporäres Set überführen. Niemals bei halb gefülltem Resultset ältere Daten löschen.
4. Wiederkehrende Termine innerhalb des genehmigten Fensters expandieren; stabile Schlüssel und Hashes erzeugen.
5. Transaktionalen Commit / generation-basierten Swap durchführen. Inserts/Updates/Deletes zählen; Revision nur bei echter Datenänderung erhöhen.
6. Cursor **erst nach abgeschlossenem Commit** atomar speichern. Bei Abbruch bleibt bisheriger Cursor unverändert.
7. Attachment-Jobs anhand des committed Eventzustands anlegen (idempotent), veraltete Downloadberechtigungen sofort widerrufen.
8. View-/Render-Caches gezielt invalidieren; letztes Erfolgsdatum und Diagnosewerte aktualisieren; Lock freigeben.

### 7.2 Betrieb

- Default-Intervall: 15 Minuten, adminseitig konfigurierbar (min. 5 Minuten, von API-/Hostinglimits abhängig).
- Initiales Fenster: 90 Tage Vergangenheit / 365 Tage Zukunft; Maximalfenster aus Sicherheitsgründen begrenzen (z. B. 3 Jahre je Richtung, Ausnahme über Serverkonstante).
- Queue mit Jobs (`sync_source`, `sync_attachment`, `prune_storage`, `rebuild_cache`, `rotate_logs`), begrenzter Batch, Deadline, Retry mit Backoff und Jitter. Für 429/503 `Retry-After` beachten.
- WP-Cron als Standard, zusätzlich WP-CLI und optional echter Cron. Jobs dürfen niemals ungeprüft im Frontend-Request abgearbeitet werden.
- Locking muss atomar per DB-Operation funktionieren, nicht über read-then-write-Transients. Verwaiste Locks anhand `locked_until` übernehmen.
- Der Abruf alter Kalenderdaten bleibt verfügbar, wenn ein Provider temporär nicht erreichbar ist. Nach Reauth-Pflicht bleibt Quelle im von ihrer WordPress-Sichtbarkeit erlaubten Modus anzeigbar, bis Admin sie deaktiviert oder Datenaufbewahrung greift.
- Bei Policy-/Quellendeaktivierung muss der Download-Zugriff **sofort** gesperrt werden, auch wenn eine Lösch-Queue noch aussteht.
- Vollständiger Resync darf bei Fehler niemals die bisher sichtbare Datenbasis halb leeren.

### 7.3 Synchronisationsmetriken

Pro Lauf: `started_at`, `finished_at`, Dauer, Anbieter, Modus (`full`/`delta`), Pages, Events gelesen, Occurrences angelegt/geändert/entfernt, Attachments geplant/erfolgreich/übersprungen/fehlgeschlagen, API-Fehlercode, nächste Wiederholung. Keine personenbezogenen Token, Downloadlinks oder Rohbeschreibungen in Logs.

## 8. Anhangssynchronisierung und Berechtigungen [V1]

### 8.1 Source-Settings (vertragliche Semantik)

```json
{
  "sync_attachments": true,
  "public_only": true,
  "max_file_size_mb": 20,
  "allowed_mime_types": ["image/jpeg", "image/png", "image/webp", "image/gif", "application/pdf", "text/plain", "application/zip"],
  "generate_image_previews": true,
  "publish_private_attachments_confirmed": false
}
```

`public_only=true` ist Default. Bei `public_only=false` wird die Bestätigung zwingend, wenn `source.visibility=public`. Die öffentliche Quelle selbst benötigt zudem eine Bestätigung, wenn sie aus einem extern privat authentifizierten Kalender stammt. Diese Bestätigungen werden mit Zeitpunkt, Admin-ID und betroffener Source-Version protokolliert.

### 8.2 Policy-Zustände

`PUBLIC_VERIFIED`, `PRIVATE`, `UNKNOWN`, `UNSUPPORTED` sowie `NOT_APPLICABLE` (z. B. kein Dateianhang). Nur `PUBLIC_VERIFIED` darf bei `public_only` synchronisiert werden. Wenn der Provider keine beweisbare öffentliche Freigabe liefern kann, Status `UNKNOWN`, überspringen und in der Source-Diagnose kenntlich machen.

Ohne `public_only` dürfen lesbare private Anhänge synchronisiert werden. WordPress-Sichtbarkeit richtet sich dann **ausschließlich nach dem zugehörigen Source-ACL**; beim öffentlichem Kalender erfordert dies explizite Freigabe.

### 8.3 Download-Pipeline

1. Provider-Anhang und Freigabestatus prüfen; Download nur bei erlaubter Policy.
2. Größenlimit bereits aus Metadaten prüfen; tatsächlichen Stream zusätzlich hart auf maximale Bytes begrenzen.
3. Download in temporäre, nur serverseitig zugängliche Datei; MIME via Inhaltsprüfung erkennen, Dateiendung/Whitelist abgleichen.
4. SHA-256 berechnen, Atomar-Replace ins private Storage; `storage_key` zufällig oder anhand internem Hash, nie aus externem Pfad ungeprüft zusammensetzen.
5. Für Rasterbilder vorsichtig Thumbnail erzeugen (GD/Imagick/WordPress Image Editor), Pixelzahl und Speicherbedarf begrenzen; GIF-Animation nicht unbemerkt zerstören. SVG nicht inline erlauben. EXIF-Orientierung berücksichtigen.
6. Attachments nur erneut laden, wenn `provider_version`/ETag geändert wurde oder erneute Prüfung erforderlich ist; wenn Provider keine Version liefert, konfigurierbares Refresh-Intervall statt permanenter Unterdrückung.
7. Fehlschlag: vorherige Datei nur behalten, **wenn** Freigabe noch gültig ist; Widerruf oder unbekannte neue Berechtigung sperrt Veröffentlichung fail-closed.
8. Entfernte Attachments auf `revoked` setzen, Zugriff sofort unterbinden; Storage-Cleanup asynchron nach definierter Frist.

### 8.4 Private lokale Speicherung

- Bevorzugt **außerhalb des DocumentRoot**, z. B. konfigurierbarer `SW_CALENDAR_PRIVATE_STORAGE_PATH`.
- Fallback im WordPress-Verzeichnis nur wenn Webserver-Zugriff *nachweislich* verboten ist; `.htaccess` alleine schützt Nginx nicht. Wenn keine sichere Ablage verifizierbar ist, Attachment-Sync deaktivieren und Diagnosefehler anzeigen.
- Pfade niemals aus Nutzerwerten zusammensetzen; `realpath`-/Containment-Prüfung, keine Symlink-Flucht, restriktive Berechtigungen, atomare Writes.
- Für Massen-Uploads Speicherquoten je Source und global; kontrolliertes Garbage Collection.

### 8.5 Lokale HTTP-Auslieferung

Vorschlag: `GET /wp-json/sw-cloudcalendar/v1/attachments/{id}/content` und `GET /wp-json/sw-cloudcalendar/v1/attachments/{id}/preview` (Rewrite-Route optional). Verhalten:

- Lookup by numeric internal ID; **vor** Filezugriff ACL der Source, aktuelle Policy und Publikationsstatus prüfen.
- Bilder dürfen als `<img src="lokale-URL">` eingebunden werden; andere als Downloadliste mit Dateiname/Typ/Größe. Keine externen Source-URLs im gerenderten Markup.
- Header: sicher bestimmter `Content-Type`, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline` nur für freigegebene Rasterbilder, sonst `attachment`; passende `Cache-Control` nach Source-ACL.
- **Geschützte** Dateien: `Cache-Control: private, no-store` (konservative V1-Policy). **Öffentliche** Dateien: nur mit garantiert widerrufbarer URL-Strategie oder kurzer TTL und dokumentiertem Restfenster cachen; keine unbegrenzt immutable Download-URLs.
- Keine HTML-, SVG- oder ausführbare Inline-Ausgabe. Bei gelöschten Quellen/Anhängen 404 oder 403 ohne Leck von Metadaten.
- Große Dateien streamen statt vollständig in PHP-RAM laden; Ausgabe-Buffer und Fehlerbehandlung korrekt schließen. Range-Request außerhalb MVP.
- Alte Browser-, CDN- und Full-Page-Caches können Widerruf verzögern; bei sensiblen Quellen und Dateien HTTP-Cache deaktivieren.

## 9. Zugriffsmodell und Ausgabe [V1]

### 9.1 ACL-Matrix

| Source-Visibility | Unangemeldet | WP-Login ohne passende Rolle | WP-Login mit passender Rolle |
|---|---|---|---|
| `public` | Zugriff | Zugriff | Zugriff |
| `authenticated` | kein Zugriff | Zugriff | Zugriff |
| `roles` | kein Zugriff | kein Zugriff | Zugriff |

Keine individuellen Event-/Attachment-ACLs: Alle freigegebenen Anhänge übernehmen das Recht der Source. Der `public_only`-Filter beschränkt die **Menge** der synchronisierten Anhänge, nicht die Kalenderzugriffsregel.

### 9.2 Gemischte Views

Der Backend-Lese-Service berechnet stets `effective_source_ids = view_source_ids ∩ requested_source_ids ∩ authorized_source_ids`. Wenn kein `requested_source_ids` vorhanden ist, gilt die gesamte View-Auswahl. `requested_source_ids` darf nicht die View-Auswahl erweitern. Keine autorisierten Quellen → neutrale, nicht-leckende Leerausgabe oder definierter 403, je Endpoint.

### 9.3 Externe Caches

**Wichtig:** Pluginseitige ACL kann nicht verhindern, dass ein übergeordnetes Full-Page-Cache-Plugin eine einmal für einen angemeldeten Benutzer generierte HTML-Seite an Gäste ausliefert. Für Views mit `authenticated`/`roles`:

- PHP-/REST-Lese-API prüft ACL bei **jedem** Request.
- Dynamische geschützte Kalenderansichten nach Login über nicht öffentlich cachebares Endpoint laden; kein geschütztes Event-Markup ins öffentlich gecachte HTML serialisieren.
- Geschützte Responses `private, no-store` und `Vary: Cookie` nur ergänzend, nicht als alleinigen Schutz einsetzen.
- WordPress/Cache-Plugin-Kompatibilität testen; Template- oder Block-Render darf anonyme Caches nicht mit Benutzerinhalten befüllen.

## 10. Views, Shortcodes und Gutenberg [V1]

### 10.1 Shortcode-Syntax

```text
[sw_calendar id="12"]
[sw_calendar id="12" view="list" limit="5" days="30"]
[sw_calendar id="12" source="2,3" search="Gottesdienst"]
[sw_calendar id="12" start="2026-11-01" end="2026-12-31" location="Kirche"]
[sw_calendar id="12" template="7" show_images="true" show_attachments="false"]
```

### 10.2 Filtervertrag

| Attribut | Typ / zulässige Werte | Semantik |
|---|---|---|
| `id` | positive int, **Pflicht** | gespeicherte View-ID |
| `source` | kommaseparierte positive IDs | Schnittmenge mit View-Quellen, keine Erweiterung |
| `view` | `month`, `list`, `cards` | Ausgabelayout, sofern View freigibt |
| `limit` | int `1..100` | nur für resultatorientierte Ansichten |
| `days` | int `1..1095` | Zeitraum ab aktuellem Datum/jetzt (klar dokumentiert) |
| `start`, `end` | `YYYY-MM-DD` | lokales Datumsfenster; inkl. Start, inkl. Enddatum als `[start, end+1 day)` |
| `past` | boolean | vergangene Events einbeziehen |
| `search` | string max. 150 Zeichen | sichere Volltextsuche in Titel/Beschreibung/Ort |
| `location` | string max. 150 Zeichen | normalisierte Orts-Substring-Suche |
| `category` | slug | lokale Kategorie/Tag nur wenn tatsächlich implementiert; andernfalls strikt ablehnen |
| `exclude` | Liste stabiler interner IDs | Occurrences auslassen |
| `sort` | `asc`, `desc` | Startdatum, deterministischer Tie-Breaker |
| `template` | positive int | nur freigegebene Templates, `kind` validieren |
| `show_images`, `show_attachments` | boolean | reine Darstellung, nicht Sync/ACL |

`days` und `start`/`end` nicht uneindeutig kombinieren: feste Präzedenz `start/end` > `days` > View-Default. Unbekannte Parameter werden protokolliert und ignoriert oder mit definierter Validierungsantwort zurückgewiesen; niemals in DB-Queries interpolieren.

### 10.3 Gutenberg-Block

- Blockname `sw-cloudcalendar/calendar`, serverseitig dynamisch gerendert (`render_callback`).
- Attribute entsprechen typisiertem Filterschema; View-Auswahl, Farben, Vorschau und Anzeigeoptionen. Keine OAuth-Secrets im Block-JSON.
- Block erhält dieselbe Query-/ACL-Implementierung wie Shortcode und REST, kein Parallelpfad.
- Mehrere Blöcke auf einer Seite mit verschiedenen Views und Filtern möglich; Assets nur einmal laden.
- Kein Framework-Zwang für Endbenutzerseiten. Calendar-Grid muss Tastaturbedienung, ARIA-Labels, Farbe-plus-Text und mobile Darstellung unterstützen.

### 10.4 Event-Detailansicht

Interner Link verwendet stabilen Opaque-Identifier oder interne ID; Detailroute arbeitet ausschließlich mit ACL-validierter Occurrence. Zeigt Template, Terminzeit, Ort, Galerie, Downloadliste und Provider-Link nur wenn freigegeben. Bei Vorlagen ohne eigene Detailansicht darf ein dialogbasierter Drawer/Modal verwendet werden.

## 11. Template-Engine und Editoren [V1]

### 11.1 Unterstützte Syntax (vollständiger V1-Sprachumfang)

```html
<article class="swc-event">
  <h3>{{event.title}}</h3>
  <time>{{event.start_date}}</time>
  {{#event.location}}<p>{{event.location}}</p>{{/event.location}}
  {{^event.location}}<p>Ort wird bekanntgegeben</p>{{/event.location}}
  {{event.attachments.gallery}}
  {{event.attachments.file_list}}
</article>
```

- Variablen `{{namespace.name}}`, Sections `{{#namespace.name}}...{{/namespace.name}}`, invertierte Sections `{{^namespace.name}}...{{/namespace.name}}`.
- **Keine** allgemeinen Loops, Helper-Funktionen, Pipe-Filter, Partials, PHP, JavaScript, dynamischen Includes oder Funktionsaufrufe. Komplexe Komponenten wie Eventliste und Galerie werden von sicheren Plugin-Renderern erzeugt.
- Nur registrierte Platzhalter sind gültig. Unbekannte Platzhalter/inkonsistente Sections führen beim Speichern zu aussagekräftigem Validierungsfehler.
- Date-/Time-Formate werden auf View-/Template-Einstellungen festgelegt; Locale und Zeitzone aus normalisiertem Render-Kontext.
- `event.description` wird als Text escaped; optional separater `event.description_html` **nur** als pluginseitig sanitisiertes, freigegebenes Fragment. Kein frei wählbares Triple-Mustache.
- Renderer-Variablen (`event.attachments.gallery`, `event.attachments.file_list`, `calendar.events`) sind typisierte, intern erzeugte Trusted-HTML-Komponenten, nicht vom Provider übernommenes rohes HTML.

### 11.2 Kontextbezogene Ausgaben

Die Template-Engine muss **Ausgabekontexte** beachten:

- HTML-Textknoten: `esc_html`-äquivalentes Encoding.
- HTML-Attribute: `esc_attr`-äquivalent.
- URL-Attribute: `esc_url`-äquivalent sowie erlaubte Schemes.
- `<script>`, Inline-Eventhandler, Stylesheet-Imports, gefährliche CSS-Funktionen und nicht erlaubte Tags/Attribute sind unzulässig.
- Kein alleiniger `str_replace()`-Renderer: Ein AST/Tokenizer kennt HTML-Text-, Attribut- und URL-Kontexte. Alternativ **nur** ausdrücklich im HTML-Textkontext freigegebene dynamische Variablen erlauben und Attribut-Platzhalter verweigern. Dies ist für V1 die sicherere Mindestvariante, falls kein kontextbewusster Parser eingesetzt wird.
- Benutzerdefiniertes HTML wird beim Speichern WordPress-konform anhand Capabilities und einer dokumentierten Allowlist gereinigt. Auch Admin-Templates dürfen nicht zu XSS-Verteilern werden.
- CSS auf Root-Wrapperklasse `.swc-view-{id}` scopen oder durch spezifische generierte Klasse kapseln; `url()`, `@import` und andere gefährliche Exfiltrationswege validieren/entfernen.

### 11.3 Compile-Cache

Aus Template wird beim Speichern oder erstem Rendern ein **serialisierbares AST** erzeugt, kein PHP-Code. Cache-Key:

```text
swc:tpl:{template_id}:{sha256(body|engine_version|allowlist_version)}
```

- Speicher via persistenter Transients-API, mit eigener Version/Size-Limits. Cache-Miss → compile → validieren → speichern.
- Ein Cache-Korruptionsfall fällt sicher auf Neucompilierung zurück; keine Ausführung von Code.
- Rendern trennt Daten und Struktur; Darstellung als `TrustedHtml` nur durch interne Renderer.
- Bei Template-/Engine-/Allowlist-Update Schlüsselwechsel und invalidate.

### 11.4 Editor-Modi und Presets

**Presets V1:** `Classic List`, `Event Cards`, `Month Grid`, `Timeline List`. Jeweils Container-/Event-/Detail-Vorlage soweit für Modus sinnvoll.

**Visual Editor:** Bausteine `Title`, `Date/Time`, `Description`, `Location`, `Provider Link`, `Image Gallery`, `Attachment List`, `Container`, `Divider`, `Custom HTML`, optional `Event List`. Reihenfolge, Sichtbarkeit und CSS-Klassen einstellbar. Custom-HTML-Block nutzt dieselbe begrenzte Syntax.

**HTML Editor:** editierbare HTML-Markup-Struktur mit Highlighting, Autocomplete für bekannte Platzhalter, Live-Vorschau, Linting/Validierung, CSS-Textarea. Editormoduswechsel nur wenn Konvertierung sicher möglich; sonst duplizieren und als HTML weiterbearbeiten.

**Preview:** feste Testdatensätze (ganztägig, mehrtägig, wiederkehrend, ohne Ort, mit/ohne Bild/Anhängen, lange Texte, potenziell gefährliche externe Inhalte) und optional echte freigegebene Daten. Jeder Preview-Aufruf durch Admin-ACL geschützt; keine externen Provideranfragen.

## 12. Rendering-Cache und Invalidierung [V1]

| Cache | Speichermedium | Schlüssel | Ungültig wenn |
|---|---|---|---|
| Provider-Daten | lokale DB + private Dateien | Source + stabile Provider-ID | erfolgreicher Sync/Revocation |
| Template-AST | WordPress Transients oder persistenter Store | Template-ID + Body-Hash + Engine-Version | Template-/Engine-Änderung |
| Gerendertes HTML | optional Transients/persistenter Store | View-ID + View-Revision + erlaubte Source-Revisions + Filterhash + Template-Revision + Locale + TZ + Scope | Event-/View-/Template-/Policy-Änderung oder Zeitgrenze |

**Kein Cache als Autorisierungsquelle:** `canReadSource` wird vor Ausgabe auch bei Hit geprüft. Für gemischte Views werden Quelllisten vor Key-Bildung autorisiert und im Key berücksichtigt. Private / rollenbeschränkte Daten sollen bevorzugt **nicht** global als HTML persistiert werden; wenn doch, ausschließlich hinter pro Request autorisiertem Controller und ohne öffentlich nutzbare URL.

Zeitabhängige Listen (`days`, `past=false`, „nächste N“) verfallen spätestens an der **nächsten relevanten Event- oder Tagesgrenze**, nicht nur nach fixen 15 Minuten. 1.0 darf konservativ mit kurzer TTL arbeiten, sofern garantiert keine bereits abgelaufenen Events über die definierte Semantik hinaus angezeigt werden. HTML-Cache kann per Option abgeschaltet werden. Cache-Clearing im Admin und WP-CLI.

## 13. REST-API [V1]

**Namespace:** `/wp-json/sw-cloudcalendar/v1/`. Alle Endpunkte mittels `register_rest_route()` auf `rest_api_init`; `permission_callback` zwingend. Public-Endpunkte nutzen trotzdem interne Source-ACL. Admin-Endpunkte benötigen Capability `manage_sw_calendar` und bei zustandsändernden Browser-Requests WP-REST-Nonce.

| Methode | Pfad | Zweck | Rechte |
|---|---|---|---|
| GET | `/views/{id}/events` | normalisierte, filterbare Events im Fenster | Source-ACL, nur erlaubte Felder |
| GET | `/views/{id}/render` | HTML-Rendering für View/Filter | Source-ACL |
| GET | `/events/{id}` | einzelne Occurrence/Details | Source-ACL |
| GET | `/attachments/{id}/content` | gespeicherte Datei | Source-ACL + Policy |
| GET | `/attachments/{id}/preview` | lokales Preview | Source-ACL + Policy |
| GET | `/admin/connections` | Verbindungen (ohne Geheimnisse) | manage_sw_calendar |
| POST | `/admin/connections` | Verbindung/ICS-Konfiguration erstellen | manage_sw_calendar |
| POST | `/admin/connections/{id}/test` | Verbindung testen | manage_sw_calendar |
| GET/POST | `/admin/sources` | Quellen abrufen/erstellen | manage_sw_calendar |
| GET/PUT | `/admin/sources/{id}` | Quelleneinstellungen | manage_sw_calendar |
| POST | `/admin/sources/{id}/sync` | Sync-Job einreihen | manage_sw_calendar |
| GET/POST | `/admin/views` | Views verwalten | manage_sw_calendar |
| GET/PUT | `/admin/views/{id}` | View aktualisieren | manage_sw_calendar |
| GET/POST | `/admin/templates` | Templates verwalten | manage_sw_calendar |
| GET/PUT | `/admin/templates/{id}` | Template aktualisieren | manage_sw_calendar |
| POST | `/admin/templates/validate` | Syntax-/HTML-Validierung | manage_sw_calendar |
| POST | `/admin/templates/preview` | sicheren Preview erzeugen | manage_sw_calendar |
| GET | `/admin/diagnostics` | Sync/Fehler/Storage/Cache | manage_sw_calendar |
| POST | `/admin/cache/clear` | selektiver Cache-Reset | manage_sw_calendar |

**Event-Antwort (Beispiel):**

```json
{
  "events": [{
    "id": 1501,
    "source_id": 3,
    "title": "Gottesdienst",
    "start": "2026-11-01T09:00:00+01:00",
    "end": "2026-11-01T10:30:00+01:00",
    "all_day": false,
    "location": "Gemeindehaus",
    "url": "/veranstaltung/?swc_event=1501",
    "attachments": [
      {"id": 27, "name": "Programm.pdf", "type": "application/pdf", "size": 121340,
       "url": "/wp-json/sw-cloudcalendar/v1/attachments/27/content"}
    ]
  }],
  "pagination": {"page": 1, "per_page": 20, "total": 1},
  "meta": {"timezone": "Europe/Berlin", "last_synced_at": "2026-10-24T17:20:00Z"}
}
```

Bei unbekannten oder gesperrten Quellen keinen Ressourcenbestand indirekt über Anzahl, Fehlermeldung oder Metadaten preisgeben. REST-API begrenzt Fenster, `per_page`, Requestfrequenz und Textlängen; Pagination stabil.

## 14. WordPress-Administration [V1]

**Menü:** Kalender → Dashboard, Verbindungen, Quellen, Ansichten, Templates, Synchronisierung, Einstellungen, Diagnose.

1. **Verbindungen:** Provider-Auswahl, Setup-Anleitung für Google-/Microsoft-App, OAuth-Flow, Test, Refresh-Status, Trennen. Secrets niemals in Standardformulare zurückgeben.
2. **Quellen:** Name, ID, Farbe, Zeitzone, Sichtbarkeit, Rollen, Sync-Zeitfenster/-Intervall; Optionen `sync_attachments`, `public_only`, Größenlimit, MIME-Allowlist, separate Freigabebestätigung für privat→öffentlich; letzte Synchronisierung.
3. **Ansichten:** Quellen mehrfach kombinieren, Defaults/Filter setzen, Template zuordnen, Anzeige auswählen, Shortcode kopieren, Gutenberg-Vorschau.
4. **Templates:** Preset-Galerie, Duplicate, Visual/HTML-Modus, CSS, Variable Reference, Validate, Preview.
5. **Synchronisierung:** Fortschritt, letzte Läufe, manuelles Nachladen, Fehler je Quelle/Anhang, Retry.
6. **Diagnose:** Rechte, Speicherpfadschutz, WP-Cron, API-/OAuth-Status, GD/Imagick, belegte Bytes, Daten-/Template-/Render-Cache-Hits, Bereinigungsstatus.
7. **Deinstallation:** Default Daten **behalten** bei Deaktivierung; optional konfigurierbares vollständiges Löschen bei Deinstallation mit vorherigem Export-/Warnhinweis. Geheimnisse bei expliziter Verbindungsentfernung löschen.

## 15. WP-CLI [V1]

```bash
wp sw-cloudcalendar sources list
wp sw-cloudcalendar connections test --id=1
wp sw-cloudcalendar sync --source=3
wp sw-cloudcalendar sync --all --full
wp sw-cloudcalendar attachments sync --source=3
wp sw-cloudcalendar attachments prune --dry-run
wp sw-cloudcalendar cache clear --scope=all
wp sw-cloudcalendar diagnostics
```

CLI hat definierte Exitcodes, ist idempotent, vermeidet Geheimnisse im stdout, zeigt Zusammenfassung und kann in echtem Cron genutzt werden. Locks/Queues werden auch CLI-seitig respektiert. `--dry-run` verändert niemals Daten.

## 16. Sicherheitsanforderungen [V1]

- Fähigkeiten: eine eigene `manage_sw_calendar`-Capability, bei Aktivierung Admin zuweisen; keine Annahme, dass jeder Editor Templates ändern darf.
- Für Admin-Mutationen Capability + Nonce/REST-Authentifizierung; Eingaben schema-validieren, DB-Zugriffe mit `$wpdb->prepare`, Ausgaben strikt escapen.
- OAuth: PKCE/State/Redirect-URI validieren; Secrets nur serverseitig. `SW_CALENDAR_ENCRYPTION_KEY` aus `wp-config.php`/Environment als bevorzugte AEAD-Key-Quelle. Bei Fehlen dokumentierter Fallback mit WP-Salts und Warnung in Diagnose; keine Behauptung, dass derselbe Serverkompromiss damit abgewehrt wird. Ciphertext mit random Nonce und authentifiziertem Algorithmus (`sodium_crypto_secretbox` oder gleichwertig); Key-Rotation vorsehen.
- SSRF: bei externen ICS-/ATTACH-URLs HTTPS, `wp_safe_remote_get()`/safe requests, Redirect-Limits, DNS/IP-Prüfung einschließlich Redirect, Block lokaler/link-local/Metadaten-Adressen und ungewöhnlicher Ports, Byte-/Zeitlimits. Für Cloudprovider-Endpunkte feste erlaubte Hosts und keine willkürlichen Redirect-Ziele.
- Attachment-Download: Path Traversal, Symlinks, Content-Sniffing, Header Injection, Public-Path-Exposure und bösartige Formate verhindern; keine Ausführung fremder Uploads.
- Template-Engine: externe Daten nie als unbereinigtes HTML; kein PHP/JS; Platzhalter in URL/Attribut nur nach Kontextprüfung; keine CSS-Exfiltration über fremde Ressourcen.
- Logging: keine Tokens, PII aus Kalenderbeschreibungen, kompletten Download-URLs oder Auth-Headers.
- Datenschutz: kalenderspezifische Datenhaltung/Aufbewahrung dokumentieren, Löschen bei Verbindungstrennung und Migrationen; durch WP-Admin exportierbare Sync-Diagnose ohne private Eventinhalte.
- Kein ungeschützter REST-Zugang zu Quellen, die nur WordPress-Login/Rollen erlauben. Jede öffentliche Download- und Preview-Route unabhängig testen.

## 17. Performanz und Betrieb [V1]

Nicht funktionale **Zielwerte**, die in CI/Staging unter einem dokumentierten Referenzsystem gemessen werden, keine Garantien für beliebiges Shared Hosting:

- 10 Quellen, bis zu 20.000 Occurrences insgesamt innerhalb des konfigurierten Fensters.
- Ein einfacher Listen-Render aus lokalem Bestand benötigt keine Cloud-Anfrage; Query-/Renderzeit per Profiling messen, Ziel p95 unter 300 ms (ohne WordPress-Gesamtbootstrap) auf Referenzhardware.
- Kein ungebremstes `SELECT *` über alle Event-Inhalte; Pagination/Indizes.
- Template-Parsing findet bei Cache-Hit nicht erneut statt.
- Datei-Downloads streamen mit beschränktem PHP-RAM; pro Job Größenlimits; bei zu geringem Speicher verständliche Meldung.
- Cron bei wenig Traffic dokumentiert; Button „Sync jetzt“ erzeugt Job und wartet nicht synchron auf beliebig lange Cloud-Aufrufe.
- Eindeutige Statusanzeige bei OAuth-Expiry, 429, Sync-Cursor-Verfall, Source-Deaktivierung, nicht nachweisbarer Anhangs-Öffentlichkeit.

## 18. Testplan und Abnahmekriterien [V1]

### 18.1 Unit-Tests (MUSS)

- Shortcode-Parameter: unbekannte Quelle kann keine View erweitern; `limit`/Zeiten validiert; sortierte Ausgabe stabil.
- ACL-Matrix und Null-Schnittmengen; Sichtbarkeitsänderung wirkt vor Cache-Ausgabe.
- Template-Parser: Variablen, einfache und invertierte Sections, Syntaxfehler, unzulässige Platzhalter; gefährliche HTML-/Attribut-/URL-Kontexte.
- AST-Cache: Hit/Miss, Korruption, Version-/Templatewechsel.
- Event-Zeiten: DST-Umstellung Europe/Berlin, ganztags, mehrtägig, verschobene Instanz, `EXDATE`, abgebrochene Serieninstanz, exklusive Endzeit.
- Attachment-Policy: `public_only` + UNKNOWN muss überspringen; privat→öffentlich ohne Bestätigung blockieren; ACL vor Download, auch bei Cache-Hit.
- Dateihärtung: Path Traversal, Symlink, gefährliche SVG/HTML, falscher MIME, zu große Datei, widerrufene Datei.

### 18.2 Integrationstests mit Provider-Fixtures

- Google: Initial-/Delta-Sync über mehrere Pages, 410 führt zum Full-Rebuild; kein `timeMin`/`timeMax` bei Sync-Token; keine Datenverluste bei Abbruch.
- Microsoft: `calendarView/delta`-Initialrunde und `@odata.nextLink`/`deltaLink`; neues Fenster erzwingt Reset; Graph-429 inklusive `Retry-After`.
- ICS: ETag/304, UID/RECURRENCE-ID, TZID, bösartige Feed-URLs, unendliche RRULE, falsch codierte ICS-Daten.
- Attachments: Google Drive/Microsoft Dateianhang erfolgreich; M365-Referenzlink nicht pauschal wie Datei behandelt; öffentlicher Status nur bei Nachweis; Dateirevision/Revocation.
- Transaktions-/Generation-Sync: Fehler auf Seite 3/5 lässt Daten und Cursor unverändert.
- `dbDelta`-/Upgrade-Migration auf leerer und bestehender Datenbank; Multisite-Präfix.

### 18.3 End-to-End-Tests

| ID | Szenario | Erwartung |
|---|---|---|
| E2E-01 | Google/M365/ICS Quelle verbinden & synchronisieren | Events lokal vorhanden; Quelle hat Status/letzten Sync |
| E2E-02 | Monats-/Listenansicht mit mehreren Quellen | korrekte Termine/Farben/Zeiträume |
| E2E-03 | Shortcode `source=...` plus Filter | nur zugelassene Quellen und gefilterte Termine |
| E2E-04 | HTML-Template und visueller Builder | funktionierende Vorschau, sicheres Rendering, wiederverwendbare Presets |
| E2E-05 | Bildanhang | Bild erscheint inline über lokale WordPress-URL |
| E2E-06 | PDF/DOCX-Anhang | Dateiname/Icon/Größe in Liste und lokaler WordPress-Download |
| E2E-07 | `public_only` und unbekannte Freigabe | kein Download, Diagnose dokumentiert Ausschluss |
| E2E-08 | privater Providerkalender → WordPress `public` | Veröffentlichung erst nach ausdrücklicher Bestätigung |
| E2E-09 | Rollenbeschränkte Quelle | Gäste sehen keine Events, Details, JSON, Preview oder Dateien |
| E2E-10 | ACL-Änderung bei vorhandenem Render-Cache | unmittelbar gesperrt; keine Alt-Datenleckage |
| E2E-11 | Cloud offline | bestehende Termine bleiben sichtbar, Sync-Fehler dokumentiert |
| E2E-12 | Template ändern | nach Neuspeicherung aktualisierte HTML-Ausgabe |
| E2E-13 | Ereignis/Attachment gelöscht | nach erfolgreichem Sync nicht mehr abrufbar |
| E2E-14 | Öffentlicher Page Cache vor Login-Ansicht | geschützte Termine werden nicht als öffentlicher HTML-Cache ausgeliefert |
| E2E-15 | WP-CLI, Cron, manuelles Sync | idempotent, Lock verhindert Parallelverarbeitung |

**Release-Blocker:** irgendeine öffentlich erreichbare private Datei; XSS über Templates oder Event-Daten; verlorene Eventdaten nach abgebrochenem Sync; doppelte/fehlende Serieninstanzen im Standard-Testkorpus; unzulässige Cloud-Request-Parameter; fehlende wirksame Storage-Isolation.

## 19. Umsetzungspakete und Definition of Done

### Milestone 1 — Fundament

Plugin-Bootstrap, Capabilities, Schema/Migration, Dependency Injection, Domain DTOs, Quellen-/Views-/Templates-Repositories, Testpipeline. **Done:** frische Installation, Update und Uninstall-Pfad getestet; Basis-REST-API authentifiziert.

### Milestone 2 — ICS + Kalenderansicht

ICS-Parser mit Recurrence-/TZ-Support, Sync-Job & Staging, normalisierte Occurrences, Filter-Service, Shortcode, Listen-/Monatsansicht. **Done:** E2E mit Kalender inkl. DST und Ausnahmen.

### Milestone 3 — Templates

AST-Parser/Cache, Presets, HTML-Editor, Preview, visueller Builder, sicherer HTML-Kontext. **Done:** keine ungeprüften Variablen/Funktionen; Presets rendern nach identischem Vertrag.

### Milestone 4 — Google/M365

OAuth, Google- und Graph-Adapter, Paged Full-/Delta-Sync, Cursor-Renewal, Diagnose. **Done:** Provider-Fixtures inkl. 410/429/abgebrochener Runde grün.

### Milestone 5 — Attachments

Public-Only-Policy, Drive/Graph/ICS-Anhänge, privater Storage, Bildergalerie, lokale Datei-Downloadliste, ACL-Downloads, Cleanup. **Done:** alle Datei- und Veröffentlichungs-Sicherheitstests grün.

### Milestone 6 — WordPress UX & Release

Gutenberg, View-Editor, Admin-Sync, CLI, Render-Cache, Accessibility, i18n, Dokumentation, ZIP-Build. **Done:** sämtliche E2E-Fälle grün, Security Review, dokumentierte Konfigurationsgrenzen, versionierte Release-Notizen.

**Jeder Milestone:** Unit-/Integrationstests, PHPCS, PHPStan, npm build, manuelle Review-Changelog-Notiz; keine vollständige Funktionsabnahme ohne Tests und reproduzierbare Fixtures.

## 20. Offene technische Entscheidungen (explizit zu protokollieren)

Die Umsetzung DARF diese Themen nicht stillschweigend als „gelöst“ markieren:

1. **Google Sync-Strategie für große/fensterbasierte Kalender:** Referenzansatz ist Token-Sync ohne inkompatible `timeMin/timeMax` und lokale Recurrence-Expansion. Lastgrenzen und Rebuild-Alternative vor Produktionsfreigabe benchmarken.
2. **M365 Auth-Modell:** V1 zunächst dedizierte App-Registrierung je Installation und delegierter Access; für Shared-Mailbox-/Application-Permissions gesonderte Entscheidung/Tests.
3. **Public-only-Abgleich:** verlässliche Freigabeprüfung für Google Drive/OneDrive/SharePoint; nicht beweisbare Fälle werden übersprungen. Vor Release Capability-Matrix öffentlich dokumentieren.
4. **ICS-Paketwahl:** RFC5545/VTIMEZONE-konformes Paket und Lizenz/Abhängigkeitsisolation prüfen; keine Eigenimplementierung von RRULE.
5. **Frontend-Kalender:** FullCalendar oder kleinere Alternative anhand Bundlegröße, benötigter Views, Barrierefreiheit und Lizenz auswählen.
6. **Dateispeicher:** Installationsprüfung außerhalb Webroot, Hosting-Ausnahmen und Limits dokumentieren; bei unsicherer Konfiguration Download-Funktion blockieren.
7. **HTML-Attributplatzhalter:** entweder kontextsensitives AST plus Parser oder auf Textkontext beschränkte Syntax. Niemals rohes Ersetzen in beliebigem Markup.
8. **Lokale Kategorisierung:** V1 nur implementieren, wenn Datenmodell/UI fertig sind; `category`-Filter ansonsten nicht als Feature bewerben.
9. **Google/Graph App-Verifikation:** reale Anforderungen an Consent Screen, Tenant/Admin Consent und Provider-Limits pro Deployment dokumentieren; API-Zugang ist nicht ohne Konfiguration garantiert.

## 21. Referenzdokumentation

- WordPress REST-Routen: https://developer.wordpress.org/reference/functions/register_rest_route/
- WordPress Schema-Migrationen: https://developer.wordpress.org/reference/functions/dbdelta/
- WordPress sichere HTTP-Requests: https://developer.wordpress.org/reference/functions/wp_safe_remote_get/
- Google Calendar Events `list`: https://developers.google.com/workspace/calendar/api/v3/reference/events/list
- Google Calendar Event Attachment-Daten: https://developers.google.com/workspace/calendar/api/v3/reference/events
- Microsoft Graph `calendarView/delta`: https://learn.microsoft.com/en-us/graph/delta-query-events
- Microsoft Graph Attachment-Download: https://learn.microsoft.com/en-us/graph/api/attachment-get?view=graph-rest-1.0
- RFC 5545 (iCalendar): https://www.rfc-editor.org/rfc/rfc5545

---

## Implementierungsauftrag für Codex

Implementiere das Plugin **iterativ anhand der Milestones 1–6**. Behandle die Sicherheitsanforderungen, Source-ACL, Sync-Konsistenz, Attachment-Policy und Cache-Grenzen als Release-blockierende Akzeptanzkriterien. Arbeite keine unbekannten Provider-Fähigkeiten durch Vermutungen ab. Verwende pro Milestone nachvollziehbare Tests und liefere beim Abschluss einen kurzen Bericht mit geänderten Dateien, Testausgaben, offenen Risiken und bewusst nicht implementierten Funktionen. **Keine automatische Erweiterung des Funktionsumfangs über dieses Dokument hinaus.**
