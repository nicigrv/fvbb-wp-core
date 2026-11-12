# Förderverein Funktionen – WordPress-Plugin

Funktionen für [fv-biedensand-baeder.de](https://fv-biedensand-baeder.de/), passend zum Theme
[fvbb-wp-theme](https://github.com/nicigrv/fvbb-wp-theme). Enthält nur, was es auf der Website schon gibt:

| Funktion | ersetzt |
|---|---|
| Hinweis-Popup (z. B. „Neuer Newsletter“) – Dashboard → Hinweise (Popup) | Popup Maker |
| Cookie-Banner – Einstellungen → Cookie-Banner | CookieYes |
| Tabellen-Verwaltung + Block „Tabelle“ (Vorstand) | TablePress |
| Block „Zeitleiste“ (Seite „Aktionen“) | Timeline Block |
| Block „Symbol“ (runde Symbole über den „Wir …“-Karten) | Symbole des Mesmerize-Baukastens |
| Werkzeuge → Förderverein Umstellung: Startseite in Blöcke, Tabellen, Zeitleiste, Popup übernehmen – mit Sicherung und „Rückgängig“ | Mesmerize Companion |

Contact Form 7, Wordfence, Burst, Simple History und die WPMU-DEV-Plugins bleiben unverändert.

Anleitung zur Umstellung: [ANLEITUNG.md](ANLEITUNG.md)

## Neue Version veröffentlichen
Versionsnummer in `fvbb-core.php` (Kopf **und** `FVBB_CORE_VERSION`) erhöhen und auf `main` pushen.
Die GitHub-Action testet in einer frischen WordPress-Installation (mit dem Theme) und legt bei Erfolg ein Release mit
`fvbb-core.zip` an. WordPress zeigt das Update danach unter **Dashboard → Aktualisierungen**.

Lizenz: GPL-3.0-or-later.
