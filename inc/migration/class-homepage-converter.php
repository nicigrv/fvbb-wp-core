<?php
/**
 * Wandelt die Startseite aus dem Mesmerize-/EmpowerWP-Companion-Baukasten in Blöcke um.
 *
 * Jede Baukasten-Sektion wird zu einem Abschnitt (Gruppe in voller Breite).
 * Erkannt werden: „Wir …“-Karten mit Symbolen (überlappend), Aktuelles, Text + Bild
 * (auch mit Hintergrundbild-Spalte), Vorstand (Personen-Karten), Hinweis mit Button,
 * Kontakt mit Formular, Bankverbindung und Fotoreihen. Unbekannte Sektionen werden
 * als HTML-Block übernommen und im Protokoll gemeldet.
 *
 * @package fvbb-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FVBB_Homepage_Converter {

	/** @var string[] */
	public $log = array();

	/** @var string[] */
	public $warnings = array();

	/** @var string Hintergrund des vorigen Abschnitts (für den Wechsel hell/weiß) */
	protected $prev_bg = '';

	/**
	 * Ist das Baukasten-Inhalt?
	 *
	 * @param string $content Inhalt.
	 * @return bool
	 */
	public static function is_builder_content( $content ) {
		return false !== strpos( $content, 'data-export-id=' ) && ! has_blocks( $content );
	}

	/**
	 * Umwandeln.
	 *
	 * @param string $html Baukasten-HTML.
	 * @return string
	 */
	public function convert( $html ) {
		$this->log      = array();
		$this->warnings = array();
		$this->prev_bg  = 'start';

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div id="fvbb-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		$xp = new DOMXPath( $doc );

		$sections = $xp->query( '//*[@id="fvbb-root"]/*[@data-export-id]' );
		$out      = array();
		foreach ( $sections as $sec ) {
			$out[] = $this->section( $sec, $xp );
		}
		$this->log[] = sprintf( __( 'Startseite: %d Abschnitte in Blöcke umgewandelt', 'fvbb-core' ), count( $sections ) );
		return implode( "\n\n", array_filter( $out ) );
	}

	/* ------------------------------------------------------------------ */

	protected function cls( DOMElement $el ) {
		return ' ' . preg_replace( '/\s+/', ' ', $el->getAttribute( 'class' ) ) . ' ';
	}

	protected function has_class( DOMElement $el, $c ) {
		return false !== strpos( $this->cls( $el ), ' ' . $c . ' ' );
	}

	protected function inner_html( DOMNode $n ) {
		$h = '';
		foreach ( $n->childNodes as $c ) {
			$h .= $n->ownerDocument->saveHTML( $c );
		}
		return $this->clean_inline( $h );
	}

	/**
	 * Inline-HTML säubern (nur einfache Auszeichnungen behalten).
	 *
	 * @param string $h HTML.
	 * @return string
	 */
	protected function clean_inline( $h ) {
		$h = wp_kses( $h, array(
			'a'      => array( 'href' => true, 'target' => true, 'rel' => true ),
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
		) );
		// <b> wie der Editor als <strong>, leere Auszeichnungen weg.
		$h = preg_replace( '#<(/?)b>#i', '<$1strong>', $h );
		$h = preg_replace( '#<(strong|em|i)>\s*</\1>#i', '', $h );
		// Leerzeichen am Ende von <strong> nach außen ziehen ("<strong>Wir </strong>möchten").
		$h = preg_replace( '#(\s|&nbsp;|\x{00A0})+</strong>#u', '</strong> ', $h );
		$h = preg_replace( '/(&nbsp;|\x{00A0}|\s)+$/u', '', $h );
		$h = preg_replace( '/^(&nbsp;|\x{00A0}|\s)+/u', '', $h );
		$h = preg_replace( '/ {2,}/', ' ', $h );
		return trim( $h );
	}

	/**
	 * Heller Hintergrund der Sektion?
	 *
	 * @param DOMElement $sec Sektion.
	 * @return bool
	 */
	protected function is_light( DOMElement $sec ) {
		return (bool) preg_match( '/background-color:\s*(#f5fafd|rgb\(\s*245,\s*250,\s*253\s*\))/i', $sec->getAttribute( 'style' ) );
	}

	/**
	 * Hintergrund wählen: helle Abschnitte wechseln sich mit weißen ab, damit nicht alles gleich aussieht.
	 *
	 * @param DOMElement $sec   Sektion.
	 * @param string     $force Feste Farbe.
	 * @return string Slug oder ''.
	 */
	protected function bg( DOMElement $sec, $force = null ) {
		if ( null !== $force ) {
			$bg = $force;
		} else {
			$bg = $this->is_light( $sec ) ? 'water' : '';
			if ( 'water' === $bg && 'water' === $this->prev_bg ) {
				$bg = '';
			}
		}
		$this->prev_bg = $bg;
		return $bg;
	}

	/**
	 * Abschnitt (Gruppe in voller Breite).
	 *
	 * @param string $inner  Innere Blöcke.
	 * @param string $bg     Farbe.
	 * @param string $anchor ID.
	 * @param string $class  Zusätzliche Klasse.
	 * @return string
	 */
	protected function wrap( $inner, $bg, $anchor, $class = '' ) {
		$attrs   = array( 'align' => 'full' );
		$classes = array( 'alignfull' );
		if ( $class ) {
			$attrs['className'] = $class;
		}
		if ( $bg ) {
			$attrs['backgroundColor'] = $bg;
			$classes[]                = 'has-' . $bg . '-background-color';
			$classes[]                = 'has-background';
		}
		if ( $class ) {
			$classes[] = $class;
		}
		$attrs['layout'] = array( 'type' => 'constrained' );
		return FVBB_Converter::group( $inner, $attrs, $classes, 'section', $anchor );
	}

	/**
	 * Überschrift (mit Welle) und Einleitung aus der Titel-Spalte.
	 *
	 * @param DOMElement $sec   Sektion.
	 * @param DOMXPath   $xp    XPath.
	 * @param string     $align Ausrichtung.
	 * @return array [Überschrift-Block, Einleitungs-Blöcke[], Überschrift-Text]
	 */
	protected function title( DOMElement $sec, DOMXPath $xp, $align = 'center' ) {
		$col = $xp->query( './/*[contains(concat(" ", normalize-space(@class), " "), " section-title-col ")]', $sec );
		if ( ! $col->length ) {
			return array( '', array(), '' );
		}
		$col     = $col->item( 0 );
		$heading = '';
		$text    = '';
		$leads   = array();
		foreach ( $xp->query( './/h1|.//h2|.//h3|.//p', $col ) as $el ) {
			$html = $this->clean_inline( $this->inner_html( $el ) );
			if ( '' === $html ) {
				continue;
			}
			if ( 'p' === strtolower( $el->tagName ) ) {
				$leads[] = FVBB_Converter::paragraph( $html, array( 'align' => $align, 'className' => $this->has_class( $el, 'lead' ) ? 'fvbb-lead' : '' ) );
			} elseif ( '' === $heading ) {
				$text    = trim( wp_strip_all_tags( $html ) );
				$heading = FVBB_Converter::heading( $html, 2, array( 'className' => 'is-style-fvbb-wave', 'align' => $align ) );
			}
		}
		return array( $heading, $leads, $text );
	}

	/**
	 * Link korrigieren.
	 *
	 * @param string $url Link.
	 * @return string
	 */
	protected function fix_url( $url ) {
		$url  = trim( html_entity_decode( $url ) );
		$url  = preg_replace( '/^matilo:/i', 'mailto:', $url );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$url  = str_replace( 'https://' . $host . '/' . $host . '/', 'https://' . $host . '/', $url );
		if ( false !== strpos( $url, '[mesmerize_blog_link]' ) ) {
			$posts_page = (int) get_option( 'page_for_posts' );
			$url        = $posts_page ? get_permalink( $posts_page ) : home_url( '/' );
		}
		// Eigene Links ohne Schrägstrich am Ende (".../mitgliedsantrag") wie WordPress schreiben.
		if ( fvbb_is_own_host( $url ) && preg_match( '#^https?://[^?\#]+/[^/?\#.]+$#', $url ) ) {
			$url .= '/';
		}
		return $url;
	}

	/**
	 * Baukasten-Button → Button-Daten.
	 *
	 * @param DOMElement $a Link.
	 * @return array
	 */
	protected function button_data( DOMElement $a ) {
		$url      = $this->fix_url( $a->getAttribute( 'href' ) );
		$external = 0 === strpos( $url, 'http' ) && ! fvbb_is_own_host( $url );
		$style    = '';
		if ( 0 === strpos( $url, 'mailto:' ) ) {
			$style = 'fvbb-mail';
		} elseif ( false !== stripos( $url, 'mitglied' ) ) {
			$style = 'fvbb-sun';
		}
		return array(
			'text'       => esc_html( trim( $a->textContent ) ),
			'url'        => $url,
			'style'      => $style,
			'new_window' => $external && '_blank' === $a->getAttribute( 'target' ),
		);
	}

	/**
	 * Bild-Block aus <img> oder Hintergrundbild-Spalte.
	 *
	 * @param string $src   Adresse.
	 * @param string $alt   Alternativtext.
	 * @param string $class Block-Stil.
	 * @return string
	 */
	protected function image_block( $src, $alt = '', $class = 'is-style-fvbb-photo' ) {
		$id = $this->attachment_id( $src );
		return FVBB_Converter::image( array(
			'id'        => $id,
			'size'      => $id ? 'large' : '',
			'url'       => $this->https( $src ),
			'alt'       => $alt,
			'className' => $class,
		) );
	}

	/**
	 * Mediathek-ID zu einer Bildadresse (http/https egal).
	 *
	 * @param string $src Adresse.
	 * @return int
	 */
	protected function attachment_id( $src ) {
		$id = attachment_url_to_postid( $src );
		if ( ! $id ) {
			$id = attachment_url_to_postid( set_url_scheme( $src, 'https' ) );
		}
		if ( ! $id ) {
			$id = attachment_url_to_postid( set_url_scheme( $src, 'http' ) );
		}
		return (int) $id;
	}

	/**
	 * Eigene Bilder über https laden (alte Inhalte enthalten teils http://).
	 *
	 * @param string $src Adresse.
	 * @return string
	 */
	protected function https( $src ) {
		return fvbb_is_own_host( $src ) && is_ssl() ? set_url_scheme( $src, 'https' ) : $src;
	}

	/**
	 * Hintergrundbild aus einem style-Attribut.
	 *
	 * @param DOMElement $el Element.
	 * @return string
	 */
	protected function bg_image( DOMElement $el ) {
		if ( preg_match( '/background-image:\s*url\((["\']?)(.*?)\1\)/i', html_entity_decode( $el->getAttribute( 'style' ) ), $m ) ) {
			return trim( $m[2], "\"' " );
		}
		return '';
	}

	/**
	 * Ist das eine Bild-Spalte (img oder Hintergrundbild)?
	 *
	 * @param DOMElement $col Spalte.
	 * @return bool
	 */
	protected function has_image( DOMElement $col ) {
		if ( 'image' === $col->getAttribute( 'data-bg' ) && $this->bg_image( $col ) ) {
			return true;
		}
		return $col->getElementsByTagName( 'img' )->length > 0;
	}

	/**
	 * Spalten-Kinder eines Elements (class="col…").
	 *
	 * @param DOMElement $row Zeile.
	 * @return DOMElement[]
	 */
	protected function col_children( DOMElement $row ) {
		$cols = array();
		foreach ( $row->childNodes as $c ) {
			if ( $c instanceof DOMElement && preg_match( '/(^|\s)col(-|\s|$)/', $c->getAttribute( 'class' ) ) ) {
				$cols[] = $c;
			}
		}
		return $cols;
	}

	/**
	 * Elemente einer Spalte in Blöcke umwandeln.
	 *
	 * @param DOMElement $col   Spalte.
	 * @param string     $align Textausrichtung.
	 * @param bool       $wave  Überschrift h2 mit Welle?
	 * @param int        $index Position der Spalte (für die Symbolfarbe).
	 * @return string[]
	 */
	protected function column_blocks( DOMElement $col, $align = '', $wave = true, $index = 0 ) {
		$blocks  = array();
		$buttons = array();
		$flush   = function () use ( &$blocks, &$buttons, $align ) {
			if ( $buttons ) {
				$blocks[] = FVBB_Converter::buttons( $buttons, 'center' === $align ? 'center' : '' );
				$buttons  = array();
			}
		};

		if ( 'image' === $col->getAttribute( 'data-bg' ) && $this->bg_image( $col ) ) {
			return array( $this->image_block( $this->bg_image( $col ) ) );
		}

		$walker = function ( DOMNode $node ) use ( &$walker, &$blocks, &$buttons, $flush, $align, $wave, $index ) {
			foreach ( $node->childNodes as $c ) {
				if ( ! $c instanceof DOMElement ) {
					continue;
				}
				$tag = strtolower( $c->tagName );

				// Verschachtelte Zeile mit mehreren Spalten → Raster (z. B. vier „Wir …“-Punkte).
				$sub = $this->has_class( $c, 'row' ) ? $this->col_children( $c ) : array();
				if ( count( $sub ) > 1 ) {
					$flush();
					$items = array();
					foreach ( $sub as $s ) {
						$items = array_merge( $items, $this->column_blocks( $s, '', false ) );
					}
					if ( $items ) {
						$blocks[] = FVBB_Converter::group(
							implode( "\n\n", $items ),
							array( 'className' => 'fvbb-points', 'layout' => array( 'type' => 'grid', 'columnCount' => 2, 'minimumColumnWidth' => '14rem' ) ),
							array( 'fvbb-points' )
						);
					}
					continue;
				}

				if ( in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
					$flush();
					$text = $this->clean_inline( $this->inner_html( $c ) );
					if ( '' === $text ) {
						continue;
					}
					$level = (int) substr( $tag, 1 );
					if ( 5 === $level || 6 === $level ) {
						// Kleine Überzeile → Absatz.
						$blocks[] = FVBB_Converter::paragraph( $text, array( 'align' => $align, 'className' => 'fvbb-kicker' ) );
						continue;
					}
					$opt = array( 'align' => $align );
					if ( 2 === $level && $wave ) {
						$opt['className'] = 'is-style-fvbb-wave';
					}
					$blocks[] = FVBB_Converter::heading( $text, max( 2, $level ), $opt );
				} elseif ( 'p' === $tag ) {
					$flush();
					$text = $this->clean_inline( $this->inner_html( $c ) );
					if ( '' !== $text ) {
						$blocks[] = FVBB_Converter::paragraph( $text, array( 'align' => $align ) );
					}
				} elseif ( 'a' === $tag && $this->has_class( $c, 'button' ) ) {
					$buttons[] = $this->button_data( $c );
				} elseif ( 'img' === $tag ) {
					$flush();
					$blocks[] = $this->image_block( $c->getAttribute( 'src' ), $c->getAttribute( 'alt' ), $this->has_class( $c, 'face' ) ? 'is-style-fvbb-portrait' : 'is-style-fvbb-photo' );
				} elseif ( 'i' === $tag ) {
					$icon = function_exists( 'fvbb_symbol_from_fa' ) ? fvbb_symbol_from_fa( $c->getAttribute( 'class' ) ) : '';
					if ( $icon ) {
						$flush();
						$attrs = array( 'icon' => $icon );
						if ( 1 === $index % 2 ) {
							$attrs['color'] = 'sun';
						}
						$blocks[] = FVBB_Converter::block( 'fvbb/symbol', $attrs, '' );
					}
				} else {
					$walker( $c );
				}
			}
		};
		$walker( $col );
		$flush();
		return $blocks;
	}

	/**
	 * Spalten der Sektion finden (erste Zeile mit Spalten, außer der Titel-Zeile).
	 *
	 * @param DOMElement $sec Sektion.
	 * @param DOMXPath   $xp  XPath.
	 * @return DOMElement[]
	 */
	protected function columns( DOMElement $sec, DOMXPath $xp ) {
		$rows = $xp->query( './/*[contains(concat(" ", normalize-space(@class), " "), " row ")][not(.//*[contains(@class,"section-title-col")])][not(contains(@class,"section-title-col"))]', $sec );
		foreach ( $rows as $row ) {
			$cols = $this->col_children( $row );
			if ( $cols ) {
				return $cols;
			}
		}
		return array();
	}

	/**
	 * Nur Buttons?
	 *
	 * @param string[] $blocks Blöcke.
	 * @return bool
	 */
	protected function only_buttons( array $blocks ) {
		foreach ( $blocks as $b ) {
			if ( 0 !== strpos( $b, '<!-- wp:buttons' ) ) {
				return false;
			}
		}
		return (bool) $blocks;
	}

	/* ------------------------------------------------------------------ */

	protected function section( DOMElement $sec, DOMXPath $xp ) {
		$type   = $sec->getAttribute( 'data-export-id' );
		$cat    = $sec->getAttribute( 'data-category' );
		$anchor = sanitize_title( $sec->getAttribute( 'id' ) );

		if ( 'latest_news' === $cat || 0 === strpos( $type, 'blog-section' ) ) {
			return $this->news( $sec, $xp, $anchor );
		}
		if ( 'team' === $cat ) {
			return $this->team( $sec, $xp, $anchor );
		}
		if ( 'contact' === $cat ) {
			return $this->contact( $sec, $xp, $anchor );
		}

		list( $title, $leads, $title_text ) = $this->title( $sec, $xp );
		$head = implode( "\n\n", array_filter( array_merge( array( $title ), $leads ) ) );

		// Bankverbindung: Einleitung in einen Kasten, Fotos darunter.
		if ( false !== stripos( $title_text, 'bankverbindung' ) ) {
			return $this->bank( $sec, $xp, $title, $leads, $anchor );
		}

		$cols = $this->columns( $sec, $xp );
		if ( ! $cols && ! $head ) {
			$this->warnings[] = sprintf( __( 'Startseiten-Abschnitt „%s“ nicht erkannt – als HTML übernommen', 'fvbb-core' ), $sec->getAttribute( 'data-label' ) );
			return $this->wrap( FVBB_Converter::block( 'core/html', array(), "\n" . $sec->ownerDocument->saveHTML( $sec ) . "\n" ), $this->bg( $sec ), $anchor );
		}

		$overlap = 'true' === $sec->getAttribute( 'data-overlap' ) || 'overlappable' === $cat;

		// Text + Bild nebeneinander (zwei Spalten, eine davon mit Bild).
		$with_img = 0;
		foreach ( $cols as $c ) {
			if ( $this->has_image( $c ) ) {
				$with_img++;
			}
		}
		if ( 2 === count( $cols ) && 1 === $with_img && ! $overlap ) {
			$col_html = array();
			foreach ( $cols as $c ) {
				$inner      = $this->column_blocks( $c, '', true );
				$col_html[] = FVBB_Converter::block(
					'core/column',
					array( 'verticalAlignment' => 'center' ),
					"\n<div class=\"wp-block-column is-vertically-aligned-center\">" . implode( "\n\n", $inner ) . "</div>\n"
				);
			}
			$columns = FVBB_Converter::block(
				'core/columns',
				array( 'verticalAlignment' => 'center', 'align' => 'wide' ),
				"\n<div class=\"wp-block-columns alignwide are-vertically-aligned-center\">" . implode( "\n\n", $col_html ) . "</div>\n"
			);
			$this->log[] = __( 'Startseite: Text + Bild → Spalten', 'fvbb-core' );
			return $this->wrap( ( $head ? $head . "\n\n" : '' ) . $columns, $this->bg( $sec ), $anchor );
		}

		// Spalten in Blöcke.
		$cards   = array();
		$buttons = array();
		foreach ( $cols as $i => $c ) {
			$inner = $this->column_blocks( $c, 'center', false, $i );
			if ( ! $inner ) {
				continue;
			}
			if ( $this->only_buttons( $inner ) ) {
				$buttons = array_merge( $buttons, $inner );
				continue;
			}
			$only_image = 1 === count( $inner ) && 0 === strpos( $inner[0], '<!-- wp:image' );
			$cards[]    = $only_image ? $inner[0] : FVBB_Converter::group( implode( "\n\n", $inner ), array( 'className' => 'is-style-fvbb-card' ), array( 'is-style-fvbb-card' ) );
		}

		// Nur Text + Button (z. B. „Wir sind kein gemeinnütziger Verein!“) → Hinweis-Kasten.
		if ( ! $cards ) {
			$box = FVBB_Converter::group(
				$head . ( $buttons ? "\n\n" . implode( "\n\n", $buttons ) : '' ),
				array( 'className' => 'is-style-fvbb-sunbox', 'layout' => array( 'type' => 'constrained' ) ),
				array( 'is-style-fvbb-sunbox' )
			);
			$this->log[] = sprintf( __( 'Startseite: „%s“ → Hinweis-Kasten', 'fvbb-core' ), $title_text );
			$wrapped = FVBB_Converter::group( $box, array( 'layout' => array( 'type' => 'constrained', 'contentSize' => '820px' ) ) );
			return $this->wrap( $wrapped, $this->bg( $sec ), $anchor );
		}

		if ( 1 === count( $cards ) ) {
			$grid = FVBB_Converter::group( $cards[0], array( 'layout' => array( 'type' => 'constrained', 'contentSize' => '520px' ) ) );
		} else {
			$grid = FVBB_Converter::group(
				implode( "\n\n", $cards ),
				array( 'align' => 'wide', 'className' => 'fvbb-cards', 'layout' => array( 'type' => 'grid', 'minimumColumnWidth' => '16rem' ) ),
				array( 'alignwide', 'fvbb-cards' )
			);
		}
		$inner = ( $head ? $head . "\n\n" : '' ) . $grid . ( $buttons ? "\n\n" . implode( "\n\n", $buttons ) : '' );

		if ( $overlap ) {
			$this->log[] = __( 'Startseite: „Wir …“-Karten mit Symbolen (ragen in das Kopfbild)', 'fvbb-core' );
			return $this->wrap( $inner, $this->bg( $sec, '' ), $anchor, 'fvbb-overlap' );
		}
		return $this->wrap( $inner, $this->bg( $sec ), $anchor );
	}

	protected function news( DOMElement $sec, DOMXPath $xp, $anchor ) {
		list( $title, $leads ) = $this->title( $sec, $xp );
		$query = FVBB_Converter::block(
			'core/query',
			array(
				'queryId'   => 1,
				'query'     => array(
					'perPage'  => 3,
					'pages'    => 0,
					'offset'   => 0,
					'postType' => 'post',
					'order'    => 'desc',
					'orderBy'  => 'date',
					'author'   => '',
					'search'   => '',
					'exclude'  => array(),
					'sticky'   => '',
					'inherit'  => false,
				),
				'align'     => 'wide',
				'className' => 'fvbb-news',
			),
			"\n<div class=\"wp-block-query alignwide fvbb-news\">"
			. FVBB_Converter::block( 'core/post-template', array( 'layout' => array( 'type' => 'grid', 'columnCount' => 3 ) ),
				"\n" . FVBB_Converter::block( 'core/post-featured-image', array( 'isLink' => true, 'aspectRatio' => '4/3' ), '' )
				. "\n\n" . FVBB_Converter::block( 'core/post-date', array( 'className' => 'fvbb-news__date' ), '' )
				. "\n\n" . FVBB_Converter::block( 'core/post-title', array( 'level' => 3, 'isLink' => true ), '' )
				. "\n\n" . FVBB_Converter::block( 'core/post-excerpt', array( 'moreText' => 'Weiterlesen', 'excerptLength' => 24 ), '' )
				. "\n"
			)
			. "</div>\n"
		);

		$buttons = array();
		foreach ( $xp->query( './/a[contains(concat(" ", normalize-space(@class), " "), " button ")]', $sec ) as $a ) {
			$buttons[] = $this->button_data( $a );
		}
		$inner = implode( "\n\n", array_filter( array_merge( array( $title ), $leads ) ) );
		$inner = ( $inner ? $inner . "\n\n" : '' ) . $query;
		if ( $buttons ) {
			$inner .= "\n\n" . FVBB_Converter::buttons( $buttons, 'center' );
		} else {
			$posts_page = (int) get_option( 'page_for_posts' );
			if ( $posts_page ) {
				$inner .= "\n\n" . FVBB_Converter::buttons( array( array( 'text' => esc_html__( 'Weitere Beiträge', 'fvbb-core' ), 'url' => get_permalink( $posts_page ), 'style' => 'fvbb-light' ) ), 'center' );
			}
		}
		$this->log[] = __( 'Startseite: Aktuelles → Abfrage-Block (letzte 3 Beiträge)', 'fvbb-core' );
		return $this->wrap( $inner, $this->bg( $sec, '' ), $anchor );
	}

	/**
	 * Vorstand: Personen-Karten (Foto, Name, Amt, E-Mail).
	 */
	protected function team( DOMElement $sec, DOMXPath $xp, $anchor ) {
		list( $title, $leads, $title_text ) = $this->title( $sec, $xp );
		$cards = array();
		foreach ( $xp->query( './/*[contains(concat(" ", normalize-space(@class), " "), " card ")]', $sec ) as $card ) {
			$inner = array();
			$img   = $card->getElementsByTagName( 'img' );
			if ( $img->length ) {
				$inner[] = $this->image_block( $img->item( 0 )->getAttribute( 'src' ), '', 'is-style-fvbb-portrait' );
			}
			$name = $xp->query( './/h1|.//h2|.//h3|.//h4|.//h5', $card );
			if ( $name->length ) {
				$inner[] = FVBB_Converter::heading( esc_html( trim( $name->item( 0 )->textContent ) ), 3, array( 'align' => 'center' ) );
			}
			foreach ( $xp->query( './/p', $card ) as $p ) {
				$t = $this->clean_inline( $this->inner_html( $p ) );
				if ( '' !== $t ) {
					$inner[] = FVBB_Converter::paragraph( wp_strip_all_tags( $t ), array( 'align' => 'center' ) );
				}
			}
			// Nur echte Links übernehmen (die übrigen Symbole waren ausgeblendete Platzhalter).
			foreach ( $xp->query( './/a[@href]', $card ) as $a ) {
				$href = trim( $a->getAttribute( 'href' ) );
				if ( 'true' === $a->getAttribute( 'data-reiki-hidden' ) || '' === $href || '#' === $href ) {
					continue;
				}
				$href  = $this->fix_url( $href );
				$label = 0 === strpos( $href, 'mailto:' ) ? __( 'E-Mail schreiben', 'fvbb-core' ) : __( 'Mehr erfahren', 'fvbb-core' );
				$inner[] = FVBB_Converter::paragraph( '<a href="' . esc_url( $href, array( 'http', 'https', 'mailto', 'tel' ) ) . '">' . esc_html( $label ) . '</a>', array( 'align' => 'center', 'className' => 'fvbb-person__link' ) );
				break;
			}
			if ( $inner ) {
				$cards[] = FVBB_Converter::group( implode( "\n\n", $inner ), array( 'className' => 'is-style-fvbb-person', 'layout' => array( 'type' => 'constrained' ) ), array( 'is-style-fvbb-person' ) );
			}
		}
		$grid = FVBB_Converter::group(
			implode( "\n\n", $cards ),
			array( 'align' => 'wide', 'className' => 'fvbb-people', 'layout' => array( 'type' => 'grid', 'minimumColumnWidth' => '13rem' ) ),
			array( 'alignwide', 'fvbb-people' )
		);
		$this->log[] = sprintf( __( 'Startseite: %1$s → %2$d Personen-Karten', 'fvbb-core' ), $title_text ? $title_text : __( 'Team', 'fvbb-core' ), count( $cards ) );
		$head = implode( "\n\n", array_filter( array_merge( array( $title ), $leads ) ) );
		return $this->wrap( ( $head ? $head . "\n\n" : '' ) . $grid, $this->bg( $sec ), $anchor );
	}

	/**
	 * Bankverbindung mit Fotoreihe.
	 */
	protected function bank( DOMElement $sec, DOMXPath $xp, $title, array $leads, $anchor ) {
		$box = $title;
		if ( $leads ) {
			$box .= "\n\n" . FVBB_Converter::group( implode( "\n\n", $leads ), array( 'className' => 'fvbb-bank', 'layout' => array( 'type' => 'constrained' ) ), array( 'fvbb-bank' ) );
		}
		// Kasten schmal, Fotoreihe darunter breit.
		$inner = FVBB_Converter::group( $box, array( 'layout' => array( 'type' => 'constrained', 'contentSize' => '640px' ) ) );
		$imgs  = array();
		foreach ( $xp->query( './/img', $sec ) as $img ) {
			$imgs[] = $img;
		}
		if ( $imgs ) {
			$inner .= "\n\n" . $this->gallery( $imgs );
		}
		$this->log[] = __( 'Startseite: Bankverbindung → Kasten + Fotoreihe', 'fvbb-core' );
		return $this->wrap( $inner, $this->bg( $sec, 'sand' ), $anchor );
	}

	/**
	 * Galerie-Block (Lightbox kommt vom Theme).
	 *
	 * @param DOMElement[] $imgs Bilder.
	 * @return string
	 */
	protected function gallery( array $imgs ) {
		$items = array();
		foreach ( $imgs as $img ) {
			$src = $img->getAttribute( 'src' );
			$id  = $this->attachment_id( $src );
			$o   = array( 'id' => $id, 'size' => $id ? 'large' : '', 'url' => $this->https( $src ), 'alt' => $img->getAttribute( 'alt' ) );
			$items[] = FVBB_Converter::image( $o );
		}
		$n = count( $items );
		return FVBB_Converter::block(
			'core/gallery',
			array( 'columns' => min( 3, max( 1, $n ) ), 'linkTo' => 'none', 'align' => 'wide', 'className' => 'fvbb-photos' ),
			"\n<figure class=\"wp-block-gallery alignwide has-nested-images columns-" . min( 3, max( 1, $n ) ) . " is-cropped fvbb-photos\">" . implode( "\n\n", $items ) . "</figure>\n"
		);
	}

	protected function contact( DOMElement $sec, DOMXPath $xp, $anchor ) {
		list( $title, $leads ) = $this->title( $sec, $xp );
		$bg = $this->bg_image( $sec );
		// Formular-Shortcode aus dem Baukasten-Attribut lesen.
		$form = '';
		$raw  = $sec->ownerDocument->saveHTML( $sec );
		for ( $i = 0; $i < 6; $i++ ) {
			$dec = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $dec === $raw ) {
				break;
			}
			$raw = $dec;
		}
		if ( preg_match( '/\[?(contact-form-7\s+id="?([^"\s\]]+)"?(?:\s+title="([^"]*)")?)/', $raw, $fm ) ) {
			$form = '[contact-form-7 id="' . $fm[2] . '"' . ( ! empty( $fm[3] ) ? ' title="' . $fm[3] . '"' : '' ) . ']';
		}
		$inner = implode( "\n\n", array_filter( array_merge( array( $title ), $leads ) ) );
		if ( $inner ) {
			$inner .= "\n\n";
		}
		if ( $form ) {
			$inner .= FVBB_Converter::group(
				FVBB_Converter::block( 'core/shortcode', array(), "\n" . $form . "\n" ),
				array( 'className' => 'fvbb-form-card', 'layout' => array( 'type' => 'constrained' ) ),
				array( 'fvbb-form-card' )
			);
		} else {
			$this->warnings[] = __( 'Startseite: Kontaktformular nicht gefunden – bitte Formular-Block einfügen', 'fvbb-core' );
		}

		if ( ! $bg ) {
			$this->prev_bg = 'water';
			return $this->wrap( $inner, 'water', $anchor );
		}
		$this->prev_bg = 'cover';

		$bg    = $this->https( $bg );
		$bg_id = $this->attachment_id( $bg );
		$attrs = array( 'url' => $bg );
		if ( $bg_id ) {
			$attrs['id'] = $bg_id;
		}
		$attrs = array_merge( $attrs, array(
			'dimRatio'           => 80,
			'overlayColor'       => 'navy',
			'isUserOverlayColor' => true,
			'align'              => 'full',
			'layout'             => array( 'type' => 'constrained', 'contentSize' => '760px' ),
		) );
		$img_cls = $bg_id ? 'wp-block-cover__image-background wp-image-' . $bg_id : 'wp-block-cover__image-background';
		$id_attr = $anchor ? ' id="' . esc_attr( $anchor ) . '"' : '';
		$this->log[] = __( 'Startseite: Kontakt → Bild-Abschnitt mit Formular', 'fvbb-core' );
		return FVBB_Converter::block(
			'core/cover',
			$attrs,
			"\n<div{$id_attr} class=\"wp-block-cover alignfull\"><img class=\"{$img_cls}\" alt=\"\" src=\"" . esc_url( $bg ) . "\" data-object-fit=\"cover\"/><span aria-hidden=\"true\" class=\"wp-block-cover__background has-navy-background-color has-background-dim-80 has-background-dim\"></span><div class=\"wp-block-cover__inner-container\">{$inner}</div></div>\n"
		);
	}
}
