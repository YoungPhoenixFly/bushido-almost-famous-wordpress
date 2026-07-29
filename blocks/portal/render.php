<?php
/**
 * Server-side render for the almost-famous/portal block.
 *
 * Outputs the same thing as the [almost-famous-portal] shortcode by delegating
 * to the registered shortcode callback. This keeps script/style enqueueing and
 * the mount div in a single place (DRY): the shortcode's
 * render_public_portal_shortcode() method.
 *
 * @package AlmostFamous
 *
 * @var array $attributes Block attributes.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Delegate to the shortcode so the block and shortcode share one render path.
echo do_shortcode( '[almost-famous-portal]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is already escaped internally.
