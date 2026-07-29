<?php
/**
 * Canonical API error codes surfaced by the client.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Enums;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum Error_Code: string {
	case API_UNREACHABLE   = 'api_unreachable';
	case API_TIMEOUT       = 'api_timeout';
	case TIER_INSUFFICIENT = 'tier_insufficient';
	case TIER_EXPIRED      = 'tier_expired';
	case PLATFORM_DEGRADED = 'platform_degraded';
	case PERMISSION_DENIED = 'permission_denied';
	case VALIDATION_ERROR  = 'validation_error';
	case UPGRADE_REQUIRED  = 'upgrade_required';
	case RATE_LIMITED      = 'rate_limited';
	case UNKNOWN_ERROR     = 'unknown_error';
}
