<?php
/**
 * Block "Symbol": rundes Symbol (z. B. über den „Wir …“-Karten auf der Startseite).
 * Ersetzt die Font-Awesome-Symbole des alten Theme-Baukastens.
 *
 * @package fvbb-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verfügbare Symbole (24×24, Linien).
 *
 * @return array slug => [Bezeichnung, SVG-Pfade]
 */
function fvbb_symbols() {
	return array(
		'handshake' => array( __( 'Handschlag', 'fvbb-core' ), '<path d="M11 17l-1.5 1.5a2.1 2.1 0 0 1-3-3L9 13"/><path d="M13 7l-2.4-1.8a2 2 0 0 0-2.3 0L3 9v6l2 1"/><path d="M21 15V9l-5.3-3.6a2 2 0 0 0-2.3.1L9.5 8.6a1.9 1.9 0 0 0 2.4 3l2.1-1.3 4 3.4a1.8 1.8 0 0 1-2.4 2.6L14 15"/><path d="M12.5 17.5l1 1a2 2 0 0 0 2.8-2.8"/>' ),
		'drop'      => array( __( 'Wassertropfen', 'fvbb-core' ), '<path d="M12 3s-6 6.6-6 11a6 6 0 0 0 12 0c0-4.4-6-11-6-11z"/><path d="M9.5 14.5a2.5 2.5 0 0 0 2.5 2.5"/>' ),
		'child'     => array( __( 'Kind', 'fvbb-core' ), '<circle cx="12" cy="4.5" r="2.2"/><path d="M5 9l4 1.2h6L19 9"/><path d="M9 10.2V21M15 10.2V21M9 15.5h6"/>' ),
		'waves'     => array( __( 'Wellen', 'fvbb-core' ), '<path d="M2 8c2.5-2 5-2 7.5 0s5 2 7.5 0 3.5-1.6 5-.4"/><path d="M2 13c2.5-2 5-2 7.5 0s5 2 7.5 0 3.5-1.6 5-.4"/><path d="M2 18c2.5-2 5-2 7.5 0s5 2 7.5 0 3.5-1.6 5-.4"/>' ),
		'swim'      => array( __( 'Schwimmer', 'fvbb-core' ), '<circle cx="17" cy="7" r="2"/><path d="M3 12l5-3.5 4 2.5 3-1.5"/><path d="M2 17c2-1.6 4-1.6 6 0s4 1.6 6 0 4-1.6 6 0"/><path d="M2 21c2-1.6 4-1.6 6 0s4 1.6 6 0 4-1.6 6 0"/>' ),
		'sun'       => array( __( 'Sonne', 'fvbb-core' ), '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>' ),
		'heart'     => array( __( 'Herz', 'fvbb-core' ), '<path d="M12 20s-7.5-4.6-9.2-9.4A4.8 4.8 0 0 1 12 6.3a4.8 4.8 0 0 1 9.2 4.3C19.5 15.4 12 20 12 20z"/>' ),
		'users'     => array( __( 'Gemeinschaft', 'fvbb-core' ), '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><circle cx="17" cy="9" r="2.4"/><path d="M16 14.2a4.8 4.8 0 0 1 5 4.8"/>' ),
		'star'      => array( __( 'Stern', 'fvbb-core' ), '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>' ),
		'euro'      => array( __( 'Euro', 'fvbb-core' ), '<path d="M17.5 6.5A7 7 0 1 0 17.5 17.5"/><path d="M4 10h9M4 14h9"/>' ),
		'calendar'  => array( __( 'Kalender', 'fvbb-core' ), '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M8 3v4M16 3v4M3.5 10h17"/>' ),
		'megaphone' => array( __( 'Megafon', 'fvbb-core' ), '<path d="M3 10.5v3a1.5 1.5 0 0 0 1.5 1.5H7l7 4V5L7 9H4.5A1.5 1.5 0 0 0 3 10.5z"/><path d="M18 9a4 4 0 0 1 0 6"/><path d="M8 15l1 5"/>' ),
		'mail'      => array( __( 'E-Mail', 'fvbb-core' ), '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M4 7l8 6 8-6"/>' ),
		'leaf'      => array( __( 'Blatt', 'fvbb-core' ), '<path d="M5 19c0-8 5-13 15-14-1 10-6 15-14 15"/><path d="M5 19l7-7"/>' ),
	);
}

/**
 * Font-Awesome-Klasse aus dem alten Baukasten → Symbol.
 *
 * @param string $class Klassen des <i>-Elements.
 * @return string Slug oder ''.
 */
function fvbb_symbol_from_fa( $class ) {
	$map = array(
		'handshake'  => 'handshake',
		'tint'       => 'drop',
		'drop'       => 'drop',
		'child'      => 'child',
		'life-ring'  => 'swim',
		'swim'       => 'swim',
		'sun'        => 'sun',
		'heart'      => 'heart',
		'users'      => 'users',
		'group'      => 'users',
		'user'       => 'users',
		'star'       => 'star',
		'eur'        => 'euro',
		'money'      => 'euro',
		'calendar'   => 'calendar',
		'bullhorn'   => 'megaphone',
		'envelope'   => 'mail',
		'leaf'       => 'leaf',
		'tree'       => 'leaf',
		'water'      => 'waves',
		'ship'       => 'waves',
	);
	if ( preg_match_all( '/\bfa-([a-z0-9-]+)/', (string) $class, $m ) ) {
		foreach ( $m[1] as $name ) {
			$name = preg_replace( '/-(o|alt|square|circle)$/', '', $name );
			if ( isset( $map[ $name ] ) ) {
				return $map[ $name ];
			}
		}
	}
	return '';
}

/**
 * SVG eines Symbols.
 *
 * @param string $slug Symbol.
 * @param int    $size Größe.
 * @return string
 */
function fvbb_symbol_svg( $slug, $size = 38 ) {
	$all = fvbb_symbols();
	if ( ! isset( $all[ $slug ] ) ) {
		$slug = 'sun';
	}
	return '<svg width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $all[ $slug ][1] . '</svg>';
}

add_action( 'init', function () {
	wp_register_script(
		'fvbb-symbol-block',
		FVBB_CORE_URL . 'assets/symbol-block.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ),
		fvbb_core_ver( 'assets/symbol-block.js' ),
		true
	);
	$opts = array();
	foreach ( fvbb_symbols() as $slug => $s ) {
		$opts[] = array( 'value' => $slug, 'label' => $s[0] );
	}
	wp_localize_script( 'fvbb-symbol-block', 'fvbbSymbols', array( 'options' => $opts ) );

	register_block_type( 'fvbb/symbol', array(
		'api_version'     => 3,
		'title'           => __( 'Symbol', 'fvbb-core' ),
		'description'     => __( 'Rundes Symbol, z. B. über einer Karte.', 'fvbb-core' ),
		'category'        => 'design',
		'icon'            => 'star-filled',
		'keywords'        => array( 'symbol', 'icon', 'kreis' ),
		'editor_script'   => 'fvbb-symbol-block',
		'supports'        => array( 'html' => false ),
		'attributes'      => array(
			'icon'  => array( 'type' => 'string', 'default' => 'sun' ),
			'color' => array( 'type' => 'string', 'default' => 'blue' ),
			'left'  => array( 'type' => 'boolean', 'default' => false ),
			'label' => array( 'type' => 'string', 'default' => '' ),
		),
		'render_callback' => 'fvbb_symbol_render',
	) );
} );

/**
 * Block ausgeben.
 *
 * @param array $a Attribute.
 * @return string
 */
function fvbb_symbol_render( $a ) {
	$color = in_array( $a['color'] ?? '', array( 'blue', 'sun', 'sky' ), true ) ? $a['color'] : 'blue';
	$cls   = 'fvbb-symbol is-' . $color . ( ! empty( $a['left'] ) ? ' is-align-left' : '' );
	$label = trim( (string) ( $a['label'] ?? '' ) );
	$aria  = $label ? ' role="img" aria-label="' . esc_attr( $label ) . '"' : ' aria-hidden="true"';
	return '<div class="' . esc_attr( $cls ) . '"><span class="fvbb-symbol__circle"' . $aria . '>' . fvbb_symbol_svg( (string) ( $a['icon'] ?? 'sun' ) ) . '</span></div>';
}
