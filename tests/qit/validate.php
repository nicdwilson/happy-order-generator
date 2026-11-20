<?php
/**
 * QIT-equivalent Validation test.
 *
 * Mirrors https://qit.woo.com/docs/managed-tests/validation/ for a plugin:
 * - readme.txt present
 * - Required headers (main file + readme.txt): Requires PHP, Requires at least,
 *   Tested up to, WC requires at least, WC tested up to, License  -> warning if missing
 * - A manual "Woo:" header                                        -> warning
 * - HPOS (custom_order_tables) declared incompatible               -> ERROR (fails)
 * - Cart/Checkout blocks declared incompatible                     -> warning
 *
 * Not covered: QIT's child cart item scan (WooCommerce 11.3+), which does not
 * apply until the plugin adds child cart items (Product Bundles branch).
 *
 * Usage: php tests/qit/validate.php [--strict]   (--strict also fails on warnings)
 */

$root     = dirname( __DIR__, 2 );
$main     = $root . '/woocommerce-order-generator.php';
$readme   = $root . '/readme.txt';
$strict   = in_array( '--strict', $argv, true );
$errors   = array();
$warnings = array();

$headers = function ( string $file ): array {
	$data = (string) file_get_contents( $file, false, null, 0, 8192 );
	$out  = array();
	foreach ( array( 'Requires PHP', 'Requires at least', 'Tested up to', 'WC requires at least', 'WC tested up to', 'License', 'Woo' ) as $name ) {
		if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $data, $m ) ) {
			$out[ $name ] = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) );
		}
	}
	return $out;
};

$required = array( 'Requires PHP', 'Requires at least', 'Tested up to', 'WC requires at least', 'WC tested up to', 'License' );

// Main plugin file headers.
$main_headers = $headers( $main );
foreach ( $required as $name ) {
	if ( empty( $main_headers[ $name ] ) ) {
		$warnings[] = "Main plugin file is missing the \"$name\" header.";
	}
}
if ( isset( $main_headers['Woo'] ) ) {
	$warnings[] = 'Main plugin file includes a "Woo:" header; it should not be added manually.';
}

// readme.txt.
if ( ! file_exists( $readme ) ) {
	$warnings[] = 'readme.txt is missing.';
} else {
	$readme_headers = $headers( $readme );
	foreach ( array( 'Requires PHP', 'Requires at least', 'Tested up to', 'License' ) as $name ) {
		if ( empty( $readme_headers[ $name ] ) ) {
			$warnings[] = "readme.txt is missing the \"$name\" header.";
		}
	}
}

// Feature compatibility declarations.
$php_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$source    = '';
foreach ( $php_files as $file ) {
	$path = $file->getPathname();
	if ( 'php' !== $file->getExtension() || preg_match( '#/(vendor|node_modules|tests|\.git)/#', $path ) ) {
		continue;
	}
	$source .= "\n/* FILE $path */\n" . file_get_contents( $path );
}

if ( preg_match( "/declare_compatibility\(\s*'custom_order_tables'\s*,[^)]*false\s*\)/", $source ) ) {
	$errors[] = 'HPOS (custom_order_tables) is declared incompatible.';
} elseif ( ! preg_match( "/declare_compatibility\(\s*'custom_order_tables'/", $source ) ) {
	$warnings[] = 'HPOS (custom_order_tables) compatibility is not declared.';
}

if ( preg_match( "/declare_compatibility\(\s*'cart_checkout_blocks'\s*,[^)]*false\s*\)/", $source ) ) {
	$warnings[] = 'Cart/Checkout blocks are declared incompatible.';
}

// Report.
echo "QIT-equivalent Validation\n=========================\n";
foreach ( $errors as $e ) {
	echo "ERROR    $e\n";
}
foreach ( $warnings as $w ) {
	echo "WARNING  $w\n";
}
$result = $errors ? 'FAILED' : ( $warnings ? 'WARNING' : 'SUCCESS' );
printf( "\nResult: %s (%d errors, %d warnings)\n", $result, count( $errors ), count( $warnings ) );

exit( $errors || ( $strict && $warnings ) ? 1 : 0 );
