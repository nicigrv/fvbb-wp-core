<?php
/**
 * Werkzeuge → Förderverein Umstellung.
 *
 * @package fvbb-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	add_management_page(
		__( 'Förderverein Umstellung', 'fvbb-core' ),
		__( 'Förderverein Umstellung', 'fvbb-core' ),
		'manage_options',
		'fvbb-migration',
		'fvbb_migration_page'
	);
} );

/* Hinweis auf dem Dashboard, solange noch nicht umgestellt wurde */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) || get_option( 'fvbb_migration_done' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( $screen && 'tools_page_fvbb-migration' === $screen->id ) {
		return;
	}
	printf(
		'<div class="notice notice-info"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
		esc_html__( 'Förderverein Funktionen:', 'fvbb-core' ),
		esc_html__( 'Startseite und Inhalte alter Plugins können jetzt in normale Blöcke umgestellt werden.', 'fvbb-core' ),
		esc_url( admin_url( 'tools.php?page=fvbb-migration' ) ),
		esc_html__( 'Zur Umstellung', 'fvbb-core' )
	);
} );

/**
 * Seite ausgeben.
 */
function fvbb_migration_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	@set_time_limit( 300 ); // phpcs:ignore
	$messages = array();
	$results  = null;

	if ( isset( $_POST['fvbb_action'] ) && check_admin_referer( 'fvbb_migration' ) ) {
		$action = sanitize_key( $_POST['fvbb_action'] );

		if ( 'run' === $action ) {
			if ( empty( $_POST['fvbb_backup_ok'] ) ) {
				$messages[] = array( 'error', __( 'Bitte bestätige zuerst, dass ein Backup existiert.', 'fvbb-core' ) );
			} else {
				$results = FVBB_Migration::run_content();
				$tables  = FVBB_Migration::run_tables();
				$popups  = FVBB_Migration::run_popups();
				update_option( 'fvbb_migration_done', time() );
				$messages[] = array( 'success', sprintf(
					/* translators: 1: Inhalte, 2: Hinweise, 3: Tabellen */
					__( 'Fertig: %1$d Inhalte umgestellt, %3$d weitere Tabellen übernommen, %2$d Popups als Hinweise übernommen.', 'fvbb-core' ),
					count( $results ), $popups, $tables
				) );
			}
		}
		if ( 'undo' === $action ) {
			$n          = FVBB_Migration::undo();
			$messages[] = array( 'success', sprintf( __( '%d Inhalte auf den Stand vor der Umstellung zurückgesetzt.', 'fvbb-core' ), $n ) );
			delete_option( 'fvbb_migration_done' );
		}
		if ( 'deactivate' === $action ) {
			$list = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['fvbb_plugins'] ?? array() ) );
			$list = array_intersect( $list, array_keys( FVBB_Migration::replaced_installed() ) );
			deactivate_plugins( $list );
			$messages[] = array( 'success', sprintf( __( '%d Plugins deaktiviert. Bitte die Seite jetzt durchklicken – danach können sie unter Plugins gelöscht werden.', 'fvbb-core' ), count( $list ) ) );
		}
		if ( 'cleanup' === $action ) {
			if ( empty( $_POST['fvbb_cleanup_ok'] ) ) {
				$messages[] = array( 'error', __( 'Bitte bestätige das Aufräumen.', 'fvbb-core' ) );
			} else {
				$types   = array_map( 'sanitize_key', (array) wp_unslash( $_POST['fvbb_types'] ?? array() ) );
				$tables  = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['fvbb_tables'] ?? array() ) );
				$res     = FVBB_Migration::cleanup( $types, $tables, ! empty( $_POST['fvbb_options'] ) );
				$messages[] = array( 'success', sprintf( __( 'Aufgeräumt: %1$d Einträge, %2$d Tabellen, %3$d Einstellungen gelöscht.', 'fvbb-core' ), $res['posts'], $res['tables'], $res['options'] ) );
			}
		}
	}

	$analysis = FVBB_Migration::analyze();
	$backups  = FVBB_Migration::backup_count();
	$theme    = wp_get_theme();
	?>
	<div class="wrap fvbb-mig">
		<style>
			.fvbb-mig .card { max-width: 980px; padding: 1.2rem 1.5rem; }
			.fvbb-mig h2 { margin-top: .2rem; }
			.fvbb-mig table.widefat td, .fvbb-mig table.widefat th { vertical-align: top; }
			.fvbb-mig ul.fvbb-log { margin: 0; }
			.fvbb-mig ul.fvbb-log li { margin: 0 0 .2rem; }
			.fvbb-mig .warn { color: #996800; }
			.fvbb-mig .ok { color: #007017; font-weight: 600; }
			.fvbb-mig .step { display: inline-grid; place-items: center; width: 28px; height: 28px; border-radius: 99px; background: #0058A8; color: #fff; font-weight: 600; margin-right: .4rem; }
		</style>
		<h1><?php esc_html_e( 'Förderverein Umstellung', 'fvbb-core' ); ?></h1>
		<?php foreach ( $messages as $m ) : ?>
			<div class="notice notice-<?php echo esc_attr( $m[0] ); ?>"><p><?php echo esc_html( $m[1] ); ?></p></div>
		<?php endforeach; ?>

		<?php if ( $results ) : ?>
			<div class="card">
				<h2><?php esc_html_e( 'Umgestellt', 'fvbb-core' ); ?></h2>
				<table class="widefat striped"><tbody>
				<?php foreach ( $results as $r ) : ?>
					<tr><td><a href="<?php echo esc_url( get_permalink( $r['id'] ) ); ?>" target="_blank"><?php echo esc_html( $r['title'] ); ?></a> · <a href="<?php echo esc_url( get_edit_post_link( $r['id'] ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'fvbb-core' ); ?></a></td>
					<td><ul class="fvbb-log"><?php foreach ( $r['log'] as $l ) { echo '<li>' . esc_html( $l ) . '</li>'; } foreach ( $r['warnings'] as $w ) { echo '<li class="warn">⚠ ' . esc_html( $w ) . '</li>'; } ?></ul></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</div>
		<?php endif; ?>

		<div class="card">
			<h2><span class="step">1</span><?php esc_html_e( 'Theme', 'fvbb-core' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: Theme, 2: Version */
					esc_html__( 'Aktives Theme: %1$s (Version %2$s)', 'fvbb-core' ),
					'<strong>' . esc_html( $theme->get( 'Name' ) ) . '</strong>',
					esc_html( $theme->get( 'Version' ) )
				);
				if ( 'fvbb' === $theme->get( 'TextDomain' ) ) {
					echo ' – <span class="ok">' . esc_html__( 'passt', 'fvbb-core' ) . '</span>';
				} else {
					echo ' – <span class="warn">' . esc_html__( 'Bitte zuerst das Theme „Förderverein Biedensand Bäder“ hochladen und aktivieren (Design → Themes). Logo, Kopfbild, Menü und Footer werden aus EmpowerWP übernommen.', 'fvbb-core' ) . '</span>';
				}
				?>
			</p>
		</div>

		<div class="card">
			<h2><span class="step">2</span><?php esc_html_e( 'Inhalte umstellen', 'fvbb-core' ); ?></h2>
			<?php if ( ! $analysis ) : ?>
				<p class="ok"><?php esc_html_e( 'Keine Inhalte mehr, die von alten Plugins abhängen.', 'fvbb-core' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Diese Inhalte werden in normale Blöcke umgewandelt. Der Originalinhalt wird gesichert und kann unten wiederhergestellt werden.', 'fvbb-core' ); ?></p>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Inhalt', 'fvbb-core' ); ?></th><th><?php esc_html_e( 'Was passiert', 'fvbb-core' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $analysis as $a ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( get_permalink( $a['id'] ) ); ?>" target="_blank"><?php echo esc_html( $a['title'] ); ?></a><br><small><?php echo esc_html( $a['type'] . ' · ' . $a['status'] ); ?></small></td>
							<td><ul class="fvbb-log">
								<?php foreach ( $a['log'] as $l ) : ?><li><?php echo esc_html( $l ); ?></li><?php endforeach; ?>
								<?php foreach ( $a['warnings'] as $w ) : ?><li class="warn">⚠ <?php echo esc_html( $w ); ?></li><?php endforeach; ?>
							</ul></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" style="margin-top:1rem">
					<?php wp_nonce_field( 'fvbb_migration' ); ?>
					<p><label><input type="checkbox" name="fvbb_backup_ok" value="1"> <?php esc_html_e( 'Ich habe ein aktuelles Backup von Dateien und Datenbank.', 'fvbb-core' ); ?></label></p>
					<p><?php esc_html_e( 'Zusätzlich werden alle TablePress-Tabellen in „Tabellen“ übernommen (auch ungenutzte) und Popups (Popup Maker) als „Hinweise“ angelegt.', 'fvbb-core' ); ?></p>
					<button class="button button-primary button-hero" name="fvbb_action" value="run"><?php esc_html_e( 'Jetzt umstellen', 'fvbb-core' ); ?></button>
				</form>
			<?php endif; ?>
			<?php if ( $backups ) : ?>
				<form method="post" style="margin-top:1rem" onsubmit="return confirm('<?php echo esc_js( __( 'Alle umgestellten Inhalte auf den alten Stand zurücksetzen?', 'fvbb-core' ) ); ?>');">
					<?php wp_nonce_field( 'fvbb_migration' ); ?>
					<p><?php printf( esc_html__( '%d Inhalte sind gesichert.', 'fvbb-core' ), (int) $backups ); ?>
					<button class="button" name="fvbb_action" value="undo"><?php esc_html_e( 'Umstellung rückgängig machen', 'fvbb-core' ); ?></button></p>
				</form>
			<?php endif; ?>
		</div>

		<div class="card">
			<h2><span class="step">3</span><?php esc_html_e( 'Alte Plugins deaktivieren', 'fvbb-core' ); ?></h2>
			<p><?php esc_html_e( 'Diese Plugins werden nicht mehr gebraucht. Erst deaktivieren, Seite prüfen, dann unter „Plugins“ löschen.', 'fvbb-core' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'fvbb_migration' ); ?>
				<table class="widefat striped"><tbody>
				<?php
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$installed = get_plugins();
				$any       = false;
				foreach ( FVBB_Migration::replaced_installed() as $file => $why ) :
					$active = is_plugin_active( $file );
					$any    = $any || $active;
					?>
					<tr>
						<td style="width:28px"><input type="checkbox" name="fvbb_plugins[]" value="<?php echo esc_attr( $file ); ?>" <?php checked( $active ); ?> <?php disabled( ! $active ); ?>></td>
						<td><strong><?php echo esc_html( $installed[ $file ]['Name'] ); ?></strong><br><small><?php echo esc_html( $why ); ?></small></td>
						<td><?php echo $active ? esc_html__( 'aktiv', 'fvbb-core' ) : '<span class="ok">' . esc_html__( 'deaktiviert', 'fvbb-core' ) . '</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php if ( $any ) : ?>
					<p><button class="button button-primary" name="fvbb_action" value="deactivate"><?php esc_html_e( 'Ausgewählte deaktivieren', 'fvbb-core' ); ?></button></p>
				<?php endif; ?>
			</form>
		</div>

		<div class="card">
			<h2><span class="step">4</span><?php esc_html_e( 'Aufräumen (optional)', 'fvbb-core' ); ?></h2>
			<p><?php esc_html_e( 'Löscht Daten alter und bereits entfernter Plugins aus der Datenbank. Erst ausführen, wenn die umgestellte Seite geprüft ist. Kann nicht rückgängig gemacht werden – nur über das Backup.', 'fvbb-core' ); ?></p>
			<?php
			$counts  = FVBB_Migration::leftover_post_counts();
			$labels  = FVBB_Migration::leftover_post_types();
			$tables  = FVBB_Migration::leftover_tables();
			$options = FVBB_Migration::leftover_options();
			?>
			<form method="post">
				<?php wp_nonce_field( 'fvbb_migration' ); ?>
				<?php if ( $counts ) : ?>
					<h3><?php esc_html_e( 'Inhalte', 'fvbb-core' ); ?></h3>
					<?php foreach ( $counts as $type => $n ) : ?>
						<label style="display:block;margin:.2rem 0"><input type="checkbox" name="fvbb_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( $labels[ $type ][1] ); ?>> <?php echo esc_html( $labels[ $type ][0] . ' (' . $n . ')' ); ?></label>
					<?php endforeach; ?>
				<?php endif; ?>
				<?php if ( $tables ) : ?>
					<h3><?php esc_html_e( 'Datenbank-Tabellen', 'fvbb-core' ); ?></h3>
					<div style="columns:2">
					<?php foreach ( $tables as $t => $info ) : ?>
						<label style="display:block;margin:.15rem 0"><input type="checkbox" name="fvbb_tables[]" value="<?php echo esc_attr( $t ); ?>" <?php checked( $info['default'] ); ?>> <code><?php echo esc_html( $t ); ?></code> <small>(<?php echo (int) $info['rows']; ?>)</small></label>
					<?php endforeach; ?>
					</div>
					<p class="description"><?php esc_html_e( 'Nicht angehakt sind Tabellen, die keinem bekannten alten Plugin zugeordnet werden konnten.', 'fvbb-core' ); ?></p>
				<?php endif; ?>
				<?php if ( $options ) : ?>
					<h3><?php esc_html_e( 'Einstellungen', 'fvbb-core' ); ?></h3>
					<label><input type="checkbox" name="fvbb_options" value="1" checked> <?php printf( esc_html__( '%d gespeicherte Einstellungen alter Plugins löschen', 'fvbb-core' ), (int) $options ); ?></label>
				<?php endif; ?>
				<?php if ( $counts || $tables || $options ) : ?>
					<p style="margin-top:1rem"><label><input type="checkbox" name="fvbb_cleanup_ok" value="1"> <?php esc_html_e( 'Ich habe die Seite geprüft und ein Backup.', 'fvbb-core' ); ?></label></p>
					<p><button class="button" name="fvbb_action" value="cleanup"><?php esc_html_e( 'Ausgewähltes löschen', 'fvbb-core' ); ?></button></p>
				<?php else : ?>
					<p class="ok"><?php esc_html_e( 'Nichts mehr aufzuräumen.', 'fvbb-core' ); ?></p>
				<?php endif; ?>
			</form>
		</div>
	</div>
	<?php
}


