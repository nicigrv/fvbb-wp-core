<?php
// Router für den eingebauten PHP-Server (php -S) – nur für die Tests auf GitHub.
$root = getenv( 'WP_DIR' ) ?: '/tmp/wp';
$uri  = urldecode( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
if ( '/' !== $uri && file_exists( $root . $uri ) && ! is_dir( $root . $uri ) ) {
	if ( '.php' === substr( $uri, -4 ) ) {
		$_SERVER['SCRIPT_NAME'] = $uri;
		chdir( dirname( $root . $uri ) );
		require $root . $uri;
		return true;
	}
	return false;
}
if ( is_dir( $root . $uri ) && file_exists( $root . rtrim( $uri, '/' ) . '/index.php' ) ) {
	$_SERVER['SCRIPT_NAME'] = rtrim( $uri, '/' ) . '/index.php';
	chdir( $root . rtrim( $uri, '/' ) );
	require $root . rtrim( $uri, '/' ) . '/index.php';
	return true;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
chdir( $root );
require $root . '/index.php';
