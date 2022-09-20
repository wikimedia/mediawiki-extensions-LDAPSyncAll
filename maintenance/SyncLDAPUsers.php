<?php

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
			MediaWikiServices::getInstance()->getUserFactory()
				->newFromName( $config->get( 'LDAPSyncAllBlockExecutorUsername' ) )
		);

		$syncMechanismCallback = $config->get( 'LDAPSyncAllUsersSyncMechanism' );
		$usersSyncMechanism = call_user_func_array( $syncMechanismCallback, [ $config, $context ] );

		$status = $usersSyncMechanism->sync();
		$result = (object)$status->getValue();

		$this->output( 'LDAPSyncAll completed' );
		$this->output( PHP_EOL );

		if ( isset( $result->addedUsersCount ) ) {
			$this->output( "{$result->addedUsersCount} users added" );
			$this->output( PHP_EOL );
		}

		if ( isset( $result->disabledUsersCount ) ) {
			$this->output( "{$result->disabledUsersCount} users disabled" );
			$this->output( PHP_EOL );
		}

		if ( isset( $result->addedUsersFailsCount ) ) {
			$this->output( "{$result->addedUsersFailsCount} users failed to add" );
			$this->output( PHP_EOL );
		}

		if ( isset( $result->disabledUsersFailsCount ) ) {
			$this->output( "{$result->disabledUsersFailsCount} users failed to disable" );
			$this->output( PHP_EOL );
		}
	}
}

$maintClass = SyncLDAPUsers::class;
require_once RUN_MAINTENANCE_IF_MAIN;
