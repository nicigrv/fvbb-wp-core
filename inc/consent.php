<?php
/**
 * Eigener Cookie-Banner (ersetzt CookieYes).
 *
 * Kategorien:
 *  - notwendig: immer an (Login, Banner-Entscheidung selbst)
 *  - ext:       Externe Inhalte (eingebettete Videos, Karten oder Seiten anderer Anbieter)
 *
 * Externe iFrames werden automatisch durch einen Platzhalter ersetzt und erst nach
 * Zustimmung geladen.
 *
 * @package fvbb-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Banner-Einstellungen.
 *
 * @return array
 */
function fvbb_consent_settings() {
	$imprint = get_page_by_path( 'impressum' );
	$privacy = get_privacy_policy_url();
	if ( ! $privacy ) {
		foreach ( array( 'datenschutzerklaerung', 'datenschutz' ) as $slug ) {
			$pp = get_page_by_path( $slug );
			if ( $pp && 'publish' === $pp->post_status ) {
				$privacy = get_permalink( $pp );
				break;
			}
		}
	}
	$d       = array(
		'enabled'     => 1,
		'title'       => __( 'Wir schätzen deine Privatsphäre', 'fvbb-core' ),
		'text'        => __( 'Wir verwenden nur technisch notwendige Cookies. Externe Inhalte (z. B. eingebettete Videos oder Karten) laden wir erst, wenn du zustimmst. Deine Wahl kannst du jederzeit unten auf der Seite unter „Cookie-Einstellungen“ ändern.', 'fvbb-core' ),
		'ext_label'   => __( 'Externe Inhalte', 'fvbb-core' ),
		'ext_text'    => __( 'Eingebettete Videos, Karten oder Seiten anderer Anbieter. Dabei werden Daten wie deine IP-Adresse an den jeweiligen Anbieter übertragen.', 'fvbb-core' ),
		'privacy_url' => $privacy,
		'imprint_url' => $imprint ? get_permalink( $imprint ) : '',
	);
	$o = get_option( 'fvbb_consent', array() );
	return wp_parse_args( is_array( $o ) ? $o : array(), $d );
}

/**
 * Hat der Besucher (laut Cookie) zugestimmt? Nur für Ausnahmefälle –
 * die Ausgabe arbeitet sonst cache-sicher über JavaScript.
 *
 * @param string $cat Kategorie.
 * @return bool
 */
function fvbb_has_consent( $cat = 'ext' ) {
	$c = isset( $_COOKIE['fvbb_consent'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['fvbb_consent'] ) ) : '';
	return in_array( $cat, explode( '.', $c ), true );
}

/**
 * Externes iFrame durch Platzhalter ersetzen.
 *
 * @param string $iframe  iFrame-HTML.
 * @param string $src     Quelle.
 * @param int    $height  Höhe (px) für den Platzhalter.
 * @return string
 */
function fvbb_consent_gate_iframe( $iframe, $src, $height = 0 ) {
	$s = fvbb_consent_settings();
	if ( empty( $s['enabled'] ) || fvbb_is_own_host( $src ) ) {
		return $iframe;
	}
	$host = preg_replace( '/^www\./', '', (string) wp_parse_url( $src, PHP_URL_HOST ) );
	$min  = $height ? min( (int) $height, 520 ) : 320;
	ob_start();
	?>
	<div class="fvbb-gate" data-fvbb-consent="ext" style="min-height:<?php echo (int) $min; ?>px">
		<template><?php echo $iframe; // phpcs:ignore ?></template>
		<div class="fvbb-gate__box">
			<p class="fvbb-gate__title"><?php esc_html_e( 'Externer Inhalt', 'fvbb-core' ); ?></p>
			<p class="fvbb-gate__text">
				<?php
				/* translators: %s: Anbieter-Domain */
				printf( esc_html__( 'Hier wird ein Inhalt von %s angezeigt. Beim Laden werden Daten wie deine IP-Adresse an diesen Anbieter übertragen.', 'fvbb-core' ), '<strong>' . esc_html( $host ) . '</strong>' );
				?>
			</p>
			<p class="fvbb-gate__actions">
				<button type="button" class="fvbb-btn fvbb-btn--primary" data-fvbb-load><?php esc_html_e( 'Inhalt laden', 'fvbb-core' ); ?></button>
				<button type="button" class="fvbb-btn fvbb-btn--ghost" data-fvbb-allow="ext"><?php esc_html_e( 'Externe Inhalte immer erlauben', 'fvbb-core' ); ?></button>
			</p>
			<?php if ( $s['privacy_url'] ) : ?>
				<a class="fvbb-gate__link" href="<?php echo esc_url( $s['privacy_url'] ); ?>"><?php esc_html_e( 'Mehr in der Datenschutzerklärung', 'fvbb-core' ); ?></a>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return trim( ob_get_clean() );
}

/**
 * Alle externen iFrames in einem HTML-Stück absichern.
 *
 * @param string $html HTML.
 * @return string
 */
function fvbb_consent_gate_html( $html ) {
	if ( false === stripos( $html, '<iframe' ) || false !== strpos( $html, 'fvbb-gate' ) ) {
		return $html;
	}
	return preg_replace_callback( '#<iframe\b[^>]*>.*?</iframe>#is', function ( $m ) {
		if ( ! preg_match( '#\ssrc=("|\')(.*?)\1#i', $m[0], $src ) ) {
			return $m[0];
		}
		$height = preg_match( '#height=("|\')?(\d+)#i', $m[0], $hm ) ? (int) $hm[2] : 0;
		return fvbb_consent_gate_iframe( $m[0], html_entity_decode( $src[2] ), $height );
	}, $html );
}

add_filter( 'render_block', function ( $html, $block ) {
	if ( is_admin() || 'fvbb/iframe' === ( $block['blockName'] ?? '' ) ) {
		return $html;
	}
	return fvbb_consent_gate_html( $html );
}, 20, 2 );

add_filter( 'the_content', function ( $html ) {
	return is_admin() ? $html : fvbb_consent_gate_html( $html );
}, 999 );

add_filter( 'widget_text_content', 'fvbb_consent_gate_html', 999 );
add_filter( 'widget_block_content', 'fvbb_consent_gate_html', 999 );

/* Frühe Markierung: Banner noch offen? (verhindert Flackern) */
add_action( 'wp_head', function () {
	$s = fvbb_consent_settings();
	if ( empty( $s['enabled'] ) ) {
		return;
	}
	echo "<script>(function(){if(!/(^|;\\s*)fvbb_consent=/.test(document.cookie)){document.documentElement.classList.add('fvbb-consent-pending');}})();</script>\n";
}, 2 );

add_action( 'wp_enqueue_scripts', function () {
	$s = fvbb_consent_settings();
	if ( empty( $s['enabled'] ) ) {
		return;
	}
	wp_enqueue_style( 'fvbb-consent', FVBB_CORE_URL . 'assets/consent.css', array(), fvbb_core_ver( 'assets/consent.css' ) );
	wp_enqueue_script( 'fvbb-consent', FVBB_CORE_URL . 'assets/consent.js', array(), fvbb_core_ver( 'assets/consent.js' ), array( 'in_footer' => true ) );
} );

add_action( 'wp_footer', function () {
	$s = fvbb_consent_settings();
	if ( empty( $s['enabled'] ) ) {
		return;
	}
	?>
	<div class="fvbb-consent" id="fvbb-consent" role="dialog" aria-modal="false" aria-labelledby="fvbb-consent-title" aria-describedby="fvbb-consent-text" hidden>
		<p class="fvbb-consent__title" id="fvbb-consent-title"><?php echo esc_html( $s['title'] ); ?></p>
		<p class="fvbb-consent__text" id="fvbb-consent-text"><?php echo esc_html( $s['text'] ); ?></p>
		<div class="fvbb-consent__details" hidden>
			<label class="fvbb-consent__opt">
				<input type="checkbox" checked disabled>
				<span><strong><?php esc_html_e( 'Notwendig', 'fvbb-core' ); ?></strong><br><?php esc_html_e( 'Speichert deine Auswahl und hält Anmeldungen aufrecht. Immer aktiv.', 'fvbb-core' ); ?></span>
			</label>
			<label class="fvbb-consent__opt">
				<input type="checkbox" name="fvbb-ext" value="ext">
				<span><strong><?php echo esc_html( $s['ext_label'] ); ?></strong><br><?php echo esc_html( $s['ext_text'] ); ?></span>
			</label>
		</div>
		<div class="fvbb-consent__actions">
			<button type="button" class="fvbb-btn fvbb-btn--primary" data-fvbb-choice="all"><?php esc_html_e( 'Alle akzeptieren', 'fvbb-core' ); ?></button>
			<button type="button" class="fvbb-btn fvbb-btn--secondary" data-fvbb-choice="none"><?php esc_html_e( 'Nur notwendige', 'fvbb-core' ); ?></button>
			<button type="button" class="fvbb-btn fvbb-btn--text" data-fvbb-details><?php esc_html_e( 'Einstellungen', 'fvbb-core' ); ?></button>
			<button type="button" class="fvbb-btn fvbb-btn--secondary" data-fvbb-choice="save" hidden><?php esc_html_e( 'Auswahl speichern', 'fvbb-core' ); ?></button>
		</div>
		<p class="fvbb-consent__links">
			<?php if ( $s['privacy_url'] ) : ?><a href="<?php echo esc_url( $s['privacy_url'] ); ?>"><?php esc_html_e( 'Datenschutz', 'fvbb-core' ); ?></a><?php endif; ?>
			<?php if ( $s['imprint_url'] ) : ?><a href="<?php echo esc_url( $s['imprint_url'] ); ?>"><?php esc_html_e( 'Impressum', 'fvbb-core' ); ?></a><?php endif; ?>
		</p>
	</div>
	<?php
}, 5 );

/* Link "Cookie-Einstellungen" im Footer des Themes und als Shortcode */
function fvbb_consent_settings_button() {
	return '<button type="button" class="fvbb-consent-open" data-fvbb-open>' . esc_html__( 'Cookie-Einstellungen', 'fvbb-core' ) . '</button>';
}
add_action( 'fvbb_footer_bottom', function () {
	$s = fvbb_consent_settings();
	if ( ! empty( $s['enabled'] ) ) {
		echo fvbb_consent_settings_button(); // phpcs:ignore
	}
} );
add_shortcode( 'fvbb_cookie_einstellungen', 'fvbb_consent_settings_button' );

/* Einstellungen → Cookie-Banner */
add_action( 'admin_menu', function () {
	add_options_page( __( 'Cookie-Banner', 'fvbb-core' ), __( 'Cookie-Banner', 'fvbb-core' ), 'manage_options', 'fvbb-consent', 'fvbb_consent_admin_page' );
} );

/**
 * Admin-Seite.
 */
function fvbb_consent_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['fvbb_consent_save'] ) && check_admin_referer( 'fvbb_consent' ) ) {
		$in = wp_unslash( $_POST ); // phpcs:ignore
		update_option( 'fvbb_consent', array(
			'enabled'     => empty( $in['enabled'] ) ? 0 : 1,
			'title'       => sanitize_text_field( $in['title'] ?? '' ),
			'text'        => sanitize_textarea_field( $in['text'] ?? '' ),
			'ext_label'   => sanitize_text_field( $in['ext_label'] ?? '' ),
			'ext_text'    => sanitize_textarea_field( $in['ext_text'] ?? '' ),
			'privacy_url' => esc_url_raw( $in['privacy_url'] ?? '' ),
			'imprint_url' => esc_url_raw( $in['imprint_url'] ?? '' ),
		) );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Gespeichert.', 'fvbb-core' ) . '</p></div>';
	}
	$s = fvbb_consent_settings();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Cookie-Banner', 'fvbb-core' ); ?></h1>
		<p><?php esc_html_e( 'Externe Inhalte (iFrames anderer Anbieter) werden erst nach Zustimmung geladen. Inhalte der eigenen Domain gelten als eigene Inhalte.', 'fvbb-core' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'fvbb_consent' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><?php esc_html_e( 'Banner aktiv', 'fvbb-core' ); ?></th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> <?php esc_html_e( 'Banner anzeigen und externe Inhalte blockieren', 'fvbb-core' ); ?></label></td></tr>
				<tr><th><label for="t"><?php esc_html_e( 'Überschrift', 'fvbb-core' ); ?></label></th><td><input id="t" class="regular-text" name="title" value="<?php echo esc_attr( $s['title'] ); ?>"></td></tr>
				<tr><th><label for="x"><?php esc_html_e( 'Text', 'fvbb-core' ); ?></label></th><td><textarea id="x" class="large-text" rows="4" name="text"><?php echo esc_textarea( $s['text'] ); ?></textarea></td></tr>
				<tr><th><label for="el"><?php esc_html_e( 'Kategorie „Extern“: Name', 'fvbb-core' ); ?></label></th><td><input id="el" class="regular-text" name="ext_label" value="<?php echo esc_attr( $s['ext_label'] ); ?>"></td></tr>
				<tr><th><label for="et"><?php esc_html_e( 'Kategorie „Extern“: Beschreibung', 'fvbb-core' ); ?></label></th><td><textarea id="et" class="large-text" rows="3" name="ext_text"><?php echo esc_textarea( $s['ext_text'] ); ?></textarea></td></tr>
				<tr><th><label for="pu"><?php esc_html_e( 'Link Datenschutzerklärung', 'fvbb-core' ); ?></label></th><td><input id="pu" class="regular-text" type="url" name="privacy_url" value="<?php echo esc_attr( $s['privacy_url'] ); ?>"></td></tr>
				<tr><th><label for="iu"><?php esc_html_e( 'Link Impressum', 'fvbb-core' ); ?></label></th><td><input id="iu" class="regular-text" type="url" name="imprint_url" value="<?php echo esc_attr( $s['imprint_url'] ); ?>"></td></tr>
			</table>
			<?php submit_button( __( 'Speichern', 'fvbb-core' ), 'primary', 'fvbb_consent_save' ); ?>
		</form>
	</div>
	<?php
}
