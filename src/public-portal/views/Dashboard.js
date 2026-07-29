import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api, unwrapCollection } from '../api';
import { StatusBadge, formatCurrency, formatDate, canManage } from '../utils';

/**
 * Dashboard — campaign list with search, filter, and action buttons.
 * @param {Object}   root0          Component properties.
 * @param {Function} root0.navigate Portal navigation callback.
 * @return {JSX.Element} Campaign dashboard.
 */
export function Dashboard( { navigate } ) {
	const manage = canManage();
	const [ campaigns, setCampaigns ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ statusFilter, setStatusFilter ] = useState( '' );

	const load = useCallback( () => {
		setLoading( true );
		const params = {};
		if ( search ) {
			params.search = search;
		}
		if ( statusFilter ) {
			params.status = statusFilter;
		}
		api.listCampaigns( params )
			.then( ( res ) => {
				setCampaigns( unwrapCollection( res ) );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError(
					err.message ||
						__(
							'Failed to load campaigns',
							'bushido-almost-famous'
						)
				);
				setLoading( false );
			} );
	}, [ search, statusFilter ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const handleSearch = ( e ) => {
		e.preventDefault();
		load();
	};

	if ( loading ) {
		return (
			<div className="af-loading" role="status">
				{ __( 'Loading campaigns…', 'bushido-almost-famous' ) }
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

	return (
		<div>
			<div className="af-page-header">
				<div>
					<h2>{ __( 'Campaigns', 'bushido-almost-famous' ) }</h2>
					<p>
						{ __(
							'Manage your ad campaigns',
							'bushido-almost-famous'
						) }
					</p>
				</div>
				{ manage && (
					<button
						className="af-btn af-btn-primary"
						onClick={ () => navigate( '#/campaigns/new' ) }
					>
						{ __( '+ New Campaign', 'bushido-almost-famous' ) }
					</button>
				) }
			</div>

			<form className="af-toolbar" onSubmit={ handleSearch }>
				<input
					type="text"
					placeholder={ __(
						'Search campaigns…',
						'bushido-almost-famous'
					) }
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
				/>
				<select
					value={ statusFilter }
					onChange={ ( e ) => setStatusFilter( e.target.value ) }
				>
					<option value="">
						{ __( 'All statuses', 'bushido-almost-famous' ) }
					</option>
					<option value="DRAFT">
						{ __( 'Draft', 'bushido-almost-famous' ) }
					</option>
					<option value="ACTIVE">
						{ __( 'Active', 'bushido-almost-famous' ) }
					</option>
					<option value="PAUSED">
						{ __( 'Paused', 'bushido-almost-famous' ) }
					</option>
					<option value="COMPLETED">
						{ __( 'Completed', 'bushido-almost-famous' ) }
					</option>
					<option value="ARCHIVED">
						{ __( 'Archived', 'bushido-almost-famous' ) }
					</option>
				</select>
				<button
					type="submit"
					className="af-btn af-btn-secondary af-btn-sm"
				>
					{ __( 'Search', 'bushido-almost-famous' ) }
				</button>
			</form>

			{ campaigns.length === 0 ? (
				<div className="af-empty">
					<div className="af-empty-icon" aria-hidden="true">
						📢
					</div>
					<p>
						{ manage
							? __(
									'No campaigns found. Create your first campaign to get started.',
									'bushido-almost-famous'
							  )
							: __(
									'No campaigns yet.',
									'bushido-almost-famous'
							  ) }
					</p>
					{ manage && (
						<button
							className="af-btn af-btn-primary"
							onClick={ () => navigate( '#/campaigns/new' ) }
						>
							{ __( 'Create Campaign', 'bushido-almost-famous' ) }
						</button>
					) }
				</div>
			) : (
				<div className="af-campaign-grid">
					{ campaigns.map( ( c ) => (
						<div key={ c.id } className="af-card af-campaign-card">
							<div className="af-card-header">
								<button
									type="button"
									className="af-campaign-name af-link-button"
									onClick={ () =>
										navigate( `#/campaigns/${ c.id }` )
									}
								>
									{ c.name ||
										__(
											'Untitled Campaign',
											'bushido-almost-famous'
										) }
								</button>
								<StatusBadge status={ c.status } />
							</div>
							<div className="af-card-body">
								<div className="af-campaign-meta">
									<span>
										💰{ ' ' }
										{ formatCurrency(
											c.budgetTotal || c.budgetDaily,
											c.currency || 'USD'
										) }
									</span>
									<span>
										📅 { formatDate( c.createdAt ) }
									</span>
									{ c.objective && (
										<span>
											🎯{ ' ' }
											{ c.objective
												.replace( /_/g, ' ' )
												.toLowerCase() }
										</span>
									) }
								</div>
							</div>
							<div className="af-card-footer">
								<button
									className="af-btn af-btn-secondary af-btn-sm"
									onClick={ () =>
										navigate( `#/campaigns/${ c.id }` )
									}
								>
									{ __( 'View', 'bushido-almost-famous' ) }
								</button>
								{ manage && c.status === 'DRAFT' && (
									<button
										className="af-btn af-btn-success af-btn-sm"
										onClick={ () =>
											navigate(
												`#/campaigns/${ c.id }/checkout`
											)
										}
									>
										{ __(
											'Checkout',
											'bushido-almost-famous'
										) }
									</button>
								) }
								{ c.status === 'ACTIVE' && (
									<button
										className="af-btn af-btn-secondary af-btn-sm"
										onClick={ () =>
											navigate(
												`#/campaigns/${ c.id }/analytics`
											)
										}
									>
										{ __(
											'Analytics',
											'bushido-almost-famous'
										) }
									</button>
								) }
							</div>
						</div>
					) ) }
				</div>
			) }
		</div>
	);
}
