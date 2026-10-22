<?php
/**
 * Cron handler: release expired seat locks.
 *
 * Fires on the `csb_release_locks` WP-Cron event, scheduled every 5 minutes.
 * Keeps the seat_locks table small and ensures temporary holds don't block
 * real customers indefinitely.
 *
 * @package BookaClass\Cron
 */

namespace BookaClass\Cron;

use BookaClass\Services\CapacityService;
use BookaClass\Services\AuditLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SeatLockCron
 */
class SeatLockCron {

	public function init(): void {
		add_action( 'csb_release_locks', [ $this, 'run' ] );
	}

	/**
	 * Deletes all expired seat locks.
	 */
	public function run(): void {
		$deleted = CapacityService::release_expired();

		if ( $deleted > 0 ) {
			AuditLogger::log(
				'expired_locks_released',
				'seat_lock',
				0,
				[ 'count' => $deleted ]
			);
		}
	}
}
