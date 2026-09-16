<?php
/**
 * Fake CRM that answers at the HTTP API layer.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Demo;

use DeliveryTrace\Config;
use WP_Error;

/**
 * Answers requests to the demo host so the delivery code runs unchanged.
 *
 * The demo replaces the network, not the delivery code: the Deliverer
 * still calls wp_safe_remote_post(), and this filter short-circuits it
 * before any DNS lookup or connection happens.
 */
final class FakeTransport {

	/**
	 * Scripted outcomes and accepted keys.
	 *
	 * @var ScriptStore
	 */
	private ScriptStore $store;

	/**
	 * Expected token.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Constructor.
	 *
	 * @param ScriptStore $store  Scripted outcomes and accepted keys.
	 * @param Config      $config Expected token.
	 */
	public function __construct( ScriptStore $store, Config $config ) {
		$this->store  = $store;
		$this->config = $config;
	}

	/**
	 * Hook into the HTTP API.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	/**
	 * Answer requests to the demo host; leave every other request alone.
	 *
	 * @param false|array|WP_Error $preempt A preemptive response from an earlier filter, or false.
	 * @param array                $args    Request arguments.
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error
	 */
	public function intercept( $preempt, $args, $url ) {
		if ( false !== $preempt || Config::DEMO_HOST !== wp_parse_url( (string) $url, PHP_URL_HOST ) ) {
			return $preempt;
		}

		$headers = array_change_key_case( (array) ( $args['headers'] ?? array() ), CASE_LOWER );

		if ( ( $headers['authorization'] ?? '' ) !== 'Bearer ' . $this->config->crm_token() ) {
			return $this->response( 401, '{"error":"invalid token"}' );
		}

		$key = (string) ( $headers['idempotency-key'] ?? '' );

		if ( '' !== $key && $this->store->has_accepted( $key ) ) {
			return $this->response( 200, '{"duplicate":true}' );
		}

		$outcome = $this->store->next_outcome();

		if ( ScriptStore::HTTP_500 === $outcome ) {
			return $this->response( 500, '{"error":"internal error"}' );
		}

		$this->store->accept( $key );

		if ( ScriptStore::TIMEOUT_AFTER_ACCEPT === $outcome ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}

		return $this->response( 204, '' );
	}

	/**
	 * A response array shaped like the one WP_Http returns.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Response body.
	 * @return array
	 */
	private function response( int $code, string $body ): array {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
