#!/usr/bin/env bash
# Frische WordPress-Installation für die Tests (GitHub Actions).
# Aufruf: tests/ci/setup.sh <plugin-ordner> <theme-ordner>
set -euo pipefail
PLUGIN_SRC=$(realpath "$1")
THEME_SRC=$(realpath "$2")
WP=${WP_DIR:-/tmp/wp}

mkdir -p "$WP" && cd "$WP"
wp core download --locale=de_DE --skip-content --force
mkdir -p wp-content/plugins wp-content/themes wp-content/uploads
wp config create --dbname=wordpress --dbuser=root --dbpass=root --dbhost=127.0.0.1 --locale=de_DE --force --extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '/tmp/wp-debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'DISABLE_WP_CRON', true );
PHP
wp core install --url=http://127.0.0.1:8080 --title="Förderverein Biedensand Bäder Lampertheim" --admin_user=admin --admin_password=admin --admin_email=test@example.org --skip-email
wp language core activate de_DE || true
wp option update timezone_string Europe/Berlin
wp rewrite structure '/%postname%/'

rsync -a --exclude .git --exclude node_modules "$PLUGIN_SRC/" wp-content/plugins/fvbb-core/
rsync -a --exclude .git "$THEME_SRC/" wp-content/themes/fvbb/
wp theme activate fvbb
wp plugin activate fvbb-core
# Contact Form 7 bleibt auf der Website (Kontaktformular auf der Startseite).
wp plugin install contact-form-7 --activate || echo "Contact Form 7 konnte nicht installiert werden"
wp core version --extra
wp plugin list --fields=name,status,version
wp theme list --fields=name,status,version
