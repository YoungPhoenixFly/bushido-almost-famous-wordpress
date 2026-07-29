import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const portalConfig = () => window.afPublicPortal || {};
const ep = () => portalConfig().endpoints || {};

function buildHeaders( extraHeaders = {} ) {
	const config = portalConfig();
	const headers = { ...extraHeaders };

	// Signed-in users authorize every request with the REST nonce (the server
	// requires it on writes). The portal is an authenticated console, so there
	// is no anonymous data path — anonymous visitors see the sign-in gate.
	if ( config.nonce ) {
		headers[ 'X-WP-Nonce' ] = config.nonce;
	}

	if ( config.demoMode ) {
		headers[ 'X-AF-Demo-Mode' ] = '1';
	}

	return headers;
}

function normalizeError( error ) {
	const message =
		error?.data?.error?.message ||
		error?.data?.message ||
		error?.message ||
		__( 'Request failed', 'bushido-almost-famous' );

	const normalized = new Error( message );
	normalized.code =
		error?.data?.error?.code || error?.code || 'request_failed';
	normalized.detail = error?.data?.error?.detail || '';
	normalized.status = error?.statusCode || error?.data?.status || 500;

	return normalized;
}

async function request( { path, method = 'GET', data } ) {
	try {
		return await apiFetch( {
			path,
			method,
			data,
			headers: buildHeaders(),
		} );
	} catch ( error ) {
		throw normalizeError( error );
	}
}

export function unwrapEntity( response ) {
	return response?.data ?? response ?? null;
}

export function unwrapCollection( response ) {
	const payload = unwrapEntity( response );

	if ( Array.isArray( payload ) ) {
		return payload;
	}

	if ( Array.isArray( payload?.data ) ) {
		return payload.data;
	}

	return [];
}

export const api = {
	listCampaigns: ( params = {} ) => {
		const qs = new URLSearchParams( params ).toString();
		const base = ep().campaigns || '/almost-famous/v1/campaigns';
		return request( { path: qs ? `${ base }?${ qs }` : base } );
	},
	getCampaign: ( id ) =>
		request( {
			path: `${
				ep().campaigns || '/almost-famous/v1/campaigns'
			}/${ id }`,
		} ),
	createCampaign: ( data ) =>
		request( {
			path: ep().campaigns || '/almost-famous/v1/campaigns',
			method: 'POST',
			data,
		} ),
	pauseCampaign: ( id ) =>
		request( {
			path: `${
				ep().campaigns || '/almost-famous/v1/campaigns'
			}/${ id }/pause`,
			method: 'POST',
		} ),
	resumeCampaign: ( id ) =>
		request( {
			path: `${
				ep().campaigns || '/almost-famous/v1/campaigns'
			}/${ id }/resume`,
			method: 'POST',
		} ),
	archiveCampaign: ( id ) =>
		request( {
			path: `${
				ep().campaigns || '/almost-famous/v1/campaigns'
			}/${ id }/archive`,
			method: 'POST',
		} ),
	duplicateCampaign: ( id ) =>
		request( {
			path: `${
				ep().campaigns || '/almost-famous/v1/campaigns'
			}/${ id }/duplicate`,
			method: 'POST',
		} ),
	getCampaignAnalytics: ( id ) =>
		request( {
			path: `${
				ep().campaigns || '/almost-famous/v1/campaigns'
			}/${ id }/analytics`,
		} ),
	refreshCampaignMetrics: ( id ) =>
		request( {
			path: `${
				ep().campaigns || '/almost-famous/v1/campaigns'
			}/${ id }/metrics/refresh`,
			method: 'POST',
		} ),
	createCheckout: ( campaignId, data ) =>
		request( {
			path: `${
				ep().campaigns || '/almost-famous/v1/campaigns'
			}/${ campaignId }/checkout`,
			method: 'POST',
			data,
		} ),

	listAudiences: ( params = {} ) => {
		const qs = new URLSearchParams( params ).toString();
		const base = ep().audiences || '/almost-famous/v1/audiences';
		return request( { path: qs ? `${ base }?${ qs }` : base } );
	},
	listCreatives: ( params = {} ) => {
		const qs = new URLSearchParams( params ).toString();
		const base = ep().creatives || '/almost-famous/v1/creatives';
		return request( { path: qs ? `${ base }?${ qs }` : base } );
	},

	listPayments: ( params = {} ) => {
		const qs = new URLSearchParams( params ).toString();
		const base = ep().payments || '/almost-famous/v1/payments';
		return request( { path: qs ? `${ base }?${ qs }` : base } );
	},
	getPayment: ( id ) =>
		request( {
			path: `${ ep().payments || '/almost-famous/v1/payments' }/${ id }`,
		} ),
	refundPayment: ( id, data ) =>
		request( {
			path: `${
				ep().payments || '/almost-famous/v1/payments'
			}/${ id }/refund`,
			method: 'POST',
			data,
		} ),
};
