<?php
/**
 * WordPress Site Health integration — exposes a "Bushido API" check
 * under Tools → Site Health → Status.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AlmostFamous\Api\Api_Client;

/**
 * Surfaces backend reachability through WordPress's Site Health system.
 *
 * Site owners can spot a degraded AF API without leaving wp-admin, and
 * support staff have a one-screen view that confirms whether the plugin
 * can reach Bushido at all.
 */
class Site_Health {

	/**
	 * API client used to ping /health.
	 *
	 * @var Api_Client
	 */
	private Api_Client $client;

	/**
	 * Constructor.
	 *
	 * @param Api_Client $client API client.
	 */
	public function __construct( Api_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'site_status_tests', array( $this, 'register_test' ) );
	}

	/**
	 * Register the AF API direct test with the Site Health framework.
	 *
	 * @param array $tests Existing Site Health tests.
	 * @return array
	 */
	public function register_test( array $tests ): array {
		$tests['direct']['almost_famous_api'] = array(
			'label' => __( 'Bushido API reachability', 'bushido-almost-famous' ),
			'test'  => array( $this, 'run_test' ),
		);
		return $tests;
	}

	/**
	 * Execute the test. Returns a Site Health test result envelope.
	 *
	 * @return array
	 */
	public function run_test(): array {
		$response = $this->client->get_top( '/health' );

		$base = array(
			'badge'   => array(
				'label' => __( 'Bushido Almost Famous', 'bushido-almost-famous' ),
				'color' => 'blue',
			),
			'test'    => 'almost_famous_api',
			'actions' => '',
		);

		if ( isset( $response['error'] ) ) {
			return array_merge(
				$base,
				array(
					'label'       => __( 'Could not reach the Bushido API', 'bushido-almost-famous' ),
					'status'      => 'recommended',
					'description' => sprintf(
						'<p>%s</p>',
						esc_html__( 'The plugin tried to ping /health on the Bushido API and got no response. Campaign data, conversion tracking, and OAuth flows will not work until connectivity is restored.', 'bushido-almost-famous' )
					),
					'badge'       => array(
						'label' => __( 'Bushido Almost Famous', 'bushido-almost-famous' ),
						'color' => 'red',
					),
				)
			);
		}

		$payload = is_array( $response['data'] ?? null ) ? $response['data'] : array();
		$status  = (string) ( $payload['status'] ?? 'unknown' );

		if ( 'ok' === $status ) {
			return array_merge(
				$base,
				array(
					'label'       => __( 'Bushido API is reachable', 'bushido-almost-famous' ),
					'status'      => 'good',
					'description' => sprintf(
						'<p>%s</p>',
						esc_html__( 'The plugin reached the Bushido API health endpoint successfully.', 'bushido-almost-famous' )
					),
					'badge'       => array(
						'label' => __( 'Bushido Almost Famous', 'bushido-almost-famous' ),
						'color' => 'green',
					),
				)
			);
		}

		return array_merge(
			$base,
			array(
				'label'       => __( 'Bushido API is degraded', 'bushido-almost-famous' ),
				'status'      => 'recommended',
				'description' => sprintf(
					'<p>%s</p>',
					sprintf(
						/* translators: %s: API status string. */
						esc_html__( 'Bushido API returned status "%s". Some features may be slow or unavailable.', 'bushido-almost-famous' ),
						esc_html( $status )
					)
				),
				'badge'       => array(
					'label' => __( 'Bushido Almost Famous', 'bushido-almost-famous' ),
					'color' => 'orange',
				),
			)
		);
	}
}
