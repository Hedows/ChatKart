<?php
/**
 * Pure helper functions.
 *
 * @package ChatKart
 */

defined( 'ABSPATH' ) || exit;

class ChatKart_Helpers {

	/**
	 * Turn a WooCommerce amount into plain text (no HTML, no entities).
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function price( $amount ) {
		$text = html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' );
		return trim( str_replace( "\xC2\xA0", ' ', $text ) );
	}

	/**
	 * Keep digits only.
	 *
	 * @param string $value Raw phone.
	 * @return string
	 */
	public static function digits( $value ) {
		return preg_replace( '/\D+/', '', (string) $value );
	}

	/**
	 * Normalise a customer phone number to international digits.
	 *
	 * @param string $raw Raw number such as 0313 5340703 or +92 313 5340703.
	 * @return string
	 */
	public static function normalize_phone( $raw ) {
		$raw   = trim( (string) $raw );
		$cc    = self::digits( ChatKart_Settings::get( 'country_code' ) );
		$clean = self::digits( $raw );
		if ( '' === $clean ) {
			return '';
		}
		if ( 0 === strpos( $raw, '+' ) ) {
			return $clean;
		}
		if ( 0 === strpos( $clean, '00' ) ) {
			return substr( $clean, 2 );
		}
		if ( '' !== $cc && 0 === strpos( $clean, '0' ) ) {
			return $cc . ltrim( $clean, '0' );
		}
		return $clean;
	}

	/**
	 * Build a wa.me link.
	 *
	 * @param string $phone Digits only, or empty for the "choose contact" share link.
	 * @param string $text  Message.
	 * @return string
	 */
	public static function wa_url( $phone, $text ) {
		$phone = self::digits( $phone );
		$text  = (string) $text;
		if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > 2500 ) {
			$text = mb_substr( $text, 0, 2500 ); // keep the link within browser URL limits.
		}
		return 'https://wa.me/' . $phone . '?text=' . rawurlencode( $text );
	}

	/**
	 * Replace {placeholders}. Lines that contain placeholders which are all empty are dropped.
	 *
	 * @param string $template Template text.
	 * @param array  $data     Key => value pairs (without braces).
	 * @return string
	 */
	public static function render( $template, array $data ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $template );
		$out   = array();

		foreach ( $lines as $line ) {
			$found = preg_match_all( '/\{([a-z_]+)\}/', $line, $m );
			if ( ! $found ) {
				$out[] = $line;
				continue;
			}
			$has_value = false;
			foreach ( $m[1] as $key ) {
				if ( isset( $data[ $key ] ) && '' !== trim( (string) $data[ $key ] ) ) {
					$has_value = true;
					break;
				}
			}
			if ( ! $has_value ) {
				continue;
			}
			$out[] = preg_replace_callback(
				'/\{([a-z_]+)\}/',
				static function ( $mm ) use ( $data ) {
					return isset( $data[ $mm[1] ] ) ? (string) $data[ $mm[1] ] : '';
				},
				$line
			);
		}

		$text = implode( "\n", $out );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( (string) $text );
	}

	/**
	 * Category IDs (including descendants) from a settings list.
	 *
	 * @param array $ids Term IDs.
	 * @return int[]
	 */
	private static function with_children( $ids ) {
		$all = array();
		foreach ( (array) $ids as $id ) {
			$id    = absint( $id );
			$all[] = $id;
			$kids  = get_term_children( $id, 'product_cat' );
			if ( is_array( $kids ) ) {
				$all = array_merge( $all, array_map( 'absint', $kids ) );
			}
		}
		return array_values( array_unique( $all ) );
	}

	/**
	 * Parse "1, 2,3" to int array.
	 *
	 * @param string $csv CSV of IDs.
	 * @return int[]
	 */
	private static function ids( $csv ) {
		return array_values( array_filter( array_map( 'absint', explode( ',', (string) $csv ) ) ) );
	}

	/**
	 * May the order button be shown / used for this product?
	 *
	 * @param WC_Product|false|null $product Product.
	 * @return bool
	 */
	public static function product_allowed( $product ) {
		static $cache = array();

		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$pid = $product->get_id();
		if ( isset( $cache[ $pid ] ) ) {
			return $cache[ $pid ];
		}

		$ok = true;

		if ( ! ChatKart_Settings::is( 'enabled' ) ) {
			$ok = false;
		} elseif ( ChatKart_Settings::is( 'logged_in_only' ) && ! is_user_logged_in() ) {
			$ok = false;
		} elseif ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) {
			$ok = false;
		} elseif ( ! $product->is_in_stock() ) {
			$ok = false;
		} else {
			$override = (string) $product->get_meta( '_chatkart_override', true );
			if ( 'disable' === $override ) {
				$ok = false;
			} elseif ( 'enable' !== $override ) {
				$parent_id = $pid;
				if ( in_array( $parent_id, self::ids( ChatKart_Settings::get( 'exclude_products' ) ), true ) ) {
					$ok = false;
				} elseif ( self::in_cats( $parent_id, ChatKart_Settings::get( 'exclude_cats' ) ) ) {
					$ok = false;
				} elseif ( 'selected' === ChatKart_Settings::get( 'scope' ) ) {
					$ok = in_array( $parent_id, self::ids( ChatKart_Settings::get( 'include_products' ) ), true )
						|| self::in_cats( $parent_id, ChatKart_Settings::get( 'include_cats' ) );
				}
			}
		}

		$cache[ $pid ] = (bool) apply_filters( 'chatkart_product_allowed', $ok, $product );
		return $cache[ $pid ];
	}

	/**
	 * Is the product in any of the given categories (or their children)?
	 *
	 * @param int   $product_id Product ID.
	 * @param array $cat_ids    Category IDs.
	 * @return bool
	 */
	private static function in_cats( $product_id, $cat_ids ) {
		if ( empty( $cat_ids ) || ! is_array( $cat_ids ) ) {
			return false;
		}
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return false;
		}
		return (bool) array_intersect( array_map( 'absint', $terms ), self::with_children( $cat_ids ) );
	}

	/**
	 * Simple per-IP rate limit. Returns false when the limit is exceeded.
	 *
	 * @param string $bucket  Name.
	 * @param int    $max     Max hits.
	 * @param int    $seconds Window.
	 * @return bool
	 */
	public static function rate_ok( $bucket, $max, $seconds = 600 ) {
		return self::bump( 'chatkart_rl_' . md5( $bucket . '|' . self::client_ip() ), $max, $seconds );
	}

	/**
	 * Site-wide cap (all visitors together). Returns false when the cap is reached.
	 *
	 * @param string $bucket  Name.
	 * @param int    $max     Max hits per window.
	 * @param int    $seconds Window length.
	 * @return bool
	 */
	public static function rate_global( $bucket, $max, $seconds = 3600 ) {
		return self::bump( 'chatkart_rg_' . md5( $bucket ), $max, $seconds );
	}

	/**
	 * Fixed-window counter (the window does NOT slide, so counters always reset).
	 *
	 * @param string $key     Transient key.
	 * @param int    $max     Max hits.
	 * @param int    $seconds Window.
	 * @return bool
	 */
	private static function bump( $key, $max, $seconds ) {
		$now = time();
		$d   = get_transient( $key );
		if ( ! is_array( $d ) || ! isset( $d['n'], $d['t'] ) || $now - (int) $d['t'] >= $seconds ) {
			$d = array( 'n' => 0, 't' => $now );
		}
		if ( (int) $d['n'] >= $max ) {
			return false;
		}
		$d['n'] = (int) $d['n'] + 1;
		set_transient( $key, $d, max( 1, $seconds - ( $now - (int) $d['t'] ) ) );
		return true;
	}

	/**
	 * Visitor IP used for rate limits. Only REMOTE_ADDR is trusted; sites behind a reverse proxy / CDN
	 * can return the real address with the chatkart_client_ip filter.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'chatkart_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	}

	/**
	 * Is this URL on the current site? Returns a clean URL or an empty string.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function own_url( $url ) {
		$url  = esc_url_raw( substr( (string) $url, 0, 255 ) );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return ( $host && strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) ? $url : '';
	}

	/**
	 * WhatsApp number set on a single page/post/product (digits) or ''.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function phone_for_post( $post_id ) {
		$p = self::digits( get_post_meta( (int) $post_id, '_chatkart_phone', true ) );
		return strlen( $p ) >= 8 ? $p : '';
	}

	/**
	 * Inline WhatsApp icon markup.
	 *
	 * @return string
	 */
	public static function icon() {
		return '<svg class="chatkart-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';
	}
}
