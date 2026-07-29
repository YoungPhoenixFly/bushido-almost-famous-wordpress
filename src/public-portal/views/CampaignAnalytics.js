import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api, unwrapEntity } from '../api';
import { formatCurrency, formatPlatform, canManage } from '../utils';

/**
 * Campaign analytics view — key metrics in a stat row + table.
 * @param {Object}   root0          Component properties.
 * @param {string}   root0.id       Campaign ID.
 * @param {Function} root0.navigate Portal navigation callback.
 * @return {JSX.Element} Analytics view.
 */
export function CampaignAnalytics( { id, navigate } ) {
	const [ metrics, setMetrics ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ refreshing, setRefreshing ] = useState( false );
	const [ error, setError ] = useState( null );

	const load = () =>
		api
			.getCampaignAnalytics( id )
			.then( ( res ) => {
				setMetrics( unwrapEntity( res ) );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError(
					err.message ||
						__(
							'Failed to load analytics',
							'bushido-almost-famous'
						)
				);
				setLoading( false );
			} );

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ id ] );

	const handleRefresh = () => {
		setRefreshing( true );
		setError( null );
		// Ask the backend to re-pull fresh numbers from the ad platforms,
		// then refetch the aggregate.
		api.refreshCampaignMetrics( id )
			.catch( () => null ) // A failed refresh still falls through to a refetch.
			.then( () => load() )
			.then( () => setRefreshing( false ) );
	};

	if ( loading ) {
		return (
			<div className="af-loading" role="status">
				{ __( 'Loading analytics…', 'bushido-almost-famous' ) }
			</div>
		);
	}
	if ( error ) {
		return (
			<div>
				<div className="af-page-header">
					<h2>
						{ __( 'Campaign Analytics', 'bushido-almost-famous' ) }
					</h2>
					<button
						className="af-btn af-btn-secondary"
						onClick={ () => navigate( `#/campaigns/${ id }` ) }
					>
						{ __( '← Back', 'bushido-almost-famous' ) }
					</button>
				</div>
				<div className="af-alert af-alert-error" role="alert">
					{ error }
				</div>
			</div>
		);
	}

	// Normalize — af-server may return different shapes
	const data = metrics || {};
	const summary = data.summary || data;

	const stats = [
		{
			label: __( 'Impressions', 'bushido-almost-famous' ),
			value: summary.impressions ?? summary.totalImpressions ?? 0,
		},
		{
			label: __( 'Clicks', 'bushido-almost-famous' ),
			value: summary.clicks ?? summary.totalClicks ?? 0,
		},
		{
			label: __( 'Spend', 'bushido-almost-famous' ),
			value: formatCurrency( summary.spend ?? summary.totalSpend ?? 0 ),
		},
		{
			label: __( 'CTR', 'bushido-almost-famous' ),
			value: `${ (
				( summary.ctr ??
					( summary.clicks && summary.impressions
						? ( summary.clicks / summary.impressions ) * 100
						: 0 ) ) ||
				0
			).toFixed( 2 ) }%`,
		},
		{
			label: __( 'CPC', 'bushido-almost-famous' ),
			value: formatCurrency(
				summary.cpc ??
					( summary.clicks > 0
						? ( summary.spend || 0 ) / summary.clicks
						: 0 )
			),
		},
		{
			label: __( 'CPM', 'bushido-almost-famous' ),
			value: formatCurrency(
				summary.cpm ??
					( summary.impressions > 0
						? ( ( summary.spend || 0 ) / summary.impressions ) *
						  1000
						: 0 )
			),
		},
	];

	// Platform breakdown if available
	const platformBreakdown = data.platforms || data.byPlatform || [];

	return (
		<div>
			<div className="af-page-header">
				<h2>{ __( 'Campaign Analytics', 'bushido-almost-famous' ) }</h2>
				<div style={ { display: 'flex', gap: '8px' } }>
					{ canManage() && (
						<button
							className="af-btn af-btn-secondary"
							onClick={ handleRefresh }
							disabled={ refreshing }
						>
							{ refreshing
								? __( 'Refreshing…', 'bushido-almost-famous' )
								: __(
										'Refresh metrics',
										'bushido-almost-famous'
								  ) }
						</button>
					) }
					<button
						className="af-btn af-btn-secondary"
						onClick={ () => navigate( `#/campaigns/${ id }` ) }
					>
						{ __( '← Back', 'bushido-almost-famous' ) }
					</button>
				</div>
			</div>

			<div className="af-stats-row">
				{ stats.map( ( s ) => (
					<div key={ s.label } className="af-stat-card">
						<span className="af-stat-value">
							{ typeof s.value === 'number'
								? s.value.toLocaleString()
								: s.value }
						</span>
						<span className="af-stat-label">{ s.label }</span>
					</div>
				) ) }
			</div>

			{ Array.isArray( platformBreakdown ) &&
				platformBreakdown.length > 0 && (
					<div className="af-card">
						<h3
							style={ {
								margin: '0 0 12px',
								fontSize: '16px',
								fontWeight: 600,
							} }
						>
							{ __( 'By Platform', 'bushido-almost-famous' ) }
						</h3>
						<div className="af-table-wrap">
							<table className="af-table">
								<thead>
									<tr>
										<th>
											{ __(
												'Platform',
												'bushido-almost-famous'
											) }
										</th>
										<th>
											{ __(
												'Impressions',
												'bushido-almost-famous'
											) }
										</th>
										<th>
											{ __(
												'Clicks',
												'bushido-almost-famous'
											) }
										</th>
										<th>
											{ __(
												'Spend',
												'bushido-almost-famous'
											) }
										</th>
										<th>
											{ __(
												'CTR',
												'bushido-almost-famous'
											) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ platformBreakdown.map( ( p, i ) => {
										let ctr = '0.00';
										if (
											p.ctr !== null &&
											p.ctr !== undefined
										) {
											ctr = Number( p.ctr ).toFixed( 2 );
										} else if ( p.impressions > 0 ) {
											ctr = (
												( p.clicks / p.impressions ) *
												100
											).toFixed( 2 );
										}

										return (
											<tr key={ p.platform || i }>
												<td>
													{ formatPlatform(
														p.platform
													) }
												</td>
												<td>
													{ (
														p.impressions ?? 0
													).toLocaleString() }
												</td>
												<td>
													{ (
														p.clicks ?? 0
													).toLocaleString() }
												</td>
												<td>
													{ formatCurrency(
														p.spend ?? 0
													) }
												</td>
												<td>{ ctr }%</td>
											</tr>
										);
									} ) }
								</tbody>
							</table>
						</div>
					</div>
				) }
		</div>
	);
}
