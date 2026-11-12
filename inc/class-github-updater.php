<?php
/**
 * Updates direkt aus GitHub-Releases.
 *
 * WordPress zeigt neue Versionen wie gewohnt unter Dashboard → Aktualisierungen
 * (bzw. Plugins/Themes) an. Quelle ist das neueste Release im GitHub-Repo; installiert
 * wird die dort angehängte ZIP-Datei (wird von der GitHub-Action gebaut).
 *
 * Erkennung über den Header "Update URI: https://github.com/<owner>/<repo>".
 *
 * @package fvbb
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'FVBB_Core_GitHub_Updater' ) ) {

	class FVBB_Core_GitHub_Updater {

		/** @var string plugin|theme */
		protected $type;
		/** @var string owner/repo */
		protected $repo;
		/** @var string Ordnername (Plugin-Ordner bzw. Theme-Stylesheet) */
		protected $slug;
		/** @var string Plugin-Basisname (ordner/datei.php) – nur Plugins */
		protected $file;
		/** @var string Name der ZIP-Datei im Release */
		protected $asset;
		/** @var string Anzeigename */
		protected $name;

		/**
		 * @param array $args type, repo, slug, file, asset, name.
		 */
		public function __construct( array $args ) {
			$this->type  = $args['type'];
			$this->repo  = $args['repo'];
			$this->slug  = $args['slug'];
			$this->file  = $args['file'] ?? '';
			$this->asset = $args['asset'] ?? ( $this->slug . '.zip' );
			$this->name  = $args['name'] ?? $this->slug;

			if ( 'plugin' === $this->type ) {
				add_filter( 'update_plugins_github.com', array( $this, 'plugin_update' ), 10, 3 );
				add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
			} else {
				add_filter( 'update_themes_github.com', array( $this, 'theme_update' ), 10, 3 );
			}
			add_filter( 'upgrader_source_selection', array( $this, 'fix_folder' ), 10, 4 );
			add_action( 'upgrader_process_complete', array( $this, 'flush' ), 10, 0 );
			add_action( 'load-update-core.php', array( $this, 'maybe_flush' ) );
		}

		/** Cache-Schlüssel */
		protected function key() {
			return 'fvbb_gh_' . md5( $this->repo );
		}

		/** Cache leeren */
		public function flush() {
			delete_site_transient( $this->key() );
		}

		/** "Erneut prüfen" unter Dashboard → Aktualisierungen */
		public function maybe_flush() {
			if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$this->flush();
			}
		}

		/**
		 * Neuestes Release (6 h gecacht, bei Fehlern 1 h Pause).
		 *
		 * @return array|null version, package, url, notes, date
		 */
		public function release() {
			$cached = get_site_transient( $this->key() );
			if ( is_array( $cached ) ) {
				return empty( $cached['version'] ) ? null : $cached;
			}

			$resp = wp_remote_get(
				'https://api.github.com/repos/' . $this->repo . '/releases/latest',
				array(
					'timeout' => 10,
					'headers' => array(
						'Accept'     => 'application/vnd.github+json',
						'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
					),
				)
			);

			$data = null;
			if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
				$json = json_decode( wp_remote_retrieve_body( $resp ), true );
				if ( is_array( $json ) && ! empty( $json['tag_name'] ) && empty( $json['draft'] ) && empty( $json['prerelease'] ) ) {
					$package = '';
					foreach ( (array) ( $json['assets'] ?? array() ) as $a ) {
						if ( ( $a['name'] ?? '' ) === $this->asset ) {
							$package = $a['browser_download_url'];
							break;
						}
					}
					if ( $package ) {
						$data = array(
							'version' => ltrim( $json['tag_name'], 'vV' ),
							'package' => $package,
							'url'     => $json['html_url'] ?? 'https://github.com/' . $this->repo,
							'notes'   => (string) ( $json['body'] ?? '' ),
							'date'    => (string) ( $json['published_at'] ?? '' ),
						);
					}
				}
			}

			set_site_transient( $this->key(), $data ? $data : array( 'version' => '' ), $data ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
			return $data;
		}

		/**
		 * Plugin: Update-Info für WordPress.
		 *
		 * @param array|false $update      Bisher.
		 * @param array       $plugin_data Plugin-Header.
		 * @param string      $plugin_file Basisname.
		 * @return array|false
		 */
		public function plugin_update( $update, $plugin_data, $plugin_file ) {
			if ( $plugin_file !== $this->file ) {
				return $update;
			}
			$r = $this->release();
			if ( ! $r ) {
				return $update;
			}
			return array(
				'id'           => 'github.com/' . $this->repo,
				'slug'         => $this->slug,
				'plugin'       => $this->file,
				'version'      => $r['version'],
				'url'          => 'https://github.com/' . $this->repo,
				'package'      => $r['package'],
				'requires_php' => $plugin_data['RequiresPHP'] ?? '',
				'requires'     => $plugin_data['RequiresWP'] ?? '',
				'tested'       => get_file_data( WP_PLUGIN_DIR . '/' . $this->file, array( 'Tested' => 'Tested up to' ) )['Tested'],
			);
		}

		/**
		 * Plugin: "Details ansehen" mit Änderungen aus dem Release.
		 *
		 * @param mixed  $res    Ergebnis.
		 * @param string $action Aktion.
		 * @param object $args   Argumente.
		 * @return mixed
		 */
		public function plugin_info( $res, $action, $args ) {
			if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
				return $res;
			}
			$r = $this->release();
			if ( ! $r ) {
				return $res;
			}
			$plugin = get_file_data( WP_PLUGIN_DIR . '/' . $this->file, array( 'Version' => 'Version', 'Author' => 'Author', 'RequiresWP' => 'Requires at least', 'RequiresPHP' => 'Requires PHP' ) );
			return (object) array(
				'name'          => $this->name,
				'slug'          => $this->slug,
				'version'       => $r['version'],
				'author'        => $plugin['Author'],
				'homepage'      => 'https://github.com/' . $this->repo,
				'requires'      => $plugin['RequiresWP'],
				'requires_php'  => $plugin['RequiresPHP'],
				'last_updated'  => $r['date'],
				'download_link' => $r['package'],
				'sections'      => array(
					'changelog' => $this->notes_html( $r ),
				),
			);
		}

		/**
		 * Theme: Update-Info für WordPress.
		 *
		 * @param array|false $update     Bisher.
		 * @param array       $theme_data Theme-Header.
		 * @param string      $stylesheet Theme-Ordner.
		 * @return array|false
		 */
		public function theme_update( $update, $theme_data, $stylesheet ) {
			if ( $stylesheet !== $this->slug ) {
				return $update;
			}
			$r = $this->release();
			if ( ! $r ) {
				return $update;
			}
			return array(
				'theme'   => $this->slug,
				'version' => $r['version'],
				'url'     => $r['url'],
				'package' => $r['package'],
			);
		}

		/**
		 * Release-Text als einfaches HTML.
		 *
		 * @param array $r Release.
		 * @return string
		 */
		protected function notes_html( array $r ) {
			$txt = trim( $r['notes'] );
			if ( '' === $txt ) {
				$txt = 'Version ' . $r['version'];
			}
			$html = esc_html( $txt );
			$html = preg_replace( '/^#{1,6}\s*(.+)$/m', '<strong>$1</strong>', $html );
			$html = preg_replace( '/^\s*[-*]\s+(.+)$/m', '• $1', $html );
			$html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
			return wpautop( $html ) . '<p><a href="' . esc_url( $r['url'] ) . '" target="_blank" rel="noopener">GitHub-Release ansehen</a></p>';
		}

		/**
		 * Ordner im ZIP auf den richtigen Namen bringen (falls nötig).
		 *
		 * @param string      $source        Entpackter Ordner.
		 * @param string      $remote_source Übergeordneter Ordner.
		 * @param WP_Upgrader $upgrader      Upgrader.
		 * @param array       $hook_extra    plugin|theme.
		 * @return string|WP_Error
		 */
		public function fix_folder( $source, $remote_source, $upgrader = null, $hook_extra = array() ) {
			$ours = ( 'plugin' === $this->type && ( $hook_extra['plugin'] ?? '' ) === $this->file )
				|| ( 'theme' === $this->type && ( $hook_extra['theme'] ?? '' ) === $this->slug );
			if ( ! $ours || basename( untrailingslashit( $source ) ) === $this->slug ) {
				return $source;
			}
			global $wp_filesystem;
			$target = trailingslashit( $remote_source ) . $this->slug . '/';
			if ( $wp_filesystem && $wp_filesystem->move( $source, $target, true ) ) {
				return $target;
			}
			return $source;
		}
	}
}
