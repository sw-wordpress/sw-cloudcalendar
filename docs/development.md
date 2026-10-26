# Entwicklung

## Setup

```bash
composer install
(cd apps/admin && npm install)
bin/test-db.sh start                 # private MariaDB auf 127.0.0.1:33078 (nie die echte Datenbank)
(cd tests/wp && composer install)    # WordPress-Testumgebung (eigenes PHPUnit 9.6)
```

## Prüfungen

```bash
composer cs          # PSR-12: Domain, Application, Support, Provider/Storage/Crypto/OAuth, Tests
composer cs:wp       # WordPress Coding Standards: Bootstrap, Infrastructure/{Database,WordPress,Http}, Presentation
composer stan        # PHPStan Level 8 (framework-freier Code)
composer test        # PHPUnit: Unit + Integration (Provider-Fixtures, ohne WordPress)
composer test:wp     # WordPress-Integrationstests (Schema, REST, ACL, Sync, Anhänge)
php tests/bootstrap.php              # Smoke-Test des Plugin-Bootstraps
bin/i18n.sh check                    # Übersetzungen vollständig und aktuell
(cd apps/admin && npm run typecheck && npm run lint && npm test)
```

Zeilenlängen-Warnungen über 200 Zeichen schlagen nicht fehl (wie sw-backupit).

## Build

`bin/build-zip.sh` erzeugt `dist/sw-cloudcalendar-<version>.zip`:
Admin-Bundle bauen → Produktionsabhängigkeiten installieren → Smoke-Test → Mozart (Docker-Image
`coenjacobs/mozart:1.2.3`, Namespace `SW\Calendar\Dependencies\`) → `bin/prefix-sources.php` (schreibt die Verweise
im eigenen `src/` auf die isolierten Namen um und bricht ab, wenn einer übrig bleibt) → Smoke-Test ohne `vendor/` →
ZIP. Der GitHub-Workflow `.github/workflows/main.yml` führt zusätzlich die Qualitäts-Gates aus und testet das
entpackte Archiv.

Quellcode-Checkouts nutzen `vendor/autoload.php`; Releases nur `vendor-prefixed/autoload.php`.

## Konventionen

- `docs/CODE-STYLE.md` (verbindlich), `docs/decisions.md` (Projektentscheidungen), `docs/rest-api.md` (Vertrag mit der SPA).
- Ports (Interfaces) in `src/Application/Port`, Implementierungen in `src/Infrastructure`, Verdrahtung nur in `src/Services.php`.
- Texte der Oberfläche: PHP über gettext (`bin/i18n.sh update`), Admin-SPA über `apps/admin/src/i18n/{de,en}.ts`.
- Die WordPress-Tests laufen in Transaktionen; `SW_CALENDAR_TEST_SAVEPOINTS` lässt `WpdbTransaction` Savepoints nutzen.

## Bekannte Stolperstellen

- `wpdb::prepare()` macht aus `NULL` einen Leerstring: optionale Spalten werden separat oder über `wpdb::update()` gesetzt.
- sabre/vobject `EventIterator` ignoriert RRULE bei vorhandenem RDATE (deshalb eigener `RecurrenceExpander`).
- Mozart schreibt nur die Pakete um, nicht `src/` (deshalb `bin/prefix-sources.php`).
