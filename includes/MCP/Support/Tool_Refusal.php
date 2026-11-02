<?php
/**
 * A tool refusal with a stable code. Thrown from a tool handler; the tools/call
 * error reply carries the code in structuredContent.error (and any extra
 * fields) next to the message, so a client can branch on the code instead of
 * matching message text.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tool_Refusal extends \Exception {

	/** @var string */
	private $error_code;

	/** @var array */
	private $extra;

	/**
	 * @param string $error_code Stable snake_case code.
	 * @param string $message    Human-readable message.
	 * @param array  $extra      More machine fields for structuredContent.
	 */
	public function __construct( $error_code, $message, array $extra = [] ) {
		parent::__construct( (string) $message );
		$this->error_code = (string) $error_code;
		$this->extra      = $extra;
	}

	public function error_code() {
		return $this->error_code;
	}

	/** structuredContent for the error reply. */
	public function structured() {
		return array_merge( $this->extra, [ 'error' => $this->error_code, 'message' => $this->getMessage() ] );
	}
}
