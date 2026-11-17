<?php
/**
 * Changes to one part of a stored option value: set a key, add an item to a
 * list, remove an item, change a field of a list item. Works on a copy and
 * returns the new value plus a report of what each step changed; the caller
 * stores it. Any step that cannot be applied refuses the whole set.
 *
 * A path is a list of steps from the top of the value. A step is a key
 * (text), a position in a list (whole number, from 0), or a match
 * ({"id": 1234}) that picks the one list item whose field has that value.
 *
 * The report never shows more than a read of the option would: before and
 * after are read out of a redacted copy of the whole value, a match may not
 * test a field a read would hide, and a path may not pass through a part a
 * read hides as a whole.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Option_Patch {

	const MAX_OPERATIONS = 50;
	const MAX_PATH_STEPS = 12;
	const MAX_KEY_LENGTH = 200;
	/** Longest before / after shown per step in the report, as JSON. */
	const REPORT_BYTES = 2000;
	/** Shown in the report where a read would hide the value. */
	const HIDDEN = '[REDACTED]';

	/**
	 * Apply the operations in order.
	 *
	 * @param mixed    $value      Current option value.
	 * @param mixed    $operations The operations argument as sent.
	 * @param callable      $redact     Returns the whole value as a read would show it.
	 * @param callable|null $read_views Returns every view of the whole value that a read tool gives AI
	 *                                  clients (defaults to $redact's). A set may not replace a part that
	 *                                  any of them shows differently from what is stored.
	 * @return array{0:mixed,1:array<int,array<string,mixed>>} New value and one report row per operation.
	 * @throws Tool_Refusal When any operation cannot be applied; nothing is to be stored then.
	 */
	public static function apply( $value, $operations, callable $redact, $read_views = null ) {
		if ( ! is_array( $operations ) || [] === $operations || ! self::is_list( $operations ) ) {
			throw new Tool_Refusal( 'invalid_operations', 'operations must be a non-empty list of operations.' );
		}
		if ( count( $operations ) > self::MAX_OPERATIONS ) {
			throw new Tool_Refusal( 'invalid_operations', sprintf( 'At most %d operations per call.', self::MAX_OPERATIONS ) );
		}
		if ( ! is_array( $value ) ) {
			throw new Tool_Refusal( 'option_not_structured', 'This option does not hold a list or a set of named values, so one part of it cannot be changed. Send the whole value instead.' );
		}
		$report   = [];
		$original = $value;
		// Each read of the whole value is worked out once per distinct value (a large option is costly to read).
		$memo    = [];
		$read_of = static function ( $kind, $v ) use ( $redact, $read_views, &$memo ) {
			try {
				$id = $kind . sha1( serialize( $v ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- memo key only.
			} catch ( \Throwable $e ) {
				$id = null;
			}
			if ( null !== $id && array_key_exists( $id, $memo ) ) {
				return $memo[ $id ];
			}
			$out = 'v' === $kind ? ( is_callable( $read_views ) ? (array) call_user_func( $read_views, $v ) : [ $redact( $v ) ] ) : $redact( $v );
			if ( null !== $id ) {
				if ( count( $memo ) >= 8 ) {
					$memo = [];
				}
				$memo[ $id ] = $out;
			}
			return $out;
		};
		$shown0     = $read_of( 's', $original );
		$touched    = []; // Key paths this call has written so far.
		$structural = false; // A remove moved list items, so key paths no longer line up with the value as read.
		$overlaps   = static function ( array $a, array $b ) {
			$n = min( count( $a ), count( $b ) );
			return array_slice( $a, 0, $n ) === array_slice( $b, 0, $n );
		};
		// What a read shows at a key path: the read the client made, unless this call already wrote that part
		// or a part above it. (A part below it that this call wrote is the client's own data.)
		$seen = static function ( array $keys ) use ( &$value, &$touched, &$structural, $shown0, $read_of ) {
			$clean = ! $structural;
			foreach ( $clean ? $touched : [] as $t ) {
				if ( count( $t ) <= count( $keys ) && array_slice( $keys, 0, count( $t ) ) === $t ) {
					$clean = false;
					break;
				}
			}
			return self::pick( $clean ? $shown0 : $read_of( 's', $value ), $keys );
		};
		// Whether replacing an existing part would overwrite something a read hides. Judged on the value the
		// client read (a part this call already wrote is the client's own), or after a remove on the value as it is now.
		$masked = static function ( array $keys, $before ) use ( &$value, &$touched, &$structural, $original, $read_of ) {
			if ( Secret_Scrubber::holds_invalid_utf8( $before ) ) {
				return true;
			}
			if ( $structural ) {
				foreach ( $read_of( 'v', $value ) as $view ) {
					if ( ! self::same_raw( self::pick( $view, $keys ), $before ) ) {
						return true;
					}
				}
				return false;
			}
			foreach ( $touched as $t ) {
				if ( count( $t ) <= count( $keys ) && array_slice( $keys, 0, count( $t ) ) === $t ) {
					return false;
				}
			}
			$found = false;
			$raw   = self::raw_at( $original, $keys, $found );
			if ( ! $found || Secret_Scrubber::holds_invalid_utf8( $raw ) ) {
				return true;
			}
			foreach ( $read_of( 'v', $original ) as $view ) {
				if ( ! self::same_raw( self::pick( $view, $keys ), $raw ) ) {
					return true;
				}
			}
			return false;
		};
		$snapshots = [];
		foreach ( $operations as $i => $op ) {
			try {
				$row = self::apply_one( $value, $op, $seen, $masked );
			} catch ( Tool_Refusal $e ) {
				throw new Tool_Refusal( $e->error_code(), sprintf( 'Operation %d: %s Nothing was changed.', $i + 1, $e->getMessage() ), [ 'operation' => $i + 1 ] );
			}
			if ( 'remove' === $row['op'] ) {
				$structural = true;
			}
			if ( null !== $row['_after'] ) {
				$touched[] = $row['_after'];
			}
			$snapshots[ $i ] = $value;
			$report[ $i ]    = $row;
		}
		// What a read shows after each operation: the final value's read, unless a later operation changed that part again.
		$shown_final = null;
		$rows        = [];
		foreach ( $report as $i => $row ) {
			$keys_before = $row['_before'];
			$keys_after  = $row['_after'];
			$raw_before  = $row['_raw_before'];
			$raw_after   = $row['_raw_after'];
			$before_seen = $row['_before_seen'];
			unset( $row['_before'], $row['_after'], $row['_raw_before'], $row['_raw_after'], $row['_before_seen'] );
			$after_seen = null;
			if ( null !== $keys_after ) {
				$later = false;
				foreach ( $report as $j => $other ) {
					if ( $j > $i && ( 'remove' === $other['op'] || ( null !== $other['_after'] && $overlaps( $other['_after'], $keys_after ) ) || ( null !== $other['_before'] && $overlaps( $other['_before'], $keys_after ) ) ) ) {
						$later = true;
						break;
					}
				}
				if ( $later ) {
					$after_seen = self::pick( $read_of( 's', $snapshots[ $i ] ), $keys_after );
				} else {
					if ( null === $shown_final ) {
						$shown_final = $read_of( 's', $value );
					}
					$after_seen = self::pick( $shown_final, $keys_after );
				}
			}
			if ( null !== $keys_before ) {
				$row['before'] = self::cut( $before_seen );
			}
			if ( null !== $keys_after ) {
				$row['after'] = self::cut( $after_seen );
			}
			// Whether a set changed anything is only said when a read shows both values.
			if ( 'set' === $row['op'] && ! $row['created'] && $before_seen === $raw_before && $after_seen === $raw_after ) {
				$row['changed'] = $raw_before !== $raw_after;
			}
			$rows[] = $row;
		}
		$report = $rows;
		return [ $value, $report ];
	}

	/**
	 * @param array    $root   Value being changed, by reference.
	 * @param mixed    $op     One operation.
	 * @param callable $seen   Returns what a read shows at a key path, before this operation.
	 * @param callable $masked Returns whether replacing the existing part at a key path would overwrite something a read hides.
	 * @return array<string,mixed> Report row with the internal fields apply() reads and removes.
	 * @throws Tool_Refusal When the operation cannot be applied.
	 */
	private static function apply_one( array &$root, $op, callable $seen, callable $masked ) {
		if ( ! is_array( $op ) || self::is_list( $op ) ) {
			throw new Tool_Refusal( 'invalid_operations', 'each operation must be an object with op and path.' );
		}
		$kind = isset( $op['op'] ) && is_string( $op['op'] ) ? $op['op'] : '';
		if ( ! in_array( $kind, [ 'set', 'append', 'remove' ], true ) ) {
			throw new Tool_Refusal( 'invalid_operations', 'op must be set, append or remove.' );
		}
		$unknown = array_diff( array_keys( $op ), [ 'op', 'path', 'value' ] );
		if ( $unknown ) {
			throw new Tool_Refusal( 'invalid_operations', 'unknown field ' . implode( ', ', array_map( 'strval', $unknown ) ) . '; an operation has op, path and (for set and append) value.' );
		}
		$path = $op['path'] ?? null;
		if ( ! is_array( $path ) || ! self::is_list( $path ) || ( [] === $path && 'append' !== $kind ) ) {
			throw new Tool_Refusal( 'invalid_path', 'path must be a list of steps (keys, positions or matches); only append may use an empty path, to add to a top-level list.' );
		}
		if ( count( $path ) > self::MAX_PATH_STEPS ) {
			throw new Tool_Refusal( 'invalid_path', sprintf( 'path has more than %d steps.', self::MAX_PATH_STEPS ) );
		}
		$has_value = array_key_exists( 'value', $op );
		if ( 'remove' === $kind && $has_value ) {
			throw new Tool_Refusal( 'invalid_operations', 'remove takes no value.' );
		}
		if ( 'remove' !== $kind && ! $has_value ) {
			throw new Tool_Refusal( 'invalid_operations', $kind . ' needs a value.' );
		}
		if ( $has_value && self::holds_object( $op['value'] ) ) {
			throw new Tool_Refusal( 'invalid_operations', 'value must be plain JSON data.' );
		}
		// A placeholder for a hidden value would be stored in place of the real value.
		if ( ( $has_value && Secret_Scrubber::contains_marker( $op['value'] ) ) || Secret_Scrubber::contains_marker( $path ) ) {
			throw new Tool_Refusal( 'redacted_value', 'it holds "[REDACTED]" or another placeholder Royal MCP shows in place of hidden data, so writing it would store that text instead of the real value. Send the real value for that part, or leave that part out.' );
		}

		if ( 'append' === $kind ) {
			$keys = self::resolve_all( $root, $path, count( $path ), false, $seen, true );
			$list = &self::node( $root, $keys );
			if ( ! is_array( $list ) || ! self::is_list( $list ) ) {
				throw new Tool_Refusal( 'not_a_list', self::label( $path ) . ' is not a list, so nothing can be added to its end.' );
			}
			$list[] = $op['value'];
			$at     = count( $list ) - 1;
			return [ 'op' => 'append', 'path' => self::label( $path ), 'position' => $at, 'items_now' => count( $list ), '_before' => null, '_after' => array_merge( $keys, [ $at ] ), '_raw_before' => null, '_raw_after' => $op['value'], '_before_seen' => null ];
		}

		$keys   = self::resolve_all( $root, $path, count( $path ), 'set' === $kind, $seen );
		$key    = array_pop( $keys );
		$parent = &self::node( $root, $keys );
		if ( ! is_array( $parent ) ) {
			throw new Tool_Refusal( 'path_not_found', self::label( array_slice( $path, 0, -1 ) ) . ' holds a single value, not named values or a list.' );
		}
		$parent_is_list = self::is_list( $parent ) && [] !== $parent;
		$exists         = array_key_exists( $key, $parent );
		$before         = $exists ? $parent[ $key ] : null;
		$full           = array_merge( $keys, [ $key ] );
		$before_seen    = $exists ? $seen( $full ) : null;

		if ( 'remove' === $kind ) {
			if ( ! $exists ) {
				throw new Tool_Refusal( 'path_not_found', self::label( $path ) . ' is not there.' );
			}
			unset( $parent[ $key ] );
			if ( $parent_is_list ) {
				$parent = array_values( $parent );
			}
			$row = [ 'op' => 'remove', 'path' => self::label( $path ) ];
			if ( $parent_is_list ) {
				$row['position']  = $key;
				$row['items_now'] = count( $parent );
			}
			return $row + [ '_before' => $full, '_after' => null, '_raw_before' => $before, '_raw_after' => null, '_before_seen' => $before_seen ];
		}

		// set
		if ( $parent_is_list && ! $exists ) {
			throw new Tool_Refusal( 'path_not_found', self::label( $path ) . ' is past the end of the list; use append to add an item.' );
		}
		// A part a read does not show exactly as stored (hidden in whole or in part, or text that is not
		// valid UTF-8) is never replaced: the new value could only be built from what the read showed.
		if ( $exists ) {
			if ( $masked( $full, $before ) ) {
				throw new Tool_Refusal( 'read_is_masked', self::label( $path ) . ' is partly or wholly hidden from AI clients, so setting it could overwrite data the client never saw. Set a longer path that reaches only parts a read shows in full, or change this part in the WordPress admin.' );
			}
		}
		$parent[ $key ] = $op['value'];
		return [ 'op' => 'set', 'path' => self::label( $path ), 'created' => ! $exists, '_before' => $exists ? $full : null, '_after' => $full, '_raw_before' => $before, '_raw_after' => $op['value'], '_before_seen' => $before_seen ];
	}

	/**
	 * The array keys the first $count steps of a path point at, in order.
	 *
	 * @param array $root        Value.
	 * @param array $path        Path as sent.
	 * @param int   $count       Steps to resolve.
	 * @param bool  $last_may_be_new Whether the last step may name a key that is not there yet.
	 * @param callable $seen     Returns what a read shows at a key path.
	 * @return array<int,int|string>
	 * @throws Tool_Refusal When a step is malformed or leads nowhere.
	 */
	private static function resolve_all( array $root, array $path, $count, $last_may_be_new, callable $seen, $into_last = false ) {
		$keys = [];
		$node = $root;
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $node ) ) {
				throw new Tool_Refusal( 'path_not_found', self::label( array_slice( $path, 0, $i ) ) . ' holds a single value, not named values or a list.' );
			}
			$so_far = array_slice( $path, 0, $i + 1 );
			$key    = self::resolve( $node, $path[ $i ], $so_far, $seen( $keys ) );
			$keys[] = $key;
			$last   = $i === $count - 1;
			if ( ! array_key_exists( $key, $node ) ) {
				if ( $last && $last_may_be_new ) {
					break;
				}
				if ( $last ) {
					// The caller reports a missing last step in its own words.
					break;
				}
				throw new Tool_Refusal( 'path_not_found', self::label( $so_far ) . ' is not there.' );
			}
			$node = $node[ $key ];
			// What a read hides as a whole is changed as a whole: a path may end at it, not pass through it.
			if ( ( ! $last || $into_last ) && is_array( $node ) && ! is_array( $seen( $keys ) ) ) {
				throw new Tool_Refusal( 'path_through_hidden', self::label( $so_far ) . ' is hidden in reads of this option, so parts of it cannot be changed one at a time. Set the whole of it instead.' );
			}
		}
		return $keys;
	}

	/** Reference to the node at a list of existing keys. */
	private static function &node( array &$root, array $keys ) {
		$node = &$root;
		foreach ( $keys as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				throw new Tool_Refusal( 'path_not_found', 'that part of the value is not there.' );
			}
			$node = &$node[ $key ];
		}
		return $node;
	}

	/**
	 * The array key a step points at inside $node.
	 *
	 * @param array $node       Node the step is applied to.
	 * @param mixed $step       Key, position or match.
	 * @param array $so_far     Path up to and including this step, for messages.
	 * @param mixed $node_shown The same node as a read would show it.
	 * @return int|string
	 * @throws Tool_Refusal When the step is malformed or matches no item or several.
	 */
	private static function resolve( array $node, $step, array $so_far, $node_shown ) {
		$is_list = self::is_list( $node ) && [] !== $node;
		if ( is_array( $step ) ) {
			if ( 1 !== count( $step ) || self::is_list( $step ) ) {
				throw new Tool_Refusal( 'invalid_path', 'a match step names exactly one field and its value, for example {"id": 1234}.' );
			}
			$field = (string) array_key_first( $step );
			$want  = $step[ $field ];
			if ( ! is_scalar( $want ) ) {
				throw new Tool_Refusal( 'invalid_path', 'a match step compares against text, a number, true or false.' );
			}
			if ( ! $is_list ) {
				throw new Tool_Refusal( 'not_a_list', self::label( array_slice( $so_far, 0, -1 ) ) . ' is not a list, so its items cannot be picked by a field.' );
			}
			$hits = [];
			foreach ( $node as $k => $item ) {
				if ( ! is_array( $item ) || ! array_key_exists( $field, $item ) ) {
					continue;
				}
				// A field a read hides cannot be tested, whether or not the guess is right.
				$seen = is_array( $node_shown ) && is_array( $node_shown[ $k ] ?? null ) && array_key_exists( $field, $node_shown[ $k ] ) ? $node_shown[ $k ][ $field ] : self::HIDDEN;
				if ( $seen !== $item[ $field ] ) {
					throw new Tool_Refusal( 'match_on_hidden_field', sprintf( 'the field %s is hidden in reads of this option, so items cannot be picked by it. Use a position, or another field.', mb_substr( $field, 0, 60 ) ) );
				}
				if ( is_scalar( $item[ $field ] ) && self::same( $item[ $field ], $want ) ) {
					$hits[] = $k;
				}
			}
			if ( 1 !== count( $hits ) ) {
				throw new Tool_Refusal( [] === $hits ? 'path_not_found' : 'ambiguous_match', sprintf( '%s matches %d items; a match must pick exactly one.', self::label( $so_far ), count( $hits ) ) );
			}
			return $hits[0];
		}
		if ( is_float( $step ) ) {
			if ( floor( $step ) !== $step || abs( $step ) > 9007199254740992 ) {
				throw new Tool_Refusal( 'invalid_path', 'a position is a whole number.' );
			}
			$step = (int) $step;
		}
		if ( is_int( $step ) ) {
			if ( $is_list && $step < 0 ) {
				throw new Tool_Refusal( 'invalid_path', 'a position counts from 0 and cannot be negative.' );
			}
			// On named values a whole number is a name.
			return $step;
		}
		if ( is_string( $step ) ) {
			if ( '' === $step || strlen( $step ) > self::MAX_KEY_LENGTH ) {
				throw new Tool_Refusal( 'invalid_path', sprintf( 'a key is text of 1 to %d characters.', self::MAX_KEY_LENGTH ) );
			}
			if ( $is_list ) {
				// PHP would turn "3" into position 3; anything else would turn the list into named values.
				if ( ! preg_match( '/^(?:0|[1-9]\d{0,8})$/', $step ) ) {
					throw new Tool_Refusal( 'not_a_list_key', self::label( array_slice( $so_far, 0, -1 ) ) . ' is a list: use a position (a whole number) or a match such as {"id": 1234}.' );
				}
				return (int) $step;
			}
			return $step;
		}
		throw new Tool_Refusal( 'invalid_path', 'a path step is a key (text), a position (whole number) or a match such as {"id": 1234}.' );
	}

	/**
	 * What a read shows at a list of keys: the marker when the value, or anything above it, is hidden.
	 *
	 * @param mixed $shown Redacted copy of the whole value.
	 * @param array $keys  Keys from the top.
	 * @return mixed
	 */
	private static function pick( $shown, array $keys ) {
		$node = $shown;
		foreach ( $keys as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				return self::HIDDEN;
			}
			$node = $node[ $key ];
		}
		return $node;
	}

	/** The stored value at a list of keys; $found says whether it is there. */
	private static function raw_at( $value, array $keys, &$found ) {
		$found = false;
		foreach ( $keys as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return null;
			}
			$value = $value[ $key ];
		}
		$found = true;
		return $value;
	}

	/** Field values match when both are the same text once written out; true and false only match themselves. */
	private static function same( $have, $want ) {
		if ( is_bool( $have ) || is_bool( $want ) ) {
			return $have === $want;
		}
		return (string) $have === (string) $want;
	}

	private static function is_list( array $a ) {
		$i = 0;
		foreach ( $a as $k => $_ ) {
			if ( $k !== $i++ ) {
				return false;
			}
		}
		return true;
	}

	private static function holds_object( $v ) {
		if ( is_object( $v ) || is_resource( $v ) ) {
			return true;
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $item ) {
				if ( self::holds_object( $item ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Most changed paths an undo snapshot records before it keeps the whole value instead. */
	const MAX_UNDO_PATHS = 200;

	/**
	 * Where two values of an option differ, as disjoint paths of real keys with
	 * what each held before and after. A list whose length changed counts as one
	 * change, since its items moved. Null when there are more than MAX_UNDO_PATHS,
	 * or when either value holds objects: those do not come back intact from the
	 * undo record, so such a value is only ever put back whole.
	 *
	 * @param mixed $before Value before the write.
	 * @param mixed $after  Value after the write.
	 * @return array<int,array<string,mixed>>|null
	 */
	public static function changed_paths( $before, $after ) {
		if ( self::holds_object( $before ) || self::holds_object( $after ) ) {
			return null;
		}
		$out = [];
		return self::collect_changes( $before, $after, [], $out ) ? $out : null;
	}

	private static function collect_changes( $before, $after, array $keys, array &$out ) {
		$whole_list = is_array( $before ) && is_array( $after ) && self::is_list( $before ) && self::is_list( $after ) && count( $before ) !== count( $after );
		if ( is_array( $before ) && is_array( $after ) && ! $whole_list ) {
			foreach ( array_keys( $before + $after ) as $k ) {
				$in_before = array_key_exists( $k, $before );
				$in_after  = array_key_exists( $k, $after );
				$at        = array_merge( $keys, [ $k ] );
				if ( $in_before && $in_after ) {
					if ( ! self::collect_changes( $before[ $k ], $after[ $k ], $at, $out ) ) {
						return false;
					}
					continue;
				}
				$out[] = [ 'path' => $at, 'before_exists' => $in_before, 'before' => $in_before ? $before[ $k ] : null, 'after_exists' => $in_after, 'after' => $in_after ? $after[ $k ] : null ];
				if ( count( $out ) > self::MAX_UNDO_PATHS ) {
					return false;
				}
			}
			return true;
		}
		if ( self::same_raw( $before, $after ) ) {
			return true;
		}
		$out[] = [ 'path' => $keys, 'before_exists' => true, 'before' => $before, 'after_exists' => true, 'after' => $after ];
		return count( $out ) <= self::MAX_UNDO_PATHS;
	}

	/**
	 * The recorded paths whose value now differs from what the write left there, as readable labels.
	 *
	 * @param mixed $current Value as stored now.
	 * @param array $changes Rows from changed_paths().
	 * @return string[]
	 */
	public static function drifted_paths( $current, array $changes ) {
		$out = [];
		foreach ( $changes as $c ) {
			$keys   = array_values( (array) ( $c['path'] ?? [] ) );
			$node   = $current;
			$exists = true;
			foreach ( $keys as $k ) {
				if ( ! is_array( $node ) || ! array_key_exists( $k, $node ) ) {
					$exists = false;
					break;
				}
				$node = $node[ $k ];
			}
			if ( $exists !== ! empty( $c['after_exists'] ) || ( $exists && ! self::same_recorded( $node, $c['after'] ?? null ) ) ) {
				$out[] = self::label( $keys );
			}
		}
		return $out;
	}

	/** Two stored values are equal: identical, or (for objects) equal once serialized. */
	private static function same_raw( $a, $b ) {
		if ( $a === $b ) {
			return true;
		}
		if ( ! self::holds_object( $a ) && ! self::holds_object( $b ) ) {
			return false;
		}
		try {
			return serialize( $a ) === serialize( $b ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- equality check only, nothing is stored.
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * A stored value still equals one read back from the undo record. The record
	 * went through JSON, which rewrites text that is not valid UTF-8, so values
	 * that differ in raw form are also compared as JSON.
	 */
	private static function same_recorded( $current, $recorded ) {
		if ( self::same_raw( $current, $recorded ) ) {
			return true;
		}
		if ( self::holds_object( $current ) || self::holds_object( $recorded ) ) {
			return false;
		}
		$a = wp_json_encode( $current );
		return false !== $a && $a === wp_json_encode( $recorded );
	}

	/**
	 * Put back what each recorded path held before the write, leaving the rest of the value as it is now.
	 *
	 * @param mixed $current Value as stored now.
	 * @param array $changes Rows from changed_paths().
	 * @return mixed
	 */
	public static function restore_paths( $current, array $changes ) {
		foreach ( $changes as $c ) {
			$keys   = array_values( (array) ( $c['path'] ?? [] ) );
			$exists = ! empty( $c['before_exists'] );
			$value  = $c['before'] ?? null;
			if ( [] === $keys ) {
				$current = $value;
				continue;
			}
			if ( ! is_array( $current ) ) {
				$current = [];
			}
			$last = array_pop( $keys );
			$node = &$current;
			$gone = false;
			foreach ( $keys as $k ) {
				if ( ! array_key_exists( $k, $node ) || ! is_array( $node[ $k ] ) ) {
					if ( ! $exists ) {
						$gone = true;
						break;
					}
					$node[ $k ] = [];
				}
				$node = &$node[ $k ];
			}
			if ( ! $gone ) {
				if ( $exists ) {
					$node[ $last ] = $value;
				} else {
					unset( $node[ $last ] );
				}
			}
			unset( $node );
		}
		return $current;
	}

	/** A path as readable text: entries[id=1234].reason */
	public static function label( array $path ) {
		if ( [] === $path ) {
			return 'the value';
		}
		$out = '';
		foreach ( $path as $step ) {
			if ( is_array( $step ) ) {
				$field = (string) array_key_first( $step );
				$val   = $step[ $field ] ?? '';
				$out  .= '[' . $field . '=' . ( is_scalar( $val ) ? ( is_bool( $val ) ? ( $val ? 'true' : 'false' ) : (string) $val ) : '?' ) . ']';
			} elseif ( is_int( $step ) || is_float( $step ) ) {
				$out .= '[' . ( is_float( $step ) && abs( $step ) > 9007199254740992 ? '?' : (int) $step ) . ']';
			} else {
				$out .= ( '' === $out ? '' : '.' ) . ( is_scalar( $step ) ? (string) $step : '?' );
			}
		}
		return mb_substr( $out, 0, 300 );
	}

	/** A redacted value for the report, cut when long. */
	private static function cut( $v ) {
		$json = (string) wp_json_encode( $v );
		if ( strlen( $json ) <= self::REPORT_BYTES ) {
			return $v;
		}
		return [ 'too_long_to_show' => true, 'bytes' => strlen( $json ), 'starts' => mb_substr( $json, 0, 200 ) ];
	}
}
