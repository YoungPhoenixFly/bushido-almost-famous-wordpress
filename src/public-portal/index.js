import { render } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import { __ } from '@wordpress/i18n';
import {
	useRouter,
	portalConfig,
	canView,
	canManage,
	isDemo,
	t,
} from './utils';
import { Dashboard } from './views/Dashboard';
import { CreateCampaign } from './views/CreateCampaign';
import { CampaignDetail } from './views/CampaignDetail';
import { CampaignAnalytics } from './views/CampaignAnalytics';
import { Checkout } from './views/Checkout';
import { Payments } from './views/Payments';

function Nav( { view, navigate, manage } ) {
	const link = ( hash, label, key ) => (
		<a
			key={ key }
			href={ hash }
			className={ view === key ? 'active' : '' }
			aria-current={ view === key ? 'page' : undefined }
			onClick={ ( event ) => {
				event.preventDefault();
				navigate( hash );
			} }
		>
			{ label }
		</a>
	);

	return (
		<nav
			className="af-portal-nav"
			aria-label={ __(
				'Bushido Almost Famous portal',
				'bushido-almost-famous'
			) }
		>
			{ link(
				'#/',
				'📢 ' + __( 'Campaigns', 'bushido-almost-famous' ),
				'dashboard'
			) }
			{ manage &&
				link(
					'#/campaigns/new',
					__( '+ New', 'bushido-almost-famous' ),
					'create'
				) }
			<span className="af-nav-spacer" />
			{ manage &&
				link(
					'#/payments',
					'💳 ' + __( 'Payments', 'bushido-almost-famous' ),
					'payments'
				) }
		</nav>
	);
}

/**
 * Shown to visitors who are not signed in (and not in demo mode). The portal
 * is an authenticated console — it never renders campaign data to anonymous
 * visitors, which would expose the owner's budgets and spend.
 */
function SignInGate() {
	const config = portalConfig();
	return (
		<div className="af-portal">
			<div className="af-card af-portal-gate">
				<h2>
					{ t(
						'signInTitle',
						__(
							'Sign in to manage campaigns',
							'bushido-almost-famous'
						)
					) }
				</h2>
				<p>
					{ t(
						'signInBody',
						__(
							'The Bushido Almost Famous portal is available to signed-in team members.',
							'bushido-almost-famous'
						)
					) }
				</p>
				{ config.loginUrl && (
					<a
						className="af-btn af-btn-primary"
						href={ config.loginUrl }
					>
						{ t(
							'signInCta',
							__( 'Sign in', 'bushido-almost-famous' )
						) }
					</a>
				) }
			</div>
		</div>
	);
}

function App() {
	const { view, id, navigate } = useRouter();
	const config = portalConfig();
	const manage = canManage();

	// Authenticated console: anonymous visitors get a sign-in prompt, never
	// campaign data. Demo mode bypasses this with local fixtures.
	if ( ! canView() ) {
		return <SignInGate />;
	}

	// Guard management-only routes against view-only users (defense in depth —
	// the nav already hides them, but a pasted #hash must not render a form
	// whose writes the server will reject).
	const manageOnly = [ 'create', 'checkout', 'payments' ];
	const effectiveView =
		! manage && manageOnly.includes( view ) ? 'dashboard' : view;

	return (
		<div className="af-portal">
			{ isDemo() && (
				<div className="af-alert af-alert-info" role="status">
					{ t(
						'demoModeNotice',
						__( 'Demo mode is enabled.', 'bushido-almost-famous' )
					) }
				</div>
			) }

			{ ! isDemo() && ! config.hasApiKey && (
				<div className="af-alert af-alert-warning" role="status">
					{ t(
						'configurationRequired',
						__(
							'Configure an API key to use the portal.',
							'bushido-almost-famous'
						)
					) }
				</div>
			) }

			{ ! manage && (
				<div className="af-alert af-alert-info" role="status">
					{ t(
						'readOnlyNotice',
						__(
							'You have view-only access. Ask an administrator for campaign-management permissions to make changes.',
							'bushido-almost-famous'
						)
					) }
				</div>
			) }

			<Nav
				view={ effectiveView }
				navigate={ navigate }
				manage={ manage }
			/>

			{ effectiveView === 'dashboard' && (
				<Dashboard navigate={ navigate } />
			) }
			{ effectiveView === 'create' && (
				<CreateCampaign navigate={ navigate } />
			) }
			{ effectiveView === 'detail' && (
				<CampaignDetail id={ id } navigate={ navigate } />
			) }
			{ effectiveView === 'analytics' && (
				<CampaignAnalytics id={ id } navigate={ navigate } />
			) }
			{ effectiveView === 'checkout' && (
				<Checkout id={ id } navigate={ navigate } />
			) }
			{ effectiveView === 'payments' && (
				<Payments navigate={ navigate } />
			) }
		</div>
	);
}

domReady( () => {
	const el = document.getElementById( 'af-public-portal' );
	if ( el ) {
		render( <App />, el );
	}
} );
