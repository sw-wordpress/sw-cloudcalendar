# Composer-Abhängigkeiten in WordPress-Plugins isolieren

Wiederverwendbare Arbeitsanleitung nach dem Muster von SW Newspaper2 und SW Forms.
Ziel: Releases liefern ausschließlich isolierte Produktionsbibliotheken aus;
Quellcode-Checkouts funktionieren weiterhin mit normalem Composer-Autoloading.

## 1. Vor Beginn klären und lesen

- Zielverzeichnis, Plugin-Slug und gewünschten Namespace eindeutig bestimmen.
  Widersprüchliche Plugin-Namen vor Änderungen klären.
- Repository-Regeln (`AGENTS.md`), Git-Status, `composer.json`, `composer.lock`,
  Plugin-Einstiegsdatei, `.gitignore`, Release-Workflow und vorhandene Tests lesen.
- Produktionsabhängigkeiten (`require`) von Entwicklungswerkzeugen (`require-dev`)
  unterscheiden. Ohne externe Produktionsbibliotheken trotzdem den generierten
  Loader und den Start des Release-Pakets prüfen.
- Eigenen Plugin-Namespace und öffentliche WordPress-Funktionen nicht umbenennen.
  Nur externe Bibliotheken werden isoliert.
- Prüfen, ob Assets gebaut werden müssen und welche Dateien zur Laufzeit nötig sind.

## 2. Composer konfigurieren

Beispiel für den Plugin-Namespace `SWForms`; für andere Plugins alle Präfixe anpassen:

```json
"extra": {
    "mozart": {
        "dep_namespace": "SWForms\\Dependencies\\",
        "dep_directory": "/vendor-prefixed/",
        "classmap_directory": "/vendor-prefixed/",
        "classmap_prefix": "SWForms_",
        "constant_prefix": "SWFORMS_",
        "functions_prefix": "swforms_",
        "generate_autoloader": true,
        "delete_vendor_directories": true
    }
}
```

- Bestehende PSR-4- und Entwicklungs-Autoload-Konfiguration beibehalten.
- `/vendor-prefixed/` zusätzlich zu `/vendor/` ignorieren.
- Mozart wird über das versionierte Docker-Image ausgeführt, nicht als neue
  Produktionsabhängigkeit installiert.
- Lock-Metadaten ohne geplantes Paketupgrade aktualisieren:

```bash
composer update --lock --no-install --no-interaction --no-scripts
composer validate --strict
```

Anschließend kontrollieren, dass keine unbeabsichtigten Versionsänderungen entstanden sind.

## 3. Bootstrap anpassen

1. Zuerst `vendor-prefixed/autoload.php` verwenden.
2. Falls nicht lesbar, auf `vendor/autoload.php` zurückfallen.
3. Fehlen beide, bestehende Admin-Fehlermeldung anzeigen und Bootstrap abbrechen.
4. Den Rückgabewert des Loaders übernehmen und darauf den eigenen Plugin-Namespace
   mit `addPsr4()` registrieren: Mozarts Loader kennt die Abhängigkeiten, aber nicht
   automatisch die eigenen Plugin-Klassen.
5. Erst danach öffentliche Hilfsfunktionen laden und Plugin/Hooks initialisieren.

Beispiel; Konstanten und Namespace an das Zielplugin anpassen:

```php
$plugin_autoload = PLUGIN_DIR . 'vendor-prefixed/autoload.php';
if ( ! is_readable( $plugin_autoload ) ) {
    $plugin_autoload = PLUGIN_DIR . 'vendor/autoload.php';
}
if ( ! is_readable( $plugin_autoload ) ) {
    // Hier die bestehende Admin-Fehlermeldung registrieren.
    return;
}
$plugin_loader = require $plugin_autoload;
$plugin_loader->addPsr4( 'SWForms\\', PLUGIN_DIR . 'src/' );
unset( $plugin_autoload, $plugin_loader );
```

Bei einer anderen Verzeichnisstruktur die tatsächlichen PSR-4-Mappings registrieren.
Den Stil des Zielprojekts beachten: In SW Forms verwendet der WordPress-Adapter WPCS,
der Testbereich dagegen PSR-12.

## 4. Release-Workflow

Diese Reihenfolge verwenden:

1. Checkout und PHP/Composer einrichten.
2. Composer-Konfiguration strikt validieren.
3. Nur Produktionsabhängigkeiten installieren.
4. Bootstrap des Quellcode-Checkouts testen.
5. Abhängigkeiten mit Mozart isolieren.
6. Existenz von `vendor-prefixed/autoload.php` prüfen.
7. Falls nötig Node einrichten und Produktionsassets bauen.
8. Plugin in ein Staging-Verzeichnis kopieren, ohne ursprüngliches `vendor/`.
9. Bootstrap gegen das Staging-Verzeichnis testen.
10. ZIP mit stabilem Plugin-Slug als oberstem Ordner erstellen.
11. ZIP-Integrität, Pflichtdateien und Ausschlüsse prüfen; erst danach veröffentlichen.

Die entscheidenden Befehle:

```bash
composer install --no-dev --prefer-dist --no-interaction --no-progress \
  --no-scripts --optimize-autoloader

docker run --rm \
  --user "$(id -u):$(id -g)" \
  -v "$PWD:/project" \
  coenjacobs/mozart:1.2.3 /mozart/bin/mozart compose

test -f vendor-prefixed/autoload.php
php tests/bootstrap.php "$STAGING"
```

Im GitHub-Workflow kann anstelle von `$PWD` auch `$GITHUB_WORKSPACE` gemountet werden.
Mozart kann Bibliotheksreferenzen im Quellcode umschreiben und Vendor-Verzeichnisse
entfernen: lokale Versuche deshalb in einer **temporären, separaten Kopie** durchführen.

### Paketinhalt

Einzuschließen sind Plugin-Einstiegsdatei, eigene Laufzeitklassen, benötigte Includes,
Templates, Übersetzungen, gebaute Assets und `vendor-prefixed/`.

Auszuschließen sind insbesondere:

- ursprüngliches `vendor/`, Tests, Git-Metadaten und CI-Konfiguration;
- `node_modules/` und reine Frontend-Quellen, sofern sie zur Laufzeit nicht nötig sind;
- Entwicklerwerkzeuge, Caches, Logs und lokale Umgebungsdateien;
- Composer-Manifeste und reine Analyse-/Testkonfiguration nach dem Referenzmuster.

Nicht blind alle Verzeichnisse ausschließen: manche Plugins benötigen zusätzliche
Laufzeitdateien oder Bibliotheksressourcen. Kopierte Workflows auf veraltete Slugs,
Einstiegsdateinamen und fehlende Asset-Builds prüfen. Der ZIP-Dateiname darf vom
Repositorynamen abhängen, der oberste Plugin-Ordner muss den richtigen Slug behalten.

## 5. Tests und Abnahmekriterien

Ein eigenständiger `tests/bootstrap.php` akzeptiert optional das Plugin-Verzeichnis.
Er stellt minimale WordPress-Stubs bereit, lädt die **echte Einstiegsdatei** und prüft:

- eigene Klassen aus allen relevanten PSR-4-Bereichen sind ladbar;
- Aktivierungs-/Deaktivierungs-Callbacks sind aufrufbar, soweit vorhanden;
- wichtige Shortcodes, Admin- und Frontend-Hooks sind registriert;
- öffentliche Hilfsfunktionen sind verfügbar;
- keine Bootstrap-Fehlermeldung wurde registriert.

Keine Datenbank nötig; registrierte Lifecycle-Callbacks nicht ausführen.
Ein Test, der nur Composer direkt lädt, beweist nicht den korrekten Plugin-Bootstrap.

Abnahme:

- [ ] Composer strikt valide; keine unerwarteten Paketupdates.
- [ ] Bestehende Stilprüfungen, statische Analyse und Unit-Tests erfolgreich.
- [ ] Quellcode-Checkout startet mit Composer-Fallback.
- [ ] Tatsächlicher Mozart-Lauf mit der im Workflow verwendeten Version erfolgreich.
- [ ] Staging-Paket startet ohne ursprüngliches `vendor/`.
- [ ] ZIP besteht Integritäts- und Inhaltsprüfung und startet nach dem Entpacken.
- [ ] Falls vorhanden: Frontend-Linting, Typprüfung, Tests und Asset-Build erfolgreich.
- [ ] Dokumentation beschreibt Namespace, Build und lokalen Entwicklungsweg.

Bei echten Produktionsbibliotheken zusätzlich repräsentative Bibliotheksaufrufe und
benötigte Ressourcen testen: ein erfolgreicher Bootstrap allein beweist nicht, dass
alle dynamischen Klassenreferenzen, Funktionen und Dateien korrekt isoliert wurden.

## Referenzen in diesem Workspace

- `wp-content/plugins/sw-newspaper2/composer.json`
- `wp-content/plugins/sw-newspaper2/sw-newspaper2.php`
- `wp-content/plugins/sw-newspaper2/.github/workflows/main.yml`
- `wp-content/plugins/sw-newspaper2/tests/bootstrap.php`
- `wp-content/plugins/sw-forms/composer.json`
- `wp-content/plugins/sw-forms/sw-forms.php`
- `wp-content/plugins/sw-forms/.github/workflows/main.yml`
- `wp-content/plugins/sw-forms/tests/bootstrap.php`

Die Pfade sind relativ zum WordPress-Workspace. Diese Anleitung bei einer späteren
Aufgabe explizit mitgeben; eine automatische Erinnerung über Sitzungen hinweg ist
nicht vorausgesetzt.
