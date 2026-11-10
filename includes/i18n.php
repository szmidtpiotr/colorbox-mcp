<?php
/**
 * Język wtyczki = język WordPressa (użytkownika albo strony, determine_locale()). Teksty źródłowe są po
 * angielsku, tłumaczenia w languages/. Każdy wariant polskiego (pl, pl_PL) dostaje tłumaczenie pl_PL.
 */

namespace Colorbox\WpMcp\I18n;

defined( 'ABSPATH' ) || exit;

const DOMAIN = 'colorbox-wp-mcp';

function boot(): void {
	// Przed rejestracją abilities (wp_abilities_api_init) i przed menu admina.
	add_action( 'init', __NAMESPACE__ . '\\load', 0 );
}

function load(): void {
	$locale = determine_locale();
	$dir    = dirname( \Colorbox\WpMcp\FILE ) . '/languages/';
	foreach ( array_unique( array( $locale, 0 === strpos( $locale, 'pl' ) ? 'pl_PL' : $locale ) ) as $candidate ) {
		$mo = $dir . DOMAIN . '-' . $candidate . '.mo';
		if ( is_readable( $mo ) || is_readable( substr( $mo, 0, -3 ) . '.l10n.php' ) ) {
			unload_textdomain( DOMAIN, true );
			load_textdomain( DOMAIN, $mo, $candidate );
			return;
		}
	}
}
