import { useState, useEffect } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, unwrapEntity } from '../api';
import { formatCurrency } from '../utils';

/**
 * Days between the campaign's start and end dates — the duration the checkout
 * charges for (the backend bills `budgetDaily × durationDays` for a FIXED
 * duration). Falls back to 1 day when the dates are missing or invalid.
 * @param {Object|null} campaign Campaign data.
 * @return {number} Billable duration in days.
 */
function campaignDurationDays( campaign ) {
	const start = campaign?.startDate ? new Date( campaign.startDate ) : null;
	const end = campaign?.endDate ? new Date( campaign.endDate ) : null;
	if (
		! start ||
		! end ||
		Number.isNaN( start.getTime() ) ||
		Number.isNaN( end.getTime() )
	) {
		return 1;
	}
	return Math.max(
		1,
		Math.ceil( ( end.getTime() - start.getTime() ) / 86400000 )
	);
}

/**
 * Checkout flow — prepares the real server-side order (which computes the
 * charge from the stored budget plus the organization's service fee), shows
 * the exact amounts from the created payment record, then hands off to Stripe.
 * @param {Object}   root0          Component properties.
 * @param {string}   root0.id       Campaign ID.
 * @param {Function} root0.navigate Portal navigation callback.
 * @return {JSX.Element} Checkout view.
 */
export function Checkout( { id, navigate } ) {
	const [ campaign, setCampaign ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	// { checkoutUrl, payment } once the server has created the order. The
	// payment record carries the authoritative amount/serviceFee/totalCharged.
	const [ order, setOrder ] = useState( null );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
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

	const durationDays = campaignDurationDays( campaign );

	const handlePrepare = async () => {
		setSubmitting( true );
		setError( null );
		try {
			const res = await api.createCheckout( id, {
				successUrl:
					window.location.href.split( '#' )[ 0 ] +
					`#/campaigns/${ id }`,
				cancelUrl:
					window.location.href.split( '#' )[ 0 ] +
					`#/campaigns/${ id }`,
				durationType: 'FIXED',
				durationDays,
			} );
			const checkout = unwrapEntity( res );
			if ( ! checkout?.checkoutUrl ) {
				setError(
					__(
						'No checkout URL received. The campaign may not be ready for checkout.',
						'bushido-almost-famous'
					)
				);
				return;
			}
			// The checkout response carries no amounts; the payment record it
			// created does. Fetch it so the summary shows the exact figures
			// Stripe will charge. A failure here is non-fatal — Stripe shows
			// the same total on its payment page.
			let payment = null;
			if ( checkout.paymentId ) {
				try {
					payment = unwrapEntity(
						await api.getPayment( checkout.paymentId )
					);
				} catch {
					payment = null;
				}
			}
			setOrder( { checkoutUrl: checkout.checkoutUrl, payment } );
		} catch ( err ) {
			setError(
				err.message ||
					__(
						'Failed to create checkout session',
						'bushido-almost-famous'
					)
			);
		} finally {
			setSubmitting( false );
		}
	};

	if ( loading ) {
		return (
			<div className="af-loading" role="status">
				{ __( 'Loading checkout…', 'bushido-almost-famous' ) }
			</div>
		);
	}
	if ( error && ! campaign ) {
		return (
			<div>
				<div className="af-page-header">
					<h2>{ __( 'Checkout', 'bushido-almost-famous' ) }</h2>
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

	// Pre-order estimate mirroring the backend's charge basis for a FIXED
	// duration: daily budget × days when a daily budget is set, else the
	// stored total. Fees are intentionally NOT estimated here — the service
	// fee rate is org-specific and only the server knows it, so the fee and
	// total render from the real payment record after the order is prepared.
	const budgetDaily = Number( campaign?.budgetDaily ) || 0;
	const budgetTotal = Number( campaign?.budgetTotal ) || 0;
	const estimatedBudget =
		budgetDaily > 0 ? budgetDaily * durationDays : budgetTotal;
	const currency = campaign?.currency || 'USD';

	const payment = order?.payment;
	const totalCharged = Number( payment?.totalCharged );
	const hasAmounts = Number.isFinite( totalCharged );
	const licensingFee = Number( payment?.licensingFee ) || 0;

	return (
		<div>
			<div className="af-page-header">
				<div>
					<h2>{ __( 'Checkout', 'bushido-almost-famous' ) }</h2>
					<p>
						{ campaign?.name ||
							__( 'Campaign', 'bushido-almost-famous' ) }
					</p>
				</div>
				<button
					className="af-btn af-btn-secondary"
					onClick={ () => navigate( `#/campaigns/${ id }` ) }
				>
					{ __( '← Back', 'bushido-almost-famous' ) }
				</button>
			</div>

			{ error && (
				<div className="af-alert af-alert-error" role="alert">
					{ error }
				</div>
			) }

			<div className="af-card" style={ { maxWidth: '500px' } }>
				<h3
					style={ {
						margin: '0 0 16px',
						fontSize: '16px',
						fontWeight: 600,
					} }
				>
					{ __( 'Order Summary', 'bushido-almost-famous' ) }
				</h3>

				<div className="af-detail-grid">
					<span className="af-detail-label">
						{ __( 'Campaign', 'bushido-almost-famous' ) }
					</span>
					<span className="af-detail-value">
						{ campaign?.name ||
							__( 'Untitled', 'bushido-almost-famous' ) }
					</span>

					<span className="af-detail-label">
						{ __( 'Objective', 'bushido-almost-famous' ) }
					</span>
					<span
						className="af-detail-value"
						style={ { textTransform: 'capitalize' } }
					>
						{ campaign?.objective
							? campaign.objective
									.replace( /_/g, ' ' )
									.toLowerCase()
							: '—' }
					</span>

					<span className="af-detail-label">
						{ __( 'Duration', 'bushido-almost-famous' ) }
					</span>
					<span className="af-detail-value">
						{ sprintf(
							/* translators: %d: number of days the campaign runs. */
							_n(
								'%d day',
								'%d days',
								durationDays,
								'bushido-almost-famous'
							),
							durationDays
						) }
					</span>

					{ ! order && (
						<>
							<span className="af-detail-label">
								{ __( 'Ad Budget', 'bushido-almost-famous' ) }
							</span>
							<span className="af-detail-value">
								{ formatCurrency( estimatedBudget, currency ) }
							</span>
						</>
					) }

					{ order && hasAmounts && (
						<>
							<span className="af-detail-label">
								{ __( 'Ad Budget', 'bushido-almost-famous' ) }
							</span>
							<span className="af-detail-value">
								{ formatCurrency(
									payment.amount,
									payment.currency || currency
								) }
							</span>

							<span className="af-detail-label">
								{ __( 'Service Fee', 'bushido-almost-famous' ) }
							</span>
							<span className="af-detail-value">
								{ formatCurrency(
									payment.serviceFee,
									payment.currency || currency
								) }
							</span>

							{ licensingFee > 0 && (
								<>
									<span className="af-detail-label">
										{ __(
											'Licensing Fee',
											'bushido-almost-famous'
										) }
									</span>
									<span className="af-detail-value">
										{ formatCurrency(
											licensingFee,
											payment.currency || currency
										) }
									</span>
								</>
							) }

							<span
								className="af-detail-label"
								style={ { fontWeight: 700, fontSize: '14px' } }
							>
								{ __( 'Total', 'bushido-almost-famous' ) }
							</span>
							<span
								className="af-detail-value"
								style={ {
									fontWeight: 700,
									fontSize: '18px',
									color: '#6c5ce7',
								} }
							>
								{ formatCurrency(
									totalCharged,
									payment.currency || currency
								) }
							</span>
						</>
					) }
				</div>

				<div style={ { marginTop: '24px' } }>
					{ ! order ? (
						<>
							<button
								className="af-btn af-btn-primary"
								onClick={ handlePrepare }
								disabled={ submitting }
								style={ {
									width: '100%',
									justifyContent: 'center',
									padding: '14px',
								} }
							>
								{ submitting
									? __(
											'Preparing order…',
											'bushido-almost-famous'
									  )
									: __(
											'Review Order →',
											'bushido-almost-famous'
									  ) }
							</button>
							<p
								style={ {
									fontSize: '12px',
									color: '#636e72',
									textAlign: 'center',
									marginTop: '8px',
								} }
							>
								{ __(
									'The exact total, including your organization’s service fee, is calculated by the server and shown before you pay.',
									'bushido-almost-famous'
								) }
							</p>
						</>
					) : (
						<>
							<button
								className="af-btn af-btn-primary"
								onClick={ () => {
									window.location.href = order.checkoutUrl;
								} }
								style={ {
									width: '100%',
									justifyContent: 'center',
									padding: '14px',
								} }
							>
								{ hasAmounts
									? sprintf(
											/* translators: %s: formatted checkout total. */
											__(
												'Pay %s →',
												'bushido-almost-famous'
											),
											formatCurrency(
												totalCharged,
												payment.currency || currency
											)
									  )
									: __(
											'Continue to Stripe →',
											'bushido-almost-famous'
									  ) }
							</button>
							<p
								style={ {
									fontSize: '12px',
									color: '#636e72',
									textAlign: 'center',
									marginTop: '8px',
								} }
							>
								{ hasAmounts
									? __(
											'You will be redirected to Stripe for secure payment.',
											'bushido-almost-famous'
									  )
									: __(
											'The final total, including your organization’s service fee, is shown on the secure Stripe payment page.',
											'bushido-almost-famous'
									  ) }
							</p>
						</>
					) }
				</div>
			</div>
		</div>
	);
}
