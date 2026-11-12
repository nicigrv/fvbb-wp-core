<?php
/**
 * Integrationstests für „Förderverein Funktionen“ (+ Theme).
 *
 * Läuft auf GitHub Actions in einer frischen WordPress-Installation:
 *   wp eval-file wp-content/plugins/fvbb-core/tests/run.php
 * Baut die Website so auf, wie sie heute mit EmpowerWP/Mesmerize aussieht (Startseite aus dem
 * Baukasten, TablePress-Tabelle, Timeline-Block, Popup Maker, alte Theme-Einstellungen), führt die
 * Umstellung aus und prüft das Ergebnis. Schreibt am Ende tests/ci/urls.txt für HTTP- und Browser-Tests.
 *
 * @package fvbb-core
 */

require __DIR__ . '/lib.php';
require_once FVBB_CORE_DIR . 'inc/migration/class-converter.php';
require_once FVBB_CORE_DIR . 'inc/migration/class-homepage-converter.php';
require_once FVBB_CORE_DIR . 'inc/migration/class-migration.php';

wp_set_current_user( 1 );
kses_remove_filters();
$urls   = array();
$editor = array();
$home   = untrailingslashit( home_url() );
$upl    = untrailingslashit( wp_get_upload_dir()['baseurl'] );

/* =========================================================================
 * Grundlagen
 * ====================================================================== */
t_section( 'Grundlagen' );
t_ok( post_type_exists( 'fvbb_table' ), 'Inhaltstyp Tabellen ist registriert' );
t_ok( post_type_exists( 'fvbb_notice' ), 'Inhaltstyp Hinweise ist registriert' );
foreach ( array( 'fvbb/table', 'fvbb/symbol', 'fvbb/zeitleiste' ) as $b ) {
	t_ok( WP_Block_Type_Registry::get_instance()->is_registered( $b ), "Block {$b} ist registriert" );
}
t_eq( get_stylesheet(), 'fvbb', 'Theme „fvbb“ ist aktiv' );

/* =========================================================================
 * Tabellen
 * ====================================================================== */
t_section( 'Tabellen' );
$tid  = fvbb_table_create( 'Testtabelle', array( 'rows' => array( array( 'A', 'B', 'C' ), array( 'Oktober', '', '' ), array( '1', '<a href="https://example.org">x</a>', '3' ) ), 'head' => true ) );
$html = fvbb_table_render( $tid );
t_has( $html, '<thead>', 'Kopfzeile wird ausgegeben' );
t_has( $html, 'is-group-row', 'Zwischenüberschrift (nur erste Zelle) wird erkannt' );
t_has( $html, 'href="https://example.org"', 'Links in Zellen bleiben erhalten' );
$csv = fvbb_table_parse_csv( "\xEF\xBB\xBFDatum;Name\n01.02.;Test \"A\"\n" );
t_eq( count( $csv ), 2, 'CSV mit BOM und Semikolon wird gelesen' );
wp_delete_post( $tid, true );

/* =========================================================================
 * Zeitleiste
 * ====================================================================== */
t_section( 'Zeitleiste' );
t_eq( fvbb_timeline_parse_date( '26. Oktober 2025' ), array( 2025, 10, 26 ), 'Datum „26. Oktober 2025“' );
t_eq( fvbb_timeline_parse_date( '30. September2023' ), array( 2023, 9, 30 ), 'Datum ohne Leerzeichen vor dem Jahr' );
t_eq( fvbb_timeline_parse_date( '04. Juni 2025' ), array( 2025, 6, 4 ), 'Juni wird nicht als Juli gelesen' );
t_eq( fvbb_timeline_parse_date( '22. März 2025' ), array( 2025, 3, 22 ), 'Umlaut im Monatsnamen' );
t_eq( fvbb_timeline_parse_date( '12.03.2025' ), array( 2025, 3, 12 ), 'Datum mit Punkten' );
t_eq( fvbb_timeline_parse_date( 'Mai 2024' ), array( 2024, 5, 0 ), 'Nur Monat und Jahr' );
$tl = fvbb_timeline_render( array( 'items' => array(
	array( 'date' => '03. November 2024', 'text' => "Älterer Eintrag\nzweite Zeile" ),
	array( 'date' => '26. Oktober 2025', 'text' => 'Neuer <b>Eintrag</b> https://example.org' ),
	array( 'date' => '', 'text' => '' ),
) ) );
t_ok( strpos( $tl, '2025' ) < strpos( $tl, '2024' ), 'Neueste Einträge zuerst, nach Jahren gruppiert' );
t_eq( substr_count( $tl, 'fvbb-timeline__item' ), 2, 'Leere Einträge werden weggelassen' );
t_has( $tl, 'Neuer &lt;b&gt;Eintrag&lt;/b&gt;', 'Text wird sicher ausgegeben' );
t_has( $tl, '<a href="https://example.org"', 'Links im Text werden klickbar' );
t_has( $tl, 'Älterer Eintrag<br>', 'Zeilenumbrüche bleiben erhalten' );

/* =========================================================================
 * Symbol
 * ====================================================================== */
t_section( 'Symbol' );
t_eq( fvbb_symbol_from_fa( 'fa icon fa-handshake-o reverse round' ), 'handshake', 'fa-handshake-o → Handschlag' );
t_eq( fvbb_symbol_from_fa( 'fa icon fa-tint' ), 'drop', 'fa-tint → Tropfen' );
t_eq( fvbb_symbol_from_fa( 'fa icon fa-child' ), 'child', 'fa-child → Kind' );
$sym = fvbb_symbol_render( array( 'icon' => 'drop', 'color' => 'sun' ) );
t_has( $sym, 'fvbb-symbol is-sun', 'Symbol mit Farbe' );
t_has( $sym, '<svg', 'Symbol als SVG' );

/* =========================================================================
 * Alte Website nachbauen (wie heute mit EmpowerWP/Mesmerize)
 * ====================================================================== */
t_section( 'Alte Website nachbauen' );

// Blog-Seite und Beiträge.
$blog = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Blog', 'post_name' => 'blog' ) );
update_option( 'page_for_posts', $blog );

$live_posts = array();
$res        = wp_remote_get( 'https://fv-biedensand-baeder.de/wp-json/wp/v2/posts?per_page=5&_embed=wp:featuredmedia', array( 'timeout' => 25 ) );
if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
	$live_posts = json_decode( wp_remote_retrieve_body( $res ), true );
}
if ( is_array( $live_posts ) && $live_posts ) {
	foreach ( array_reverse( $live_posts ) as $lp ) {
		$pid = wp_insert_post( array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => html_entity_decode( $lp['title']['rendered'] ),
			'post_name'    => $lp['slug'],
			'post_date'    => $lp['date'],
			'post_content' => "<!-- wp:html -->\n" . $lp['content']['rendered'] . "\n<!-- /wp:html -->",
		) );
		$src = $lp['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '';
		if ( $src && preg_match( '#/wp-content/uploads/(.+)$#', $src, $m ) ) {
			set_post_thumbnail( $pid, t_image( $m[1] ) );
		}
	}
	echo "  info Beiträge von fv-biedensand-baeder.de übernommen: " . count( $live_posts ) . "\n";
} else {
	foreach ( array( 'Sonnensegel im Freibad', 'Saubere Gemarkung', 'Neue Fußballnetze im Freibad' ) as $i => $t ) {
		$pid = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $t, 'post_date' => gmdate( 'Y-m-d H:i:s', time() - ( 3 - $i ) * DAY_IN_SECONDS ), 'post_content' => '<!-- wp:paragraph --><p>Text zum Beitrag „' . $t . '“.</p><!-- /wp:paragraph -->' ) );
		set_post_thumbnail( $pid, t_image( '2026/01/beitrag-' . $i . '.jpg' ) );
	}
	echo "  WARN fv-biedensand-baeder.de nicht erreichbar – Beispielbeiträge angelegt\n";
}
$first_post = get_posts( array( 'numberposts' => 1 ) );
if ( $first_post ) {
	$urls[] = wp_make_link_relative( get_permalink( $first_post[0] ) );
}

// Startseite aus dem Baukasten (Namen und Bankdaten im Testbestand durch Platzhalter ersetzt).
$fixture = file_get_contents( __DIR__ . '/fixtures/startseite.html' ); // phpcs:ignore
preg_match_all( '#\{UPLOADS\}/([^"&)\s]+)#', $fixture, $m );
foreach ( array_unique( $m[1] ) as $rel ) {
	// Mesmerize nutzt teils zugeschnittene Kopien ("cropped-…"): die liegen als eigene Anhänge in der Mediathek.
	t_image( $rel );
}
$builder = str_replace( array( '{UPLOADS}', '{HOME}' ), array( str_replace( 'https://', 'http://', $upl ), $home ), $fixture );
$front   = wp_insert_post( array(
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => 'mesmerize',
	'post_name'    => 'mesmerize',
	'post_content' => $builder,
) );
update_post_meta( $front, '_wp_page_template', 'page-templates/homepage.php' );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $front );
t_ok( FVBB_Homepage_Converter::is_builder_content( get_post( $front )->post_content ), 'Startseite ist Baukasten-Inhalt' );

// Mitgliedsantrag (Ziel der Buttons).
$antrag = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Jetzt Mitglied werden!', 'post_name' => 'mitgliedsantrag',
	'post_content' => "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"#\">Mitgliedsantrag herunterladen</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->\n\n<!-- wp:paragraph -->\n<p><strong>Nach dem Ausfüllen des Antrages kannst du ihn per E-Mail an <a href=\"mailto:info@example.org\">info@example.org</a> schicken.</strong></p>\n<!-- /wp:paragraph -->" ) );

// Vorstand: TablePress-Tabelle (mit leerer Zeile und Fußzeile wie im Original).
$tp_rows = array( array( 'Name', 'Postition' ), array( 'Person 1', '1. Vorsitzender' ), array( 'Person 2', '2. Vorsitzende' ), array( 'Person 3', 'Kassenverwalter' ), array( 'Person 4', 'Schriftführerin' ), array( '', '' ), array( 'Kontakt zum Vorstand: info@example.org', '' ) );
$tp_post = wp_insert_post( array( 'post_type' => 'tablepress_table', 'post_status' => 'publish', 'post_title' => 'Vorstand', 'post_content' => wp_json_encode( $tp_rows ) ) );
update_post_meta( $tp_post, '_tablepress_table_options', wp_json_encode( array( 'table_head' => true, 'table_foot' => true, 'print_name' => false ) ) );
update_post_meta( $tp_post, '_tablepress_table_visibility', wp_json_encode( array( 'rows' => array_fill( 0, 7, 1 ), 'columns' => array( 1, 1 ) ) ) );
update_option( 'tablepress_tables', wp_json_encode( array( 'last_id' => 1, 'table_post' => array( '1' => $tp_post ) ) ) );
$vorstand = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Unser Vorstand', 'post_name' => 'unser-vorstand', 'post_content' => '<!-- wp:tablepress/table {"id":"1"} /-->' ) );

// Aktionen: Zeitleiste aus dem Plugin „Timeline Block“ (Daten wie auf der Website).
$tl_items = array(
	array( 'date' => '2003', 'label' => '26. Oktober 2025', 'description' => 'Teilnahme am 13. Vereinsfrühshoppen der Stadt Lampertheim mit dem Thema Demokratie und Engagement. ' ),
	array( 'date' => '2003', 'label' => '04. Juni 2025', 'description' => 'Offizielle Eröffnung des neuen Schwimmerbeckens im Freibad. ' ),
	array( 'date' => '2003', 'label' => '22. März 2025', 'description' => 'Mitmachaktion bei der Sauberen Gemarkung 2025 in Lampertheim. Danach gab es eine Stärkung für alle Mitmachenden.' ),
	array( 'date' => '2003', 'label' => '03. November 2024', 'description' => "Teilnahme am Lampertheimer Vereinsfrühschoppen. \nThemen: \nNachwuchssorgen\nSichtbarkeit der Vereine" ),
	array( 'date' => '2004', 'label' => '30. Juli 2024', 'description' => 'Mithilfe beim EWR Triathlon. ' ),
	array( 'date' => '02-02-2002', 'label' => '30. September2023', 'description' => "Jubiläumsfeier zum 50-jährigen Jubiläum des Hallenbads im Hallenbadcafé\n" ),
	array( 'date' => '02-02-2002', 'label' => '15. Juli 2023', 'description' => 'Stand beim Hessischen Familientag in Lampertheim.  ' ),
);
$tl_attrs = array( 'timelines' => array_map( function ( $t ) {
	return array_merge( array( 'icon' => "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><path d='M8 0'/></svg>", 'isAddTinyEditor' => false ), $t );
}, $tl_items ), 'type' => 'vertical', 'itemPosition' => 'right' );
$aktionen = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Aktionen', 'post_name' => 'aktionen',
	'post_content' => get_comment_delimited_block_content( 'tlgb/b-timeline-block', $tl_attrs, "\n<div class=\"wp-block-tlgb-b-timeline-block\"></div>\n" )
		. "\n\n<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Aktuell laufende Aktionen:</h3>\n<!-- /wp:heading -->\n\n<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li><strong>Teilnahme an den Vereinsfrühstücken der Stadt Lampertheim</strong></li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->" ) ) );

// Popup Maker: „Neuer Newsletter“ auf allen Seiten.
$popup = wp_insert_post( array( 'post_type' => 'popup', 'post_status' => 'publish', 'post_title' => 'Newsletter', 'post_content' => "<!-- wp:paragraph -->\n<p><a href=\"https://example.org/newsletter.pdf\">Hier herunterladen!</a></p>\n<!-- /wp:paragraph -->" ) );
update_post_meta( $popup, 'popup_title', 'Neuer Newsletter 1 – 2026' );
update_post_meta( $popup, 'enabled', '1' );
update_post_meta( $popup, 'popup_settings', array( 'triggers' => array( array( 'type' => 'auto_open' ) ), 'conditions' => array() ) );

// Einstellungen des alten Themes (EmpowerWP ist Child-Theme von Mesmerize).
$logo  = t_image( '2024/12/cropped-Logo.png', 'Logo' );
$hero  = t_image( '2025/01/WhatsApp-Bild-2025-01-09-um-12.01.37_93a0da72.jpg', 'Freibad' );
$inner = t_image( '2025/01/cropped-WhatsApp-Bild-2025-01-09-um-12.01.37_93a0da72.jpg', 'Freibad (zugeschnitten)' );
update_option( 'theme_mods_empowerwp', array(
	'custom_logo'                          => $logo,
	'header_title'                         => 'Förderverein Biedensand Bäder Lampertheim e.V.',
	'header_content_buttons'               => array( array( 'label' => 'Der FV auf Instagram', 'url' => 'https://www.instagram.com/fv_biedensand_baeder', 'target' => '_blank' ) ),
	'header_front_page_image'              => str_replace( 'https://', 'http://', wp_get_attachment_url( $hero ) ),
	'header_image'                         => wp_get_attachment_url( $inner ),
	'header_image_data'                    => (object) array( 'attachment_id' => $inner, 'url' => wp_get_attachment_url( $inner ) ),
	'footer_content_social_icon_0_enabled' => true,
	'footer_content_social_icon_0_link'    => 'mailto:info@fv-biedensand-baeder.de',
	'footer_content_social_icon_1_enabled' => false,
	'footer_content_social_icon_1_link'    => '#',
) );

// Footer-Widgets wie bisher (drei Textspalten).
update_option( 'widget_block', array(
	9  => array( 'content' => "<!-- wp:paragraph -->\n<p>Der Förderverein Biedensand Bäder Lampertheim ist <strong>kein</strong> gemeinnütziger Verein. Eine Spende kann demnach nicht bei der Steuer abgesetzt werden.</p>\n<!-- /wp:paragraph -->" ),
	15 => array( 'content' => "<!-- wp:paragraph -->\n<p><strong>Förderverein Biedensand Bäder Lampertheim e.V.</strong><br>An der Sandbeune 21b<br>68623 Lampertheim<br>info@fv-biedensand-baeder.de</p>\n<!-- /wp:paragraph -->" ),
	16 => array( 'content' => "<!-- wp:paragraph -->\n<p><a href=\"/impressum/\">Impressum</a><br><a href=\"/datenschutzerklaerung/\">Datenschutzerklärung</a><br><a href=\"#\">Satzung</a><br><a href=\"/verlinkungen/\">Verlinkungen</a></p>\n<!-- /wp:paragraph -->" ),
	'_multiwidget' => 1,
) );
$sw = get_option( 'sidebars_widgets', array() );
$sw['first_box_widgets']  = array( 'block-9' );
$sw['second_box_widgets'] = array( 'block-15' );
$sw['third_box_widgets']  = array( 'block-16' );
update_option( 'sidebars_widgets', $sw );

// Weitere Seiten von der echten Website (nur zum Ansehen; Inhalt als HTML übernommen).
$extra = array( 'das-schwimmbad' => 'Das Schwimmbad', 'aktuelle-newsletter' => 'Aktuelle Newsletter', 'verlinkungen' => 'Verlinkungen', 'impressum' => 'Impressum' );
$pages = array();
foreach ( $extra as $slug => $title ) {
	$content = '';
	$r       = wp_remote_get( 'https://fv-biedensand-baeder.de/wp-json/wp/v2/pages?slug=' . $slug, array( 'timeout' => 20 ) );
	if ( ! is_wp_error( $r ) && 200 === wp_remote_retrieve_response_code( $r ) ) {
		$d = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( ! empty( $d[0]['content']['rendered'] ) ) {
			$content = "<!-- wp:html -->\n" . $d[0]['content']['rendered'] . "\n<!-- /wp:html -->";
		}
	}
	if ( ! $content ) {
		$content = "<!-- wp:paragraph -->\n<p>Inhalt der Seite „{$title}“.</p>\n<!-- /wp:paragraph -->";
	}
	$pages[ $slug ] = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => $content ) );
}

// Menü wie auf der Website.
$menu = wp_create_nav_menu( 'Hauptmenü' );
wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Startseite', 'menu-item-url' => home_url( '/' ), 'menu-item-status' => 'publish', 'menu-item-type' => 'custom' ) );
foreach ( array( $vorstand, $aktionen, $pages['aktuelle-newsletter'], $pages['das-schwimmbad'], $antrag ) as $pid ) {
	wp_update_nav_menu_item( $menu, 0, array( 'menu-item-object-id' => $pid, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
}
set_theme_mod( 'nav_menu_locations', array( 'primary' => $menu ) );
t_ok( true, 'Seiten, Beiträge, Tabelle, Zeitleiste, Popup, Menü und alte Theme-Einstellungen angelegt' );

/* =========================================================================
 * Umstellung
 * ====================================================================== */
t_section( 'Umstellung: Analyse' );
$analysis = FVBB_Migration::analyze();
$found    = wp_list_pluck( $analysis, 'id' );
t_ok( in_array( $front, $found, true ), 'Startseite wird zur Umstellung vorgeschlagen' );
t_ok( in_array( $vorstand, $found, true ), 'Vorstand (TablePress) wird vorgeschlagen' );
t_ok( in_array( $aktionen, $found, true ), 'Aktionen (Zeitleiste) wird vorgeschlagen' );
t_eq( (int) wp_count_posts( 'fvbb_table' )->publish, 0, 'Vorschau legt noch keine Tabellen an' );

t_section( 'Umstellung: Ausführen' );
$done   = FVBB_Migration::run_content();
$tables = FVBB_Migration::run_tables();
$popups = FVBB_Migration::run_popups();
t_eq( count( $done ), 3, '3 Inhalte umgestellt' );
t_eq( $popups, 1, 'Popup als Hinweis übernommen' );
t_eq( FVBB_Migration::backup_count(), 3, 'Originale gesichert' );
foreach ( $done as $d ) {
	foreach ( $d['warnings'] as $w ) {
		echo "  WARN {$d['title']}: {$w}\n";
	}
}

t_section( 'Startseite' );
$fc = get_post( $front )->post_content;
t_ok( has_blocks( $fc ) && false === strpos( $fc, 'data-export-id' ), 'Startseite besteht nur noch aus Blöcken' );
t_not( $fc, 'wp:html', 'Kein Abschnitt als HTML-Block übrig' );
t_eq( substr_count( $fc, '<!-- wp:fvbb/symbol' ), 3, '3 Symbole über den „Wir …“-Karten' );
t_has( $fc, '"icon":"handshake"', 'Handschlag-Symbol übernommen' );
t_has( $fc, 'fvbb-overlap', 'Erster Abschnitt ragt ins Kopfbild' );
t_has( $fc, '"perPage":3', 'Aktuelles: letzte 3 Beiträge' );
t_has( $fc, 'Weitere Beiträge', 'Button „Weitere Beiträge“' );
t_has( $fc, '>Unsere Ziele</h2>', 'Überschrift „Unsere Ziele“' );
t_eq( substr_count( $fc, '"className":"fvbb-points"' ), 1, '„Wir …“-Punkte als Raster' );
t_has( $fc, 'wp-image-', 'Bilder mit der Mediathek verknüpft' );
t_eq( substr_count( $fc, '"className":"is-style-fvbb-person"' ), 6, 'Vorstand: 6 Personen-Karten' );
t_has( $fc, 'mailto:info@fv-biedensand-baeder.de', 'Vorstand: E-Mail-Link übernommen' );
t_not( $fc, 'twitter', 'Ausgeblendete Platzhalter-Symbole entfallen' );
t_has( $fc, 'is-style-fvbb-sunbox', '„Kein gemeinnütziger Verein“ als Hinweis-Kasten' );
t_has( $fc, 'ist leider <strong>kein</strong> gemeinnütziger', 'Fettdruck im Text bleibt erhalten' );
t_has( $fc, '[contact-form-7 id="c086e96" title="Kontaktformular 1"]', 'Kontaktformular (Contact Form 7) übernommen' );
t_has( $fc, 'wp:cover', 'Kontakt als Bild-Abschnitt' );
t_has( $fc, 'fvbb-bank', 'Bankverbindung im Kasten' );
t_has( $fc, 'IBAN: DE00 0000 0000 0000 0000 00', 'IBAN übernommen' );
t_has( $fc, 'wp:gallery', 'Fotoreihe als Galerie' );
t_has( $fc, $home . '/mitgliedsantrag/', 'Links auf den Mitgliedsantrag mit Schrägstrich' );
t_has( $fc, 'is-style-fvbb-sun', 'Mitglied-werden-Buttons in Sonnengelb' );
$ser = serialize_blocks( parse_blocks( $fc ) );
t_eq( md5( $ser ), md5( $fc ), 'Block-Markup ist stabil (parse → serialize)' );
$editor[] = $front;
$urls[]   = '/';

t_section( 'Vorstand (TablePress)' );
$vc = get_post( $vorstand )->post_content;
t_has( $vc, '<!-- wp:fvbb/table', 'TablePress → Block „Tabelle“' );
$tables_now = get_posts( array( 'post_type' => 'fvbb_table', 'numberposts' => -1 ) );
t_eq( count( $tables_now ), 1, 'Tabelle einmal übernommen' );
$vh = fvbb_table_render( $tables_now[0]->ID );
t_has( $vh, '<tfoot>', 'Fußzeile übernommen' );
t_eq( substr_count( $vh, '<tr' ), 6, 'Leere Zeile wird ausgeblendet' );
t_has( $vh, 'Kassenverwalter', 'Inhalt übernommen' );
$editor[] = $vorstand;
$urls[]   = '/unser-vorstand/';

t_section( 'Aktionen (Zeitleiste)' );
$ac     = get_post( $aktionen )->post_content;
$blocks = parse_blocks( $ac );
t_eq( $blocks[0]['blockName'], 'fvbb/zeitleiste', 'Timeline Block → Zeitleiste' );
t_eq( count( $blocks[0]['attrs']['items'] ?? array() ), 7, 'Alle 7 Einträge übernommen' );
t_eq( $blocks[0]['attrs']['items'][0]['date'] ?? '', '26. Oktober 2025', 'Sichtbares Datum (nicht der Platzhalter „2003“)' );
t_eq( $blocks[0]['attrs']['items'][3]['text'] ?? '', "Teilnahme am Lampertheimer Vereinsfrühschoppen.\nThemen:\nNachwuchssorgen\nSichtbarkeit der Vereine", 'Zeilenumbrüche im Text bleiben' );
t_has( $ac, 'Aktuell laufende Aktionen', 'Restlicher Inhalt bleibt' );
$ah = do_blocks( $ac );
t_eq( substr_count( $ah, 'fvbb-timeline__year' ), 3, 'Drei Jahre (2025, 2024, 2023)' );
$editor[] = $aktionen;
$urls[]   = '/aktionen/';

t_section( 'Hinweis (Popup)' );
$n = get_posts( array( 'post_type' => 'fvbb_notice', 'numberposts' => 1 ) );
t_ok( (bool) $n, 'Hinweis angelegt' );
if ( $n ) {
	t_eq( get_the_title( $n[0] ), 'Neuer Newsletter 1 – 2026', 'Titel aus dem Popup' );
	t_ok( (bool) get_post_meta( $n[0]->ID, '_fvbb_active', true ), 'Hinweis ist aktiv' );
	t_eq( get_post_meta( $n[0]->ID, '_fvbb_scope', true ), 'all', 'Auf allen Seiten (wie bisher)' );
}

t_section( 'Rückgängig und erneut' );
t_eq( FVBB_Migration::undo(), 3, 'Rückgängig stellt 3 Inhalte wieder her' );
t_has( get_post( $front )->post_content, 'data-export-id', 'Startseite wieder im alten Zustand' );
FVBB_Migration::run_content();
t_eq( count( get_posts( array( 'post_type' => 'fvbb_table', 'numberposts' => -1 ) ) ), 1, 'Erneute Umstellung legt keine doppelte Tabelle an' );
t_ok( false === strpos( get_post( $front )->post_content, 'data-export-id' ), 'Startseite wieder umgestellt' );

/* =========================================================================
 * Theme
 * ====================================================================== */
t_section( 'Theme: alte Einstellungen' );
t_eq( (int) get_theme_mod( 'custom_logo' ), $logo, 'Logo aus EmpowerWP übernommen' );
t_eq( fvbb_opt( 'hero_title' ), 'Förderverein Biedensand Bäder Lampertheim e.V.', 'Titel im Kopfbild übernommen' );
t_eq( fvbb_opt( 'hero_btn1_label' ), 'Der FV auf Instagram', 'Instagram-Button übernommen' );
t_eq( fvbb_header_image_ids( true ), array( $hero ), 'Kopfbild der Startseite (http-Adresse) gefunden' );
t_eq( fvbb_header_image_ids( false ), array( $inner ), 'Kopfbild der Unterseiten übernommen' );
t_eq( fvbb_opt( 'social_1' ), 'mailto:info@fv-biedensand-baeder.de', 'Mail-Symbol im Footer übernommen' );

t_section( 'Theme: Menü und Seiten' );
$nav = wp_nav_menu( array( 'theme_location' => 'primary', 'echo' => false ) );
t_has( $nav, 'menu-item-cta', '„Jetzt Mitglied werden!“ als Button hervorgehoben' );
t_eq( substr_count( $nav, 'menu-item-cta' ), 1, 'Nur ein Menüpunkt hervorgehoben' );
$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $front ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
t_eq( fvbb_first_section_color(), '', 'Welle unter dem Kopfbild bleibt weiß (Karten ragen hinein)' );
wp_reset_query();
foreach ( $pages as $slug => $pid ) {
	$urls[] = '/' . $slug . '/';
}
$urls[] = '/mitgliedsantrag/';
$urls[] = '/blog/';

/* =========================================================================
 * Ergebnis
 * ====================================================================== */
file_put_contents( __DIR__ . '/ci/urls.txt', implode( "\n", array_unique( $urls ) ) . "\n" );
file_put_contents( __DIR__ . '/ci/editor-ids.txt', implode( ' ', $editor ) . "\n" );

$t = $GLOBALS['fvbb_t'];
echo "\n" . $t['pass'] . ' Prüfungen bestanden, ' . count( $t['fail'] ) . " fehlgeschlagen.\n";
if ( $t['fail'] ) {
	echo "\nFehlgeschlagen:\n - " . implode( "\n - ", $t['fail'] ) . "\n";
	exit( 1 );
}
