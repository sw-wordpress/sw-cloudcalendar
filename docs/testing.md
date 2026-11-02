# Tests und Abdeckung der Abnahmekriterien

| Ebene | Ort | Inhalt |
|---|---|---|
| Unit | `tests/Unit` | Template-Compiler (XSS in Text/Attribut/URL/Style, Sections, Syntaxfehler, AST-Cache/Korruption), CSS-Scoping, Filterschema, Zeiträume, ACL-Matrix und Null-Schnittmengen, Recurrence (DST Europe/Berlin, ganztägig, mehrtägig, verschobene/abgesagte Instanz, EXDATE, RDATE+RRULE, exklusive Enden, Grenzen), ICS-Parser (Windows-TZID, Floating, DURATION, ATTACH, Encoding), SyncService (Seite 3/5 fehlerhaft, Delta, 410-Rebuild, Rollback, Lock, Retry-After, Reauth), Attachment-Policy, Secret-Vault, Dateispeicher (Traversal, Symlink, MIME-Spoofing), SSRF-Guard, Redaction |
| Integration | `tests/Integration` | Provider-Fixtures: Google (Pagination ohne `timeMin/timeMax`, Sync-Token, 410, 401-Refresh), Microsoft (`nextLink`/`deltaLink` opak, Fensterbindung, 429 + Retry-After, fremde Links), OAuth (PKCE, `invalid_grant`) |
| WordPress | `tests/wp/tests` | Schema/Migration, verschlüsselte Secrets, ICS-Sync → Shortcode, 304, Provider-Ausfall, gelöschte Termine, geschützte Views nie im Seiten-HTML, AST- und HTML-Cache-Hits, ACL über REST (Gäste/Rollen, `source` erweitert nichts, Detail-404, Sperre vor Cache-Hit, deaktivierte Quelle), Admin-Rechte, Freigabebestätigungen, Template-Validierung/409/Änderung wirkt sofort, Preview ohne Providerzugriff, OAuth-State (einmalig, benutzergebunden), Anhänge (Speicherung, MIME-Spoofing, `public_only`, ACL vor Download, Widerruf bei Entfernung und ACL-Wechsel, unsicherer Speicher) |

| Browser-E2E | `tests/E2E` | Wegwerf-WordPress (private MariaDB, PHP-Builtin-Server), Daten über den echten Sync-Service mit Fixture-Feed; Playwright/Chromium: Liste, Detail-Dialog, Monatsraster und REST-Navigation, Mobil-Agenda, geschützte View (kein Markup im Seitenquelltext für Gäste, nachgeladen nur mit Rolle), Editor ohne Admin-Zugriff (403), alle Admin-SPA-Seiten ohne Konsolenfehler, View- und Template-Editor mit Sandbox-Vorschau; Screenshots in `<workdir>/shots` |

Aufruf: `npm ci --prefix tests/E2E`, dann in `tests/E2E` `npx playwright-core install --with-deps chromium`.
Nach `bin/test-db.sh start` und dem Admin-Build: `tests/E2E/run.sh [workdir]`.
`CHROME` und `PW_DIR` können Browser bzw. Node-Abhängigkeiten überschreiben; die Datenbank nutzt dieselben
`SWC_TEST_DB_*`-Variablen wie die WordPress-Suite. CI führt die Browser-Suite im PHP-Job aus und archiviert
Screenshots, Ergebnis und Server-/WordPress-Logs für 14 Tage.

## E2E-Szenarien (SPEC §18.3)

| ID | Abdeckung |
|---|---|
| E2E-01 | ICS: WordPress-Test (Sync → Status). Google/M365: Provider-Fixtures; echte Konten nicht automatisiert |
| E2E-02 | WordPress-Test Monats-/Listenansicht; Farben/mehrere Quellen im Renderer |
| E2E-03 | `AccessTest::test_source_parameter_cannot_extend_view`, Shortcode-Filter |
| E2E-04 | `AdminRulesTest::test_template_validation_and_in_use_protection`, Preview-Test; visueller Builder: Vitest der SPA |
| E2E-05/06 | `AttachmentTest` (Bild inline über lokale URL, PDF in Liste) |
| E2E-07 | `AttachmentTest::test_public_only_skips_unverifiable_files` |
| E2E-08 | `AdminRulesTest::test_private_provider_calendar_requires_publication_confirmation` |
| E2E-09 | `AccessTest` (Events, Details, JSON), `AttachmentTest` (Dateien) |
| E2E-10 | `AccessTest::test_visibility_change_is_effective_before_cached_html` |
| E2E-11 | `SyncAndRenderTest::test_provider_outage_keeps_last_data` |
| E2E-12 | `AdminRulesTest` (Template ändern) |
| E2E-13 | `SyncAndRenderTest::test_deleted_event_disappears_after_successful_sync`, `AttachmentTest` (Revoke) |
| E2E-14 | `SyncAndRenderTest::test_protected_view_is_never_serialized_into_page_html` |
| E2E-15 | `SyncServiceTest::testLockPreventsParallelRunAndExpiredLockIsTakenOver`, Job-Idempotenz |

**Nicht automatisiert:** Formular-Workflows der Admin-SPA im Browser (Anlegen/Speichern; abgedeckt durch Vitest und
die REST-Tests), Tests gegen echte Google-/Microsoft-Konten, Last-/Performance-Messungen (SPEC §17) und Tests mit
realen Page-Cache-Plugins.

## Coverage

Zeilenabdeckung je Sprache muss **> 80 %** sein (ganzzahliger Vergleich, genau 80 % schlägt fehl); CI bricht sonst ab.

- **JS/TS:** `npm run test:coverage` in `apps/admin` (Vitest V8). Umfang: `apps/admin/src` (ohne reine Typdateien `src/types`,
  `*.d.ts`), `apps/admin/tools`, die ungebündelten Skripte `assets/js/**` und `blocks/**/*.js` (Tests:
  `frontend-script.test.ts`, `block-editor.test.ts`). `scripts/check-coverage.mjs` prüft > 80 %, schlägt fehl, wenn eine
  Produktivdatei aus Admin-App, Build-Plugin oder `assets/js`/`blocks` im Bericht fehlt, und schreibt die LCOV-Pfade
  relativ zur Plugin-Wurzel (SonarQube).
- **PHP:** Unit/Integration und WordPress-Suite liefern Clover, `tests/coverage/merge.php` vereinigt sie,
  `tests/coverage/check.php` prüft > 80 % und dass jede PHP-Datei aus `src`, `templates`, `blocks`, `sw-cloudcalendar.php`,
  `uninstall.php` im Bericht steht. Code, der nur beim Bootstrap vor Start der Messung läuft (Hauptdatei), zählt als
  nicht abgedeckt.

Vitest 5 zählt ausführbare Zeilen; frühere Prozentwerte aus Vitest 3 sind nicht direkt vergleichbar.
