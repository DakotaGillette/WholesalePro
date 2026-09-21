<?php
/**
 * Thin wrapper over wc_get_logger() so call sites don't repeat the
 * source context array.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logger
 */
class Logger {

	private const SOURCE = 'protech-wholesale';

	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}

	public static function warning( string $message, array $context = array() ): void {
		self::log( 'warning', $message, $context );
	}

	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	private static function log( string $level, string $message, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$context['source'] = self::SOURCE;

		wc_get_logger()->log( $level, $message, $context );
	}
}
