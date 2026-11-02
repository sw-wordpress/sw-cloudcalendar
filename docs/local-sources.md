# Lokale Plugin-Quellen (API v1)

CloudCalendar liefert ausschließlich den allgemeinen Vertrag. Der Adapter wird vom Quellplugin registriert;
ChurchTools Suite ist die erste externe Implementierung. Lokale Quellen speichern in CloudCalendar nur Verbindung,
Kalenderauswahl, Farbe und Sichtbarkeit. Termine, Bilder und ID-Zuordnungen werden nicht kopiert.

## Einrichtung

1. Beide Plugins aktivieren. Unter **Kalender → Verbindungen → Neue Verbindung → Lokales Plugin** das Quellplugin wählen.
2. Unter **Quellen → Neue Quelle → Mehrere Kalender hinzufügen** die gewünschten Kalender oder **Alle auswählen** wählen und gemeinsam als Entwürfe anlegen. Kalendername/Farbe werden vorgeschlagen und können
   überschrieben werden. Quellen starten als Entwurf. Sichtbarkeit festlegen und anschließend aktivieren.
3. Eine öffentliche Freigabe muss ausdrücklich bestätigt werden. Die Auswahl im Quellplugin allein veröffentlicht nichts.
4. Unter **Ansichten** mehrere Quellen auswählen; synchronisierte und lokale Kalender dürfen gemischt werden.
   Farbregeln der Ansicht überschreiben die gespeicherte Quellenfarbe. In den Anzeigeoptionen lassen sich farbige
   Betreffzeilen, Legende, Spalten, Tageslimit und gemeinsame Monatsnavigation einstellen.

In der Detailansicht jeder Quelle öffnet **Termine** in der Kopfzeile eine Liste mit Monatsauswahl und Seitennavigation, auch für Entwürfe. Lokale Termine werden live gelesen, synchronisierte aus dem gespeicherten Bestand. Nur Kalenderadministratoren (bei lokalen Quellen zusätzlich mit Adapterfreigabe) können sie abrufen; die Ausgabe wird nicht gecacht. Der Terminzähler lokaler Quellen zeigt die live ermittelte Anzahl im aktuellen Monat.

Beispiel entsprechend einer Seite mit Veranstaltungsliste und Monatsraster (12/13 sind anzulegende Ansichten):

```text
[sw_calendar id="12" view="list" columns="2" color_titles="true" legend="true" month="2026-11" navigation_group="mgwh" limit="100"]
[sw_calendar id="13" view="month" month="2026-11" day_limit="3" navigation_group="mgwh"]
```

Für die bebilderte Liste das Preset **timeline-list** für das Event-Template verwenden. Bei mehreren Spalten bleibt
auf kleinen Bildschirmen eine Spalte. `month` begrenzt Listen auf den Kalendermonat; im Raster werden zusätzlich die
angrenzenden Tage der vollständigen Wochen berücksichtigt. `navigation_group` verbindet nur ausdrücklich gleich
benannte Ansichten; ohne Gruppe navigieren sie unabhängig. Die gemeinsame Navigation benötigt JavaScript.
Alte Links mit `cts_month=11&cts_year=2026` werden als Monatsauswahl verstanden; neue Links verwenden `swc_month`.
Der Monatskalender zeigt zunächst `day_limit` Termine je Tag; weitere Termine sind über ein natives, per Tastatur und
auch ohne JavaScript bedienbares `<details>` erreichbar.

## Adapter registrieren

Der Hook wird bei erstmaligem Bedarf nach dem Laden der Plugins aufgerufen. Adapterdateien erst im Callback laden,
damit das Quellplugin auch ohne CloudCalendar startet. API-Klassen sind über den CloudCalendar-Autoloader verfügbar.

```php
add_action('sw_calendar_register_local_sources', static function ($registry): void {
    require_once __DIR__ . '/MyLocalSource.php';
    $registry->register('my-plugin', new MyLocalSource(), 1);
});
```

`MyLocalSource` implementiert `SW\Calendar\Application\Local\LocalEventSourceInterface`:

| Methode | Vertrag |
| --- | --- |
| `name(): string` | Anzeigename der Integration |
| `calendars(): array` | Liste `CalendarDefinition(externalId, name, timezone, color, publicAtProvider)`; stabile IDs, Farbe `#rrggbb` |
| `canReadCalendar(string, ?int): bool` | Aktuelle Freigabe für Kalender und WordPress-Benutzer; zusätzliche Einschränkung der CloudCalendar-ACL |
| `query(LocalEventQuery): LocalEventPage` | Bereits autorisierte Kalender gemeinsam abfragen; begrenzte, sortierte Seite plus exakte Gesamtzahl |
| `event(string $calendarId, string $id): ?LocalEvent` | Eine Instanz im angegebenen Kalender; bei Entfernung/fehlender Freigabe `null` |

Adapter-ID: 2–64 Zeichen, `[a-z][a-z0-9_-]*`, eindeutig je WordPress-Installation. API-Version 1 ist verbindlich;
ungültige IDs, doppelte Registrierung und inkompatible Versionen werden zurückgewiesen. Erweiterungen müssen
abwärtskompatibel sein; brechende Vertragsänderungen benötigen eine neue Version.

### Terminschema

Die Übergabe erfolgt als typisiertes `LocalEvent`, nicht als ungeprüftes Datenbankobjekt.

| Eigenschaft | Typ / Bedeutung |
| --- | --- |
| `id` | Nicht leerer String, höchstens 190 Bytes, innerhalb des Adapters eindeutige **Instanz**-ID; über Updates stabil |
| `calendarId` | Zugehörige `CalendarDefinition.externalId` |
| `title` | Klartext |
| `start`, `end` | `DateTimeImmutable`; Ende exklusiv und größer als Beginn. Zeitpunkte werden nach UTC normalisiert. |
| `timezone` | Ursprungszeitzone, z. B. `Europe/Berlin` |
| `allDay` | Bei `true`: reine Kalenderdaten als UTC-Mitternacht, ohne Zeitzonenverschiebung |
| `status` | `OccurrenceStatus::Confirmed`, `Tentative`, `Cancelled`; abgesagte Termine nicht in Suchergebnissen zählen/liefern |
| `descriptionText`, `descriptionHtml` | Klartext / optionales HTML; HTML wird vor der Ausgabe durch CloudCalendar bereinigt |
| `location`, `url` | Optionaler Ort / öffentlicher HTTP(S)-Link |
| `imageUrl`, `imageAlt` | Optionales **öffentliches** Vorschaubild und Alternativtext; HTTP(S), keine Tokens oder privaten Downloadlinks |

Wiederholungen bereits expandiert liefern. `id` darf nicht mit anderen Instanzen desselben Adapters kollidieren.
CloudCalendar bildet daraus `local.<source-id>.<base64url-id>` ohne Datenbankschreibzugriff. Numerische IDs und Links
synchronisierter Termine bleiben gültig. REST `/events/{id}?view=...` prüft Ansicht und Quelle vor der lokalen
Detailabfrage; auf unbekannte und gesperrte Referenzen folgt gleichermaßen 404.

### Abfragen und Performance

`LocalEventQuery` enthält `calendarIds`, `range`, `search`, `location`, `excludeIds`, `descending`, `limit`, `offset`,
`sortOffsetSeconds` und `endedBeforeRangeEnd`. `range` hat UTC-Grenzen für zeitgebundene Termine sowie
`floatingStart`/`floatingEnd` für Ganztagstermine. Grenzen sind halb offen: `start < range.end && end > range.start`.
`endedBeforeRangeEnd` verlangt zusätzlich `end <= range.end`. Suche umfasst Titel/Beschreibung/Ort; `location` schränkt
den Ort zusätzlich ein. Ausschlüsse beziehen sich auf adapterlokale Instanz-IDs. Keine abgesagten Termine mitzählen.

Sortierung: Beginn (Ganztagstermine um `sortOffsetSeconds` verschoben), dann Reihenfolge der übergebenen
`calendarIds`, dann Instanz-ID lexikografisch; `descending` kehrt nur die Zeitrichtung um. Bei mehreren Kalendern die
Reihenfolge des Eingabearrays erhalten. `LocalEventPage.total` zählt alle Treffer vor Limit/Offset.

Eine einzelne lokale Verbindung erhält Limit/Offset direkt. Bei Mischbetrieb fordert CloudCalendar je Verbindung
höchstens `offset + limit` Kandidaten an, sortiert gemeinsam und wendet den Offset erst danach an. Für diesen Fall
sind maximal 10000 Kandidaten je Verbindung erlaubt; bei tieferen Seiten den Zeitraum eingrenzen. Keine unbegrenzten
Vollabfragen, keine Abfrage pro Event. Bilder/Metadaten gebündelt laden. Indizes anhand echter Abfragepläne prüfen;
kein konkretes Laufzeitversprechen ohne Messung.

Quellplugins synchronisieren weiterhin selbst. Der Adapter darf beim Seitenaufruf keine externe API kontaktieren.
Fehlende Adapter liefern keine lokalen Termine. Laufzeitfehler werden ohne Event-/Geheimnisdaten protokolliert;
andere Quellen bleiben nutzbar. Ein fehlgeschlagener Adapter liefert in dieser Antwort keinen Beitrag zur Gesamtzahl.

## Berechtigungen und Caches

Ansichtsquellen ∩ angeforderte Quellen ∩ CloudCalendar-ACL ∩ Adapterfreigabe werden vor Abfragen angewendet.
Berechtigungen dürfen durch Filter, Detailreferenzen oder Cachetreffer nicht erweitert werden. Das Quellplugin muss
private Kalender und benutzerabhängige Rechte in `canReadCalendar()` durchsetzen.

Lokale Ansichten verwenden keinen geteilten HTML-/HTTP-Cache. Auf der WordPress-Seite erscheint ein Platzhalter;
der Browser lädt die autorisierte Ausgabe über REST (`no-store`). Dadurch bleiben auch zwischengespeicherte Seiten
bei Kalenderentfernung oder Rechteänderung sicher. JavaScript ist für diese eingebetteten Live-Ansichten erforderlich.
Synchronisierte Quellen behalten ihren bisherigen Cachepfad. Ein künftiger Cache für lokale Quellen benötigt einen
zusätzlichen verlässlichen Revisionsvertrag; API v1 verwendet bewusst keinen dauerhaften Eventcache.

Vorhandene Bilder werden direkt referenziert. Geschützte Anhänge und neue Datei-Downloads sind nicht Teil von API v1.

## Tests

- `composer test`, `composer stan`, `composer cs`, `composer cs:wp`
- `composer test:wp`; PHP-Coverage gemäß [development.md](development.md)
- `(cd apps/admin && npm run lint && npm run build && npm run test:coverage)`
- Die echte externe Implementierung wird im ChurchTools-Repository mit dem gemeinsamen WordPress-Harness geprüft;
  siehe dessen `docs/CLOUDCALENDAR.md`. CloudCalendar-Tests benötigen keinen ChurchTools-Adapter im Produktcode.
