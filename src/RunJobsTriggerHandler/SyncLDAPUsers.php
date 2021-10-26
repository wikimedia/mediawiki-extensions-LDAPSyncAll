<?php
namespace LDAPSyncAll\RunJobsTriggerHandler;

use BlueSpice\RunJobsTriggerHandler;
use GlobalVarConfig;
use RequestContext;
use Status;
use User;

class SyncLDAPUsers extends RunJobsTriggerHandler {

	public function doRun() {
		$status = Status::newGood();
		$config = new GlobalVarConfig( '' );
		$context = RequestContext::getMain();
		$context->setUser(
			User::newFromName( $config->get( 'LDAPSyncAllBlockExecutorUsername' ) )
		);

		$syncMechanismCallback = $config->get( 'LDAPSyncAllUsersSyncMechanism' );
		$usersSyncMechanism = call_user_func_array( $syncMechanismCallback, [ $config, $context ] );

		$usersSyncMechanism->sync();

		return $status;
	}
}
