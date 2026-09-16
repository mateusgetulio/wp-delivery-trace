<?php
/**
 * Lead status names.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Storage;

/**
 * Where a lead is in its delivery.
 */
final class LeadStatus {

	public const PENDING         = 'pending';
	public const DELIVERING      = 'delivering';
	public const DELIVERED       = 'delivered';
	public const RETRY_SCHEDULED = 'retry_scheduled';
	public const NEEDS_ATTENTION = 'needs_attention';

	public const AUTOMATIC_CLAIMABLE = array( self::PENDING, self::RETRY_SCHEDULED );

	public const MANUAL_CLAIMABLE = array( self::PENDING, self::RETRY_SCHEDULED, self::NEEDS_ATTENTION );

	/**
	 * Constants only.
	 */
	private function __construct() {
	}
}
