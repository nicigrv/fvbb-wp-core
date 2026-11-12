<?php
/**
 * Hinweise / Popups (ersetzt "Popup Maker").
 * Ein Hinweis wird als Fenster angezeigt, bis Besucher ihn schließen.
 *
 * @package fvbb-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	register_post_type( 'fvbb_notice', array(
		'labels'       => array(
			'name'          => __( 'Hinweise', 'fvbb-core' ),
			'singular_name' => __( 'Hinweis', 'fvbb-core' ),
			'add_new'       => __( 'Neuer Hinweis', 'fvbb-core' ),
			'add_new_item'  => __( 'Neuen Hinweis anlegen', 'fvbb-core' ),
			'edit_item'     => __( 'Hinweis bearbeiten', 'fvbb-core' ),
			'all_items'     => __( 'Alle Hinweise', 'fvbb-core' ),
			'menu_name'     => __( 'Hinweise (Popup)', 'fvbb-core' ),
		),
		'public'       => false,
		'show_ui'      => true,
		'show_in_rest' => true,
		'menu_icon'    => 'dashicons-megaphone',
		'menu_position' => 26,
		'supports'     => array( 'title', 'editor', 'revisions' ),
		'capability_type' => 'page',
	) );

	foreach ( array(
		'_fvbb_active' => array( 'type' => 'boolean', 'default' => false ),
		'_fvbb_scope'  => array( 'type' => 'string', 'default' => 'front' ),
		'_fvbb_until'  => array( 'type' => 'string', 'default' => '' ),
	) as $key => $conf ) {
		register_post_meta( 'fvbb_notice', $key, array(
			'type'          => $conf['type'],
			'single'        => true,
			'default'       => $conf['default'],
			'show_in_rest'  => true,
			'auth_callback' => function () {
				return current_user_can( 'edit_pages' );
			},
		) );
	}
} );

/* Einstellungen-Box im Editor */
add_action( 'add_meta_boxes_fvbb_notice', function () {
	add_meta_box( 'fvbb_notice_settings', __( 'Anzeige', 'fvbb-core' ), 'fvbb_notice_box', 'fvbb_notice', 'side', 'high' );
} );

/**
 * Meta-Box.
 *
 * @param WP_Post $post Beitrag.
 */
function fvbb_notice_box( $post ) {
	wp_nonce_field( 'fvbb_notice', 'fvbb_notice_nonce' );
	$active = (bool) get_post_meta( $post->ID, '_fvbb_active', true );
	$scope  = get_post_meta( $post->ID, '_fvbb_scope', true ) ?: 'front';
	$until  = get_post_meta( $post->ID, '_fvbb_until', true );
	?>
	<p><label><input type="checkbox" name="fvbb_active" value="1" <?php checked( $active ); ?>> <strong><?php esc_html_e( 'Hinweis anzeigen', 'fvbb-core' ); ?></strong></label></p>
	<p><label for="fvbb_scope"><?php esc_html_e( 'Wo?', 'fvbb-core' ); ?></label><br>
		<select name="fvbb_scope" id="fvbb_scope">
			<option value="front" <?php selected( $scope, 'front' ); ?>><?php esc_html_e( 'Nur auf der Startseite', 'fvbb-core' ); ?></option>
			<option value="all" <?php selected( $scope, 'all' ); ?>><?php esc_html_e( 'Auf allen Seiten', 'fvbb-core' ); ?></option>
		</select></p>
	<p><label for="fvbb_until"><?php esc_html_e( 'Anzeigen bis (optional)', 'fvbb-core' ); ?></label><br>
		<input type="date" name="fvbb_until" id="fvbb_until" value="<?php echo esc_attr( $until ); ?>"></p>
	<p class="description"><?php esc_html_e( 'Besucher sehen den Hinweis, bis sie ihn schließen. Nach einer Änderung am Text erscheint er erneut.', 'fvbb-core' ); ?></p>
	<?php
}

add_action( 'save_post_fvbb_notice', function ( $post_id ) {
	if ( ! isset( $_POST['fvbb_notice_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['fvbb_notice_nonce'] ), 'fvbb_notice' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	update_post_meta( $post_id, '_fvbb_active', ! empty( $_POST['fvbb_active'] ) );
	update_post_meta( $post_id, '_fvbb_scope', 'all' === ( $_POST['fvbb_scope'] ?? '' ) ? 'all' : 'front' );
	$until = sanitize_text_field( wp_unslash( $_POST['fvbb_until'] ?? '' ) );
	update_post_meta( $post_id, '_fvbb_until', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ? $until : '' );
} );

/* Spalten in der Übersicht */
add_filter( 'manage_fvbb_notice_posts_columns', function ( $cols ) {
	$new = array();
	foreach ( $cols as $k => $v ) {
		$new[ $k ] = $v;
		if ( 'title' === $k ) {
			$new['fvbb_active'] = __( 'Status', 'fvbb-core' );
			$new['fvbb_scope']  = __( 'Wo', 'fvbb-core' );
		}
	}
	return $new;
} );
add_action( 'manage_fvbb_notice_posts_custom_column', function ( $col, $id ) {
	if ( 'fvbb_active' === $col ) {
		$until = get_post_meta( $id, '_fvbb_until', true );
		if ( get_post_meta( $id, '_fvbb_active', true ) && ( ! $until || $until >= current_time( 'Y-m-d' ) ) ) {
			echo '<span style="color:#007017;font-weight:600">' . esc_html__( 'Wird angezeigt', 'fvbb-core' ) . '</span>';
			if ( $until ) {
				/* translators: %s: Datum */
				echo '<br><small>' . esc_html( sprintf( __( 'bis %s', 'fvbb-core' ), date_i18n( get_option( 'date_format' ), strtotime( $until ) ) ) ) . '</small>';
			}
		} else {
			echo '<span style="color:#646970">' . esc_html__( 'Aus', 'fvbb-core' ) . '</span>';
		}
	}
	if ( 'fvbb_scope' === $col ) {
		echo 'all' === get_post_meta( $id, '_fvbb_scope', true ) ? esc_html__( 'Alle Seiten', 'fvbb-core' ) : esc_html__( 'Startseite', 'fvbb-core' );
	}
}, 10, 2 );

/**
 * Aktiven Hinweis für die aktuelle Seite finden.
 *
 * @return WP_Post|null
 */
function fvbb_current_notice() {
	if ( is_admin() || is_feed() || is_embed() ) {
		return null;
	}
	$q = new WP_Query( array(
		'post_type'      => 'fvbb_notice',
		'post_status'    => 'publish',
		'posts_per_page' => 10,
		'orderby'        => 'modified',
		'order'          => 'DESC',
		'no_found_rows'  => true,
		'meta_query'     => array( array( 'key' => '_fvbb_active', 'value' => '1' ) ), // phpcs:ignore
	) );
	$today = current_time( 'Y-m-d' );
	foreach ( $q->posts as $p ) {
		$until = get_post_meta( $p->ID, '_fvbb_until', true );
		if ( $until && $until < $today ) {
			continue;
		}
		$scope = get_post_meta( $p->ID, '_fvbb_scope', true ) ?: 'front';
		if ( 'front' === $scope && ! is_front_page() ) {
			continue;
		}
		return $p;
	}
	return null;
}

add_action( 'wp_footer', function () {
	$p = fvbb_current_notice();
	if ( ! $p ) {
		return;
	}
	$key = 'n' . $p->ID . '-' . strtotime( $p->post_modified_gmt );
	// Inhalt wie einen Beitrag rendern (Blöcke, Shortcodes).
	$content = apply_filters( 'the_content', $p->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	?>
	<dialog class="fvbb-notice" id="fvbb-notice" data-key="<?php echo esc_attr( $key ); ?>" aria-labelledby="fvbb-notice-title">
		<div class="fvbb-notice__inner">
			<button type="button" class="fvbb-notice__close" data-fvbb-close aria-label="<?php esc_attr_e( 'Hinweis schließen', 'fvbb-core' ); ?>">
				<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
			</button>
			<h2 class="fvbb-notice__title" id="fvbb-notice-title"><?php echo esc_html( get_the_title( $p ) ); ?></h2>
			<div class="fvbb-notice__content"><?php echo $content; // phpcs:ignore ?></div>
			<p class="fvbb-notice__actions"><button type="button" class="fvbb-notice__ok" data-fvbb-close><?php esc_html_e( 'Verstanden', 'fvbb-core' ); ?></button></p>
		</div>
	</dialog>
	<script>
	(function () {
		var d = document.getElementById('fvbb-notice');
		if (!d || typeof d.showModal !== 'function') { return; }
		var k = 'fvbb_notice_' + d.getAttribute('data-key');
		try { if (localStorage.getItem(k)) { return; } } catch (e) {}
		function close() { try { localStorage.setItem(k, '1'); } catch (e) {} d.close(); }
		d.querySelectorAll('[data-fvbb-close]').forEach(function (b) { b.addEventListener('click', close); });
		d.addEventListener('cancel', function () { try { localStorage.setItem(k, '1'); } catch (e) {} });
		d.addEventListener('click', function (e) { if (e.target === d) { close(); } });
		// Erst nach dem Cookie-Banner zeigen
		function show() { if (!d.open) { d.showModal(); } }
		if (document.documentElement.classList.contains('fvbb-consent-pending')) {
			document.addEventListener('fvbb:consent', function () { setTimeout(show, 300); }, { once: true });
		} else { setTimeout(show, 700); }
	})();
	</script>
	<?php
}, 30 );
