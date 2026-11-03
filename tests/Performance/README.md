# Lokaler Performance-Test

```sh
bin/test-db.sh start
# Abhängigkeiten: composer install sowie composer install --working-dir=tests/wp
SWC_PERF_REPORT=/tmp/swc-performance.json composer test:performance
```

Die Suite installiert WordPress in der bestehenden **Wegwerf-Testdatenbank** neu; niemals auf eine
Site-Datenbank umkonfigurieren oder parallel zu `composer test:wp` starten. Der Testdatensatz wird
zurückgerollt. Ohne `SWC_PERF_REPORT` liegt das JSON unter `/tmp/swc-performance.json`.
CI führt den Benchmark bei Pushes und Pull Requests aus und archiviert die Messung für 14 Tage.
Bei erfolgreichen PR-Läufen aus demselben Repository aktualisiert ein separater Job einen Bot-Kommentar
mit Median, P95, SQL-Zahlen und Anzahl ausgegebener Monatsereignisse. Nur dieser Job erhält
`issues: write` und `pull-requests: write`; Fork-/Dependabot-PRs erhalten Job-Summary und Artefakt ohne Kommentar.
Der Kommentar-Formatter ist mit `node --test tests/Performance/comment.test.cjs` prüfbar.
[baseline.json](baseline.json) enthält den lokalen Referenzlauf.

Gemessen werden 2.000 eigenständige ICS-Ereignisse im November 2026: Erstimport, unveränderter
Vollsync, HTTP 304, Änderung von 100 und Löschung von 100 Ereignissen; außerdem lokale REST-Abfragen
für Ereignisse, Seite 20, Suche, Details sowie Liste/Karten/Monat. Assertions prüfen Status,
Bestandszahlen, Änderungen/Löschungen und den tatsächlich angeforderten Darstellungsmodus.

Die Fixture-Erzeugung liegt außerhalb der Messung und verwendet eindeutige UIDs, ohne vorgelagerten
Duplikatscheck. Gleichzeitige Termine sind erlaubt. Der reguläre Sync bleibt unverändert; dessen
Abgleich bestehender Ereignisse gehört zur Messung. Es wird kein globaler Duplikatschutz deaktiviert.

Pro Leseaktion: einmal mit geleertem Plugin-Cache, danach zehn Wiederholungen. „Cold“ betrifft
Template-/HTML-Cache, nicht Betriebssystem, Datenbank oder PHP-Prozess. Ereignis-JSON wird nicht
als HTML gecacht. JSON enthält Rohzeiten, Median, P95 (nearest rank), SQL-Abfragezahl,
Speicherdifferenz und den Peak des gesamten Testprozesses. Einzelmessungen bei Sync/Cold sind
keine belastbaren Perzentile. Es gibt bewusst keine hardwareabhängige Zeitgrenze in CI;
Funktionsfehler brechen ab. Vergleiche benötigen dieselbe Maschine, PHP-/DB-Version und Konfiguration.

Der Transport zum ICS-Anbieter ist eine lokale HTTP-Fixture. Die Datenbank, der Parser, Sync,
Renderer und REST-Dispatcher sind echt. Gemessen wird innerhalb eines PHP-Prozesses mit Xdebug
ausgeschaltet; HTTP-/WordPress-Startzeit pro Request, Browserlayout, externe Anbieter und parallele
Nutzer sind nicht enthalten. Dies ist eine lokale Performance-Messung, kein Durchsatztest.

Referenzlauf (PHP 8.5.4, WordPress 6.9.10, MariaDB 11.8.6, lokaler Testserver):

| Aktion | Zeit | SQL-Abfragen |
|---|---:|---:|
| Import 2.000 | 5,15 s | 66 |
| Vollsync unverändert | 5,34 s | 51 |
| Sync HTTP 304 | 62 ms | 30 |
| 100 Änderungen | 5,58 s | 153 |
| 100 Löschungen | 5,08 s | 51 |
| Ereignisse / Seite 20 / Suche, warm (Median) | 5,31 / 4,46 / 6,55 ms | 10 / 8 / 10 |
| Liste / Karten, cold | 11,94 / 10,92 ms | 19 / 19 |
| Liste / Karten, warm (Median) | 1,01 / 1,00 ms | 5 / 5 |
| Monat, cold / warm (Median) | 97,93 / 1,89 ms | 19 / 5 |
| Details, cold / warm (Median) | 3,05 / 1,07 ms | 9 / 6 |

Auch der unveränderte Vollsync verarbeitet den Feed erneut und ist ähnlich teuer wie der Import.
HTTP 304 ist deutlich günstiger. Datenanlage und Leseaktionen sind getrennt gemessen.

**Befund:** Die Monatsansicht lädt derzeit höchstens 1.000 Ereignisse, obwohl `total` 2.000 meldet
(`ViewRenderer::renderView`). `rendered_unique_events` dokumentiert die tatsächlich ausgegebenen IDs.
Die Messzeit der Monatsansicht gilt daher nicht für die vollständige Darstellung aller 2.000 Termine.
Liste/Karten sind regulär auf 50 Ereignisse pro Seite begrenzt.

Ein Vergleich von WordPress mit aktiviertem und deaktiviertem sw-cloudcalendar ist nicht Teil
dieser Suite: Die Messungen zeigen die Kosten seiner Aktionen, nicht den globalen Bootstrap-Aufwand
auf gewöhnlichen WordPress-Seiten. Produktivhardware, Seiten-Cache und gleichzeitige Aufrufe können
andere Ergebnisse liefern.
