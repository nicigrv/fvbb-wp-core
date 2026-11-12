# Umstellung auf das neue Theme – Schritt für Schritt

Dauer: etwa 20 Minuten. Alles lässt sich rückgängig machen, solange die alten Plugins noch installiert sind.

## 1. Sicherung
Vorher ein Backup von Dateien **und** Datenbank anlegen (z. B. über das Hosting oder WPMU DEV).

## 2. Plugin und Theme hochladen
1. **Plugins → Installieren → Plugin hochladen** → `fvbb-core.zip` → aktivieren.
2. **Design → Themes → Hinzufügen → Theme hochladen** → `fvbb.zip` → aktivieren.

Logo, Kopfbild, Titel „Förderverein Biedensand Bäder Lampertheim e.V.“, der Button „Der FV auf Instagram“,
das Menü und die drei Footer-Spalten werden automatisch aus EmpowerWP übernommen. Die Startseite sieht
direkt nach dem Theme-Wechsel noch unfertig aus – das ändert Schritt 3.

## 3. Inhalte umstellen
**Werkzeuge → Förderverein Umstellung**

- Die Liste zeigt, was passiert: Startseite → normale Blöcke, Vorstandstabelle (TablePress) → „Tabellen“,
  Zeitleiste auf „Aktionen“ → Block „Zeitleiste“, Popup „Neuer Newsletter“ → „Hinweise (Popup)“.
- Backup-Häkchen setzen → **Jetzt umstellen**.
- Danach Startseite, „Unser Vorstand“ und „Aktionen“ ansehen.
- Gefällt etwas nicht: **Umstellung rückgängig machen** stellt alle Inhalte wieder her.

## 4. Alte Plugins abschalten
Auf derselben Seite unter „Alte Plugins deaktivieren“: Mesmerize Companion, TablePress, Popup Maker,
CookieYes, Timeline Block und OMGF (Schriften liegen jetzt im Theme). Seite noch einmal durchklicken, dann unter
**Plugins** löschen. Contact Form 7, Wordfence, Burst, Simple History und WPMU DEV bleiben.
Danach unter **Design → Themes** EmpowerWP und Mesmerize löschen.

## 5. Aufräumen (optional, später)
Unter „Aufräumen“ alte Popup-/TablePress-Einträge und Einstellungen löschen – erst wenn alles passt.

## Im Alltag
| Was | Wo |
|---|---|
| Newsletter-Hinweis ändern oder abschalten | **Hinweise (Popup)** → Hinweis bearbeiten; rechts „Hinweis anzeigen“ und „Anzeigen bis“ |
| Neue Aktion eintragen | Seite **Aktionen** bearbeiten → im Block „Zeitleiste“ auf **+ Neuer Eintrag**. Datum frei schreiben („12. Mai 2026“), sortiert wird automatisch |
| Vorstand ändern | Startseite: Karte im Abschnitt „Unser Vorstand“ bearbeiten. Seite „Unser Vorstand“: **Tabellen** → Vorstand |
| Kopfbild, Titel, Buttons, Footer | **Design → Anpassen → Förderverein** |
| Symbol über einer Karte ändern | Block „Symbol“ anklicken → rechts Symbol und Farbe wählen |
| Cookie-Banner-Texte | **Einstellungen → Cookie-Banner** |

Muster für neue Abschnitte (Block einfügen → Muster → „Förderverein“): Vorstand, Bankverbindung, Text + Bild, Info-Box.
