<?php
/**
 * Supported advertising platform identifiers.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Enums;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum Platform_Type: string {
	case META    = 'meta';
	case YOUTUBE = 'youtube';
	case TIKTOK  = 'tiktok';
	case SPOTIFY = 'spotify';
}
