#!/usr/bin/env bash
# Prüft die Seiten über HTTP (PHP-Server) – nach tests/run.php.
set -uo pipefail
WP=${WP_DIR:-/tmp/wp}
DIR=$(cd "$(dirname "$0")" && pwd)
BASE=http://127.0.0.1:8080
FAIL=0
fail() { echo "  FAIL $*"; FAIL=1; }
ok() { echo "  ok   $*"; }

: > /tmp/wp-debug.log

echo "== Seiten"
while read -r u; do
  [ -z "$u" ] && continue
  code=$(curl -s -o /tmp/page.html -w '%{http_code}' "$BASE$u")
  if [ "$code" = "200" ]; then ok "$u → 200"; else fail "$u → $code"; fi
  if grep -qiE 'Fatal error|kritischer Fehler|critical error' /tmp/page.html; then fail "$u enthält eine Fehlermeldung"; fi
done < "$DIR/urls.txt"
code=$(curl -s -o /tmp/404.html -w '%{http_code}' "$BASE/gibt-es-nicht/")
[ "$code" = "404" ] && ok "Unbekannte Adresse → 404" || fail "Unbekannte Adresse → $code"

echo "== Inhalte"
has() { curl -s "$BASE$1" > /tmp/check.html; grep -qE "$2" /tmp/check.html; }
has / 'class="custom-logo' && ok "Logo aus dem alten Theme" || fail "Logo fehlt"
has / 'front-hero__title">Förderverein Biedensand Bäder Lampertheim e.V.' && ok "Titel im Kopfbild" || fail "Titel im Kopfbild fehlt"
has / 'instagram.com/fv_biedensand_baeder' && ok "Instagram-Button" || fail "Instagram-Button fehlt"
has / 'hero-media__img' && ok "Kopfbild" || fail "Kopfbild fehlt"
has / 'fvbb-symbol' && ok "Symbole über den Karten" || fail "Symbole fehlen"
has / 'wp-block-post-title' && ok "Aktuelles zeigt Beiträge" || fail "Aktuelles ohne Beiträge"
has / 'id="fvbb-notice"' && ok "Hinweis (Newsletter) wird eingeblendet" || fail "Hinweis fehlt"
has / 'id="fvbb-consent"' && ok "Cookie-Banner" || fail "Cookie-Banner fehlt"
has / 'menu-item-cta' && ok "Mitglied werden im Menü hervorgehoben" || fail "Menü-Button fehlt"
has / 'An der Sandbeune 21b' && ok "Footer-Spalten (Widgets) übernommen" || fail "Footer-Widgets fehlen"
has / 'mulish-latin-wght-normal.woff2' && ok "Schrift lokal" || fail "Schrift nicht lokal"
has / 'fonts.googleapis.com' && fail "Google Fonts werden geladen" || ok "keine Google Fonts"
has /unser-vorstand/ '<tfoot>' && ok "Vorstand: Tabelle mit Fußzeile" || fail "Vorstand: Tabelle fehlt"
has /blog/ '>Uncategorized<' && fail "Standardkategorie „Uncategorized“ wird angezeigt" || ok "Standardkategorie ausgeblendet"
has / 'Schwimmsport in Lampertheim' && fail "Ausgeblendeter Untertitel wird angezeigt" || ok "Untertitel wie bisher ausgeblendet"
has /aktionen/ 'fvbb-timeline__item' && ok "Aktionen: Zeitleiste" || fail "Aktionen: Zeitleiste fehlt"
has /unser-vorstand/ 'hero-media__img' && ok "Unterseiten mit Kopfbild" || fail "Unterseite ohne Kopfbild"

echo "== PHP-Fehlerprotokoll"
if grep -E 'PHP (Fatal|Parse|Warning|Notice|Deprecated)' /tmp/wp-debug.log; then fail "PHP-Fehler/Warnungen im Protokoll"; else ok "keine PHP-Fehler oder Warnungen"; fi

exit $FAIL
