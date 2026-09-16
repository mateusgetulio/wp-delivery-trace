<?php
/**
 * Runs automatic retries.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Delivery;

use DeliveryTrace\Storage\LeadRepository;

/**
 * Handles each lead's scheduled retry, and a periodic sweep for anything a lost cron event or a dead process left behind.
 */
final class Runner {

	public const SWEEP_HOOK = 'delivery_trace_sweep';

	public const SWEEP_SCHEDULE = 'delivery_trace_five_minutes';

	public const PENDING_GRACE_SECONDS = 60;

	public const BATCH_SIZE = 20;

	public const TIME_BUDGET_SECONDS = 20;

	/**
	 * Lead storage.
	 *
	 * @var LeadRepository
	 */
	private LeadRepository $leads;

	/**
	 * Makes the attempts.
	 *
	 * @var Deliverer
	 */
	private Deliverer $deliverer;

	/**
	 * Constructor.
	 *
	 * @param LeadRepository $leads     Lead storage.
	 * @param Deliverer      $deliverer Makes the attempts.
	 */
	public function __construct( LeadRepository $leads, Deliverer $deliverer ) {
		$this->leads     = $leads;
		$this->deliverer = $deliverer;
	}

	/**
	 * Hook the retry and sweep events, and make sure the sweep is scheduled.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Five minutes matches the retry schedule.
		add_action( Deliverer::RETRY_HOOK, array( $this, 'run_scheduled' ) );
		add_action( self::SWEEP_HOOK, array( $this, 'run_due' ) );

		if ( is_admin() || wp_doing_cron() ) {
			self::schedule_sweep();
		}
	}

	/**
	 * Schedule the sweep if it is not scheduled yet.
	 *
	 * @return void
	 */
	public static function schedule_sweep(): void {
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::SWEEP_SCHEDULE, self::SWEEP_HOOK );
		}
	}

	/**
	 * Add the five-minute interval used by the sweep.
	 *
	 * @param array $schedules Registered schedules.
	 * @return array
	 */
	public function add_schedule( $schedules ): array {
		$schedules                         = (array) $schedules;
		$schedules[ self::SWEEP_SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'delivery-trace' ),
		);

		return $schedules;
	}

	/**
	 * Handle one lead's retry event, only if the lead is actually due.
	 *
	 * The event can be older than the lead's current schedule, for example
	 * after a manual retry, so next_attempt_at decides, not the event.
	 *
	 * @param int|string $lead_id Lead ID from the event arguments.
	 * @return void
	 */
	public function run_scheduled( $lead_id ): void {
		$lead_id = (int) $lead_id;

		if ( $this->leads->is_due( $lead_id, time(), self::PENDING_GRACE_SECONDS ) ) {
			$this->deliverer->attempt( $lead_id );
		}
	}

	/**
	 * Attempt due leads, up to one batch and one time budget.
	 *
	 * With the CRM down every attempt can take the full timeout, so the
	 * budget keeps a web request well under common execution limits.
	 *
	 * @return int How many leads were attempted.
	 */
	public function run_due(): int {
		$attempted = 0;
		$deadline  = microtime( true ) + self::TIME_BUDGET_SECONDS;

		foreach ( $this->leads->due_ids( time(), self::PENDING_GRACE_SECONDS, self::BATCH_SIZE ) as $lead_id ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}

			if ( null !== $this->deliverer->attempt( $lead_id ) ) {
				++$attempted;
			}
		}

		return $attempted;
	}

	/**
	 * Remove the sweep and every pending retry event.
	 *
	 * @return void
	 */
	public static function unschedule_all(): void {
		wp_clear_scheduled_hook( self::SWEEP_HOOK );
		wp_unschedule_hook( Deliverer::RETRY_HOOK );
	}
}
