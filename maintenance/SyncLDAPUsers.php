<?php

use MediaWiki\Extension\LDAPProvider\DomainConfigFactory;
use LDAPSyncAll\UsersSyncMechanism;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

$maintPath = ( getenv( 'MW_INSTALL_PATH' ) !== false
		? getenv( 'MW_INSTALL_PATH' )
		: __DIR__ . '/../../..' ) . '/maintenance/Maintenance.php';
if ( !file_exists( $maintPath ) ) {
	echo "Please set the environment variable MW_INSTALL_PATH "
		. "to your MediaWiki installation.\n";
	exit( 1 );
}
require_once $maintPath;

class SyncLDAPUsers extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'LDAPSyncAll' );
	}

	public function execute() {
		$config = new GlobalVarConfig( '' );
		$context = RequestContext::getMain();
		$context->setUser(
			User::newFromName( $config->get( 'LDAPSyncAllBlockExecutorUsername' ) )
		);

		$usersSyncMechanism = new UsersSyncMechanism(
			DomainConfigFactory::getInstance()->getConfiguredDomains(),
			$config->get( 'LDAPGroupsSyncMechanismRegistry' ),
			$config->get( 'LDAPUserInfoModifierRegistry' ),
			$config->get( 'LDAPSyncAllExcludedUsernames' ),
			$config->get( 'LDAPSyncAllExcludedGroups' ),
			LoggerFactory::getInstance( 'LDAPSyncAll' ),
			MediaWikiServices::getInstance()->getDBLoadBalancer(),
			$context
		);

		$status = $usersSyncMechanism->sync();
		$result = (object)$status->getValue();

		$this->output( 'LDAPSyncAll completed' );
		$this->output( PHP_EOL );
		$this->output( "{$result->addedUsersCount} users added" );
		$this->output( PHP_EOL );
		$this->output( "{$result->disabledUsersCount} users disabled" );
		$this->output( PHP_EOL );
		$this->output( "{$result->addedUsersFailsCount} users failed to add" );
		$this->output( PHP_EOL );
		$this->output( "{$result->disabledUsersFailsCount} users failed to disable" );
		$this->output( PHP_EOL );
	}
}

$maintClass = SyncLDAPUsers::class;
require_once RUN_MAINTENANCE_IF_MAIN;
