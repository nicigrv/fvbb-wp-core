<?php
/**
 * Block "Zeitleiste" für die Seite „Aktionen“ (ersetzt das Plugin „Timeline Block“).
 * Einträge (Datum + Text) werden direkt im Block bearbeitet und nach Jahren gruppiert angezeigt.
 *
 * @package fvbb-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	wp_register_style( 'fvbb-timeline', FVBB_CORE_URL . 'assets/timeline.css', array(), fvbb_core_ver( 'assets/timeline.css' ) );
	wp_register_script(
		'fvbb-timeline-block',
		FVBB_CORE_URL . 'assets/timeline-block.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ),
		fvbb_core_ver( 'assets/timeline-block.js' ),
		true
	);

	register_block_type( 'fvbb/zeitleiste', array(
		'api_version'     => 3,
		'title'           => __( 'Zeitleiste', 'fvbb-core' ),
		'description'     => __( 'Aktionen mit Datum und Text, nach Jahren gruppiert.', 'fvbb-core' ),
		'category'        => 'widgets',
		'icon'            => 'backup',
		'keywords'        => array( 'zeitleiste', 'timeline', 'aktionen', 'chronik', 'termine' ),
		'editor_script'   => 'fvbb-timeline-block',
		'style'           => 'fvbb-timeline',
		'editor_style'    => 'fvbb-timeline',
		'supports'        => array( 'html' => false, 'align' => array( 'wide' ) ),
		'attributes'      => array(
			'items'  => array(
				'type'    => 'array',
				'default' => array(),
				'items'   => array( 'type' => 'object' ),
			),
			'years'  => array( 'type' => 'boolean', 'default' => true ),
			'sorted' => array( 'type' => 'boolean', 'default' => true ),
		),
		'render_callback' => 'fvbb_timeline_render',
	) );
} );

/**
 * Monatsnamen → Nummer (für die Sortierung).
 *
 * @return array
 */
function fvbb_timeline_months() {
	return array(
		'januar' => 1, 'jan' => 1, 'jänner' => 1, 'februar' => 2, 'feb' => 2, 'märz' => 3, 'maerz' => 3, 'mär' => 3, 'mrz' => 3,
		'april' => 4, 'apr' => 4, 'mai' => 5, 'juni' => 6, 'jun' => 6, 'juli' => 7, 'jul' => 7, 'august' => 8, 'aug' => 8,
		'september' => 9, 'sep' => 9, 'sept' => 9, 'oktober' => 10, 'okt' => 10, 'november' => 11, 'nov' => 11, 'dezember' => 12, 'dez' => 12,
	);
}

/**
 * Datum aus einer frei geschriebenen Angabe lesen („26. Oktober 2025“, „30. September2023“, „12.03.2025“, „Mai 2024“).
 *
 * @param string $label Datumstext.
 * @return array [Jahr, Monat, Tag] – 0, wenn unbekannt.
 */
function fvbb_timeline_parse_date( $label ) {
	$l = mb_strtolower( trim( (string) $label ) );
	if ( preg_match( '/(\d{1,2})\.(\d{1,2})\.(\d{4}|\d{2})\b/', $l, $m ) ) {
		$y = (int) $m[3];
		return array( $y < 100 ? 2000 + $y : $y, (int) $m[2], (int) $m[1] );
	}
	$y = preg_match( '/(19|20)\d{2}/', $l, $ym ) ? (int) $ym[0] : 0;
	$mo = 0;
	foreach ( fvbb_timeline_months() as $name => $num ) {
		if ( preg_match( '/(^|[^\p{L}])' . preg_quote( $name, '/' ) . '(?=[^\p{L}]|\d|$)/u', $l ) ) {
			$mo = $num;
			break;
		}
	}
	$d = preg_match( '/^(\d{1,2})\./', $l, $dm ) ? (int) $dm[1] : 0;
	return array( $y, $mo, $d );
}

/**
 * Einträge säubern.
 *
 * @param mixed $items Rohdaten.
 * @return array
 */
function fvbb_timeline_clean_items( $items ) {
	$out = array();
	foreach ( (array) $items as $it ) {
		if ( ! is_array( $it ) ) {
			continue;
		}
		$date  = trim( (string) ( $it['date'] ?? '' ) );
		$title = trim( (string) ( $it['title'] ?? '' ) );
		$text  = trim( (string) ( $it['text'] ?? '' ) );
		if ( '' === $date && '' === $title && '' === $text ) {
			continue;
		}
		$out[] = array( 'date' => $date, 'title' => $title, 'text' => $text );
	}
	return $out;
}

/**
 * Text eines Eintrags: Absätze, Zeilenumbrüche, Links.
 *
 * @param string $text Text.
 * @return string
 */
function fvbb_timeline_text( $text ) {
	$text = str_replace( array( "\r\n", "\r", "\t" ), array( "\n", "\n", ' ' ), (string) $text );
	$html = '';
	foreach ( preg_split( "/\n{2,}/", trim( $text ) ) as $para ) {
		$para = trim( $para );
		if ( '' === $para ) {
			continue;
		}
		$html .= '<p>' . make_clickable( nl2br( esc_html( $para ), false ) ) . '</p>';
	}
	return $html;
}

/**
 * Block ausgeben.
 *
 * @param array $a Attribute.
 * @return string
 */
function fvbb_timeline_render( $a ) {
	$items = fvbb_timeline_clean_items( $a['items'] ?? array() );
	if ( ! $items ) {
		return is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ? '<p>' . esc_html__( 'Noch keine Einträge.', 'fvbb-core' ) . '</p>' : '';
	}
	foreach ( $items as $i => $it ) {
		$items[ $i ]['_d'] = fvbb_timeline_parse_date( $it['date'] );
		$items[ $i ]['_i'] = $i;
	}
	if ( ! isset( $a['sorted'] ) || $a['sorted'] ) {
		// Neueste zuerst; Einträge ohne erkennbares Datum behalten ihre Position relativ zueinander.
		usort( $items, function ( $x, $y ) {
			$c = array( $y['_d'][0], $y['_d'][1], $y['_d'][2] ) <=> array( $x['_d'][0], $x['_d'][1], $x['_d'][2] );
			return 0 !== $c ? $c : $x['_i'] <=> $y['_i'];
		} );
	}
	$by_year = ! isset( $a['years'] ) || $a['years'];
	$align   = ! empty( $a['align'] ) && 'wide' === $a['align'] ? ' alignwide' : '';

	$html = '<div class="fvbb-timeline' . $align . '">';
	$year = null;
	$open = false;
	foreach ( $items as $it ) {
		$y = $it['_d'][0];
		if ( $by_year && $y && $y !== $year ) {
			if ( $open ) {
				$html .= '</ol>';
			}
			$html .= '<h3 class="fvbb-timeline__year">' . esc_html( (string) $y ) . '</h3><ol class="fvbb-timeline__list">';
			$year  = $y;
			$open  = true;
		} elseif ( ! $open ) {
			$html .= '<ol class="fvbb-timeline__list">';
			$open  = true;
		}
		$html .= '<li class="fvbb-timeline__item">';
		if ( '' !== $it['date'] ) {
			$html .= '<p class="fvbb-timeline__date">' . esc_html( $it['date'] ) . '</p>';
		}
		$html .= '<div class="fvbb-timeline__card">';
		if ( '' !== $it['title'] ) {
			$html .= '<h4 class="fvbb-timeline__title">' . esc_html( $it['title'] ) . '</h4>';
		}
		$html .= fvbb_timeline_text( $it['text'] ) . '</div></li>';
	}
	if ( $open ) {
		$html .= '</ol>';
	}
	return $html . '</div>';
}
