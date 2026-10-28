<?php
/**
 * Dependency-free unit tests for the replacer and TAR classes.
 *
 * Usage: php tests/run-unit-tests.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-mse-tar.php';
require __DIR__ . '/../includes/class-mse-replacer.php';

$failures = 0;
function check( $label, $expected, $actual ) {
	global $failures;
	if ( $expected === $actual ) {
		echo "ok   - $label\n";
		return;
	}
	$failures++;
	echo "FAIL - $label\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

// ---------------------------------------------------------------- Replacer.
$r = new MSE_Replacer();
$r->add( '/wp-content/uploads/sites/17/', '/wp-content/uploads/sites/42/' );
$r->add_url( 'old.example.com', 'new.example.org' );

check( 'plain url', 'See https://new.example.org/page', $r->replace( 'See https://old.example.com/page' ) );
check( 'host boundary keeps longer host', 'https://old.example.com.au/x', $r->replace( 'https://old.example.com.au/x' ) );
check( 'host boundary keeps subdomain-like suffix', '//old.example.community', $r->replace( '//old.example.community' ) );
check( 'case-insensitive host', '//new.example.org/', $r->replace( '//OLD.example.com/' ) );
check( 'json escaped', '{"u":"https:\/\/new.example.org\/a"}', $r->replace( '{"u":"https:\/\/old.example.com\/a"}' ) );
check( 'url encoded', 'u=https%3A%2F%2Fnew.example.org%2Fa', $r->replace( 'u=https%3A%2F%2Fold.example.com%2Fa' ) );
check( 'uploads path', '<img src="/wp-content/uploads/sites/42/a.jpg">', $r->replace( '<img src="/wp-content/uploads/sites/17/a.jpg">' ) );
check( 'non-string untouched', 5, $r->replace( 5 ) );
check( 'null untouched', null, $r->replace( null ) );

$data = array(
	'url'    => 'https://old.example.com/',
	'nested' => array( 'img' => 'https://old.example.com/wp-content/uploads/sites/17/x.png', 'n' => 3, 'f' => 1.5, 'b' => true, 'z' => null ),
	'https://old.example.com/' => 'key stays',
	'json'   => json_encode( array( 'u' => 'https://old.example.com/x' ) ),
	'double' => serialize( array( 'deep' => 'http://old.example.com' ) ),
	'utf8'   => 'Ünïcödé https://old.example.com ✓',
);
$out      = $r->replace( serialize( $data ) );
$decoded  = unserialize( $out );
check( 'serialized: valid after replace', true, is_array( $decoded ) );
check( 'serialized: string', 'https://new.example.org/', $decoded['url'] );
check( 'serialized: nested', 'https://new.example.org/wp-content/uploads/sites/42/x.png', $decoded['nested']['img'] );
check( 'serialized: scalars', array( 3, 1.5, true, null ), array( $decoded['nested']['n'], $decoded['nested']['f'], $decoded['nested']['b'], $decoded['nested']['z'] ) );
check( 'serialized: keys unchanged', 'key stays', $decoded['https://old.example.com/'] );
check( 'serialized: json inside', 'https://new.example.org/x', json_decode( $decoded['json'], true )['u'] );
check( 'serialized: double serialized', 'http://new.example.org', unserialize( $decoded['double'] )['deep'] );
check( 'serialized: multibyte lengths', 'Ünïcödé https://new.example.org ✓', $decoded['utf8'] );

// Objects of classes that do not exist here must survive untouched apart from strings.
$obj = 'O:12:"Missing_Cls":2:{s:3:"url";s:24:"https://old.example.com/";s:9:"' . "\0" . '*' . "\0" . 'prot";a:1:{i:0;s:23:"//old.example.com/a.jpg";}}';
$res = $r->replace( $obj );
check( 'object rebuilt', 'O:12:"Missing_Cls":2:{s:3:"url";s:24:"https://new.example.org/";s:9:"' . "\0" . '*' . "\0" . 'prot";a:1:{i:0;s:23:"//new.example.org/a.jpg";}}', $res );
$std = $r->replace( serialize( (object) array( 'a' => 'https://old.example.com' ) ) );
check( 'stdClass', 'https://new.example.org', unserialize( $std )->a );

check( 'broken serialized falls back to plain', 's:99:"https://new.example.org";', $r->replace( 's:99:"https://old.example.com";' ) );
check( 'serialized with references', array( 'a' => '//new.example.org', 'b' => '//new.example.org' ), unserialize( $r->replace( 'a:2:{s:1:"a";s:17:"//old.example.com";s:1:"b";R:2;}' ) ) );

$r2 = new MSE_Replacer();
$r2->add_url( 'a.com', 'b.com' );
check( 'dollar signs in replacement are literal', '//b.com $1', $r2->replace( '//a.com $1' ) );
$r3 = new MSE_Replacer();
$r3->add_url( 'a.com', 'x$1.com' );
check( 'dollar signs in target are literal', '//x$1.com/', $r3->replace( '//a.com/' ) );

// --------------------------------------------------------------------- Tar.
$file = tempnam( sys_get_temp_dir(), 'msetar' );
$long = str_repeat( 'very-long-directory-name/', 8 ) . 'file.txt';
$fh   = fopen( $file, 'wb' );
foreach ( array( 'manifest.json' => '{"a":1}', $long => 'long name content', 'empty.txt' => '' ) as $name => $content ) {
	fwrite( $fh, MSE_Tar::header( $name, strlen( $content ), 1700000000 ) . $content . MSE_Tar::padding( strlen( $content ) ) );
}
fwrite( $fh, MSE_Tar::footer() );
fclose( $fh );

$fh      = fopen( $file, 'rb' );
$offset  = 0;
$entries = array();
while ( $entry = MSE_Tar::read_entry( $fh, $offset ) ) {
	$entries[ $entry['name'] ] = MSE_Tar::read_data( $fh, $entry );
	$offset                    = $entry['next_offset'];
}
fclose( $fh );
check( 'tar roundtrip', array( 'manifest.json' => '{"a":1}', $long => 'long name content', 'empty.txt' => '' ), $entries );

// Interoperability with the system tar binary.
if ( trim( (string) shell_exec( 'command -v tar' ) ) ) {
	$listing = trim( (string) shell_exec( 'tar -tf ' . escapeshellarg( $file ) . ' 2>&1' ) );
	check( 'system tar can list package', "manifest.json\n$long\nempty.txt", $listing );
}
unlink( $file );

echo $failures ? "\n$failures failure(s)\n" : "\nAll tests passed\n";
exit( $failures ? 1 : 0 );
