<?php
/**
 * Mini-Testhelfer (ohne PHPUnit/Composer). Läuft per `wp eval-file tests/run.php`.
 *
 * @package fvbb-core
 */

$GLOBALS['fvbb_t'] = array( 'pass' => 0, 'fail' => array(), 'section' => '' );

function t_section( $name ) {
	$GLOBALS['fvbb_t']['section'] = $name;
	echo "\n== {$name}\n";
}

function t_ok( $cond, $msg ) {
	if ( $cond ) {
		$GLOBALS['fvbb_t']['pass']++;
		echo "  ok   {$msg}\n";
	} else {
		$GLOBALS['fvbb_t']['fail'][] = $GLOBALS['fvbb_t']['section'] . ': ' . $msg;
		echo "  FAIL {$msg}\n";
	}
}

function t_eq( $actual, $expected, $msg ) {
	$ok = $actual === $expected;
	t_ok( $ok, $msg . ( $ok ? '' : ' – erwartet ' . var_export( $expected, true ) . ', erhalten ' . var_export( $actual, true ) ) );
}

function t_has( $haystack, $needle, $msg ) {
	t_ok( false !== strpos( (string) $haystack, (string) $needle ), $msg );
}

function t_not( $haystack, $needle, $msg ) {
	t_ok( false === strpos( (string) $haystack, (string) $needle ), $msg );
}

/**
 * Bild in der Mediathek anlegen: an der gleichen Stelle wie auf der echten Website.
 * Erst wird das Original von fv-biedensand-baeder.de geladen, sonst ein Platzhalter erzeugt.
 *
 * @param string $rel   Pfad unter uploads/ (z. B. 2025/02/bild.jpg).
 * @param string $title Titel.
 * @return int Anhang-ID.
 */
function t_image( $rel, $title = '' ) {
	static $done = array();
	if ( isset( $done[ $rel ] ) ) {
		return $done[ $rel ];
	}
	$up   = wp_get_upload_dir();
	$file = $up['basedir'] . '/' . $rel;
	wp_mkdir_p( dirname( $file ) );
	if ( ! file_exists( $file ) ) {
		$res = wp_remote_get( 'https://fv-biedensand-baeder.de/wp-content/uploads/' . $rel, array( 'timeout' => 20 ) );
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) && strlen( wp_remote_retrieve_body( $res ) ) > 500 ) {
			file_put_contents( $file, wp_remote_retrieve_body( $res ) ); // phpcs:ignore
		} else {
			$im = imagecreatetruecolor( 800, 600 );
			for ( $y = 0; $y < 600; $y++ ) {
				$c = imagecolorallocate( $im, (int) ( 96 + $y / 8 ), (int) ( 176 - $y / 12 ), 216 );
				imageline( $im, 0, $y, 800, $y, $c );
			}
			imagefilledellipse( $im, 640, 120, 140, 140, imagecolorallocate( $im, 248, 176, 0 ) );
			if ( preg_match( '/\.webp$/i', $file ) && function_exists( 'imagewebp' ) ) {
				imagewebp( $im, $file );
			} elseif ( preg_match( '/\.png$/i', $file ) ) {
				imagepng( $im, $file );
			} else {
				imagejpeg( $im, $file, 85 );
			}
			imagedestroy( $im );
		}
	}
	$type = wp_check_filetype( $file );
	$id   = wp_insert_attachment( array(
		'post_title'     => $title ? $title : pathinfo( $file, PATHINFO_FILENAME ),
		'post_mime_type' => $type['type'] ? $type['type'] : 'image/jpeg',
		'post_status'    => 'inherit',
	), $file );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
	$done[ $rel ] = (int) $id;
	return (int) $id;
}
