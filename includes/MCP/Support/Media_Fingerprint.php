<?php
/**
 * File details and fingerprints for media attachments: size, dimensions,
 * SHA-256, where an upload came from, and lookups that let an upload reuse
 * an attachment it already made.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Media_Fingerprint {

	/** Public URL an attachment was downloaded from (URL uploads only). */
	const SOURCE_URL_META = '_royal_mcp_source_url';

	/** SHA-256 of the bytes an upload received, before any resize or convert. */
	const SOURCE_SHA_META = '_royal_mcp_source_sha256';

	/** Signature of the image options an upload used ('' when none). */
	const OPTS_META = '_royal_mcp_upload_opts';

	/** SHA-256 of the stored file, and the size|mtime it was computed for. */
	const FILE_SHA_META     = '_royal_mcp_file_sha256';
	const FILE_SHA_SIG_META = '_royal_mcp_file_sha256_sig';

	/** Files larger than this are not hashed on read. */
	const MAX_HASH_BYTES = 209715200; // 200 MB

	/** Most matches wp_find_media returns. */
	const MAX_MATCHES = 20;

	/**
	 * Signature of an upload's image options, so a reuse only matches an
	 * attachment made with the same conversion.
	 *
	 * @param array $opts convert_to / max_width / max_height / max_kb / quality.
	 * @return string
	 */
	public static function opts_signature( array $opts ) {
		if ( empty( $opts ) ) {
			return '';
		}
		ksort( $opts );
		return sha1( (string) wp_json_encode( $opts ) );
	}

	/**
	 * Remember where an upload came from and what it contained.
	 *
	 * @param int         $attachment_id Attachment.
	 * @param string      $source_sha256 SHA-256 of the received bytes.
	 * @param string      $opts_sig      opts_signature() of the upload.
	 * @param string|null $source_url    Public URL it was downloaded from, or null.
	 */
	public static function record( $attachment_id, $source_sha256, $opts_sig, $source_url = null ) {
		$attachment_id = (int) $attachment_id;
		update_post_meta( $attachment_id, self::SOURCE_SHA_META, (string) $source_sha256 );
		update_post_meta( $attachment_id, self::OPTS_META, (string) $opts_sig );
		if ( null !== $source_url && '' !== $source_url ) {
			update_post_meta( $attachment_id, self::SOURCE_URL_META, esc_url_raw( (string) $source_url ) );
		}
		self::file_sha256( $attachment_id );
	}

	/**
	 * An attachment an earlier upload made from the same bytes (or the same
	 * URL) with the same image options, that the current user can read and
	 * whose file still exists. 0 when there is none.
	 *
	 * @param string $by       'sha' | 'url'.
	 * @param string $value    SHA-256 or URL.
	 * @param string $opts_sig opts_signature() of the new upload.
	 * @return int
	 */
	public static function find_reusable( $by, $value, $opts_sig ) {
		$key = 'url' === $by ? self::SOURCE_URL_META : self::SOURCE_SHA_META;
		$value = 'url' === $by ? esc_url_raw( (string) $value ) : strtolower( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		$ids = get_posts(
			[
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => 10,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- indexed meta_key lookup on attachments only
					'relation' => 'AND',
					[ 'key' => $key, 'value' => $value ],
					[ 'key' => self::OPTS_META, 'value' => (string) $opts_sig ],
				],
			]
		);
		foreach ( $ids as $id ) {
			$file = get_attached_file( (int) $id );
			if ( current_user_can( 'read_post', (int) $id ) && $file && file_exists( $file ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Attachments by source URL or SHA-256 (of the received bytes or of the
	 * stored file), newest first, limited to ones the current user can read.
	 *
	 * @param string $source_url Exact source URL, or ''.
	 * @param string $sha256     64 hex characters, or ''.
	 * @return int[]
	 */
	public static function find( $source_url, $sha256 ) {
		$clauses = [ 'relation' => 'OR' ];
		if ( '' !== $source_url ) {
			$clauses[] = [ 'key' => self::SOURCE_URL_META, 'value' => esc_url_raw( $source_url ) ];
		}
		if ( '' !== $sha256 ) {
			$sha256    = strtolower( $sha256 );
			$clauses[] = [ 'key' => self::SOURCE_SHA_META, 'value' => $sha256 ];
			$clauses[] = [ 'key' => self::FILE_SHA_META, 'value' => $sha256 ];
		}
		if ( count( $clauses ) < 2 ) {
			return [];
		}
		$ids = get_posts(
			[
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => self::MAX_MATCHES * 2,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => $clauses, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- indexed meta_key lookup on attachments only
			]
		);
		$out = [];
		foreach ( $ids as $id ) {
			if ( current_user_can( 'read_post', (int) $id ) ) {
				$out[] = (int) $id;
			}
			if ( count( $out ) >= self::MAX_MATCHES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * SHA-256 of the stored file, cached against its size and modification
	 * time. Null when the file is missing or larger than MAX_HASH_BYTES.
	 *
	 * @param int $attachment_id Attachment.
	 * @return string|null
	 */
	public static function file_sha256( $attachment_id ) {
		$file = get_attached_file( (int) $attachment_id );
		if ( ! $file || ! is_readable( $file ) ) {
			return null;
		}
		$size = (int) filesize( $file );
		if ( $size > self::MAX_HASH_BYTES ) {
			return null;
		}
		$sig    = $size . '|' . (int) filemtime( $file );
		$cached = (string) get_post_meta( (int) $attachment_id, self::FILE_SHA_META, true );
		if ( '' !== $cached && get_post_meta( (int) $attachment_id, self::FILE_SHA_SIG_META, true ) === $sig ) {
			return $cached;
		}
		$hash = hash_file( 'sha256', $file );
		if ( ! is_string( $hash ) ) {
			return null;
		}
		update_post_meta( (int) $attachment_id, self::FILE_SHA_META, $hash );
		update_post_meta( (int) $attachment_id, self::FILE_SHA_SIG_META, $sig );
		return $hash;
	}

	/**
	 * File details for wp_get_media_item and wp_find_media.
	 *
	 * @param int $attachment_id Attachment.
	 * @return array
	 */
	public static function details( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$file          = get_attached_file( $attachment_id );
		$exists        = $file && file_exists( $file );
		$size          = $exists ? (int) filesize( $file ) : null;
		$details       = [
			'file_size' => $size,
			'width'     => null,
			'height'    => null,
			'sha256'    => $exists ? self::file_sha256( $attachment_id ) : null,
		];
		if ( ! $exists ) {
			$details['file_note'] = 'The file for this attachment is missing from the uploads folder.';
		} elseif ( null === $details['sha256'] ) {
			$details['file_note'] = sprintf( 'Files over %d MB are not hashed.', (int) ( self::MAX_HASH_BYTES / 1048576 ) );
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			$details['width']  = (int) $meta['width'];
			$details['height'] = (int) $meta['height'];
		}
		// WordPress scales very large images down and keeps the upload as the original.
		if ( is_array( $meta ) && ! empty( $meta['original_image'] ) && function_exists( 'wp_get_original_image_path' ) ) {
			$original = wp_get_original_image_path( $attachment_id );
			$dims     = $original && file_exists( $original ) ? @getimagesize( $original ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a corrupt original must not break the read
			if ( is_array( $dims ) ) {
				$details['original_width']  = (int) $dims[0];
				$details['original_height'] = (int) $dims[1];
			}
		}

		$source_url = (string) get_post_meta( $attachment_id, self::SOURCE_URL_META, true );
		$source_sha = (string) get_post_meta( $attachment_id, self::SOURCE_SHA_META, true );
		if ( '' !== $source_url ) {
			$details['downloaded_from'] = $source_url;
		}
		if ( '' !== $source_sha ) {
			$details['source_sha256'] = $source_sha;
		}
		return $details;
	}
}
