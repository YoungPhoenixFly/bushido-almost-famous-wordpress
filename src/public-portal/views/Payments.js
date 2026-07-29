import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api, unwrapCollection } from '../api';
import { StatusBadge, formatCurrency, formatDate } from '../utils';

/**
 * Payments list view with refund action.
 * @param {Object}   root0          Component properties.
 * @param {Function} root0.navigate Portal navigation callback.
 * @return {JSX.Element} Payments view.
 */
export function Payments( { navigate } ) {
	const [ payments, setPayments ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ refunding, setRefunding ] = useState( null );
	const [ confirming, setConfirming ] = useState( null );
	const [ reason, setReason ] = useState( '' );
	const [ refundError, setRefundError ] = useState( null );

	useEffect( () => {
		api.listPayments()
			.then( ( res ) => {
				setPayments( unwrapCollection( res ) );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError(
					err.message ||
						__( 'Failed to load payments', 'bushido-almost-famous' )
				);
				setLoading( false );
			} );
	}, [] );

	const startRefund = ( paymentId ) => {
		setRefundError( null );
		setReason( '' );
		setConfirming( paymentId );
	};

	const submitRefund = async ( paymentId ) => {
		setRefundError( null );
		setRefunding( paymentId );
		try {
			await api.refundPayment( paymentId, {
				reason:
					reason.trim() ||
					__( 'User requested refund', 'bushido-almost-famous' ),
			} );
			const res = await api.listPayments();
			setPayments( unwrapCollection( res ) );
			setConfirming( null );
		} catch ( err ) {
			setRefundError(
				sprintf(
					/* translators: %s: refund request error message. */
					__(
						'Failed to request refund: %s',
						'bushido-almost-famous'
					),
					err.message ||
						__( 'Unknown error', 'bushido-almost-famous' )
				)
			);
		} finally {
			setRefunding( null );
		}
	};

	if ( loading ) {
		return (
			<div className="af-loading">
				{ __( 'Loading payments…', 'bushido-almost-famous' ) }
			</div>
		);
	}
	if ( error ) {
		return <div className="af-alert af-alert-error">{ error }</div>;
	}

	return (
		<div>
			<div className="af-page-header">
				<div>
					<h2>{ __( 'Payments', 'bushido-almost-famous' ) }</h2>
					<p>
						{ __(
							'View your payment history',
							'bushido-almost-famous'
						) }
					</p>
				</div>
			</div>

			{ refundError && (
				<div className="af-alert af-alert-error" role="alert">
					{ refundError }
				</div>
			) }

			{ payments.length === 0 ? (
				<div className="af-empty">
					<div className="af-empty-icon" aria-hidden="true">
						💳
					</div>
					<p>
						{ __(
							'No payments yet. Payments appear here after campaign checkout.',
							'bushido-almost-famous'
						) }
					</p>
				</div>
			) : (
				<div className="af-card">
					<div className="af-table-wrap">
						<table className="af-table">
							<thead>
								<tr>
									<th>
										{ __(
											'Date',
											'bushido-almost-famous'
										) }
									</th>
									<th>
										{ __(
											'Campaign',
											'bushido-almost-famous'
										) }
									</th>
									<th>
										{ __(
											'Amount',
											'bushido-almost-famous'
										) }
									</th>
									<th>
										{ __( 'Fee', 'bushido-almost-famous' ) }
									</th>
									<th>
										{ __(
											'Total',
											'bushido-almost-famous'
										) }
									</th>
									<th>
										{ __(
											'Status',
											'bushido-almost-famous'
										) }
									</th>
									<th>
										{ __(
											'Actions',
											'bushido-almost-famous'
										) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ payments.map( ( p ) => (
									<tr key={ p.id }>
										<td>{ formatDate( p.createdAt ) }</td>
										<td>
											{ p.campaignId ? (
												<button
													type="button"
													className="af-link-button"
													onClick={ () =>
														navigate(
															`#/campaigns/${ p.campaignId }`
														)
													}
												>
													{ p.campaignName ||
														p.campaignId.substring(
															0,
															8
														) }
												</button>
											) : (
												'—'
											) }
										</td>
										<td>
											{ formatCurrency(
												p.amount,
												p.currency || 'USD'
											) }
										</td>
										<td>
											{ formatCurrency(
												p.serviceFee,
												p.currency || 'USD'
											) }
										</td>
										<td style={ { fontWeight: 600 } }>
											{ formatCurrency(
												p.totalCharged,
												p.currency || 'USD'
											) }
										</td>
										<td>
											<StatusBadge status={ p.status } />
										</td>
										<td>
											{ p.status === 'PAID' &&
												confirming !== p.id && (
													<button
														className="af-btn af-btn-danger af-btn-sm"
														onClick={ () =>
															startRefund( p.id )
														}
													>
														{ __(
															'Refund',
															'bushido-almost-famous'
														) }
													</button>
												) }
											{ p.status === 'PAID' &&
												confirming === p.id && (
													<div
														className="af-refund-form"
														style={ {
															display: 'flex',
															gap: '6px',
															alignItems:
																'center',
															flexWrap: 'wrap',
														} }
													>
														<label
															className="screen-reader-text"
															htmlFor={ `af-refund-reason-${ p.id }` }
														>
															{ __(
																'Refund reason',
																'bushido-almost-famous'
															) }
														</label>
														<input
															id={ `af-refund-reason-${ p.id }` }
															type="text"
															placeholder={ __(
																'Reason (optional)',
																'bushido-almost-famous'
															) }
															value={ reason }
															onChange={ ( e ) =>
																setReason(
																	e.target
																		.value
																)
															}
															disabled={
																refunding ===
																p.id
															}
														/>
														<button
															className="af-btn af-btn-danger af-btn-sm"
															onClick={ () =>
																submitRefund(
																	p.id
																)
															}
															disabled={
																refunding ===
																p.id
															}
														>
															{ refunding === p.id
																? __(
																		'Requesting…',
																		'bushido-almost-famous'
																  )
																: __(
																		'Confirm refund',
																		'bushido-almost-famous'
																  ) }
														</button>
														<button
															className="af-btn af-btn-secondary af-btn-sm"
															onClick={ () =>
																setConfirming(
																	null
																)
															}
															disabled={
																refunding ===
																p.id
															}
														>
															{ __(
																'Cancel',
																'bushido-almost-famous'
															) }
														</button>
													</div>
												) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				</div>
			) }
		</div>
	);
}
