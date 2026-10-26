# Tests und Abdeckung der Abnahmekriterien

| Ebene | Ort | Inhalt |
|---|---|---|
| Unit | `tests/Unit` | Template-Compiler (XSS in Text/Attribut/URL/Style, Sections, Syntaxfehler, AST-Cache/Korruption), CSS-Scoping, Filterschema, Zeiträume, ACL-Matrix und Null-Schnittmengen, Recurrence (DST Europe/Berlin, ganztägig, mehrtägig, verschobene/abgesagte Instanz, EXDATE, RDATE+RRULE, exklusive Enden, Grenzen), ICS-Parser (Windows-TZID, Floating, DURATION, ATTACH, Encoding), SyncService (Seite 3/5 fehlerhaft, Delta, 410-Rebuild, Rollback, Lock, Retry-After, Reauth), Attachment-Policy, Secret-Vault, Dateispeicher (Traversal, Symlink, MIME-Spoofing), SSRF-Guard, Redaction |
| Integration | `tests/Integration` | Provider-Fixtures: Google (Pagination ohne `timeMin/timeMax`, Sync-Token, 410, 401-Refresh), Microsoft (`nextLink`/`deltaLink` opak, Fensterbindung, 429 + Retry-After, fremde Links), OAuth (PKCE, `invalid_grant`) |
| WordPress | `tests/wp/tests` | Schema/Migration, verschlüsselte Secrets, ICS-Sync → Shortcode, 304, Provider-Ausfall, gelöschte Termine, geschützte Views nie im Seiten-HTML, AST- und HTML-Cache-Hits, ACL über REST (Gäste/Rollen, `source` erweitert nichts, Detail-404, Sperre vor Cache-Hit, deaktivierte Quelle), Admin-Rechte, Freigabebestätigungen, Template-Validierung/409/Änderung wirkt sofort, Preview ohne Providerzugriff, OAuth-State (einmalig, benutzergebunden), Anhänge (Speicherung, MIME-Spoofing, `public_only`, ACL vor Download, Widerruf bei Entfernung und ACL-Wechsel, unsicherer Speicher) |

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

**Nicht automatisiert:** Browser-E2E (Playwright) für Admin-SPA und Frontend-JavaScript, Tests gegen echte Google-/
Microsoft-Konten, Last-/Performance-Messungen (SPEC §17) und Tests mit realen Page-Cache-Plugins.
