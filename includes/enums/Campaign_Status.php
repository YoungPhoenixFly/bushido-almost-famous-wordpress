<?php
/**
 * Campaign lifecycle states mirrored from the backend.
 *
 * Values are the UPPERCASE strings the Bushido Almost Famous API returns on the wire
 * (the service's CAMPAIGN_STATUS_VALUES contract). Comparisons against a
 * campaign's `status` field must use these values.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Enums;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum Campaign_Status: string {
	case DRAFT        = 'DRAFT';
	case PENDING_SYNC = 'PENDING_SYNC';
	case ACTIVE       = 'ACTIVE';
	case PAUSED       = 'PAUSED';
	case COMPLETED    = 'COMPLETED';
	case ARCHIVED     = 'ARCHIVED';

	/**
	 * Resolve a raw status string to a case, tolerating legacy lowercase.
	 *
	 * @param string $status Raw status from the API.
	 * @return self|null
	 */
	public static function try_normalize( string $status ): ?self {
		return self::tryFrom( strtoupper( trim( $status ) ) );
	}

	/**
	 * Human-readable label (e.g. "Pending Sync").
	 *
	 * @return string
	 */
	public function label(): string {
		return ucwords( strtolower( str_replace( '_', ' ', $this->value ) ) );
	}
}
