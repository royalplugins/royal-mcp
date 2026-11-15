<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Launch and report tools: publish a post with its launch steps, optimise one
 * post for launch, a site-readiness check, a monthly maintenance report, and a
 * rewrite of theme asset URLs after a theme change.
 *
 * Each tool runs a list of steps. compose_mode decides what a failed step
 * does: strict stops and takes back the reversible steps, best_effort carries
 * on, report_only runs the read-only steps and only describes the writes.
 */
class LaunchTools {

	/** Tool => [ handler, undo handler or null, the WordPress capability it needs ]. */
	const TOOLS = [
		'wp_publish_and_launch'         => [ 'handle_publish_and_launch', 'undo_publish_and_launch', 'publish_posts' ],
		'seo_optimize_for_launch'       => [ 'handle_seo_optimize_for_launch', 'undo_seo_optimize_for_launch', 'edit_posts' ],
		'wp_prepare_for_launch'         => [ 'handle_prepare_for_launch', null, 'manage_options' ],
		'wp_monthly_maintenance_report' => [ 'handle_monthly_maintenance_report', null, 'manage_options' ],
		'wp_migrate_theme_assets'       => [ 'handle_migrate_theme_assets', 'undo_migrate_theme_assets', 'edit_others_posts' ],
	];

	/** The public address search engines read the site's IndexNow key from. */
	const KEY_ROUTE = '/indexnow-key';

	public static function register() {
		// The IndexNow protocol has search engines fetch the site's key, signed out, to confirm
		// the site sent a submission. The key is a public identifier, not a secret.
		add_action( 'rest_api_init', static function () {
			register_rest_route( 'royal-mcp/v1', self::KEY_ROUTE, [
				'methods'             => 'GET',
				// security-ignore: WP-AUTH-001 -- the IndexNow protocol needs a public GET of the key
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					// Read only: the key is made when a submission is first sent, never by a visitor.
					$key = get_option( 'royal_mcp_indexnow_key', '' );
					if ( ! is_string( $key ) || 32 !== strlen( $key ) || ! ctype_xdigit( $key ) ) {
						return new \WP_Error( 'not_found', 'No key.', [ 'status' => 404 ] );
					}
					return new \WP_REST_Response( $key, 200, [ 'Content-Type' => 'text/plain; charset=utf-8' ] );
				},
			] );
		} );
		// The key is compared byte for byte, so it goes out as plain text, not as a JSON string.
		add_filter( 'rest_pre_serve_request', static function ( $served, $result, $request ) {
			if ( $served || ! $request instanceof \WP_REST_Request || '/royal-mcp/v1' . self::KEY_ROUTE !== strtolower( untrailingslashit( $request->get_route() ) ) ) {
				return $served;
			}
			if ( ! $result instanceof \WP_REST_Response || 200 !== $result->get_status() || ! is_string( $result->get_data() ) ) {
				return $served;
			}
			if ( ! headers_sent() ) {
				header( 'Content-Type: text/plain; charset=utf-8' );
			}
			echo esc_html( $result->get_data() );
			return true;
		}, 10, 3 );
	}

	public static function handles( $name ) {
		return is_string( $name ) && isset( self::TOOLS[ $name ] );
	}

	public static function get_tools() {
		return array_values( self::schemas() );
	}

	public static function execute_tool( $name, $args ) {
		if ( ! self::handles( $name ) ) {
			throw new \Exception( 'Unknown launch tool: ' . esc_html( is_string( $name ) ? $name : '' ) );
		}
		$args    = is_array( $args ) ? $args : [];
		$known   = (array) ( self::schemas()[ $name ]['inputSchema']['properties'] ?? [] );
		$problem = self::arg_problem( $args, $known, '' );
		if ( null !== $problem ) {
			return Envelope::error( 'invalid_args', $problem );
		}
		if ( ! current_user_can( self::TOOLS[ $name ][2] ) ) {
			return Envelope::error( 'insufficient_caps', 'You do not have permission to use this tool.' );
		}
		return call_user_func( [ __CLASS__, self::TOOLS[ $name ][0] ], $args );
	}

	/** Undo of the three tools that return a token. */
	public static function undo( array $snapshot, $force = false ) {
		$op = (string) ( $snapshot['op'] ?? '' );
		if ( ! self::handles( $op ) || null === self::TOOLS[ $op ][1] ) {
			throw new \Exception( 'Unknown launch tool undo.' );
		}
		if ( ! current_user_can( self::TOOLS[ $op ][2] ) ) {
			throw new \Exception( 'You do not have permission to undo this.' );
		}
		$payload = (array) ( $snapshot['pre_op_state']['payload'] ?? [] );
		if ( 'seo_optimize_for_launch' === $op ) {
			$post_id = (int) ( $payload['post_id'] ?? 0 );
			if ( $post_id > 0 && get_post( $post_id ) && ! current_user_can( 'edit_post', $post_id ) ) {
				throw new \Exception( 'You do not have permission to change this post.' );
			}
			if ( ! empty( $payload['redirect_ids_created'] ) && ! current_user_can( 'manage_options' ) ) { // audit:multisite-manage-options-safe -- redirects are per site
				throw new \Exception( 'Only administrators can remove the redirects this change added.' );
			}
			$snap = is_array( $payload['seo_meta_snapshot'] ?? null ) ? $payload['seo_meta_snapshot'] : [];
			if ( ! $force && isset( $snap['written'] ) && $post_id > 0 && get_post( $post_id ) && self::seo_print( $post_id ) !== (string) $snap['written'] ) {
				throw new \Royal_MCP\MCP\Support\Tool_Refusal( 'edited_since_write', 'The SEO title or description of this post was changed after this write, so the undo left it as it is. Pass force: true to put the earlier values back anyway; the later change is lost.', [ 'post_id' => $post_id ] );
			}
		}
		if ( 'wp_publish_and_launch' === $op ) {
			$post_id = (int) ( $payload['post_id'] ?? 0 );
			if ( $post_id > 0 && get_post( $post_id ) ) {
				if ( ! current_user_can( 'delete_post', $post_id ) ) {
					throw new \Exception( 'You do not have permission to delete this post.' );
				}
				$then = (string) ( $snapshot['pre_op_state']['modified'] ?? '' );
				if ( ! $force && '' !== $then && (string) get_post_field( 'post_modified_gmt', $post_id, 'raw' ) !== $then ) {
					throw new \Royal_MCP\MCP\Support\Tool_Refusal( 'edited_since_write', 'The post was edited after it was created, and this undo deletes it permanently, so it was left as it is. Pass force: true to delete it anyway.', [ 'post_id' => $post_id ] );
				}
			}
		}
		$result  = (array) call_user_func( [ __CLASS__, self::TOOLS[ $op ][1] ], $payload, [ 'force' => (bool) $force ] );
		$summary = (string) ( $result['restored_state_summary'] ?? 'Done.' );
		unset( $result['restored_state_summary'], $result['remaining_payload'] );
		return Envelope::success( $summary, [ 'undone' => true, 'op' => $op ] + $result );
	}

	/**
	 * Checks arguments against a tool definition, nested objects included, and turns the accepted
	 * spellings of true / false into booleans. Returns what is wrong, or null.
	 */
	private static function arg_problem( array &$args, array $props, $path ) {
		foreach ( $args as $key => $value ) {
			$name = $path . sanitize_key( (string) $key );
			if ( ! isset( $props[ $key ] ) ) {
				return sprintf( 'This tool does not take: %s.', $name );
			}
			$def  = (array) $props[ $key ];
			$type = (string) ( $def['type'] ?? '' );
			if ( 'boolean' === $type ) {
				if ( ! is_bool( $value ) && ! in_array( $value, [ 0, 1, '0', '1', 'true', 'false' ], true ) ) {
					return sprintf( '%s must be true or false.', $name );
				}
				$args[ $key ] = in_array( $value, [ true, 1, '1', 'true' ], true );
			} elseif ( 'string' === $type ) {
				if ( ! is_string( $value ) ) {
					return sprintf( '%s must be text.', $name );
				}
				if ( isset( $def['enum'] ) && ! in_array( $value, (array) $def['enum'], true ) ) {
					return sprintf( '%s must be one of: %s.', $name, implode( ', ', (array) $def['enum'] ) );
				}
			} elseif ( 'integer' === $type ) {
				if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
					return sprintf( '%s must be a whole number.', $name );
				}
			} elseif ( 'array' === $type ) {
				if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
					return sprintf( '%s must be a list.', $name );
				}
				if ( 'string' === ( $def['items']['type'] ?? '' ) && array_filter( $value, static function ( $item ) {
					return ! is_string( $item );
				} ) ) {
					return sprintf( 'Every entry of %s must be text.', $name );
				}
			} elseif ( 'object' === $type ) {
				if ( ! is_array( $value ) ) {
					return sprintf( '%s must be an object.', $name );
				}
				$inner = self::arg_problem( $value, (array) ( $def['properties'] ?? [] ), $name . '.' );
				if ( null !== $inner ) {
					return $inner;
				}
				$args[ $key ] = $value;
			}
		}
		return null;
	}

	/** A short print of a post's stored SEO title, description and focus keyword. */
	private static function seo_print( $post_id ) {
		$now = self::read_seo_meta_universal( (int) $post_id, self::probe_dependencies() );
		return md5( (string) ( $now['title'] ?? '' ) . '|' . (string) ( $now['description'] ?? '' ) . '|' . (string) ( $now['focus_keyword'] ?? '' ) );
	}

	/** A short print of a redirect's source and target, or '' when it is gone. */
	private static function redirect_print( $id ) {
		$item = class_exists( '\\Red_Item' ) ? \Red_Item::get_by_id( (int) $id ) : null;
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_url' ) ) {
			return '';
		}
		return md5( (string) $item->get_url() . '|' . ( method_exists( $item, 'get_action_data' ) ? (string) wp_json_encode( $item->get_action_data() ) : '' ) );
	}

	private static function has_admin_caps() {
		return current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
	}

	/** Stores what an undo needs and returns the token details for the reply, or null. */
	private static function token( $tool, $payload ) {
		$stored = \Royal_MCP\MCP\Undo_Store::store( [
			'op'           => (string) $tool,
			'summary'      => (string) $tool,
			'target'       => is_array( $payload ) && isset( $payload['post_id'] ) ? [ 'post_id' => (int) $payload['post_id'] ] : [],
			'pre_op_state' => [ 'payload' => $payload, 'modified' => is_array( $payload ) && ! empty( $payload['post_id'] ) ? (string) get_post_field( 'post_modified_gmt', (int) $payload['post_id'], 'raw' ) : '' ],
		] );
		return is_array( $stored ) && ! empty( $stored['token'] ) ? $stored : null;
	}

	/** Adds the undo token to a reply: on the reply, in its structured part and in its text. */
	private static function attach_undo( array $envelope, $undo ) {
		if ( ! is_array( $undo ) || empty( $undo['token'] ) ) {
			return $envelope;
		}
		$envelope['undo']              = $undo;
		$envelope['structuredContent'] = array_merge( (array) ( $envelope['structuredContent'] ?? [] ), [
			'undo_available'  => true,
			'undo_token'      => (string) $undo['token'],
			'undo_expires_at' => isset( $undo['expires_at'] ) ? (string) $undo['expires_at'] : null,
		] );
		if ( isset( $envelope['content'][0]['text'] ) ) {
			$envelope['content'][0]['text'] = rtrim( (string) $envelope['content'][0]['text'], ". \t\r\n" ) . '. Undo token: ' . (string) $undo['token'] . ' (' . \Royal_MCP\MCP\Undo_Store::window_label() . ', pass to mcp_undo_last_operation to reverse).';
		}
		return $envelope;
	}

	/** The message of an exception; for an engine error, a general line, with the details in the PHP error log. */
	private static function error_message( \Throwable $e ) {
		if ( $e instanceof \Exception ) {
			return $e->getMessage();
		}
		error_log( 'Royal MCP: ' . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- an engine error has to reach the log.
		return 'An internal error occurred; the details are in the PHP error log of the site.';
	}

	private static function handle_publish_and_launch( array $args ) {
		$title   = isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : '';
		$content = isset( $args['content'] ) ? (string) $args['content'] : '';
		if ( $title === '' || $content === '' ) {
			return self::error_envelope( 'invalid_args', 'title and content are required.' );
		}
		if ( ! current_user_can( 'publish_posts' ) ) {
			return self::error_envelope( 'insufficient_caps', 'publish_posts capability required.' );
		}

		if ( isset( $args['featured_image_url'] ) && '' !== trim( (string) $args['featured_image_url'] ) && ! in_array( strtolower( (string) wp_parse_url( trim( (string) $args['featured_image_url'] ), PHP_URL_SCHEME ) ), [ 'http', 'https' ], true ) ) {
			return self::error_envelope( 'invalid_args', 'featured_image_url must be an http or https URL.' );
		}
		$mode           = self::resolve_mode( $args, 'strict' );
		$status_explicit = isset( $args['status'] );
		$status          = $status_explicit ? sanitize_key( (string) $args['status'] ) : 'publish';
		$publish_date   = isset( $args['publish_date'] ) ? sanitize_text_field( (string) $args['publish_date'] ) : '';
		$featured_url   = isset( $args['featured_image_url'] ) ? esc_url_raw( (string) $args['featured_image_url'] ) : '';
		$category       = isset( $args['category'] ) ? sanitize_text_field( (string) $args['category'] ) : '';
		$tags           = ( isset( $args['tags'] ) && is_array( $args['tags'] ) ) ? array_map( 'sanitize_text_field', $args['tags'] ) : [];
		$seo_title      = isset( $args['seo_title'] ) ? sanitize_text_field( (string) $args['seo_title'] ) : '';
		$seo_desc       = isset( $args['seo_description'] ) ? sanitize_text_field( (string) $args['seo_description'] ) : '';
		$seo_focus_kw   = isset( $args['seo_focus_keyword'] ) ? sanitize_text_field( (string) $args['seo_focus_keyword'] ) : '';

		$pre_backup     = self::has_admin_caps() && ( ! isset( $args['pre_backup'] ) || (bool) $args['pre_backup'] );
		$post_purge     = ! isset( $args['post_purge_cache'] ) || (bool) $args['post_purge_cache'];

		$seo_automation      = ( isset( $args['seo_automation'] ) && is_array( $args['seo_automation'] ) ) ? $args['seo_automation'] : [];
		$sa_generate_meta    = ! empty( $seo_automation['generate_meta'] );
		$sa_generate_schema  = ! empty( $seo_automation['generate_schema'] );
		$sa_push_to_indexnow = ! empty( $seo_automation['push_to_indexnow'] );
		$sa_push_to_gsc      = ! empty( $seo_automation['push_to_gsc'] );

		if ( $sa_generate_meta ) {
			if ( $seo_title === '' && $title !== '' ) {
				$seo_title = self::truncate_to_len( $title, 60 );
			}
			if ( $seo_desc === '' ) {
				$desc_source = trim( wp_strip_all_tags( strip_shortcodes( (string) $content ), true ) );
				$desc_source = preg_replace( '/\s+/u', ' ', (string) $desc_source );
				if ( $desc_source !== '' ) {
					$seo_desc = self::truncate_to_len( $desc_source, 160 );
				}
			}
		}

		$sa_schema_data   = null;
		$sa_indexnow_data = null;
		$sa_gsc_data      = null;

		$deps = self::probe_dependencies();

		$created_post_id  = 0;
		$created_media_id = 0;
		$rollback_list    = []; 

		$steps = [
			[
				'name'       => 'pre_backup_via_sitevault',
				'reversible' => false,
				'condition'  => $pre_backup && $deps['sitevault']['available'],
				'run'        => static function () use ( $deps ) {
					return self::invoke_sitevault_backup( $deps );
				},
			],
			[
				'name'       => 'upload_featured_image',
				'reversible' => true,
				'condition'  => $featured_url !== '',
				'run'        => static function () use ( $featured_url, &$created_media_id, &$rollback_list ) {
					$mid = self::sideload_media( $featured_url );
					if ( is_wp_error( $mid ) ) {
						return [ 'status' => 'error', 'message' => $mid->get_error_message() ];
					}
					$created_media_id = (int) $mid;
					$rollback_list[]  = [ 'op' => 'delete_media', 'id' => $created_media_id ];
					return [ 'status' => 'ok', 'data' => [ 'attachment_id' => $created_media_id ] ];
				},
			],
			[
				'name'       => 'create_post',
				'reversible' => true,
				'condition'  => true,
				'run'        => static function () use ( $title, $content, $status, $publish_date, &$created_post_id, &$created_media_id, &$rollback_list ) {
					$insert = [
						'post_title'   => $title,
						'post_content' => $content,
						'post_status'  => in_array( $status, [ 'draft', 'publish', 'pending', 'private', 'future' ], true ) ? $status : 'publish',
						'post_type'    => 'post',
						'post_author'  => get_current_user_id(),
					];
					if ( $publish_date !== '' ) {
						$insert['post_date']     = $publish_date;
						$insert['post_date_gmt'] = get_gmt_from_date( $publish_date );
						if ( $status === 'publish' && strtotime( $publish_date ) > time() ) {
							$insert['post_status'] = 'future';
						}
					}
					if ( $created_media_id ) {

						$insert['meta_input'] = [ '_thumbnail_id' => (int) $created_media_id ];
					}
					$pid = wp_insert_post( wp_slash( $insert ), true );
					if ( is_wp_error( $pid ) ) {
						return [ 'status' => 'error', 'message' => $pid->get_error_message() ];
					}
					$created_post_id = (int) $pid;
					$rollback_list[] = [ 'op' => 'delete_post', 'id' => $created_post_id ];
					return [ 'status' => 'ok', 'data' => [ 'post_id' => $created_post_id, 'url' => get_permalink( $created_post_id ) ] ];
				},
			],
			[
				'name'       => 'set_featured_image',
				'reversible' => false,
				'condition'  => $featured_url !== '',
				'run'        => static function () use ( &$created_post_id, &$created_media_id ) {
					if ( ! $created_post_id || ! $created_media_id ) {
						return [ 'status' => 'skipped', 'message' => 'no post or media to attach' ];
					}

					$set_ok = (bool) set_post_thumbnail( $created_post_id, $created_media_id );
					$verify = (int) get_post_thumbnail_id( $created_post_id );
					if ( ! $set_ok || $verify !== (int) $created_media_id ) {
						return [
							'status'  => 'error',
							'message' => sprintf( 'set_post_thumbnail returned %s; verify read back thumbnail_id=%d, expected %d', $set_ok ? 'true' : 'false', $verify, $created_media_id ),
							'data'    => [ 'post_id' => $created_post_id, 'media_id' => $created_media_id, 'verified_thumbnail_id' => $verify ],
						];
					}
					return [ 'status' => 'ok', 'data' => [ 'post_id' => $created_post_id, 'thumbnail_id' => $verify ] ];
				},
			],
			[
				'name'       => 'assign_category',
				'reversible' => false,
				'condition'  => $category !== '',
				'run'        => static function () use ( &$created_post_id, $category, &$rollback_list ) {
					if ( ! $created_post_id ) {
						return [ 'status' => 'skipped', 'message' => 'no post created' ];
					}
					$term = term_exists( $category, 'category' );
					if ( ! $term ) {
						$taxonomy = get_taxonomy( 'category' );
						if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->edit_terms ) ) {
							return [ 'status' => 'skipped', 'message' => sprintf( 'Category "%s" does not exist and you cannot create categories.', $category ) ];
						}
						$term = wp_insert_term( wp_slash( $category ), 'category' );
						if ( is_wp_error( $term ) ) {
							return [ 'status' => 'error', 'message' => $term->get_error_message() ];
						}
						$rollback_list[] = [ 'op' => 'delete_term', 'id' => (int) $term['term_id'], 'tax' => 'category' ];
					}
					$term_id = (int) $term['term_id'];

					$set = wp_set_object_terms( $created_post_id, $term_id, 'category', false );
					if ( is_wp_error( $set ) ) {
						return [ 'status' => 'error', 'message' => 'category assignment failed: ' . $set->get_error_message(), 'data' => [ 'term_id' => $term_id ] ];
					}
					if ( ! is_array( $set ) || empty( $set ) ) {
						return [ 'status' => 'error', 'message' => sprintf( 'category assignment silent-failed (wp_set_object_terms returned %s)', gettype( $set ) ), 'data' => [ 'term_id' => $term_id ] ];
					}
					return [ 'status' => 'ok', 'data' => [ 'term_id' => $term_id, 'category_name' => $category ] ];
				},
			],
			[
				'name'       => 'assign_tags',
				'reversible' => false,
				'condition'  => ! empty( $tags ),
				'run'        => static function () use ( &$created_post_id, $tags, &$rollback_list ) {
					if ( ! $created_post_id ) {
						return [ 'status' => 'skipped', 'message' => 'no post created' ];
					}
					$new_tags = array_values( array_filter( $tags, static function ( $tag ) {
						return '' !== $tag && ! term_exists( $tag, 'post_tag' );
					} ) );

					$set = wp_set_post_tags( $created_post_id, $tags, false );
					foreach ( $new_tags as $tag ) {
						$made = get_term_by( 'name', $tag, 'post_tag' );
						if ( $made ) {
							$rollback_list[] = [ 'op' => 'delete_term', 'id' => (int) $made->term_id, 'tax' => 'post_tag' ];
						}
					}
					if ( is_wp_error( $set ) ) {
						return [ 'status' => 'error', 'message' => 'tag assignment failed: ' . $set->get_error_message(), 'data' => [ 'tags_requested' => count( $tags ) ] ];
					}
					if ( $set === false ) {
						return [ 'status' => 'error', 'message' => 'tag assignment failed (wp_set_post_tags returned false)', 'data' => [ 'tags_requested' => count( $tags ) ] ];
					}
					$applied = is_array( $set ) ? count( $set ) : 0;
					if ( $applied === 0 && count( $tags ) > 0 ) {
						return [ 'status' => 'error', 'message' => sprintf( 'tag assignment silent-failed: requested %d, applied 0', count( $tags ) ), 'data' => [ 'tags_requested' => count( $tags ), 'tags_applied' => 0 ] ];
					}
					return [ 'status' => 'ok', 'data' => [ 'tags_requested' => count( $tags ), 'tags_applied' => $applied ] ];
				},
			],
			[
				'name'       => 'write_seo_meta',
				'reversible' => false,
				'condition'  => $seo_title !== '' || $seo_desc !== '' || $seo_focus_kw !== '',
				'run'        => static function () use ( &$created_post_id, $seo_title, $seo_desc, $seo_focus_kw, $deps ) {
					if ( ! $created_post_id ) {
						return [ 'status' => 'skipped', 'message' => 'no post created' ];
					}
					$written = self::write_seo_meta_universal( $created_post_id, $seo_title, $seo_desc, $seo_focus_kw, $deps );
					if ( ! $written['ok'] ) {
						return [ 'status' => 'skipped', 'message' => $written['message'] ];
					}

					$post_audit = self::audit_seo_meta_for_launch( $created_post_id, $deps );
					$post_audit_data = ( $post_audit['status'] ?? '' ) === 'ok' ? ( $post_audit['data'] ?? null ) : null;
					$warnings = [];
					if ( is_array( $post_audit_data ) ) {
						if ( $seo_title !== '' && ( $post_audit_data['title']['issue'] ?? 'ok' ) !== 'ok' ) {
							$t_issue = (string) $post_audit_data['title']['issue'];
							$warnings[] = sprintf(
								'seo_title written but audit shows issue=%s (length=%d, thresholds 30-60). %s.',
								$t_issue,
								(int) ( $post_audit_data['title']['length'] ?? 0 ),
								self::warning_hint_for_issue( $t_issue, 'seo_title' )
							);
						}
						if ( $seo_desc !== '' && ( $post_audit_data['description']['issue'] ?? 'ok' ) !== 'ok' ) {
							$d_issue = (string) $post_audit_data['description']['issue'];
							$warnings[] = sprintf(
								'seo_description written but audit shows issue=%s (length=%d, thresholds 120-160). %s.',
								$d_issue,
								(int) ( $post_audit_data['description']['length'] ?? 0 ),
								self::warning_hint_for_issue( $d_issue, 'seo_description' )
							);
						}
					}

					return [ 'status' => 'ok', 'data' => [
						'seo_plugin'       => $written['plugin'],
						'audit_after'      => $post_audit_data,
						'post_write_audit' => $post_audit_data, 
						'warnings'         => $warnings,
						'audit_resolved'   => empty( $warnings ),
					] ];
				},
			],

			[
				'name'       => 'post_purge_cache',
				'reversible' => false,
				'condition'  => $post_purge && $deps['forgecache']['available'],
				'run'        => static function () use ( &$created_post_id, $deps, $status ) {

					if ( ! in_array( (string) $status, [ 'publish', 'inherit', 'future' ], true ) ) {
						return [
							'status'  => 'skipped',
							'message' => sprintf( 'cache purge skipped: post status "%s" has no public URL to invalidate', (string) $status ),
							'data'    => [ 'post_status' => (string) $status ],
						];
					}
					return self::invoke_forgecache_purge( $deps );
				},
			],
		];

		$sa_steps = [];
		if ( $sa_generate_schema ) {
			$sa_steps[] = [
				'name'       => 'seo_automation_verify_schema',
				'reversible' => false,
				'read_only'  => true,  
				'condition'  => true,
				'run'        => static function () use ( &$created_post_id, &$sa_schema_data ) {
					if ( ! $created_post_id ) {
						return [ 'status' => 'skipped', 'message' => 'no post created' ];
					}

					$post_obj = get_post( $created_post_id );
					if ( $post_obj && ! in_array( (string) $post_obj->post_status, [ 'publish', 'inherit' ], true ) ) {
						return [
							'status'  => 'skipped',
							'message' => sprintf(
								'schema verification skipped: post status "%s" is not publicly fetchable (must be "publish"). Re-run after publishing.',
								(string) $post_obj->post_status
							),
							'data'    => [ 'post_status' => (string) $post_obj->post_status ],
						];
					}
					$r = self::verify_schema_present( $created_post_id );
					if ( ( $r['status'] ?? '' ) === 'ok' ) {
						$sa_schema_data = $r['data'] ?? null;
					}
					return $r;
				},
			];
		}
		if ( $sa_push_to_indexnow ) {
			$sa_steps[] = [
				'name'       => 'seo_automation_indexnow_push',
				'reversible' => false,
				'condition'  => true,
				'run'        => static function () use ( &$created_post_id, &$sa_indexnow_data ) {
					if ( ! $created_post_id ) {
						return [ 'status' => 'skipped', 'message' => 'no post created' ];
					}
					$r = self::push_indexnow_for_post( $created_post_id );
					if ( ( $r['status'] ?? '' ) === 'ok' ) {
						$sa_indexnow_data = $r['data'] ?? null;
					}
					return $r;
				},
			];
		}
		if ( $sa_push_to_gsc ) {
			$sa_steps[] = [
				'name'       => 'seo_automation_gsc_push',
				'reversible' => false,
				'condition'  => true,
				'run'        => static function () use ( &$created_post_id, &$sa_gsc_data ) {
					if ( ! $created_post_id ) {
						return [ 'status' => 'skipped', 'message' => 'no post created' ];
					}
					$r = self::push_gsc_for_post( $created_post_id );
					if ( ( $r['status'] ?? '' ) === 'ok' ) {
						$sa_gsc_data = $r['data'] ?? null;
					}
					return $r;
				},
			];
		}
		if ( ! empty( $sa_steps ) ) {

			array_splice( $steps, count( $steps ) - 1, 0, $sa_steps );
		}

		$engine = self::run_steps( $steps, $mode );

		if ( $engine['aborted'] && $mode === 'strict' && ! empty( $rollback_list ) ) {
			self::run_rollback( array_reverse( $rollback_list ) );
			$engine['rolled_back']    = true;
			$engine['rollback_count'] = count( $rollback_list );
			$created_post_id  = 0;
			$created_media_id = 0;
		}

		$undo_meta = null;
		if ( ! $engine['aborted'] && $created_post_id > 0 ) {

			$undo_meta = self::token( 'wp_publish_and_launch', [
					'post_id'                => $created_post_id,
					'media_id'               => $created_media_id,
					'created_terms'          => array_values( array_filter( (array) $rollback_list, static function ( $op ) {
						return 'delete_term' === ( $op['op'] ?? '' );
					} ) ),
					'indexnow_pushed'        => $sa_indexnow_data,
					'gsc_pushed'             => $sa_gsc_data,
					'cache_purged_on_create' => $post_purge && $deps['forgecache']['available'] && in_array( (string) $status, [ 'publish', 'future' ], true ),
				] );
		}

		$warnings = [];
		if ( ! $status_explicit && $status === 'publish' ) {
			$warnings[] = 'status defaulted to "publish" — this call wrote a LIVE post. If you meant to stage first, pass status="draft" explicitly.';
		}

		$structured = [
			'compose_mode'         => $mode,
			'post_id'              => $created_post_id ?: null,
			'post_url'             => $created_post_id ? get_permalink( $created_post_id ) : null,
			'attachment_id'        => $created_media_id ?: null,
			'dependencies_probed'  => $deps,
			'steps'                => $engine['steps'],
			'counts'               => $engine['counts'],
			'aborted'              => (bool) $engine['aborted'],
			'rolled_back'          => ! empty( $engine['rolled_back'] ),
		];

		if ( $sa_generate_meta || $sa_generate_schema || $sa_push_to_indexnow || $sa_push_to_gsc ) {

			$sa_schema_envelope = $sa_schema_data;
			if ( $sa_schema_envelope === null && $sa_generate_schema ) {
				foreach ( (array) $engine['steps'] as $s ) {
					if ( ( $s['name'] ?? '' ) === 'seo_automation_verify_schema' && ( $s['status'] ?? '' ) === 'skipped' ) {
						$sa_schema_envelope = [
							'status' => 'skipped',
							'reason' => (string) ( $s['message'] ?? 'skipped' ),
							'data'   => is_array( $s['data'] ?? null ) ? $s['data'] : null,
						];
						break;
					}
				}
			}
			$structured['seo_automation'] = [
				'requested' => [
					'generate_meta'    => $sa_generate_meta,
					'generate_schema'  => $sa_generate_schema,
					'push_to_indexnow' => $sa_push_to_indexnow,
					'push_to_gsc'      => $sa_push_to_gsc,
				],
				'schema'    => $sa_schema_envelope,
				'indexnow'  => $sa_indexnow_data,
				'gsc'       => $sa_gsc_data,

			];
		}

		$structured['quality'] = self::aggregate_quality_signals( $engine['steps'] );
		if ( ! empty( $warnings ) ) {
			$structured['warnings'] = $warnings;
		}

		$failing_step_text = '';
		if ( $engine['aborted'] ) {
			foreach ( (array) $engine['steps'] as $s ) {
				if ( ( $s['status'] ?? '' ) === 'error' ) {
					$failing_step_text = sprintf(
						' Failing step: %s — %s.',
						(string) ( $s['name'] ?? '?' ),
						(string) ( $s['message'] ?? 'no message' )
					);
					break;
				}
			}
		}

		$quality_p_suffix = '';
		if ( ! $engine['aborted'] && isset( $structured['quality'] ) ) {
			$q = $structured['quality'];
			if ( ! empty( $q['has_unresolved_issues'] ) || ( $q['warnings_count'] ?? 0 ) > 0 ) {
				$quality_p_suffix = sprintf(
					' Quality: audit_resolved=%s, %d warning(s). See structuredContent.quality for detail.',
					! empty( $q['audit_resolved'] ) ? 'true' : 'false',
					(int) ( $q['warnings_count'] ?? 0 )
				);
			}
		}

		$envelope = [
			'isError'           => (bool) $engine['aborted'], // audit:helper-bypass-safe -- one reply shape for a completed or aborted run
			'content'           => [ [
				'type' => 'text',
				'text' => sprintf(
					'%s post %s. steps: %d ok, %d skipped, %d error%s.%s%s',
					$engine['aborted'] ? 'ABORTED: nothing was kept from' : ( $created_post_id && 'publish' !== get_post_status( $created_post_id ) ? sprintf( 'Saved (%s)', (string) get_post_status( $created_post_id ) ) : 'Published' ),
					$created_post_id ? "#$created_post_id" : '(none)',
					$engine['counts']['ok'],
					$engine['counts']['skipped'],
					$engine['counts']['error'],
					! empty( $engine['rolled_back'] ) ? sprintf( ' (rolled back %d reversible steps)', $engine['rollback_count'] ) : '',
					$failing_step_text,
					$quality_p_suffix
				),
			] ],
			'structuredContent' => $structured,
		];
		if ( $undo_meta ) {
			$envelope = self::attach_undo( $envelope, $undo_meta );
		}
		return $envelope;
	}

	private static function handle_seo_optimize_for_launch( array $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( $post_id <= 0 ) {
			return self::error_envelope( 'invalid_args', 'post_id is required.' );
		}
		if ( ! get_post( $post_id ) ) {
			return self::error_envelope( 'post_not_found', sprintf( 'Post #%d does not exist.', $post_id ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::error_envelope( 'insufficient_caps', sprintf( 'edit_post capability required for post #%d.', $post_id ) );
		}
		$target = get_post( $post_id );
		if ( \Royal_MCP\MCP\Support\Post_Access::is_code_type( $target->post_type ) || in_array( $target->post_type, [ 'revision', 'attachment', 'nav_menu_item' ], true ) || in_array( $target->post_status, [ 'trash', 'auto-draft', 'inherit' ], true ) ) {
			return self::error_envelope( 'invalid_post', sprintf( 'Post #%d is not a page or post that can be prepared for launch (code, attachments, revisions and trashed items are left alone).', $post_id ) );
		}

		$fixes            = ( isset( $args['fixes'] ) && is_array( $args['fixes'] ) ) ? $args['fixes'] : [];
		if ( ! empty( $fixes['add_301_from_slugs'] ) && ! current_user_can( 'manage_options' ) ) { // audit:multisite-manage-options-safe -- redirects are per site
			return self::error_envelope( 'insufficient_caps', 'Redirects change URLs across the site; only administrators can add them.' );
		}
		$auto_fix_title   = ! empty( $fixes['auto_fix_title'] );
		$auto_fix_desc    = ! empty( $fixes['auto_fix_description'] );

		$generate_schema  = ! isset( $fixes['generate_schema'] )  || (bool) $fixes['generate_schema'];
		$purge_cache_flag = ! isset( $fixes['purge_cache'] )      || (bool) $fixes['purge_cache'];
		$push_to_indexnow = ! empty( $fixes['push_to_indexnow'] );
		$push_to_gsc      = ! empty( $fixes['push_to_gsc'] );
		$add_301_slugs    = ( isset( $fixes['add_301_from_slugs'] ) && is_array( $fixes['add_301_from_slugs'] ) )
			? array_values( array_filter( array_map( 'sanitize_title', $fixes['add_301_from_slugs'] ) ) )
			: [];

		$snapshot_enabled = self::has_admin_caps() && ( ! isset( $args['snapshot'] ) || (bool) $args['snapshot'] );
		$mode             = self::resolve_mode( $args, 'strict' );
		$deps             = self::probe_dependencies();

		$rollback_stack = [];

		$sitevault_snapshot_data = null; 
		$audit_data              = null;
		$seo_meta_snapshot       = null; 
		$schema_data             = null; 
		$redirect_ids_created    = []; 
		$indexnow_data           = null; 
		$gsc_data                = null; 
		$cache_purge_data        = null; 

		$steps = [

			[
				'name'       => 'sitevault_snapshot',
				'reversible' => false,
				'condition'  => $snapshot_enabled && $deps['sitevault']['available'],
				'run'        => static function () use ( $deps, &$sitevault_snapshot_data ) {
					$r = self::invoke_sitevault_backup( $deps );
					if ( ( $r['status'] ?? '' ) === 'ok' ) {
						$sitevault_snapshot_data = $r['data'] ?? null;
					}
					return $r;
				},
			],

			[
				'name'       => 'audit',
				'reversible' => false,
				'read_only'  => true,  
				'condition'  => true,
				'run'        => static function () use ( $post_id, $deps, &$audit_data ) {
					$r = self::audit_seo_meta_for_launch( $post_id, $deps );
					if ( ( $r['status'] ?? '' ) === 'ok' ) {
						$audit_data = $r['data'] ?? null;
					}
					return $r;
				},
			],

			[
				'name'       => 'auto_fix_meta',
				'reversible' => true,
				'condition'  => $auto_fix_title || $auto_fix_desc,
				'run'        => static function () use ( $post_id, $auto_fix_title, $auto_fix_desc, $deps, &$audit_data, &$seo_meta_snapshot, &$rollback_stack ) {
					$pre = self::read_seo_meta_universal( $post_id, $deps );
					if ( ! $pre['ok'] ) {
						return [ 'status' => 'skipped', 'message' => 'no supported SEO plugin detected (Yoast / Rank Math / AIOSEO / SEOPress / SEObolt required for auto-fix)' ];
					}
					$audit_for_rules = is_array( $audit_data ) ? $audit_data : [];
					$new_title = $auto_fix_title ? self::rules_based_title( $post_id, (string) $pre['title'], $audit_for_rules ) : (string) $pre['title'];
					$new_desc  = $auto_fix_desc  ? self::rules_based_description( $post_id, (string) $pre['description'], $audit_for_rules ) : (string) $pre['description'];

					if ( $new_title === (string) $pre['title'] && $new_desc === (string) $pre['description'] ) {
						$reasons = [];
						$warnings_skip = [];

						if ( $auto_fix_title ) {
							$t_issue = (string) ( $audit_for_rules['title']['issue'] ?? '' );
							if ( $t_issue === 'ok' ) {
								$reasons[] = 'title already within thresholds';
							} else {
								$reasons[] = sprintf( "title stays %s — generator's source (post_title = '%s') produces the same value", $t_issue, esc_html( (string) get_post_field( 'post_title', $post_id ) ) );
								$warnings_skip[] = sprintf(
									'title auto-fix could not improve issue=%s — post_title is the source and derives to the same value. Edit post_title or set an explicit seo_title.',
									$t_issue
								);
							}
						}

						if ( $auto_fix_desc ) {
							$d_issue = (string) ( $audit_for_rules['description']['issue'] ?? '' );
							if ( $d_issue === 'ok' ) {
								$reasons[] = 'description already within thresholds';
							} else {
								$reasons[] = sprintf( 'description stays %s — generator falls back to post_excerpt or content, which produces the same value or is empty', $d_issue );
								$warnings_skip[] = sprintf(
									'description auto-fix could not improve issue=%s — post_excerpt / post_content produces the same value or is empty. Edit post_excerpt or set an explicit seo_description.',
									$d_issue
								);
							}
						}

						return [
							'status'  => 'skipped',
							'message' => 'no write: ' . ( empty( $reasons ) ? 'no change needed' : implode( '; ', $reasons ) ),
							'data'    => [
								'plugin'              => (string) $pre['plugin'],
								'title_updated'       => false,
								'description_updated' => false,
								'audit_before'        => $audit_for_rules,
								'audit_after'         => $audit_for_rules, 
								'warnings'            => $warnings_skip,
								'audit_resolved'      => empty( $warnings_skip ),
							],
						];
					}

					$written = self::write_seo_meta_universal( $post_id, $new_title, $new_desc, (string) $pre['focus_keyword'], $deps );
					if ( ! $written['ok'] ) {
						return [ 'status' => 'error', 'message' => (string) ( $written['message'] ?? 'write_seo_meta_universal failed' ) ];
					}

					$rollback_stack[] = static function () use ( $post_id, $pre, $deps ) {
						self::restore_seo_meta_universal(
							$post_id,
							(string) $pre['title'],
							(string) $pre['description'],
							(string) $pre['focus_keyword'],
							$deps,
							(string) ( $pre['plugin'] ?? '' )
						);
					};

					$seo_meta_snapshot = [
						'plugin'        => (string) $written['plugin'],
						'prev_title'    => (string) $pre['title'],
						'prev_desc'     => (string) $pre['description'],
						'prev_focus_kw' => (string) $pre['focus_keyword'],
					];

					$post_audit = self::audit_seo_meta_for_launch( $post_id, $deps );
					$post_audit_data = ( $post_audit['status'] ?? '' ) === 'ok' ? ( $post_audit['data'] ?? null ) : null;

					$warnings = [];
					if ( is_array( $post_audit_data ) ) {
						if ( $auto_fix_title && ( $post_audit_data['title']['issue'] ?? 'ok' ) !== 'ok' ) {
							$t_issue = (string) ( $post_audit_data['title']['issue'] ?? '?' );
							$t_hint  = self::warning_hint_for_issue( $t_issue, 'seo_title' );
							$warnings[] = sprintf(
								'title auto-fix wrote a value that still fails audit: issue=%s length=%d (thresholds 30-60). Rules-based generator used post_title; %s.',
								$t_issue,
								(int) ( $post_audit_data['title']['length'] ?? 0 ),
								$t_hint
							);
						}
						if ( $auto_fix_desc && ( $post_audit_data['description']['issue'] ?? 'ok' ) !== 'ok' ) {
							$d_issue = (string) ( $post_audit_data['description']['issue'] ?? '?' );
							$d_hint  = self::warning_hint_for_issue( $d_issue, 'seo_description' );
							$warnings[] = sprintf(
								'description auto-fix wrote a value that still fails audit: issue=%s length=%d (thresholds 120-160). Rules-based generator used post_excerpt/content; %s.',
								$d_issue,
								(int) ( $post_audit_data['description']['length'] ?? 0 ),
								$d_hint
							);
						}
					}

					return [ 'status' => 'ok', 'data' => [
						'plugin'              => (string) $written['plugin'],
						'title_updated'       => $auto_fix_title && $new_title !== (string) $pre['title'],
						'description_updated' => $auto_fix_desc  && $new_desc  !== (string) $pre['description'],
						'audit_before'        => $audit_for_rules,
						'audit_after'         => $post_audit_data,
						'warnings'            => $warnings,
						'audit_resolved'      => empty( $warnings ),
					] ];
				},
			],

			[
				'name'       => 'verify_schema',
				'reversible' => false,
				'read_only'  => true,  
				'condition'  => $generate_schema,
				'run'        => static function () use ( $post_id, &$schema_data ) {

					$post_obj = get_post( $post_id );
					if ( $post_obj && ! in_array( (string) $post_obj->post_status, [ 'publish', 'inherit' ], true ) ) {
						return [
							'status'  => 'skipped',
							'message' => sprintf(
								'schema verification skipped: post status "%s" is not publicly fetchable (must be "publish"). Re-run after publishing to verify rendered schema.',
								(string) $post_obj->post_status
							),
							'data'    => [ 'post_status' => (string) $post_obj->post_status ],
						];
					}
					$r = self::verify_schema_present( $post_id );
					if ( ( $r['status'] ?? '' ) === 'ok' ) {
						$schema_data = $r['data'] ?? null;
					}
					return $r;
				},
			],

			[
				'name'       => 'add_301_redirects',
				'reversible' => true,
				'condition'  => ! empty( $add_301_slugs ),
				'run'        => static function () use ( $post_id, $add_301_slugs, &$redirect_ids_created, &$rollback_stack ) {
					if ( ! class_exists( '\\Red_Item' ) ) {
						return [ 'status' => 'skipped', 'message' => 'Redirection plugin not active — cannot create 301 redirects' ];
					}

					global $wpdb;
					$items_table = $wpdb->prefix . 'redirection_items';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table-name check against known plugin table
					if ( $wpdb->get_var( "SHOW TABLES LIKE '{$items_table}'" ) !== $items_table ) {
						return [ 'status' => 'skipped', 'message' => 'Redirection plugin is active but its DB tables have not been created yet. Visit Tools → Redirection in wp-admin once to trigger install, then retry.' ];
					}
					$post_url  = get_permalink( $post_id );
					$target    = wp_parse_url( $post_url, PHP_URL_PATH );
					if ( ! is_string( $target ) || $target === '' ) {
						return [ 'status' => 'error', 'message' => sprintf( 'could not resolve permalink path for post %d', $post_id ) ];
					}

					$group_id = self::resolve_or_create_redirection_group();
					if ( ! $group_id ) {
						return [ 'status' => 'error', 'message' => 'could not resolve or create a Redirection group (Red_Group::create returned no ID)' ];
					}
					$created   = [];
					$failed    = [];
					$conflicts = []; 
					$self_skips = []; 
					foreach ( $add_301_slugs as $slug ) {
						$source = '/' . ltrim( (string) $slug, '/' );
						if ( substr( $source, -1 ) !== '/' ) {
							$source .= '/';
						}
						if ( $source === $target ) {
							$self_skips[] = $source;
							continue; 
						}

						try {
							$source = \Royal_MCP\Integrations\Redirection::as_stored_source( $source, false );
							if ( '' === $source ) {
								throw new \Exception( 'source is empty once unsupported characters are removed' );
							}
							\Royal_MCP\Integrations\Redirection::refuse_protected_source( $source, false, [] );
							if ( $source === $target ) {
								$self_skips[] = $source;
								continue;
							}
						} catch ( \Exception $e ) {
							$failed[] = [ 'source' => (string) $slug, 'error' => trim( (string) preg_replace( '/\s*Pass allow_protected_source.*$/s', '', $e->getMessage() ) ) ];
							continue;
						}

						if ( method_exists( '\\Red_Item', 'get_for_url' ) ) {
							$existing = \Red_Item::get_for_url( $source, 'redirection' );
							if ( ! empty( $existing ) ) {
								$existing_ids = [];
								foreach ( $existing as $ex ) {
									if ( is_object( $ex ) && method_exists( $ex, 'get_id' ) ) {
										$existing_ids[] = (int) $ex->get_id();
									}
								}
								$conflicts[] = [ 'source' => $source, 'existing_ids' => $existing_ids ];
								continue;
							}
						}
						$redirect = \Red_Item::create( [
							'url'         => $source,
							'action_type' => 'url',
							'action_code' => 301,
							'action_data' => [ 'url' => $target ],
							'match_type'  => 'url',
							'group_id'    => $group_id,
							'title'       => sprintf( 'Auto-created by seo_optimize_for_launch → post #%d', $post_id ),
						] );
						if ( is_wp_error( $redirect ) ) {
							$failed[] = [ 'source' => $source, 'error' => $redirect->get_error_message() ];
							continue;
						}
						if ( is_object( $redirect ) && method_exists( $redirect, 'get_id' ) ) {
							$created[] = (int) $redirect->get_id();
						}
					}

					$result_data = [
						'target'             => $target,
						'created_ids'        => $created,
						'created'            => count( $created ),
						'failed'             => $failed,
						'existing_conflicts' => $conflicts,
						'self_redirect_skipped' => $self_skips,
					];

					if ( empty( $created ) && empty( $failed ) && ! empty( $conflicts ) ) {
						return [
							'status'  => 'skipped',
							'message' => sprintf( '%d requested redirect(s) already exist for these source URLs; no writes made to avoid conflict.', count( $conflicts ) ),
							'data'    => $result_data,
						];
					}
					if ( empty( $created ) && ! empty( $failed ) ) {
						return [ 'status' => 'error', 'message' => 'all redirect creations failed', 'data' => $result_data ];
					}
					$redirect_ids_created = $created;
					if ( ! empty( $created ) ) {
						$rollback_stack[] = static function () use ( $created ) {
							foreach ( $created as $rid ) {
								$item = \Red_Item::get_by_id( $rid );
								if ( is_object( $item ) && method_exists( $item, 'delete' ) ) {
									$item->delete();
								}
							}
						};
					}
					return [ 'status' => 'ok', 'data' => $result_data ];
				},
			],

			[
				'name'       => 'indexnow_push',
				'reversible' => false,
				'condition'  => $push_to_indexnow,
				'run'        => static function () use ( $post_id, &$indexnow_data ) {
					$r = self::push_indexnow_for_post( $post_id );
					if ( ( $r['status'] ?? '' ) === 'ok' ) {
						$indexnow_data = $r['data'] ?? null;
					}
					return $r;
				},
			],

			[
				'name'       => 'gsc_push',
				'reversible' => false,
				'condition'  => $push_to_gsc,
				'run'        => static function () use ( $post_id, &$gsc_data ) {
					$r = self::push_gsc_for_post( $post_id );
					if ( ( $r['status'] ?? '' ) === 'ok' ) {
						$gsc_data = $r['data'] ?? null;
					}
					return $r;
				},
			],

			[
				'name'       => 'cache_purge',
				'reversible' => false,
				'condition'  => $purge_cache_flag,
				'run'        => static function () use ( $post_id, &$cache_purge_data ) {
					$post_obj = get_post( $post_id );
					if ( $post_obj && ! in_array( (string) $post_obj->post_status, [ 'publish', 'inherit', 'future' ], true ) ) {
						return [
							'status'  => 'skipped',
							'message' => sprintf( 'cache purge skipped: post status "%s" has no public URL to invalidate', (string) $post_obj->post_status ),
							'data'    => [ 'post_status' => (string) $post_obj->post_status ],
						];
					}
					$r = self::purge_cache_for_post( $post_id );
					if ( ( $r['status'] ?? '' ) === 'ok' ) {
						$cache_purge_data = $r['data'] ?? null;
					}
					return $r;
				},
			],
		];

		$engine = self::run_steps( $steps, $mode );

		if ( $engine['aborted'] && $mode === 'strict' && ! empty( $rollback_stack ) ) {
			foreach ( array_reverse( $rollback_stack ) as $fn ) {
				try {
					$fn();
				} catch ( \Throwable $e ) {

				}
			}
			$engine['rolled_back']    = true;
			$engine['rollback_count'] = count( $rollback_stack );
			$seo_meta_snapshot        = null; 
		}

		$undo_meta = null;
		$has_reversible_write = ( $seo_meta_snapshot !== null ) || ( ! empty( $redirect_ids_created ) );
		if ( ! $engine['aborted'] && $has_reversible_write ) {
			if ( is_array( $seo_meta_snapshot ) ) {
				$seo_meta_snapshot['written'] = self::seo_print( $post_id );
			}
			$undo_meta = self::token( 'seo_optimize_for_launch', [
					'post_id'              => $post_id,
					'seo_meta_snapshot'    => $seo_meta_snapshot,
					'redirect_ids_created' => $redirect_ids_created,
					'redirect_prints'      => array_combine( array_map( 'intval', (array) $redirect_ids_created ), array_map( [ __CLASS__, 'redirect_print' ], (array) $redirect_ids_created ) ),

					'sitevault_snapshot'   => $sitevault_snapshot_data,
					'indexnow_pushed'      => $indexnow_data,
					'gsc_pushed'           => $gsc_data,
					'cache_purged'         => $cache_purge_data,
				] );
		}

		$quality = self::aggregate_quality_signals( $engine['steps'] );

		$schema_envelope = $schema_data;
		if ( $schema_envelope === null ) {
			foreach ( (array) $engine['steps'] as $s ) {
				if ( ( $s['name'] ?? '' ) === 'verify_schema' && ( $s['status'] ?? '' ) === 'skipped' ) {
					$schema_envelope = [
						'status'  => 'skipped',
						'reason'  => (string) ( $s['message'] ?? 'skipped' ),
						'data'    => is_array( $s['data'] ?? null ) ? $s['data'] : null,
					];
					break;
				}
			}
		}

		$structured = [
			'compose_mode'         => $mode,
			'post_id'              => $post_id,
			'post_url'             => get_permalink( $post_id ) ?: null,
			'dependencies_probed'  => $deps,
			'sitevault_snapshot'   => $sitevault_snapshot_data,
			'audit'                => $audit_data,
			'seo_meta_snapshot'    => $seo_meta_snapshot,
			'schema'               => $schema_envelope,
			'redirects_created'    => $redirect_ids_created,
			'indexnow'             => $indexnow_data,
			'gsc'                  => $gsc_data,
			'cache_purge'          => $cache_purge_data,
			'steps'                => $engine['steps'],
			'counts'               => $engine['counts'],
			'aborted'              => (bool) $engine['aborted'],
			'rolled_back'          => ! empty( $engine['rolled_back'] ),
			'quality'              => $quality,
		];

		$failing_step_text = '';
		if ( $engine['aborted'] ) {
			foreach ( (array) $engine['steps'] as $s ) {
				if ( ( $s['status'] ?? '' ) === 'error' ) {
					$failing_step_text = sprintf(
						' Failing step: %s — %s.',
						(string) ( $s['name'] ?? '?' ),
						(string) ( $s['message'] ?? 'no message' )
					);
					break;
				}
			}
		}

		$quality_suffix = '';
		if ( ! $engine['aborted'] ) {
			if ( $quality['has_unresolved_issues'] || $quality['warnings_count'] > 0 ) {
				$quality_suffix = sprintf(
					' Quality: audit_resolved=%s, %d warning(s). See structuredContent.quality for detail.',
					$quality['audit_resolved'] ? 'true' : 'false',
					$quality['warnings_count']
				);
			}
		}

		$envelope = [
			'isError' => (bool) $engine['aborted'], // audit:helper-bypass-safe -- one reply shape for a completed or aborted run
			'content' => [ [
				'type' => 'text',
				'text' => sprintf(
					'%s post #%d. steps: %d ok, %d skipped, %d error%s.%s%s',
					$engine['aborted'] ? 'ABORTED: the changes were taken back on' : 'Optimized',
					$post_id,
					$engine['counts']['ok'],
					$engine['counts']['skipped'],
					$engine['counts']['error'],
					! empty( $engine['rolled_back'] ) ? sprintf( ' (rolled back %d reversible sub-ops)', $engine['rollback_count'] ) : '',
					$failing_step_text,
					$quality_suffix
				),
			] ],
			'structuredContent' => $structured,
		];
		if ( $undo_meta ) {
			$envelope = self::attach_undo( $envelope, $undo_meta );
		}
		return $envelope;
	}

	private static function handle_prepare_for_launch( array $args ) {
		if ( ! self::has_admin_caps() ) {
			return self::error_envelope( 'insufficient_caps', 'manage_options capability required.' );
		}
		$mode = self::resolve_mode( $args, 'best_effort' );
		$deps = self::probe_dependencies();

		$do_backup = ! empty( $args['backup'] ) && empty( $args['skip_backup'] );

		$steps = [
			[
				'name'       => 'backup_snapshot',
				'reversible' => false,
				'condition'  => $do_backup && $deps['sitevault']['available'],
				'run'        => static function () use ( $deps ) {
					return self::invoke_sitevault_backup( $deps );
				},
			],
			[
				'name'       => 'seo_audit',
				'reversible' => false,
				'read_only'  => true,  
				'condition'  => true,
				'run'        => static function () use ( $deps ) {

					$home_html = @wp_remote_retrieve_body( wp_safe_remote_get( home_url( '/' ), [ 'timeout' => 10 ] ) );
					if ( ! is_string( $home_html ) || $home_html === '' ) {
						return [ 'status' => 'error', 'message' => 'could not fetch home page' ];
					}
					$has_title = (bool) preg_match( '/<title[^>]*>([^<]{5,})<\/title>/i', $home_html );
					$has_meta  = (bool) preg_match( '/<meta[^>]+name=["\']description["\']/i', $home_html );
					$has_h1    = (bool) preg_match( '/<h1[\s>]/i', $home_html );
					return [ 'status' => 'ok', 'data' => [
						'via'      => $deps['seobolt']['available'] ? 'seobolt' : 'native_scan',
						'has_title' => $has_title,
						'has_meta_description' => $has_meta,
						'has_h1'    => $has_h1,
					] ];
				},
			],
			[
				'name'       => 'broken_link_scan_home',
				'reversible' => false,
				'read_only'  => true,  
				'condition'  => true,
				'run'        => static function () {

					$home_html = @wp_remote_retrieve_body( wp_safe_remote_get( home_url( '/' ), [ 'timeout' => 10 ] ) );
					if ( ! is_string( $home_html ) || $home_html === '' ) {
						return [ 'status' => 'error', 'message' => 'could not fetch home page' ];
					}
					$host = wp_parse_url( home_url(), PHP_URL_HOST );
					$urls = [];
					if ( preg_match_all( '/(?:href|src)=["\']([^"\']+)["\']/i', $home_html, $m ) ) {
						foreach ( $m[1] as $u ) {
							$u_host = wp_parse_url( $u, PHP_URL_HOST );
							if ( $u_host && $u_host === $host && strpos( $u, home_url() ) === 0 ) {
								$urls[] = $u;
							}
						}
					}
					$urls  = array_slice( array_unique( $urls ), 0, 25 ); 
					$broken = [];
					foreach ( $urls as $u ) {
						$resp = wp_safe_remote_head( $u, [ 'timeout' => 3 ] );
						$code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
						if ( $code >= 400 || $code === 0 ) {
							$broken[] = [ 'url' => $u, 'code' => $code ];
						}
					}
					return [ 'status' => 'ok', 'data' => [
						'sampled'       => count( $urls ),
						'broken_count'  => count( $broken ),
						'broken_urls'   => $broken,
					] ];
				},
			],
			[
				'name'       => 'perf_check_ttfb',
				'reversible' => false,
				'read_only'  => true,  
				'condition'  => true,
				'run'        => static function () {
					$start = microtime( true );
					$r = wp_safe_remote_get( home_url( '/' ), [ 'timeout' => 8 ] );
					$elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
					$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
					return [ 'status' => is_wp_error( $r ) ? 'error' : 'ok', 'data' => [
						'home_elapsed_ms' => $elapsed_ms,
						'home_status'     => $code,
					] ];
				},
			],
			[
				'name'       => 'security_status',
				'reversible' => false,
				'read_only'  => true,  
				'condition'  => true,
				'run'        => static function () use ( $deps ) {
					$data = [
						'via'                   => $deps['guardpress']['available'] ? 'guardpress' : 'native_scan',
						'wp_debug_enabled'      => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
						'wp_debug_display'      => (bool) ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ),
						'disallow_file_edit'    => (bool) ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ),
						'file_mods_allowed'     => ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
						'is_ssl'                => is_ssl(),
					];
					if ( $deps['guardpress']['available'] ) {
						$data['guardpress_active'] = true;
					}
					return [ 'status' => 'ok', 'data' => $data ];
				},
			],
		];

		$engine = self::run_steps( $steps, $mode );

		return \Royal_MCP\MCP\Support\Envelope::success(
			sprintf(
				'Launch readiness (%s): %d ok, %d skipped, %d error.',
				$mode,
				$engine['counts']['ok'],
				$engine['counts']['skipped'],
				$engine['counts']['error']
			),
			[
				'compose_mode'        => $mode,
				'dependencies_probed' => $deps,
				'steps'               => $engine['steps'],
				'counts'              => $engine['counts'],
				'summary'             => self::launch_summary( $engine['steps'] ),
			]
		);
	}

	private static function handle_monthly_maintenance_report( array $args ) {
		if ( ! self::has_admin_caps() ) {
			return self::error_envelope( 'insufficient_caps', 'manage_options capability required.' );
		}
		$mode = self::resolve_mode( $args, 'best_effort' );

		$include_attachment = (bool) apply_filters( 'royal_mcp_maintenance_report_include_attachment', false );
		$window_days = isset( $args['window_days'] ) ? max( 1, min( 365, (int) $args['window_days'] ) ) : 30;
		$deps = self::probe_dependencies();
		$include_error_log = ! empty( $args['include_error_log'] );

		$steps = [
			[
				'name'       => 'plugin_updates_status',
				'reversible' => false,
				'read_only'  => true,
				'condition'  => true,
				'run'        => static function () {
					if ( ! function_exists( 'get_plugin_updates' ) ) {
						require_once ABSPATH . 'wp-admin/includes/update.php';
						require_once ABSPATH . 'wp-admin/includes/plugin.php';
					}
					$updates      = get_plugin_updates();
					$active_files = array_flip( (array) get_option( 'active_plugins', [] ) );
					$out          = [];
					$skipped      = [ 'backup_dirs' => 0, 'inactive' => 0 ];
					foreach ( (array) $updates as $slug => $info ) {
						$slug_str = (string) $slug;

						if ( strpos( $slug_str, '.bak-' ) !== false || strpos( $slug_str, '.bak/' ) !== false ) {
							$skipped['backup_dirs']++;
							continue;
						}
						if ( ! isset( $active_files[ $slug_str ] ) ) {
							$skipped['inactive']++;
							continue;
						}
						$out[] = [
							'slug'            => $slug_str,
							'name'            => isset( $info->Name ) ? (string) $info->Name : '',
							'current_version' => isset( $info->Version ) ? (string) $info->Version : '',
							'new_version'     => isset( $info->update->new_version ) ? (string) $info->update->new_version : '',
						];
					}
					return [ 'status' => 'ok', 'data' => [
						'updates_available' => count( $out ),
						'plugins'           => $out,
						'skipped'           => $skipped,
					] ];
				},
			],
			[
				'name'       => 'error_log_tail',
				'reversible' => false,
				'read_only'  => true,

				'condition'  => $include_error_log,
				'run'        => static function () {
					$server = new \Royal_MCP\MCP\Server();
					$exec   = new \ReflectionMethod( $server, 'execute_tool' );
					$exec->setAccessible( true );
					$log    = (array) $exec->invoke( $server, 'wp_get_error_log_tail', [ 'lines' => 40 ] );
					$lines  = array_values( array_filter( (array) ( $log['lines'] ?? [] ), 'is_string' ) );
					$max    = 2048;
					$tail   = [];
					$size   = 0;
					$cut    = false;
					foreach ( array_reverse( $lines ) as $line ) {
						$room = $max - $size;
						if ( $room <= 1 ) {
							$cut = true;
							break;
						}
						if ( strlen( $line ) + 1 > $room ) {

							array_unshift( $tail, function_exists( 'mb_strcut' ) ? mb_strcut( $line, 0, $room - 1 ) : substr( $line, 0, $room - 1 ) );
							$cut = true;
							break;
						}
						$size += strlen( $line ) + 1;
						array_unshift( $tail, $line );
					}
					return [ 'status' => 'ok', 'data' => [
						'log_status' => (string) ( $log['status'] ?? 'unknown' ),
						'lines'      => $tail,
						'truncated'  => $cut || count( $tail ) < count( $lines ),
					] ];
				},
			],
			[
				'name'       => 'backup_status',
				'reversible' => false,
				'read_only'  => true,
				'condition'  => true,
				'run'        => static function () use ( $deps ) {
					if ( ! $deps['sitevault']['available'] ) {
						return [ 'status' => 'skipped', 'message' => 'SiteVault not installed — cannot query backup history' ];
					}

					if ( class_exists( '\\Royal_MCP\\Integrations\\SiteVaultHealth' )
						 && method_exists( '\\Royal_MCP\\Integrations\\SiteVaultHealth', 'execute_tool' ) ) {
						$result = \Royal_MCP\Integrations\SiteVaultHealth::execute_tool( 'sv_get_dashboard_summary', [] );
						if ( is_array( $result ) && empty( $result['isError'] ) ) {
							$summary = $result['structuredContent'] ?? [];
							return [ 'status' => 'ok', 'data' => [
								'via'     => 'sv_get_dashboard_summary',
								'summary' => $summary,
							] ];
						}
					}
					return [ 'status' => 'ok', 'data' => [
						'via'  => 'sv_get_dashboard_summary_unavailable',
						'note' => 'The SiteVault summary is not available.',
					] ];
				},
			],
			[
				'name'       => 'audit_summary_window',
				'reversible' => false,
				'read_only'  => true,
				'condition'  => true,
				'run'        => static function () use ( $window_days ) {
					global $wpdb;
					$table = $wpdb->prefix . 'royal_mcp_logs';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence check of our own table
					if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
						return [ 'status' => 'skipped', 'message' => 'The Activity Log table is not on this site, so calls could not be counted.' ];
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Maintenance report scan.
					$rows = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$table} WHERE timestamp >= %s",
						gmdate( 'Y-m-d H:i:s', time() - $window_days * DAY_IN_SECONDS )
					) );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$by_tool = $wpdb->get_results( $wpdb->prepare(
						"SELECT action, status, COUNT(*) AS c
						 FROM {$table}
						 WHERE timestamp >= %s
						 GROUP BY action, status
						 ORDER BY c DESC LIMIT 25",
						gmdate( 'Y-m-d H:i:s', time() - $window_days * DAY_IN_SECONDS )
					), ARRAY_A );
					return [ 'status' => 'ok', 'data' => [
						'window_days'      => $window_days,
						'total_logged_calls' => $rows,
						'top_by_action'    => $by_tool,
					] ];
				},
			],
			[
				'name'       => 'perf_snapshot',
				'reversible' => false,
				'read_only'  => true,
				'condition'  => true,
				'run'        => static function () {
					$start = microtime( true );
					$r = wp_safe_remote_get( home_url( '/' ), [ 'timeout' => 8 ] );
					return [ 'status' => is_wp_error( $r ) ? 'error' : 'ok', 'data' => [
						'home_elapsed_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
						'home_status'     => is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r ),
					] ];
				},
			],
		];

		$engine = self::run_steps( $steps, $mode );

		$envelope = \Royal_MCP\MCP\Support\Envelope::success(
			sprintf(
				'Monthly maintenance report (window=%dd, mode=%s): %d ok, %d skipped, %d error.',
				$window_days,
				$mode,
				$engine['counts']['ok'],
				$engine['counts']['skipped'],
				$engine['counts']['error']
			),
			[
				'compose_mode'        => $mode,
				'window_days'         => $window_days,
				'generated_at'        => current_time( 'mysql', true ) . 'Z',
				'admin_view_url'      => admin_url( 'admin.php?page=royal-mcp-logs' ),
				'dependencies_probed' => $deps,
				'steps'               => $engine['steps'],
				'counts'              => $engine['counts'],
				'attachment_gated'    => ! $include_attachment,
				'attachment_note'     => $include_attachment
					? 'attachment_json included per royal_mcp_maintenance_report_include_attachment filter'
					: 'full attachment gated behind royal_mcp_maintenance_report_include_attachment filter',
			]
		);
		if ( $include_attachment ) {
			$envelope['structuredContent']['attachment_json'] = wp_json_encode( $engine['steps'] );
		}
		return $envelope;
	}

	private static function handle_migrate_theme_assets( array $args ) {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return self::error_envelope( 'insufficient_caps', 'edit_others_posts capability required.' );
		}
		// Folder names are used exactly as given (themes such as "Divi" have capitals); anything else is refused, not rewritten.
		$slug = static function ( $value ) {
			$value = is_string( $value ) ? trim( $value ) : '';
			return ( preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $value ) && false === strpos( $value, '..' ) ) ? $value : '';
		};
		$from = $slug( $args['from_theme_slug'] ?? '' );
		$to   = $slug( $args['to_theme_slug'] ?? '' );
		if ( $from === '' || $to === '' || $from === $to ) {
			return self::error_envelope( 'invalid_args', 'from_theme_slug and to_theme_slug must be two different theme folder names (letters, digits, dots, hyphens and underscores).' );
		}
		$mode      = self::resolve_mode( $args, 'best_effort' );
		$dry_run   = ! empty( $args['dry_run'] ) || 'report_only' === $mode;
		$post_types = ( isset( $args['post_types'] ) && is_array( $args['post_types'] ) )
			? array_values( array_filter( array_map( 'sanitize_key', $args['post_types'] ) ) )
			: [ 'post', 'page' ];
		$post_types = array_values( array_filter( $post_types, static function ( $pt ) {
			return ! \Royal_MCP\MCP\Support\Post_Access::is_code_type( $pt ) && ! in_array( $pt, [ 'revision', 'nav_menu_item', 'attachment' ], true );
		} ) );
		if ( ! $post_types ) {
			return self::error_envelope( 'invalid_args', 'post_types holds no post type this tool rewrites (code, revisions, menu items and attachments are left alone).' );
		}

		$needle_paths = [
			"/wp-content/themes/{$from}/",
			"\\/wp-content\\/themes\\/{$from}\\/", 
		];
		$replacements = [
			"/wp-content/themes/{$to}/",
			"\\/wp-content\\/themes\\/{$to}\\/",
		];

		global $wpdb;
		$like  = '%' . $wpdb->esc_like( "/wp-content/themes/{$from}/" ) . '%';
		$like2 = '%' . $wpdb->esc_like( "\\/wp-content\\/themes\\/{$from}\\/" ) . '%';
		$type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$sql = "SELECT ID, post_content FROM {$wpdb->posts}
				WHERE post_type IN ($type_placeholders)
				  AND post_status IN ('publish','draft','private','pending','future')
				  AND ( post_content LIKE %s OR post_content LIKE %s )
				ORDER BY ID ASC
				LIMIT 500";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$matches = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $post_types, [ $like, $like2 ] ) ), ARRAY_A );

		$snapshot = [];
		$result = [ 'updated' => [], 'skipped' => [], 'errors' => [] ];
		$content_length_before = 0;
		$content_length_after  = 0;
		foreach ( (array) $matches as $row ) {
			$pid = (int) $row['ID'];
			$orig = (string) $row['post_content'];
			$count = 0;
			$new = str_replace( $needle_paths, $replacements, $orig, $count );
			if ( $count === 0 || $new === $orig ) {
				$result['skipped'][] = [ 'id' => $pid, 'reason' => 'no_effective_change' ];
				continue;
			}
			// A plain replace inside serialized data breaks its stored lengths.
			if ( is_serialized( $orig ) ) {
				$result['skipped'][] = [ 'id' => $pid, 'reason' => 'serialized_content' ];
				continue;
			}

			if ( ! current_user_can( 'edit_post', $pid ) ) {
				$result['not_editable'] = ( $result['not_editable'] ?? 0 ) + 1;
				continue;
			}
			if ( $dry_run ) {
				$result['updated'][] = $pid;
				continue;
			}
			$upd = wp_update_post( [ 'ID' => $pid, 'post_content' => wp_slash( $new ) ], true );
			if ( is_wp_error( $upd ) ) {
				$result['errors'][] = [ 'id' => $pid, 'reason' => $upd->get_error_message() ];
				if ( 'strict' === $mode ) {
					// strict: the first failure stops the run and the posts already rewritten get their content back.
					foreach ( $snapshot as $done ) {
						wp_update_post( [ 'ID' => (int) $done['post_id'], 'post_content' => wp_slash( (string) $done['post_content'] ) ] );
					}
					return self::error_envelope( 'aborted', sprintf( 'Post %d could not be saved (%s). strict mode: the %d posts already rewritten were put back; nothing is changed.', $pid, $upd->get_error_message(), count( $snapshot ) ), [ 'failed_post_id' => $pid ] );
				}
			} else {
				// The content as stored after the write, so an undo can tell a later edit from this change.
				$snapshot[] = [ 'post_id' => $pid, 'post_content' => $orig, 'written' => md5( (string) get_post_field( 'post_content', $pid, 'raw' ) ) ];
				$result['updated'][] = $pid;
				$content_length_before += strlen( $orig );
				$content_length_after  += strlen( $new );
			}
		}

		$undo_meta = null;
		if ( ! $dry_run && ! empty( $snapshot ) ) {
			$undo_meta = self::token( 'wp_migrate_theme_assets', $snapshot );
		}

		$envelope = \Royal_MCP\MCP\Support\Envelope::success(
			sprintf(
				'%sMigrated %d posts (%d skipped, %d errors) from theme "%s" → "%s".',
				$dry_run ? '[dry-run] ' : '',
				count( $result['updated'] ),
				count( $result['skipped'] ),
				count( $result['errors'] ),
				$from,
				$to
			),
			array_merge( $result, [
				'compose_mode' => $mode,
				'dry_run'      => $dry_run,
				'from_theme'   => $from,
				'to_theme'     => $to,
				'post_types'   => $post_types,
				'sample_capped_at' => 500,
				'more_may_remain'  => 500 === count( (array) $matches ),
			] )
		);
		if ( ! $dry_run && ( $content_length_before > 0 || $content_length_after > 0 ) ) {
			$envelope['structuredContent']['telemetry'] = [
				'content_length_before'     => $content_length_before,
				'content_length_after'      => $content_length_after,
				'posts_with_content_writes' => count( $result['updated'] ),
			];
		}
		if ( $undo_meta ) {
			$envelope = self::attach_undo( $envelope, $undo_meta );
		}
		return $envelope;
	}

	private static function undo_publish_and_launch( array $payload, array $token_row ) {
		$post_id  = (int) ( $payload['post_id'] ?? 0 );
		$media_id = (int) ( $payload['media_id'] ?? 0 );

		$pre_status = null;
		if ( $post_id > 0 ) {
			$existing = get_post( $post_id );
			$pre_status = $existing ? (string) $existing->post_status : null;
		}

		$post_deleted  = false;
		$media_deleted = false;
		if ( $post_id > 0 && get_post( $post_id ) ) {
			$post_deleted = (bool) wp_delete_post( $post_id, true );
		}
		$media_kept = false;
		if ( $media_id > 0 && get_post( $media_id ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one lookup: is the image the featured image of another post now
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s AND post_id <> %d", (string) $media_id, $post_id ) );
			if ( $used > 0 ) {
				$media_kept = true;
			} else {
				$media_deleted = (bool) wp_delete_attachment( $media_id, true );
			}
		}
		self::run_rollback( array_filter( (array) ( $payload['created_terms'] ?? [] ), 'is_array' ) );

		$cache_purge_on_undo = null;
		if ( $post_deleted && in_array( (string) $pre_status, [ 'publish', 'inherit' ], true ) ) {
			$deps = self::probe_dependencies();
			if ( ! empty( $deps['forgecache']['available'] ) ) {
				$cache_purge_on_undo = self::invoke_forgecache_purge( $deps );
			}
		}

		$cannot_recall = [];
		if ( ! empty( $payload['indexnow_pushed'] ) ) {
			$cannot_recall[] = 'IndexNow submission (already sent to Bing / Yandex during publish)';
		}
		if ( ! empty( $payload['gsc_pushed'] ) ) {
			$cannot_recall[] = 'Google Search Console recrawl request';
		}
		if ( ! empty( $payload['cache_purged_on_create'] ) ) {
			$cannot_recall[] = 'cache purge on publish (post was live briefly before undo — a search-engine or user request during that window may have observed the post)';
		}

		$summary_parts = [];
		$summary_parts[] = sprintf( 'Deleted post %s', $post_deleted ? "#{$post_id}" : '(none, already gone)' );
		if ( $media_id > 0 ) {
			$summary_parts[] = $media_kept ? sprintf( 'attachment #%d kept (another post uses it now)', $media_id ) : sprintf( 'attachment %s', $media_deleted ? "#{$media_id}" : '(none)' );
		}
		if ( is_array( $cache_purge_on_undo ) && ( $cache_purge_on_undo['status'] ?? '' ) === 'ok' ) {
			$summary_parts[] = 'cache purged';
		}
		$summary = 'Undo: ' . implode( ', ', $summary_parts ) . '.';
		if ( ! empty( $cannot_recall ) ) {
			$summary .= ' Cannot recall: ' . implode( ', ', $cannot_recall ) . '.';
		}

		return [
			'restored_state_summary' => $summary,
			'post_deleted'           => $post_deleted,
			'media_deleted'          => $media_deleted,
			'pre_delete_status'      => $pre_status,
			'cache_purged_on_undo'   => is_array( $cache_purge_on_undo ) ? $cache_purge_on_undo : null,
			'cannot_recall'          => $cannot_recall,
		];
	}

	private static function undo_migrate_theme_assets( array $payload, array $token_row ) {
		$restored = 0;
		$gone     = 0;
		// Posts edited after the rewrite keep those edits: the whole undo waits for force.
		if ( empty( $token_row['force'] ) ) {
			$edited = [];
			foreach ( $payload as $row ) {
				$pid = is_array( $row ) ? (int) ( $row['post_id'] ?? 0 ) : 0;
				if ( $pid > 0 && get_post( $pid ) && isset( $row['written'] ) && md5( (string) get_post_field( 'post_content', $pid, 'raw' ) ) !== (string) $row['written'] ) {
					$edited[] = $pid;
				}
			}
			if ( $edited ) {
				throw new \Royal_MCP\MCP\Support\Tool_Refusal( 'edited_since_write', sprintf( '%d of the rewritten posts were edited afterwards, so nothing was put back. Pass force: true to restore the earlier content anyway; the later edits to those posts are lost.', count( $edited ) ), [ 'edited_post_ids' => array_slice( $edited, 0, 50 ) ] );
			}
		}
		foreach ( $payload as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$pid = (int) ( $row['post_id'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}
			if ( ! get_post( $pid ) || ! current_user_can( 'edit_post', $pid ) ) {
				$gone++;
				continue;
			}
			$upd = wp_update_post( [ 'ID' => $pid, 'post_content' => wp_slash( (string) ( $row['post_content'] ?? '' ) ) ], true );
			if ( is_wp_error( $upd ) ) {
				$gone++;
			} else {
				$restored++;
			}
		}
		return [
			'restored_state_summary' => sprintf( 'Restored prior post_content on %d posts (%d could not be restored).', $restored, $gone ),
			'posts_restored'         => $restored,
		];
	}

	private static function undo_seo_optimize_for_launch( array $payload, array $token_row ) {
		$post_id     = (int) ( $payload['post_id'] ?? 0 );
		$post_exists = $post_id > 0 && get_post( $post_id );

		$summary_parts     = [];
		$seo_meta_restored = false;
		$seo_plugin        = 'none';
		$redirects_deleted = 0;
		$cannot_recall     = [];

		$snap = $payload['seo_meta_snapshot'] ?? null;
		if ( is_array( $snap ) && $post_exists ) {
			$deps     = self::probe_dependencies();
			$restored = self::restore_seo_meta_universal(
				$post_id,
				(string) ( $snap['prev_title']    ?? '' ),
				(string) ( $snap['prev_desc']     ?? '' ),
				(string) ( $snap['prev_focus_kw'] ?? '' ),
				$deps,
				(string) ( $snap['plugin'] ?? '' )  
			);
			if ( $restored['ok'] ) {
				$seo_meta_restored = true;
				$seo_plugin        = (string) $restored['plugin'];

				if ( ! empty( $snap['plugin'] ) && $snap['plugin'] !== $seo_plugin ) {
					$summary_parts[] = sprintf( 'Restored SEO meta on post #%d — original write plugin was %s but %s is now active; wrote to %s', $post_id, (string) $snap['plugin'], $seo_plugin, $seo_plugin );
				} elseif ( ! empty( $restored['plugin_matched_snapshot'] ) ) {
					$summary_parts[] = sprintf( 'Restored SEO meta on post #%d (via %s, matches original write)', $post_id, $seo_plugin );
				} else {
					$summary_parts[] = sprintf( 'Restored SEO meta on post #%d (via %s)', $post_id, $seo_plugin );
				}
			} else {
				$summary_parts[]   = sprintf( 'SEO meta restore failed: %s', (string) ( $restored['message'] ?? 'no supported SEO plugin' ) );
			}
		} elseif ( is_array( $snap ) && ! $post_exists ) {

			$summary_parts[] = sprintf( 'SEO meta restore skipped: post #%d no longer exists', $post_id );
		}

		$rids               = ( isset( $payload['redirect_ids_created'] ) && is_array( $payload['redirect_ids_created'] ) )
			? $payload['redirect_ids_created'] : [];
		$redirects_gone     = 0; 
		if ( ! empty( $rids ) ) {
			if ( ! class_exists( '\\Red_Item' ) ) {
				$summary_parts[] = sprintf( '%d redirect deletions skipped: Redirection plugin no longer active', count( $rids ) );
			} else {
				foreach ( $rids as $rid ) {
					$item  = \Red_Item::get_by_id( (int) $rid );
					$print = (string) ( $payload['redirect_prints'][ (int) $rid ] ?? '' );
					if ( is_object( $item ) && '' !== $print && empty( $token_row['force'] ) && self::redirect_print( (int) $rid ) !== $print ) {
						// Pointed somewhere else since: it is someone's redirect now.
						$summary_parts[] = sprintf( 'Redirect #%d was changed after it was created and was left in place', (int) $rid );
						continue;
					}
					if ( is_object( $item ) && method_exists( $item, 'delete' ) ) {
						$item->delete();
						$redirects_deleted++;
					} else {
						$redirects_gone++;
					}
				}
				if ( $redirects_gone > 0 ) {
					$summary_parts[] = sprintf( 'Deleted %d of %d 301 redirects (%d already gone — deleted manually before undo)', $redirects_deleted, count( $rids ), $redirects_gone );
				} else {
					$summary_parts[] = sprintf( 'Deleted %d of %d 301 redirects', $redirects_deleted, count( $rids ) );
				}
			}
		}

		if ( ! empty( $payload['indexnow_pushed'] ) ) {
			$cannot_recall[] = 'IndexNow submission (already sent to Bing / Yandex)';
		}
		if ( ! empty( $payload['gsc_pushed'] ) ) {
			$cannot_recall[] = 'Google Search Console recrawl request';
		}
		if ( ! empty( $payload['cache_purged'] ) ) {
			$cannot_recall[] = 'cache purge (cache already cleared)';
		}
		if ( ! empty( $payload['sitevault_snapshot'] ) ) {
			$cannot_recall[] = 'SiteVault snapshot preserved (invoke SiteVault directly for full restore)';
		}

		$cache_purge_on_undo = null;
		if ( $post_exists && ( $seo_meta_restored || $redirects_deleted > 0 ) ) {
			$post_obj = get_post( $post_id );
			if ( $post_obj && in_array( (string) $post_obj->post_status, [ 'publish', 'inherit' ], true ) ) {
				$deps = self::probe_dependencies();
				$cache_purge_on_undo = self::purge_cache_for_post( $post_id );
				if ( is_array( $cache_purge_on_undo ) && ( $cache_purge_on_undo['status'] ?? '' ) === 'ok' ) {
					$summary_parts[] = 'cache purged';
				}
			}
		}

		if ( empty( $summary_parts ) ) {
			$summary_parts[] = 'nothing to reverse (no reversible sub-ops in payload)';
		}
		$summary = implode( '. ', $summary_parts );
		if ( ! empty( $cannot_recall ) ) {
			$summary .= '. Cannot recall: ' . implode( ', ', $cannot_recall );
		}
		$summary .= '.';

		return [
			'restored_state_summary'  => $summary,
			'seo_meta_restored'       => $seo_meta_restored,
			'plugin'                  => $seo_plugin,
			'redirects_deleted'       => $redirects_deleted,
			'redirects_already_gone'  => $redirects_gone,
			'post_existed_at_undo'    => (bool) $post_exists,
			'cache_purged_on_undo'    => $cache_purge_on_undo,
			'cannot_recall'           => $cannot_recall,
		];
	}

	private static function resolve_mode( array $args, $default ) {
		$mode = isset( $args['compose_mode'] ) ? sanitize_key( (string) $args['compose_mode'] ) : $default;
		return in_array( $mode, [ 'strict', 'best_effort', 'report_only' ], true ) ? $mode : $default;
	}

	private static function steps_write( array $steps ) {
		foreach ( $steps as $step ) {
			if ( empty( $step['read_only'] ) && ! empty( $step['condition'] ) && false === strpos( (string) ( $step['name'] ?? '' ), 'backup' ) && false === strpos( (string) ( $step['name'] ?? '' ), 'snapshot' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function run_steps( array $steps, $mode ) {
		$records = [];

		$counts  = [ 'ok' => 0, 'skipped' => 0, 'error' => 0, 'planned' => 0 ];
		$aborted = false;
		foreach ( $steps as $step ) {
			$name       = (string) $step['name'];
			$reversible = ! empty( $step['reversible'] );
			$read_only  = ! empty( $step['read_only'] );

			if ( $mode === 'report_only' && ! $read_only ) {
				$records[] = [
					'name'       => $name,
					'status'     => 'planned',
					'reversible' => $reversible,
					'would_run'  => ! empty( $step['condition'] ),
				];
				$counts['planned']++;
				continue;
			}
			if ( empty( $step['condition'] ) ) {
				$records[] = [
					'name'       => $name,
					'status'     => 'skipped',
					'reversible' => $reversible,
					'message'    => 'condition_false',
				];
				$counts['skipped']++;
				continue;
			}
			try {
				$r = call_user_func( $step['run'] );
				if ( ! is_array( $r ) || ! isset( $r['status'] ) ) {
					$r = [ 'status' => 'error', 'message' => 'malformed step return' ];
				}
			} catch ( \Throwable $e ) {
				$r = [ 'status' => 'error', 'message' => self::error_message( $e ) ];
			}
			$status = (string) $r['status'];

			$no_snapshot = [ 'backup_in_progress', 'backup_class_not_callable' ];
			if ( 'strict' === $mode && 'skipped' === $status && in_array( (string) ( $r['data']['reason'] ?? '' ), $no_snapshot, true ) && self::steps_write( $steps ) ) {
				$status       = 'error';
				$r['message'] = 'backup_in_progress' === $r['data']['reason']
					? 'Strict mode stopped here: a SiteVault backup is already running, so this run cannot take its own backup first. Nothing after this step was done. Run it again when that backup has finished, or pass compose_mode best_effort to go on without one.'
					: 'Strict mode stopped here: SiteVault is installed but its backup cannot be started from here, so this run cannot take a backup first. Nothing after this step was done. Pass compose_mode best_effort to go on without one.';
			}
			$rec = [
				'name'       => $name,
				'status'     => $status,
				'reversible' => $reversible,
			];
			if ( isset( $r['message'] ) ) {
				$rec['message'] = (string) $r['message'];
			}
			if ( isset( $r['data'] ) ) {
				$rec['data'] = $r['data'];
			}
			$records[] = $rec;
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			} else {
				$counts['error']++;
			}
			if ( $status === 'error' && $mode === 'strict' ) {
				$aborted = true;
				break;
			}
		}
		return [ 'aborted' => $aborted, 'steps' => $records, 'counts' => $counts ];
	}

	private static function run_rollback( array $ops ) {
		foreach ( $ops as $op ) {
			$id = (int) ( $op['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			if ( ( $op['op'] ?? '' ) === 'delete_media' ) {
				wp_delete_attachment( $id, true );
			} elseif ( ( $op['op'] ?? '' ) === 'delete_post' ) {
				wp_delete_post( $id, true );
			}
		}
		// Terms the run created go last, once the post that used them is gone, and only when nothing else uses them.
		foreach ( $ops as $op ) {
			if ( ( $op['op'] ?? '' ) === 'delete_term' && (int) ( $op['id'] ?? 0 ) > 0 ) {
				$term = get_term( (int) $op['id'], (string) ( $op['tax'] ?? '' ) );
				if ( $term && ! is_wp_error( $term ) && 0 === (int) $term->count ) {
					wp_delete_term( (int) $term->term_id, $term->taxonomy );
				}
			}
		}
	}

	private static function probe_dependencies() {

		return [
			'sitevault' => [
				'available' => self::sitevault_active(),
				'version'   => self::sitevault_version(),
				'tier'      => self::sitevault_pro_active() ? 'pro' : ( self::sitevault_free_active() ? 'free' : null ),
			],
			'forgecache' => [
				'available' => self::forgecache_active(),
				'version'   => self::forgecache_version(),
			],
			'seobolt' => [
				'available' => self::seobolt_active(),
				'version'   => self::seobolt_version(),
			],
			'guardpress' => [
				'available' => self::guardpress_active(),
				'version'   => self::guardpress_version(),
				'tier'      => self::guardpress_pro_active() ? 'pro' : ( self::guardpress_free_active() ? 'free' : null ),
			],
			'seo_third_party' => [

				'yoast'    => class_exists( '\\WPSEO_Meta' ),
				'rankmath' => class_exists( '\\RankMath' ) || defined( 'RANK_MATH_VERSION' ),
				'aioseo'   => class_exists( '\\AIOSEO\\Plugin' ) || function_exists( 'aioseo' ),
				'seopress' => defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_get_service' ),
			],
		];
	}

	private static function sitevault_backup_running( $backup_class ) {
		if ( '\\RB_Async_Backup' === $backup_class ) {
			if ( ! is_callable( [ '\\RB_Backup_Manager', 'instance' ] ) ) {
				return false;
			}
			$active = \RB_Backup_Manager::instance()->get_active_backup();
			if ( ! $active ) {
				return false;
			}
			$async = call_user_func( [ $backup_class, 'instance' ] );
			return ! is_callable( [ $async, 'is_job_alive' ] ) || $async->is_job_alive( (int) $active->id );
		}
		if ( ! is_callable( [ '\\SiteVault_Backup_Manager', 'instance' ] ) ) {
			return false;
		}
		$manager = \SiteVault_Backup_Manager::instance();

		if ( is_callable( [ $manager, 'cleanup_stale_backups' ] ) ) {
			$manager->cleanup_stale_backups();
		}
		return is_callable( [ $manager, 'has_active_backup' ] ) && $manager->has_active_backup();
	}

	private static function invoke_sitevault_backup( array $deps ) {
		if ( ! $deps['sitevault']['available'] ) {
			return [ 'status' => 'skipped', 'message' => 'SiteVault not installed on this site.' ];
		}
		$tier    = $deps['sitevault']['tier'] ?? null;
		$version = $deps['sitevault']['version'] ?? null;

		$backup_class = null;
		if ( $tier === 'pro' && is_callable( [ '\\RB_Async_Backup', 'instance' ] ) ) {
			$backup_class = '\\RB_Async_Backup';
		} elseif ( is_callable( [ '\\SiteVault_Async_Backup', 'instance' ] ) ) {
			$backup_class = '\\SiteVault_Async_Backup';
		} elseif ( is_callable( [ '\\RB_Async_Backup', 'instance' ] ) ) {

			$backup_class = '\\RB_Async_Backup';
		}

		if ( $backup_class === null ) {
			return [
				'status'  => 'skipped',
				'message' => sprintf(
					'SiteVault %s %s detected but no async-backup class is loaded. Invoke the sv_create_backup MCP tool manually.',
					$tier === 'pro' ? 'Pro' : ( $tier === 'free' ? '(Free)' : '' ),
					$version ? $version : ''
				),
				'data'    => [
					'detected_tier'    => $tier,
					'detected_version' => $version,
					'reason'           => 'backup_class_not_callable',
				],
			];
		}

		if ( self::sitevault_backup_running( $backup_class ) ) {
			return [
				'status'  => 'skipped',
				'message' => 'A SiteVault backup is already running — left it to finish instead of starting another.',
				'data'    => [
					'detected_tier'    => $tier,
					'detected_version' => $version,
					'reason'           => 'backup_in_progress',
				],
			];
		}

		$backup_name = sprintf( 'royal-mcp-launch-%s', gmdate( 'Ymd-His' ) );

		try {
			$instance  = call_user_func( [ $backup_class, 'instance' ] );
			$backup_id = $instance->start_backup( [ 'type' => 'full', 'name' => $backup_name ] );
			if ( is_wp_error( $backup_id ) ) {
				return [
					'status'  => 'error',
					'message' => sprintf( 'SiteVault backup failed to start: [%s] %s', $backup_id->get_error_code(), $backup_id->get_error_message() ),
					'data'    => [
						'detected_tier'    => $tier,
						'detected_version' => $version,
						'method'           => ltrim( $backup_class, '\\' ) . '::start_backup',
					],
				];
			}
			return [
				'status'  => 'ok',
				'message' => sprintf( 'SiteVault %s backup queued (id=%d, name=%s). Runs asynchronously in the background — may still be in progress when this reply arrives.', $version ? $version : '', (int) $backup_id, $backup_name ),
				'data'    => [
					'detected_tier'    => $tier,
					'detected_version' => $version,
					'method'           => ltrim( $backup_class, '\\' ) . '::start_backup',
					'backup_id'        => (int) $backup_id,
					'backup_name'      => $backup_name,
					'backup_mode'      => 'async',
					'note'             => 'Backup queued asynchronously. Poll SiteVault admin for completion status.',
				],
			];
		} catch ( \Throwable $e ) {
			return [
				'status'  => 'error',
				'message' => sprintf( 'SiteVault backup threw: %s', self::error_message( $e ) ),
				'data'    => [
					'detected_tier'    => $tier,
					'detected_version' => $version,
					'method'           => ltrim( $backup_class, '\\' ) . '::start_backup',
				],
			];
		}
	}

	private static function invoke_forgecache_purge( array $deps ) {
		if ( ! $deps['forgecache']['available'] ) {
			return [ 'status' => 'skipped', 'message' => 'ForgeCache not installed on this site.' ];
		}
		if ( ! current_user_can( 'manage_options' ) ) { // audit:multisite-manage-options-safe -- the page cache is per site
			return [ 'status' => 'skipped', 'message' => 'Clearing the whole cache needs an administrator; the cache was not cleared.' ];
		}
		$version = $deps['forgecache']['version'] ?? null;

		if ( ! is_callable( [ '\\ForgeCache_Cache', 'clear_all_cache_static' ] ) ) {
			return [
				'status'  => 'skipped',
				'message' => 'ForgeCache active per constant, but ForgeCache_Cache::clear_all_cache_static is not callable. Invoke the fc_clear_cache MCP tool manually.',
				'data'    => [ 'detected_version' => $version, 'reason' => 'purge_api_not_callable' ],
			];
		}
		try {
			\ForgeCache_Cache::clear_all_cache_static();
			return [
				'status'  => 'ok',
				'message' => sprintf( 'ForgeCache %s cache cleared.', $version ? $version : '' ),
				'data'    => [
					'detected_version' => $version,
					'method'           => 'ForgeCache_Cache::clear_all_cache_static',
				],
			];
		} catch ( \Throwable $e ) {
			return [
				'status'  => 'error',
				'message' => sprintf( 'ForgeCache purge threw: %s', self::error_message( $e ) ),
				'data'    => [
					'detected_version' => $version,
					'method'           => 'ForgeCache_Cache::clear_all_cache_static',
				],
			];
		}
	}

	private static function write_seo_meta_universal( $post_id, $title, $desc, $focus_kw, array $deps ) {
		// The same SEO plugin wp_update_seo_meta writes to, so both tools always agree.
		$seo_detected = \Royal_MCP\MCP\Support\Seo_Plugin::detect();
		$wrote_any = false;
		$plugin = 'none';

		if ( 'yoast' === $seo_detected ) {
			$plugin = 'yoast';
			if ( $title !== '' ) { update_post_meta( $post_id, '_yoast_wpseo_title', wp_slash( $title ) ); $wrote_any = true; }
			if ( $desc  !== '' ) { update_post_meta( $post_id, '_yoast_wpseo_metadesc', wp_slash( $desc ) ); $wrote_any = true; }
			if ( $focus_kw !== '' ) { update_post_meta( $post_id, '_yoast_wpseo_focuskw', wp_slash( $focus_kw ) ); $wrote_any = true; }
		}

		elseif ( 'rankmath' === $seo_detected ) {
			$plugin = 'rankmath';
			if ( $title !== '' ) { update_post_meta( $post_id, 'rank_math_title', wp_slash( $title ) ); $wrote_any = true; }
			if ( $desc  !== '' ) { update_post_meta( $post_id, 'rank_math_description', wp_slash( $desc ) ); $wrote_any = true; }
			if ( $focus_kw !== '' ) { update_post_meta( $post_id, 'rank_math_focus_keyword', wp_slash( $focus_kw ) ); $wrote_any = true; }
		}

		elseif ( 'aioseo' === $seo_detected ) {
			$plugin = 'aioseo';
			if ( $title !== '' ) { update_post_meta( $post_id, '_aioseo_title', wp_slash( $title ) ); $wrote_any = true; }
			if ( $desc  !== '' ) { update_post_meta( $post_id, '_aioseo_description', wp_slash( $desc ) ); $wrote_any = true; }
			if ( $focus_kw !== '' ) { update_post_meta( $post_id, '_aioseo_focus_keyphrase', wp_slash( $focus_kw ) ); $wrote_any = true; }
			$aio = \Royal_MCP\MCP\Support\Seo_Plugin::aioseo_write( $post_id, array_filter( [ 'title' => $title, 'description' => $desc, 'focus_keyword' => $focus_kw ], 'strlen' ) );
			if ( true !== $aio ) {
				return [ 'ok' => false, 'plugin' => 'aioseo', 'message' => (string) $aio ];
			}
		}

		elseif ( 'seopress' === $seo_detected ) {
			$plugin = 'seopress';
			if ( $title !== '' ) { update_post_meta( $post_id, '_seopress_titles_title', wp_slash( $title ) ); $wrote_any = true; }
			if ( $desc  !== '' ) { update_post_meta( $post_id, '_seopress_titles_desc', wp_slash( $desc ) ); $wrote_any = true; }
			if ( $focus_kw !== '' ) { update_post_meta( $post_id, '_seopress_analysis_target_kw', wp_slash( $focus_kw ) ); $wrote_any = true; }
		}

		elseif ( 'seobolt' === $seo_detected ) {
			$plugin = 'seobolt';
			if ( $title !== '' ) { update_post_meta( $post_id, '_seobolt_meta_title', wp_slash( $title ) ); $wrote_any = true; }
			if ( $desc  !== '' ) { update_post_meta( $post_id, '_seobolt_meta_description', wp_slash( $desc ) ); $wrote_any = true; }
			if ( $focus_kw !== '' ) { update_post_meta( $post_id, '_seobolt_focus_keyword', wp_slash( $focus_kw ) ); $wrote_any = true; }
		}
		return [ 'ok' => $wrote_any, 'plugin' => $plugin, 'message' => $wrote_any ? '' : 'no SEO plugin detected' ];
	}

	private static function restore_seo_meta_universal( $post_id, $title, $desc, $focus_kw, array $deps, $prefer_plugin = '' ) {
		// The same SEO plugin wp_update_seo_meta writes to, so both tools always agree.
		$seo_detected = \Royal_MCP\MCP\Support\Seo_Plugin::detect();
		$pid = (int) $post_id;

		$key_maps = [
			'yoast'    => [ 't' => '_yoast_wpseo_title',       'd' => '_yoast_wpseo_metadesc',    'k' => '_yoast_wpseo_focuskw' ],
			'rankmath' => [ 't' => 'rank_math_title',          'd' => 'rank_math_description',    'k' => 'rank_math_focus_keyword' ],
			'aioseo'   => [ 't' => '_aioseo_title',            'd' => '_aioseo_description',      'k' => '_aioseo_focus_keyphrase' ],
			'seopress' => [ 't' => '_seopress_titles_title',   'd' => '_seopress_titles_desc',    'k' => '_seopress_analysis_target_kw' ],
			'seobolt'  => [ 't' => '_seobolt_meta_title',      'd' => '_seobolt_meta_description', 'k' => '_seobolt_focus_keyword' ],
		];

		$plugin_matched_snapshot = false;
		$selected_plugin         = null;

		if ( is_string( $prefer_plugin ) && $prefer_plugin !== '' && isset( $key_maps[ $prefer_plugin ] ) ) {
			$selected_plugin         = $prefer_plugin;
			$plugin_matched_snapshot = true;
		}

		if ( $selected_plugin === null ) {
			if ( 'yoast' === $seo_detected ) {
				$selected_plugin = 'yoast';
			} elseif ( 'rankmath' === $seo_detected ) {
				$selected_plugin = 'rankmath';
			} elseif ( 'aioseo' === $seo_detected ) {
				$selected_plugin = 'aioseo';
			} elseif ( 'seopress' === $seo_detected ) {
				$selected_plugin = 'seopress';
			} elseif ( 'seobolt' === $seo_detected ) {
				$selected_plugin = 'seobolt';
			}
		}

		if ( $selected_plugin === null ) {
			return [
				'ok'                      => false,
				'plugin'                  => 'none',
				'plugin_matched_snapshot' => false,
				'message'                 => 'no supported SEO plugin detected',
			];
		}

		$keys = $key_maps[ $selected_plugin ];
		$title    === '' ? delete_post_meta( $pid, $keys['t'] ) : update_post_meta( $pid, $keys['t'], wp_slash( (string) $title ) );
		$desc     === '' ? delete_post_meta( $pid, $keys['d'] ) : update_post_meta( $pid, $keys['d'], wp_slash( (string) $desc ) );
		$focus_kw === '' ? delete_post_meta( $pid, $keys['k'] ) : update_post_meta( $pid, $keys['k'], wp_slash( (string) $focus_kw ) );
		if ( 'aioseo' === $selected_plugin ) {
			\Royal_MCP\MCP\Support\Seo_Plugin::aioseo_write( $pid, [ 'title' => (string) $title, 'description' => (string) $desc, 'focus_keyword' => (string) $focus_kw ] );
		}

		return [
			'ok'                      => true,
			'plugin'                  => $selected_plugin,
			'plugin_matched_snapshot' => $plugin_matched_snapshot,
		];
	}

	private static function verify_schema_present( $post_id ) {
		$url = get_permalink( (int) $post_id );
		if ( ! is_string( $url ) || $url === '' ) {
			return [ 'status' => 'error', 'message' => sprintf( 'could not resolve permalink for post %d', (int) $post_id ) ];
		}

		$parts      = wp_parse_url( $url );
		$home_parts = wp_parse_url( home_url() );
		if ( ! $parts || empty( $parts['host'] ) || empty( $home_parts['host'] ) ) {
			return [ 'status' => 'error', 'message' => 'invalid permalink URL' ];
		}
		if ( strcasecmp( $parts['host'], $home_parts['host'] ) !== 0 ) {
			return [ 'status' => 'error', 'message' => 'permalink host does not match home_url — cannot verify schema' ];
		}
		$safe_path  = isset( $parts['path'] )  ? $parts['path']         : '/';
		$safe_query = isset( $parts['query'] ) ? '?' . $parts['query']  : '';
		$safe_url   = rtrim( home_url(), '/' ) . $safe_path . $safe_query;

		$resp = wp_safe_remote_get( $safe_url, [
			'timeout'    => 8,
			'user-agent' => 'Royal MCP seo_optimize_for_launch schema verify',
		] );
		if ( is_wp_error( $resp ) ) {
			return [ 'status' => 'error', 'message' => 'fetch failed: ' . $resp->get_error_message() ];
		}
		$status = (int) wp_remote_retrieve_response_code( $resp );
		$html   = (string) wp_remote_retrieve_body( $resp );
		if ( $status < 200 || $status >= 300 || $html === '' ) {
			return [ 'status' => 'error', 'message' => sprintf( 'HTTP %d fetching %s', $status, $safe_url ) ];
		}

		$blocks = [];
		if ( preg_match_all( '#<script[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			foreach ( $m[1] as $body ) {
				$decoded = json_decode( trim( (string) $body ), true );
				if ( is_array( $decoded ) ) {
					$blocks[] = $decoded;
				}
			}
		}

		$types = [];
		$target_types = [ 'Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'ItemPage', 'Product' ];
		$walk = static function ( $node ) use ( &$walk, &$types ) {
			if ( ! is_array( $node ) ) return;
			if ( isset( $node['@type'] ) ) {
				$t = $node['@type'];
				if ( is_string( $t ) ) {
					$types[ $t ] = true;
				} elseif ( is_array( $t ) ) {
					foreach ( $t as $tv ) {
						if ( is_string( $tv ) ) $types[ $tv ] = true;
					}
				}
			}
			if ( isset( $node['@graph'] ) && is_array( $node['@graph'] ) ) {
				foreach ( $node['@graph'] as $child ) $walk( $child );
			}
		};
		foreach ( $blocks as $b ) $walk( $b );

		$found_target = false;
		foreach ( $target_types as $tt ) {
			if ( ! empty( $types[ $tt ] ) ) { $found_target = true; break; }
		}

		return [
			'status' => 'ok',
			'data'   => [
				'schema_present' => $found_target,
				'schema_types'   => array_keys( $types ),
				'blocks_found'   => count( $blocks ),
				'note'           => $found_target
					? 'JSON-LD schema detected on rendered post.'
					: 'No Article / WebPage schema detected in rendered post <head>. Enable schema output in your SEO plugin (Yoast / Rank Math / AIOSEO / SEOPress) for this post type.',
			],
		];
	}

	private static function push_indexnow_for_post( $post_id ) {
		$post = get_post( (int) $post_id );
		$type = $post ? get_post_type_object( $post->post_type ) : null;
		if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password
			|| ! $type || ! is_post_type_viewable( $type )
			|| \Royal_MCP\MCP\Support\Seo_Plugin::is_noindex( $post->ID ) ) {
			return [ 'status' => 'skipped', 'message' => sprintf( 'post %d is not public and indexable, so it was not sent to IndexNow', (int) $post_id ) ];
		}
		$url = get_permalink( (int) $post_id );
		if ( ! is_string( $url ) || $url === '' ) {
			return [ 'status' => 'error', 'message' => sprintf( 'could not resolve permalink for post %d', (int) $post_id ) ];
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) || $host === '' ) {
			return [ 'status' => 'error', 'message' => 'cannot resolve site host' ];
		}
		$key = self::get_or_create_indexnow_key();

		$payload = [
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => rest_url( 'royal-mcp/v1/indexnow-key' ),
			'urlList'     => [ $url ],
		];
		$resp = wp_safe_remote_post( 'https://api.indexnow.org/indexnow', [
			'timeout'     => 8,
			'headers'     => [ 'Content-Type' => 'application/json; charset=utf-8' ],
			'body'        => wp_json_encode( $payload ),
			'user-agent'  => 'Royal MCP IndexNow',
		] );
		if ( is_wp_error( $resp ) ) {
			return [ 'status' => 'error', 'message' => 'IndexNow POST failed: ' . $resp->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );

		$ok = ( $code >= 200 && $code < 300 );
		return [
			'status'  => $ok ? 'ok' : 'error',
			'message' => $ok
				? sprintf( 'IndexNow accepted (HTTP %d). Cannot-recall — submission is one-way.', $code )
				: sprintf( 'IndexNow rejected (HTTP %d): %s', $code, wp_remote_retrieve_body( $resp ) ),
			'data'    => [
				'url'          => $url,
				'host'         => $host,
				'endpoint'     => 'https://api.indexnow.org/indexnow',
				'key_location' => $payload['keyLocation'],
				'http_status'  => $code,
			],
		];
	}

	private static function get_or_create_indexnow_key() {
		$key = get_option( 'royal_mcp_indexnow_key', '' );
		if ( ! is_string( $key ) || strlen( $key ) !== 32 || ! ctype_xdigit( $key ) ) {
			$key = bin2hex( random_bytes( 16 ) );
			update_option( 'royal_mcp_indexnow_key', $key, false );
		}
		return $key;
	}

	private static function resolve_or_create_redirection_group() {
		if ( self::$redirection_group_id !== null ) {
			return self::$redirection_group_id;
		}
		if ( ! class_exists( '\\Red_Group' ) ) {
			return 0;
		}

		if ( method_exists( '\\Red_Group', 'get_all_for_module' ) ) {
			$groups = \Red_Group::get_all_for_module( 1 );
			if ( is_array( $groups ) && ! empty( $groups ) ) {
				$first = $groups[0];
				$gid   = is_object( $first ) && method_exists( $first, 'get_id' )
					? (int) $first->get_id()
					: (int) ( is_array( $first ) ? ( $first['id'] ?? 0 ) : 0 );
				if ( $gid > 0 ) {
					self::$redirection_group_id = $gid;
					return $gid;
				}
			}
		}

		$new = \Red_Group::create( 'Royal MCP', 1, true );
		if ( is_wp_error( $new ) || ! is_object( $new ) || ! method_exists( $new, 'get_id' ) ) {
			return 0;
		}
		self::$redirection_group_id = (int) $new->get_id();
		return self::$redirection_group_id;
	}

	private static function push_gsc_for_post( $post_id ) {
		$url = get_permalink( (int) $post_id );
		if ( ! is_string( $url ) || $url === '' ) {
			return [ 'status' => 'error', 'message' => sprintf( 'could not resolve permalink for post %d', (int) $post_id ) ];
		}
		$site_kit_active = defined( 'GOOGLESITEKIT_VERSION' ) || class_exists( '\\Google\\Site_Kit\\Plugin' );
		if ( ! $site_kit_active ) {
			return [ 'status' => 'skipped', 'message' => 'Google Site Kit not active — GSC push requires Site Kit with Search Console module' ];
		}

		return [
			'status'  => 'skipped',
			'message' => 'GSC push skipped: nothing was sent to Google. Site Kit has no programmatic recrawl request; submit this URL with URL Inspection in Search Console.',
			'data'    => [
				'url'                 => $url,
				'site_kit_active'     => true,
				'programmatic_recrawl' => false,
				'admin_action_hint'   => admin_url( 'admin.php?page=googlesitekit-splash' ),
			],
		];
	}

	private static function purge_cache_for_post( $post_id ) {
		$url                = get_permalink( (int) $post_id );
		$tried              = [];
		$installed_inactive = []; 

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_active = static function ( $folder_prefix ) {
			$active = (array) get_option( 'active_plugins', [] );
			foreach ( $active as $slug ) {
				if ( strpos( $slug, $folder_prefix . '/' ) === 0 ) {
					return true;
				}
			}
			return false;
		};

		$fc_installed = defined( 'FORGECACHE_VERSION' )
			|| class_exists( '\\ForgeCache_Cache' )
			|| $plugin_active( 'forgecache' );
		if ( $fc_installed ) {
			if ( function_exists( 'forgecache_purge_url' ) && is_string( $url ) ) {
				$tried[] = 'forgecache';
				if ( (bool) forgecache_purge_url( $url ) ) {
					return [ 'status' => 'ok', 'message' => 'ForgeCache purged', 'data' => [ 'plugin' => 'forgecache', 'url' => $url, 'scope' => 'url', 'method' => 'forgecache_purge_url' ] ];
				}
			} elseif ( is_callable( [ '\\ForgeCache_Cache', 'clear_all_cache_static' ] ) && current_user_can( 'manage_options' ) ) { // audit:multisite-manage-options-safe -- the page cache is per site

				$tried[] = 'forgecache';
				try {
					call_user_func( [ '\\ForgeCache_Cache', 'clear_all_cache_static' ] );
					return [ 'status' => 'ok', 'message' => 'ForgeCache purged (site-wide via class method)', 'data' => [ 'plugin' => 'forgecache', 'url' => $url, 'scope' => 'all', 'method' => 'ForgeCache_Cache::clear_all_cache_static' ] ];
				} catch ( \Throwable $e ) {
					$installed_inactive[] = 'forgecache';
				}
			} else {
				$installed_inactive[] = 'forgecache';
			}
		}

		$wpr_installed = defined( 'WP_ROCKET_VERSION' ) || class_exists( '\\WP_Rocket\\Plugin' )
			|| $plugin_active( 'wp-rocket' );
		if ( $wpr_installed ) {
			if ( function_exists( 'rocket_clean_post' ) ) {
				$tried[] = 'wp-rocket';
				rocket_clean_post( (int) $post_id );
				return [ 'status' => 'ok', 'message' => 'WP Rocket purged post cache', 'data' => [ 'plugin' => 'wp-rocket', 'url' => $url, 'scope' => 'post' ] ];
			}
			$installed_inactive[] = 'wp-rocket';
		}

		$w3tc_installed = defined( 'W3TC' ) || defined( 'W3TC_VERSION' ) || class_exists( '\\W3TC\\Root_Environment' )
			|| $plugin_active( 'w3-total-cache' );
		if ( $w3tc_installed ) {
			if ( function_exists( 'w3tc_pgcache_flush_post' ) ) {
				$tried[] = 'w3-total-cache';
				w3tc_pgcache_flush_post( (int) $post_id );
				return [ 'status' => 'ok', 'message' => 'W3 Total Cache purged post cache', 'data' => [ 'plugin' => 'w3-total-cache', 'url' => $url, 'scope' => 'post' ] ];
			}
			$installed_inactive[] = 'w3-total-cache';
		}

		$wpsc_installed = defined( 'WPCACHEHOME' ) || function_exists( 'wp_super_cache_init' )
			|| $plugin_active( 'wp-super-cache' );
		if ( $wpsc_installed ) {
			if ( function_exists( 'wp_cache_post_change' ) ) {
				$tried[] = 'wp-super-cache';
				wp_cache_post_change( (int) $post_id );
				return [ 'status' => 'ok', 'message' => 'WP Super Cache purged post cache', 'data' => [ 'plugin' => 'wp-super-cache', 'url' => $url, 'scope' => 'post' ] ];
			}
			$installed_inactive[] = 'wp-super-cache';
		}

		if ( ! empty( $installed_inactive ) ) {
			return [
				'status'  => 'skipped',
				'message' => sprintf(
					'cache plugin(s) installed but purge capability not currently exposed: %s. Enable the page-cache module in the plugin admin to unlock post-scoped purge.',
					implode( ', ', $installed_inactive )
				),
				'data'    => [ 'installed_inactive' => $installed_inactive, 'tried' => $tried ],
			];
		}
		return [
			'status'  => 'skipped',
			'message' => 'no cache plugin detected (ForgeCache / WP Rocket / W3 Total Cache / WP Super Cache)',
			'data'    => [ 'installed_inactive' => [], 'tried' => $tried ],
		];
	}

	private static function read_seo_meta_universal( $post_id, array $deps ) {
		// The same SEO plugin wp_update_seo_meta writes to, so both tools always agree.
		$seo_detected = \Royal_MCP\MCP\Support\Seo_Plugin::detect();
		$pid = (int) $post_id;
		if ( 'yoast' === $seo_detected ) {
			return [
				'ok'            => true,
				'plugin'        => 'yoast',
				'title'         => (string) get_post_meta( $pid, '_yoast_wpseo_title', true ),
				'description'   => (string) get_post_meta( $pid, '_yoast_wpseo_metadesc', true ),
				'focus_keyword' => (string) get_post_meta( $pid, '_yoast_wpseo_focuskw', true ),
			];
		}
		if ( 'rankmath' === $seo_detected ) {
			return [
				'ok'            => true,
				'plugin'        => 'rankmath',
				'title'         => (string) get_post_meta( $pid, 'rank_math_title', true ),
				'description'   => (string) get_post_meta( $pid, 'rank_math_description', true ),
				'focus_keyword' => (string) get_post_meta( $pid, 'rank_math_focus_keyword', true ),
			];
		}
		if ( 'aioseo' === $seo_detected ) {
			return [
				'ok'            => true,
				'plugin'        => 'aioseo',
				'title'         => (string) ( \Royal_MCP\MCP\Support\Seo_Plugin::aioseo_value( $pid, 'title' ) ?? get_post_meta( $pid, '_aioseo_title', true ) ),
				'description'   => (string) ( \Royal_MCP\MCP\Support\Seo_Plugin::aioseo_value( $pid, 'description' ) ?? get_post_meta( $pid, '_aioseo_description', true ) ),
				'focus_keyword' => (string) ( \Royal_MCP\MCP\Support\Seo_Plugin::aioseo_value( $pid, 'focus_keyword' ) ?? get_post_meta( $pid, '_aioseo_focus_keyphrase', true ) ),
			];
		}
		if ( 'seopress' === $seo_detected ) {
			return [
				'ok'            => true,
				'plugin'        => 'seopress',
				'title'         => (string) get_post_meta( $pid, '_seopress_titles_title', true ),
				'description'   => (string) get_post_meta( $pid, '_seopress_titles_desc', true ),
				'focus_keyword' => (string) get_post_meta( $pid, '_seopress_analysis_target_kw', true ),
			];
		}
		if ( 'seobolt' === $seo_detected ) {
			return [
				'ok'            => true,
				'plugin'        => 'seobolt',
				'title'         => (string) get_post_meta( $pid, '_seobolt_meta_title', true ),
				'description'   => (string) get_post_meta( $pid, '_seobolt_meta_description', true ),
				'focus_keyword' => (string) get_post_meta( $pid, '_seobolt_focus_keyword', true ),
			];
		}
		return [
			'ok'            => false,
			'plugin'        => 'none',
			'title'         => '',
			'description'   => '',
			'focus_keyword' => '',
		];
	}

	private static function audit_seo_meta_for_launch( $post_id, array $deps ) {
		$pid  = (int) $post_id;
		$post = get_post( $pid );
		if ( ! $post ) {
			return [ 'status' => 'error', 'message' => sprintf( 'post %d not found', $pid ) ];
		}
		$meta = self::read_seo_meta_universal( $pid, $deps );
		if ( ! $meta['ok'] ) {
			return [
				'status' => 'ok',
				'data'   => [
					'plugin'       => 'none',
					'title'        => [ 'value' => '', 'length' => 0, 'issue' => 'no_seo_plugin' ],
					'description'  => [ 'value' => '', 'length' => 0, 'issue' => 'no_seo_plugin' ],
					'issues'       => [ [ 'field' => 'plugin', 'issue' => 'no_seo_plugin', 'note' => 'Auto-fix cannot run without an active SEO plugin.' ] ],
					'issues_found' => 1,
					'note'         => 'No supported SEO plugin detected. Install Yoast, Rank Math, AIOSEO, SEOPress, or SEObolt.',
				],
			];
		}
		$len_fn = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen';
		$title  = (string) $meta['title'];
		$desc   = (string) $meta['description'];
		$t_len  = (int) $len_fn( $title );
		$d_len  = (int) $len_fn( $desc );

		$t_issue = $title === '' ? 'missing' : ( $t_len < 30 ? 'too_short' : ( $t_len > 60 ? 'too_long' : 'ok' ) );
		$d_issue = $desc  === '' ? 'missing' : ( $d_len < 120 ? 'too_short' : ( $d_len > 160 ? 'too_long' : 'ok' ) );

		$issues = [];
		if ( $t_issue !== 'ok' ) {
			$issues[] = [ 'field' => 'title', 'issue' => $t_issue, 'length' => $t_len, 'thresholds' => [ 'min' => 30, 'max' => 60 ] ];
		}
		if ( $d_issue !== 'ok' ) {
			$issues[] = [ 'field' => 'description', 'issue' => $d_issue, 'length' => $d_len, 'thresholds' => [ 'min' => 120, 'max' => 160 ] ];
		}
		return [
			'status' => 'ok',
			'data'   => [
				'plugin'       => (string) $meta['plugin'],
				'title'        => [ 'value' => $title, 'length' => $t_len, 'issue' => $t_issue ],
				'description'  => [ 'value' => $desc,  'length' => $d_len, 'issue' => $d_issue ],
				'issues'       => $issues,
				'issues_found' => count( $issues ),
			],
		];
	}

	private static function rules_based_title( $post_id, $current, array $audit ) {
		$issue = isset( $audit['title']['issue'] ) ? (string) $audit['title']['issue'] : '';
		if ( $issue === 'ok' ) {
			return (string) $current;
		}
		$post = get_post( (int) $post_id );
		$base = ( $post && (string) $post->post_title !== '' ) ? (string) $post->post_title : (string) $current;
		return self::truncate_to_len( $base, 60 );
	}

	private static function rules_based_description( $post_id, $current, array $audit ) {
		$issue = isset( $audit['description']['issue'] ) ? (string) $audit['description']['issue'] : '';
		if ( $issue === 'ok' ) {
			return (string) $current;
		}
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return (string) $current;
		}
		$source = trim( (string) $post->post_excerpt );
		// The body of a private or password-protected post never becomes its public description.
		if ( $source === '' && ( '' !== (string) $post->post_password || 'private' === $post->post_status ) ) {
			return (string) $current;
		}
		if ( $source === '' ) {
			$stripped = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ), true );
			$stripped = preg_replace( '/\s+/u', ' ', (string) $stripped );
			$source   = trim( (string) $stripped );
		}
		if ( $source === '' ) {
			return (string) $current;
		}
		return self::truncate_to_len( $source, 160 );
	}

	private static function aggregate_quality_signals( array $steps ) {
		$explicit_audit_resolved       = true;   
		$has_explicit_audit_signal     = false;  
		$has_audit_signal              = false;  
		$warnings                      = [];
		$issues_found_total            = 0;      
		$schema_status                 = null;

		foreach ( $steps as $s ) {
			$name = (string) ( $s['name'] ?? '' );
			$data = is_array( $s['data'] ?? null ) ? $s['data'] : [];

			if ( array_key_exists( 'audit_resolved', $data ) ) {
				$has_audit_signal          = true;
				$has_explicit_audit_signal = true;
				if ( ! (bool) $data['audit_resolved'] ) {
					$explicit_audit_resolved = false;
				}
			}

			if ( ! empty( $data['warnings'] ) && is_array( $data['warnings'] ) ) {
				foreach ( $data['warnings'] as $w ) {
					if ( is_string( $w ) ) $warnings[] = $w;
				}
			}

			if ( in_array( $name, [ 'audit', 'seo_automation_audit' ], true )
				 && isset( $data['issues_found'] ) ) {
				$has_audit_signal    = true;
				$issues_found_total += (int) $data['issues_found'];
			}

			foreach ( [ 'audit_after', 'post_write_audit', 'current_audit' ] as $nested_key ) {
				if ( isset( $data[ $nested_key ] ) && is_array( $data[ $nested_key ] )
					 && isset( $data[ $nested_key ]['issues_found'] ) ) {
					$has_audit_signal    = true;
					$issues_found_total += (int) $data[ $nested_key ]['issues_found'];
					break; 
				}
			}

			if ( in_array( $name, [ 'verify_schema', 'seo_automation_verify_schema' ], true ) ) {
				$schema_status = (string) ( $s['status'] ?? '' );
			}
		}

		if ( $has_explicit_audit_signal ) {
			$final_audit_resolved = $explicit_audit_resolved;
		} elseif ( $has_audit_signal && $issues_found_total > 0 ) {
			$final_audit_resolved = false;
		} else {
			$final_audit_resolved = true;
		}

		return [
			'audit_resolved'        => $has_audit_signal ? $final_audit_resolved : true,
			'has_audit_signal'      => $has_audit_signal,
			'has_unresolved_issues' => $has_audit_signal ? ! $final_audit_resolved : false,
			'warnings'              => $warnings,
			'warnings_count'        => count( $warnings ),
			'issues_found_total'    => $issues_found_total,
			'schema_status'         => $schema_status,
		];
	}

	private static function warning_hint_for_issue( $issue, $arg_name ) {
		switch ( (string) $issue ) {
			case 'too_short':
				return sprintf( 'set a longer explicit %s (or lengthen the source field the generator reads from)', $arg_name );
			case 'too_long':
				return sprintf( 'set a shorter explicit %s (the generator cannot always find a clean truncation point)', $arg_name );
			case 'missing':
				return sprintf( 'set an explicit %s', $arg_name );
			default:
				return sprintf( 'set an explicit %s', $arg_name );
		}
	}

	private static function truncate_to_len( $str, $max ) {
		$len_fn = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen';
		$sub_fn = function_exists( 'mb_substr' ) ? 'mb_substr' : 'substr';
		$s      = (string) $str;

		if ( $len_fn( $s ) <= (int) $max ) {
			return $s;
		}
		$cut = (string) $sub_fn( $s, 0, (int) $max );
		$sp  = strrpos( $cut, ' ' );
		if ( $sp !== false && $sp >= ( (int) $max - 20 ) ) {
			$cut = substr( $cut, 0, $sp );
		}
		return self::strip_trailing_stopwords( rtrim( $cut ) );
	}

	private static function strip_trailing_stopwords( $str ) {
		static $stopwords = null;
		if ( $stopwords === null ) {
			$stopwords = array_flip( [

				'a', 'an', 'the',

				'and', 'or', 'but', 'so', 'if', 'then', 'than', 'that', 'when', 'while', 'because', 'although', 'though', 'yet', 'nor',

				'in', 'on', 'at', 'to', 'from', 'of', 'for', 'with', 'by', 'into', 'onto', 'up', 'out',
				'over', 'under', 'above', 'below', 'before', 'after', 'during', 'without', 'within',
				'inside', 'outside', 'between', 'among', 'through', 'across', 'about', 'via', 'per',
				'as', 'off', 'down',

				'is', 'are', 'was', 'were', 'be', 'been', 'being', 'am',
				'has', 'have', 'had', 'having',
				'do', 'does', 'did', 'doing',
				'will', 'would', 'shall', 'should', 'may', 'might', 'can', 'could', 'must', 'ought',
			] );
		}

		static $dangling_penult = null;
		if ( $dangling_penult === null ) {
			$dangling_penult = array_flip( [ 'and', 'or', 'but', 'nor', 'yet' ] );
		}

		$cut = trim( (string) $str );

		for ( $i = 0; $i < 30; $i++ ) {

			$cut = rtrim( $cut, " \t\n\r,;:—-" );
			$last_space = strrpos( $cut, ' ' );
			if ( $last_space === false ) {
				break; 
			}
			$last_token = substr( $cut, $last_space + 1 );
			$lc = strtolower( $last_token );
			$last_is_stop = isset( $stopwords[ $lc ] )
				|| ( preg_match( '/^\d+$/', $lc ) === 1 );

			if ( $last_is_stop ) {
				$cut = substr( $cut, 0, $last_space );
				continue;
			}

			$rest = substr( $cut, 0, $last_space );
			$penult_space = strrpos( $rest, ' ' );
			$penult_token = ( $penult_space === false ) ? $rest : substr( $rest, $penult_space + 1 );
			if ( isset( $dangling_penult[ strtolower( $penult_token ) ] ) ) {

				$cut = ( $penult_space === false ) ? '' : substr( $rest, 0, $penult_space );
				continue;
			}
			break;
		}
		return rtrim( $cut );
	}

	private static function sideload_media( $url ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'insufficient_caps', 'Adding images to the media library needs the upload_files capability.' );
		}
		return \Royal_MCP\MCP\Server::sideload_public_image( $url );
	}

	private static function launch_summary( array $step_records ) {
		$summary = [];
		foreach ( $step_records as $s ) {
			$summary[] = sprintf( '[%s] %s', strtoupper( (string) ( $s['status'] ?? '?' ) ), (string) ( $s['name'] ?? '?' ) );
		}
		return $summary;
	}

	private static function error_envelope( $code, $message, array $extra = [] ) {
		return \Royal_MCP\MCP\Support\Envelope::error( (string) $code, (string) $message, $extra );
	}

	private static function sitevault_active() {
		return self::sitevault_pro_active() || self::sitevault_free_active();
	}

	private static function sitevault_pro_active() {
		return defined( 'RB_VERSION' ) && defined( 'RB_IS_PRO' ) && \RB_IS_PRO === true;
	}

	private static function sitevault_free_active() {

		return defined( 'SITEVAULT_VERSION' );
	}

	private static function sitevault_version() {
		if ( defined( 'RB_VERSION' ) ) {
			return (string) \RB_VERSION;
		}
		if ( defined( 'SITEVAULT_VERSION' ) ) {
			return (string) \SITEVAULT_VERSION;
		}
		return null;
	}

	private static function forgecache_active() {
		return defined( 'FORGECACHE_VERSION' );
	}

	private static function forgecache_version() {
		return defined( 'FORGECACHE_VERSION' ) ? (string) \FORGECACHE_VERSION : null;
	}

	private static function guardpress_active() {
		return self::guardpress_pro_active() || self::guardpress_free_active();
	}

	private static function guardpress_pro_active() {
		return defined( 'GUARDPRESS_VERSION' );
	}

	private static function guardpress_free_active() {
		return defined( 'GUARDPRESS_LITE_VERSION' );
	}

	private static function guardpress_version() {
		if ( defined( 'GUARDPRESS_VERSION' ) ) {
			return (string) \GUARDPRESS_VERSION;
		}
		if ( defined( 'GUARDPRESS_LITE_VERSION' ) ) {
			return (string) \GUARDPRESS_LITE_VERSION;
		}
		return null;
	}

	private static function seobolt_active() {

		if ( ! function_exists( 'is_plugin_active' ) ) {
			if ( defined( 'ABSPATH' ) ) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
		}
		if ( function_exists( 'is_plugin_active' ) ) {
			$slugs = [ 'seobolt-pro/seobolt-pro.php', 'seobolt/seobolt.php' ];
			foreach ( $slugs as $slug ) {
				if ( is_plugin_active( $slug ) ) {
					return true;
				}
			}
			return false;
		}
		return defined( 'SEOBOLT_VERSION' );
	}

	private static function seobolt_version() {
		return defined( 'SEOBOLT_VERSION' ) ? (string) \SEOBOLT_VERSION : null;
	}

	// ------------------------------------------------------------
	// Tool definitions
	// ------------------------------------------------------------

	private static function schemas() {
		$mode = [
			'type'        => 'string',
			'enum'        => [ 'strict', 'best_effort', 'report_only' ],
			'description' => 'strict stops at the first failed step and takes back the reversible steps already done; best_effort carries on past failures; report_only runs the read-only steps and only describes the writes, changing nothing.',
		];
		$bool = static function ( $desc ) {
			return [ 'type' => 'boolean', 'description' => $desc ];
		};
		$tool = static function ( $name, $desc, array $props, array $req ) {
			return [ 'name' => $name, 'description' => $desc, 'inputSchema' => [ 'type' => 'object', 'required' => $req, 'properties' => $props, 'additionalProperties' => false ] ];
		};
		$out  = [
			$tool( 'wp_publish_and_launch', 'Publish a post with its launch steps in one call: optional SiteVault backup first, featured image from a public URL, the post, category and tags, SEO title / description / focus keyword (written to the active SEO plugin: Yoast, Rank Math, AIOSEO, SEOPress or SEObolt), optional seo_automation steps, and a cache purge. status defaults to publish, so the post goes LIVE unless you pass status "draft". compose_mode defaults to strict: a failed step stops the run and the steps already done are taken back. The reply lists every step with its result. An IndexNow submission cannot be recalled. Undo removes the post, the image it uploaded (unless another post uses it by then) and categories or tags the call created that nothing else uses. For a plain publish without these steps use wp_publish_and_promote. Requires publish_posts.', [
				'title'              => [ 'type' => 'string', 'description' => 'Post title.' ],
				'content'            => [ 'type' => 'string', 'description' => 'Post body (HTML or blocks).' ],
				'status'             => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'description' => 'Defaults to publish.' ],
				'publish_date'       => [ 'type' => 'string', 'description' => 'YYYY-MM-DD HH:MM:SS. A future date with status publish schedules the post.' ],
				'featured_image_url' => [ 'type' => 'string', 'description' => 'Public image URL to add to the media library and set as the featured image. Needs upload_files.' ],
				'category'           => [ 'type' => 'string', 'description' => 'Category name; created when it does not exist (needs the capability to manage categories).' ],
				'tags'               => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Tag names; WordPress creates the ones that do not exist.' ],
				'seo_title'          => [ 'type' => 'string', 'description' => 'SEO title for the active SEO plugin.' ],
				'seo_description'    => [ 'type' => 'string', 'description' => 'Meta description for the active SEO plugin.' ],
				'seo_focus_keyword'  => [ 'type' => 'string', 'description' => 'Focus keyword for the active SEO plugin.' ],
				'compose_mode'       => $mode,
				'pre_backup'         => $bool( 'Start a SiteVault backup before writing. Default true when SiteVault is active and you are an administrator; skipped with a note otherwise.' ),
				'post_purge_cache'   => $bool( 'Purge ForgeCache after publishing. Default true when ForgeCache is active; skipped with a note otherwise.' ),
				'seo_automation'     => [
					'type'        => 'object',
					'description' => 'Optional extra steps after the post exists. All default false.',
					'properties'  => [
						'generate_meta'    => $bool( 'Fill an empty SEO title or description from the post title and its first paragraph. Values you passed are never replaced.' ),
						'generate_schema'  => $bool( 'Check that the published page carries JSON-LD Article or WebPage schema, and report the types found. Checks only; the SEO plugin produces the schema.' ),
						'push_to_indexnow' => $bool( 'Send the post URL to IndexNow (Bing, Yandex), with a site key made on first use. Cannot be recalled.' ),
						'push_to_gsc'      => $bool( 'Reserved: nothing is sent to Google Search Console; the step is reported as skipped with the URL to submit by hand.' ),
					],
				],
			], [ 'title', 'content' ] ),
			$tool( 'seo_optimize_for_launch', 'Get one existing post ready for launch: optional SiteVault backup, audit of its stored SEO title and description, optional rule-based fixes for a missing, too short or too long title / description, a check for JSON-LD schema on the page, optional 301 redirects from old slugs (needs the Redirection plugin and manage_options), optional IndexNow submission, and a cache purge for the post. compose_mode defaults to strict. Undo restores the earlier SEO title and description and removes the redirects it created; an IndexNow submission and a cache purge cannot be taken back, and the backup is not restored by undo. Not for code snippets, attachments, revisions or trashed items; the body of a private or password-protected post is never used for its description. Requires edit rights on the post.', [
				'post_id'      => [ 'type' => 'integer', 'description' => 'The post to optimise.' ],
				'fixes'        => [
					'type'        => 'object',
					'description' => 'Which steps run.',
					'properties'  => [
						'auto_fix_title'       => $bool( 'Write a rule-based SEO title when the audit finds it missing, too short or too long. Default false.' ),
						'auto_fix_description' => $bool( 'Write a rule-based meta description when the audit finds it missing, too short or too long. Default false.' ),
						'generate_schema'      => $bool( 'Check the page for JSON-LD Article or WebPage schema. Default true. Checks only.' ),
						'add_301_from_slugs'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Old URL slugs to redirect (301) to this post. A slug that already leads to the post is skipped.' ],
						'push_to_indexnow'     => $bool( 'Send the post URL to IndexNow (Bing, Yandex). Cannot be recalled. Default false.' ),
						'push_to_gsc'          => $bool( 'Reserved: nothing is sent; reported as skipped with the URL to submit by hand. Default false.' ),
						'purge_cache'          => $bool( 'Purge the cache for the post URL (ForgeCache, WP Rocket, W3 Total Cache or WP Super Cache, whichever is active). Default true.' ),
					],
				],
				'snapshot'     => $bool( 'Start a SiteVault backup before any write. Default true when SiteVault is active and you are an administrator.' ),
				'compose_mode' => $mode,
			], [ 'post_id' ] ),
			$tool( 'wp_prepare_for_launch', 'Site readiness check before a launch: SEO audit (SEObolt when active, otherwise basic checks), a broken-link scan of the links on the home page that stay on this site, a home page response time check, and security status (GuardPress when active, otherwise basic checks). Changes nothing unless backup is true, which first starts a SiteVault backup. compose_mode defaults to best_effort. Administrators only.', [
				'compose_mode' => $mode,
				'backup'       => $bool( 'Start a SiteVault backup before the checks. Default false. Skipped while another backup is running.' ),
				'skip_backup'  => $bool( 'True always skips the backup.' ),
			], [] ),
			$tool( 'wp_monthly_maintenance_report', 'Read-only monthly summary: plugin updates waiting, core and PHP versions, the latest SiteVault backup (when SiteVault is active), a count of the calls Royal MCP logged in the window (when its activity log is on), and a home page response time. window_days defaults to 30. Administrators only.', [
				'compose_mode'      => $mode,
				'window_days'       => [ 'type' => 'integer', 'description' => 'Days to look back, 1 to 365. Default 30.' ],
				'include_error_log' => $bool( 'Also return the last lines of the PHP error log (secrets removed, at most 2 KB; lines can name server paths). Default false.' ),
			], [] ),
			$tool( 'wp_migrate_theme_assets', 'After a theme change, rewrite theme asset URLs in post content from /wp-content/themes/{from}/ to /wp-content/themes/{to}/ on the given post types (plain and JSON-escaped forms). Pass dry_run true first to see what would change. At most 500 posts per call (more_may_remain says when to run it again). compose_mode report_only is the same as dry_run; strict stops at the first post that cannot be saved and puts the rewritten ones back. Serialized content is skipped. Every changed post is recorded; undo puts the earlier content back. Requires edit_others_posts; posts you may not edit are skipped.', [
				'from_theme_slug' => [ 'type' => 'string', 'description' => 'Folder name of the theme being replaced.' ],
				'to_theme_slug'   => [ 'type' => 'string', 'description' => 'Folder name of the theme to point to.' ],
				'dry_run'         => $bool( 'Report the matches and write nothing. No undo token.' ),
				'compose_mode'    => $mode,
				'post_types'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Post types to scan. Default post and page.' ],
			], [ 'from_theme_slug', 'to_theme_slug' ] ),
		];
		return array_column( $out, null, 'name' );
	}
}
