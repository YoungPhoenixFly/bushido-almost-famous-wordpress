import { useState, useEffect } from '@wordpress/element';

/**
 * Localized portal configuration (capabilities, i18n, endpoints).
 */
export function portalConfig() {
	return window.afPublicPortal || {};
}

/**
 * Demo mode serves in-process fixtures with no real data or writes, so it
 * grants the full UI to anyone for local testing.
 */
export function isDemo() {
	return !! portalConfig().demoMode;
}

/**
 * Whether the current visitor may view campaigns (signed-in with the AF
 * view capability, or demo mode).
 */
export function canView() {
	return isDemo() || !! portalConfig().canView;
}

/**
 * Whether the current visitor may create/modify campaigns and payments.
 */
export function canManage() {
	return isDemo() || !! portalConfig().canManage;
}

/**
 * Look up a localized string with a fallback.
 * @param {string} key      Localization key.
 * @param {string} fallback Default English value.
 * @return {string} Localized value.
 */
export function t( key, fallback ) {
	return portalConfig().i18n?.[ key ] || fallback;
}

/**
 * Minimal hash-based router for the portal SPA.
 * Routes: #/ #/campaigns/new #/campaigns/:id #/campaigns/:id/analytics #/payments
 */
export function useRouter() {
	const [ route, setRoute ] = useState( () =>
		parseHash( window.location.hash )
	);

	useEffect( () => {
		const onChange = () => setRoute( parseHash( window.location.hash ) );
		window.addEventListener( 'hashchange', onChange );
		return () => window.removeEventListener( 'hashchange', onChange );
	}, [] );

	const navigate = ( hash ) => {
		window.location.hash = hash;
	};

	return { ...route, navigate };
}

function parseHash( hash ) {
	const raw = ( hash || '#/' ).replace( /^#\/?/, '' );
	const parts = raw.split( '/' ).filter( Boolean );

	// #/ → dashboard
	if ( parts.length === 0 ) {
		return { view: 'dashboard', id: null };
	}
	// #/payments
	if ( parts[ 0 ] === 'payments' ) {
		return { view: 'payments', id: null };
	}
	// #/campaigns/new
	if ( parts[ 0 ] === 'campaigns' && parts[ 1 ] === 'new' ) {
		return { view: 'create', id: null };
	}
	// #/campaigns/:id/analytics
	if ( parts[ 0 ] === 'campaigns' && parts[ 2 ] === 'analytics' ) {
		return { view: 'analytics', id: parts[ 1 ] };
	}
	// #/campaigns/:id/checkout
	if ( parts[ 0 ] === 'campaigns' && parts[ 2 ] === 'checkout' ) {
		return { view: 'checkout', id: parts[ 1 ] };
	}
	// #/campaigns/:id
	if ( parts[ 0 ] === 'campaigns' && parts[ 1 ] ) {
		return { view: 'detail', id: parts[ 1 ] };
	}

	return { view: 'dashboard', id: null };
}

/**
 * Badge component for campaign/payment status.
 * @param {Object} root0        Component properties.
 * @param {string} root0.status Status value.
 * @return {JSX.Element} Status badge.
 */
export function StatusBadge( { status } ) {
	const normalized = ( status || 'UNKNOWN' ).toString();
	const cssStatus = normalized.toLowerCase();
	const label = normalized.replace( /_/g, ' ' ).toLowerCase();

	return (
		<span className={ `af-badge af-badge-${ cssStatus }` }>{ label }</span>
	);
}

/**
 * Formats a number as currency (USD).
 * @param {number|string|null} amount   Amount to format.
 * @param {string}             currency ISO currency code.
 * @return {string} Formatted currency.
 */
export function formatCurrency( amount, currency = 'USD' ) {
	if ( amount === null || amount === undefined ) {
		return '—';
	}
	const locale =
		( window.afPublicPortal && window.afPublicPortal.locale ) || undefined;
	return new Intl.NumberFormat( locale, {
		style: 'currency',
		currency,
	} ).format( Number( amount ) );
}

/**
 * Formats ISO date string to human-readable.
 * @param {string} dateStr ISO date.
 * @return {string} Localized date.
 */
export function formatDate( dateStr ) {
	if ( ! dateStr ) {
		return '—';
	}
	const locale =
		( window.afPublicPortal && window.afPublicPortal.locale ) || undefined;
	return new Date( dateStr ).toLocaleDateString( locale, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
	} );
}

export function formatPlatform( platform ) {
	if ( ! platform ) {
		return '—';
	}

	const normalized = platform.toString().toUpperCase();
	const labels = {
		META: 'Meta',
		GOOGLE: 'Google',
		SPOTIFY: 'Spotify',
		TIKTOK: 'TikTok',
	};

	return labels[ normalized ] || normalized;
}
