<?php
/**
 * Minimal append-only TAR writer and sequential reader.
 *
 * TAR is used for packages because it can be written and read incrementally
 * across many short requests: every entry is a 512-byte header followed by
 * its data padded to 512 bytes, so the job only needs to remember a byte
 * offset to resume. Long names use the GNU "././@LongLink" extension, which
 * GNU tar, bsdtar and 7-Zip all understand.
 *
 * @package MultisiteExporter
 */

defined( 'ABSPATH' ) || exit;

class MSE_Tar {

	const BLOCK = 512;

	/**
	 * Builds the header block(s) for an entry.
	 *
	 * @param string $name  Entry path inside the archive.
	 * @param int    $size  Data size in bytes.
	 * @param int    $mtime Modification time.
	 * @param string $type  Type flag ('0' regular file, 'L' GNU long name).
	 * @return string
	 */
	public static function header( $name, $size, $mtime = 0, $type = '0' ) {
		$out = '';
		if ( strlen( $name ) > 99 ) {
			$long = $name . "\0";
			$out .= self::raw_header( '././@LongLink', strlen( $long ), 0, 'L' );
			$out .= $long . self::padding( strlen( $long ) );
			$name  = substr( $name, 0, 99 );
		}
		return $out . self::raw_header( $name, $size, $mtime, $type );
	}

	/**
	 * Zero bytes needed to pad $size up to a block boundary.
	 *
	 * @param int $size Data size.
	 * @return string
	 */
	public static function padding( $size ) {
		$rest = $size % self::BLOCK;
		return $rest ? str_repeat( "\0", self::BLOCK - $rest ) : '';
	}

	/**
	 * Size of data padded to a whole number of blocks.
	 *
	 * @param int $size Data size.
	 * @return int
	 */
	public static function padded_size( $size ) {
		return (int) ( ceil( $size / self::BLOCK ) * self::BLOCK );
	}

	/**
	 * End-of-archive marker (two zero blocks).
	 *
	 * @return string
	 */
	public static function footer() {
		return str_repeat( "\0", self::BLOCK * 2 );
	}

	private static function raw_header( $name, $size, $mtime, $type ) {
		if ( $size > 077777777777 ) {
			throw new RuntimeException( sprintf( 'Entry "%s" is larger than 8 GB and cannot be stored.', $name ) );
		}
		$header  = str_pad( $name, 100, "\0" );
		$header .= sprintf( '%07o', 0644 ) . "\0";
		$header .= sprintf( '%07o', 0 ) . "\0";
		$header .= sprintf( '%07o', 0 ) . "\0";
		$header .= sprintf( '%011o', $size ) . "\0";
		$header .= sprintf( '%011o', max( 0, (int) $mtime ) ) . "\0";
		$header .= '        '; // Checksum placeholder.
		$header .= $type;
		$header .= str_repeat( "\0", 100 ); // Link name.
		$header .= "ustar\0" . '00';
		$header .= str_repeat( "\0", 32 + 32 ); // User / group names.
		$header .= sprintf( '%07o', 0 ) . "\0" . sprintf( '%07o', 0 ) . "\0";
		$header .= str_repeat( "\0", 155 + 12 ); // Prefix + padding.

		$sum    = array_sum( array_map( 'ord', str_split( $header ) ) );
		$header = substr_replace( $header, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );
		return $header;
	}

	/**
	 * Reads the entry whose header starts at $offset.
	 *
	 * @param resource $fh     Open file handle.
	 * @param int      $offset Header offset.
	 * @return array|null Entry info, or null at end of archive.
	 */
	public static function read_entry( $fh, $offset ) {
		$long_name = null;
		while ( true ) {
			fseek( $fh, $offset );
			$block = fread( $fh, self::BLOCK );
			if ( false === $block || strlen( $block ) < self::BLOCK ) {
				throw new RuntimeException( 'Unexpected end of package file (it may be incomplete or corrupt).' );
			}
			if ( trim( $block, "\0" ) === '' ) {
				return null;
			}

			$stored = octdec( trim( substr( $block, 148, 8 ), " \0" ) );
			$check  = substr_replace( $block, '        ', 148, 8 );
			if ( array_sum( array_map( 'ord', str_split( $check ) ) ) !== (int) $stored ) {
				throw new RuntimeException( sprintf( 'Corrupt package: bad header checksum at byte %d.', $offset ) );
			}

			$name = rtrim( substr( $block, 0, 100 ), "\0" );
			$size = (int) octdec( trim( substr( $block, 124, 12 ), " \0" ) );
			$type = substr( $block, 156, 1 );
			$data = $offset + self::BLOCK;

			if ( 'L' === $type ) {
				$long_name = rtrim( fread( $fh, $size ), "\0" );
				$offset    = $data + self::padded_size( $size );
				continue;
			}

			$prefix = rtrim( substr( $block, 345, 155 ), "\0" );
			if ( null === $long_name && '' !== $prefix ) {
				$name = $prefix . '/' . $name;
			}

			return array(
				'name'        => null !== $long_name ? $long_name : $name,
				'size'        => $size,
				'type'        => $type,
				'data_offset' => $data,
				'next_offset' => $data + self::padded_size( $size ),
			);
		}
	}

	/**
	 * Reads a whole (small) entry's data.
	 *
	 * @param resource $fh    Open file handle.
	 * @param array    $entry Entry from read_entry().
	 * @return string
	 */
	public static function read_data( $fh, $entry ) {
		if ( 0 === $entry['size'] ) {
			return '';
		}
		fseek( $fh, $entry['data_offset'] );
		$data = fread( $fh, $entry['size'] );
		if ( strlen( $data ) !== $entry['size'] ) {
			throw new RuntimeException( sprintf( 'Unexpected end of package while reading "%s".', $entry['name'] ) );
		}
		return $data;
	}
}
