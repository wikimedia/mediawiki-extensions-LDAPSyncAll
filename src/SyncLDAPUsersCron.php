<?php

namespace LDAPSyncAll;

use LDAPSyncAll\Process\SyncLDAPUsers;
use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\ProcessManager\ManagedProcess;
use MWStake\MediaWiki\Component\WikiCron\WikiCronManager;

class SyncLDAPUsersCron {

	/**
	 * @return void
	 */
	public static function register(): void {
		if ( defined( 'MW_PHPUNIT_TEST' ) || defined( 'MW_QUIBBLE_CI' ) ) {
			return;
		}

		/** @var WikiCronManager $cronManager */
		$cronManager = MediaWikiServices::getInstance()->getService( 'MWStake.WikiCronManager' );

		// Interval: Daily at 01:00
		$cronManager->registerCron( 'ldap-users-sync-all', '0 1 * * *', new ManagedProcess( [
			'sync-all' => [
				'class' => SyncLDAPUsers::class,
				'services' => [
					'UserFactory',
				],
			]
		] ) );
	}
}
