<?php

namespace LDAPSyncAll\Process;

use MediaWiki\Config\GlobalVarConfig;
use MediaWiki\Context\RequestContext;
use MediaWiki\User\UserFactory;
use MWStake\MediaWiki\Component\ProcessManager\IProcessStep;

class SyncLDAPUsers implements IProcessStep {

	/**
	 * @param UserFactory $userFactory
	 */
	public function __construct( private readonly UserFactory $userFactory ) {
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $data = [] ): array {
		$config = new GlobalVarConfig( '' );
		$context = RequestContext::getMain();
		$context->setUser(
			$this->userFactory->newFromName( $config->get( 'LDAPSyncAllBlockExecutorUsername' ) )
		);

		$syncMechanismCallback = $config->get( 'LDAPSyncAllUsersSyncMechanism' );
		$usersSyncMechanism = call_user_func_array( $syncMechanismCallback, [ $config, $context ] );

		$usersSyncMechanism->sync();

		return [];
	}
}
