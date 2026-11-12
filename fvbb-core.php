<?php
/**
 * Plugin Name:       Förderverein Funktionen
 * Plugin URI:        https://fv-biedensand-baeder.de/
 * Description:       Funktionen für den Förderverein Biedensand Bäder Lampertheim e.V.: Hinweis-Popup (z. B. neuer Newsletter), Cookie-Banner, Tabellen-Verwaltung, Zeitleiste für Aktionen, Symbole, Umstellung vom alten Theme. Ersetzt Popup Maker, CookieYes, TablePress und Timeline Block.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Author:            greylo.de
 * Author URI:        https://greylo.de/
 * License:           GPL-3.0-or-later
 * Text Domain:       fvbb-core
 * Update URI:        https://github.com/nicigrv/fvbb-wp-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FVBB_CORE_VERSION', '1.0.0' );
define( 'FVBB_CORE_FILE', __FILE__ );
define( 'FVBB_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'FVBB_CORE_URL', plugin_dir_url( __FILE__ ) );

require FVBB_CORE_DIR . 'inc/helpers.php';
require FVBB_CORE_DIR . 'inc/class-github-updater.php';

// Updates direkt aus GitHub (Dashboard → Aktualisierungen).
new FVBB_Core_GitHub_Updater( array(
	'type'  => 'plugin',
	'repo'  => 'nicigrv/fvbb-wp-core',
	'slug'  => dirname( plugin_basename( __FILE__ ) ),
	'file'  => plugin_basename( __FILE__ ),
	'asset' => 'fvbb-core.zip',
	'name'  => 'Förderverein Funktionen',
) );
require FVBB_CORE_DIR . 'inc/notices.php';
require FVBB_CORE_DIR . 'inc/tables.php';
require FVBB_CORE_DIR . 'inc/consent.php';
require FVBB_CORE_DIR . 'inc/symbols.php';
require FVBB_CORE_DIR . 'inc/timeline.php';

if ( is_admin() ) {
	require FVBB_CORE_DIR . 'inc/migration/class-converter.php';
	require FVBB_CORE_DIR . 'inc/migration/class-homepage-converter.php';
	require FVBB_CORE_DIR . 'inc/migration/class-migration.php';
	require FVBB_CORE_DIR . 'inc/migration/admin-page.php';
}
