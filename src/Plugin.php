<?php
/**
 * Wires the plugin's services to WordPress.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace;

use DeliveryTrace\Delivery\Deliverer;
use DeliveryTrace\Delivery\OutcomeClassifier;
use DeliveryTrace\Delivery\RetryPolicy;
use DeliveryTrace\Demo\FakeTransport;
use DeliveryTrace\Demo\ScriptStore;
use DeliveryTrace\Form\Shortcode;
use DeliveryTrace\Form\SubmitHandler;
use DeliveryTrace\Privacy\Scrubber;
use DeliveryTrace\Storage\EventRepository;
use DeliveryTrace\Storage\LeadRepository;
use DeliveryTrace\Storage\Schema;
use InvalidArgumentException;

/**
 * Composition root.
 */
final class Plugin {

	/**
	 * Build the services and register their hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Schema::maybe_upgrade();

		$config    = Config::from_constants();
		$leads     = new LeadRepository();
		$events    = new EventRepository();
		$deliverer = new Deliverer( $leads, $events, new OutcomeClassifier(), self::retry_policy(), new Scrubber(), $config );

		( new Shortcode() )->register();
		( new SubmitHandler( $leads, $events, $deliverer ) )->register();

		if ( $config->is_demo() ) {
			( new FakeTransport( new ScriptStore(), $config ) )->register();
		}
	}

	/**
	 * Retry policy with the filterable schedule, falling back to the default when the filter returns nonsense.
	 *
	 * @return RetryPolicy
	 */
	private static function retry_policy(): RetryPolicy {
		/**
		 * Filters the seconds to wait before each automatic retry. At most three retries are used.
		 *
		 * @param int[] $delays Default 60, 300 and 900 seconds.
		 */
		$delays = apply_filters( 'delivery_trace_retry_schedule', array( 60, 300, 900 ) );

		try {
			return new RetryPolicy( is_array( $delays ) ? $delays : array() );
		} catch ( InvalidArgumentException $exception ) {
			return new RetryPolicy();
		}
	}
}
