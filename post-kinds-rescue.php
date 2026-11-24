<?php
/**
 * Plugin Name:       Post Kinds Rescue
 * Description:       Puts posts migrated from the classic Post Kinds plugin back in the right kind, using the response data (mf2_ post meta) the classic plugin left behind. Preview first, fix in one go, undo any time.
 * Version:           0.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       post-kinds-rescue
 *
 * @package PostKindsRescue
 */

namespace PostKindsRescue;

defined( 'ABSPATH' ) || exit;

const TAXONOMY  = 'kind';
const PREV_META = '_pk_rescue_previous_kind';
const APPLIED_META = '_pk_rescue_applied_kind';
const NO_KIND   = '__none__';
const PAGE_SLUG = 'post-kinds-rescue';

/**
 * Which mf2 property decides which kind, in Post Type Discovery order.
 *
 * Mirrors \ParseThis\post_type_discovery() in classic Post Kinds 4.x, then adds
 * the kinds whose property that function does not check (wish, quote, mood …),
 * placed before the generic reply/photo/video/audio properties.
 *
 * @return array<string, string[]> Kind slug => mf2 property names.
 */
function property_map(): array {
	return array(
		'rsvp'        => array( 'rsvp' ),
		'review'      => array( 'review-of' ),
		'checkin'     => array( 'checkin' ),
		'itinerary'   => array( 'itinerary' ),
		'repost'      => array( 'repost-of' ),
		'like'        => array( 'like-of' ),
		'follow'      => array( 'follow-of' ),
		'tag'         => array( 'tag-of' ),
		'favorite'    => array( 'favorite-of' ),
		'bookmark'    => array( 'bookmark-of' ),
		'watch'       => array( 'watch-of' ),
		'jam'         => array( 'jam-of' ),
		'listen'      => array( 'listen-of' ),
		'read'        => array( 'read-of' ),
		'play'        => array( 'play-of' ),
		'eat'         => array( 'ate', 'pk-ate' ),
		'drink'       => array( 'drank', 'pk-drank' ),
		'wish'        => array( 'wish-of' ),
		'quote'       => array( 'quotation-of' ),
		'acquisition' => array( 'acquired-of' ),
		'craft'       => array( 'craft-of' ),
		'event'       => array( 'event' ),
		'recipe'      => array( 'recipe' ),
		'question'    => array( 'question' ),
		'sleep'       => array( 'sleep' ),
		'exercise'    => array( 'exercise' ),
		'trip'        => array( 'trip' ),
		'weather'     => array( 'weather' ),
		'mood'        => array( 'mood' ),
		'reply'       => array( 'in-reply-to' ),
		'video'       => array( 'video' ),
		'photo'       => array( 'photo' ),
		'audio'       => array( 'audio' ),
	);
}

/**
 * Plain words for what an mf2 property holds.
 *
 * @param string $name Property name.
 * @return string
 */
function property_label( string $name ): string {
	$labels = array(
		'rsvp'         => __( 'an RSVP answer', 'post-kinds-rescue' ),
		'review-of'    => __( 'a reviewed item', 'post-kinds-rescue' ),
		'checkin'      => __( 'a check-in place', 'post-kinds-rescue' ),
		'itinerary'    => __( 'a travel itinerary', 'post-kinds-rescue' ),
		'repost-of'    => __( 'a reposted link', 'post-kinds-rescue' ),
		'like-of'      => __( 'a liked link', 'post-kinds-rescue' ),
		'follow-of'    => __( 'a followed link', 'post-kinds-rescue' ),
		'tag-of'       => __( 'a tagged link', 'post-kinds-rescue' ),
		'favorite-of'  => __( 'a favorited link', 'post-kinds-rescue' ),
		'bookmark-of'  => __( 'a bookmarked link', 'post-kinds-rescue' ),
		'watch-of'     => __( 'something watched', 'post-kinds-rescue' ),
		'jam-of'       => __( 'a jam (song)', 'post-kinds-rescue' ),
		'listen-of'    => __( 'something listened to', 'post-kinds-rescue' ),
		'read-of'      => __( 'something read', 'post-kinds-rescue' ),
		'play-of'      => __( 'a game played', 'post-kinds-rescue' ),
		'ate'          => __( 'something eaten', 'post-kinds-rescue' ),
		'pk-ate'       => __( 'something eaten', 'post-kinds-rescue' ),
		'drank'        => __( 'something drunk', 'post-kinds-rescue' ),
		'pk-drank'     => __( 'something drunk', 'post-kinds-rescue' ),
		'wish-of'      => __( 'a wished-for item', 'post-kinds-rescue' ),
		'quotation-of' => __( 'a quotation source', 'post-kinds-rescue' ),
		'acquired-of'  => __( 'an acquired item', 'post-kinds-rescue' ),
		'craft-of'     => __( 'a craft project', 'post-kinds-rescue' ),
		'in-reply-to'  => __( 'a reply-to link', 'post-kinds-rescue' ),
	);
	/* translators: %s: property name, e.g. photo. */
	return $labels[ $name ] ?? sprintf( __( '%s data', 'post-kinds-rescue' ), $name );
}

/**
 * Whether a stored meta value carries no data.
 *
 * @param mixed $value Unserialized meta value.
 * @return bool
 */
function is_blank( $value ): bool {
	if ( is_array( $value ) ) {
		foreach ( $value as $item ) {
			if ( ! is_blank( $item ) ) {
				return false;
			}
		}
		return true;
	}
	return null === $value || false === $value || '' === trim( (string) $value );
}

/**
 * The mf2 properties with data on a post.
 *
 * @param int $post_id Post ID.
 * @return array<string, true> Property name => true.
 */
function stored_properties( int $post_id ): array {
	$found = array();
	foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
		if ( 0 !== strpos( (string) $key, 'mf2_' ) ) {
			continue;
		}
		foreach ( (array) $values as $raw ) {
			if ( ! is_blank( maybe_unserialize( $raw ) ) ) {
				$found[ substr( (string) $key, 4 ) ] = true;
				break;
			}
		}
	}
	return $found;
}

/**
 * Works out the kind a post's stored data points to.
 *
 * @param int $post_id Post ID.
 * @return array{kind: string, reason: string, legacy: bool}
 */
function detect_kind( int $post_id ): array {
	$props = stored_properties( $post_id );
	foreach ( property_map() as $kind => $names ) {
		foreach ( $names as $name ) {
			if ( isset( $props[ $name ] ) ) {
				return array(
					'kind'   => $kind,
					/* translators: 1: what was found, e.g. "a liked link", 2: meta key, e.g. mf2_like-of. */
					'reason' => sprintf( __( 'Found %1$s (%2$s)', 'post-kinds-rescue' ), property_label( $name ), 'mf2_' . $name ),
					'legacy' => false,
				);
			}
		}
	}
	$legacy = isset( $props['cite'] ) || ! is_blank( get_post_meta( $post_id, 'response', true ) );
	return array(
		'kind'   => '',
		'reason' => $legacy ? __( 'only old-style response data (mf2_cite or response); kind cannot be told for sure', 'post-kinds-rescue' ) : '',
		'legacy' => $legacy,
	);
}

/**
 * Normalises the options for a run.
 *
 * @param array $raw Raw options (from a form, the CLI or defaults).
 * @return array{replace: string[], untitled_note: bool, skip: string[]}
 */
function options( array $raw = array() ): array {
	$replace = isset( $raw['replace'] ) ? (array) $raw['replace'] : array( NO_KIND, 'article', 'note' );
	$replace = array_values( array_intersect( array_map( 'sanitize_key', $replace ), array( NO_KIND, 'article', 'note' ) ) );
	$skip    = isset( $raw['skip'] ) ? array_map( 'sanitize_key', (array) $raw['skip'] ) : array();
	return array(
		'replace'       => $replace,
		'untitled_note' => ! empty( $raw['untitled_note'] ),
		'skip'          => array_values( array_intersect( $skip, array_merge( array_keys( property_map() ), array( 'note' ) ) ) ),
	);
}

/**
 * Builds the list of changes, without saving anything.
 *
 * @param array $opts Options from options().
 * @return array{changes: array[], review: array[], checked: int, missing_terms: string[]}
 */
function build_plan( array $opts ): array {
	global $wpdb;
	$plan    = array(
		'changes'       => array(),
		'review'        => array(),
		'checked'       => 0,
		'missing_terms' => array(),
	);
	$last_id = 0;
	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Keyset scan over every post, run on demand.
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status NOT IN ('trash','auto-draft','inherit') AND ID > %d ORDER BY ID ASC LIMIT 500", $last_id ) ) );
		if ( ! $ids ) {
			break;
		}
		_prime_post_caches( $ids, false, false );
		update_meta_cache( 'post', $ids );
		update_object_term_cache( $ids, 'post' );
		foreach ( $ids as $id ) {
			++$plan['checked'];
			$terms   = get_the_terms( $id, TAXONOMY );
			$current = ( $terms && ! is_wp_error( $terms ) ) ? wp_list_pluck( $terms, 'slug' ) : array();
			$label   = $current ? implode( ',', $current ) : NO_KIND;
			if ( count( $current ) > 1 || ! in_array( $label, $opts['replace'], true ) ) {
				continue; // A kind someone chose on purpose: never touch it.
			}
			$found = detect_kind( $id );
			$new   = $found['kind'];
			$why   = $found['reason'];
			if ( '' === $new && ! $found['legacy'] && $opts['untitled_note'] && 'article' === $label && '' === trim( (string) get_post_field( 'post_title', $id ) ) ) {
				$new = 'note';
				$why = __( 'no title, so a note rather than an article', 'post-kinds-rescue' );
			}
			if ( '' === $new ) {
				if ( $found['legacy'] ) {
					$plan['review'][] = array( 'id' => $id, 'current' => $label, 'new' => '', 'reason' => $why );
				}
				continue;
			}
			if ( $new === $label || in_array( $new, $opts['skip'], true ) ) {
				continue;
			}
			if ( ! term_id( $new ) ) {
				$plan['missing_terms'][ $new ] = $new;
				continue;
			}
			$plan['changes'][] = array( 'id' => $id, 'current' => $label, 'new' => $new, 'reason' => $why );
		}
		$last_id = end( $ids );
	} while ( count( $ids ) === 500 );
	$plan['missing_terms'] = array_values( $plan['missing_terms'] );
	return $plan;
}

/**
 * Term ID for a kind slug, looked up once per request.
 *
 * @param string $slug Kind slug.
 * @return int Term ID, or 0 when the kind does not exist.
 */
function term_id( string $slug ): int {
	static $cache = array();
	if ( ! array_key_exists( $slug, $cache ) ) {
		$term           = get_term_by( 'slug', $slug, TAXONOMY );
		$cache[ $slug ] = $term ? (int) $term->term_id : 0;
	}
	return $cache[ $slug ];
}

/**
 * Applies changes until done or out of time. Safe to call again.
 *
 * @param array $changes  Changes from build_plan().
 * @param float $deadline Unix time to stop by; 0 for no limit.
 * @return int Number of posts changed.
 */
function apply_changes( array $changes, float $deadline = 0 ): int {
	$done = 0;
	foreach ( $changes as $change ) {
		if ( $deadline && microtime( true ) > $deadline ) {
			break;
		}
		$term_id = term_id( $change['new'] );
		if ( ! $term_id ) {
			continue;
		}
		// Keep the kind from before the first run, so undo always goes all the way back.
		$added  = ! metadata_exists( 'post', $change['id'], PREV_META ) && add_post_meta( $change['id'], PREV_META, $change['current'], true );
		$result = wp_set_object_terms( $change['id'], array( $term_id ), TAXONOMY, false );
		if ( is_wp_error( $result ) ) {
			if ( $added ) {
				delete_post_meta( $change['id'], PREV_META );
			}
			continue;
		}
		update_post_meta( $change['id'], APPLIED_META, $change['new'] );
		++$done;
	}
	return $done;
}

/**
 * IDs of posts this tool has changed.
 *
 * @return int[]
 */
function changed_ids(): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id", PREV_META ) ) );
}

/**
 * Puts posts back the way they were before this tool changed them.
 *
 * A post whose kind was changed again after the fix is left as it is, and so
 * is one whose earlier kind no longer exists: undo never guesses.
 *
 * @param float $deadline Unix time to stop by; 0 for no limit.
 * @return array{done: int, skipped: int} Posts restored, and posts left alone.
 */
function undo_changes( float $deadline = 0 ): array {
	$out = array(
		'done'    => 0,
		'skipped' => 0,
	);
	foreach ( changed_ids() as $id ) {
		if ( $deadline && microtime( true ) > $deadline ) {
			break;
		}
		$previous = (string) get_post_meta( $id, PREV_META, true );
		$applied  = (string) get_post_meta( $id, APPLIED_META, true );
		$terms    = get_the_terms( $id, TAXONOMY );
		$current  = ( $terms && ! is_wp_error( $terms ) ) ? implode( ',', wp_list_pluck( $terms, 'slug' ) ) : '';
		$slugs    = NO_KIND === $previous || '' === $previous ? array() : explode( ',', $previous );
		$term_ids = array_map( __NAMESPACE__ . '\\term_id', $slugs );
		if ( ( '' !== $applied && $current !== $applied ) || in_array( 0, $term_ids, true ) ) {
			delete_post_meta( $id, PREV_META );
			delete_post_meta( $id, APPLIED_META );
			++$out['skipped'];
			continue;
		}
		$result = wp_set_object_terms( $id, $term_ids, TAXONOMY, false );
		if ( ! is_wp_error( $result ) ) {
			delete_post_meta( $id, PREV_META );
			delete_post_meta( $id, APPLIED_META );
			++$out['done'];
		}
	}
	return $out;
}

/**
 * Counts what is in the database: current kinds and mf2 keys.
 *
 * @return array{kinds: array<string,int>, keys: array<string,int>}
 */
function inventory(): array {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$kinds = $wpdb->get_results( $wpdb->prepare( "SELECT t.slug, COUNT(*) AS n FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id = tt.term_id JOIN {$wpdb->posts} p ON p.ID = r.object_id WHERE tt.taxonomy = %s AND p.post_type = 'post' AND p.post_status NOT IN ('trash','auto-draft','inherit') GROUP BY t.slug ORDER BY n DESC", TAXONOMY ) );
	$none  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'post' AND p.post_status NOT IN ('trash','auto-draft','inherit') AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id WHERE r.object_id = p.ID AND tt.taxonomy = %s)", TAXONOMY ) );
	$keys  = $wpdb->get_results( "SELECT m.meta_key, COUNT(DISTINCT m.post_id) AS n FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = 'post' AND (m.meta_key LIKE 'mf2\\_%' OR m.meta_key = 'response') GROUP BY m.meta_key ORDER BY n DESC" );
	// phpcs:enable
	$out = array(
		'kinds' => array(),
		'keys'  => array(),
	);
	foreach ( $kinds as $row ) {
		$out['kinds'][ $row->slug ] = (int) $row->n;
	}
	if ( $none ) {
		$out['kinds'][ NO_KIND ] = $none;
	}
	foreach ( $keys as $row ) {
		$out['keys'][ $row->meta_key ] = (int) $row->n;
	}
	return $out;
}

/**
 * A readable name for a kind slug.
 *
 * @param string $slug Kind slug, comma list, or NO_KIND.
 * @return string
 */
function kind_name( string $slug ): string {
	if ( NO_KIND === $slug || '' === $slug ) {
		return __( 'No kind', 'post-kinds-rescue' );
	}
	$names = array();
	foreach ( explode( ',', $slug ) as $one ) {
		$term    = get_term_by( 'slug', $one, TAXONOMY );
		$names[] = $term ? $term->name : $one;
	}
	return implode( ', ', $names );
}

/* -------------------------------------------------------------------------
 * Admin screen: Tools > Post Kinds Rescue. Plain forms, no JavaScript needed.
 * ---------------------------------------------------------------------- */

add_action(
	'admin_menu',
	static function () {
		$hook = add_management_page(
			__( 'Post Kinds Rescue', 'post-kinds-rescue' ),
			__( 'Post Kinds Rescue', 'post-kinds-rescue' ),
			'manage_options',
			PAGE_SLUG,
			__NAMESPACE__ . '\\render_page'
		);
		if ( $hook ) {
			add_action(
				'admin_enqueue_scripts',
				static function ( $current ) use ( $hook ) {
					if ( $current === $hook ) {
						wp_enqueue_script( 'wp-a11y' );
					}
				}
			);
		}
	}
);

/**
 * Options from the current request, falling back to defaults.
 *
 * @return array
 */
function request_options(): array {
	// phpcs:disable WordPress.Security.NonceVerification -- Read-only preview options; writes check a nonce.
	$source = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ? $_POST : $_GET;
	if ( empty( $source['pkr_submitted'] ) ) {
		return options( array() );
	}
	$keys = static function ( string $name ) use ( $source ): array {
		return isset( $source[ $name ] ) ? array_map( 'sanitize_key', (array) wp_unslash( $source[ $name ] ) ) : array();
	};
	$skip = $keys( 'skip' );
	if ( ! empty( $source['pkr_kinds_form'] ) ) {
		// Kinds offered with a tick box: unticked ones join the skip list, ticked ones leave it.
		$ticked = $keys( 'kinds_shown' );
		$skip   = array_diff( array_unique( array_merge( $skip, array_diff( $keys( 'all_kinds' ), $ticked ) ) ), $ticked );
	}
	return options(
		array(
			'replace'       => $keys( 'replace' ),
			'untitled_note' => ! empty( $source['untitled_note'] ),
			'skip'          => array_values( $skip ),
		)
	);
	// phpcs:enable
}

/**
 * Hidden fields that carry the options into another form.
 *
 * @param array $opts Options.
 */
function option_fields( array $opts ): void {
	echo '<input type="hidden" name="pkr_submitted" value="1">';
	foreach ( $opts['replace'] as $value ) {
		echo '<input type="hidden" name="replace[]" value="' . esc_attr( $value ) . '">';
	}
	foreach ( $opts['skip'] as $value ) {
		echo '<input type="hidden" name="skip[]" value="' . esc_attr( $value ) . '">';
	}
	if ( $opts['untitled_note'] ) {
		echo '<input type="hidden" name="untitled_note" value="1">';
	}
}

add_action(
	'admin_post_pkr_apply',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'post-kinds-rescue' ) );
		}
		check_admin_referer( 'pkr_form' );
		$deadline = start_clock();
		$opts     = request_options();
		$plan     = build_plan( $opts );
		$done     = apply_changes( $plan['changes'], $deadline );
		$left     = count( $plan['changes'] ) - $done;
		redirect_back( $opts, array( 'pkr_done' => 'fixed', 'pkr_n' => $done, 'pkr_left' => $left ) );
	}
);

add_action(
	'admin_post_pkr_preview',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'post-kinds-rescue' ) );
		}
		check_admin_referer( 'pkr_form' );
		redirect_back( request_options(), array( 'pkr_done' => 'preview' ), 'pkr-preview' );
	}
);

/**
 * Gives a request more time and returns when work should stop.
 *
 * WP Engine and similar hosts end requests at about 60 seconds, so work
 * stops after 25 and the person presses the button again for the rest.
 *
 * @return float Unix time to stop by.
 */
function start_clock(): float {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	return microtime( true ) + 25;
}

add_action(
	'admin_post_pkr_undo',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'post-kinds-rescue' ) );
		}
		check_admin_referer( 'pkr_undo' );
		$res = undo_changes( start_clock() );
		redirect_back( request_options(), array( 'pkr_done' => 'undone', 'pkr_n' => $res['done'], 'pkr_skipped' => $res['skipped'], 'pkr_left' => count( changed_ids() ) ) );
	}
);

add_action(
	'admin_post_pkr_csv',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'post-kinds-rescue' ) );
		}
		check_admin_referer( 'pkr_form' );
		start_clock();
		$plan = build_plan( request_options() );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="post-kinds-rescue-preview.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'post_id', 'title', 'url', 'current_kind', 'new_kind', 'reason', 'status' ), ',', '"', '\\' );
		foreach ( array( 'change' => $plan['changes'], 'needs review' => $plan['review'] ) as $status => $rows ) {
			foreach ( $rows as $row ) {
				fputcsv( $out, array_map( __NAMESPACE__ . '\\csv_cell', array( $row['id'], get_the_title( $row['id'] ), get_permalink( $row['id'] ), $row['current'], $row['new'], $row['reason'], $status ) ), ',', '"', '\\' );
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
);

/**
 * Stops spreadsheet apps from running a cell as a formula.
 *
 * @param mixed $value Cell value.
 * @return string
 */
function csv_cell( $value ): string {
	$value = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
	return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
}

/**
 * Redirects to the tool page with the options and a result.
 *
 * @param array  $opts   Options.
 * @param array  $result Result query args.
 * @param string $anchor Optional element id to jump to.
 */
function redirect_back( array $opts, array $result, string $anchor = 'pkr-result' ): void {
	$args = array_merge(
		array(
			'page'          => PAGE_SLUG,
			'pkr_submitted' => 1,
			'replace'       => $opts['replace'],
			'skip'          => $opts['skip'],
			'untitled_note' => $opts['untitled_note'] ? 1 : 0,
		),
		$result
	);
	wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) . '#' . $anchor );
	exit;
}

/**
 * Renders Tools > Post Kinds Rescue.
 */
function render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$opts    = request_options();
	$plan    = build_plan( $opts );
	$inv     = inventory();
	$changed = count( changed_ids() );
	$total   = count( $plan['changes'] );
	$by_kind = array();
	foreach ( $plan['changes'] as $row ) {
		$by_kind[ $row['new'] ] = ( $by_kind[ $row['new'] ] ?? 0 ) + 1;
	}
	arsort( $by_kind );
	// Kinds the person unticked still need a checkbox so they can tick them again.
	foreach ( $opts['skip'] as $skipped ) {
		$by_kind[ $skipped ] = $by_kind[ $skipped ] ?? 0;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Post Kinds Rescue', 'post-kinds-rescue' ); ?></h1>
		<?php render_result_notice(); ?>
		<nav aria-label="<?php esc_attr_e( 'Steps on this page', 'post-kinds-rescue' ); ?>">
			<p><?php esc_html_e( 'Jump to:', 'post-kinds-rescue' ); ?>
				<a href="#pkr-fix"><?php esc_html_e( 'Step 1, Fix the kinds', 'post-kinds-rescue' ); ?></a> |
				<a href="#pkr-details"><?php esc_html_e( 'Step 2, Show the details', 'post-kinds-rescue' ); ?></a>
			</p>
		</nav>
		<?php if ( ! taxonomy_exists( TAXONOMY ) ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'The kind taxonomy is not registered. Activate the Post Kinds for IndieWeb plugin first, then come back.', 'post-kinds-rescue' ); ?></p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'This tool reads the response data the classic Post Kinds plugin saved with each post (for example the liked URL, or the song you listened to) and gives each post the kind that data points to. Step 1 only changes the kind; post content, titles and dates are not touched. Step 2 adds the saved details back to each post. Nothing changes until you press the button in each step, and both steps can be undone.', 'post-kinds-rescue' ); ?></p>

		<h2><?php esc_html_e( 'Summary', 'post-kinds-rescue' ); ?></h2>
		<ul>
			<?php /* translators: %s: number of posts. */ ?>
			<li><?php echo esc_html( sprintf( _n( '%s post checked.', '%s posts checked.', $plan['checked'], 'post-kinds-rescue' ), number_format_i18n( $plan['checked'] ) ) ); ?></li>
			<?php /* translators: %s: number of posts. */ ?>
			<li><strong><?php echo esc_html( sprintf( _n( '%s post can be fixed.', '%s posts can be fixed.', $total, 'post-kinds-rescue' ), number_format_i18n( $total ) ) ); ?></strong></li>
			<?php if ( $plan['review'] ) : ?>
				<?php /* translators: %s: number of posts. */ ?>
				<li><?php echo esc_html( sprintf( _n( '%s post has only old-style data and needs a person to check it. It is listed in the downloadable file and will not be changed.', '%s posts have only old-style data and need a person to check them. They are listed in the downloadable file and will not be changed.', count( $plan['review'] ), 'post-kinds-rescue' ), number_format_i18n( count( $plan['review'] ) ) ) ); ?></li>
			<?php endif; ?>
			<?php if ( $plan['missing_terms'] ) : ?>
				<?php /* translators: %s: comma-separated kind slugs. */ ?>
				<li><?php echo esc_html( sprintf( __( 'Skipped because these kinds do not exist on this site: %s.', 'post-kinds-rescue' ), implode( ', ', $plan['missing_terms'] ) ) ); ?></li>
			<?php endif; ?>
			<?php if ( $changed ) : ?>
				<?php /* translators: %s: number of posts. */ ?>
				<li><?php echo esc_html( sprintf( _n( '%s post was changed by this tool earlier and can be undone.', '%s posts were changed by this tool earlier and can be undone.', $changed, 'post-kinds-rescue' ), number_format_i18n( $changed ) ) ); ?></li>
			<?php endif; ?>
		</ul>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'pkr_form' ); ?>
			<input type="hidden" name="pkr_submitted" value="1">
			<input type="hidden" name="pkr_kinds_form" value="1">
			<?php foreach ( $opts['skip'] as $value ) : ?>
				<input type="hidden" name="skip[]" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>

			<h2><?php esc_html_e( 'Settings', 'post-kinds-rescue' ); ?></h2>
			<fieldset>
				<legend><strong><?php esc_html_e( 'Change only posts that have one of these kinds now:', 'post-kinds-rescue' ); ?></strong></legend>
				<?php foreach ( array( NO_KIND => __( 'No kind', 'post-kinds-rescue' ), 'article' => __( 'Article', 'post-kinds-rescue' ), 'note' => __( 'Note', 'post-kinds-rescue' ) ) as $value => $label ) : ?>
					<p><label><input type="checkbox" name="replace[]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $opts['replace'], true ) ); ?>> <?php echo esc_html( $label ); ?></label></p>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Posts with a kind that is not ticked here are never changed. Untick Note if you chose Note on purpose for some posts.', 'post-kinds-rescue' ); ?></p>
			</fieldset>
			<fieldset>
				<legend><strong><?php esc_html_e( 'Posts without response data', 'post-kinds-rescue' ); ?></strong></legend>
				<p><label><input type="checkbox" name="untitled_note" value="1" <?php checked( $opts['untitled_note'] ); ?>> <?php esc_html_e( 'Make articles that have no title into notes', 'post-kinds-rescue' ); ?></label></p>
			</fieldset>
			<?php if ( $by_kind ) : ?>
				<fieldset>
					<legend><strong><?php esc_html_e( 'Kinds to fix. Untick any you want to leave alone:', 'post-kinds-rescue' ); ?></strong></legend>
					<?php foreach ( $by_kind as $kind => $count ) : ?>
						<?php
						$skipped = in_array( $kind, $opts['skip'], true );
						/* translators: 1: kind name, 2: number of posts. */
						$label = $skipped ? sprintf( __( '%1$s (left alone)', 'post-kinds-rescue' ), kind_name( $kind ) ) : sprintf( _n( '%1$s: %2$s post', '%1$s: %2$s posts', $count, 'post-kinds-rescue' ), kind_name( $kind ), number_format_i18n( $count ) );
						?>
						<p><label><input type="checkbox" class="pkr-kind-box" data-count="<?php echo esc_attr( (string) ( $skipped ? -1 : $count ) ); ?>" name="kinds_shown[]" value="<?php echo esc_attr( $kind ); ?>" <?php checked( ! $skipped ); ?>> <?php echo esc_html( $label ); ?></label></p>
						<?php if ( ! $skipped ) : ?>
							<input type="hidden" name="all_kinds[]" value="<?php echo esc_attr( $kind ); ?>">
						<?php endif; ?>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>
			<p><button type="submit" class="button" id="pkr-preview-button" name="action" value="pkr_preview"><?php esc_html_e( 'Update preview', 'post-kinds-rescue' ); ?></button></p>

			<h2 id="pkr-preview" tabindex="-1"><?php esc_html_e( 'Preview', 'post-kinds-rescue' ); ?></h2>
			<?php if ( ! $total ) : ?>
				<p><?php esc_html_e( 'Nothing to fix with these settings.', 'post-kinds-rescue' ); ?></p>
			<?php else : ?>
				<?php $shown = array_slice( $plan['changes'], 0, 100 ); ?>
				<p><a href="#pkr-fix"><?php esc_html_e( 'Skip the table and go to step 1, Fix the kinds', 'post-kinds-rescue' ); ?></a></p>
				<table class="widefat striped">
					<?php /* translators: 1: rows shown, 2: total changes. */ ?>
					<caption style="text-align:left;padding:8px 0;"><?php echo esc_html( sprintf( __( 'Planned changes: showing %1$s of %2$s. Download the file below for the full list.', 'post-kinds-rescue' ), number_format_i18n( count( $shown ) ), number_format_i18n( $total ) ) ); ?></caption>
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Post', 'post-kinds-rescue' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Kind now', 'post-kinds-rescue' ); ?></th>
						<th scope="col"><?php esc_html_e( 'New kind', 'post-kinds-rescue' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Why', 'post-kinds-rescue' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $shown as $row ) : ?>
						<?php $title = get_the_title( $row['id'] ); ?>
						<tr>
							<th scope="row"><a href="<?php echo esc_url( (string) get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( '' !== $title ? $title : __( 'Untitled post', 'post-kinds-rescue' ) ); ?><span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %d: post ID. */ __( ' (ID %d)', 'post-kinds-rescue' ), $row['id'] ) ); ?></span></a></th>
							<td><?php echo esc_html( kind_name( $row['current'] ) ); ?></td>
							<td><?php echo esc_html( kind_name( $row['new'] ) ); ?></td>
							<td><?php echo esc_html( $row['reason'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( $total || $plan['review'] ) : ?>
				<p><button type="submit" class="button" id="pkr-csv-button" name="action" value="pkr_csv"><?php esc_html_e( 'Download full preview (CSV file)', 'post-kinds-rescue' ); ?></button></p>
			<?php endif; ?>

			<?php if ( ! $total ) : ?>
				<h2 id="pkr-fix" tabindex="-1"><?php esc_html_e( 'Step 1: Fix the kinds', 'post-kinds-rescue' ); ?></h2>
				<p><?php esc_html_e( 'No kinds need fixing with these settings.', 'post-kinds-rescue' ); ?></p>
			<?php endif; ?>
			<?php if ( $total ) : ?>
				<h2 id="pkr-fix" tabindex="-1"><?php esc_html_e( 'Step 1: Fix the kinds', 'post-kinds-rescue' ); ?></h2>
				<p><?php esc_html_e( 'This uses the settings on this page as they are now, so you do not need to press Update preview first. Only the kind of each post changes. Large sites are fixed in rounds of about 25 seconds: if some posts are left, the message at the top of the page says so and you press this button again.', 'post-kinds-rescue' ); ?></p>
				<?php /* translators: %s: number of posts. */ ?>
				<p><button type="submit" class="button button-primary" id="pkr-fix-button" name="action" value="pkr_apply"><?php echo esc_html( sprintf( _n( 'Change the kind of %s post now', 'Change the kind of up to %s posts now', $total, 'post-kinds-rescue' ), number_format_i18n( $total ) ) ); ?></button></p>
			<?php endif; ?>
		</form>
		<?php
		$js_text = array(
			/* translators: %s: number of posts. */
			'some'    => __( 'Change the kind of up to %s posts now', 'post-kinds-rescue' ),
			/* translators: %s: number of posts. */
			'one'     => __( 'Change the kind of %s post now', 'post-kinds-rescue' ),
			'unknown' => __( 'Change the kinds now (press Update preview to see how many)', 'post-kinds-rescue' ),
		);
		?>
		<script>
		(function () {
			var t = <?php echo wp_json_encode( $js_text ); ?>;
			function update() {
				var btn = document.getElementById('pkr-fix-button');
				if (!btn) { return; }
				var total = 0, unknown = false;
				document.querySelectorAll('.pkr-kind-box').forEach(function (b) {
					var c = parseInt(b.getAttribute('data-count'), 10);
					if (b.checked) { if (c < 0) { unknown = true; } else { total += c; } }
				});
				btn.textContent = unknown ? t.unknown : (total === 1 ? t.one : t.some).replace('%s', total.toLocaleString());
			}
			document.addEventListener('change', function (e) {
				if (e.target && e.target.classList && e.target.classList.contains('pkr-kind-box')) { update(); }
			});
			var u = document.querySelector('input[name="untitled_note"]');
			if (u) { u.addEventListener('change', function () { var btn = document.getElementById('pkr-fix-button'); if (btn) { btn.textContent = t.unknown; } }); }
		})();
		</script>

		<?php if ( $changed ) : ?>
			<h2><?php esc_html_e( 'Undo the kind changes', 'post-kinds-rescue' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pkr_undo">
				<?php wp_nonce_field( 'pkr_undo' ); ?>
				<?php option_fields( $opts ); ?>
				<?php /* translators: %s: number of posts. */ ?>
				<p><?php echo esc_html( sprintf( _n( 'Put %s post back to the kind it had before this tool changed it.', 'Put %s posts back to the kind they had before this tool changed them.', $changed, 'post-kinds-rescue' ), number_format_i18n( $changed ) ) ); ?></p>
				<p><button type="submit" class="button" id="pkr-undo-button"><?php esc_html_e( 'Undo kind changes', 'post-kinds-rescue' ); ?></button></p>
			</form>
		<?php endif; ?>

		<?php render_details_section( $opts ); ?>

		<h2><?php esc_html_e( 'What is in your database', 'post-kinds-rescue' ); ?></h2>
		<p><?php esc_html_e( 'Useful if the preview looks wrong: copy these two tables into your support request.', 'post-kinds-rescue' ); ?></p>
		<?php
		$kind_rows = array();
		foreach ( $inv['kinds'] as $slug => $count ) {
			$kind_rows[ kind_name( (string) $slug ) . ( NO_KIND === $slug ? '' : ' (' . $slug . ')' ) ] = $count;
		}
		render_count_table( __( 'Posts per kind right now', 'post-kinds-rescue' ), __( 'Kind', 'post-kinds-rescue' ), $kind_rows );
		?>
		<?php render_count_table( __( 'Posts per response data field', 'post-kinds-rescue' ), __( 'Field', 'post-kinds-rescue' ), $inv['keys'] ); ?>
	</div>
	<?php
}

/**
 * Prints a two-column count table.
 *
 * @param string $caption Caption.
 * @param string $col     First column heading.
 * @param array  $rows    Label => count.
 */
function render_count_table( string $caption, string $col, array $rows ): void {
	if ( ! $rows ) {
		echo '<p>' . esc_html( $caption ) . ': ' . esc_html__( 'none found.', 'post-kinds-rescue' ) . '</p>';
		return;
	}
	echo '<table class="widefat striped" style="max-width:40em;margin-bottom:1.5em;"><caption style="text-align:left;padding:8px 0;">' . esc_html( $caption ) . '</caption>';
	echo '<thead><tr><th scope="col">' . esc_html( $col ) . '</th><th scope="col">' . esc_html__( 'Posts', 'post-kinds-rescue' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $label => $count ) {
		echo '<tr><th scope="row">' . esc_html( (string) $label ) . '</th><td>' . esc_html( number_format_i18n( (int) $count ) ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

/**
 * Shows the result of the last Fix or Undo, and moves focus to it.
 */
function render_result_notice(): void {
	// phpcs:disable WordPress.Security.NonceVerification -- Display only.
	if ( empty( $_GET['pkr_done'] ) ) {
		return;
	}
	$what = sanitize_key( wp_unslash( $_GET['pkr_done'] ) );
	$n    = isset( $_GET['pkr_n'] ) ? absint( $_GET['pkr_n'] ) : 0;
	$left    = isset( $_GET['pkr_left'] ) ? absint( $_GET['pkr_left'] ) : 0;
	$skipped = isset( $_GET['pkr_skipped'] ) ? absint( $_GET['pkr_skipped'] ) : 0;
	// phpcs:enable
	if ( 'details' === $what ) {
		if ( $left ) {
			/* translators: 1: posts done, 2: posts left. */
			$msg = sprintf( __( 'Added details to %1$s posts so far. %2$s are left: press the Add details button in step 2 again to continue.', 'post-kinds-rescue' ), number_format_i18n( $n ), number_format_i18n( $left ) );
		} else {
			/* translators: %s: number of posts. */
			$msg = sprintf( _n( 'All done: details added to %s post. You can undo this with the Remove added details button in step 2.', 'All done: details added to %s posts. You can undo this with the Remove added details button in step 2.', $n, 'post-kinds-rescue' ), number_format_i18n( $n ) );
		}
	} elseif ( 'details_undone' === $what ) {
		/* translators: %s: number of posts. */
		$msg = sprintf( _n( 'Details removed from %s post.', 'Details removed from %s posts.', $n, 'post-kinds-rescue' ), number_format_i18n( $n ) );
		if ( $skipped ) {
			/* translators: %s: number of posts. */
			$msg .= ' ' . sprintf( _n( '%s post was left as it is because it was edited after the details were added.', '%s posts were left as they are because they were edited after the details were added.', $skipped, 'post-kinds-rescue' ), number_format_i18n( $skipped ) );
		}
		if ( $left ) {
			/* translators: %s: number of posts. */
			$msg .= ' ' . sprintf( _n( '%s post is left. Press Remove added details again to finish.', '%s posts are left. Press Remove added details again to finish.', $left, 'post-kinds-rescue' ), number_format_i18n( $left ) );
		}
	} elseif ( 'preview' === $what ) {
		$total = count( build_plan( request_options() )['changes'] );
		/* translators: %s: number of posts. */
		$msg = sprintf( _n( 'Preview updated: %s post can be fixed with these settings.', 'Preview updated: %s posts can be fixed with these settings.', $total, 'post-kinds-rescue' ), number_format_i18n( $total ) );
	} elseif ( 'undone' === $what ) {
		/* translators: %s: number of posts. */
		$msg = sprintf( _n( 'Undo finished: %s post put back.', 'Undo finished: %s posts put back.', $n, 'post-kinds-rescue' ), number_format_i18n( $n ) );
		if ( $skipped ) {
			/* translators: %s: number of posts. */
			$msg .= ' ' . sprintf( _n( '%s post was left as it is, because its kind was changed again after the fix or its earlier kind no longer exists.', '%s posts were left as they are, because their kind was changed again after the fix or their earlier kind no longer exists.', $skipped, 'post-kinds-rescue' ), number_format_i18n( $skipped ) );
		}
		if ( $left ) {
			/* translators: %s: number of posts. */
			$msg .= ' ' . sprintf( _n( '%s post is left. Press Undo kind changes again to finish.', '%s posts are left. Press Undo kind changes again to finish.', $left, 'post-kinds-rescue' ), number_format_i18n( $left ) );
		}
	} else {
		/* translators: %s: number of posts. */
		if ( $left ) {
			/* translators: 1: posts fixed, 2: posts left. */
			$msg = sprintf( __( 'Fixed %1$s posts so far. %2$s are left: press the Change the kind button in step 1 again to continue.', 'post-kinds-rescue' ), number_format_i18n( $n ), number_format_i18n( $left ) );
		} else {
			/* translators: %s: number of posts. */
			$msg = sprintf( _n( 'All done: %s post fixed. Next, you can show the details in step 2, or undo with the Undo kind changes button.', 'All done: %s posts fixed. Next, you can show the details in step 2, or undo with the Undo kind changes button.', $n, 'post-kinds-rescue' ), number_format_i18n( $n ) );
		}
	}
	// "inline" stops WordPress from moving the notice, which used to drop the focus.
	// The message is a focused heading: screen readers read a heading out when it gets focus,
	// and "inline" stops WordPress from moving the notice (which used to drop the focus).
	echo '<div class="notice notice-success inline"><h2 id="pkr-result" tabindex="-1" style="font-size:1em;margin:.75em 0;">' . esc_html( $msg ) . '</h2></div>';
	echo '<script>window.addEventListener("load",function(){var n=document.getElementById("pkr-result");if(!n){return;}setTimeout(function(){n.focus();},300);setTimeout(function(){if(window.wp&&wp.a11y&&wp.a11y.speak){wp.a11y.speak(n.textContent,"polite");}},1200);});</script>';
}

/* -------------------------------------------------------------------------
 * WP-CLI: wp pk-rescue scan | preview | apply | undo
 * ---------------------------------------------------------------------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Options from CLI flags.
	 *
	 * @param array $assoc Associative args.
	 * @return array
	 */
	function cli_options( array $assoc ): array {
		$raw = array( 'untitled_note' => ! empty( $assoc['untitled-note'] ) );
		if ( isset( $assoc['replace'] ) ) {
			$raw['replace'] = str_replace( 'none', NO_KIND, explode( ',', (string) $assoc['replace'] ) );
		}
		if ( isset( $assoc['skip'] ) ) {
			$raw['skip'] = explode( ',', (string) $assoc['skip'] );
		}
		return options( $raw );
	}

	\WP_CLI::add_command(
		'pk-rescue scan',
		static function () {
			$inv = inventory();
			\WP_CLI::log( 'Posts per kind:' );
			foreach ( $inv['kinds'] as $kind => $n ) {
				\WP_CLI::log( sprintf( '  %-14s %d', NO_KIND === $kind ? '(no kind)' : $kind, $n ) );
			}
			\WP_CLI::log( 'Posts per response data field:' );
			foreach ( $inv['keys'] as $key => $n ) {
				\WP_CLI::log( sprintf( '  %-24s %d', $key, $n ) );
			}
		},
		array( 'shortdesc' => 'Count posts per kind and per mf2_ data field.' )
	);

	\WP_CLI::add_command(
		'pk-rescue preview',
		static function ( $args, $assoc ) {
			$plan = build_plan( cli_options( $assoc ) );
			$rows = array();
			foreach ( $plan['changes'] as $r ) {
				$rows[] = array( 'id' => $r['id'], 'current' => $r['current'], 'new' => $r['new'], 'reason' => $r['reason'], 'status' => 'change' );
			}
			foreach ( $plan['review'] as $r ) {
				$rows[] = array( 'id' => $r['id'], 'current' => $r['current'], 'new' => '', 'reason' => $r['reason'], 'status' => 'needs review' );
			}
			$format = $assoc['format'] ?? 'table';
			if ( $rows || 'table' !== $format ) {
				\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'current', 'new', 'reason', 'status' ) );
			}
			if ( 'table' === $format ) {
				\WP_CLI::log( sprintf( 'Checked %d posts. %d to change, %d need review.', $plan['checked'], count( $plan['changes'] ), count( $plan['review'] ) ) );
				if ( $plan['missing_terms'] ) {
					\WP_CLI::warning( 'Kinds missing on this site, skipped: ' . implode( ', ', $plan['missing_terms'] ) );
				}
			}
		},
		array(
			'shortdesc' => 'Show the changes without saving. Flags: --replace=none,article,note --untitled-note --skip=photo,video --format=table|csv|json',
		)
	);

	\WP_CLI::add_command(
		'pk-rescue apply',
		static function ( $args, $assoc ) {
			$plan = build_plan( cli_options( $assoc ) );
			$n    = count( $plan['changes'] );
			if ( ! $n ) {
				\WP_CLI::success( 'Nothing to change.' );
				return;
			}
			\WP_CLI::confirm( sprintf( 'Change the kind of %d posts?', $n ), $assoc );
			$done = apply_changes( $plan['changes'] );
			\WP_CLI::success( sprintf( 'Changed %d of %d posts. Run "wp pk-rescue undo" to reverse.', $done, $n ) );
		},
		array( 'shortdesc' => 'Save the changes. Same flags as preview, plus --yes.' )
	);

	\WP_CLI::add_command(
		'pk-rescue undo',
		static function ( $args, $assoc ) {
			$n = count( changed_ids() );
			if ( ! $n ) {
				\WP_CLI::success( 'Nothing to undo.' );
				return;
			}
			\WP_CLI::confirm( sprintf( 'Put %d posts back to their earlier kind?', $n ), $assoc );
			$res = undo_changes();
			\WP_CLI::success( sprintf( 'Restored %d posts.', $res['done'] ) );
			if ( $res['skipped'] ) {
				\WP_CLI::warning( sprintf( '%d posts were left as they are: their kind was changed after the fix, or their earlier kind no longer exists.', $res['skipped'] ) );
			}
		},
		array( 'shortdesc' => 'Reverse every change this tool made. --yes to skip the question.' )
	);
}

/* -------------------------------------------------------------------------
 * Step 2: show the details (liked link, song, book …) on each post.
 * ---------------------------------------------------------------------- */

require_once __DIR__ . '/includes/details.php';

/**
 * Posts that still have details waiting to be added.
 *
 * @return int
 */
function details_left(): int {
	$ids = array();
	foreach ( array_merge( Details\build_details_plan()['items'], Details\build_cards_plan()['items'] ) as $item ) {
		$ids[ $item['id'] ] = true;
	}
	return count( $ids );
}

add_action(
	'admin_post_pkr_details_apply',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'post-kinds-rescue' ) );
		}
		check_admin_referer( 'pkr_details' );
		$deadline = start_clock();
		$ids      = array();
		$meta     = Details\build_details_plan();
		Details\apply_details( $meta, $deadline );
		foreach ( $meta['items'] as $item ) {
			$ids[ $item['id'] ] = true;
		}
		if ( microtime( true ) < $deadline ) {
			$cards = Details\build_cards_plan();
			Details\apply_cards( $cards, $deadline );
			foreach ( $cards['items'] as $item ) {
				$ids[ $item['id'] ] = true;
			}
		}
		$left = details_left();
		redirect_back( request_options(), array( 'pkr_done' => 'details', 'pkr_n' => max( 0, count( $ids ) - $left ), 'pkr_left' => $left ) );
	}
);

add_action(
	'admin_post_pkr_details_undo',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'post-kinds-rescue' ) );
		}
		check_admin_referer( 'pkr_details_undo' );
		$deadline = start_clock();
		$cards    = Details\undo_cards( $deadline );
		$meta     = Details\undo_details( $deadline );
		$left     = count( array_diff( array_unique( array_merge( Details\written_ids(), details_card_ids() ) ), $cards['skipped_ids'] ) );
		redirect_back(
			request_options(),
			array(
				'pkr_done'    => 'details_undone',
				'pkr_n'       => max( $meta, $cards['done'] ),
				'pkr_skipped' => $cards['skipped'],
				'pkr_left'    => max( 0, $left ),
			)
		);
	}
);

/**
 * IDs of posts that got a card from this tool and still have an undo record.
 *
 * @return int[]
 */
function details_card_ids(): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_pk_rescue_original_content'" ) );
}

/**
 * Renders the Show details section.
 *
 * @param array $opts Current options (carried through redirects).
 */
function render_details_section( array $opts ): void {
	$plan  = Details\describe_details_plan();
	$undo  = count( array_unique( array_merge( Details\written_ids(), details_card_ids() ) ) );
	?>
	<h2 id="pkr-details" tabindex="-1"><?php esc_html_e( 'Step 2: Show the details on each post', 'post-kinds-rescue' ); ?></h2>
	<p><?php esc_html_e( 'The new plugin does not read the details the classic plugin saved, such as the liked link, the song you listened to or the book you read. This step copies those details into the new plugin and adds a short summary box at the top of each post, for example the title of the liked page with a link to it, so the details show again. The summary box is the same one the new plugin adds when you write a new post of that kind. Do this after step 1.', 'post-kinds-rescue' ); ?></p>
	<p><?php esc_html_e( 'Your own text stays exactly as it is, below the summary. Posts are saved quietly: the last-modified date does not change, and no notifications or social posts are sent again. Undo puts each post back exactly, unless you edit it after this step.', 'post-kinds-rescue' ); ?></p>
	<?php if ( ! $plan['count'] ) : ?>
		<p><?php esc_html_e( 'No posts need details added right now.', 'post-kinds-rescue' ); ?></p>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pkr_details_apply">
			<?php wp_nonce_field( 'pkr_details' ); ?>
			<?php option_fields( $opts ); ?>
			<?php /* translators: %s: number of posts. */ ?>
			<p><button type="submit" class="button button-primary" id="pkr-details-button"><?php echo esc_html( sprintf( _n( 'Add details to %s post now', 'Add details to up to %s posts now', $plan['count'], 'post-kinds-rescue' ), number_format_i18n( $plan['count'] ) ) ); ?></button></p>
		</form>
		<table class="widefat striped" style="max-width:40em;">
			<?php /* translators: %s: number of posts. */ ?>
			<caption style="text-align:left;padding:8px 0;"><?php echo esc_html( sprintf( _n( 'Details waiting to be added: %s post', 'Details waiting to be added: %s posts', $plan['count'], 'post-kinds-rescue' ), number_format_i18n( $plan['count'] ) ) ); ?></caption>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Kind', 'post-kinds-rescue' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Posts getting details', 'post-kinds-rescue' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Posts getting a summary box', 'post-kinds-rescue' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $plan['by_kind'] as $kind => $row ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( kind_name( (string) $kind ) ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $row['meta'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $row['cards'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php if ( $undo ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pkr_details_undo">
			<?php wp_nonce_field( 'pkr_details_undo' ); ?>
			<?php option_fields( $opts ); ?>
			<?php /* translators: %s: number of posts. */ ?>
			<p><?php echo esc_html( sprintf( _n( '%s post has details added by this tool.', '%s posts have details added by this tool.', $undo, 'post-kinds-rescue' ), number_format_i18n( $undo ) ) ); ?></p>
			<p><button type="submit" class="button" id="pkr-details-undo-button"><?php esc_html_e( 'Remove added details', 'post-kinds-rescue' ); ?></button></p>
		</form>
	<?php endif; ?>
	<?php
}

