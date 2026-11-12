<?php
/**
 * Wandelt Inhalte alter Plugins in normale WordPress-Blöcke um.
 *
 * - TablePress                         → Tabellen-Verwaltung + Block "Tabelle"
 * - Timeline Block (tlgb)              → Block "Zeitleiste"
 * - Shortcodes gelöschter Plugins      → entfernt (wird protokolliert)
 *
 * Das Markup entspricht exakt dem, was der Block-Editor selbst speichert,
 * damit die Blöcke im Editor ohne Fehlermeldung öffnen.
 *
 * @package fvbb-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FVBB_Converter {

	/** @var string[] Protokoll der aktuellen Umwandlung */
	public $log = array();

	/** @var string[] Hinweise, die manuell geprüft werden sollten */
	public $warnings = array();

	/** @var bool Nur Vorschau – nichts anlegen */
	public $dry = false;

	/* =====================================================================
	 * Einstieg
	 * ================================================================== */

	/**
	 * Inhalt umwandeln.
	 *
	 * @param string $content Beitragsinhalt.
	 * @return string
	 */
	public function convert( $content ) {
		$this->log      = array();
		$this->warnings = array();

		if ( ! has_blocks( $content ) ) {
			$out = $this->convert_classic( $content );
		} else {
			$out = $this->serialize_list( parse_blocks( $content ) );
		}

		return $this->fix_links( $out );
	}

	/**
	 * Bekannte Tippfehler in Links korrigieren.
	 *
	 * @param string $html Inhalt.
	 * @return string
	 */
	public function fix_links( $html ) {
		$fixed = str_replace( 'href="matilo:', 'href="mailto:', $html );
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$fixed = str_replace( 'https://' . $host . '/' . $host . '/', 'https://' . $host . '/', $fixed );
		if ( $fixed !== $html ) {
			$this->log[] = __( 'Fehlerhafte Links korrigiert (mailto/doppelte Domain)', 'fvbb-core' );
		}
		return $fixed;
	}

	/* =====================================================================
	 * Block-Baum durchlaufen und wieder zusammensetzen
	 * ================================================================== */

	/**
	 * @param array $blocks Geparste Blöcke.
	 * @return string
	 */
	protected function serialize_list( array $blocks ) {
		$out = '';
		foreach ( $blocks as $b ) {
			$out .= $this->serialize_one( $b );
		}
		return $out;
	}

	/**
	 * @param array $b Block.
	 * @return string
	 */
	protected function serialize_one( array $b ) {
		$replacement = $this->transform( $b );
		if ( null !== $replacement ) {
			return $replacement;
		}

		if ( empty( $b['blockName'] ) ) {
			// Freier HTML-/Klassik-Text zwischen Blöcken.
			return $this->convert_classic( $b['innerHTML'] ?? '', true );
		}

		$content = '';
		$i       = 0;
		foreach ( $b['innerContent'] as $chunk ) {
			if ( is_string( $chunk ) ) {
				$content .= $chunk;
			} else {
				$content .= $this->serialize_one( $b['innerBlocks'][ $i++ ] );
			}
		}
		return get_comment_delimited_block_content( $b['blockName'], $b['attrs'], $content );
	}

	/**
	 * Einen Block ersetzen – oder null, wenn er bleibt.
	 *
	 * @param array $b Block.
	 * @return string|null
	 */
	protected function transform( array $b ) {
		$name  = $b['blockName'] ?? '';
		$attrs = $b['attrs'] ?? array();

		switch ( $name ) {
			case 'tablepress/table':
				return $this->tablepress( $attrs['id'] ?? '' );

			case 'tlgb/b-timeline-block':
				return $this->timeline( $attrs, $b['innerHTML'] ?? '' );

			case 'core/shortcode':
				return $this->shortcode_block( trim( $b['innerHTML'] ?? '' ) );
		}

		return null;
	}

	/**
	 * Klassischer Inhalt ohne Blöcke: nur alleinstehende Shortcodes ersetzen.
	 *
	 * @param string $html     Inhalt.
	 * @param bool   $fragment Teil eines Block-Inhalts?
	 * @return string
	 */
	protected function convert_classic( $html, $fragment = false ) {
		$self = $this;
		return preg_replace_callback(
			'/^[ \t]*(?:<p>)?\s*(\[(?:table|popup|popup_trigger)\b[^\]]*\])\s*(?:<\/p>)?[ \t]*$/m',
			function ( $m ) use ( $self, $fragment ) {
				$r = $self->shortcode_block( $m[1] );
				if ( null === $r ) {
					return $m[0];
				}
				if ( ! $fragment ) {
					$self->warnings[] = __( 'Klassischer Inhalt (ohne Blöcke) – Ergebnis bitte im Editor kontrollieren', 'fvbb-core' );
				}
				return "\n" . $r . "\n";
			},
			$html
		);
	}

	/**
	 * Shortcode-Block umwandeln.
	 *
	 * @param string $text Inhalt des Shortcode-Blocks.
	 * @return string|null
	 */
	public function shortcode_block( $text ) {
		if ( ! preg_match( '/^\[([a-z0-9_-]+)([^\]]*)\]$/i', trim( $text ), $m ) ) {
			return null;
		}
		$tag  = strtolower( $m[1] );
		$atts = shortcode_parse_atts( trim( rtrim( $m[2], '/' ) ) );
		$atts = is_array( $atts ) ? $atts : array();

		switch ( $tag ) {
			case 'table':
				return $this->tablepress( $atts['id'] ?? '' );
			case 'popup':
			case 'popup_trigger':
				if ( ! shortcode_exists( $tag ) ) {
					$this->log[] = sprintf( __( 'Shortcode [%s] entfernt (Plugin nicht mehr vorhanden)', 'fvbb-core' ), $tag );
					return '';
				}
				return null;
		}
		return null;
	}

	/* =====================================================================
	 * Markup-Bausteine (exakt wie vom Editor gespeichert)
	 * ================================================================== */

	/**
	 * Block-Kommentar.
	 *
	 * @param string $name    Name ohne core/.
	 * @param array  $attrs   Attribute.
	 * @param string $content Inhalt (leer = selbstschließend).
	 * @return string
	 */
	public static function block( $name, array $attrs, $content ) {
		return get_comment_delimited_block_content( $name, $attrs, $content );
	}

	public static function heading( $text_html, $level = 2, array $opt = array() ) {
		$attrs   = array();
		$classes = array( 'wp-block-heading' );
		if ( 2 !== $level ) {
			$attrs['level'] = $level;
		}
		if ( ! empty( $opt['className'] ) ) {
			$attrs['className'] = $opt['className'];
		}
		if ( ! empty( $opt['align'] ) ) {
			$attrs['style'] = array( 'typography' => array( 'textAlign' => $opt['align'] ) );
			$classes[]      = 'has-text-align-' . $opt['align'];
		}
		if ( ! empty( $opt['className'] ) ) {
			$classes[] = $opt['className'];
		}
		$id = ! empty( $opt['anchor'] ) ? ' id="' . esc_attr( $opt['anchor'] ) . '"' : '';
		return self::block( 'core/heading', $attrs, "\n<h{$level}{$id} class=\"" . implode( ' ', $classes ) . "\">{$text_html}</h{$level}>\n" );
	}

	public static function paragraph( $html, array $opt = array() ) {
		$attrs   = array();
		$classes = array();
		if ( ! empty( $opt['className'] ) ) {
			$attrs['className'] = $opt['className'];
		}
		if ( ! empty( $opt['align'] ) ) {
			$attrs['style'] = array( 'typography' => array( 'textAlign' => $opt['align'] ) );
			$classes[]      = 'has-text-align-' . $opt['align'];
		}
		if ( ! empty( $opt['className'] ) ) {
			$classes[] = $opt['className'];
		}
		$cls = $classes ? ' class="' . implode( ' ', $classes ) . '"' : '';
		return self::block( 'core/paragraph', $attrs, "\n<p{$cls}>{$html}</p>\n" );
	}

	/**
	 * Buttons-Gruppe.
	 *
	 * @param array  $buttons Liste aus [text, url, style, new_window].
	 * @param string $justify left|center|right|space-between.
	 * @return string
	 */
	public static function buttons( array $buttons, $justify = '' ) {
		$inner = array();
		foreach ( $buttons as $btn ) {
			$attrs = array();
			$cls   = 'wp-block-button';
			if ( ! empty( $btn['style'] ) ) {
				$attrs['className'] = 'is-style-' . $btn['style'];
				$cls               .= ' is-style-' . $btn['style'];
			}
			$target = ! empty( $btn['new_window'] ) ? ' target="_blank" rel="noreferrer noopener"' : '';
			$inner[] = self::block( 'core/button', $attrs, "\n<div class=\"{$cls}\"><a class=\"wp-block-button__link wp-element-button\" href=\"" . esc_url( $btn['url'], array( 'http', 'https', 'mailto', 'tel' ) ) . "\"{$target}>" . $btn['text'] . "</a></div>\n" );
		}
		$attrs = array();
		if ( $justify && 'left' !== $justify ) {
			$attrs['layout'] = array( 'type' => 'flex', 'justifyContent' => $justify );
		}
		return self::block( 'core/buttons', $attrs, "\n<div class=\"wp-block-buttons\">" . implode( "\n\n", $inner ) . "</div>\n" );
	}

	/**
	 * Einzelbild.
	 *
	 * @param array $o id, url, alt, size, className, href, new_window, linkDestination.
	 * @return string
	 */
	public static function image( array $o ) {
		$attrs = array();
		$fig   = array( 'wp-block-image' );
		$id    = (int) ( $o['id'] ?? 0 );
		$size  = $o['size'] ?? '';
		$src   = $o['url'] ?? '';

		if ( $id ) {
			$attrs['id'] = $id;
			if ( $size ) {
				$attrs['sizeSlug'] = $size;
				$fig[]             = 'size-' . $size;
				$s                 = wp_get_attachment_image_src( $id, $size );
				if ( $s ) {
					$src = $s[0];
				}
			}
		}
		if ( ! empty( $o['href'] ) ) {
			$attrs['linkDestination'] = 'custom';
		} elseif ( $id && $size ) {
			$attrs['linkDestination'] = 'none';
		}
		if ( ! empty( $o['className'] ) ) {
			$attrs['className'] = $o['className'];
			$fig[]              = $o['className'];
		}
		$img_cls = $id ? ' class="wp-image-' . $id . '"' : '';
		$img     = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $o['alt'] ?? '' ) . '"' . $img_cls . '/>';
		if ( ! empty( $o['href'] ) ) {
			$t   = ! empty( $o['new_window'] ) ? ' target="_blank" rel="noreferrer noopener"' : '';
			$img = '<a href="' . esc_url( $o['href'] ) . '"' . $t . '>' . $img . '</a>';
		}
		return self::block( 'core/image', $attrs, "\n<figure class=\"" . implode( ' ', $fig ) . "\">{$img}</figure>\n" );
	}

	/**
	 * Gruppe.
	 *
	 * @param string $inner   Innere Blöcke.
	 * @param array  $attrs   Block-Attribute.
	 * @param array  $classes Zusätzliche Klassen im HTML (Reihenfolge wie Editor).
	 * @param string $tag     div|section.
	 * @param string $anchor  ID.
	 * @return string
	 */
	public static function group( $inner, array $attrs = array(), array $classes = array(), $tag = 'div', $anchor = '' ) {
		if ( 'div' !== $tag ) {
			$attrs = array_merge( array( 'tagName' => $tag ), $attrs );
		}
		$id = $anchor ? ' id="' . esc_attr( $anchor ) . '"' : '';
		return self::block( 'core/group', $attrs, "\n<{$tag}{$id} class=\"" . implode( ' ', array_merge( array( 'wp-block-group' ), $classes ) ) . "\">{$inner}</{$tag}>\n" );
	}

	/**
	 * Info-Box (ersetzt SiteOrigin-CTA).
	 *
	 * @param string $title    Titel.
	 * @param string $sub      Untertitel.
	 * @param array  $buttons  Buttons.
	 * @return string
	 */
	public static function box( $title, $sub, array $buttons ) {
		$text = self::heading( $title, 3 );
		if ( '' !== trim( $sub ) ) {
			$text .= "\n\n" . self::paragraph( $sub );
		}
		$left  = self::group(
			$text,
			array(
				'style'  => array( 'spacing' => array( 'blockGap' => '0.2rem' ) ),
				'layout' => array( 'type' => 'flex', 'orientation' => 'vertical', 'flexWrap' => 'nowrap' ),
			)
		);
		$inner = $left;
		if ( $buttons ) {
			$inner .= "\n\n" . self::buttons( $buttons );
		}
		return self::group(
			$inner,
			array(
				'className' => 'is-style-fvbb-box',
				'layout'    => array( 'type' => 'flex', 'flexWrap' => 'nowrap', 'justifyContent' => 'space-between', 'verticalAlignment' => 'center' ),
			),
			array( 'is-style-fvbb-box' )
		);
	}

	/* =====================================================================
	 * TablePress
	 * ================================================================== */

	protected function tablepress( $table_id ) {
		$table_id = trim( (string) $table_id );
		$map      = json_decode( (string) get_option( 'tablepress_tables', '' ), true );
		$post_id  = isset( $map['table_post'][ $table_id ] ) ? (int) $map['table_post'][ $table_id ] : 0;
		$post     = $post_id ? get_post( $post_id ) : null;

		// Schon übernommen? (z. B. zweite Seite mit derselben Tabelle oder erneute Umstellung)
		$existing = get_posts( array(
			'post_type'   => 'fvbb_table',
			'post_status' => 'any',
			'meta_key'    => '_fvbb_from_tablepress', // phpcs:ignore
			'meta_value'  => $table_id, // phpcs:ignore
			'numberposts' => 1,
			'fields'      => 'ids',
		) );

		if ( ! $existing && ( ! $post || 'tablepress_table' !== $post->post_type ) ) {
			$this->warnings[] = sprintf( __( 'TablePress-Tabelle %s nicht gefunden', 'fvbb-core' ), $table_id );
			return null;
		}

		$new_id = $existing ? (int) $existing[0] : 0;
		$title  = $post ? $post->post_title : get_the_title( $new_id );
		$cols   = 0;

		if ( ! $new_id ) {
			$data = json_decode( $post->post_content, true );
			if ( ! is_array( $data ) || ! $data ) {
				$this->warnings[] = sprintf( __( 'TablePress-Tabelle %s ist leer', 'fvbb-core' ), $table_id );
				return null;
			}
			$opt = json_decode( (string) get_post_meta( $post_id, '_tablepress_table_options', true ), true );
			$vis = json_decode( (string) get_post_meta( $post_id, '_tablepress_table_visibility', true ), true );

			$hidden_rows = array();
			foreach ( (array) ( $vis['rows'] ?? array() ) as $i => $v ) {
				if ( 0 === (int) $v ) {
					$hidden_rows[] = $i;
				}
			}
			// Ganz leere Zeilen (z. B. vergessene Leerzeile in der Vorstandstabelle) ausblenden.
			foreach ( $data as $i => $row ) {
				if ( '' === trim( implode( '', array_map( 'strval', (array) $row ) ) ) && ! in_array( $i, $hidden_rows, true ) ) {
					$hidden_rows[] = $i;
				}
			}
			$hidden_cols = array();
			foreach ( (array) ( $vis['columns'] ?? array() ) as $i => $v ) {
				if ( 0 === (int) $v ) {
					$hidden_cols[] = $i;
				}
			}
			foreach ( $data as $row ) {
				foreach ( (array) $row as $cell ) {
					if ( 0 === strpos( (string) $cell, '=' ) ) {
						$this->warnings[] = sprintf( __( 'TablePress-Tabelle „%s“ enthält Formeln – Werte bitte prüfen', 'fvbb-core' ), $title );
						break 2;
					}
				}
			}
			$cols = isset( $data[0] ) ? count( $data[0] ) - count( $hidden_cols ) : 0;

			// Kopfzeile und Zwischenüberschriften (nur erste Zelle gefüllt) werden auf der Website
			// automatisch fett – das <strong> drumherum macht das Bearbeiten nur unübersichtlich.
			$unwrap = function ( $c ) {
				$c = trim( (string) $c );
				return preg_match( '#^<strong>([^<]*)</strong>$#i', $c, $m ) ? $m[1] : $c;
			};
			foreach ( $data as $ri => $row ) {
				$filled = count( array_filter( array_map( 'trim', array_map( 'strval', (array) $row ) ), 'strlen' ) );
				$is_group = 1 === $filled && '' !== trim( (string) $row[0] ) && count( $row ) > 2;
				if ( ( 0 === $ri && ! empty( $opt['table_head'] ) ) || $is_group ) {
					$data[ $ri ] = array_map( $unwrap, (array) $row );
				}
			}

			if ( $this->dry ) {
				$this->log[] = sprintf( __( 'TablePress „%s“ → Tabellen-Verwaltung (Block „Tabelle“)', 'fvbb-core' ), $title );
				return '<!-- wp:fvbb/table {"id":0} /-->';
			}

			$new_id = fvbb_table_create( $title, array(
				'rows'        => $data,
				'head'        => ! empty( $opt['table_head'] ),
				'foot'        => ! empty( $opt['table_foot'] ),
				'hidden_rows' => $hidden_rows,
				'hidden_cols' => $hidden_cols,
				'show_title'  => ! empty( $opt['print_name'] ),
				'note'        => ! empty( $opt['print_description'] ) ? $post->post_excerpt : '',
			) );
			if ( ! $new_id ) {
				$this->warnings[] = sprintf( __( 'Tabelle „%s“ konnte nicht angelegt werden', 'fvbb-core' ), $title );
				return null;
			}
			update_post_meta( $new_id, '_fvbb_from_tablepress', $table_id );
		} else {
			$d    = function_exists( 'fvbb_table_get' ) ? fvbb_table_get( $new_id ) : null;
			$cols = $d ? count( $d['rows'][0] ) - count( $d['hidden_cols'] ) : 0;
		}

		$attrs = array( 'id' => $new_id );
		if ( $cols > 3 ) {
			$attrs['align'] = 'wide';
		}
		$this->log[] = sprintf( __( 'TablePress „%s“ → Tabellen-Verwaltung (Block „Tabelle“)', 'fvbb-core' ), $title );
		return self::block( 'fvbb/table', $attrs, '' );
	}

	/* =====================================================================
	 * Timeline Block (bPlugins) → Zeitleiste
	 * ================================================================== */

	/**
	 * @param array  $attrs Block-Attribute.
	 * @param string $html  Gespeichertes HTML (enthält die Daten auch als data-attributes).
	 * @return string|null
	 */
	protected function timeline( array $attrs, $html ) {
		$list = $attrs['timelines'] ?? null;
		if ( ! is_array( $list ) && preg_match( "/data-attributes='([^']*)'/", (string) $html, $m ) ) {
			$data = json_decode( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
			$list = is_array( $data ) ? ( $data['timelines'] ?? null ) : null;
		}
		if ( ! is_array( $list ) ) {
			$this->warnings[] = __( 'Zeitleiste ohne Einträge gefunden – bitte prüfen', 'fvbb-core' );
			return null;
		}
		$items = array();
		foreach ( $list as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			// "label" ist das sichtbare Datum, "date" war im alten Block nur ein Platzhalter.
			$date = trim( wp_strip_all_tags( (string) ( $t['label'] ?? '' ) ) );
			if ( '' === $date ) {
				$date = trim( wp_strip_all_tags( (string) ( $t['date'] ?? '' ) ) );
			}
			$text = (string) ( $t['description'] ?? '' );
			$text = preg_replace( '#<br\s*/?>#i', "\n", $text );
			$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$text = trim( preg_replace( "/[ \t]+(\n|$)/", '$1', str_replace( "\t", ' ', $text ) ) );
			if ( '' === $date && '' === $text ) {
				continue;
			}
			$items[] = array( 'date' => $date, 'title' => '', 'text' => $text );
		}
		$this->log[] = sprintf( __( 'Zeitleiste (Timeline Block) → Block „Zeitleiste“ mit %d Einträgen', 'fvbb-core' ), count( $items ) );
		return self::block( 'fvbb/zeitleiste', array( 'items' => $items ), '' );
	}
}
