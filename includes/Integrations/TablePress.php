<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Undo_Store;
use Royal_MCP\MCP\Support\Envelope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TablePress MCP integration: list, read, create, copy and export tables, and
 * change cells. Changing a table ID (it breaks shortcodes), importing from a
 * server path or URL, deleting tables and editing custom CSS are not offered.
 *
 * TablePress's own capabilities apply (tablepress_list_tables, _add_tables,
 * _edit_table, _copy_table, _export_table). Cell HTML is filtered like post
 * content for users without unfiltered_html, as TablePress's editor does.
 */
class TablePress {

	const MAX_ROWS         = 5000;
	const MAX_COLUMNS      = 100;
	const MAX_CELLS        = 100000;
	const MAX_CELL_BYTES   = 65535;
	const MAX_TABLE_BYTES  = 2097152;
	const MAX_CELL_CHANGES = 1000;

	/** Table options a caller may set, with their types. */
	const SETTABLE_OPTIONS = [
		'table_head'                  => 'int',
		'table_foot'                  => 'int',
		'alternating_row_colors'      => 'bool',
		'row_hover'                   => 'bool',
		'print_name'                  => 'bool',
		'print_description'           => 'bool',
		'extra_css_classes'           => 'string',
		'use_datatables'              => 'bool',
		'datatables_sort'             => 'bool',
		'datatables_filter'           => 'bool',
		'datatables_paginate'         => 'bool',
		'datatables_paginate_entries' => 'int',
	];

	public static function is_available() {
		if ( ! class_exists( '\TablePress' ) || ! isset( \TablePress::$model_table ) || ! method_exists( '\TablePress', 'load_class' ) ) {
			return false;
		}
		foreach ( [ 'load', 'load_all', 'add', 'save', 'copy', 'delete', 'table_exists', 'prepare_table', 'get_table_template', 'sanitize' ] as $method ) {
			if ( ! method_exists( \TablePress::$model_table, $method ) ) {
				return false;
			}
		}
		return true;
	}

	public static function get_tools() {
		$table_id = [ 'type' => 'string', 'description' => 'Table ID, as in the shortcode [table id=... /] (from tablepress_list_tables).' ];
		$options  = [
			'type'        => 'object',
			'description' => 'Optional table options: table_head / table_foot (rows used as header / footer, 0 or more), alternating_row_colors, row_hover, print_name, print_description, extra_css_classes, use_datatables, datatables_sort, datatables_filter, datatables_paginate, datatables_paginate_entries. Others are ignored.',
		];
		return [
			[
				'name'        => 'tablepress_list_tables',
				'description' => 'List TablePress tables: ID, name, description, size, author, last change and shortcode. Paged: {tables, page, per_page, total, total_pages}. Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'search'   => [ 'type' => 'string', 'description' => 'Only tables whose name or description contains this text.' ],
						'page'     => [ 'type' => 'integer', 'description' => 'Page number, 1-indexed (default 1).' ],
						'per_page' => [ 'type' => 'integer', 'description' => 'Tables per page (default 50, max 100).' ],
					],
				],
			],
			[
				'name'        => 'tablepress_get_table',
				'description' => 'Get one TablePress table: cells as rows of strings (row 1 is the header when table_head is 1), options, which rows and columns are visible, and the shortcode. Large tables are cut at max_rows (truncated: true). Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'table_id' => $table_id,
						'max_rows' => [ 'type' => 'integer', 'description' => 'Rows to return (default 500, max 5000).' ],
					],
					'required'   => [ 'table_id' ],
				],
			],
			[
				'name'        => 'tablepress_create_table',
				'description' => 'Create a TablePress table from rows of cells. Short rows are padded so every row has the same number of columns. Up to 5000 rows, 100 columns and 100,000 cells. Cell HTML is filtered like post content unless you may post unfiltered HTML. The table appears on the site only where its shortcode is placed. Returns the new table ID, its shortcode and an undo token (undo deletes the table if it is unchanged).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'name'        => [ 'type' => 'string' ],
						'description' => [ 'type' => 'string' ],
						'data'        => [ 'type' => 'array', 'items' => [ 'type' => 'array', 'items' => [ 'type' => [ 'string', 'number', 'boolean', 'null' ] ] ], 'description' => 'Rows of cells, e.g. [["Plan","Price"],["Basic","9"]].' ],
						'options'     => $options,
					],
					'required'   => [ 'name', 'data' ],
				],
			],
			[
				'name'        => 'tablepress_update_cells',
				'description' => 'Change cells of a TablePress table by row and column (both 1-indexed; row 1 is the first row, usually the header). Every cell must already exist; the table size does not change. Up to 1000 cells per call. Cell HTML is filtered like post content unless you may post unfiltered HTML. dry_run reports before / after without writing. Changes every page that shows the table; returns an undo token (undo refuses if a changed cell was edited again).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'table_id' => $table_id,
						'cells'    => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'row'    => [ 'type' => 'integer' ],
									'column' => [ 'type' => 'integer' ],
									'value'  => [ 'type' => [ 'string', 'number', 'boolean', 'null' ] ],
								],
								'required'   => [ 'row', 'column', 'value' ],
							],
						],
						'dry_run'  => [ 'type' => 'boolean' ],
					],
					'required'   => [ 'table_id', 'cells' ],
				],
			],
			[
				'name'        => 'tablepress_copy_table',
				'description' => 'Copy a TablePress table (cells, options, visibility) to a new table named "Copy of ...". Returns the new table ID, its shortcode and an undo token (undo deletes the copy if it is unchanged).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [ 'table_id' => $table_id ],
					'required'   => [ 'table_id' ],
				],
			],
			[
				'name'        => 'tablepress_export_table',
				'description' => 'Export a TablePress table as CSV, JSON or HTML text (up to 2 MB). Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'table_id'      => $table_id,
						'format'        => [ 'type' => 'string', 'enum' => [ 'csv', 'json', 'html' ], 'description' => 'Default csv.' ],
						'csv_delimiter' => [ 'type' => 'string', 'enum' => [ ',', ';', 'tab' ], 'description' => 'CSV only (default comma).' ],
					],
					'required'   => [ 'table_id' ],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		// The capability check comes first, so callers who could never use these
		// tools are not told whether TablePress is installed.
		if ( ! self::is_available() ) {
			// TablePress's capabilities do not exist while it is inactive; administrators get the plain answer.
			throw new \Exception( current_user_can( 'manage_options' ) ? 'TablePress is not active' : 'You do not have permission to use TablePress tools.' );
		}
		if ( ! current_user_can( 'tablepress_list_tables' ) ) {
			throw new \Exception( 'You do not have permission to use TablePress tools.' );
		}
		$args = is_array( $args ) ? $args : [];

		switch ( $name ) {
			case 'tablepress_list_tables':
				return self::list_tables( $args );
			case 'tablepress_get_table':
				return self::get_table( $args );
			case 'tablepress_create_table':
				return self::create_table( $args );
			case 'tablepress_update_cells':
				return self::update_cells( $args );
			case 'tablepress_copy_table':
				return self::copy_table( $args );
			case 'tablepress_export_table':
				return self::export_table( $args );
			default:
				throw new \Exception( 'Unknown TablePress tool: ' . esc_html( (string) $name ) );
		}
	}

	private static function list_tables( array $args ) {
		$per_page = (int) max( 1, min( is_scalar( $args['per_page'] ?? null ) ? (float) $args['per_page'] : 50, 100 ) );
		$page     = (int) max( 1, min( is_scalar( $args['page'] ?? null ) ? (float) $args['page'] : 1, 1000000 ) );
		$search   = isset( $args['search'] ) && is_string( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';

		$model = \TablePress::$model_table;
		$rows  = [];
		foreach ( $model->load_all( true ) as $id ) {
			$table = $model->load( (string) $id, false, false );
			if ( is_wp_error( $table ) ) {
				continue;
			}
			if ( '' !== $search && false === stripos( $table['name'] . ' ' . $table['description'], $search ) ) {
				continue;
			}
			$rows[] = $table;
		}
		$total = count( $rows );
		$out   = [];
		foreach ( array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ) as $table ) {
			$full = $model->load( (string) $table['id'], true, false );
			$data = ! is_wp_error( $full ) && is_array( $full['data'] ?? null ) ? $full['data'] : [];
			$out[] = [
				'id'            => (string) $table['id'],
				'name'          => (string) $table['name'],
				'description'   => (string) $table['description'],
				'rows'          => count( $data ),
				'columns'       => $data ? count( (array) $data[0] ) : 0,
				'author'        => (int) $table['author'],
				'last_modified' => (string) $table['last_modified'],
				'shortcode'     => self::shortcode( (string) $table['id'] ),
			];
		}
		return [
			'tables'      => $out,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => (int) max( 1, ceil( $total / $per_page ) ),
		];
	}

	private static function get_table( array $args ) {
		$id    = self::table_id( $args );
		$table = self::load_for( $id, 'tablepress_edit_table', 'read' );
		$max   = (int) max( 1, min( is_scalar( $args['max_rows'] ?? null ) ? (float) $args['max_rows'] : 500, self::MAX_ROWS ) );
		$data  = (array) $table['data'];
		return [
			'id'            => $id,
			'name'          => (string) $table['name'],
			'description'   => (string) $table['description'],
			'author'        => (int) $table['author'],
			'last_modified' => (string) $table['last_modified'],
			'rows'          => count( $data ),
			'columns'       => $data ? count( (array) $data[0] ) : 0,
			'data'          => array_slice( $data, 0, $max ),
			'truncated'     => count( $data ) > $max,
			'options'       => (array) ( $table['options'] ?? [] ),
			'visibility'    => (array) ( $table['visibility'] ?? [] ),
			'shortcode'     => self::shortcode( $id ),
		];
	}

	private static function create_table( array $args ) {
		if ( ! current_user_can( 'tablepress_add_tables' ) ) {
			throw new \Exception( 'You do not have permission to create TablePress tables.' );
		}
		$name = isset( $args['name'] ) && is_string( $args['name'] ) ? trim( $args['name'] ) : '';
		if ( '' === $name ) {
			throw new \Exception( 'name is required.' );
		}
		$description = isset( $args['description'] ) && is_string( $args['description'] ) ? $args['description'] : '';
		$data        = self::clean_data( $args['data'] ?? null );
		$columns     = count( $data[0] );

		$model     = \TablePress::$model_table;
		$new_table = [
			'name'        => $name,
			'description' => $description,
			'data'        => $data,
			'options'     => self::clean_options( $args['options'] ?? null ),
			'visibility'  => [
				'rows'    => array_fill( 0, count( $data ), 1 ),
				'columns' => array_fill( 0, $columns, 1 ),
			],
		];
		$table = $model->prepare_table( $model->get_table_template(), $new_table, false );
		if ( is_wp_error( $table ) ) {
			throw new \Exception( 'The table could not be prepared: ' . esc_html( $table->get_error_code() ) );
		}
		$new_id = $model->add( $table );
		if ( is_wp_error( $new_id ) ) {
			throw new \Exception( 'The table could not be created: ' . esc_html( $new_id->get_error_code() ) );
		}
		$stored   = $model->load( (string) $new_id, true, false );
		$filtered = [];
		foreach ( is_wp_error( $stored ) ? [] : (array) $stored['data'] as $r => $row ) {
			foreach ( (array) $row as $c => $cell ) {
				if ( (string) $cell !== (string) ( $data[ $r ][ $c ] ?? '' ) ) {
					$filtered[] = [ 'row' => $r + 1, 'column' => $c + 1, 'saved' => (string) $cell ];
				}
			}
		}
		return self::created_reply(
			(string) $new_id,
			'tablepress_create_table',
			sprintf( 'Created table %s (%d x %d)%s.', $new_id, count( $data ), $columns, $filtered ? '; some HTML was filtered' : '' ),
			$filtered ? [ 'filtered_cells' => $filtered ] : []
		);
	}

	private static function update_cells( array $args ) {
		$id    = self::table_id( $args );
		$table = self::load_for( $id, 'tablepress_edit_table', 'change' );
		if ( ! empty( $table['is_corrupted'] ) ) {
			throw new \Exception( 'Table ' . esc_html( $id ) . ' is corrupted in the database; repair it in TablePress before changing cells.' );
		}
		$cells = $args['cells'] ?? null;
		if ( ! is_array( $cells ) || [] === $cells ) {
			throw new \Exception( 'cells must be a non-empty list of {row, column, value}.' );
		}
		if ( count( $cells ) > self::MAX_CELL_CHANGES ) {
			throw new \Exception( sprintf( 'At most %d cells per call.', self::MAX_CELL_CHANGES ) );
		}
		$data    = (array) $table['data'];
		$rows    = count( $data );
		$columns = $rows ? count( (array) $data[0] ) : 0;

		$changes = [];
		foreach ( array_values( $cells ) as $i => $cell ) {
			if ( ! is_array( $cell ) || ! array_key_exists( 'row', $cell ) || ! array_key_exists( 'column', $cell ) || ! array_key_exists( 'value', $cell ) ) {
				throw new \Exception( sprintf( 'cells[%d] needs row, column and value.', $i ) );
			}
			$r = self::positive_int( $cell['row'] );
			$c = self::positive_int( $cell['column'] );
			if ( null === $r || null === $c || $r > $rows || $c > $columns ) {
				throw new \Exception( sprintf( 'cells[%d]: row and column must be whole numbers inside the table (%d rows x %d columns, counting from 1).', $i, $rows, $columns ) );
			}
			$value = self::cell_string( $cell['value'], sprintf( 'cells[%d]', $i ) );
			$changes[ ( $r - 1 ) . ':' . ( $c - 1 ) ] = [ $r - 1, $c - 1, $value ];
		}

		$report = [];
		foreach ( $changes as [ $r, $c, $value ] ) {
			$before = (string) $data[ $r ][ $c ];
			if ( $before === $value ) {
				continue;
			}
			$report[] = [ 'row' => $r + 1, 'column' => $c + 1, 'before' => $before, 'after' => $value ];
		}
		if ( ! empty( $args['dry_run'] ) ) {
			return [ 'dry_run' => true, 'table_id' => $id, 'changes' => $report, 'unchanged' => count( $changes ) - count( $report ) ];
		}
		if ( [] === $report ) {
			return Envelope::success( 'No cell changed: every value was already in place.', [ 'table_id' => $id, 'changed' => 0 ] );
		}

		$new     = $data;
		$touched = [];
		foreach ( $report as $change ) {
			$new[ $change['row'] - 1 ][ $change['column'] - 1 ] = $change['after'];
			$touched[ ( $change['row'] - 1 ) . ':' . ( $change['column'] - 1 ) ] = true;
		}
		$saved = self::save_data( $table, $new, $touched, false );

		$applied = [];
		$prior   = [];
		$altered = [];
		foreach ( $report as $change ) {
			$stored    = (string) $saved[ $change['row'] - 1 ][ $change['column'] - 1 ];
			$applied[] = [ $change['row'], $change['column'], $stored ];
			$prior[]   = [ $change['row'], $change['column'], $change['before'] ];
			if ( $stored !== $change['after'] ) {
				$altered[] = [ 'row' => $change['row'], 'column' => $change['column'], 'saved' => $stored ];
			}
		}
		$undo = Undo_Store::store( [
			'op'           => 'tablepress_update_cells',
			'summary'      => sprintf( 'Restore %d cell(s) of TablePress table %s', count( $prior ), $id ),
			'target'       => [ 'table_id' => $id, 'table_post_id' => self::post_id( $id ) ],
			'pre_op_state' => [ 'prior_cells' => $prior, 'applied_cells' => $applied, 'rows' => count( $saved ), 'columns' => count( (array) $saved[0] ) ],
		] );
		$struct = [ 'table_id' => $id, 'changed' => count( $report ), 'changes' => $report ];
		if ( $altered ) {
			$struct['filtered_cells'] = $altered;
		}
		return Envelope::success(
			sprintf( 'Changed %d cell(s) in table %s%s.', count( $report ), $id, $altered ? ' (some HTML was filtered)' : '' ),
			$struct,
			$undo
		);
	}

	private static function copy_table( array $args ) {
		$id     = self::table_id( $args );
		$source = self::load_for( $id, 'tablepress_copy_table', 'copy' );
		// TablePress filters the copy's HTML for this account; refuse rather than make a different table.
		$lost = self::cells_lost_to_filtering( $source, (array) $source['data'], [] );
		if ( $lost ) {
			throw new \Exception( sprintf( 'Copying table %s would remove HTML your account cannot post from %s. Ask a user who may post unfiltered HTML to copy it.', esc_html( $id ), esc_html( self::describe_cells( $lost ) ) ) );
		}
		$new_id = \TablePress::$model_table->copy( $id );
		if ( is_wp_error( $new_id ) ) {
			throw new \Exception( 'The table could not be copied: ' . esc_html( $new_id->get_error_code() ) );
		}
		return self::created_reply( (string) $new_id, 'tablepress_copy_table', sprintf( 'Copied table %s to table %s.', $id, $new_id ), [ 'source_table_id' => $id ] );
	}

	private static function export_table( array $args ) {
		$id     = self::table_id( $args );
		$table  = self::load_for( $id, 'tablepress_export_table', 'export' );
		$format = isset( $args['format'] ) && is_string( $args['format'] ) ? $args['format'] : 'csv';
		if ( ! in_array( $format, [ 'csv', 'json', 'html' ], true ) ) {
			throw new \Exception( 'format must be csv, json or html.' );
		}
		$delimiter = isset( $args['csv_delimiter'] ) && is_string( $args['csv_delimiter'] ) ? $args['csv_delimiter'] : ',';
		if ( ! in_array( $delimiter, [ ',', ';', 'tab' ], true ) ) {
			throw new \Exception( 'csv_delimiter must be ",", ";" or "tab".' );
		}
		$exporter = \TablePress::load_class( 'TablePress_Export', 'class-export.php', 'classes' );
		$text     = (string) $exporter->export_table( $table, $format, [ 'csv_delimiter' => $delimiter ] );
		if ( strlen( $text ) > self::MAX_TABLE_BYTES ) {
			throw new \Exception( 'The export is larger than 2 MB; export it from TablePress\'s Export screen instead.' );
		}
		return [ 'table_id' => $id, 'format' => $format, 'content' => $text, 'bytes' => strlen( $text ) ];
	}

	/**
	 * Undo of a create, copy or cell change. Called by the undo tool with the
	 * stored snapshot; refuses when the table changed after the tracked write.
	 */
	public static function undo( array $snapshot ) {
		if ( ! self::is_available() ) {
			throw new \Exception( 'TablePress is no longer active, so this change cannot be undone.' );
		}
		$op  = (string) ( $snapshot['op'] ?? '' );
		$id  = (string) ( $snapshot['target']['table_id'] ?? '' );
		$pre = (array) ( $snapshot['pre_op_state'] ?? [] );
		if ( '' === $id || ! \TablePress::$model_table->table_exists( $id ) ) {
			throw new \Exception( 'Table ' . esc_html( $id ) . ' no longer exists, so there is nothing to undo.' );
		}
		// The ID must still belong to the table the change made or touched.
		$post_id = (int) ( $snapshot['target']['table_post_id'] ?? 0 );
		if ( $post_id <= 0 || self::post_id( $id ) !== $post_id ) {
			throw new \Exception( 'Cannot undo: table ID ' . esc_html( $id ) . ' now belongs to a different table.' );
		}

		if ( 'tablepress_create_table' === $op || 'tablepress_copy_table' === $op ) {
			if ( ! current_user_can( 'tablepress_delete_table', $id ) ) {
				throw new \Exception( 'You do not have permission to delete TablePress table ' . esc_html( $id ) . '.' );
			}
			if ( self::fingerprint( $id ) !== (string) ( $pre['fingerprint'] ?? '' ) ) {
				throw new \Exception( 'Cannot undo: table ' . esc_html( $id ) . ' was changed after it was created (cells, name, description, options or visibility). Delete it in TablePress if it is no longer wanted.' );
			}
			$used_in = self::shortcode_used_in( $id );
			if ( $used_in ) {
				throw new \Exception( sprintf( 'Cannot undo: table %s is placed on post %d. Remove it there first, or delete the table in TablePress.', esc_html( $id ), $used_in ) );
			}
			$deleted = \TablePress::$model_table->delete( $id );
			if ( is_wp_error( $deleted ) ) {
				throw new \Exception( 'The table could not be deleted: ' . esc_html( $deleted->get_error_code() ) );
			}
			return Envelope::success( sprintf( 'Deleted table %s.', $id ), [ 'undone' => true, 'op' => $op, 'table_id' => $id ] );
		}

		if ( 'tablepress_update_cells' === $op ) {
			$table = self::load_for( $id, 'tablepress_edit_table', 'change' );
			$data  = (array) $table['data'];
			if ( count( $data ) !== (int) ( $pre['rows'] ?? -1 ) || count( (array) ( $data[0] ?? [] ) ) !== (int) ( $pre['columns'] ?? -1 ) ) {
				throw new \Exception( 'Cannot undo: table ' . esc_html( $id ) . ' changed size after the tracked change.' );
			}
			foreach ( (array) ( $pre['applied_cells'] ?? [] ) as [ $r, $c, $value ] ) {
				if ( (string) ( $data[ $r - 1 ][ $c - 1 ] ?? '' ) !== (string) $value ) {
					throw new \Exception( sprintf( 'Cannot undo: cell row %d, column %d of table %s was edited after the tracked change.', (int) $r, (int) $c, esc_html( $id ) ) );
				}
			}
			$touched = [];
			foreach ( (array) ( $pre['prior_cells'] ?? [] ) as [ $r, $c, $value ] ) {
				$data[ $r - 1 ][ $c - 1 ] = (string) $value;
				$touched[ ( $r - 1 ) . ':' . ( $c - 1 ) ] = true;
			}
			self::save_data( $table, $data, $touched, true );
			return Envelope::success(
				sprintf( 'Restored %d cell(s) of table %s.', count( $touched ), $id ),
				[ 'undone' => true, 'op' => $op, 'table_id' => $id, 'restored' => count( $touched ) ]
			);
		}

		throw new \Exception( 'Unsupported TablePress undo: ' . esc_html( $op ) );
	}

	// ============ Helpers ============

	private static function created_reply( $new_id, $op, $summary, array $extra = [] ) {
		$stored = \TablePress::$model_table->load( $new_id, true, false );
		$data   = is_wp_error( $stored ) ? [] : (array) $stored['data'];
		$undo   = Undo_Store::store( [
			'op'           => $op,
			'summary'      => sprintf( 'Delete TablePress table %s', $new_id ),
			'target'       => [ 'table_id' => $new_id, 'table_post_id' => self::post_id( $new_id ) ],
			'pre_op_state' => [ 'fingerprint' => self::fingerprint( $new_id ) ],
		] );
		return Envelope::success(
			$summary,
			array_merge(
				[
					'table_id'  => $new_id,
					'shortcode' => self::shortcode( $new_id ),
					'rows'      => count( $data ),
					'columns'   => $data ? count( (array) $data[0] ) : 0,
				],
				$extra
			),
			$undo
		);
	}

	/** Hash of everything about a table a person can change: cells, name, description, options, visibility. */
	private static function fingerprint( $id ) {
		$table = \TablePress::$model_table->load( (string) $id, true, true );
		if ( is_wp_error( $table ) ) {
			return '';
		}
		$options = (array) ( $table['options'] ?? [] );
		unset( $options['last_editor'] );
		return md5( (string) wp_json_encode( [ $table['name'], $table['description'], $table['data'], $options, $table['visibility'] ?? [] ] ) );
	}

	/**
	 * ID of a post (any status but trash) that shows the table through its
	 * shortcode or block, or 0.
	 */
	private static function shortcode_used_in( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'tablepress_table') AND post_status NOT IN ('trash', 'auto-draft') AND ( post_content LIKE %s OR post_content LIKE %s ) AND post_content LIKE %s LIMIT 500",
			'%' . $wpdb->esc_like( '[table' ) . '%',
			'%' . $wpdb->esc_like( 'wp:tablepress/table' ) . '%',
			'%' . $wpdb->esc_like( $id ) . '%'
		) );
		$quoted = preg_quote( (string) $id, '/' );
		foreach ( (array) $rows as $row ) {
			if ( preg_match( '/\[table\b[^\]]*\bid\s*=\s*["\']?' . $quoted . '["\']?(?=[\s\/\]])/i', $row->post_content )
				|| preg_match( '/wp:tablepress\/table\s+\{[^}]*"id"\s*:\s*"?' . $quoted . '"?[,}]/', $row->post_content ) ) {
				return (int) $row->ID;
			}
		}
		return 0;
	}

	/**
	 * Cells (and the name / description) whose HTML TablePress would filter
	 * when this account saves the table, ignoring the cells being written.
	 *
	 * @param array<string,bool> $skip "row:column" (0-indexed) of cells being written.
	 * @return array<int,array{0:int,1:int}> 1-indexed positions; [0, 0] means the name or description.
	 */
	private static function cells_lost_to_filtering( array $table, array $data, array $skip ) {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return [];
		}
		$lost = [];
		foreach ( [ 'name', 'description' ] as $field ) {
			$value = (string) ( $table[ $field ] ?? '' );
			if ( wp_kses_post( $value ) !== $value ) {
				$lost[] = [ 0, 0 ];
				break;
			}
		}
		foreach ( $data as $r => $row ) {
			foreach ( (array) $row as $c => $cell ) {
				if ( isset( $skip[ $r . ':' . $c ] ) ) {
					continue;
				}
				$cell = (string) $cell;
				if ( wp_kses_post( $cell ) !== $cell ) {
					$lost[] = [ $r + 1, $c + 1 ];
				}
			}
		}
		return $lost;
	}

	private static function describe_cells( array $cells ) {
		$parts = [];
		foreach ( array_slice( $cells, 0, 10 ) as [ $r, $c ] ) {
			$parts[] = 0 === $r ? 'the table name or description' : sprintf( 'row %d column %d', $r, $c );
		}
		return implode( ', ', $parts ) . ( count( $cells ) > 10 ? sprintf( ' and %d more', count( $cells ) - 10 ) : '' );
	}

	/**
	 * Save new cell data on a loaded table; returns the cells as stored.
	 * TablePress filters every cell's HTML for accounts without unfiltered_html,
	 * so the save is refused when that would change cells this call does not
	 * write, or (for an undo) the values being restored.
	 *
	 * @param array<string,bool> $touched       "row:column" (0-indexed) of cells written.
	 * @param bool               $exact_touched Refuse when a written value would itself be filtered.
	 */
	private static function save_data( array $table, array $data, array $touched, $exact_touched ) {
		$lost = self::cells_lost_to_filtering( $table, $data, $touched );
		if ( $lost ) {
			throw new \Exception( sprintf( 'Saving table %s would remove HTML your account cannot post from %s, which this change does not touch. Ask a user who may post unfiltered HTML to make it.', esc_html( (string) $table['id'] ), esc_html( self::describe_cells( $lost ) ) ) );
		}
		if ( $exact_touched && ! current_user_can( 'unfiltered_html' ) ) {
			$filtered = [];
			foreach ( array_keys( $touched ) as $key ) {
				[ $r, $c ] = array_map( 'intval', explode( ':', $key ) );
				$cell      = (string) ( $data[ $r ][ $c ] ?? '' );
				if ( wp_kses_post( $cell ) !== $cell ) {
					$filtered[] = [ $r + 1, $c + 1 ];
				}
			}
			if ( $filtered ) {
				throw new \Exception( sprintf( 'Cannot undo: restoring %s needs HTML your account cannot post. Ask a user who may post unfiltered HTML to undo it.', esc_html( self::describe_cells( $filtered ) ) ) );
			}
		}

		$model     = \TablePress::$model_table;
		$rows      = count( $data );
		$columns   = count( (array) $data[0] );
		$new_table = [
			'id'          => $table['id'],
			'name'        => $table['name'],
			'description' => $table['description'],
			'data'        => $data,
			'number'      => [ 'rows' => $rows, 'columns' => $columns ],
			'visibility'  => (array) $table['visibility'],
		];
		$prepared = $model->prepare_table( $table, $new_table, true );
		if ( is_wp_error( $prepared ) ) {
			throw new \Exception( 'The table could not be prepared: ' . esc_html( $prepared->get_error_code() ) );
		}
		$saved = $model->save( $prepared );
		if ( is_wp_error( $saved ) ) {
			throw new \Exception( 'The table could not be saved: ' . esc_html( $saved->get_error_code() ) );
		}
		$after = $model->load( (string) $table['id'], true, false );
		return is_wp_error( $after ) ? $data : (array) $after['data'];
	}

	/**
	 * Load a table the caller may act on. One answer for a missing table and
	 * one the caller may not use, so table IDs cannot be probed.
	 */
	private static function load_for( $id, $cap, $verb ) {
		$model = \TablePress::$model_table;
		if ( ! $model->table_exists( $id ) || ! current_user_can( $cap, $id ) ) {
			throw new \Exception( sprintf( 'No table %s that you may %s.', esc_html( $id ), esc_html( $verb ) ) );
		}
		$table = $model->load( $id, true, true );
		if ( is_wp_error( $table ) ) {
			throw new \Exception( sprintf( 'No table %s that you may %s.', esc_html( $id ), esc_html( $verb ) ) );
		}
		return $table;
	}

	private static function table_id( array $args ) {
		$id = $args['table_id'] ?? null;
		if ( is_int( $id ) ) {
			$id = (string) $id;
		}
		if ( ! is_string( $id ) || ! preg_match( '/^[A-Za-z0-9_-]{1,64}\z/', $id ) ) {
			throw new \Exception( 'table_id is required: letters, numbers, - and _ only.' );
		}
		return $id;
	}

	private static function post_id( $id ) {
		$tables = json_decode( (string) get_option( 'tablepress_tables', '' ), true );
		return (int) ( $tables['table_post'][ $id ] ?? 0 );
	}

	private static function shortcode( $id ) {
		return '[table id=' . $id . ' /]';
	}

	private static function positive_int( $value ) {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && ctype_digit( $value ) && (int) $value > 0 ) {
			return (int) $value;
		}
		return null;
	}

	private static function cell_string( $value, $where ) {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( ! is_scalar( $value ) ) {
			throw new \Exception( esc_html( $where ) . ': a cell must be text or a number.' );
		}
		$value = (string) $value;
		if ( strlen( $value ) > self::MAX_CELL_BYTES ) {
			throw new \Exception( esc_html( $where ) . sprintf( ': a cell may hold at most %d bytes.', self::MAX_CELL_BYTES ) );
		}
		return $value;
	}

	/** Rows of cells as strings, padded to one width, inside the size limits. */
	private static function clean_data( $data ) {
		if ( ! is_array( $data ) || [] === $data ) {
			throw new \Exception( 'data must be a non-empty list of rows, each a list of cells.' );
		}
		if ( count( $data ) > self::MAX_ROWS ) {
			throw new \Exception( sprintf( 'At most %d rows.', self::MAX_ROWS ) );
		}
		$width = 0;
		foreach ( array_values( $data ) as $i => $row ) {
			if ( ! is_array( $row ) ) {
				throw new \Exception( sprintf( 'data[%d] must be a list of cells.', $i ) );
			}
			$width = max( $width, count( $row ) );
		}
		if ( 0 === $width ) {
			throw new \Exception( 'data needs at least one cell.' );
		}
		if ( $width > self::MAX_COLUMNS ) {
			throw new \Exception( sprintf( 'At most %d columns.', self::MAX_COLUMNS ) );
		}
		if ( count( $data ) * $width > self::MAX_CELLS ) {
			throw new \Exception( sprintf( 'At most %d cells.', self::MAX_CELLS ) );
		}
		$clean = [];
		$bytes = 0;
		foreach ( array_values( $data ) as $i => $row ) {
			$out = [];
			foreach ( array_values( $row ) as $j => $cell ) {
				$out[] = self::cell_string( $cell, sprintf( 'data[%d][%d]', $i, $j ) );
				$bytes += strlen( end( $out ) );
			}
			$clean[] = array_pad( $out, $width, '' );
		}
		if ( $bytes > self::MAX_TABLE_BYTES ) {
			throw new \Exception( 'The table is larger than 2 MB.' );
		}
		return $clean;
	}

	private static function clean_options( $options ) {
		if ( null === $options ) {
			return [];
		}
		if ( ! is_array( $options ) ) {
			throw new \Exception( 'options must be an object.' );
		}
		$clean = [];
		foreach ( self::SETTABLE_OPTIONS as $key => $type ) {
			if ( ! array_key_exists( $key, $options ) ) {
				continue;
			}
			$value = $options[ $key ];
			if ( 'bool' === $type ) {
				$clean[ $key ] = (bool) $value;
			} elseif ( 'int' === $type ) {
				$clean[ $key ] = max( 0, (int) $value );
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = $value;
			}
		}
		return $clean;
	}
}
