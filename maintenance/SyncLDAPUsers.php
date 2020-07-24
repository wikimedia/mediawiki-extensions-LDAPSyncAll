<?php

use MediaWiki\Extension\LDAPProvider\ClientFactory;
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

	public function execute() {
		$configuredDomains = DomainConfigFactory::getInstance()->getConfiguredDomains();
		$config = new GlobalVarConfig('');

		$context = RequestContext::getMain();
		$context->setUser(
			User::newFromName( $config->get( 'LDAPSyncAllBlockExecutorUsername' ) )
		);
		foreach ($configuredDomains as $domain) {
			$usersSyncMechanism = new UsersSyncMechanism(
				ClientFactory::getInstance()->getForDomain($domain),
				$domain,
				$config->get('LDAPGroupsSyncMechanismRegistry'),
				$config->get('LDAPUserInfoModifierRegistry'),
				$config->get('LDAPSyncAllExcludedUsernames'),
				$config->get('LDAPSyncAllExcludedGroups'),
				LoggerFactory::getInstance('ldapusersync'),
				MediaWikiServices::getInstance()->getDBLoadBalancer(),
				$context
			);
			$usersSyncMechanism->sync();
			$this->output('LDAPSyncAll completed' );
			$this->output(PHP_EOL);
			$this->output("{$usersSyncMechanism->addedUsersCount} users added" );
			$this->output(PHP_EOL);
			$this->output("{$usersSyncMechanism->disabledUsersCount} users disabled" );
			$this->output(PHP_EOL);
			$this->output("{$usersSyncMechanism->addedUsersFailsCount} users failed to add" );
			$this->output(PHP_EOL);
			$this->output("{$usersSyncMechanism->disabledUsersFailsCount} users failed to disable" );
			$this->output(PHP_EOL);
		}
	}
}

$maintClass = SyncLDAPUsers::class;
require_once RUN_MAINTENANCE_IF_MAIN;