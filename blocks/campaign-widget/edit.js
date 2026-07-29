/**
 * @package
 * @license GPL-2.0-or-later
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	Spinner,
	Placeholder,
} from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export default function Edit( { attributes, setAttributes } ) {
	const campaignId = attributes.campaign_id;
	const [ campaign, setCampaign ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const blockProps = useBlockProps();

	useEffect( () => {
		if ( ! campaignId ) {
			setCampaign( null );
			return;
		}

		setLoading( true );
		setError( '' );

		apiFetch( { path: `/almost-famous/v1/campaigns/${ campaignId }` } )
			.then( ( response ) => {
				setCampaign( response.data || response );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError(
					err.message ||
						__(
							'Failed to load campaign.',
							'bushido-almost-famous'
						)
				);
				setCampaign( null );
				setLoading( false );
			} );
	}, [ campaignId ] );

	const formatNumber = ( num ) => {
		if ( num === undefined || num === null ) {
			return '\u2014';
		}
		return Number( num ).toLocaleString();
	};

	const formatCurrency = ( num ) => {
		if ( num === undefined || num === null ) {
			return '\u2014';
		}
		return '$' + Number( num ).toFixed( 2 );
	};

	const formatRoas = ( num ) => {
		if ( num === undefined || num === null ) {
			return '\u2014';
		}
		return Number( num ).toFixed( 2 );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Campaign Settings', 'bushido-almost-famous' ) }
				>
					<TextControl
						label={ __( 'Campaign ID', 'bushido-almost-famous' ) }
						value={ campaignId || '' }
						onChange={ ( value ) =>
							setAttributes( { campaign_id: value } )
						}
						help={ __(
							'Enter the Bushido campaign ID to display.',
							'bushido-almost-famous'
						) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ ! campaignId && (
					<Placeholder
						icon="chart-bar"
						label={ __(
							'Campaign Widget',
							'bushido-almost-famous'
						) }
						instructions={ __(
							'Enter a Campaign ID in the block settings to display performance data.',
							'bushido-almost-famous'
						) }
					>
						<TextControl
							label={ __(
								'Campaign ID',
								'bushido-almost-famous'
							) }
							value={ campaignId || '' }
							onChange={ ( value ) =>
								setAttributes( { campaign_id: value } )
							}
						/>
					</Placeholder>
				) }

				{ campaignId && loading && (
					<Placeholder
						icon="chart-bar"
						label={ __(
							'Campaign Widget',
							'bushido-almost-famous'
						) }
					>
						<Spinner />
					</Placeholder>
				) }

				{ campaignId && error && (
					<Placeholder
						icon="chart-bar"
						label={ __(
							'Campaign Widget',
							'bushido-almost-famous'
						) }
					>
						<p style={ { color: '#d63638' } }>{ error }</p>
					</Placeholder>
				) }

				{ campaignId && campaign && ! loading && (
					<div
						className="af-campaign-widget"
						style={ {
							border: '1px solid #e2e4e7',
							borderRadius: '4px',
							padding: '16px',
							background: '#fff',
						} }
					>
						<div
							style={ {
								display: 'flex',
								justifyContent: 'space-between',
								alignItems: 'center',
								marginBottom: '12px',
							} }
						>
							<h4 style={ { margin: 0, fontSize: '16px' } }>
								{ campaign.name ||
									__(
										'Unknown Campaign',
										'bushido-almost-famous'
									) }
							</h4>
							<span
								style={ {
									display: 'inline-block',
									padding: '2px 8px',
									borderRadius: '3px',
									fontSize: '12px',
									fontWeight: 600,
									textTransform: 'uppercase',
									background: '#f0f0f1',
									color: '#50575e',
								} }
							>
								{ campaign.status || 'unknown' }
							</span>
						</div>
						<div
							style={ {
								display: 'grid',
								gridTemplateColumns: '1fr 1fr 1fr',
								gap: '12px',
							} }
						>
							<div>
								<span
									style={ {
										display: 'block',
										fontSize: '11px',
										textTransform: 'uppercase',
										color: '#757575',
										marginBottom: '2px',
									} }
								>
									{ __( 'ROAS', 'bushido-almost-famous' ) }
								</span>
								<span
									style={ {
										display: 'block',
										fontSize: '20px',
										fontWeight: 700,
										color: '#1d2327',
									} }
								>
									{ formatRoas( campaign.roas ) }
								</span>
							</div>
							<div>
								<span
									style={ {
										display: 'block',
										fontSize: '11px',
										textTransform: 'uppercase',
										color: '#757575',
										marginBottom: '2px',
									} }
								>
									{ __(
										'Impressions',
										'bushido-almost-famous'
									) }
								</span>
								<span
									style={ {
										display: 'block',
										fontSize: '20px',
										fontWeight: 700,
										color: '#1d2327',
									} }
								>
									{ formatNumber( campaign.impressions ) }
								</span>
							</div>
							<div>
								<span
									style={ {
										display: 'block',
										fontSize: '11px',
										textTransform: 'uppercase',
										color: '#757575',
										marginBottom: '2px',
									} }
								>
									{ __( 'Spend', 'bushido-almost-famous' ) }
								</span>
								<span
									style={ {
										display: 'block',
										fontSize: '20px',
										fontWeight: 700,
										color: '#1d2327',
									} }
								>
									{ formatCurrency( campaign.spend ) }
								</span>
							</div>
						</div>
					</div>
				) }
			</div>
		</>
	);
}
