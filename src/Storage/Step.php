<?php
/**
 * Event step names.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Storage;

/**
 * The steps recorded in a lead's trace.
 */
final class Step {

	public const RECEIVED            = 'received';
	public const SANITIZED           = 'sanitized';
	public const VALIDATED           = 'validated';
	public const STORED              = 'stored';
	public const MANUAL_RETRY        = 'manual_retry';
	public const ATTEMPT_INTERRUPTED = 'attempt_interrupted';
	public const CRM_ATTEMPT         = 'crm_attempt';
	public const RETRY_SCHEDULED     = 'retry_scheduled';
	public const NEEDS_ATTENTION     = 'needs_attention';

	/**
	 * Constants only.
	 */
	private function __construct() {
	}
}
