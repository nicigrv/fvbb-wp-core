<?php
/**
 * Hilfsfunktionen.
 *
 * @package fvbb-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ist ein Host "unser eigener" (eigene Domain und Subdomains)?
 *
 * @param string $url URL.
 * @return bool
 */
function fvbb_is_own_host( $url ) {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( '' === $host ) {
		return true;
	}
	$own = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$own = preg_replace( '/^www\./', '', $own );
	$list = apply_filters( 'fvbb_own_hosts', array( $own ) );
	foreach ( $list as $o ) {
		if ( $host === $o || substr( $host, -strlen( '.' . $o ) ) === '.' . $o ) {
			return true;
		}
	}
	return false;
}

/**
 * Asset-Version anhand Dateizeit.
 *
 * @param string $rel Pfad relativ zum Plugin.
 * @return string
 */
function fvbb_core_ver( $rel ) {
	$f = FVBB_CORE_DIR . $rel;
	return file_exists( $f ) ? (string) filemtime( $f ) : FVBB_CORE_VERSION;
}
