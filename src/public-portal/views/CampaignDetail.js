import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api, unwrapEntity } from '../api';
import {
	StatusBadge,
	formatCurrency,
	formatDate,
	formatPlatform,
	canManage,
} from '../utils';

/**
 * Campaign detail view — shows full info with lifecycle action buttons.
 * @param {Object}   root0          Component properties.
 * @param {string}   root0.id       Campaign ID.
 * @param {Function} root0.navigate Portal navigation callback.
 * @return {JSX.Element} Campaign detail view.
 */
export function CampaignDetail( { id, navigate } ) {
	const [ campaign, setCampaign ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ actionLoading, setActionLoading ] = useState( null );
	const [ actionError, setActionError ] = useState( null );

	const load = useCallback( () => {
		setLoading( true );
		api.getCampaign( id )
			.then( ( res ) => {
				setCampaign( unwrapEntity( res ) );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError(
					err.message ||
						__( 'Failed to load campaign', 'bushido-almost-famous' )
				);
				setLoading( false );
			} );
	}, [ id ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const doAction = async ( action, label ) => {
		// Native confirmation prevents accidental campaign lifecycle changes.
		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			sprintf(
				/* translators: %s: campaign action such as pause or archive. */
				__(
					'Are you sure you want to %s this campaign?',
					'bushido-almost-famous'
				),
				label
			)
		);
		if ( ! confirmed ) {
			return;
		}
		setActionError( null );
		setActionLoading( action );
		try {
			if ( action === 'pause' ) {
				await api.pauseCampaign( id );
			} else if ( action === 'resume' ) {
				await api.resumeCampaign( id );
			} else if ( action === 'archive' ) {
				await api.archiveCampaign( id );
			} else if ( action === 'duplicate' ) {
				const res = await api.duplicateCampaign( id );
				const newId = unwrapEntity( res )?.id;
				if ( newId ) {
					navigate( `#/campaigns/${ newId }` );
					return;
				}
			}
			load(); // Refresh
		} catch ( err ) {
			setActionError(
				sprintf(
					/* translators: 1: campaign action, 2: API error message. */
					__( 'Failed to %1$s: %2$s', 'bushido-almost-famous' ),
					label,
					err.message ||
						__( 'Unknown error', 'bushido-almost-famous' )
				)
			);
		} finally {
			setActionLoading( null );
		}
	};

	if ( loading ) {
		return (
			<div className="af-loading" role="status">
				{ __( 'Loading campaign…', 'bushido-almost-famous' ) }
			</div>
		);
	}
	if ( error ) {
		return (
			<div className="af-alert af-alert-error" role="alert">
				{ error }
			</div>
		);
	}
	if ( ! campaign ) {
		return (
			<div className="af-alert af-alert-error">
				{ __( 'Campaign not found', 'bushido-almost-famous' ) }
			</div>
		);
	}

	const s = ( campaign.status || '' ).toUpperCase();

	return (
		<div>
			<div className="af-page-header">
				<div>
					<h2>
						{ campaign.name ||
							__( 'Untitled Campaign', 'bushido-almost-famous' ) }
					</h2>
					<p>
						<StatusBadge status={ campaign.status } />{ ' ' }
						{ __( 'Created', 'bushido-almost-famous' ) }{ ' ' }
						{ formatDate( campaign.createdAt ) }
					</p>
				</div>
				<div style={ { display: 'flex', gap: '8px' } }>
					<button
						className="af-btn af-btn-secondary"
						onClick={ () =>
							navigate( `#/campaigns/${ id }/analytics` )
						}
					>
						📊 { __( 'Analytics', 'bushido-almost-famous' ) }
					</button>
					<button
						className="af-btn af-btn-secondary"
						onClick={ () => navigate( '#/' ) }
					>
						{ __( '← Back', 'bushido-almost-famous' ) }
					</button>
				</div>
			</div>

			<div className="af-stats-row">
				<div className="af-stat-card">
					<span className="af-stat-value">
						{ formatCurrency(
							campaign.budgetTotal || campaign.budgetDaily,
							campaign.currency || 'USD'
						) }
					</span>
					<span className="af-stat-label">
						{ __( 'Budget', 'bushido-almost-famous' ) }
					</span>
				</div>
				<div className="af-stat-card">
					<span className="af-stat-value">
						{ campaign.objective
							? campaign.objective
									.replace( /_/g, ' ' )
									.toLowerCase()
							: '—' }
					</span>
					<span className="af-stat-label">
						{ __( 'Objective', 'bushido-almost-famous' ) }
					</span>
				</div>
				<div className="af-stat-card">
					<span className="af-stat-value">
						{ campaign.creationMode || '—' }
					</span>
					<span className="af-stat-label">
						{ __( 'Mode', 'bushido-almost-famous' ) }
					</span>
				</div>
				<div className="af-stat-card">
					<span className="af-stat-value">
						{ campaign.currency || 'USD' }
					</span>
					<span className="af-stat-label">
						{ __( 'Currency', 'bushido-almost-famous' ) }
					</span>
				</div>
			</div>

			<div className="af-card" style={ { marginBottom: '16px' } }>
				<h3
					style={ {
						margin: '0 0 12px',
						fontSize: '16px',
						fontWeight: 600,
					} }
				>
					{ __( 'Details', 'bushido-almost-famous' ) }
				</h3>
				<div className="af-detail-grid">
					<span className="af-detail-label">
						{ __( 'Status', 'bushido-almost-famous' ) }
					</span>
					<span className="af-detail-value">
						<StatusBadge status={ campaign.status } />
					</span>

					<span className="af-detail-label">
						{ __( 'Daily Budget', 'bushido-almost-famous' ) }
					</span>
					<span className="af-detail-value">
						{ formatCurrency(
							campaign.budgetDaily,
							campaign.currency || 'USD'
						) }
					</span>

					<span className="af-detail-label">
						{ __( 'Total Budget', 'bushido-almost-famous' ) }
					</span>
					<span className="af-detail-value">
						{ formatCurrency(
							campaign.budgetTotal,
							campaign.currency || 'USD'
						) }
					</span>

					<span className="af-detail-label">
						{ __( 'Start Date', 'bushido-almost-famous' ) }
					</span>
					<span className="af-detail-value">
						{ formatDate( campaign.startDate ) }
					</span>

					<span className="af-detail-label">
						{ __( 'End Date', 'bushido-almost-famous' ) }
					</span>
					<span className="af-detail-value">
						{ formatDate( campaign.endDate ) }
					</span>

					{ campaign.targeting && (
						<>
							<span className="af-detail-label">
								{ __( 'Targeting', 'bushido-almost-famous' ) }
							</span>
							<span className="af-detail-value">
								{ typeof campaign.targeting === 'object'
									? JSON.stringify(
											campaign.targeting,
											null,
											2
									  ).substring( 0, 200 )
									: String( campaign.targeting ) }
							</span>
						</>
					) }
				</div>
			</div>

			{ /* Platform allocations */ }
			{ campaign.campaignPlatforms &&
				campaign.campaignPlatforms.length > 0 && (
					<div className="af-card" style={ { marginBottom: '16px' } }>
						<h3
							style={ {
								margin: '0 0 12px',
								fontSize: '16px',
								fontWeight: 600,
							} }
						>
							{ __( 'Platforms', 'bushido-almost-famous' ) }
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
												'Allocation',
												'bushido-almost-famous'
											) }
										</th>
										<th>
											{ __(
												'Status',
												'bushido-almost-famous'
											) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ campaign.campaignPlatforms.map( ( p ) => (
										<tr key={ p.id || p.platform }>
											<td>
												{ formatPlatform( p.platform ) }
											</td>
											<td>
												{ p.budgetAllocation !== null &&
												p.budgetAllocation !== undefined
													? sprintf(
															/* translators: %s: platform budget allocation percentage. */
															__(
																'%1$s%%',
																'bushido-almost-famous'
															),
															p.budgetAllocation
													  )
													: '—' }
											</td>
											<td>
												<StatusBadge
													status={
														p.platformStatus ||
														p.status ||
														'pending'
													}
												/>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					</div>
				) }

			{ /* Actions — management only */ }
			{ canManage() && (
				<div className="af-card">
					<h3
						style={ {
							margin: '0 0 12px',
							fontSize: '16px',
							fontWeight: 600,
						} }
					>
						{ __( 'Actions', 'bushido-almost-famous' ) }
					</h3>
					{ actionError && (
						<div className="af-alert af-alert-error" role="alert">
							{ actionError }
						</div>
					) }
					<div
						style={ {
							display: 'flex',
							gap: '8px',
							flexWrap: 'wrap',
						} }
					>
						{ s === 'DRAFT' && (
							<button
								className="af-btn af-btn-success"
								onClick={ () =>
									navigate( `#/campaigns/${ id }/checkout` )
								}
								disabled={ !! actionLoading }
							>
								💳{ ' ' }
								{ __(
									'Checkout & Pay',
									'bushido-almost-famous'
								) }
							</button>
						) }
						{ s === 'ACTIVE' && (
							<button
								className="af-btn af-btn-warning af-btn-sm"
								onClick={ () => doAction( 'pause', 'pause' ) }
								disabled={ !! actionLoading }
							>
								{ actionLoading === 'pause'
									? __( 'Pausing…', 'bushido-almost-famous' )
									: '⏸ ' +
									  __( 'Pause', 'bushido-almost-famous' ) }
							</button>
						) }
						{ s === 'PAUSED' && (
							<button
								className="af-btn af-btn-success af-btn-sm"
								onClick={ () => doAction( 'resume', 'resume' ) }
								disabled={ !! actionLoading }
							>
								{ actionLoading === 'resume'
									? __( 'Resuming…', 'bushido-almost-famous' )
									: '▶ ' +
									  __( 'Resume', 'bushido-almost-famous' ) }
							</button>
						) }
						<button
							className="af-btn af-btn-secondary af-btn-sm"
							onClick={ () =>
								doAction( 'duplicate', 'duplicate' )
							}
							disabled={ !! actionLoading }
						>
							{ actionLoading === 'duplicate'
								? __( 'Duplicating…', 'bushido-almost-famous' )
								: '📋 ' +
								  __( 'Duplicate', 'bushido-almost-famous' ) }
						</button>
						{ s !== 'ARCHIVED' && (
							<button
								className="af-btn af-btn-danger af-btn-sm"
								onClick={ () =>
									doAction( 'archive', 'archive' )
								}
								disabled={ !! actionLoading }
							>
								{ actionLoading === 'archive'
									? __(
											'Archiving…',
											'bushido-almost-famous'
									  )
									: '🗃 ' +
									  __( 'Archive', 'bushido-almost-famous' ) }
							</button>
						) }
					</div>
				</div>
			) }
		</div>
	);
}
