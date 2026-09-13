<?php
/**
 * Recipe source, merge, and %root% unwrap logic.
 *
 * Built-ins come from \Automatic_CSS\API::get_all_recipes() (a flat name => css
 * map with wrapIn wrappers and [breakpoint-*] tokens already applied). Custom
 * recipes come from the `acss_recipe_drawer_custom` option and override
 * built-ins on name collision. The merged map is cached in a transient salted
 * with the plugin version and the ACSS version so a framework upgrade
 * invalidates it.
 *
 * @package ACSS_Recipe_Drawer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACSS_Recipe_Drawer_Recipes {

	const OPTION_NAME   = 'acss_recipe_drawer_custom';
	const TRANSIENT_NAME = 'acss_recipe_drawer_cache';
	const CACHE_TTL      = 12 * HOUR_IN_SECONDS;

	/**
	 * Get the merged recipe map: built-ins first, custom merged over the top.
	 *
	 * @param bool $refresh Bypass the transient cache.
	 * @return array<string, array{css: string, source: string, label: string}>
	 */
	public function get_all( $refresh = false ) {
		$salt = ACSS_RECIPE_DRAWER_VERSION . '|' . $this->get_acss_version();
		if ( $refresh ) {
			delete_transient( self::TRANSIENT_NAME );
		}

		$cached = get_transient( self::TRANSIENT_NAME );
		if ( false !== $cached && is_array( $cached ) && isset( $cached['salt'] ) && $cached['salt'] === $salt && isset( $cached['recipes'] ) ) {
			return $cached['recipes'];
		}

		$recipes = array();

		foreach ( $this->get_builtins() as $name => $css ) {
			$recipes[ $name ] = array(
				'css'    => $this->unwrap_root( $css ),
				'source' => 'acss',
				'label'  => $name,
			);
		}

		foreach ( $this->get_custom() as $name => $data ) {
			$recipes[ $name ] = array(
				'css'    => $this->unwrap_root( $data['css'] ),
				'source' => 'custom',
				'label'  => $name,
			);
		}

		set_transient( self::TRANSIENT_NAME, array( 'salt' => $salt, 'recipes' => $recipes ), self::CACHE_TTL );
		return $recipes;
	}

	/**
	 * Get built-in recipes from the ACSS API, guarded against any throw.
	 *
	 * @return array<string, string> name => css
	 */
	public function get_builtins() {
		if ( ! class_exists( '\Automatic_CSS\API' ) ) {
			return array();
		}
		try {
			$recipes = \Automatic_CSS\API::get_all_recipes();
			if ( ! is_array( $recipes ) ) {
				return array();
			}
			return $recipes;
		} catch ( \Throwable $e ) {
			static $logged = false;
			if ( ! $logged ) {
				error_log( sprintf( 'ACSS Recipe Drawer: get_all_recipes() threw %s: %s [%s:%d]', get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() ) );
				$logged = true;
			}
			return array();
		}
	}

	/**
	 * Get custom recipes from the option.
	 *
	 * @return array<string, array{css: string, description: string}>
	 */
	public function get_custom() {
		$custom = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $custom ) ) {
			return array();
		}
		$clean = array();
		foreach ( $custom as $name => $data ) {
			if ( ! is_array( $data ) || empty( $name ) ) {
				continue;
			}
			$clean[ $name ] = array(
				'css'         => isset( $data['css'] ) ? (string) $data['css'] : '',
				'description' => isset( $data['description'] ) ? (string) $data['description'] : '',
			);
		}
		return $clean;
	}

	/**
	 * Count built-in recipes without running the unwrap pass.
	 *
	 * @return int
	 */
	public function count_builtins() {
		return count( $this->get_builtins() );
	}

	/**
	 * Delete the recipe cache transient.
	 */
	public function flush_cache() {
		delete_transient( self::TRANSIENT_NAME );
	}

	/**
	 * Resolve the installed ACSS version for cache salting.
	 *
	 * @return string
	 */
	private function get_acss_version() {
		if ( defined( 'ACSS_PLUGIN_FILE' ) && file_exists( ACSS_PLUGIN_FILE ) ) {
			$data = get_file_data( ACSS_PLUGIN_FILE, array( 'Version' => 'Version' ) );
			if ( ! empty( $data['Version'] ) ) {
				return $data['Version'];
			}
		}
		return 'unknown';
	}

	// -------------------------------------------------------------------------
	// The %root% unwrap algorithm.
	// -------------------------------------------------------------------------

	/**
	 * Unwrap the %root% token from a recipe's CSS.
	 *
	 * Two tiers:
	 *  - Tier 4 (single plain wrapper): strip the `%root% {` opener and its
	 *    matching `}`, then dedent the inner block one level.
	 *  - Tier 5 (any other arrangement): replace every real `%root%` with `&`,
	 *    yielding valid CSS-nesting selectors.
	 *
	 * Comments are masked first so `%root%` inside a comment is neither counted
	 * nor replaced, and braces inside comments do not affect brace matching.
	 *
	 * @param string $css Raw recipe CSS from get_all_recipes().
	 * @return string
	 */
	public function unwrap_root( $css ) {
		if ( ! is_string( $css ) || false === strpos( $css, '%root%' ) ) {
			return (string) $css;
		}

		list( $masked, $placeholders ) = $this->mask_comments( $css );
		$count = substr_count( $masked, '%root%' );

		if ( 0 === $count ) {
			return $css;
		}

		// Tier 4: exactly one real %root%, a plain top-level wrapper, and its
		// close brace is the last brace in the document.
		if ( 1 === $count && preg_match( '/^[ \t]*%root%[ \t]*\{/m', $masked, $m, PREG_OFFSET_CAPTURE ) ) {
			$open_start = $m[0][1];
			$brace_pos  = strpos( $masked, '{', $open_start );
			$close_pos  = $this->find_matching_brace( $masked, $brace_pos );
			if ( false !== $close_pos && ! $this->has_brace_after( $masked, $close_pos ) ) {
				$stripped = $this->strip_single_wrapper( $masked, $open_start, $brace_pos, $close_pos );
				if ( null !== $stripped ) {
					return $this->restore_comments( $stripped, $placeholders );
				}
			}
		}

		// Tier 5: replace every real %root% with &.
		$replaced = str_replace( '%root%', '&', $masked );
		return $this->restore_comments( $replaced, $placeholders );
	}

	/**
	 * Strip a single top-level `%root% { ... }` wrapper and dedent the inner block.
	 *
	 * @param string $masked     Comment-masked CSS.
	 * @param int    $open_start  Offset of the opener match start.
	 * @param int    $brace_pos   Offset of the opening `{`.
	 * @param int    $close_pos   Offset of the matching `}`.
	 * @return string|null The rewritten masked CSS, or null if the opener/close
	 *                      are not alone on their lines (caller falls back to tier 5).
	 */
	private function strip_single_wrapper( $masked, $open_start, $brace_pos, $close_pos ) {
		$opener_line_start = $this->line_start( $masked, $open_start );
		$opener_line_end   = $this->line_end( $masked, $brace_pos );

		// The opener must be alone on its line (only whitespace after the `{`).
		$after_brace = substr( $masked, $brace_pos + 1, $opener_line_end - $brace_pos - 1 );
		if ( '' !== trim( $after_brace, " \t\r\n" ) ) {
			return null;
		}

		$close_line_start = $this->line_start( $masked, $close_pos );
		$close_line_end   = $this->line_end( $masked, $close_pos );

		// The close brace must be alone on its line.
		$close_line = substr( $masked, $close_line_start, $close_line_end - $close_line_start );
		if ( '' !== trim( str_replace( '}', '', $close_line ), " \t\r\n" ) ) {
			return null;
		}

		$before = substr( $masked, 0, $opener_line_start );
		$inner  = substr( $masked, $opener_line_end, $close_line_start - $opener_line_end );
		$after  = substr( $masked, $close_line_end );

		return $before . $this->dedent( $inner ) . $after;
	}

	/**
	 * Dedent a block by one level: strip one leading tab, or up to two leading
	 * spaces, from the start of each line.
	 *
	 * @param string $text
	 * @return string
	 */
	private function dedent( $text ) {
		return preg_replace( '/^(?:\t|[ ]{1,2})/m', '', $text );
	}

	/**
	 * Find the position of the brace matching the opening `{` at $open_pos.
	 *
	 * Tracks string context so braces inside CSS strings are ignored.
	 * Comments are already masked, so comment braces cannot interfere.
	 *
	 * @param string $text
	 * @param int    $open_pos Position of the opening `{`.
	 * @return int|false Position of the matching `}`, or false if unbalanced.
	 */
	private function find_matching_brace( $text, $open_pos ) {
		$len   = strlen( $text );
		$depth = 1;
		$i     = $open_pos + 1;
		$in_str = '';
		while ( $i < $len ) {
			$ch = $text[ $i ];
			if ( '' !== $in_str ) {
				if ( '\\' === $ch ) {
					$i += 2;
					continue;
				}
				if ( $ch === $in_str ) {
					$in_str = '';
				}
				++$i;
				continue;
			}
			if ( '"' === $ch || "'" === $ch ) {
				$in_str = $ch;
				++$i;
				continue;
			}
			if ( '{' === $ch ) {
				++$depth;
			} elseif ( '}' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
			++$i;
		}
		return false;
	}

	/**
	 * Whether there is any real `{` or `}` after $pos (outside strings).
	 *
	 * Used to confirm the tier-4 close brace is the last brace in the document.
	 *
	 * @param string $text
	 * @param int    $pos
	 * @return bool
	 */
	private function has_brace_after( $text, $pos ) {
		$len    = strlen( $text );
		$i      = $pos + 1;
		$in_str = '';
		while ( $i < $len ) {
			$ch = $text[ $i ];
			if ( '' !== $in_str ) {
				if ( '\\' === $ch ) {
					$i += 2;
					continue;
				}
				if ( $ch === $in_str ) {
					$in_str = '';
				}
				++$i;
				continue;
			}
			if ( '"' === $ch || "'" === $ch ) {
				$in_str = $ch;
				++$i;
				continue;
			}
			if ( '{' === $ch || '}' === $ch ) {
				return true;
			}
			++$i;
		}
		return false;
	}

	/**
	 * Offset of the start of the line containing $pos.
	 *
	 * @param string $text
	 * @param int    $pos
	 * @return int
	 */
	private function line_start( $text, $pos ) {
		$before = substr( $text, 0, $pos );
		$nl = strrpos( $before, "\n" );
		return false === $nl ? 0 : $nl + 1;
	}

	/**
	 * Offset just past the end of the line containing $pos (includes the newline).
	 *
	 * @param string $text
	 * @param int    $pos
	 * @return int
	 */
	private function line_end( $text, $pos ) {
		$nl = strpos( $text, "\n", $pos );
		return false === $nl ? strlen( $text ) : $nl + 1;
	}

	/**
	 * Mask every CSS comment with a placeholder so its contents are invisible to
	 * %root% counting, replacement, and brace matching.
	 *
	 * @param string $css
	 * @return array{0: string, 1: string[]} Masked text and the list of original comments.
	 */
	private function mask_comments( $css ) {
		$placeholders = array();
		$masked = preg_replace_callback(
			'#/\*.*?\*/#s',
			function ( $m ) use ( &$placeholders ) {
				$i = count( $placeholders );
				$placeholders[] = $m[0];
				return "\x00C" . $i . "\x00";
			},
			$css
		);
		return array( $masked, $placeholders );
	}

	/**
	 * Restore masked comments back into the text.
	 *
	 * @param string   $text
	 * @param string[] $placeholders
	 * @return string
	 */
	private function restore_comments( $text, $placeholders ) {
		return preg_replace_callback(
			'#\x00C(\d+)\x00#',
			function ( $m ) use ( $placeholders ) {
				return isset( $placeholders[ (int) $m[1] ] ) ? $placeholders[ (int) $m[1] ] : '';
			},
			$text
		);
	}
}
