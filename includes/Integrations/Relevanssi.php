<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Undo_Store;
use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Tool_Refusal;
use Royal_MCP\MCP\Support\Form_Entry_Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Relevanssi search: run a search the way visitors get it, check the index,
 * read search statistics (totals only), re-index one post, change synonyms
 * and add stopwords.
 *
 * Searches run by these tools are never written to Relevanssi's search log.
 * The statistics tools return each query with its count and average hits,
 * never who searched, from where, or when.
 */
class Relevanssi {

	/** Index rows kept for undoing a re-index; a post with more is re-indexed without an undo token. */
	const MAX_UNDO_ROWS = 2000;

	/** Posts re-indexed when a stopword is undone; with more, the reply says to re-index. */
	const MAX_REINDEX_ON_UNDO = 200;

	/** Index table columns, in table order. */
	const INDEX_COLUMNS = [ 'doc', 'term', 'term_reverse', 'content', 'title', 'comment', 'tag', 'link', 'author', 'category', 'excerpt', 'taxonomy', 'customfield', 'mysqlcolumn', 'taxonomy_detail', 'customfield_detail', 'mysqlcolumn_detail', 'type', 'item' ];

	public static function is_available() {
		return function_exists( 'relevanssi_do_query' ) && function_exists( 'relevanssi_index_doc' );
	}

	/** Relevanssi's settings capability while it is active, manage_options otherwise. */
	public static function options_cap() {
		$cap = self::is_available() ? apply_filters( 'relevanssi_options_capability', 'manage_options' ) : 'manage_options';
		return is_string( $cap ) && '' !== $cap ? $cap : 'manage_options';
	}

	/** Relevanssi's search statistics capability while it is active, manage_options otherwise. */
	public static function searches_cap() {
		$cap = self::is_available() ? apply_filters( 'relevanssi_user_searches_capability', 'edit_pages' ) : 'manage_options';
		return is_string( $cap ) && '' !== $cap ? $cap : 'manage_options';
	}

	/** The capability a tool needs. */
	public static function cap_for( $name ) {
		switch ( (string) $name ) {
			case 'relevanssi_search':
				return 'edit_posts';
			case 'relevanssi_list_top_searches':
			case 'relevanssi_list_zero_hit_searches':
				return self::searches_cap();
		}
		return self::options_cap();
	}

	public static function get_tools() {
		$window = Undo_Store::window_label();
		return [
			[
				'name'        => 'relevanssi_search',
				'description' => 'Search the site with Relevanssi, the way visitors get results: relevance order, synonyms and stopwords applied. Returns post_id, title, post_type, url, score and a short excerpt. Only posts you can read are returned. These searches are not written to the site\'s search log.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'query'      => [ 'type' => 'string', 'description' => 'Search words, up to 200 characters.' ],
						'post_types' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Optional post types to search. Default: every type Relevanssi indexes.' ],
						'per_page'   => [ 'type' => 'integer', 'description' => 'Results per page, 1-50. Default 10.' ],
						'page'       => [ 'type' => 'integer', 'description' => 'Page number, 1-indexed. Default 1.' ],
					],
					'required'   => [ 'query' ],
				],
			],
			[
				'name'        => 'relevanssi_get_index_status',
				'description' => 'Relevanssi index health: posts in the index, posts that should be indexed, how many are missing (with up to 20 post IDs), the post types indexed, whether the last full build finished, whether search logging is on, and how many synonyms and stopwords are set. Read-only.',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
			[
				'name'        => 'relevanssi_list_top_searches',
				'description' => 'Most frequent searches from Relevanssi\'s search log over the last N days: query, number of searches, average hits. Totals only: never who searched, IP addresses or times. Email addresses in queries are hidden. Needs search logging switched on in Relevanssi.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'days'  => [ 'type' => 'integer', 'description' => 'Look-back window in days, 1-365. Default 30.' ],
						'limit' => [ 'type' => 'integer', 'description' => 'Queries to return, 1-100. Default 20.' ],
					],
				],
			],
			[
				'name'        => 'relevanssi_list_zero_hit_searches',
				'description' => 'Searches that found nothing over the last N days, from Relevanssi\'s search log: query and number of searches. Use it to find missing content or synonyms to add. Totals only: never who searched, IP addresses or times. Email addresses in queries are hidden.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'days'  => [ 'type' => 'integer', 'description' => 'Look-back window in days, 1-365. Default 30.' ],
						'limit' => [ 'type' => 'integer', 'description' => 'Queries to return, 1-100. Default 20.' ],
					],
				],
			],
			[
				'name'        => 'relevanssi_reindex_post',
				'description' => 'Rebuild the Relevanssi index entry of one post (for example after changing it outside WordPress). Needs permission to edit the post and to manage Relevanssi. dry_run reports the post\'s index state without changing it. Returns an undo token (valid ' . $window . ') that puts the earlier index entry back; undo refuses with edited_since_write if the post was re-indexed again since, force: true restores anyway. There is no full index rebuild tool: use Relevanssi\'s settings page for that.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer', 'description' => 'Post ID.' ],
						'dry_run' => [ 'type' => 'boolean', 'description' => 'Report without changing anything. Default false.' ],
					],
					'required'   => [ 'post_id' ],
				],
			],
			[
				'name'        => 'relevanssi_update_synonyms',
				'description' => 'Change Relevanssi synonyms for the site language. Each pair {from, to} makes a search for "from" also find "to". mode append (default) adds pairs, replace sets the whole list, remove deletes the pairs given. dry_run shows the list before and after without saving. Returns an undo token (valid ' . $window . '); undo refuses with edited_since_write if the synonyms changed again since, force: true restores the earlier list anyway.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'synonyms' => [
							'type'        => 'array',
							'description' => 'Pairs, up to 500. Words up to 100 characters, without ; or =.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'from' => [ 'type' => 'string' ],
									'to'   => [ 'type' => 'string' ],
								],
								'required'   => [ 'from', 'to' ],
							],
						],
						'mode'     => [ 'type' => 'string', 'enum' => [ 'append', 'replace', 'remove' ], 'description' => 'Default append.' ],
						'dry_run'  => [ 'type' => 'boolean', 'description' => 'Report without saving. Default false.' ],
					],
					'required'   => [ 'synonyms' ],
				],
			],
			[
				'name'        => 'relevanssi_add_stopword',
				'description' => 'Add stopwords to Relevanssi for the site language: words searches ignore. Relevanssi removes each new stopword from the index at once, so posts stop matching it. dry_run reports how many posts contain each word without saving. Returns an undo token (valid ' . $window . '); undo restores the earlier list and puts back the index entries the words had (with more than ' . self::MAX_UNDO_ROWS . ' entries it re-indexes up to ' . self::MAX_REINDEX_ON_UNDO . ' posts that had them, otherwise it says to re-index). Undo refuses with edited_since_write if the stopwords changed again since; force: true restores anyway.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'words'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Words to add, up to 50, each up to 50 characters, no commas.' ],
						'dry_run' => [ 'type' => 'boolean', 'description' => 'Report without saving. Default false.' ],
					],
					'required'   => [ 'words' ],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		$args = is_array( $args ) ? $args : [];
		if ( ! current_user_can( self::cap_for( $name ) ) ) {
			throw new \Exception( 'You do not have permission to use this Relevanssi tool.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Relevanssi is not active' );
		}

		switch ( $name ) {
			case 'relevanssi_search':
				return self::search( $args );
			case 'relevanssi_get_index_status':
				return self::index_status();
			case 'relevanssi_list_top_searches':
				return self::log_totals( $args, false );
			case 'relevanssi_list_zero_hit_searches':
				return self::log_totals( $args, true );
			case 'relevanssi_reindex_post':
				return self::reindex_post( $args );
			case 'relevanssi_update_synonyms':
				return self::update_synonyms( $args );
			case 'relevanssi_add_stopword':
				return self::add_stopwords( $args );
			default:
				throw new \Exception( 'Unknown Relevanssi tool: ' . esc_html( $name ) );
		}
	}

	// ============ Reads ============

	private static function search( array $args ) {
		$query = $args['query'] ?? '';
		if ( ! is_string( $query ) ) {
			throw new \Exception( 'query must be text.' );
		}
		$query = trim( sanitize_text_field( $query ) );
		if ( '' === $query ) {
			throw new \Exception( 'query is required.' );
		}
		if ( strlen( $query ) > 200 ) {
			throw new \Exception( 'query is limited to 200 characters.' );
		}
		$per_page = self::int_arg( $args, 'per_page', 10, 1, 50 );
		$page     = self::int_arg( $args, 'page', 1, 1, 1000 );

		$vars = [
			's'              => $query,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'relevanssi'     => true,
		];
		if ( isset( $args['post_types'] ) ) {
			if ( ! is_array( $args['post_types'] ) || count( $args['post_types'] ) > 20 ) {
				throw new \Exception( 'post_types must be a list of up to 20 post type names.' );
			}
			$types = [];
			foreach ( $args['post_types'] as $t ) {
				if ( ! is_string( $t ) || ! post_type_exists( sanitize_key( $t ) ) ) {
					throw new \Exception( 'Unknown post type in post_types. Use wp_get_post_types to list them.' );
				}
				$types[] = sanitize_key( $t );
			}
			if ( $types ) {
				$vars['post_type'] = array_values( array_unique( $types ) );
			}
		}

		// Searches made through these tools stay out of the site's search log.
		$no_log = static function () {
			return false;
		};
		add_filter( 'relevanssi_ok_to_log', $no_log, PHP_INT_MAX );
		try {
			$wp_query = new \WP_Query();
			$wp_query->parse_query( $vars );
			$posts = relevanssi_do_query( $wp_query );
		} finally {
			remove_filter( 'relevanssi_ok_to_log', $no_log, PHP_INT_MAX );
		}

		$results = [];
		$hidden  = 0;
		foreach ( (array) $posts as $hit ) {
			$id = is_object( $hit ) && isset( $hit->ID ) ? (int) $hit->ID : ( is_numeric( $hit ) ? (int) $hit : 0 );
			$post = $id > 0 ? get_post( $id ) : null;
			if ( ! $post || ! current_user_can( 'read_post', $id ) || post_password_required( $post ) ) {
				$hidden++;
				continue;
			}
			$excerpt = is_object( $hit ) && ! empty( $hit->post_excerpt ) ? (string) $hit->post_excerpt : (string) $post->post_excerpt;
			if ( '' === trim( wp_strip_all_tags( $excerpt ) ) ) {
				$excerpt = wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ), 40 );
			}
			$results[] = [
				'post_id'   => $id,
				'title'     => (string) $post->post_title,
				'post_type' => (string) $post->post_type,
				'url'       => (string) get_permalink( $post ),
				'score'     => is_object( $hit ) && isset( $hit->relevance_score ) ? (float) $hit->relevance_score : null,
				'excerpt'   => mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $excerpt ) ) ), 0, 300 ),
			];
		}
		$total = (int) $wp_query->found_posts;
		$out   = [
			'query'       => $query,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'results'     => $results,
			'logged'      => false,
		];
		if ( $hidden ) {
			$out['not_shown'] = $hidden;
		}
		return $out;
	}

	private static function index_status() {
		global $wpdb;
		$table = self::index_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexed = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT doc) FROM {$table} WHERE doc > 0" );
		$total   = function_exists( 'relevanssi_count_total_posts' ) ? (int) relevanssi_count_total_posts() : null;
		$missing = function_exists( 'relevanssi_count_missing_posts' ) ? (int) relevanssi_count_missing_posts() : null;
		$sample  = [];
		if ( $missing && function_exists( 'relevanssi_generate_indexing_query' ) && function_exists( 'relevanssi_valid_status_array' ) && function_exists( 'relevanssi_post_type_restriction' ) ) {
			$sql    = relevanssi_generate_indexing_query( relevanssi_valid_status_array(), true, relevanssi_post_type_restriction(), 'LIMIT 20' );
			$sample = array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Relevanssi's own query
		}
		$types = get_option( 'relevanssi_index_post_types', [] );
		return [
			'indexed_posts'      => $indexed,
			'indexable_posts'    => $total,
			'missing_count'      => $missing,
			'missing_sample'     => $sample,
			'index_post_types'   => is_array( $types ) ? array_values( array_map( 'strval', $types ) ) : [],
			'last_build_done'    => 'done' === get_option( 'relevanssi_indexed' ),
			'search_logging'     => 'on' === get_option( 'relevanssi_log_queries' ),
			'language'           => self::language(),
			'synonym_count'      => count( self::parse_synonyms( self::current_synonyms() ) ),
			'stopword_count'     => count( self::current_stopwords() ),
			'premium'            => function_exists( 'relevanssi_premium_init' ),
		];
	}

	private static function log_totals( array $args, $zero_hits ) {
		global $wpdb;
		$days  = self::int_arg( $args, 'days', 30, 1, 365 );
		$limit = self::int_arg( $args, 'limit', 20, 1, 100 );
		$log   = self::log_table();
		$out   = [
			'days'           => $days,
			'search_logging' => 'on' === get_option( 'relevanssi_log_queries' ),
			'queries'        => [],
		];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $log ) ) ) !== $log ) {
			$out['total_searches'] = 0;
			return $out;
		}
		$hits_where = $zero_hits ? ' AND hits = 0' : '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query, COUNT(*) AS searches, AVG(hits) AS avg_hits FROM {$log} WHERE time > DATE_SUB(NOW(), INTERVAL %d DAY){$hits_where} GROUP BY query ORDER BY searches DESC, query ASC LIMIT %d",
				$days,
				$limit
			),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log} WHERE time > DATE_SUB(NOW(), INTERVAL %d DAY){$hits_where}", $days ) );
		$distinct = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT query) FROM {$log} WHERE time > DATE_SUB(NOW(), INTERVAL %d DAY){$hits_where}", $days ) );
		// phpcs:enable
		foreach ( (array) $rows as $r ) {
			$row = [
				'query'    => (string) Form_Entry_Privacy::free_text( (string) $r['query'], [] ),
				'searches' => (int) $r['searches'],
			];
			if ( ! $zero_hits ) {
				$row['avg_hits'] = round( (float) $r['avg_hits'], 1 );
			}
			$out['queries'][] = $row;
		}
		$out['total_searches']   = $total;
		$out['distinct_queries'] = $distinct;
		return $out;
	}

	// ============ Writes ============

	private static function reindex_post( array $args ) {
		$id   = Form_Entry_Privacy::positive_int( $args['post_id'] ?? null );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post ) {
			throw new \Exception( 'Post not found.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			throw new \Exception( 'You do not have permission to edit this post.' );
		}
		$types   = (array) get_option( 'relevanssi_index_post_types', [] );
		$indexed = in_array( $post->post_type, $types, true ) || ( 'attachment' === $post->post_type && in_array( 'attachment', $types, true ) );
		$before  = self::doc_rows( $id );
		if ( ! empty( $args['dry_run'] ) ) {
			return [
				'dry_run'          => true,
				'post_id'          => $id,
				'post_type'        => $post->post_type,
				'post_type_indexed' => $indexed,
				'index_terms'      => count( $before ),
			];
		}
		if ( ! $indexed ) {
			throw new \Exception( sprintf( 'Relevanssi does not index the post type %s. Add it in Relevanssi\'s indexing settings first.', esc_html( $post->post_type ) ) );
		}
		$result = function_exists( 'relevanssi_publish' ) ? relevanssi_publish( $id, true ) : relevanssi_index_doc( $id, true, '', true );
		$after  = self::doc_rows( $id );
		$struct = [
			'post_id'            => $id,
			'index_terms_before' => count( $before ),
			'index_terms_after'  => count( $after ),
			'result'             => is_numeric( $result ) ? ( (int) $result >= 0 ? 'indexed' : 'failed' ) : (string) $result,
		];
		if ( self::fingerprint( $before ) === self::fingerprint( $after ) ) {
			return Envelope::success( sprintf( 'Post %d\'s index entry was already up to date; nothing changed.', $id ), $struct + [ 'changed' => false ] );
		}
		$struct['changed'] = true;
		if ( count( $before ) > self::MAX_UNDO_ROWS ) {
			$struct['warnings'] = [ sprintf( 'No undo token: the earlier index entry had more than %d terms.', self::MAX_UNDO_ROWS ) ];
			return Envelope::success( sprintf( 'Re-indexed post %d.', $id ), $struct );
		}
		$undo = Undo_Store::store( [
			'op'           => 'relevanssi_reindex_post',
			'summary'      => sprintf( 'Put back the earlier Relevanssi index entry of post %d', $id ),
			'target'       => [ 'post_id' => $id ],
			'pre_op_state' => [ 'rows' => $before, 'written_fingerprint' => self::fingerprint( $after ) ],
		] );
		return Envelope::success( sprintf( 'Re-indexed post %d.', $id ), $struct, $undo );
	}

	private static function update_synonyms( array $args ) {
		$mode = $args['mode'] ?? 'append';
		if ( ! is_string( $mode ) || ! in_array( $mode, [ 'append', 'replace', 'remove' ], true ) ) {
			throw new \Exception( 'mode must be append, replace or remove.' );
		}
		if ( ! isset( $args['synonyms'] ) || ! is_array( $args['synonyms'] ) ) {
			throw new \Exception( 'synonyms must be a list of {from, to} pairs.' );
		}
		if ( count( $args['synonyms'] ) > 500 ) {
			throw new \Exception( 'Up to 500 synonym pairs per call.' );
		}
		if ( ! $args['synonyms'] && 'replace' !== $mode ) {
			throw new \Exception( 'Pass at least one synonym pair.' );
		}
		$given = [];
		foreach ( $args['synonyms'] as $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair['from'], $pair['to'] ) || ! is_string( $pair['from'] ) || ! is_string( $pair['to'] ) ) {
				throw new \Exception( 'Each synonym must be {from, to} with text values.' );
			}
			$from = self::synonym_word( $pair['from'] );
			$to   = self::synonym_word( $pair['to'] );
			$given[ $from . '=' . $to ] = [ 'from' => $from, 'to' => $to ];
		}

		$language = self::language();
		$prior    = self::current_synonyms();
		$current  = [];
		foreach ( self::parse_synonyms( $prior ) as $p ) {
			$current[ $p['from'] . '=' . $p['to'] ] = $p;
		}
		if ( 'replace' === $mode ) {
			$next = $given;
		} elseif ( 'append' === $mode ) {
			$next = $current + $given;
		} else {
			$next = array_diff_key( $current, $given );
		}
		$applied = implode( ';', array_keys( $next ) );
		if ( strlen( $applied ) > 50000 ) {
			throw new \Exception( 'The synonym list would be longer than 50000 characters.' );
		}
		$struct = [
			'language'      => $language,
			'mode'          => $mode,
			'before_count'  => count( $current ),
			'after_count'   => count( $next ),
			'added'         => array_values( array_diff_key( $next, $current ) ),
			'removed'       => array_values( array_diff_key( $current, $next ) ),
		];
		if ( ! empty( $args['dry_run'] ) ) {
			return [ 'dry_run' => true ] + $struct + [ 'after' => array_values( $next ) ];
		}
		if ( null !== $prior && self::canonical_synonyms( $prior ) === $applied ) {
			return Envelope::success( 'The synonyms were already set this way; nothing changed.', $struct + [ 'changed' => false ] );
		}
		self::write_synonyms( $applied );
		$now = self::current_synonyms();
		if ( $now !== $applied ) {
			throw new \Exception( 'Relevanssi did not keep the new synonyms.' );
		}
		$undo = Undo_Store::store( [
			'op'           => 'relevanssi_update_synonyms',
			'summary'      => sprintf( 'Restore the Relevanssi synonyms (%s)', $language ),
			'target'       => [ 'relevanssi_setting' => 'synonyms', 'language' => $language ],
			'pre_op_state' => [ 'prior_value' => $prior, 'applied_value' => $applied ],
		] );
		return Envelope::success(
			sprintf( 'Synonyms updated: %d added, %d removed, %d in total.', count( $struct['added'] ), count( $struct['removed'] ), count( $next ) ),
			$struct + [ 'changed' => true ],
			$undo
		);
	}

	private static function add_stopwords( array $args ) {
		if ( ! isset( $args['words'] ) || ! is_array( $args['words'] ) || ! $args['words'] ) {
			throw new \Exception( 'words must be a list of words.' );
		}
		if ( count( $args['words'] ) > 50 ) {
			throw new \Exception( 'Up to 50 words per call.' );
		}
		$prior_list = self::current_stopwords();
		$words      = [];
		$skipped    = [];
		foreach ( $args['words'] as $w ) {
			if ( ! is_string( $w ) ) {
				throw new \Exception( 'Each word must be text.' );
			}
			if ( false !== strpos( $w, ',' ) ) {
				throw new \Exception( 'Each word must be 1-50 characters without commas.' );
			}
			$n = self::stopword_form( $w );
			// Relevanssi ignores empty words and "0".
			if ( '' === $n || '0' === $n ) {
				$skipped[] = $w;
				continue;
			}
			if ( mb_strlen( $n ) > 50 ) {
				throw new \Exception( 'Each word must be 1-50 characters without commas.' );
			}
			$words[ $n ] = true;
		}
		$words   = array_keys( $words );
		$new     = array_values( array_diff( $words, $prior_list ) );
		$already = array_values( array_intersect( $words, $prior_list ) );
		$docs    = $new ? self::docs_with_terms( $new, self::MAX_REINDEX_ON_UNDO + 1 ) : [];
		$struct  = [
			'language'        => self::language(),
			'added'           => $new,
			'already_present' => $already,
			'posts_affected'  => count( $docs ) > self::MAX_REINDEX_ON_UNDO ? 'more than ' . self::MAX_REINDEX_ON_UNDO : count( $docs ),
		];
		if ( $skipped ) {
			$struct['skipped'] = $skipped;
		}
		if ( ! empty( $args['dry_run'] ) ) {
			return [ 'dry_run' => true ] + $struct;
		}
		if ( ! $new ) {
			return Envelope::success( 'No new stopword to add; nothing changed.', $struct + [ 'changed' => false ] );
		}

		// Everything undo needs is saved before Relevanssi changes the list or the index.
		$prior_raw = self::stopwords_raw();
		$expected  = implode( ',', array_filter( array_merge( null === $prior_raw || '' === $prior_raw ? [] : explode( ',', $prior_raw ), $new ) ) );
		$rows      = self::term_rows( $new );
		$snapshot  = static function ( array $kept, $applied ) use ( $prior_raw, $rows, $docs ) {
			return [
				'op'           => 'relevanssi_add_stopword',
				'summary'      => sprintf( 'Remove the stopword(s) %s and put their index entries back', implode( ', ', $kept ) ),
				'target'       => [ 'relevanssi_setting' => 'stopwords', 'language' => self::language() ],
				'pre_op_state' => [
					'prior_value'   => $prior_raw,
					'applied_value' => $applied,
					'words'         => $kept,
					'rows'          => null === $rows ? null : array_values( array_filter( $rows, static function ( $r ) use ( $kept ) {
						return in_array( (string) $r['term'], $kept, true );
					} ) ),
					'docs'          => count( $docs ) > self::MAX_REINDEX_ON_UNDO ? [] : $docs,
					'too_many_docs' => count( $docs ) > self::MAX_REINDEX_ON_UNDO,
				],
			];
		};
		$undo = Undo_Store::store( $snapshot( $new, $expected ) );

		ob_start();
		relevanssi_add_stopword( implode( ',', $new ), false );
		ob_end_clean();

		$now     = self::current_stopwords();
		$kept    = array_values( array_intersect( $new, $now ) );
		$missing = array_values( array_diff( $new, $kept ) );
		$applied = self::stopwords_raw();
		if ( $applied !== $expected || $missing ) {
			Undo_Store::delete( (string) ( $undo['token'] ?? '' ) );
			$undo = $kept ? Undo_Store::store( $snapshot( $kept, $applied ) ) : null;
		}
		$struct['added'] = $kept;
		if ( $missing ) {
			$struct['not_kept'] = $missing;
		}
		if ( ! $kept ) {
			return Envelope::success( 'Relevanssi kept none of the new stopwords; nothing changed.', $struct + [ 'changed' => false ] );
		}
		return Envelope::success(
			sprintf( 'Added %d stopword(s); Relevanssi removed them from the index.%s', count( $kept ), $missing ? ' Not kept: ' . implode( ', ', $missing ) . '.' : '' ),
			$struct + [ 'changed' => true ],
			$undo
		);
	}

	/** A word as Relevanssi stores it as a stopword. */
	private static function stopword_form( $w ) {
		$w = trim( sanitize_text_field( $w ) );
		$w = function_exists( 'relevanssi_strtolower' ) ? relevanssi_strtolower( $w ) : strtolower( $w );
		return trim( stripslashes( (string) $w ) );
	}

	/**
	 * Every index row (posts and anything else Relevanssi indexes) holding the
	 * terms, or null when there are too many to keep for undo.
	 */
	private static function term_rows( array $terms ) {
		global $wpdb;
		$table = self::index_table();
		$in    = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE term IN ({$in})", $terms ) );
		if ( $count > self::MAX_UNDO_ROWS ) {
			return null;
		}
		$cols = '`' . implode( '`, `', self::INDEX_COLUMNS ) . '`';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$cols} FROM {$table} WHERE term IN ({$in})", $terms ), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	/** Undo of a re-index, synonym change or stopword addition. */
	public static function undo( array $snapshot, $force = false ) {
		if ( ! self::is_available() ) {
			throw new \Exception( 'Relevanssi is no longer active, so this change cannot be undone.' );
		}
		if ( ! current_user_can( self::options_cap() ) ) {
			throw new \Exception( 'You do not have permission to change Relevanssi settings.' );
		}
		$op  = (string) ( $snapshot['op'] ?? '' );
		$pre = (array) ( $snapshot['pre_op_state'] ?? [] );

		if ( 'relevanssi_reindex_post' === $op ) {
			$id = (int) ( $snapshot['target']['post_id'] ?? 0 );
			if ( $id <= 0 || ! get_post( $id ) ) {
				throw new \Exception( 'The post no longer exists, so there is no index entry to put back.' );
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				throw new \Exception( 'You do not have permission to edit this post.' );
			}
			$rows = isset( $pre['rows'] ) && is_array( $pre['rows'] ) ? $pre['rows'] : null;
			if ( null === $rows ) {
				throw new \Exception( 'Undo snapshot has no index entry to restore.' );
			}
			if ( ! $force && self::fingerprint( self::doc_rows( $id ) ) !== (string) ( $pre['written_fingerprint'] ?? '' ) ) {
				throw new Tool_Refusal( 'edited_since_write', sprintf( 'Cannot undo: post %d was re-indexed again after this change. The undo token stays valid: undo again with force: true to put the earlier index entry back anyway.', $id ) );
			}
			self::replace_doc_rows( $id, $rows );
			return Envelope::success( sprintf( 'Put back the earlier index entry of post %d.', $id ), [ 'undone' => true, 'op' => $op, 'target' => [ 'post_id' => $id ], 'restored' => true, 'index_terms' => count( $rows ) ] );
		}

		if ( 'relevanssi_update_synonyms' === $op ) {
			$this_lang = (string) ( $snapshot['target']['language'] ?? '' );
			if ( $this_lang !== self::language() ) {
				throw new \Exception( 'This change was made for another site language; switch to it to undo.' );
			}
			if ( ! $force && self::current_synonyms() !== ( $pre['applied_value'] ?? null ) ) {
				throw new Tool_Refusal( 'edited_since_write', 'Cannot undo: the Relevanssi synonyms changed again after this change. The undo token stays valid: undo again with force: true to restore the earlier list anyway.' );
			}
			self::write_synonyms( isset( $pre['prior_value'] ) ? (string) $pre['prior_value'] : null );
			return Envelope::success( 'Restored the earlier Relevanssi synonyms.', [ 'undone' => true, 'op' => $op, 'restored' => true ] );
		}

		if ( 'relevanssi_add_stopword' === $op ) {
			$this_lang = (string) ( $snapshot['target']['language'] ?? '' );
			if ( $this_lang !== self::language() ) {
				throw new \Exception( 'This change was made for another site language; switch to it to undo.' );
			}
			if ( ! $force && self::stopwords_raw() !== ( $pre['applied_value'] ?? null ) ) {
				throw new Tool_Refusal( 'edited_since_write', 'Cannot undo: the Relevanssi stopwords changed again after this change. The undo token stays valid: undo again with force: true to restore the earlier list anyway.' );
			}
			self::write_stopwords_raw( isset( $pre['prior_value'] ) ? (string) $pre['prior_value'] : null );
			$words = array_values( array_filter( array_map( 'strval', (array) ( $pre['words'] ?? [] ) ), 'strlen' ) );
			if ( isset( $pre['rows'] ) && is_array( $pre['rows'] ) && $words ) {
				self::replace_term_rows( $words, $pre['rows'] );
				return Envelope::success( sprintf( 'Restored the earlier stopwords and put back %d index entries.', count( $pre['rows'] ) ), [ 'undone' => true, 'op' => $op, 'restored' => true, 'index_rows_restored' => count( $pre['rows'] ) ] );
			}
			$reindexed = 0;
			foreach ( array_map( 'intval', (array) ( $pre['docs'] ?? [] ) ) as $doc ) {
				if ( $doc > 0 && get_post( $doc ) ) {
					relevanssi_index_doc( $doc, true, function_exists( 'relevanssi_get_custom_fields' ) ? relevanssi_get_custom_fields() : '', true );
					$reindexed++;
				}
			}
			$struct = [ 'undone' => true, 'op' => $op, 'restored' => true, 'posts_reindexed' => $reindexed ];
			if ( ! empty( $pre['too_many_docs'] ) ) {
				$struct['reindex_needed'] = true;
				return Envelope::success( 'Restored the earlier stopwords. Many posts contained these words: rebuild the Relevanssi index so they match again.', $struct );
			}
			return Envelope::success( sprintf( 'Restored the earlier stopwords and re-indexed %d post(s).', $reindexed ), $struct );
		}

		throw new \Exception( 'Unsupported Relevanssi undo operation.' );
	}

	// ============ Helpers ============

	private static function int_arg( array $args, $key, $default, $min, $max ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			return $default;
		}
		$v = $args[ $key ];
		if ( ! is_int( $v ) && ! ( is_string( $v ) && preg_match( '/^-?\d{1,9}$/', $v ) ) ) {
			throw new \Exception( esc_html( $key ) . ' must be a whole number.' );
		}
		return max( $min, min( $max, (int) $v ) );
	}

	private static function index_table() {
		global $wpdb, $relevanssi_variables;
		return isset( $relevanssi_variables['relevanssi_table'] ) ? (string) $relevanssi_variables['relevanssi_table'] : $wpdb->prefix . 'relevanssi';
	}

	private static function log_table() {
		global $wpdb, $relevanssi_variables;
		return isset( $relevanssi_variables['log_table'] ) ? (string) $relevanssi_variables['log_table'] : $wpdb->prefix . 'relevanssi_log';
	}

	private static function language() {
		return function_exists( 'relevanssi_get_current_language' ) ? (string) relevanssi_get_current_language() : (string) get_locale();
	}

	/**
	 * The site language's entry of a Relevanssi per-language option, or null
	 * when none is stored.
	 */
	private static function language_value( $option ) {
		$all = get_option( $option, [] );
		if ( ! is_array( $all ) || ! array_key_exists( self::language(), $all ) ) {
			return null;
		}
		return (string) $all[ self::language() ];
	}

	/** Set (or with null, remove) the site language's entry of a Relevanssi per-language option. */
	private static function write_language_value( $option, $value ) {
		$all = get_option( $option, [] );
		$all = is_array( $all ) ? $all : [];
		if ( null === $value ) {
			unset( $all[ self::language() ] );
		} else {
			$all[ self::language() ] = (string) $value;
		}
		update_option( $option, $all );
	}

	private static function current_synonyms() {
		return self::language_value( 'relevanssi_synonyms' );
	}

	private static function write_synonyms( $value ) {
		self::write_language_value( 'relevanssi_synonyms', $value );
	}

	/** Pairs from a stored synonym string, in order. */
	private static function parse_synonyms( $value ) {
		$out = [];
		foreach ( explode( ';', (string) $value ) as $pair ) {
			$parts = explode( '=', $pair );
			if ( count( $parts ) < 2 || '' === trim( $parts[0] ) || '' === trim( $parts[1] ) ) {
				continue;
			}
			$out[] = [ 'from' => trim( $parts[0] ), 'to' => trim( $parts[1] ) ];
		}
		return $out;
	}

	private static function canonical_synonyms( $value ) {
		$keys = [];
		foreach ( self::parse_synonyms( $value ) as $p ) {
			$keys[ $p['from'] . '=' . $p['to'] ] = true;
		}
		return implode( ';', array_keys( $keys ) );
	}

	private static function synonym_word( $w ) {
		$w = trim( sanitize_text_field( $w ) );
		if ( '' === $w || mb_strlen( $w ) > 100 || false !== strpbrk( $w, ';=' ) ) {
			throw new \Exception( 'Synonym words must be 1-100 characters without ; or =.' );
		}
		return $w;
	}

	/** The stopword string for the site language, or null when none is stored. */
	private static function stopwords_raw() {
		return self::language_value( 'relevanssi_stopwords' );
	}

	private static function write_stopwords_raw( $value ) {
		self::write_language_value( 'relevanssi_stopwords', $value );
	}

	private static function current_stopwords() {
		if ( function_exists( 'relevanssi_fetch_stopwords' ) ) {
			return array_values( array_filter( array_map( 'strval', (array) relevanssi_fetch_stopwords() ), 'strlen' ) );
		}
		return array_values( array_filter( explode( ',', (string) self::stopwords_raw() ), 'strlen' ) );
	}

	/** Post IDs whose index entry holds any of the terms. */
	private static function docs_with_terms( array $terms, $limit ) {
		global $wpdb;
		$table = self::index_table();
		$in    = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT doc FROM {$table} WHERE doc > 0 AND term IN ({$in}) LIMIT %d", array_merge( $terms, [ (int) $limit ] ) ) ) );
	}

	/** One post's index rows in a stable order. */
	private static function doc_rows( $id ) {
		global $wpdb;
		$table = self::index_table();
		$cols  = '`' . implode( '`, `', self::INDEX_COLUMNS ) . '`';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$cols} FROM {$table} WHERE doc = %d ORDER BY term, item, type", (int) $id ), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	private static function fingerprint( array $rows ) {
		return md5( (string) wp_json_encode( array_values( $rows ) ) );
	}

	/** Put back the index rows of some terms exactly as they were. */
	private static function replace_term_rows( array $terms, array $rows ) {
		global $wpdb;
		$table = self::index_table();
		$in    = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE term IN ({$in})", $terms ) );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = array_intersect_key( $row, array_flip( self::INDEX_COLUMNS ) );
			if ( count( $clean ) !== count( self::INDEX_COLUMNS ) || ! in_array( (string) $clean['term'], $terms, true ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $clean );
		}
	}

	private static function replace_doc_rows( $id, array $rows ) {
		global $wpdb;
		$table = self::index_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, [ 'doc' => (int) $id ], [ '%d' ] );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = array_intersect_key( $row, array_flip( self::INDEX_COLUMNS ) );
			if ( count( $clean ) !== count( self::INDEX_COLUMNS ) || (int) $clean['doc'] !== (int) $id ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $clean );
		}
	}
}

/**
 * Manifest declaration.
 */
add_filter( 'royal_mcp_manifests', function ( $manifests ) {
	if ( ! function_exists( 'relevanssi_do_query' ) ) {
		return $manifests;
	}
	global $relevanssi_variables;
	$manifests[] = [
		'royal_mcp_manifest_version' => '1.0',
		'plugin_slug'                => 'relevanssi',
		'plugin_display_name'        => 'Relevanssi',
		'plugin_version'             => isset( $relevanssi_variables['plugin_version'] ) ? (string) $relevanssi_variables['plugin_version'] : '',
		'vendor_name'                => 'Mikko Saari',
		'mcp_endpoint'               => rest_url( 'royal-mcp/v1/mcp' ),
		'auth_methods'               => [ 'oauth2.1' ],
		'capabilities'               => [ 'read', 'write' ],
		'manifest_updated_at'        => gmdate( 'c' ),
		'trust_signals'              => [
			'supports_dry_run'                => true,
			'supports_undo'                   => true,
			'supports_snapshots'              => false,
			'requires_review_for_destructive' => false,
		],
	];
	return $manifests;
} );
