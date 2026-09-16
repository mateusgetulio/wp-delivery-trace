<?php
/**
 * Admin actions: retry now, run due retries, demo scenarios.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Admin;

use DeliveryTrace\Delivery\Deliverer;
use DeliveryTrace\Delivery\Runner;
use DeliveryTrace\Demo\ScriptStore;
use DeliveryTrace\Form\Intake;
use DeliveryTrace\Storage\EventRepository;
use RuntimeException;

/**
 * Admin-post handlers. Each checks the capability and its own nonce.
 */
final class Actions {

	public const RETRY_NOW = 'delivery_trace_retry_now';

	public const RUN_DUE = 'delivery_trace_run_due';

	public const DEMO = 'delivery_trace_demo';

	/**
	 * Makes manual attempts.
	 *
	 * @var Deliverer
	 */
	private Deliverer $deliverer;

	/**
	 * Runs due retries.
	 *
	 * @var Runner
	 */
	private Runner $runner;

	/**
	 * Stores demo leads through the same path as the form.
	 *
	 * @var Intake
	 */
	private Intake $intake;

	/**
	 * Fake CRM script.
	 *
	 * @var ScriptStore
	 */
	private ScriptStore $store;

	/**
	 * Whether demo actions are allowed.
	 *
	 * @var bool
	 */
	private bool $demo;

	/**
	 * Constructor.
	 *
	 * @param Deliverer   $deliverer Makes manual attempts.
	 * @param Runner      $runner    Runs due retries.
	 * @param Intake      $intake    Stores demo leads.
	 * @param ScriptStore $store     Fake CRM script.
	 * @param bool        $demo      Whether demo actions are allowed.
	 */
	public function __construct( Deliverer $deliverer, Runner $runner, Intake $intake, ScriptStore $store, bool $demo ) {
		$this->deliverer = $deliverer;
		$this->runner    = $runner;
		$this->intake    = $intake;
		$this->store     = $store;
		$this->demo      = $demo;
	}

	/**
	 * Register the admin-post handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::RETRY_NOW, array( $this, 'retry_now' ) );
		add_action( 'admin_post_' . self::RUN_DUE, array( $this, 'run_due' ) );

		if ( $this->demo ) {
			add_action( 'admin_post_' . self::DEMO, array( $this, 'demo' ) );
		}
	}

	/**
	 * Nonce action for a button, tied to its lead or scenario.
	 *
	 * @param string $action Admin-post action.
	 * @param array  $fields Hidden fields the button sends.
	 * @return string
	 */
	public static function nonce_action( string $action, array $fields ): string {
		return $action . '|' . implode( '|', array_map( 'strval', $fields ) );
	}

	/**
	 * The demo scenarios: button label and the fake CRM's answers in order.
	 *
	 * @return array<string, array{label: string, script: string[], name: string}>
	 */
	public static function demo_scenarios(): array {
		return array(
			'success' => array(
				'label'  => __( 'Simulate success', 'delivery-trace' ),
				'script' => array( ScriptStore::OK ),
				'name'   => 'Maria Silva',
			),
			'http500' => array(
				'label'  => __( 'Simulate HTTP 500', 'delivery-trace' ),
				'script' => array( ScriptStore::HTTP_500, ScriptStore::OK ),
				'name'   => 'James Carter',
			),
			'timeout' => array(
				'label'  => __( 'Simulate timeout', 'delivery-trace' ),
				'script' => array( ScriptStore::TIMEOUT_AFTER_ACCEPT ),
				'name'   => 'Aiko Tanaka',
			),
		);
	}

	/**
	 * Attempt one lead now, including leads that need attention.
	 *
	 * @return void
	 */
	public function retry_now(): void {
		$lead_id = $this->posted_int( 'lead' );

		$this->guard( self::nonce_action( self::RETRY_NOW, array( 'lead' => $lead_id ) ) );

		$status = $this->deliverer->attempt( $lead_id, true );
		$args   = null === $status ? array( 'delivery_trace_notice' => 'not_claimable' ) : array();

		$this->redirect( add_query_arg( $args, AdminPage::url( $lead_id ) ) );
	}

	/**
	 * Attempt every lead that is due.
	 *
	 * @return void
	 */
	public function run_due(): void {
		$this->guard( self::nonce_action( self::RUN_DUE, array() ) );

		$count = $this->runner->run_due();

		$this->redirect(
			add_query_arg(
				array(
					'delivery_trace_notice' => 'ran_due',
					'delivery_trace_count'  => $count,
				),
				AdminPage::url()
			)
		);
	}

	/**
	 * Submit a sample lead with a scripted CRM answer, then open its trace.
	 *
	 * @return void
	 */
	public function demo(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce includes the scenario and is verified in guard() before anything happens.
		$scenario  = isset( $_POST['scenario'] ) ? sanitize_key( wp_unslash( $_POST['scenario'] ) ) : '';
		$scenarios = self::demo_scenarios();

		$this->guard( self::nonce_action( self::DEMO, array( 'scenario' => $scenario ) ) );

		if ( ! isset( $scenarios[ $scenario ] ) ) {
			wp_die( esc_html__( 'Unknown demo scenario.', 'delivery-trace' ), '', array( 'response' => 400 ) );
		}

		$definition = $scenarios[ $scenario ];
		$first_name = strtolower( strtok( $definition['name'], ' ' ) );

		$this->store->set_script( $definition['script'] );

		try {
			$result = $this->intake->accept(
				array(
					'name'           => $definition['name'],
					'email'          => $first_name . '@example.com',
					'phone'          => '(303) 555-01' . wp_rand( 10, 99 ),
					'floor_plan'     => 'b2',
					'preferred_date' => wp_date( 'Y-m-d', time() + WEEK_IN_SECONDS ),
				),
				array( 'demo' ),
				EventRepository::now_ms()
			);
		} catch ( RuntimeException $exception ) {
			wp_die( esc_html__( 'The demo lead could not be stored.', 'delivery-trace' ), '', array( 'response' => 500 ) );
		}

		if ( 0 === $result['lead_id'] ) {
			wp_die( esc_html( implode( ' ', $result['errors'] ) ), '', array( 'response' => 500 ) );
		}

		$this->redirect( AdminPage::url( $result['lead_id'] ) );
	}

	/**
	 * Stop unless the user may manage the plugin and the nonce is valid.
	 *
	 * @param string $nonce_action Nonce action.
	 * @return void
	 */
	private function guard( string $nonce_action ): void {
		if ( ! current_user_can( AdminPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'delivery-trace' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * A posted non-negative integer.
	 *
	 * @param string $key Field name.
	 * @return int
	 */
	private function posted_int( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only used to build the nonce action, which guard() verifies before anything happens.
		return isset( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : 0;
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string $url Target URL.
	 * @return void
	 */
	private function redirect( string $url ): void {
		wp_safe_redirect( $url, 303 );
		exit;
	}
}
