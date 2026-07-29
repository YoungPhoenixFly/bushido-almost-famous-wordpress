import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api, unwrapEntity, unwrapCollection } from '../api';
import { formatPlatform, canManage, portalConfig } from '../utils';

const OBJECTIVES = [
	{
		value: 'AWARENESS',
		label: __( 'Brand Awareness', 'bushido-almost-famous' ),
	},
	{ value: 'TRAFFIC', label: __( 'Traffic', 'bushido-almost-famous' ) },
	{ value: 'ENGAGEMENT', label: __( 'Engagement', 'bushido-almost-famous' ) },
	{
		value: 'CONVERSIONS',
		label: __( 'Conversions', 'bushido-almost-famous' ),
	},
	{
		value: 'VIDEO_VIEWS',
		label: __( 'Video Views', 'bushido-almost-famous' ),
	},
	{ value: 'STREAMING', label: __( 'Streaming', 'bushido-almost-famous' ) },
];

const PLATFORMS = [ 'META', 'GOOGLE', 'SPOTIFY', 'TIKTOK' ];

// Ad-copy length limits mirror the service's pre-checkout creative gate, which
// refuses POST /payments/checkout when the copy would fail a platform's
// validator at launch. Validating the same bounds here turns the server's 422
// into an inline, per-field message.
const META_TITLE_MAX = 40;
const META_DESCRIPTION_MAX = 30;
const GOOGLE_TITLE_MAX = 30;
const GOOGLE_VIDEO_TITLE_MAX = 40;
const GOOGLE_DESCRIPTION_MAX = 90;
const SPOTIFY_TITLE_MAX = 40;
const TIKTOK_AD_TEXT_MAX = 100;

const CREATIVE_TYPE_LABELS = {
	image: __( 'Image', 'bushido-almost-famous' ),
	video: __( 'Video', 'bushido-almost-famous' ),
	audio: __( 'Audio', 'bushido-almost-famous' ),
};

/**
 * Mirrors `isValidHttpUrl` in the backend gate: parseable AND http(s).
 * @param {string} value Candidate URL.
 * @return {boolean} Whether the value is an HTTP(S) URL.
 */
function isValidHttpUrl( value ) {
	let parsed;
	try {
		parsed = new URL( value );
	} catch {
		return false;
	}
	return parsed.protocol === 'http:' || parsed.protocol === 'https:';
}

/**
 * Mirrors the gate's `isSpotifyUri`: a `spotify:<type>:<id>` URI or an
 * open.spotify.com / spotify.link URL. Engagement/streaming campaigns on
 * Spotify must click through to a Spotify destination.
 * @param {string} value Candidate Spotify destination.
 * @return {boolean} Whether the value is a Spotify destination.
 */
function isSpotifyLink( value ) {
	return (
		/^spotify:[a-z]+:[a-zA-Z0-9]+/.test( value ) ||
		/^https?:\/\/(open\.spotify\.com|spotify\.link)\//i.test( value )
	);
}

/**
 * Accepts a raw YouTube video id or a youtube.com / youtu.be URL and returns
 * the video id, or '' when it can't be parsed.
 * @param {string} value YouTube URL or video ID.
 * @return {string} Parsed video ID, or an empty string.
 */
function parseYouTubeId( value ) {
	const trimmed = ( value || '' ).trim();
	if ( ! trimmed ) {
		return '';
	}
	const match = trimmed.match(
		/(?:youtube\.com\/(?:watch\?.*v=|shorts\/|embed\/)|youtu\.be\/)([\w-]{6,})/
	);
	if ( match ) {
		return match[ 1 ];
	}
	return /^[\w-]{6,}$/.test( trimmed ) ? trimmed : '';
}

/**
 * Client-side mirror of the backend pre-checkout creative gate
 * (validateCampaignCreative + validateConversionTracking). Returns an array of
 * friendly error strings; empty means the campaign should pass the server
 * gate's presence/length rules for the selected platforms.
 * @param {Object}   root0                Validation input.
 * @param {string[]} root0.platforms      Selected platform keys.
 * @param {string}   root0.objective      Campaign objective.
 * @param {string}   root0.title          Ad title.
 * @param {string}   root0.description    Ad description.
 * @param {string}   root0.externalLink   Destination URL.
 * @param {string}   root0.youtubeVideoId YouTube video ID.
 * @param {string}   root0.pixelId        Tracking pixel ID.
 * @param {boolean}  root0.hasVideo       Whether video is selected.
 * @param {boolean}  root0.hasImage       Whether image is selected.
 * @param {boolean}  root0.hasAudio       Whether audio is selected.
 * @return {string[]} Validation errors.
 */
function validateAdContent( {
	platforms,
	objective,
	title,
	description,
	externalLink,
	youtubeVideoId,
	pixelId,
	hasVideo,
	hasImage,
	hasAudio,
} ) {
	const errors = [];
	const has = ( p ) => platforms.includes( p );

	if ( ! externalLink ) {
		errors.push(
			__(
				'Add a destination link — every platform needs a landing page URL for the ad.',
				'bushido-almost-famous'
			)
		);
	} else if ( ! isValidHttpUrl( externalLink ) ) {
		errors.push(
			__(
				'The destination link must be a full http(s) URL, e.g. https://example.com.',
				'bushido-almost-famous'
			)
		);
	}

	if ( has( 'META' ) ) {
		if ( ! hasVideo && ! hasImage ) {
			errors.push(
				__(
					'Meta: select an image or video creative for the ad.',
					'bushido-almost-famous'
				)
			);
		}
		if ( title.length > META_TITLE_MAX ) {
			errors.push(
				sprintf(
					/* translators: %d: maximum Meta title length. */
					__(
						'Meta: the ad title must be %d characters or fewer.',
						'bushido-almost-famous'
					),
					META_TITLE_MAX
				)
			);
		}
		if ( description.length > META_DESCRIPTION_MAX ) {
			errors.push(
				sprintf(
					/* translators: %d: maximum Meta description length. */
					__(
						'Meta: the ad description must be %d characters or fewer.',
						'bushido-almost-famous'
					),
					META_DESCRIPTION_MAX
				)
			);
		}
	}

	if ( has( 'GOOGLE' ) ) {
		// A VIDEO_VIEWS objective routes Google through the YouTube (Demand
		// Gen) path, which needs a YouTube-hosted video plus a logo/cover
		// image; other objectives synthesize a responsive display ad from a
		// landscape marketing image.
		const isVideoBranch = objective === 'VIDEO_VIEWS';
		if ( isVideoBranch && ! youtubeVideoId ) {
			errors.push(
				__(
					'Google/YouTube: Video Views campaigns need a YouTube video — paste a YouTube link or video ID (an uploaded file can’t run as a Google video ad).',
					'bushido-almost-famous'
				)
			);
		}
		if ( ! hasImage ) {
			errors.push(
				isVideoBranch
					? __(
							'Google/YouTube: select a square image creative to use as the logo/cover image.',
							'bushido-almost-famous'
					  )
					: __(
							'Google Ads: select an image creative to use as the marketing image.',
							'bushido-almost-famous'
					  )
			);
		}
		if ( ! title ) {
			errors.push(
				__(
					'Google Ads: add an ad title (used as the headline).',
					'bushido-almost-famous'
				)
			);
		}
		if ( ! description ) {
			errors.push(
				__(
					'Google Ads: add an ad description.',
					'bushido-almost-famous'
				)
			);
		}
		const titleMax = isVideoBranch
			? GOOGLE_VIDEO_TITLE_MAX
			: GOOGLE_TITLE_MAX;
		if ( title.length > titleMax ) {
			errors.push(
				sprintf(
					/* translators: %d: maximum Google title length. */
					__(
						'Google Ads: the ad title must be %d characters or fewer.',
						'bushido-almost-famous'
					),
					titleMax
				)
			);
		}
		if ( description.length > GOOGLE_DESCRIPTION_MAX ) {
			errors.push(
				sprintf(
					/* translators: %d: maximum Google description length. */
					__(
						'Google Ads: the ad description must be %d characters or fewer.',
						'bushido-almost-famous'
					),
					GOOGLE_DESCRIPTION_MAX
				)
			);
		}
	}

	if ( has( 'SPOTIFY' ) ) {
		if ( ! hasAudio ) {
			errors.push(
				__(
					'Spotify: select an audio creative (an MP3 of about 30 seconds).',
					'bushido-almost-famous'
				)
			);
		}
		if ( ! hasImage ) {
			errors.push(
				__(
					'Spotify: select an image creative to use as the companion image (square, at least 600×600).',
					'bushido-almost-famous'
				)
			);
		}
		if ( title.length > SPOTIFY_TITLE_MAX ) {
			errors.push(
				sprintf(
					/* translators: %d: maximum Spotify title length. */
					__(
						'Spotify: the ad title must be %d characters or fewer.',
						'bushido-almost-famous'
					),
					SPOTIFY_TITLE_MAX
				)
			);
		}
		if (
			( objective === 'ENGAGEMENT' || objective === 'STREAMING' ) &&
			externalLink &&
			! isSpotifyLink( externalLink )
		) {
			errors.push(
				__(
					'Spotify: engagement and streaming campaigns must link to a Spotify destination, e.g. https://open.spotify.com/track/…',
					'bushido-almost-famous'
				)
			);
		}
	}

	if ( has( 'TIKTOK' ) ) {
		if ( ! hasVideo && ! hasImage ) {
			errors.push(
				__(
					'TikTok: select a video or image creative for the ad.',
					'bushido-almost-famous'
				)
			);
		}
		if ( ! title && ! description ) {
			errors.push(
				__(
					'TikTok: add ad text (a title or a description).',
					'bushido-almost-famous'
				)
			);
		}
		const adText = description || title;
		if ( adText.length > TIKTOK_AD_TEXT_MAX ) {
			errors.push(
				sprintf(
					/* translators: %d: maximum TikTok ad-text length. */
					__(
						'TikTok: the ad text (description, or title when there is no description) must be %d characters or fewer.',
						'bushido-almost-famous'
					),
					TIKTOK_AD_TEXT_MAX
				)
			);
		}
	}

	if (
		objective === 'CONVERSIONS' &&
		( has( 'META' ) || has( 'TIKTOK' ) ) &&
		! pixelId
	) {
		errors.push(
			__(
				'Conversions campaigns on Meta or TikTok need a tracking pixel ID.',
				'bushido-almost-famous'
			)
		);
	}

	return errors;
}

/**
 * Campaign creation form — simplified single-page approach.
 * @param {Object}   root0          Component properties.
 * @param {Function} root0.navigate Portal navigation callback.
 * @return {JSX.Element} Campaign creation form.
 */
export function CreateCampaign( { navigate } ) {
	const manage = canManage();
	const [ form, setForm ] = useState( {
		name: '',
		objective: 'AWARENESS',
		creationMode: 'MANUAL',
		budgetTotal: 500,
		budgetDaily: 50,
		currency: 'USD',
		startDate: new Date( Date.now() + 86400000 )
			.toISOString()
			.split( 'T' )[ 0 ],
		endDate: new Date( Date.now() + 86400000 * 8 )
			.toISOString()
			.split( 'T' )[ 0 ],
		platforms: { META: 50, GOOGLE: 50, SPOTIFY: 0, TIKTOK: 0 },
	} );
	// A portal-built campaign is launch-ready: countries feed
	// `targeting.countries`, and the audience, ad content, and creative assets
	// are sent inside the pass-through `config` object using the exact keys the
	// backend launch synthesizer and pre-checkout gate read (`title`,
	// `description`, `externalLink`, `videoAssetId`, `thumbnailAssetId`,
	// `logoAssetId`, `audioAssetId`, `audienceIds`, `youtubeVideoId`,
	// `pixelId`).
	const [ countries, setCountries ] = useState( 'US' );
	const [ audienceId, setAudienceId ] = useState( '' );
	const [ selectedCreatives, setSelectedCreatives ] = useState( [] );
	const [ audiences, setAudiences ] = useState( [] );
	const [ creatives, setCreatives ] = useState( [] );
	const [ audiencesLoading, setAudiencesLoading ] = useState( true );
	const [ creativesLoading, setCreativesLoading ] = useState( true );
	// The destination starts at this site's configured landing page (Settings →
	// Campaign Defaults, default: the home page) rather than blank: ads that
	// land back here are the only ones the plugin's attribution cookie can tie
	// to a WooCommerce sale. Still fully editable — a Spotify engagement
	// campaign, for instance, has to point at Spotify.
	const [ ad, setAd ] = useState( {
		title: '',
		description: '',
		externalLink: portalConfig().defaultDestination || '',
		youtubeUrl: '',
		pixelId: '',
	} );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );

	// Fetch saved audiences and creatives on mount. Failures are non-blocking:
	// the user can still create a campaign without an audience or creative.
	useEffect( () => {
		let active = true;
		api.listAudiences()
			.then( ( res ) => {
				if ( active ) {
					setAudiences( unwrapCollection( res ) );
				}
			} )
			.catch( () => {
				if ( active ) {
					setAudiences( [] );
				}
			} )
			.finally( () => {
				if ( active ) {
					setAudiencesLoading( false );
				}
			} );
		api.listCreatives()
			.then( ( res ) => {
				if ( active ) {
					setCreatives( unwrapCollection( res ) );
				}
			} )
			.catch( () => {
				if ( active ) {
					setCreatives( [] );
				}
			} )
			.finally( () => {
				if ( active ) {
					setCreativesLoading( false );
				}
			} );
		return () => {
			active = false;
		};
	}, [] );

	const set = ( key, value ) =>
		setForm( ( prev ) => ( { ...prev, [ key ]: value } ) );

	const setAdField = ( key, value ) =>
		setAd( ( prev ) => ( { ...prev, [ key ]: value } ) );

	const setPlatform = ( platform, value ) => {
		setForm( ( prev ) => ( {
			...prev,
			platforms: { ...prev.platforms, [ platform ]: Number( value ) },
		} ) );
	};

	const toggleCreative = ( id ) => {
		setSelectedCreatives( ( prev ) =>
			prev.includes( id )
				? prev.filter( ( c ) => c !== id )
				: [ ...prev, id ]
		);
	};

	// Map the selected assets onto the config slots the backend actually
	// consumes: the first video → `videoAssetId`, the first image →
	// `thumbnailAssetId` (main ad image), a second image → `logoAssetId`
	// (Google logo / Spotify companion), the first audio → `audioAssetId`.
	const selectedAssets = creatives.filter( ( c ) =>
		selectedCreatives.includes( c.id )
	);
	const ofType = ( type ) =>
		selectedAssets.filter(
			( c ) => ( c.type || '' ).toLowerCase() === type
		);
	const selectedVideos = ofType( 'video' );
	const selectedImages = ofType( 'image' );
	const selectedAudios = ofType( 'audio' );

	const googleSelected = form.platforms.GOOGLE > 0;
	const metaSelected = form.platforms.META > 0;
	const tiktokSelected = form.platforms.TIKTOK > 0;
	const showYouTubeField = googleSelected && form.objective === 'VIDEO_VIEWS';
	const showPixelField =
		form.objective === 'CONVERSIONS' && ( metaSelected || tiktokSelected );

	const handleSubmit = async ( e ) => {
		e.preventDefault();
		setError( null );

		if ( ! form.name.trim() ) {
			setError(
				__( 'Campaign name is required', 'bushido-almost-famous' )
			);
			return;
		}

		const activePlatforms = Object.entries( form.platforms ).filter(
			( [ , v ] ) => v > 0
		);
		if ( activePlatforms.length === 0 ) {
			setError(
				__(
					'Select at least one platform with budget allocation',
					'bushido-almost-famous'
				)
			);
			return;
		}

		if ( new Date( form.endDate ) < new Date( form.startDate ) ) {
			setError(
				__(
					'End date must be after the start date',
					'bushido-almost-famous'
				)
			);
			return;
		}

		const totalAlloc = activePlatforms.reduce( ( s, [ , v ] ) => s + v, 0 );
		if ( Math.abs( totalAlloc - 100 ) > 1 ) {
			setError(
				sprintf(
					/* translators: %s: current total platform allocation percentage. */
					__(
						'Platform allocations must sum to 100%% (currently %1$s%%)',
						'bushido-almost-famous'
					),
					totalAlloc
				)
			);
			return;
		}

		const youtubeVideoId = showYouTubeField
			? parseYouTubeId( ad.youtubeUrl )
			: '';
		if ( showYouTubeField && ad.youtubeUrl.trim() && ! youtubeVideoId ) {
			setError(
				__(
					'The YouTube video must be a youtube.com / youtu.be link or a video ID.',
					'bushido-almost-famous'
				)
			);
			return;
		}

		const platformKeys = activePlatforms.map( ( [ p ] ) => p );
		const title = ad.title.trim();
		const description = ad.description.trim();
		const externalLink = ad.externalLink.trim();
		const pixelId = ad.pixelId.trim();

		// Mirror the server's pre-checkout creative gate so the user gets an
		// inline message now instead of a 422 at checkout.
		const adErrors = validateAdContent( {
			platforms: platformKeys,
			objective: form.objective,
			title,
			description,
			externalLink,
			youtubeVideoId,
			pixelId,
			hasVideo: selectedVideos.length > 0,
			hasImage: selectedImages.length > 0,
			hasAudio: selectedAudios.length > 0,
		} );
		if ( adErrors.length > 0 ) {
			setError( adErrors.join( ' ' ) );
			return;
		}

		// Parse comma-separated ISO country codes; default to US if empty.
		const countryList = countries
			.split( ',' )
			.map( ( c ) => c.trim().toUpperCase() )
			.filter( Boolean );

		setSubmitting( true );
		try {
			const payload = {
				name: form.name,
				objective: form.objective,
				creationMode: form.creationMode,
				// Create input uses `budget` (mapped to budgetTotal server-side);
				// responses use `budgetTotal`. This is the correct request shape.
				budget: Number( form.budgetTotal ),
				budgetDaily: Number( form.budgetDaily ),
				currency: form.currency,
				startDate: form.startDate,
				endDate: form.endDate,
				platforms: platformKeys,
				platformAllocations: Object.fromEntries( activePlatforms ),
				targeting: {
					countries: countryList.length > 0 ? countryList : [ 'US' ],
				},
			};

			// Ad content, audience, and creative selections go inside `config`,
			// not at the top level: the create schema is strict about top-level
			// keys, while `config` is a pass-through object. These are the exact
			// fields the backend launch synthesizer and the pre-checkout gate
			// read (extractSynthesisFields / extractCreativeInput).
			const config = {};
			if ( title ) {
				config.title = title;
			}
			if ( description ) {
				config.description = description;
			}
			if ( externalLink ) {
				config.externalLink = externalLink;
			}
			if ( selectedVideos.length > 0 ) {
				config.videoAssetId = selectedVideos[ 0 ].id;
			}
			if ( selectedImages.length > 0 ) {
				config.thumbnailAssetId = selectedImages[ 0 ].id;
			}
			if ( selectedImages.length > 1 ) {
				config.logoAssetId = selectedImages[ 1 ].id;
			}
			if ( selectedAudios.length > 0 ) {
				config.audioAssetId = selectedAudios[ 0 ].id;
			}
			if ( audienceId ) {
				config.audienceIds = [ audienceId ];
			}
			if ( youtubeVideoId ) {
				config.youtubeVideoId = youtubeVideoId;
			}
			if ( pixelId ) {
				config.pixelId = pixelId;
			}
			payload.config = config;

			const res = await api.createCampaign( payload );
			const createdCampaign = unwrapEntity( res );
			const campaignId = createdCampaign?.id;
			if ( campaignId ) {
				navigate( `#/campaigns/${ campaignId }` );
			} else {
				navigate( '#/' );
			}
		} catch ( err ) {
			setError(
				err.message ||
					__( 'Failed to create campaign', 'bushido-almost-famous' )
			);
		} finally {
			setSubmitting( false );
		}
	};

	// Management-only view (defense in depth — the router already redirects
	// view-only users away from #/create).
	if ( ! manage ) {
		return (
			<div className="af-alert af-alert-info" role="status">
				{ __(
					'You have view-only access. Ask an administrator for campaign-management permissions to create campaigns.',
					'bushido-almost-famous'
				) }
			</div>
		);
	}

	let creativeChoices;
	if ( creativesLoading ) {
		creativeChoices = (
			<div className="af-help-text">
				{ __( 'Loading creatives…', 'bushido-almost-famous' ) }
			</div>
		);
	} else if ( creatives.length === 0 ) {
		creativeChoices = (
			<div className="af-help-text">
				{ __( 'No creatives available.', 'bushido-almost-famous' ) }
			</div>
		);
	} else {
		creativeChoices = (
			<div
				style={ {
					marginTop: '8px',
					display: 'flex',
					flexDirection: 'column',
					gap: '4px',
				} }
			>
				{ creatives.map( ( creative ) => {
					const type =
						CREATIVE_TYPE_LABELS[
							( creative.type || '' ).toLowerCase()
						];
					return (
						<label
							key={ creative.id }
							className="af-checkbox-label"
							htmlFor={ `af-creative-${ creative.id }` }
						>
							<input
								id={ `af-creative-${ creative.id }` }
								type="checkbox"
								checked={ selectedCreatives.includes(
									creative.id
								) }
								onChange={ () => toggleCreative( creative.id ) }
							/>{ ' ' }
							{ creative.name || creative.id }
							{ type ? ` (${ type })` : '' }
						</label>
					);
				} ) }
			</div>
		);
	}

	return (
		<div>
			<div className="af-page-header">
				<div>
					<h2>
						{ __( 'Create Campaign', 'bushido-almost-famous' ) }
					</h2>
					<p>
						{ __(
							'Set up a new advertising campaign',
							'bushido-almost-famous'
						) }
					</p>
				</div>
				<button
					className="af-btn af-btn-secondary"
					onClick={ () => navigate( '#/' ) }
				>
					{ __( '← Back', 'bushido-almost-famous' ) }
				</button>
			</div>

			{ error && (
				<div className="af-alert af-alert-error">{ error }</div>
			) }

			<div className="af-card">
				<form className="af-form" onSubmit={ handleSubmit }>
					<div className="af-form-group">
						<label htmlFor="af-name">
							{ __( 'Campaign Name', 'bushido-almost-famous' ) }
						</label>
						<input
							id="af-name"
							type="text"
							value={ form.name }
							onChange={ ( e ) => set( 'name', e.target.value ) }
							placeholder={ __(
								'e.g. Summer Release Promo',
								'bushido-almost-famous'
							) }
							required
						/>
					</div>

					<div className="af-form-row">
						<div className="af-form-group">
							<label htmlFor="af-objective">
								{ __( 'Objective', 'bushido-almost-famous' ) }
							</label>
							<select
								id="af-objective"
								value={ form.objective }
								onChange={ ( e ) =>
									set( 'objective', e.target.value )
								}
							>
								{ OBJECTIVES.map( ( o ) => (
									<option key={ o.value } value={ o.value }>
										{ o.label }
									</option>
								) ) }
							</select>
						</div>
						<div className="af-form-group">
							<label htmlFor="af-mode">
								{ __(
									'Creation Mode',
									'bushido-almost-famous'
								) }
							</label>
							<select
								id="af-mode"
								value={ form.creationMode }
								onChange={ ( e ) =>
									set( 'creationMode', e.target.value )
								}
							>
								<option value="MANUAL">
									{ __( 'Manual', 'bushido-almost-famous' ) }
								</option>
								<option value="AI">
									{ __(
										'AI-Optimized',
										'bushido-almost-famous'
									) }
								</option>
							</select>
						</div>
					</div>

					<div className="af-form-row">
						<div className="af-form-group">
							<label htmlFor="af-budget-total">
								{ __(
									'Total Budget ($)',
									'bushido-almost-famous'
								) }
							</label>
							<input
								id="af-budget-total"
								type="number"
								min="1"
								step="1"
								value={ form.budgetTotal }
								onChange={ ( e ) =>
									set( 'budgetTotal', e.target.value )
								}
							/>
						</div>
						<div className="af-form-group">
							<label htmlFor="af-budget-daily">
								{ __(
									'Daily Budget ($)',
									'bushido-almost-famous'
								) }
							</label>
							<input
								id="af-budget-daily"
								type="number"
								min="1"
								step="1"
								value={ form.budgetDaily }
								onChange={ ( e ) =>
									set( 'budgetDaily', e.target.value )
								}
							/>
						</div>
					</div>

					<div className="af-form-row">
						<div className="af-form-group">
							<label htmlFor="af-start">
								{ __( 'Start Date', 'bushido-almost-famous' ) }
							</label>
							<input
								id="af-start"
								type="date"
								value={ form.startDate }
								onChange={ ( e ) =>
									set( 'startDate', e.target.value )
								}
							/>
						</div>
						<div className="af-form-group">
							<label htmlFor="af-end">
								{ __( 'End Date', 'bushido-almost-famous' ) }
							</label>
							<input
								id="af-end"
								type="date"
								value={ form.endDate }
								onChange={ ( e ) =>
									set( 'endDate', e.target.value )
								}
							/>
						</div>
					</div>

					<div className="af-form-group">
						<span className="af-form-label">
							{ __(
								'Platform Allocation (%)',
								'bushido-almost-famous'
							) }
						</span>
						<div className="af-help-text">
							{ __(
								'Distribute your budget across platforms. Must sum to 100%.',
								'bushido-almost-famous'
							) }
						</div>
						<div
							className="af-form-row"
							style={ { marginTop: '8px' } }
						>
							{ PLATFORMS.map( ( p ) => (
								<div
									key={ p }
									className="af-form-group"
									style={ { gap: '4px' } }
								>
									<label
										htmlFor={ `af-plat-${ p }` }
										style={ { fontSize: '12px' } }
									>
										{ formatPlatform( p ) }
									</label>
									<input
										id={ `af-plat-${ p }` }
										type="number"
										min="0"
										max="100"
										step="1"
										value={ form.platforms[ p ] }
										onChange={ ( e ) =>
											setPlatform( p, e.target.value )
										}
									/>
								</div>
							) ) }
						</div>
					</div>

					<div className="af-form-group">
						<label htmlFor="af-countries">
							{ __( 'Countries', 'bushido-almost-famous' ) }
						</label>
						<input
							id="af-countries"
							type="text"
							value={ countries }
							onChange={ ( e ) => setCountries( e.target.value ) }
							placeholder={ __(
								'US, GB, DE (comma-separated ISO codes)',
								'bushido-almost-famous'
							) }
						/>
						<div className="af-help-text">
							{ __(
								'Comma-separated ISO country codes. Defaults to US if left empty.',
								'bushido-almost-famous'
							) }
						</div>
					</div>

					<div className="af-form-group">
						<label htmlFor="af-audience">
							{ __( 'Audience', 'bushido-almost-famous' ) }
						</label>
						<select
							id="af-audience"
							value={ audienceId }
							onChange={ ( e ) =>
								setAudienceId( e.target.value )
							}
							disabled={ audiencesLoading }
						>
							<option value="">
								{ __(
									'No audience / Broad',
									'bushido-almost-famous'
								) }
							</option>
							{ audiences.map( ( a ) => (
								<option key={ a.id } value={ a.id }>
									{ a.name || a.id }
								</option>
							) ) }
						</select>
						<div className="af-help-text">
							{ audiencesLoading
								? __(
										'Loading audiences…',
										'bushido-almost-famous'
								  )
								: __(
										'Choose a saved audience, or leave broad.',
										'bushido-almost-famous'
								  ) }
						</div>
					</div>

					<div className="af-form-group">
						<label htmlFor="af-ad-title">
							{ __( 'Ad Title', 'bushido-almost-famous' ) }
						</label>
						<input
							id="af-ad-title"
							type="text"
							value={ ad.title }
							onChange={ ( e ) =>
								setAdField( 'title', e.target.value )
							}
							placeholder={ __(
								'e.g. New single out now',
								'bushido-almost-famous'
							) }
						/>
						<div className="af-help-text">
							{ __(
								'Used as the ad headline. Google allows up to 30 characters; Meta and Spotify up to 40.',
								'bushido-almost-famous'
							) }
						</div>
					</div>

					<div className="af-form-group">
						<label htmlFor="af-ad-description">
							{ __( 'Ad Description', 'bushido-almost-famous' ) }
						</label>
						<input
							id="af-ad-description"
							type="text"
							value={ ad.description }
							onChange={ ( e ) =>
								setAdField( 'description', e.target.value )
							}
							placeholder={ __(
								'One line about what you’re promoting',
								'bushido-almost-famous'
							) }
						/>
						<div className="af-help-text">
							{ __(
								'Required for Google Ads (up to 90 characters). Meta caps the description at 30 characters; TikTok uses it as the ad text (up to 100).',
								'bushido-almost-famous'
							) }
						</div>
					</div>

					<div className="af-form-group">
						<label htmlFor="af-ad-link">
							{ __(
								'Destination Link',
								'bushido-almost-famous'
							) }
						</label>
						<input
							id="af-ad-link"
							type="url"
							value={ ad.externalLink }
							onChange={ ( e ) =>
								setAdField( 'externalLink', e.target.value )
							}
							placeholder={ __(
								'https://example.com/landing-page',
								'bushido-almost-famous'
							) }
							required
						/>
						<div className="af-help-text">
							{ form.objective === 'ENGAGEMENT' ||
							form.objective === 'STREAMING'
								? __(
										'Where the ad clicks through to. Spotify engagement/streaming campaigns must use a Spotify link (e.g. https://open.spotify.com/track/…).',
										'bushido-almost-famous'
								  )
								: __(
										'Where the ad clicks through to. Defaults to this site — clicks that land here can be matched to your WooCommerce sales; other destinations cannot.',
										'bushido-almost-famous'
								  ) }
						</div>
					</div>

					{ showYouTubeField && (
						<div className="af-form-group">
							<label htmlFor="af-ad-youtube">
								{ __(
									'YouTube Video',
									'bushido-almost-famous'
								) }
							</label>
							<input
								id="af-ad-youtube"
								type="text"
								value={ ad.youtubeUrl }
								onChange={ ( e ) =>
									setAdField( 'youtubeUrl', e.target.value )
								}
								placeholder={ __(
									'https://www.youtube.com/watch?v=… or a video ID',
									'bushido-almost-famous'
								) }
							/>
							<div className="af-help-text">
								{ __(
									'Video Views campaigns on Google run as YouTube ads and need a YouTube-hosted video.',
									'bushido-almost-famous'
								) }
							</div>
						</div>
					) }

					{ showPixelField && (
						<div className="af-form-group">
							<label htmlFor="af-ad-pixel">
								{ __(
									'Tracking Pixel ID',
									'bushido-almost-famous'
								) }
							</label>
							<input
								id="af-ad-pixel"
								type="text"
								value={ ad.pixelId }
								onChange={ ( e ) =>
									setAdField( 'pixelId', e.target.value )
								}
								placeholder={ __(
									'e.g. 1234567890',
									'bushido-almost-famous'
								) }
							/>
							<div className="af-help-text">
								{ __(
									'Conversions campaigns on Meta and TikTok need a tracking pixel to attribute results.',
									'bushido-almost-famous'
								) }
							</div>
						</div>
					) }

					<div className="af-form-group">
						<span className="af-form-label">
							{ __( 'Creatives', 'bushido-almost-famous' ) }
						</span>
						<div className="af-help-text">
							{ __(
								'Select the creative assets for the ad. The first video and image you pick become the ad media, a second image becomes the logo/companion image, and the first audio asset powers Spotify. Google needs a landscape image (e.g. 1200×628); Spotify needs a square one (at least 600×600).',
								'bushido-almost-famous'
							) }
						</div>
						{ creativeChoices }
					</div>

					<div className="af-form-actions">
						<button
							type="submit"
							className="af-btn af-btn-primary"
							disabled={ submitting }
						>
							{ submitting
								? __( 'Creating…', 'bushido-almost-famous' )
								: __(
										'Create Campaign',
										'bushido-almost-famous'
								  ) }
						</button>
						<button
							type="button"
							className="af-btn af-btn-secondary"
							onClick={ () => navigate( '#/' ) }
						>
							{ __( 'Cancel', 'bushido-almost-famous' ) }
						</button>
					</div>
				</form>
			</div>
		</div>
	);
}
