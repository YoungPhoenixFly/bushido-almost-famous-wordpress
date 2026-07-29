/**
 * @package
 * @license GPL-2.0-or-later
 */
/**
 * Bushido Almost Famous admin JavaScript.
 *
 * Core AfPoller module for ETag conditional polling (Story 2.6).
 *
 * Uses wp.apiFetch for all REST requests. Polling respects the
 * Page Visibility API — pauses when tab is hidden, resumes when visible.
 *
 * @param {Object} wp WordPress browser APIs.
 * @package
 */

( function ( wp ) {
	'use strict';

	/**
	 * Look up a localized admin string (from afAdminData.i18n) with a fallback.
	 *
	 * @param {string} key      i18n key.
	 * @param {string} fallback Default English string.
	 * @return {string} Localized string.
	 */
	function afI18n( key, fallback ) {
		const data = window.afAdminData || {};
		const i18n = data.i18n || {};
		return i18n[ key ] || fallback;
	}

	function getResponseData( response ) {
		if (
			response &&
			typeof response === 'object' &&
			response.data !== undefined
		) {
			return response.data;
		}

		return response;
	}

	function getResponseErrorMessage( error ) {
		if ( error && error.message ) {
			return error.message;
		}

		if ( error && error.error && error.error.message ) {
			return error.error.message;
		}

		return 'Request failed.';
	}

	function replaceTextNotice( container, type, message ) {
		if ( ! container ) {
			return;
		}

		const notice = document.createElement( 'div' );
		notice.className = 'notice notice-' + type;
		const paragraph = document.createElement( 'p' );
		paragraph.textContent = String( message );
		notice.appendChild( paragraph );
		container.replaceChildren( notice );
	}

	function replaceTextState( container, className, message ) {
		const paragraph = document.createElement( 'p' );
		paragraph.className = className;
		paragraph.textContent = String( message );
		container.replaceChildren( paragraph );
	}

	/* ---------------------------------------------------------------
	 * AfPoller — setInterval-based conditional polling with ETags.
	 * --------------------------------------------------------------- */

	/**
	 * @typedef {Object} PollerConfig
	 * @property {number}   activeInterval   Interval in seconds for active campaigns (default 60).
	 * @property {number}   archivedInterval Interval in seconds for archived/idle (default 300).
	 * @property {Function} onData           Callback(panelName, data) when data changes.
	 * @property {Function} onError          Callback(panelName, error) on request failure.
	 */

	const AfPoller = ( function () {
		/**
		 * Map of registered endpoints.
		 * Key: panel name, Value: { path, etag, interval, timerId, lastData }.
		 *
		 * @type {Object.<string, Object>}
		 */
		const endpoints = {};

		/** Whether the tab is currently visible. */
		let isVisible = true;

		/** Default intervals in milliseconds. */
		let ACTIVE_INTERVAL = 60000;
		let ARCHIVED_INTERVAL = 300000;

		/** Global callbacks. */
		let onDataCallback = null;
		let onErrorCallback = null;

		/**
		 * Initialize the poller.
		 *
		 * @param {PollerConfig} config Configuration object.
		 */
		function init( config ) {
			config = config || {};

			if ( config.activeInterval ) {
				ACTIVE_INTERVAL = config.activeInterval * 1000;
			}
			if ( config.archivedInterval ) {
				ARCHIVED_INTERVAL = config.archivedInterval * 1000;
			}
			if ( typeof config.onData === 'function' ) {
				onDataCallback = config.onData;
			}
			if ( typeof config.onError === 'function' ) {
				onErrorCallback = config.onError;
			}

			/* Page Visibility API — pause/resume polling. */
			if ( typeof document.hidden !== 'undefined' ) {
				document.addEventListener(
					'visibilitychange',
					handleVisibilityChange
				);
			}
		}

		/**
		 * Register an endpoint for polling.
		 *
		 * @param {string} panelName Unique panel identifier.
		 * @param {string} path      REST API path (e.g., '/almost-famous/v1/campaigns').
		 * @param {string} type      'active' or 'archived' — determines poll interval.
		 */
		function register( panelName, path, type ) {
			const interval =
				type === 'archived' ? ARCHIVED_INTERVAL : ACTIVE_INTERVAL;

			endpoints[ panelName ] = {
				path,
				etag: '',
				interval,
				timerId: null,
				lastData: null,
				type: type || 'active',
			};
		}

		/**
		 * Start polling all registered endpoints.
		 */
		function startAll() {
			Object.keys( endpoints ).forEach( function ( name ) {
				start( name );
			} );
		}

		/**
		 * Start polling a single endpoint.
		 *
		 * @param {string} panelName Panel identifier.
		 */
		function start( panelName ) {
			const ep = endpoints[ panelName ];
			if ( ! ep ) {
				return;
			}

			/* Do an immediate fetch. */
			fetchEndpoint( panelName );

			/* Set up interval. */
			if ( ep.timerId ) {
				clearInterval( ep.timerId );
			}

			ep.timerId = setInterval( function () {
				if ( isVisible ) {
					fetchEndpoint( panelName );
				}
			}, ep.interval );
		}

		/**
		 * Stop polling a single endpoint.
		 *
		 * @param {string} panelName Panel identifier.
		 */
		function stop( panelName ) {
			const ep = endpoints[ panelName ];
			if ( ep && ep.timerId ) {
				clearInterval( ep.timerId );
				ep.timerId = null;
			}
		}

		/**
		 * Stop all polling.
		 */
		function stopAll() {
			Object.keys( endpoints ).forEach( function ( name ) {
				stop( name );
			} );
		}

		/**
		 * Fetch an endpoint using wp.apiFetch with ETag headers.
		 *
		 * @param {string} panelName Panel identifier.
		 */
		function fetchEndpoint( panelName ) {
			const ep = endpoints[ panelName ];
			if ( ! ep ) {
				return;
			}

			const headers = {};
			if ( ep.etag ) {
				headers[ 'If-None-Match' ] = ep.etag;
			}

			wp.apiFetch( {
				path: ep.path,
				method: 'GET',
				headers,
				parse: false,
			} )
				.then( function ( response ) {
					/* 304 Not Modified — data unchanged, no re-render needed. */
					if ( response.status === 304 ) {
						return;
					}

					/* Non-2xx — the body is an error envelope, not data. Route it to
				   the error handler instead of rendering it as a panel. */
					if ( ! response.ok ) {
						if ( onErrorCallback ) {
							onErrorCallback(
								panelName,
								new Error( 'HTTP ' + response.status )
							);
						}
						return;
					}

					/* Store new ETag if present. */
					const newEtag =
						response.headers.get( 'ETag' ) ||
						response.headers.get( 'etag' );
					if ( newEtag ) {
						ep.etag = newEtag;
					}

					return response.json().then( function ( data ) {
						const hasChanged =
							JSON.stringify( data ) !==
							JSON.stringify( ep.lastData );

						ep.lastData = data;

						/* Check for degraded flag and toggle banner. */
						checkDegradedFlag( data );

						/* Only re-render if data actually changed. */
						if ( hasChanged && onDataCallback ) {
							onDataCallback( panelName, data );
						}
					} );
				} )
				.catch( function ( error ) {
					if ( onErrorCallback ) {
						onErrorCallback( panelName, error );
					}
				} );
		}

		/**
		 * Handle visibility change — pause when hidden, resume when visible.
		 */
		function handleVisibilityChange() {
			if ( document.hidden ) {
				isVisible = false;
			} else {
				isVisible = true;
				/* Immediately poll all endpoints on tab return. */
				Object.keys( endpoints ).forEach( function ( name ) {
					fetchEndpoint( name );
				} );
			}
		}

		/**
		 * Check if response data contains _degraded flag and toggle banner.
		 *
		 * @param {Object} data API response data.
		 */
		function checkDegradedFlag( data ) {
			const banner = document.getElementById( 'af-degraded-banner' );
			if ( ! banner ) {
				return;
			}

			/* Toggle both ways — the banner must clear once the backend
			   recovers, not linger for the life of the page. */
			banner.style.display = data && data._degraded ? '' : 'none';
		}

		/**
		 * Get the last fetched data for a panel.
		 *
		 * @param {string} panelName Panel identifier.
		 * @return {*} Last fetched data or null.
		 */
		function getLastData( panelName ) {
			const ep = endpoints[ panelName ];
			return ep ? ep.lastData : null;
		}

		/**
		 * Update the interval type for a panel (e.g., when campaign becomes archived).
		 *
		 * @param {string} panelName Panel identifier.
		 * @param {string} type      'active' or 'archived'.
		 */
		function setIntervalType( panelName, type ) {
			const ep = endpoints[ panelName ];
			if ( ! ep ) {
				return;
			}

			const newInterval =
				type === 'archived' ? ARCHIVED_INTERVAL : ACTIVE_INTERVAL;

			if ( ep.interval !== newInterval ) {
				ep.interval = newInterval;
				ep.type = type;

				/* Restart with new interval if currently polling. */
				if ( ep.timerId ) {
					stop( panelName );
					start( panelName );
				}
			}
		}

		/* Public API */
		return {
			init,
			register,
			start,
			startAll,
			stop,
			stopAll,
			getLastData,
			setIntervalType,
		};
	} )();

	/* ---------------------------------------------------------------
	 * AfCampaignForm — Multi-step campaign creation/edit (Story 3.1).
	 * --------------------------------------------------------------- */

	const AfCampaignForm = ( function () {
		let form = null;
		let steps = null;
		let tabs = null;
		let submitting = false;

		function init() {
			form = document.getElementById( 'af-campaign-form' );
			if ( ! form ) {
				return;
			}

			steps = form.querySelectorAll( '.af-step-panel' );
			tabs = document.querySelectorAll( '.af-step-tab' );

			bindNavigation();
			bindSubmit();
			bindSaveDraft();
			loadAudienceOptions();
			loadCreativeOptions();
			AfBudgetAllocation.init();
		}

		function bindNavigation() {
			tabs.forEach( function ( tab ) {
				tab.addEventListener( 'click', function () {
					goToStep( parseInt( tab.dataset.step, 10 ) );
				} );
			} );

			form.querySelectorAll( '.af-step-next' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					goToStep( parseInt( btn.dataset.next, 10 ) );
				} );
			} );

			form.querySelectorAll( '.af-step-prev' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					goToStep( parseInt( btn.dataset.prev, 10 ) );
				} );
			} );
		}

		function goToStep( stepNum ) {
			if ( stepNum === 5 ) {
				updateReview();
			}

			steps.forEach( function ( panel ) {
				const active = parseInt( panel.dataset.step, 10 ) === stepNum;
				panel.classList.toggle( 'af-step-panel--active', active );
			} );

			tabs.forEach( function ( tab ) {
				const active = parseInt( tab.dataset.step, 10 ) === stepNum;
				tab.classList.toggle( 'af-step-tab--active', active );
			} );
		}

		function updateReview() {
			const setText = function ( id, text ) {
				const el = document.getElementById( id );
				if ( el ) {
					el.textContent = text || '\u2014';
				}
			};

			const nameEl = form.querySelector( '[name="name"]' );
			setText( 'af-review-name', nameEl ? nameEl.value : '' );

			const checked = form.querySelectorAll(
				'[name="platforms[]"]:checked'
			);
			const platNames = Array.from( checked ).map( function ( cb ) {
				return cb.value.charAt( 0 ).toUpperCase() + cb.value.slice( 1 );
			} );
			setText( 'af-review-platforms', platNames.join( ', ' ) );

			const startEl = form.querySelector( '[name="start_date"]' );
			const endEl = form.querySelector( '[name="end_date"]' );
			const startVal = startEl ? startEl.value : '';
			const endVal = endEl ? endEl.value : '';
			setText(
				'af-review-dates',
				startVal && endVal
					? startVal + ' \u2013 ' + endVal
					: startVal || endVal
			);

			const countriesEl = form.querySelector(
				'[name="targeting[countries]"]'
			);
			const interestsEl = form.querySelector(
				'[name="targeting[interests]"]'
			);
			const parts = [];
			if ( countriesEl && countriesEl.value ) {
				parts.push( 'Countries: ' + countriesEl.value );
			}
			if ( interestsEl && interestsEl.value ) {
				parts.push( 'Interests: ' + interestsEl.value );
			}
			setText( 'af-review-targeting', parts.join( '; ' ) );

			const totalEl = form.querySelector( '[name="total_budget"]' );
			const dailyEl = form.querySelector( '[name="daily_budget"]' );
			setText(
				'af-review-total-budget',
				totalEl && totalEl.value
					? '$' + parseFloat( totalEl.value ).toFixed( 2 )
					: ''
			);
			setText(
				'af-review-daily-budget',
				dailyEl && dailyEl.value
					? '$' + parseFloat( dailyEl.value ).toFixed( 2 )
					: ''
			);

			const modeEl = form.querySelector(
				'[name="budget_allocation[mode]"]:checked'
			);
			setText(
				'af-review-allocation',
				modeEl
					? modeEl.value.charAt( 0 ).toUpperCase() +
							modeEl.value.slice( 1 ) +
							' mode'
					: ''
			);

			const creativeIdsEl = document.getElementById( 'af-creative-ids' );
			setText(
				'af-review-creatives',
				creativeIdsEl && creativeIdsEl.value
					? creativeIdsEl.value.split( ',' ).length + ' selected'
					: 'None'
			);
		}

		function bindSubmit() {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				submitForm();
			} );
		}

		function bindSaveDraft() {
			const draftBtn = document.getElementById(
				'af-campaign-save-draft'
			);
			if ( ! draftBtn ) {
				return;
			}
			draftBtn.addEventListener( 'click', function () {
				submitForm( 'draft' );
			} );
		}

		function submitForm( status ) {
			/* Guard against double-submit — ignore clicks while a request is in flight. */
			if ( submitting ) {
				return;
			}

			const formData = new FormData( form );
			const data = formDataToObject( formData );

			if ( status ) {
				data.status = status;
			}

			const endpoint = form.dataset.apiEndpoint;
			const method = form.dataset.apiMethod;
			const notices = document.getElementById( 'af-form-notices' );
			notices.replaceChildren();

			setSubmitting( true );

			wp.apiFetch( {
				path: '/almost-famous/v1' + endpoint,
				method,
				data,
			} )
				.then( function ( response ) {
					const payload = getResponseData( response ) || {};
					replaceTextNotice(
						notices,
						'success',
						afI18n(
							'campaignSaved',
							'Campaign saved successfully.'
						)
					);
					if ( payload.id ) {
						/* Navigating away — leave the buttons disabled. */
						window.location.href =
							'admin.php?page=af-campaigns&action=edit&campaign_id=' +
							encodeURIComponent( payload.id );
						return;
					}
					setSubmitting( false );
				} )
				.catch( function ( err ) {
					replaceTextNotice(
						notices,
						'error',
						getResponseErrorMessage( err )
					);
					setSubmitting( false );
				} );
		}

		/**
		 * Toggle the submit/draft buttons into a busy state to prevent
		 * double-submission while a save request is in flight.
		 *
		 * @param {boolean} busy Whether a request is currently in flight.
		 */
		function setSubmitting( busy ) {
			submitting = busy;

			const buttons = form.querySelectorAll(
				'#af-campaign-submit, #af-campaign-save-draft'
			);
			buttons.forEach( function ( btn ) {
				btn.disabled = busy;
				btn.classList.toggle( 'af-busy', busy );
			} );

			const submitBtn = document.getElementById( 'af-campaign-submit' );
			if ( ! submitBtn ) {
				return;
			}

			if ( busy ) {
				submitBtn.dataset.afLabel = submitBtn.textContent;
				submitBtn.textContent = afI18n( 'saving', 'Saving…' );
			} else if ( submitBtn.dataset.afLabel ) {
				submitBtn.textContent = submitBtn.dataset.afLabel;
				delete submitBtn.dataset.afLabel;
			}
		}

		function loadAudienceOptions() {
			const select = document.getElementById( 'af-target-audience' );
			if ( ! select ) {
				return;
			}

			const currentValue = select.value || '';

			wp.apiFetch( {
				path: '/almost-famous/v1/audiences',
				method: 'GET',
			} )
				.then( function ( response ) {
					const audiences = getResponseData( response ) || [];
					if ( ! Array.isArray( audiences ) ) {
						return;
					}

					audiences.forEach( function ( audience ) {
						if ( ! audience || ! audience.id ) {
							return;
						}

						const option = document.createElement( 'option' );
						option.value = audience.id;
						option.textContent = audience.name || audience.id;
						if ( currentValue && currentValue === audience.id ) {
							option.selected = true;
						}
						select.appendChild( option );
					} );
				} )
				.catch( function () {
					/* Leave the select empty on failure. */
				} );
		}

		function loadCreativeOptions() {
			const container = document.getElementById(
				'af-campaign-creatives'
			);
			const hiddenInput = document.getElementById( 'af-creative-ids' );

			if ( ! container || ! hiddenInput ) {
				return;
			}

			let selected = hiddenInput.value
				? hiddenInput.value
						.split( ',' )
						.map( function ( id ) {
							return id.trim();
						} )
						.filter( Boolean )
				: [];

			wp.apiFetch( {
				path: '/almost-famous/v1/creatives',
				method: 'GET',
			} )
				.then( function ( response ) {
					const creatives = getResponseData( response ) || [];

					if ( ! Array.isArray( creatives ) || ! creatives.length ) {
						replaceTextState(
							container,
							'af-empty-state',
							afI18n(
								'noCreatives',
								'No approved creative assets are available yet.'
							)
						);
						return;
					}

					container.replaceChildren();

					creatives.forEach( function ( creative ) {
						if ( ! creative || ! creative.id ) {
							return;
						}

						const card = document.createElement( 'label' );
						card.className = 'af-creative-card';

						const checkbox = document.createElement( 'input' );
						checkbox.type = 'checkbox';
						checkbox.value = creative.id;
						checkbox.checked =
							selected.indexOf( creative.id ) !== -1;

						const title = document.createElement( 'strong' );
						title.textContent = creative.name || '(Untitled)';

						const meta = document.createElement( 'span' );
						meta.className = 'description';
						meta.textContent =
							String( creative.approved_count || 0 ) +
							' / ' +
							String( creative.total_formats || 0 ) +
							' approved';

						card.appendChild( checkbox );
						card.appendChild( title );
						card.appendChild( meta );
						container.appendChild( card );

						checkbox.addEventListener( 'change', function () {
							selected = Array.from(
								container.querySelectorAll(
									'input[type="checkbox"]:checked'
								)
							).map( function ( input ) {
								return input.value;
							} );

							hiddenInput.value = selected.join( ',' );
							updateReview();
						} );
					} );
				} )
				.catch( function () {
					replaceTextState(
						container,
						'af-empty-state',
						afI18n(
							'creativeLoadFail',
							'Unable to load creative assets right now.'
						)
					);
				} );
		}

		function formDataToObject( formData ) {
			const obj = {};
			formData.forEach( function ( value, key ) {
				if ( key.indexOf( '[' ) !== -1 ) {
					setNestedValue( obj, key, value );
				} else if ( obj[ key ] ) {
					if ( ! Array.isArray( obj[ key ] ) ) {
						obj[ key ] = [ obj[ key ] ];
					}
					obj[ key ].push( value );
				} else {
					obj[ key ] = value;
				}
			} );
			return obj;
		}

		function setNestedValue( obj, key, value ) {
			const parts = key.replace( /\]/g, '' ).split( '[' );
			let current = obj;
			for ( let i = 0; i < parts.length - 1; i++ ) {
				const part = parts[ i ];
				if ( ! current[ part ] ) {
					current[ part ] =
						parts[ i + 1 ] && ! isNaN( parts[ i + 1 ] ) ? [] : {};
				}
				current = current[ part ];
			}
			const lastPart = parts[ parts.length - 1 ];
			if ( lastPart === '' ) {
				if ( Array.isArray( current ) ) {
					current.push( value );
				}
			} else {
				current[ lastPart ] = value;
			}
		}

		return {
			init,
			formDataToObject,
		};
	} )();

	/* ---------------------------------------------------------------
	 * AfBudgetAllocation — Budget allocation management (Story 3.2).
	 * --------------------------------------------------------------- */

	const AfBudgetAllocation = ( function () {
		let table = null;
		let mode = 'percentage';

		function init() {
			table = document.getElementById( 'af-allocation-table' );
			if ( ! table ) {
				return;
			}

			bindModeToggle();
			bindSliders();
			bindInputs();
			bindBudgetWarning();
			updateMode();
		}

		function bindModeToggle() {
			const radios = document.querySelectorAll(
				'[name="budget_allocation[mode]"]'
			);
			radios.forEach( function ( radio ) {
				radio.addEventListener( 'change', function () {
					mode = radio.value;
					updateMode();
				} );
			} );

			const checked = document.querySelector(
				'[name="budget_allocation[mode]"]:checked'
			);
			if ( checked ) {
				mode = checked.value;
			}
		}

		function updateMode() {
			const pctCols = table.querySelectorAll( '.af-alloc-pct-col' );
			const fixedCols = table.querySelectorAll( '.af-alloc-fixed-col' );

			pctCols.forEach( function ( col ) {
				col.style.display = mode === 'percentage' ? '' : 'none';
			} );
			fixedCols.forEach( function ( col ) {
				col.style.display = mode === 'fixed' ? '' : 'none';
			} );

			recalculate();
		}

		function bindSliders() {
			table
				.querySelectorAll( '.af-allocation-slider' )
				.forEach( function ( slider ) {
					slider.addEventListener( 'input', function () {
						const row = slider.closest( '.af-allocation-row' );
						const pctInput = row.querySelector(
							'.af-allocation-pct-input'
						);
						if ( pctInput ) {
							pctInput.value = slider.value;
						}
						recalculate();
					} );
				} );
		}

		function bindInputs() {
			table
				.querySelectorAll( '.af-allocation-pct-input' )
				.forEach( function ( input ) {
					input.addEventListener( 'input', function () {
						const row = input.closest( '.af-allocation-row' );
						const slider = row.querySelector(
							'.af-allocation-slider'
						);
						if ( slider ) {
							slider.value = input.value;
						}
						recalculate();
					} );
				} );

			table
				.querySelectorAll( '.af-allocation-fixed-input' )
				.forEach( function ( input ) {
					input.addEventListener( 'input', function () {
						recalculate();
					} );
				} );
		}

		function bindBudgetWarning() {
			const dailyInput = document.getElementById( 'af-daily-budget' );
			if ( ! dailyInput ) {
				return;
			}

			const limit = parseFloat( dailyInput.dataset.budgetLimit ) || 0;
			const warning = document.getElementById( 'af-budget-warning' );

			if ( limit <= 0 || ! warning ) {
				return;
			}

			dailyInput.addEventListener( 'input', function () {
				const value = parseFloat( dailyInput.value ) || 0;
				warning.style.display = value > limit ? '' : 'none';
			} );
		}

		function recalculate() {
			const rows = table.querySelectorAll( '.af-allocation-row' );
			const dailyInput = document.getElementById( 'af-daily-budget' );
			const totalBudget = dailyInput
				? parseFloat( dailyInput.value ) || 0
				: 0;

			if ( mode === 'percentage' ) {
				let pctTotal = 0;
				rows.forEach( function ( row ) {
					const pctInput = row.querySelector(
						'.af-allocation-pct-input'
					);
					const pct = pctInput
						? parseFloat( pctInput.value ) || 0
						: 0;
					pctTotal += pct;

					const preview = row.querySelector(
						'.af-alloc-preview-amount'
					);
					if ( preview ) {
						preview.textContent = (
							( totalBudget * pct ) /
							100
						).toFixed( 2 );
					}
				} );

				const totalEl = document.getElementById( 'af-alloc-pct-total' );
				const errorEl = document.getElementById( 'af-alloc-pct-error' );

				if ( totalEl ) {
					totalEl.textContent = pctTotal.toFixed( 0 );
				}
				if ( errorEl ) {
					errorEl.style.display =
						Math.abs( pctTotal - 100 ) > 0.01 ? '' : 'none';
				}
			} else {
				let fixedTotal = 0;
				rows.forEach( function ( row ) {
					const fixedInput = row.querySelector(
						'.af-allocation-fixed-input'
					);
					const amount = fixedInput
						? parseFloat( fixedInput.value ) || 0
						: 0;
					fixedTotal += amount;

					const preview = row.querySelector(
						'.af-alloc-preview-amount'
					);
					if ( preview ) {
						preview.textContent = amount.toFixed( 2 );
					}
				} );

				const totalEl2 = document.getElementById(
					'af-alloc-fixed-total'
				);
				const errorEl2 = document.getElementById(
					'af-alloc-fixed-error'
				);

				if ( totalEl2 ) {
					totalEl2.textContent = fixedTotal.toFixed( 2 );
				}
				if ( errorEl2 ) {
					errorEl2.style.display =
						fixedTotal > totalBudget ? '' : 'none';
				}
			}
		}

		return {
			init,
		};
	} )();

	/* ---------------------------------------------------------------
	 * AfAudienceBuilder — Per-platform audience form + lookalike wiring.
	 * --------------------------------------------------------------- */

	const AfAudienceBuilder = ( function () {
		let form = null;
		let submitting = false;
		let lookalikeSubmitting = false;

		function init() {
			bindDelete();
			bindLookalike();

			form = document.getElementById( 'af-audience-form' );
			if ( ! form ) {
				return;
			}

			bindPlatformConfig();
			bindSubmit();
		}

		/**
		 * Look up the credential id resolved server-side for a platform.
		 *
		 * @param {string} platform Backend platform slug (meta, google, …).
		 * @return {string|null|undefined} Own id, null for agency, or undefined.
		 */
		function credentialFor( platform ) {
			const data = window.afAudienceData || {};
			const credentials = data.credentials || {};
			return Object.prototype.hasOwnProperty.call( credentials, platform )
				? credentials[ platform ]
				: undefined;
		}

		function hasCredentialFor( platform ) {
			return credentialFor( platform ) !== undefined;
		}

		function bindDelete() {
			document
				.querySelectorAll( '.af-audience-delete' )
				.forEach( function ( btn ) {
					btn.addEventListener( 'click', function () {
						const audienceId = btn.dataset.audienceId;
						if ( ! audienceId ) {
							return;
						}

						// Native confirmation is intentional for this destructive action.
						// eslint-disable-next-line no-alert
						const confirmed = window.confirm(
							afI18n( 'deleteAudience', 'Delete this audience?' )
						);
						if ( ! confirmed ) {
							return;
						}

						btn.disabled = true;

						wp.apiFetch( {
							path: '/almost-famous/v1/audiences/' + audienceId,
							method: 'DELETE',
						} )
							.then( function () {
								const row = btn.closest( 'tr' );
								if ( row ) {
									row.remove();
								}
							} )
							.catch( function () {
								btn.disabled = false;
								// Keep the failure visible even when the row has no notice container.
								// eslint-disable-next-line no-alert
								window.alert(
									afI18n(
										'deleteAudienceErr',
										'Could not delete the audience. Please try again.'
									)
								);
							} );
					} );
				} );
		}

		/**
		 * Show the Google-only config rows (description, membership
		 * lifespan) only while Google is the selected platform — no other
		 * platform create path consumes config fields.
		 */
		function bindPlatformConfig() {
			const select = document.getElementById( 'af-audience-platform' );
			if ( ! select ) {
				return;
			}

			const toggle = function () {
				const isGoogle = select.value === 'google';
				form.querySelectorAll( '.af-audience-google-only' ).forEach(
					function ( row ) {
						row.hidden = ! isGoogle;
					}
				);
			};

			select.addEventListener( 'change', toggle );
			toggle();
		}

		/**
		 * Build the audience create payload matching the backend contract:
		 * {name, type, platform, credentialId, config?}.
		 *
		 * @return {Object} Create payload.
		 */
		function buildCreatePayload() {
			const nameInput = form.querySelector( '[name="name"]' );
			const platformSelect = document.getElementById(
				'af-audience-platform'
			);
			const typeSelect = document.getElementById( 'af-audience-type' );
			const platform = platformSelect ? platformSelect.value : '';

			const payload = {
				name: nameInput ? nameInput.value : '',
				type: typeSelect ? typeSelect.value : '',
				platform,
			};
			const credentialId = credentialFor( platform );
			if ( typeof credentialId === 'string' && credentialId ) {
				payload.credentialId = credentialId;
			}

			/* Only Google Ads reads config on create (user-list description
			   + membershipLifeSpan). Omit config entirely otherwise. */
			if ( platform === 'google' ) {
				const config = {};
				const description = form.querySelector(
					'[name="config_description"]'
				);
				const lifespan = form.querySelector(
					'[name="config_membership_life_span"]'
				);

				if ( description && description.value ) {
					config.description = description.value;
				}
				if ( lifespan && lifespan.value ) {
					config.membershipLifeSpan = parseInt( lifespan.value, 10 );
				}

				if ( Object.keys( config ).length ) {
					payload.config = config;
				}
			}

			return payload;
		}

		function bindSubmit() {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();

				/* Guard against double-submit — ignore repeat submits in flight. */
				if ( submitting ) {
					return;
				}

				const notices = document.getElementById(
					'af-audience-notices'
				);
				notices.replaceChildren();

				const data = buildCreatePayload();
				const endpoint = form.dataset.apiEndpoint;
				const method = form.dataset.apiMethod;

				if ( ! hasCredentialFor( data.platform ) ) {
					replaceTextNotice(
						notices,
						'error',
						afI18n(
							'noPlatformCredential',
							'No connected credential for the selected platform. Connect one on the Accounts page first.'
						)
					);
					return;
				}

				setSubmitting( true );

				wp.apiFetch( {
					path: '/almost-famous/v1' + endpoint,
					method,
					data,
				} )
					.then( function () {
						replaceTextNotice(
							notices,
							'success',
							afI18n(
								'audienceSaved',
								'Audience saved successfully.'
							)
						);
						/* Audiences are immutable — return to the saved list.
					   Navigating away, so leave the button disabled. */
						window.location.href = 'admin.php?page=af-audiences';
					} )
					.catch( function ( err ) {
						replaceTextNotice(
							notices,
							'error',
							getResponseErrorMessage( err )
						);
						setSubmitting( false );
					} );
			} );
		}

		/**
		 * Wire the per-row "Create Lookalike" actions to the real
		 * POST /audiences/{id}/lookalike proxy route.
		 */
		function bindLookalike() {
			const panel = document.getElementById( 'af-lookalike-panel' );
			const lookalikeForm =
				document.getElementById( 'af-lookalike-form' );

			document
				.querySelectorAll( '.af-audience-lookalike' )
				.forEach( function ( btn ) {
					btn.addEventListener( 'click', function () {
						if ( ! panel || ! lookalikeForm ) {
							return;
						}

						const sourceId = btn.dataset.audienceId;
						if ( ! sourceId ) {
							return;
						}

						const sourceName = btn.dataset.audienceName || '';
						const sourceIdInput = document.getElementById(
							'af-lookalike-source-id'
						);
						const sourceNameEl = document.getElementById(
							'af-lookalike-source-name'
						);
						const nameInput =
							document.getElementById( 'af-lookalike-name' );

						if ( sourceIdInput ) {
							sourceIdInput.value = sourceId;
						}
						if ( sourceNameEl ) {
							sourceNameEl.textContent = sourceName;
						}
						if ( nameInput ) {
							nameInput.value = afI18n(
								'lookalikeNameFmt',
								'Lookalike — %s'
							).replace( '%s', sourceName );
						}

						panel.hidden = false;
						panel.scrollIntoView( {
							behavior: 'smooth',
							block: 'start',
						} );
					} );
				} );

			if ( ! panel || ! lookalikeForm ) {
				return;
			}

			bindLookalikeRatio();

			const cancelBtn = document.getElementById( 'af-lookalike-cancel' );
			if ( cancelBtn ) {
				cancelBtn.addEventListener( 'click', function () {
					panel.hidden = true;
				} );
			}

			lookalikeForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();

				/* Guard against double-submit — ignore repeat submits in flight. */
				if ( lookalikeSubmitting ) {
					return;
				}

				const notices = document.getElementById(
					'af-lookalike-notices'
				);
				notices.replaceChildren();

				const sourceIdInput = document.getElementById(
					'af-lookalike-source-id'
				);
				const sourceId = sourceIdInput ? sourceIdInput.value : '';
				if ( ! sourceId ) {
					return;
				}

				/* Contract body: {config: {name?, ratio?, country?}} — the
				   backend maps country → locationIds and normalizes ratio. */
				const config = {};
				const nameInput =
					document.getElementById( 'af-lookalike-name' );
				const ratioInput =
					document.getElementById( 'af-lookalike-ratio' );
				const countryInput = document.getElementById(
					'af-lookalike-country'
				);

				if ( nameInput && nameInput.value ) {
					config.name = nameInput.value;
				}
				if ( ratioInput && ratioInput.value ) {
					config.ratio = parseInt( ratioInput.value, 10 );
				}
				if ( countryInput && countryInput.value ) {
					config.country = countryInput.value.trim().toUpperCase();
				}

				setLookalikeSubmitting( true );

				wp.apiFetch( {
					path:
						'/almost-famous/v1/audiences/' +
						sourceId +
						'/lookalike',
					method: 'POST',
					data: { config },
				} )
					.then( function () {
						replaceTextNotice(
							notices,
							'success',
							afI18n(
								'lookalikeCreated',
								'Lookalike audience created.'
							)
						);
						/* Navigating away — leave the button disabled. */
						window.location.href = 'admin.php?page=af-audiences';
					} )
					.catch( function ( err ) {
						replaceTextNotice(
							notices,
							'error',
							getResponseErrorMessage( err )
						);
						setLookalikeSubmitting( false );
					} );
			} );
		}

		function bindLookalikeRatio() {
			const slider = document.getElementById( 'af-lookalike-ratio' );
			const display = document.getElementById(
				'af-lookalike-ratio-value'
			);
			if ( ! slider || ! display ) {
				return;
			}

			slider.addEventListener( 'input', function () {
				display.textContent = slider.value + '%';
			} );
		}

		/**
		 * Toggle the lookalike submit button into a busy state to prevent
		 * double-submission while a create request is in flight.
		 *
		 * @param {boolean} busy Whether a request is currently in flight.
		 */
		function setLookalikeSubmitting( busy ) {
			lookalikeSubmitting = busy;

			const submitBtn = document.getElementById( 'af-lookalike-submit' );
			if ( ! submitBtn ) {
				return;
			}

			submitBtn.disabled = busy;
			submitBtn.classList.toggle( 'af-busy', busy );

			if ( busy ) {
				submitBtn.dataset.afLabel = submitBtn.textContent;
				submitBtn.textContent = afI18n(
					'creatingLookalike',
					'Creating…'
				);
			} else if ( submitBtn.dataset.afLabel ) {
				submitBtn.textContent = submitBtn.dataset.afLabel;
				delete submitBtn.dataset.afLabel;
			}
		}

		/**
		 * Toggle the audience submit button into a busy state to prevent
		 * double-submission while a save request is in flight.
		 *
		 * @param {boolean} busy Whether a request is currently in flight.
		 */
		function setSubmitting( busy ) {
			submitting = busy;

			const submitBtn = document.getElementById( 'af-audience-submit' );
			if ( ! submitBtn ) {
				return;
			}

			submitBtn.disabled = busy;
			submitBtn.classList.toggle( 'af-busy', busy );

			if ( busy ) {
				submitBtn.dataset.afLabel = submitBtn.textContent;
				submitBtn.textContent = afI18n( 'saving', 'Saving…' );
			} else if ( submitBtn.dataset.afLabel ) {
				submitBtn.textContent = submitBtn.dataset.afLabel;
				delete submitBtn.dataset.afLabel;
			}
		}

		return {
			init,
		};
	} )();

	/* ---------------------------------------------------------------
	 * AfCreativeManager — Media library integration and async polling
	 * (Stories 4.3, 4.4).
	 * --------------------------------------------------------------- */

	const AfCreativeManager = ( function () {
		function init() {
			bindMediaButtons();
			bindAsyncPolling();
		}

		function bindMediaButtons() {
			bindMediaButton(
				'af-select-media',
				'af-source-attachment-id',
				'af-selected-media-name'
			);
		}

		function bindMediaButton( buttonId, inputId, nameId ) {
			const button = document.getElementById( buttonId );
			if ( ! button || ! wp.media ) {
				return;
			}

			button.addEventListener( 'click', function () {
				const frame = wp.media( {
					title: 'Select Source Asset',
					multiple: false,
					library: { type: [ 'image', 'video' ] },
				} );

				frame.on( 'select', function () {
					const attachment = frame
						.state()
						.get( 'selection' )
						.first()
						.toJSON();
					const input = document.getElementById( inputId );
					const nameDisplay = document.getElementById( nameId );

					if ( input ) {
						input.value = attachment.id;
					}
					if ( nameDisplay ) {
						nameDisplay.textContent =
							attachment.filename || attachment.title;
					}
				} );

				frame.open();
			} );
		}

		/**
		 * Async polling for creative generation status (Story 4.3).
		 */
		function bindAsyncPolling() {
			bindDetailViewPolling();
			bindListGridPolling();
		}

		function bindDetailViewPolling() {
			const statusEl = document.getElementById( 'af-processing-status' );
			if ( ! statusEl ) {
				return;
			}

			const creativeId = statusEl.dataset.creativeId;
			const interval =
				parseInt( statusEl.dataset.pollInterval, 10 ) || 5000;

			if ( ! creativeId ) {
				return;
			}

			/* Cap polling so a stuck job can never loop forever — stop after a
			   bounded number of attempts and tell the user to refresh later. */
			let attempts = 0;
			const maxAttempts = 60;

			const poll = function () {
				attempts += 1;
				if ( attempts > maxAttempts ) {
					renderStillProcessingNotice( statusEl );
					return;
				}

				wp.apiFetch( {
					path: '/almost-famous/v1/creatives/' + creativeId,
					method: 'GET',
				} )
					.then( function ( response ) {
						const payload = getResponseData( response ) || {};
						const status = payload.status || 'processing';
						if ( status === 'complete' ) {
							window.location.reload();
							return;
						}

						if ( status === 'failed' ) {
							renderFailureNotice( statusEl );
							return;
						}

						const progress = document.getElementById(
							'af-processing-progress'
						);
						if ( progress && payload.progress ) {
							progress.textContent = afI18n(
								'progressFmt',
								'Progress: %s%'
							).replace( '%s', payload.progress );
						}

						setTimeout( poll, interval );
					} )
					.catch( function () {
						setTimeout( poll, interval * 2 );
					} );
			};

			setTimeout( poll, interval );
		}

		function renderStillProcessingNotice( statusEl ) {
			while ( statusEl.firstChild ) {
				statusEl.removeChild( statusEl.firstChild );
			}
			const notice = document.createElement( 'div' );
			notice.className = 'notice notice-info';
			const p = document.createElement( 'p' );
			p.textContent = afI18n(
				'processingTimeout',
				'Still processing. Refresh the page later to check on progress.'
			);
			notice.appendChild( p );
			statusEl.appendChild( notice );
		}

		function renderFailureNotice( statusEl ) {
			while ( statusEl.firstChild ) {
				statusEl.removeChild( statusEl.firstChild );
			}
			const notice = document.createElement( 'div' );
			notice.className = 'notice notice-error';
			const p = document.createElement( 'p' );
			p.textContent = afI18n(
				'assetProcessingFailed',
				'Asset processing failed. Please try again.'
			);
			notice.appendChild( p );
			statusEl.appendChild( notice );
		}

		function bindListGridPolling() {
			const cards = document.querySelectorAll(
				'.af-creative-card[data-af-creative-status="processing"]'
			);
			if ( ! cards.length ) {
				return;
			}
			cards.forEach( pollCardUntilTerminal );
		}

		function pollCardUntilTerminal( card ) {
			const creativeId = card.dataset.afCreativeId;
			if ( ! creativeId ) {
				return;
			}
			let attempts = 0;
			const maxAttempts = 60;
			const poll = function () {
				attempts += 1;
				if ( attempts > maxAttempts ) {
					return;
				}
				const nextInterval = attempts > 12 ? 15000 : 5000;
				wp.apiFetch( {
					path: '/almost-famous/v1/creatives/' + creativeId,
					method: 'GET',
				} )
					.then( function ( response ) {
						const payload = getResponseData( response ) || {};
						const status = payload.status || 'processing';
						if ( status === 'complete' || status === 'failed' ) {
							updateCard( card, status, payload );
							return;
						}
						setTimeout( poll, nextInterval );
					} )
					.catch( function ( err ) {
						if (
							err &&
							( err.status === 404 ||
								err.code === 'rest_no_route' )
						) {
							return;
						}
						setTimeout( poll, nextInterval * 2 );
					} );
			};
			setTimeout( poll, 5000 );
		}

		function updateCard( card, status, payload ) {
			card.dataset.afCreativeStatus = status;
			const badge = card.querySelector( '[data-af-creative-badge]' );
			if ( badge ) {
				badge.className = 'af-badge af-badge--' + status;
				badge.textContent =
					status.charAt( 0 ).toUpperCase() + status.slice( 1 );
			}
			if ( status === 'complete' && payload && payload.thumbnail_url ) {
				const thumb = card.querySelector(
					'.af-creative-card__thumb img'
				);
				if ( thumb ) {
					thumb.src = payload.thumbnail_url;
				}
			}
		}

		return {
			init,
		};
	} )();

	/* ---------------------------------------------------------------
	 * Initialization — DOM ready.
	 * --------------------------------------------------------------- */

	document.addEventListener( 'DOMContentLoaded', function () {
		AfCampaignForm.init();
		AfAudienceBuilder.init();
		AfCreativeManager.init();
	} );

	/* Expose modules globally. */
	window.AfPoller = AfPoller;
	window.AfCampaignForm = AfCampaignForm;
	window.AfBudgetAllocation = AfBudgetAllocation;
	window.AfAudienceBuilder = AfAudienceBuilder;
	window.AfCreativeManager = AfCreativeManager;
} )( window.wp );
