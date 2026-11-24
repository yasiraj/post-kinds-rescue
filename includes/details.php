<?php
/**
 * Post Kinds Rescue, part 2: carry response details over to the new plugin.
 *
 * The classic Post Kinds plugin kept what a post is "about" (the page you
 * liked, the song you heard, the book you read, the venue you checked in at,
 * your RSVP) in `mf2_<property>` post meta, usually as an h-cite array or a
 * plain URL. "Post Kinds for IndieWeb" never reads that. It reads its own
 * `_pkiw_*` post meta. This file copies the details across, as meta only:
 *
 *  - Post content is never touched, and save_post is never fired.
 *  - A `_pkiw_*` key that already holds a value is never overwritten.
 *  - Every key written is listed in `_pk_rescue_details_written`, so undo
 *    removes exactly those keys and nothing else.
 *
 * Load it from the main plugin file, after the kind rescue functions:
 *
 *     require_once __DIR__ . '/includes/details.php';
 *
 * @package PostKindsRescue
 */

namespace PostKindsRescue\Details;

defined( 'ABSPATH' ) || exit;

const META_PREFIX  = '_pkiw_';
const WRITTEN_META = '_pk_rescue_details_written';

/**
 * Kinds this module fills, and the mf2 properties each reads, first match wins.
 *
 * @return array<string, string[]> Kind slug => mf2 property names (without `mf2_`).
 */
function sources(): array {
	return array(
		'like'        => array( 'like-of' ),
		'bookmark'    => array( 'bookmark-of' ),
		'repost'      => array( 'repost-of' ),
		'reply'       => array( 'in-reply-to' ),
		'favorite'    => array( 'favorite-of' ),
		'rsvp'        => array( 'in-reply-to' ), // The event. The answer itself is mf2_rsvp.
		'listen'      => array( 'listen-of' ),
		'jam'         => array( 'jam-of' ),
		'watch'       => array( 'watch-of' ),
		'read'        => array( 'read-of' ),
		'play'        => array( 'play-of' ),
		'checkin'     => array( 'checkin' ),
		'eat'         => array( 'ate', 'pk-ate' ),
		'drink'       => array( 'drank', 'pk-drank' ),
		'wish'        => array( 'wish-of' ),
		'acquisition' => array( 'acquired-of' ),
		'review'      => array( 'review-of' ),
	);
}

/**
 * Kinds the new plugin has no response-detail fields for, or that live in the
 * post body or attachments. Listed for the preview and the docs, never filled.
 *
 * @return string[]
 */
function skipped_kinds(): array {
	return array( 'note', 'article', 'photo', 'video', 'audio', 'quote', 'follow', 'tag', 'event', 'recipe', 'question', 'sleep', 'exercise', 'trip', 'itinerary', 'weather', 'mood', 'craft' );
}

/* -------------------------------------------------------------------------
 * Reading the classic data
 * ---------------------------------------------------------------------- */

/**
 * A scalar out of an mf2 value: a string, a list of strings, a `value` array.
 *
 * @param mixed $v Raw value.
 * @return string Trimmed text, or ''.
 */
function scalar_of( $v ): string {
	if ( is_string( $v ) || is_int( $v ) || is_float( $v ) ) {
		return trim( (string) $v );
	}
	if ( is_array( $v ) ) {
		foreach ( array( 'value', 'html' ) as $k ) {
			if ( isset( $v[ $k ] ) && is_scalar( $v[ $k ] ) && '' !== trim( (string) $v[ $k ] ) ) {
				return trim( (string) $v[ $k ] );
			}
		}
		foreach ( $v as $item ) {
			$s = scalar_of( $item );
			if ( '' !== $s ) {
				return $s;
			}
		}
	}
	return '';
}

/**
 * The property bag of an h-* item: its `properties` key, or the item itself.
 *
 * @param array $item An mf2 item or a flat array.
 * @return array
 */
function props_of( array $item ): array {
	return ( isset( $item['properties'] ) && is_array( $item['properties'] ) ) ? $item['properties'] : $item;
}

/**
 * The first usable item in a stored mf2 value.
 *
 * @param mixed $value Unserialized meta value.
 * @return string|array|null A string, an item array, or null.
 */
function first_item( $value ) {
	if ( is_string( $value ) ) {
		return '' === trim( $value ) ? null : trim( $value );
	}
	if ( ! is_array( $value ) ) {
		return null;
	}
	$keys = array( 'type', 'properties', 'url', 'name', 'uid', 'value' );
	if ( array_intersect( $keys, array_keys( $value ) ) ) {
		return $value; // One item.
	}
	foreach ( $value as $entry ) { // A list: first non-blank entry.
		$found = first_item( $entry );
		if ( null !== $found ) {
			return $found;
		}
	}
	return null;
}

/**
 * Reduces one mf2 item to a flat card of plain strings.
 *
 * @param string|array|null $item From first_item().
 * @return array<string, string> Keys: name url author author_url photo published summary publication price street locality region country latitude longitude isbn.
 */
function card_of( $item ): array {
	$card = array();
	if ( is_string( $item ) ) {
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:\S*$#i', $item ) && ! preg_match( '#^https?://#i', $item ) ) {
			return $card; // javascript:, data:, mailto: and friends are not a title or a web address.
		}
		if ( preg_match( '#^https?://#i', $item ) ) {
			$card['url'] = $item;
		} else {
			$card['name'] = $item;
		}
		return $card;
	}
	if ( ! is_array( $item ) ) {
		return $card;
	}
	$p   = props_of( $item );
	$get = static function ( array $names ) use ( $p ): string {
		foreach ( $names as $n ) {
			if ( isset( $p[ $n ] ) ) {
				$s = scalar_of( $p[ $n ] );
				if ( '' !== $s ) {
					return $s;
				}
			}
		}
		return '';
	};

	$map = array(
		'name'        => array( 'name' ),
		'url'         => array( 'url' ),
		'photo'       => array( 'photo', 'featured', 'image', 'logo' ),
		'published'   => array( 'published', 'release-date', 'date' ),
		'summary'     => array( 'summary', 'content' ),
		'publication' => array( 'publication', 'album', 'container', 'publisher' ),
		'price'       => array( 'price' ),
		'street'      => array( 'street-address', 'address' ),
		'locality'    => array( 'locality' ),
		'region'      => array( 'region' ),
		'country'     => array( 'country-name', 'country' ),
		'latitude'    => array( 'latitude' ),
		'longitude'   => array( 'longitude' ),
		'isbn'        => array( 'isbn' ),
	);
	foreach ( $map as $field => $names ) {
		$card[ $field ] = $get( $names );
	}
	if ( '' === $card['url'] ) {
		$uid = $get( array( 'uid' ) );
		if ( preg_match( '#^https?://#i', $uid ) ) {
			$card['url'] = $uid;
		}
	}

	// The author is a string, an h-card, or a list of either.
	$card['author']     = '';
	$card['author_url'] = '';
	if ( isset( $p['author'] ) ) {
		$author = first_item( $p['author'] );
		if ( is_string( $author ) ) {
			if ( preg_match( '#^https?://#i', $author ) ) {
				$card['author_url'] = $author;
			} else {
				$card['author'] = $author;
			}
		} elseif ( is_array( $author ) ) {
			$ap                 = props_of( $author );
			$card['author']     = isset( $ap['name'] ) ? scalar_of( $ap['name'] ) : '';
			$card['author_url'] = isset( $ap['url'] ) ? scalar_of( $ap['url'] ) : '';
		}
	}
	return $card;
}

/**
 * The card for the first of several mf2 properties a post has data in.
 *
 * Fills gaps from the old `mf2_cite` when it describes the same URL.
 *
 * @param int      $post_id Post ID.
 * @param string[] $names   mf2 property names, in order.
 * @return array<string, string>
 */
function post_card( int $post_id, array $names ): array {
	$card = array();
	foreach ( $names as $name ) {
		foreach ( (array) get_post_meta( $post_id, 'mf2_' . $name, false ) as $raw ) {
			$card = card_of( first_item( maybe_unserialize( $raw ) ) );
			if ( array_filter( $card ) ) {
				break 2;
			}
		}
	}
	$cite = array();
	foreach ( (array) get_post_meta( $post_id, 'mf2_cite', false ) as $raw ) {
		$cite = card_of( first_item( maybe_unserialize( $raw ) ) );
		if ( array_filter( $cite ) ) {
			break;
		}
	}
	if ( $cite && ( empty( $card['url'] ) || empty( $cite['url'] ) || $card['url'] === $cite['url'] ) ) {
		foreach ( $cite as $k => $v ) {
			if ( '' !== $v && empty( $card[ $k ] ) ) {
				$card[ $k ] = $v;
			}
		}
	}
	return $card;
}

/**
 * Everything the classic plugin stored for a post of a kind, as one flat card.
 *
 * Adds the single-value properties that live outside the h-cite: the RSVP
 * answer, the read status, and WordPress's own geo meta for check-ins.
 *
 * @param int    $post_id Post ID.
 * @param string $kind    Kind slug from sources().
 * @return array<string, string>
 */
function facts( int $post_id, string $kind ): array {
	$src = sources();
	$c   = isset( $src[ $kind ] ) ? post_card( $post_id, $src[ $kind ] ) : array();
	if ( isset( $c['name'], $c['url'] ) && $c['name'] === $c['url'] ) {
		$c['name'] = '';
	}
	if ( 'rsvp' === $kind ) {
		$answer = strtolower( scalar_of( maybe_unserialize( get_post_meta( $post_id, 'mf2_rsvp', true ) ) ) );
		if ( in_array( $answer, array( 'yes', 'no', 'maybe', 'interested', 'remote' ), true ) ) {
			$c['rsvp'] = $answer;
		}
	}
	if ( 'read' === $kind ) {
		// Classic Post Kinds: mf2_read-status is to-read, reading or finished.
		$status = strtolower( scalar_of( maybe_unserialize( get_post_meta( $post_id, 'mf2_read-status', true ) ) ) );
		if ( in_array( $status, array( 'finished', 'to-read' ), true ) ) { // "reading" is the new plugin's default.
			$c['read_status'] = $status;
		}
	}
	if ( 'checkin' === $kind && ( empty( $c['latitude'] ) || empty( $c['longitude'] ) ) ) {
		$lat = scalar_of( get_post_meta( $post_id, 'geo_latitude', true ) );
		$lng = scalar_of( get_post_meta( $post_id, 'geo_longitude', true ) );
		if ( '' !== $lat && '' !== $lng ) {
			$c['latitude']  = $lat;
			$c['longitude'] = $lng;
		}
	}
	return $c;
}

/* -------------------------------------------------------------------------
 * Mapping to _pkiw_* meta
 * ---------------------------------------------------------------------- */

/**
 * How to clean a value for a `_pkiw_` suffix.
 *
 * @param string $suffix Key without the prefix.
 * @return string url|textarea|lat|lng|text
 */
function field_type( string $suffix ): string {
	if ( 'geo_latitude' === $suffix ) {
		return 'lat';
	}
	if ( 'geo_longitude' === $suffix ) {
		return 'lng';
	}
	if ( 'cite_summary' === $suffix ) {
		return 'textarea';
	}
	if ( preg_match( '/(_url|_cover|_poster|_photo)$/', $suffix ) ) {
		return 'url';
	}
	return 'text';
}

/**
 * Cleans a value the way the new plugin's own field does. '' means: drop it.
 *
 * @param string $suffix Key without the prefix.
 * @param mixed  $value  Raw value.
 * @return string
 */
function clean( string $suffix, $value ): string {
	$value = is_scalar( $value ) ? trim( (string) $value ) : '';
	if ( '' === $value ) {
		return '';
	}
	switch ( field_type( $suffix ) ) {
		case 'url':
			return esc_url_raw( $value, array( 'http', 'https' ) );
		case 'textarea':
			return sanitize_textarea_field( $value );
		case 'lat':
		case 'lng':
			if ( ! is_numeric( $value ) ) {
				return '';
			}
			$limit = 'lat' === field_type( $suffix ) ? 90 : 180;
			$n     = (float) $value;
			return ( abs( $n ) <= $limit && 0.0 !== $n ) ? (string) $n : '';
		default:
			return sanitize_text_field( $value );
	}
}

/**
 * The `_pkiw_` suffix => value pairs for one post of a kind.
 *
 * @param int    $post_id Post ID.
 * @param string $kind    Kind slug from sources().
 * @return array<string, string> Cleaned, non-empty values.
 */
function map_post( int $post_id, string $kind ): array {
	$src = sources();
	if ( ! isset( $src[ $kind ] ) ) {
		return array();
	}
	$c = facts( $post_id, $kind );
	$g = static function ( string $k ) use ( $c ): string {
		return isset( $c[ $k ] ) ? $c[ $k ] : '';
	};

	$raw = array();
	switch ( $kind ) {
		case 'like':
		case 'bookmark':
		case 'repost':
		case 'reply':
			$raw = array(
				'cite_url'        => $g( 'url' ),
				'cite_name'       => $g( 'name' ),
				'cite_author'     => $g( 'author' ),
				'cite_author_url' => $g( 'author_url' ),
				'cite_photo'      => $g( 'photo' ),
				'cite_summary'    => $g( 'summary' ),
				'cite_published'  => $g( 'published' ),
			);
			break;
		case 'favorite':
			$raw = array(
				'favorite_url'    => $g( 'url' ),
				'favorite_name'   => $g( 'name' ),
				'cite_url'        => $g( 'url' ),
				'cite_name'       => $g( 'name' ),
				'cite_author'     => $g( 'author' ),
				'cite_author_url' => $g( 'author_url' ),
				'cite_photo'      => $g( 'photo' ),
			);
			break;
		case 'rsvp':
			$raw = array(
				'rsvp_status' => $g( 'rsvp' ),
				'cite_url'    => $g( 'url' ),
				'cite_name'   => $g( 'name' ),
			);
			break;
		case 'listen':
			$raw = array(
				'listen_track'  => $g( 'name' ),
				'listen_artist' => $g( 'author' ),
				'listen_album'  => $g( 'publication' ),
				'listen_cover'  => $g( 'photo' ),
				'listen_url'    => $g( 'url' ),
			);
			break;
		case 'jam':
			$raw = array(
				'jam_track'  => $g( 'name' ),
				'jam_artist' => $g( 'author' ),
				'jam_album'  => $g( 'publication' ),
				'jam_cover'  => $g( 'photo' ),
				'jam_url'    => $g( 'url' ),
			);
			break;
		case 'watch':
			$year = '';
			if ( preg_match( '/^(\d{4})/', $g( 'published' ), $m ) ) {
				$year = $m[1];
			}
			$has = '' !== $g( 'name' ) || '' !== $g( 'url' );
			$raw = array(
				'watch_title'  => $g( 'name' ),
				'watch_url'    => $g( 'url' ),
				'watch_poster' => $g( 'photo' ),
				'watch_year'   => $year,
				// A classic "watch" post records something already watched; the new plugin's default is "to-watch".
				'watch_status' => $has ? 'watched' : '',
			);
			break;
		case 'read':
			$isbn = preg_replace( '/[^0-9Xx]/', '', $g( 'isbn' ) );
			$raw  = array(
				'read_title'     => $g( 'name' ),
				'read_author'    => $g( 'author' ),
				'read_url'       => $g( 'url' ),
				'read_cover'     => $g( 'photo' ),
				'read_publisher' => $g( 'publication' ),
				'read_isbn'      => in_array( strlen( (string) $isbn ), array( 10, 13 ), true ) ? (string) $isbn : '',
				'read_status'    => $g( 'read_status' ),
			);
			break;
		case 'play':
			$raw = array(
				'play_title'    => $g( 'name' ),
				'play_game_url' => $g( 'url' ),
				'play_cover'    => $g( 'photo' ),
			);
			break;
		case 'checkin':
			$lat = $g( 'latitude' );
			$lng = $g( 'longitude' );
			$raw = array(
				'checkin_name'     => $g( 'name' ),
				'checkin_url'      => $g( 'url' ),
				'checkin_address'  => $g( 'street' ),
				'checkin_locality' => $g( 'locality' ),
				'checkin_region'   => $g( 'region' ),
				'checkin_country'  => $g( 'country' ),
				'geo_latitude'     => $lat,
				'geo_longitude'    => $lng,
			);
			break;
		case 'eat':
			$raw = array(
				'eat_name'  => $g( 'name' ),
				'eat_photo' => $g( 'photo' ),
			);
			break;
		case 'drink':
			$raw = array(
				'drink_name'  => $g( 'name' ),
				'drink_photo' => $g( 'photo' ),
			);
			break;
		case 'wish':
			$raw = array(
				'wish_name'  => $g( 'name' ),
				'wish_url'   => $g( 'url' ),
				'wish_photo' => $g( 'photo' ),
			);
			break;
		case 'acquisition':
			$raw = array(
				'acquisition_name'  => $g( 'name' ),
				'acquisition_url'   => $g( 'url' ),
				'acquisition_photo' => $g( 'photo' ),
				'acquisition_price' => $g( 'price' ),
			);
			break;
		case 'review':
			$raw = array(
				'review_item_name' => $g( 'name' ),
				'review_item_url'  => $g( 'url' ),
			);
			break;
	}

	$out = array();
	foreach ( $raw as $suffix => $value ) {
		$value = clean( (string) $suffix, $value );
		if ( '' !== $value ) {
			$out[ $suffix ] = $value;
		}
	}
	return $out;
}

/**
 * Whether a `_pkiw_` key holds nothing worth protecting.
 *
 * metadata_exists() first: the new plugin registers defaults (for example
 * watch_status "to-watch"), and get_post_meta() would return those for a key
 * that was never stored.
 *
 * @param int    $post_id Post ID.
 * @param string $suffix  Key without the prefix.
 * @return bool True when absent or stored as an empty string.
 */
function is_free( int $post_id, string $suffix ): bool {
	$key = META_PREFIX . $suffix;
	if ( ! metadata_exists( 'post', $post_id, $key ) ) {
		return true;
	}
	$stored = get_metadata_raw( 'post', $post_id, $key, true );
	if ( is_scalar( $stored ) ) {
		$stored = trim( (string) $stored );
		return '' === $stored || ( in_array( field_type( $suffix ), array( 'lat', 'lng' ), true ) && is_numeric( $stored ) && 0.0 === (float) $stored );
	}
	return null === $stored || array() === $stored;
}

/* -------------------------------------------------------------------------
 * Plan, apply, undo
 * ---------------------------------------------------------------------- */

/**
 * The kind to fill a post as: its kind term, else what its mf2 data points to.
 *
 * @param int $post_id Post ID.
 * @return string Kind slug from sources(), or ''.
 */
function post_kind( int $post_id ): string {
	$taxonomy = defined( 'PostKindsRescue\\TAXONOMY' ) ? constant( 'PostKindsRescue\\TAXONOMY' ) : 'kind';
	$terms    = get_the_terms( $post_id, $taxonomy );
	$known    = sources();
	if ( $terms && ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			if ( isset( $known[ $term->slug ] ) ) {
				return $term->slug;
			}
		}
		return ''; // A kind this module does not fill (note, article ...): leave it.
	}
	if ( function_exists( 'PostKindsRescue\\detect_kind' ) ) {
		$found = \PostKindsRescue\detect_kind( $post_id );
		if ( isset( $known[ $found['kind'] ] ) ) {
			return $found['kind'];
		}
	}
	return '';
}

/**
 * Builds the list of meta to write, without saving anything.
 *
 * @return array{items: array[], checked: int, by_kind: array<string,int>}
 *         Each item: id, kind, writes (suffix => value), kept (suffixes already filled).
 */
function build_details_plan(): array {
	global $wpdb;
	$plan    = array(
		'items'   => array(),
		'checked' => 0,
		'by_kind' => array(),
	);
	$like    = $wpdb->esc_like( 'mf2_' ) . '%';
	$last_id = 0;
	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Keyset scan, run on demand.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = 'post' AND p.post_status NOT IN ('trash','auto-draft','inherit') AND m.meta_key LIKE %s AND p.ID > %d ORDER BY p.ID ASC LIMIT 500", $like, $last_id ) ) );
		if ( ! $ids ) {
			break;
		}
		update_meta_cache( 'post', $ids );
		update_object_term_cache( $ids, 'post' );
		foreach ( $ids as $id ) {
			++$plan['checked'];
			$kind = post_kind( $id );
			if ( '' === $kind ) {
				continue;
			}
			$writes = array();
			$kept   = array();
			foreach ( map_post( $id, $kind ) as $suffix => $value ) {
				if ( is_free( $id, $suffix ) ) {
					$writes[ $suffix ] = $value;
				} else {
					$kept[] = $suffix;
				}
			}
			if ( $writes ) {
				$plan['items'][]          = array(
					'id'     => $id,
					'kind'   => $kind,
					'writes' => $writes,
					'kept'   => $kept,
				);
				$plan['by_kind'][ $kind ] = ( isset( $plan['by_kind'][ $kind ] ) ? $plan['by_kind'][ $kind ] : 0 ) + 1;
			}
		}
		$last_id = (int) end( $ids );
	} while ( count( $ids ) === 500 );
	return $plan;
}

/**
 * Writes the planned meta. Safe to run again, and to stop and resume.
 *
 * Re-checks every key as it writes, so a value added since the plan was built
 * is never overwritten.
 *
 * @param array $plan     From build_details_plan().
 * @param float $deadline Unix time to stop by; 0 for no limit.
 * @return int Number of posts that received at least one key.
 */
function apply_details( array $plan, float $deadline = 0 ): int {
	$done = 0;
	foreach ( isset( $plan['items'] ) ? (array) $plan['items'] : array() as $item ) {
		if ( $deadline && microtime( true ) > $deadline ) {
			break;
		}
		$id = (int) $item['id'];
		if ( $id < 1 || empty( $item['writes'] ) ) {
			continue;
		}
		$written = written_map( $id );
		$wrote   = false;
		foreach ( (array) $item['writes'] as $suffix => $value ) {
			$suffix = (string) $suffix;
			$value  = clean( $suffix, $value ); // Never trust a plan blindly.
			if ( '' === $value || ! is_free( $id, $suffix ) ) {
				continue;
			}
			update_post_meta( $id, META_PREFIX . $suffix, $value );
			// Record what is stored, not what was asked for: the new plugin's own sanitizers may reshape a value.
			$stored = get_metadata_raw( 'post', $id, META_PREFIX . $suffix, true );
			$written[ $suffix ] = is_scalar( $stored ) ? (string) $stored : $value;
			$wrote = true;
		}
		if ( $wrote ) {
			update_post_meta( $id, WRITTEN_META, $written );
			++$done;
		}
	}
	return $done;
}

/**
 * The meta this module wrote on a post: suffix => the value it stored.
 *
 * @param int $post_id Post ID.
 * @return array<string, string>
 */
function written_map( int $post_id ): array {
	$raw = get_post_meta( $post_id, WRITTEN_META, true );
	$out = array();
	foreach ( is_array( $raw ) ? $raw : array() as $k => $v ) {
		if ( is_int( $k ) && is_string( $v ) ) { // An old record: a bare list of keys. Treat the current value as ours.
			$cur         = get_metadata_raw( 'post', $post_id, META_PREFIX . $v, true );
			$out[ $v ]   = is_scalar( $cur ) ? (string) $cur : '';
		} elseif ( is_string( $k ) && is_scalar( $v ) ) {
			$out[ $k ] = (string) $v;
		}
	}
	return $out;
}

/**
 * IDs of posts this module has written to.
 *
 * @return int[]
 */
function written_ids(): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id", WRITTEN_META ) ) );
}

/**
 * Deletes exactly the keys this module wrote, then its own record.
 *
 * @param float $deadline Unix time to stop by; 0 for no limit.
 * @return int Number of posts restored.
 */
function undo_details( float $deadline = 0 ): int {
	$done = 0;
	foreach ( written_ids() as $id ) {
		if ( $deadline && microtime( true ) > $deadline ) {
			break;
		}
		foreach ( written_map( $id ) as $suffix => $value ) {
			// Only what is still exactly what we wrote: a value the person edited since is theirs.
			$now = metadata_exists( 'post', $id, META_PREFIX . $suffix ) ? get_metadata_raw( 'post', $id, META_PREFIX . $suffix, true ) : null;
			if ( is_scalar( $now ) && (string) $now === (string) $value ) {
				delete_post_meta( $id, META_PREFIX . $suffix );
			}
		}
		delete_post_meta( $id, WRITTEN_META );
		++$done;
	}
	return $done;
}


/* -------------------------------------------------------------------------
 * Card blocks
 *
 * Meta alone does not print a visible card on a single post, so the card
 * block is prepended to post_content. Fifteen of the sixteen card blocks are
 * dynamic (save() returns null, render.php prints them), so a self-closing
 * comment is valid. favorite, wish and acquisition are STATIC: save() writes
 * HTML, and a self-closing comment is "invalid content" in the editor. For
 * those the exact save() markup is written (kept in step with
 * src/blocks/{favorite,wish,acquisition}-card/save.js; test/details/validate
 * checks it against the real save functions).
 * ---------------------------------------------------------------------- */

const ORIGINAL_META = '_pk_rescue_original_content';
const NEW_MD5_META  = '_pk_rescue_new_content_md5';

/**
 * Card block per kind: whether it is static, and the attribute that must be non-empty for a card to be worth adding.
 *
 * @return array<string, array{static: bool, needs: string[]}>
 */
function card_blocks(): array {
	return array(
		'like'        => array( 'static' => false, 'needs' => array( 'title', 'url' ) ),
		'reply'       => array( 'static' => false, 'needs' => array( 'title', 'url' ) ),
		'repost'      => array( 'static' => false, 'needs' => array( 'title', 'url' ) ),
		'bookmark'    => array( 'static' => false, 'needs' => array( 'title', 'url' ) ),
		'favorite'    => array( 'static' => true, 'needs' => array( 'title' ) ),
		'listen'      => array( 'static' => false, 'needs' => array( 'trackTitle', 'listenUrl' ) ),
		'watch'       => array( 'static' => false, 'needs' => array( 'mediaTitle' ) ),
		'read'        => array( 'static' => false, 'needs' => array( 'bookTitle' ) ),
		'checkin'     => array( 'static' => false, 'needs' => array( 'venueName' ) ),
		'rsvp'        => array( 'static' => false, 'needs' => array( 'eventName', 'eventUrl' ) ),
		'play'        => array( 'static' => false, 'needs' => array( 'title' ) ),
		'eat'         => array( 'static' => false, 'needs' => array( 'name' ) ),
		'drink'       => array( 'static' => false, 'needs' => array( 'name' ) ),
		'jam'         => array( 'static' => false, 'needs' => array( 'title' ) ),
		'wish'        => array( 'static' => true, 'needs' => array( 'title' ) ),
		'acquisition' => array( 'static' => true, 'needs' => array( 'title' ) ),
	);
}

/**
 * Kinds with a card block in the plugin that this module does not fill (no classic data to map).
 *
 * @return string[]
 */
function card_kinds_not_filled(): array {
	return array( 'event', 'mood' );
}

/**
 * The card block attributes for a post, in block.json order, empty ones left out.
 *
 * @param int    $post_id Post ID.
 * @param string $kind    Kind slug from card_blocks().
 * @return array<string, mixed>
 */
function card_attrs( int $post_id, string $kind ): array {
	$c = facts( $post_id, $kind );
	$g = static function ( string $k ) use ( $c ): string {
		return isset( $c[ $k ] ) ? $c[ $k ] : '';
	};
	$t = static function ( $v ): string { // Text.
		return is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
	};
	$u = static function ( $v ): string { // Web URL.
		return is_scalar( $v ) ? esc_url_raw( trim( (string) $v ), array( 'http', 'https' ) ) : '';
	};
	$title = $t( $g( 'name' ) );
	$url   = $u( $g( 'url' ) );

	switch ( $kind ) {
		case 'like':
		case 'reply':
		case 'repost':
		case 'bookmark':
		case 'favorite':
			$a = array(
				'title'       => '' !== $title ? $title : ( 'favorite' === $kind ? $url : '' ),
				'url'         => $url,
				'description' => sanitize_textarea_field( $g( 'summary' ) ),
				'image'       => $u( $g( 'photo' ) ),
				'author'      => $t( $g( 'author' ) ),
			);
			break;
		case 'listen':
			$a = array(
				'trackTitle' => $title,
				'artistName' => $t( $g( 'author' ) ),
				'albumTitle' => $t( $g( 'publication' ) ),
				'coverImage' => $u( $g( 'photo' ) ),
				'listenUrl'  => $url,
			);
			break;
		case 'watch':
			$year = preg_match( '/^(\d{4})/', $g( 'published' ), $m ) ? (int) $m[1] : 0;
			$a    = array(
				'mediaTitle'  => $title,
				'releaseYear' => $year,
				'posterImage' => $u( $g( 'photo' ) ),
				'watchUrl'    => $url,
			);
			break;
		case 'read':
			$isbn = preg_replace( '/[^0-9Xx]/', '', $g( 'isbn' ) );
			$a    = array(
				'bookTitle'  => $title,
				'authorName' => $t( $g( 'author' ) ),
				'isbn'       => in_array( strlen( (string) $isbn ), array( 10, 13 ), true ) ? (string) $isbn : '',
				'publisher'  => $t( $g( 'publication' ) ),
				'coverImage' => $u( $g( 'photo' ) ),
				'bookUrl'    => $url,
				'readStatus' => in_array( $g( 'read_status' ), array( 'finished', 'to-read' ), true ) ? $g( 'read_status' ) : '',
			);
			break;
		case 'checkin':
			$lat = clean( 'geo_latitude', $g( 'latitude' ) );
			$lng = clean( 'geo_longitude', $g( 'longitude' ) );
			$a   = array(
				'venueName' => $title,
				'address'   => $t( $g( 'street' ) ),
				'locality'  => $t( $g( 'locality' ) ),
				'region'    => $t( $g( 'region' ) ),
				'country'   => $t( $g( 'country' ) ),
				'latitude'  => ( '' !== $lat && '' !== $lng ) ? (float) $lat : '',
				'longitude' => ( '' !== $lat && '' !== $lng ) ? (float) $lng : '',
				'venueUrl'  => $url,
			);
			break;
		case 'rsvp':
			$a = array(
				'eventName' => $title,
				'eventUrl'  => $url,
				'rsvpStatus' => 'yes' === $g( 'rsvp' ) ? '' : $g( 'rsvp' ), // "yes" is the block's default.
			);
			break;
		case 'play':
			$a = array(
				'title'   => $title,
				'cover'   => $u( $g( 'photo' ) ),
				'gameUrl' => $url,
			);
			break;
		case 'eat':
		case 'drink':
			$a = array(
				'name'  => $title,
				'photo' => $u( $g( 'photo' ) ),
			);
			break;
		case 'jam':
			$a = array(
				'title'  => $title,
				'artist' => $t( $g( 'author' ) ),
				'album'  => $t( $g( 'publication' ) ),
				'cover'  => $u( $g( 'photo' ) ),
				'url'    => $url,
			);
			break;
		case 'wish':
			$a = array(
				'title' => '' !== $title ? $title : $url,
				'url'   => $url,
				'image' => $u( $g( 'photo' ) ),
				'price' => $t( $g( 'price' ) ),
			);
			break;
		case 'acquisition':
			// The cost is private by design in this block (showCostPublicly is off), so it is not put in the content.
			$a = array(
				'title'           => '' !== $title ? $title : $url,
				'acquisitionType' => 'other', // The classic plugin does not say how it was acquired; the block's default is "purchase".
				'whereUrl'        => $url,
				'photo'           => $u( $g( 'photo' ) ),
			);
			break;
		default:
			return array();
	}
	return array_filter(
		$a,
		static function ( $v ) {
			return '' !== $v && 0 !== $v && null !== $v;
		}
	);
}

/**
 * The static save() markup of favorite, wish and acquisition cards, byte for byte.
 *
 * @param string $kind  favorite|wish|acquisition.
 * @param array  $attrs From card_attrs().
 * @return string Inner HTML, or '' for another kind.
 */
function static_html( string $kind, array $attrs ): string {
	$a = static function ( string $k ) use ( $attrs ): string {
		return isset( $attrs[ $k ] ) ? (string) $attrs[ $k ] : '';
	};
	// Escape the way @wordpress/escape-html does when the editor serializes save(): that is what the saved bytes must equal.
	$amp      = static function ( string $v ): string {
		return preg_replace( '/&(?!([a-z0-9]+|#[0-9]+|#x[a-f0-9]+);)/i', '&amp;', $v );
	};
	$esc_html = static function ( string $v ) use ( $amp ): string {
		return str_replace( '<', '&lt;', $amp( $v ) );
	};
	$esc_attr = static function ( string $v ) use ( $amp ): string {
		return str_replace( array( '"', '>' ), array( '&quot;', '&gt;' ), $amp( $v ) );
	};
	$title = $a( 'title' );
	$media = static function ( string $src, string $alt ) use ( $esc_attr ): string {
		return '' === $src ? '' : '<div class="post-kinds-card__media"><img src="' . $esc_attr( $src ) . '" alt="' . $esc_attr( $alt ) . '" class="post-kinds-card__image u-photo" loading="lazy"/></div>';
	};
	$link = static function ( string $href, string $class, string $text ) use ( $esc_attr, $esc_html ): string {
		return '' === $href ? $esc_html( $text ) : '<a href="' . $esc_attr( $href ) . '" class="' . $class . '" target="_blank" rel="noopener noreferrer">' . $esc_html( $text ) . '</a>';
	};
	$h3 = static function ( string $inner ): string {
		return '' === $inner ? '' : '<h3 class="post-kinds-card__title p-name">' . $inner . '</h3>';
	};

	if ( 'favorite' === $kind ) {
		$body = $media( $a( 'image' ), $a( 'imageAlt' ) ?: $title )
			. '<div class="post-kinds-card__content"><span class="post-kinds-card__badge">★ Favorited</span>'
			. ( '' !== $title ? $h3( $link( $a( 'url' ), 'u-url u-favorite-of', $title ) ) : '' )
			. ( '' !== $a( 'author' ) ? '<p class="post-kinds-card__subtitle p-author h-card"><span class="p-name">' . $esc_html( $a( 'author' ) ) . '</span></p>' : '' )
			. ( '' !== $a( 'description' ) ? '<p class="post-kinds-card__notes p-content">' . $esc_html( $a( 'description' ) ) . '</p>' : '' )
			. '</div>';
		return '<div class="wp-block-post-kinds-indieweb-favorite-card favorite-card layout-horizontal"><div class="post-kinds-card h-cite">' . $body . '</div></div>';
	}
	if ( 'wish' === $kind ) {
		$body = $media( $a( 'image' ), $a( 'imageAlt' ) ?: $title )
			. '<div class="post-kinds-card__content"><div class="post-kinds-card__badges"><span class="post-kinds-card__badge">Item</span><span class="post-kinds-card__badge post-kinds-card__badge--medium">Medium</span></div>'
			. ( '' !== $title ? $h3( $link( $a( 'url' ), 'u-url u-wish-of', $title ) ) : '' )
			. ( '' !== $a( 'price' ) ? '<p class="post-kinds-card__subtitle">' . $esc_html( $a( 'price' ) ) . '</p>' : '' )
			. '</div>';
		return '<div class="wp-block-post-kinds-indieweb-wish-card wish-card layout-horizontal priority-medium"><div class="post-kinds-card h-cite">' . $body . '</div></div>';
	}
	if ( 'acquisition' === $kind ) {
		$labels = array( 'purchase' => 'Purchase', 'gift' => 'Gift', 'found' => 'Found', 'won' => 'Won', 'trade' => 'Trade', 'free' => 'Free', 'inherited' => 'Inherited', 'other' => 'Other' );
		$type   = '' !== $a( 'acquisitionType' ) ? $a( 'acquisitionType' ) : 'purchase';
		$body   = $media( $a( 'photo' ), $a( 'photoAlt' ) )
			. '<div class="post-kinds-card__content"><span class="post-kinds-card__badge">' . $esc_html( isset( $labels[ $type ] ) ? $labels[ $type ] : $type ) . '</span>'
			. ( '' !== $title ? $h3( $link( $a( 'whereUrl' ), 'u-url', $title ) ) : '' )
			. '</div>';
		return '<div class="wp-block-post-kinds-indieweb-acquisition-card acquisition-card layout-horizontal"><div class="post-kinds-card h-cite u-acquisition-of">' . $body . '</div></div>';
	}
	return '';
}

/**
 * The block markup for one card.
 *
 * @param string $kind  Kind slug from card_blocks().
 * @param array  $attrs From card_attrs().
 * @return string Block markup, or '' when there is nothing worth showing.
 */
function card_markup( string $kind, array $attrs ): string {
	$blocks = card_blocks();
	if ( ! isset( $blocks[ $kind ] ) ) {
		return '';
	}
	$ok = false;
	foreach ( $blocks[ $kind ]['needs'] as $need ) {
		if ( ! empty( $attrs[ $need ] ) ) {
			$ok = true;
		}
	}
	if ( ! $ok ) {
		return '';
	}
	$name = 'post-kinds-indieweb/' . $kind . '-card';
	$json = $attrs ? ' ' . serialize_block_attributes( $attrs ) : '';
	if ( $blocks[ $kind ]['static'] ) {
		return '<!-- wp:' . $name . $json . " -->\n" . static_html( $kind, $attrs ) . "\n<!-- /wp:" . $name . ' -->';
	}
	return '<!-- wp:' . $name . $json . ' /-->';
}

/**
 * The raw post_content, straight from the database (no cache, no filters).
 *
 * @param int $post_id Post ID.
 * @return string|null Null when the post is gone.
 */
function raw_content( int $post_id ): ?string {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	// get_row, not get_var: get_var returns null for an empty string, which would read as "post gone".
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ), ARRAY_N );
	return is_array( $row ) ? (string) $row[0] : null;
}

/**
 * Writes post_content with no hooks: one UPDATE on that column, then the cache is cleared.
 *
 * post_modified stays as it is. wp_update_post() would fire save_post and friends, which would
 * re-send webmentions and syndication and run the new plugin's kind sync.
 *
 * @param int    $post_id Post ID.
 * @param string $content New content.
 * @return bool
 */
function write_content( int $post_id, string $content ): bool {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ok = false !== $wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post_id ), array( '%s' ), array( '%d' ) );
	clean_post_cache( $post_id );
	return $ok;
}

/**
 * Builds the list of card blocks to prepend, without saving anything.
 *
 * @return array{items: array[], checked: int, by_kind: array<string,int>}
 *         Each item: id, kind, markup, md5 (of the content the plan was built from).
 */
function build_cards_plan(): array {
	global $wpdb;
	$plan    = array(
		'items'   => array(),
		'checked' => 0,
		'by_kind' => array(),
	);
	$cards   = card_blocks();
	$like    = $wpdb->esc_like( 'mf2_' ) . '%';
	$last_id = 0;
	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = 'post' AND p.post_status NOT IN ('trash','auto-draft','inherit') AND m.meta_key LIKE %s AND p.ID > %d ORDER BY p.ID ASC LIMIT 500", $like, $last_id ) ) );
		if ( ! $ids ) {
			break;
		}
		update_meta_cache( 'post', $ids );
		update_object_term_cache( $ids, 'post' );
		foreach ( $ids as $id ) {
			++$plan['checked'];
			$kind = post_kind( $id );
			if ( '' === $kind || ! isset( $cards[ $kind ] ) || metadata_exists( 'post', $id, NEW_MD5_META ) ) {
				continue;
			}
			$content = raw_content( $id );
			if ( null === $content || false !== strpos( $content, '<!-- wp:post-kinds-indieweb/' . $kind . '-card' ) ) {
				continue;
			}
			$markup = card_markup( $kind, card_attrs( $id, $kind ) );
			if ( '' === $markup ) {
				continue;
			}
			$plan['items'][]          = array(
				'id'     => $id,
				'kind'   => $kind,
				'markup' => $markup,
				'md5'    => md5( $content ),
			);
			$plan['by_kind'][ $kind ] = ( isset( $plan['by_kind'][ $kind ] ) ? $plan['by_kind'][ $kind ] : 0 ) + 1;
		}
		$last_id = (int) end( $ids );
	} while ( count( $ids ) === 500 );
	return $plan;
}

/**
 * Prepends the planned cards. Safe to run again, and to stop and resume.
 *
 * Skips a post whose content changed since the plan was built.
 *
 * @param array $plan     From build_cards_plan().
 * @param float $deadline Unix time to stop by; 0 for no limit.
 * @return int Number of posts changed.
 */
function apply_cards( array $plan, float $deadline = 0 ): int {
	$done = 0;
	foreach ( isset( $plan['items'] ) ? (array) $plan['items'] : array() as $item ) {
		if ( $deadline && microtime( true ) > $deadline ) {
			break;
		}
		$id = (int) $item['id'];
		if ( $id < 1 || '' === (string) $item['markup'] || metadata_exists( 'post', $id, NEW_MD5_META ) ) {
			continue;
		}
		$content = raw_content( $id );
		if ( null === $content || md5( $content ) !== $item['md5'] ) {
			continue; // Edited since the preview.
		}
		$new = $item['markup'] . ( '' === trim( $content ) ? '' : "\n\n" . $content );
		// Keep the way back first. update_post_meta() unslashes, so slash the content going in.
		update_post_meta( $id, ORIGINAL_META, wp_slash( $content ) );
		update_post_meta( $id, NEW_MD5_META, md5( $new ) );
		if ( write_content( $id, $new ) ) {
			++$done;
		} else {
			delete_post_meta( $id, ORIGINAL_META );
			delete_post_meta( $id, NEW_MD5_META );
		}
	}
	return $done;
}

/**
 * Puts back the original content of every post this module changed, unless it was edited since.
 *
 * @param float $deadline Unix time to stop by; 0 for no limit.
 * @return array{done: int, skipped: int, skipped_ids: int[]}
 */
function undo_cards( float $deadline = 0 ): array {
	global $wpdb;
	$out = array(
		'done'        => 0,
		'skipped'     => 0,
		'skipped_ids' => array(),
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id", NEW_MD5_META ) ) );
	foreach ( $ids as $id ) {
		if ( $deadline && microtime( true ) > $deadline ) {
			break;
		}
		$current = raw_content( $id );
		$md5     = (string) get_post_meta( $id, NEW_MD5_META, true );
		if ( null === $current ) { // The post is gone: drop the leftovers.
			delete_post_meta( $id, ORIGINAL_META );
			delete_post_meta( $id, NEW_MD5_META );
			continue;
		}
		if ( md5( $current ) !== $md5 || ! metadata_exists( 'post', $id, ORIGINAL_META ) ) {
			++$out['skipped'];
			$out['skipped_ids'][] = $id; // Edited since: leave it alone, and keep the record.
			continue;
		}
		if ( write_content( $id, (string) get_post_meta( $id, ORIGINAL_META, true ) ) ) {
			delete_post_meta( $id, ORIGINAL_META );
			delete_post_meta( $id, NEW_MD5_META );
			++$out['done'];
		}
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * One plan for the admin screen and the CLI
 * ---------------------------------------------------------------------- */

/**
 * A summary of what the details step would do, for display.
 *
 * @param bool $meta_only Leave the card blocks out.
 * @return array{count: int, by_kind: array<string, array{meta: int, cards: int}>, meta_posts: int, card_posts: int, skipped_kinds: string[], checked: int}
 */
function describe_details_plan( bool $meta_only = false ): array {
	$meta  = build_details_plan();
	$cards = $meta_only ? array( 'items' => array(), 'by_kind' => array() ) : build_cards_plan();
	$posts = array();
	$kinds = array();
	foreach ( $meta['items'] as $it ) {
		$posts[ $it['id'] ] = true;
		$kinds[ $it['kind'] ]['meta'] = ( isset( $kinds[ $it['kind'] ]['meta'] ) ? $kinds[ $it['kind'] ]['meta'] : 0 ) + 1;
	}
	foreach ( $cards['items'] as $it ) {
		$posts[ $it['id'] ] = true;
		$kinds[ $it['kind'] ]['cards'] = ( isset( $kinds[ $it['kind'] ]['cards'] ) ? $kinds[ $it['kind'] ]['cards'] : 0 ) + 1;
	}
	foreach ( $kinds as $k => $row ) {
		$kinds[ $k ] = array(
			'meta'  => isset( $row['meta'] ) ? $row['meta'] : 0,
			'cards' => isset( $row['cards'] ) ? $row['cards'] : 0,
		);
	}
	ksort( $kinds );
	return array(
		'count'         => count( $posts ),
		'by_kind'       => $kinds,
		'meta_posts'    => count( $meta['items'] ),
		'card_posts'    => count( $cards['items'] ),
		'skipped_kinds' => array_merge( skipped_kinds(), card_kinds_not_filled() ),
		'checked'       => $meta['checked'],
	);
}

/* -------------------------------------------------------------------------
 * WP-CLI:  wp pk-rescue details preview|apply|undo   (--meta-only to skip card blocks)
 * ---------------------------------------------------------------------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command(
		'pk-rescue details preview',
		static function ( $args, $assoc ) {
			$assoc     = (array) $assoc;
			$meta_only = ! empty( $assoc['meta-only'] );
			$format    = isset( $assoc['format'] ) ? (string) $assoc['format'] : 'table';
			$meta      = build_details_plan();
			$cards     = $meta_only ? array( 'items' => array() ) : build_cards_plan();
			$card_by   = array();
			foreach ( $cards['items'] as $it ) {
				$card_by[ $it['id'] ] = $it;
			}
			$rows = array();
			foreach ( $meta['items'] as $item ) {
				$parts = array();
				foreach ( $item['writes'] as $k => $v ) {
					$parts[] = $k . '=' . ( strlen( $v ) > 60 ? substr( $v, 0, 57 ) . '...' : $v );
				}
				$rows[ $item['id'] ] = array(
					'id'     => $item['id'],
					'kind'   => $item['kind'],
					'meta'   => implode( ' | ', $parts ),
					'card'   => isset( $card_by[ $item['id'] ] ) ? 'prepend' : '',
					'kept'   => implode( ',', $item['kept'] ),
				);
			}
			foreach ( $card_by as $id => $item ) {
				if ( ! isset( $rows[ $id ] ) ) {
					$rows[ $id ] = array( 'id' => $id, 'kind' => $item['kind'], 'meta' => '', 'card' => 'prepend', 'kept' => '' );
				}
			}
			if ( $rows ) {
				\WP_CLI\Utils\format_items( $format, array_values( $rows ), array( 'id', 'kind', 'meta', 'card', 'kept' ) );
			}
			if ( 'table' === $format ) {
				\WP_CLI::log( sprintf( 'Checked %d posts with classic data. %d get meta, %d get a card block.', $meta['checked'], count( $meta['items'] ), count( $cards['items'] ) ) );
				\WP_CLI::log( 'Not filled (no detail fields): ' . implode( ', ', array_merge( skipped_kinds(), card_kinds_not_filled() ) ) );
			}
		},
		array( 'shortdesc' => 'Show what would be filled, without saving. --meta-only leaves card blocks out. --format=table|csv|json' )
	);

	\WP_CLI::add_command(
		'pk-rescue details apply',
		static function ( $args, $assoc ) {
			$assoc     = (array) $assoc;
			$meta_only = ! empty( $assoc['meta-only'] );
			$meta      = build_details_plan();
			$cards     = $meta_only ? array( 'items' => array() ) : build_cards_plan();
			if ( ! $meta['items'] && ! $cards['items'] ) {
				\WP_CLI::success( 'Nothing to fill.' );
				return;
			}
			\WP_CLI::confirm( sprintf( 'Fill details on %d posts and add a card block to %d? Existing values are never overwritten.', count( $meta['items'] ), count( $cards['items'] ) ), $assoc );
			$m = apply_details( $meta );
			$c = apply_cards( $cards );
			\WP_CLI::success( sprintf( 'Filled meta on %d posts, added a card block to %d. Run "wp pk-rescue details undo" to reverse.', $m, $c ) );
		},
		array( 'shortdesc' => 'Fill _pkiw_ meta and prepend card blocks from classic mf2_ data. Never overwrites a value. --meta-only, --yes.' )
	);

	\WP_CLI::add_command(
		'pk-rescue details undo',
		static function ( $args, $assoc ) {
			$assoc = (array) $assoc;
			$n     = count( written_ids() );
			global $wpdb;
			$cards = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", NEW_MD5_META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $n && ! $cards ) {
				\WP_CLI::success( 'Nothing to undo.' );
				return;
			}
			\WP_CLI::confirm( sprintf( 'Remove the meta this tool filled on %d posts and the card block on %d?', $n, $cards ), $assoc );
			$c = undo_cards();
			$m = undo_details();
			\WP_CLI::success( sprintf( 'Restored meta on %d posts and content on %d.', $m, $c['done'] ) );
			if ( $c['skipped'] ) {
				\WP_CLI::warning( sprintf( '%d posts were edited since, so their content was left alone: %s', $c['skipped'], implode( ', ', $c['skipped_ids'] ) ) );
			}
		},
		array( 'shortdesc' => 'Reverse the details step. Content is restored only if unedited since; meta only where still unchanged. --yes.' )
	);
}
