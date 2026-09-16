<?php
/**
 * Delivery outcome names.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Delivery;

/**
 * The possible results of one delivery attempt.
 */
final class Outcome {

	public const DELIVERED           = 'delivered';
	public const DELIVERED_DUPLICATE = 'delivered_duplicate';
	public const RETRYABLE           = 'retryable';
	public const UNKNOWN             = 'unknown';
	public const UNREACHABLE         = 'unreachable';
	public const AUTH_FAILED         = 'auth_failed';
	public const REJECTED            = 'rejected';
	public const MISCONFIGURED       = 'misconfigured';

	public const ALL = array(
		self::DELIVERED,
		self::DELIVERED_DUPLICATE,
		self::RETRYABLE,
		self::UNKNOWN,
		self::UNREACHABLE,
		self::AUTH_FAILED,
		self::REJECTED,
		self::MISCONFIGURED,
	);

	/**
	 * Constants only.
	 */
	private function __construct() {
	}

	/**
	 * Whether the CRM has the lead.
	 *
	 * @param string $outcome Outcome name.
	 * @return bool
	 */
	public static function is_delivered( string $outcome ): bool {
		return in_array( $outcome, array( self::DELIVERED, self::DELIVERED_DUPLICATE ), true );
	}

	/**
	 * Whether retrying without a human change can never succeed.
	 *
	 * @param string $outcome Outcome name.
	 * @return bool
	 */
	public static function is_permanent_failure( string $outcome ): bool {
		return in_array( $outcome, array( self::AUTH_FAILED, self::REJECTED, self::MISCONFIGURED ), true );
	}
}
