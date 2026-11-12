<?php
/**
 * Ablauf der Umstellung: Analyse, Ausführung, Rückgängig, Aufräumen.
 *
 * Sicherheit:
 *  - Vor jeder Änderung wird der Originalinhalt in _fvbb_backup_content gesichert.
 *  - "Rückgängig" stellt alle gesicherten Inhalte wieder her.
 *  - Daten alter Plugins werden nur beim separaten Schritt "Aufräumen" gelöscht.
 *
 * @package fvbb-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FVBB_Migration {

	const BACKUP_KEY = '_fvbb_backup_content';

	/**
	 * Plugins, deren Aufgaben jetzt Theme bzw. "Förderverein Funktionen" übernehmen.
	 * Schlüssel ist der Plugin-Ordner (der Dateiname im Ordner ist je nach Plugin verschieden).
	 */
	public static function replaced_plugins() {
		return array(
			'mesmerize-companion'   => __( 'Startseiten-Baukasten → normale Blöcke', 'fvbb-core' ),
			'tablepress'            => __( 'Tabellen → Dashboard → Tabellen', 'fvbb-core' ),
			'popup-maker'           => __( 'Popups → Hinweise (Popup)', 'fvbb-core' ),
			'cookie-law-info'       => __( 'Cookie-Banner → eigener Banner', 'fvbb-core' ),
			'timeline-block-block'  => __( 'Zeitleiste auf „Aktionen“ → Block „Zeitleiste“', 'fvbb-core' ),
			'timeline-block'        => __( 'Zeitleiste auf „Aktionen“ → Block „Zeitleiste“', 'fvbb-core' ),
			'host-webfonts-local'   => __( 'Schriften liegen jetzt im Theme', 'fvbb-core' ),
		);
	}

	/**
	 * Installierte Plugins aus der Liste oben (Plugin-Datei => Grund).
	 *
	 * @return array
	 */
	public static function replaced_installed() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$map = self::replaced_plugins();
		$out = array();
		foreach ( array_keys( get_plugins() ) as $file ) {
			$dir = dirname( $file );
			if ( isset( $map[ $dir ] ) ) {
				$out[ $file ] = $map[ $dir ];
			}
		}
		return $out;
	}

	/**
	 * Zu prüfende Inhalte.
	 *
	 * @return int[]
	 */
	public static function candidate_ids() {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type IN ('post','page','wp_block','fvbb_notice')
			   AND post_status IN ('publish','private','draft','future','pending')
			   AND ( post_content LIKE '%wp:tablepress/%' OR post_content LIKE '%[table %'
			      OR post_content LIKE '%wp:tlgb/%' OR post_content LIKE '%[popup%'
			      OR post_content LIKE '%data-export-id=%' OR post_content LIKE '%href=\"matilo:%' )
			 ORDER BY post_type, menu_order, post_title"
		) );
	}

	/**
	 * Einen Inhalt umwandeln (ohne zu speichern).
	 *
	 * @param WP_Post $post Beitrag.
	 * @return array{content:string,log:array,warnings:array,changed:bool}
	 */
	public static function convert_post( WP_Post $post, $dry = false ) {
		$content = $post->post_content;
		$log     = array();
		$warn    = array();

		if ( FVBB_Homepage_Converter::is_builder_content( $content ) ) {
			$hc      = new FVBB_Homepage_Converter();
			$content = $hc->convert( $content );
			$log     = array_merge( $log, $hc->log );
			$warn    = array_merge( $warn, $hc->warnings );
		}

		$c       = new FVBB_Converter();
		$c->dry  = $dry;
		$content = $c->convert( $content );
		$log     = array_merge( $log, $c->log );
		$warn    = array_merge( $warn, $c->warnings );

		return array(
			'content'  => $content,
			'log'      => array_values( array_unique( $log ) ),
			'warnings' => array_values( array_unique( $warn ) ),
			'changed'  => $content !== $post->post_content,
		);
	}

	/**
	 * Analyse für die Vorschau.
	 *
	 * @return array
	 */
	public static function analyze() {
		$items = array();
		foreach ( self::candidate_ids() as $id ) {
			$p = get_post( $id );
			if ( ! $p ) {
				continue;
			}
			$r = self::convert_post( $p, true );
			if ( ! $r['changed'] && ! $r['warnings'] ) {
				continue;
			}
			$items[] = array(
				'id'       => $id,
				'title'    => get_the_title( $p ) ?: '(' . $p->post_name . ')',
				'type'     => $p->post_type,
				'status'   => $p->post_status,
				'log'      => $r['log'],
				'warnings' => $r['warnings'],
				'changed'  => $r['changed'],
			);
		}
		return $items;
	}

	/**
	 * Inhalte umstellen.
	 *
	 * @return array Protokoll.
	 */
	public static function run_content() {
		global $wpdb;
		$done = array();
		foreach ( self::candidate_ids() as $id ) {
			$p = get_post( $id );
			if ( ! $p ) {
				continue;
			}
			$r = self::convert_post( $p );
			if ( ! $r['changed'] ) {
				continue;
			}
			if ( '' === get_post_meta( $id, self::BACKUP_KEY, true ) ) {
				add_post_meta( $id, self::BACKUP_KEY, wp_slash( $p->post_content ), true );
			}
			$wpdb->update( $wpdb->posts, array( 'post_content' => $r['content'] ), array( 'ID' => $id ) );
			clean_post_cache( $id );
			$done[] = array( 'id' => $id, 'title' => get_the_title( $id ), 'log' => $r['log'], 'warnings' => $r['warnings'] );
		}
		return $done;
	}

	/**
	 * Alle übrigen TablePress-Tabellen in die Tabellen-Verwaltung übernehmen
	 * (auch solche, die gerade auf keiner Seite stehen).
	 *
	 * @return int Anzahl neu übernommener Tabellen.
	 */
	public static function run_tables() {
		$map = json_decode( (string) get_option( 'tablepress_tables', '' ), true );
		if ( empty( $map['table_post'] ) || ! function_exists( 'fvbb_table_create' ) ) {
			return 0;
		}
		$before = (int) wp_count_posts( 'fvbb_table' )->publish;
		$c      = new FVBB_Converter();
		foreach ( array_keys( $map['table_post'] ) as $table_id ) {
			$c->shortcode_block( '[table id=' . $table_id . ' /]' );
		}
		wp_cache_delete( _count_posts_cache_key( 'fvbb_table' ), 'counts' );
		return max( 0, (int) wp_count_posts( 'fvbb_table' )->publish - $before );
	}

	/**
	 * Popup Maker → Hinweise.
	 *
	 * @return int Anzahl neu angelegter Hinweise.
	 */
	public static function run_popups() {
		$popups = get_posts( array( 'post_type' => 'popup', 'post_status' => array( 'publish', 'draft', 'private' ), 'numberposts' => -1 ) );
		$n      = 0;
		foreach ( $popups as $p ) {
			$exists = get_posts( array( 'post_type' => 'fvbb_notice', 'post_status' => 'any', 'meta_key' => '_fvbb_from_popup', 'meta_value' => $p->ID, 'numberposts' => 1, 'fields' => 'ids' ) ); // phpcs:ignore
			if ( $exists ) {
				continue;
			}
			$settings = get_post_meta( $p->ID, 'popup_settings', true );
			$enabled  = (string) get_post_meta( $p->ID, 'enabled', true );
			$front    = is_array( $settings ) && false !== strpos( wp_json_encode( $settings ), 'is_front_page' );
			$title    = get_post_meta( $p->ID, 'popup_title', true );
			$new_id   = wp_insert_post( wp_slash( array(
				'post_type'    => 'fvbb_notice',
				'post_status'  => 'publish',
				'post_title'   => $title ? $title : $p->post_title,
				'post_content' => $p->post_content,
			) ) );
			if ( $new_id && ! is_wp_error( $new_id ) ) {
				update_post_meta( $new_id, '_fvbb_active', '1' === $enabled && 'publish' === $p->post_status );
				update_post_meta( $new_id, '_fvbb_scope', $front ? 'front' : 'all' );
				update_post_meta( $new_id, '_fvbb_from_popup', $p->ID );
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Rückgängig machen.
	 *
	 * @return int Anzahl wiederhergestellter Inhalte.
	 */
	public static function undo() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::BACKUP_KEY ) );
		foreach ( $rows as $r ) {
			$wpdb->update( $wpdb->posts, array( 'post_content' => $r->meta_value ), array( 'ID' => $r->post_id ) );
			delete_post_meta( $r->post_id, self::BACKUP_KEY );
			clean_post_cache( $r->post_id );
		}
		return count( $rows );
	}

	/**
	 * Anzahl gesicherter Inhalte.
	 *
	 * @return int
	 */
	public static function backup_count() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::BACKUP_KEY ) );
	}

	/* =====================================================================
	 * Aufräumen
	 * ================================================================== */

	/** Inhaltstypen gelöschter/ersetzter Plugins. */
	public static function leftover_post_types() {
		return array(
			'popup'            => array( __( 'Popup Maker: Popups (sind als Hinweise übernommen)', 'fvbb-core' ), true ),
			'popup_theme'      => array( __( 'Popup Maker: Designs', 'fvbb-core' ), true ),
			'tablepress_table' => array( __( 'TablePress: Tabellen (sind in „Tabellen“ übernommen)', 'fvbb-core' ), true ),
		);
	}

	/**
	 * Tabellen alter Plugins.
	 *
	 * @return array<string, array{rows:int, default:bool}>
	 */
	public static function leftover_tables() {
		global $wpdb;
		$all  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		$keep = array( 'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts', 'term_relationships', 'term_taxonomy', 'termmeta', 'terms', 'usermeta', 'users', 'simple_history', 'simple_history_contexts', 'fbv', 'fbv_attachment_folder' );
		// Tabellen weiter genutzter Plugins (Wordfence, Burst, Simple History, WPMU DEV …) nie anbieten.
		$keep_prefix = array( 'actionscheduler_', 'wf', 'burst_', 'simple_history', 'wpmudev', 'wdp_', 'wpmu', 'blc_', 'fbv' );
		$known_old   = array( 'cky_', 'pum_' );
		$out = array();
		foreach ( $all as $t ) {
			$suffix = substr( $t, strlen( $wpdb->prefix ) );
			if ( in_array( $suffix, $keep, true ) ) {
				continue;
			}
			$skip = false;
			foreach ( $keep_prefix as $kp ) {
				if ( 0 === strpos( $suffix, $kp ) ) {
					$skip = true;
				}
			}
			if ( $skip ) {
				continue;
			}
			$default = false;
			foreach ( $known_old as $k ) {
				if ( 0 === strpos( $suffix, $k ) ) {
					$default = true;
				}
			}
			$out[ $t ] = array(
				'rows'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" ), // phpcs:ignore
				'default' => $default,
			);
		}
		return $out;
	}

	/** Options-Präfixe alter Plugins. */
	public static function leftover_option_prefixes() {
		return array(
			'tablepress_', 'popmake', 'pum_', 'popup_maker', 'cookielawinfo', 'cky_', 'cookieyes', 'omgf_', 'mesmerize_companion', 'cloudpress',
		);
	}

	/**
	 * Optionen alter Plugins zählen/löschen.
	 *
	 * @param bool $delete Löschen?
	 * @return int
	 */
	public static function leftover_options( $delete = false ) {
		global $wpdb;
		$total = 0;
		foreach ( self::leftover_option_prefixes() as $p ) {
			$like = $wpdb->esc_like( $p ) . '%';
			if ( $delete ) {
				$total += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s", $like, 'fvbb\_%' ) );
			} else {
				$total += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
			}
		}
		if ( $delete ) {
			wp_cache_flush();
		}
		return $total;
	}

	/**
	 * Inhaltstypen zählen.
	 *
	 * @return array<string,int>
	 */
	public static function leftover_post_counts() {
		global $wpdb;
		$out = array();
		foreach ( array_keys( self::leftover_post_types() ) as $type ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $type ) );
			if ( $n ) {
				$out[ $type ] = $n;
			}
		}
		return $out;
	}

	/**
	 * Aufräumen ausführen.
	 *
	 * @param string[] $types   Inhaltstypen.
	 * @param string[] $tables  Tabellen.
	 * @param bool     $options Optionen alter Plugins löschen?
	 * @return array
	 */
	public static function cleanup( array $types, array $tables, $options ) {
		global $wpdb;
		$res     = array( 'posts' => 0, 'tables' => 0, 'options' => 0 );
		$allowed = array_keys( self::leftover_post_types() );
		foreach ( $types as $type ) {
			if ( ! in_array( $type, $allowed, true ) ) {
				continue;
			}
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $type ) );
			foreach ( array_chunk( $ids, 200 ) as $chunk ) {
				$in = implode( ',', array_map( 'intval', $chunk ) );
				$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" ); // phpcs:ignore
				$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$in})" ); // phpcs:ignore
				$wpdb->query( "UPDATE {$wpdb->posts} SET post_parent = 0 WHERE post_type = 'attachment' AND post_parent IN ({$in})" ); // phpcs:ignore
				$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_parent IN ({$in}) AND post_type = 'revision'" ); // phpcs:ignore
				$res['posts'] += (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})" ); // phpcs:ignore
			}
		}
		$known = array_keys( self::leftover_tables() );
		foreach ( $tables as $t ) {
			if ( in_array( $t, $known, true ) && 0 === strpos( $t, $wpdb->prefix ) ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$t}`" ); // phpcs:ignore
				$res['tables']++;
			}
		}
		if ( $options ) {
			$res['options'] = self::leftover_options( true );
		}
		wp_cache_flush();
		return $res;
	}
}
