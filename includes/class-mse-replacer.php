<?php
/**
 * Search/replace that is safe for PHP-serialized and JSON-encoded values.
 *
 * Serialized strings are parsed with a small tokenizer instead of
 * unserialize(): no objects are ever instantiated (no object-injection risk,
 * no missing-class problems), and every string length is recalculated after
 * replacement so the data stays valid.
 *
 * @package MultisiteExporter
 */

defined( 'ABSPATH' ) || exit;

class MSE_Replacer {

	/** @var array[] Ordered operations: [ 'type' => 'str'|'regex', 'from', 'to', 'needle' ]. */
	private $ops = array();

	/** @var string[] Literal needles used for a cheap pre-check. */
	private $needles = array();

	/**
	 * Adds a literal replacement.
	 *
	 * @param string $from Search string.
	 * @param string $to   Replacement.
	 */
	public function add( $from, $to ) {
		$from = (string) $from;
		$to   = (string) $to;
		if ( '' === $from || $from === $to ) {
			return;
		}
		foreach ( $this->ops as $op ) {
			if ( 'str' === $op['type'] && $op['from'] === $from ) {
				return;
			}
		}
		$this->ops[]     = array( 'type' => 'str', 'from' => $from, 'to' => $to, 'needle' => $from );
		$this->needles[] = $from;
	}

	/**
	 * Replaces a host (optionally with path) only where it is not followed by
	 * more host characters, so "//site.com" does not touch "//site.com.au".
	 *
	 * @param string $from   Literal text, e.g. "//site.com".
	 * @param string $to     Replacement, e.g. "//new-site.com".
	 */
	public function add_host( $from, $to ) {
		$from = (string) $from;
		$to   = (string) $to;
		if ( '' === $from || $from === $to ) {
			return;
		}
		$this->ops[]     = array(
			'type'   => 'regex',
			'from'   => '~' . preg_quote( $from, '~' ) . '(?![a-z0-9\-]|\.[a-z0-9])~i',
			'to'     => $to,
			'needle' => $from,
		);
		$this->needles[] = $from;
	}

	/**
	 * Adds the standard URL variants for moving "//old" to "//new":
	 * plain, JSON-escaped (\/\/) and URL-encoded (%2F%2F).
	 *
	 * @param string $from Host + optional path without scheme, e.g. "site.com" or "site.com/blog".
	 * @param string $to   Same for the destination.
	 * @param bool   $host Use the host-boundary matcher (true) or a plain literal (false).
	 */
	public function add_url( $from, $to, $host = true ) {
		$variants = array(
			array( '//' . $from, '//' . $to ),
			array( '\\/\\/' . str_replace( '/', '\\/', $from ), '\\/\\/' . str_replace( '/', '\\/', $to ) ),
			array( '%2F%2F' . str_replace( '/', '%2F', $from ), '%2F%2F' . str_replace( '/', '%2F', $to ) ),
		);
		foreach ( $variants as $pair ) {
			if ( $host ) {
				$this->add_host( $pair[0], $pair[1] );
			} else {
				$this->add( $pair[0], $pair[1] );
			}
		}
	}

	/**
	 * @return array[] Human-readable list of pairs (for logs/UI).
	 */
	public function describe() {
		return array_map(
			function ( $op ) {
				return array( $op['needle'], $op['to'] );
			},
			$this->ops
		);
	}

	/**
	 * @return bool
	 */
	public function is_empty() {
		return empty( $this->ops );
	}

	/**
	 * Applies all replacements to a database value.
	 *
	 * @param mixed $value Value (non-strings are returned untouched).
	 * @return mixed
	 */
	public function replace( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! $this->has_needle( $value ) ) {
			return $value;
		}
		if ( self::looks_serialized( $value ) ) {
			$pos = 0;
			try {
				$out = $this->walk( $value, $pos );
				if ( strlen( $value ) === $pos ) {
					return $out;
				}
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Not valid serialized data: fall through to a plain replacement.
			}
		}
		return $this->replace_plain( $value );
	}

	private function has_needle( $value ) {
		foreach ( $this->needles as $needle ) {
			if ( false !== stripos( $value, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private function replace_plain( $value ) {
		foreach ( $this->ops as $op ) {
			if ( false === stripos( $value, $op['needle'] ) ) {
				continue;
			}
			if ( 'str' === $op['type'] ) {
				$value = str_replace( $op['from'], $op['to'], $value );
			} else {
				$value = preg_replace( $op['from'], str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $op['to'] ), $value );
			}
		}
		return $value;
	}

	/**
	 * Cheap test whether a string is (probably) PHP-serialized.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function looks_serialized( $value ) {
		$value = trim( $value );
		if ( 'N;' === $value ) {
			return true;
		}
		if ( strlen( $value ) < 4 || ':' !== $value[1] ) {
			return false;
		}
		$last = substr( $value, -1 );
		return false !== strpos( 'sabidOCE', $value[0] ) && ( ';' === $last || '}' === $last );
	}

	/**
	 * Parses one serialized token starting at $pos and returns it rebuilt with
	 * replacements applied to every string value. Keys, class and property
	 * names are copied unchanged.
	 *
	 * @param string $s   Serialized data.
	 * @param int    $pos Cursor (advanced past the token).
	 * @param bool   $key Token is an array key / property name.
	 * @return string
	 * @throws UnexpectedValueException On malformed data.
	 */
	private function walk( $s, &$pos, $key = false ) {
		if ( ! isset( $s[ $pos ] ) ) {
			throw new UnexpectedValueException( 'eof' );
		}
		$type = $s[ $pos ];

		switch ( $type ) {
			case 'N':
				$this->expect( $s, $pos, 'N;' );
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $s, ';', $pos );
				if ( false === $end || ':' !== $s[ $pos + 1 ] ) {
					throw new UnexpectedValueException( 'scalar' );
				}
				$token = substr( $s, $pos, $end - $pos + 1 );
				$pos   = $end + 1;
				return $token;

			case 's':
				$pos++;
				$len = $this->read_length( $s, $pos );
				$this->expect( $s, $pos, ':"' );
				if ( $pos + $len + 2 > strlen( $s ) ) {
					throw new UnexpectedValueException( 'string length' );
				}
				$str  = substr( $s, $pos, $len );
				$pos += $len;
				$this->expect( $s, $pos, '";' );
				if ( ! $key ) {
					$str = $this->replace( $str );
				}
				return 's:' . strlen( $str ) . ':"' . $str . '";';

			case 'a':
				$pos++;
				$count = $this->read_length( $s, $pos );
				$this->expect( $s, $pos, ':{' );
				$out = 'a:' . $count . ':{' . $this->walk_members( $s, $pos, $count ) . '}';
				$this->expect( $s, $pos, '}' );
				return $out;

			case 'O':
				$pos++;
				$name = $this->read_name( $s, $pos );
				$this->expect( $s, $pos, ':' );
				$count = $this->read_length( $s, $pos );
				$this->expect( $s, $pos, ':{' );
				$out = 'O:' . strlen( $name ) . ':"' . $name . '":' . $count . ':{' . $this->walk_members( $s, $pos, $count ) . '}';
				$this->expect( $s, $pos, '}' );
				return $out;

			case 'C':
				// Custom-serialized object: the payload is opaque, copy it verbatim.
				$start = $pos;
				$pos++;
				$this->read_name( $s, $pos );
				$this->expect( $s, $pos, ':' );
				$len = $this->read_length( $s, $pos );
				$this->expect( $s, $pos, ':{' );
				$pos += $len;
				$this->expect( $s, $pos, '}' );
				return substr( $s, $start, $pos - $start );

			case 'E':
				$start = $pos;
				$pos++;
				$this->read_name( $s, $pos );
				$this->expect( $s, $pos, ';' );
				return substr( $s, $start, $pos - $start );
		}

		throw new UnexpectedValueException( 'type' );
	}

	private function walk_members( $s, &$pos, $count ) {
		$out = '';
		for ( $i = 0; $i < $count; $i++ ) {
			$out .= $this->walk( $s, $pos, true );
			$out .= $this->walk( $s, $pos );
		}
		return $out;
	}

	/** Reads ':<len>:"<name>"' and returns name. */
	private function read_name( $s, &$pos ) {
		$len = $this->read_length( $s, $pos );
		$this->expect( $s, $pos, ':"' );
		$name = substr( $s, $pos, $len );
		$pos += $len;
		$this->expect( $s, $pos, '"' );
		return $name;
	}

	/** Reads ':<digits>' and returns the integer. */
	private function read_length( $s, &$pos ) {
		$this->expect( $s, $pos, ':' );
		$start = $pos;
		while ( isset( $s[ $pos ] ) && ctype_digit( $s[ $pos ] ) ) {
			$pos++;
		}
		if ( $pos === $start ) {
			throw new UnexpectedValueException( 'length' );
		}
		return (int) substr( $s, $start, $pos - $start );
	}

	private function expect( $s, &$pos, $literal ) {
		if ( substr( $s, $pos, strlen( $literal ) ) !== $literal ) {
			throw new UnexpectedValueException( 'expected ' . $literal );
		}
		$pos += strlen( $literal );
	}
}
