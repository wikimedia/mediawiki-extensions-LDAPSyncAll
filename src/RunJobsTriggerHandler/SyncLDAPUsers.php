<?php
namespace LDAPSyncAll\RunJobsTriggerHandler;

use BlueSpice\RunJobsTriggerHandler;
use GlobalVarConfig;
use LDAPSyncAll\UsersSyncMechanism;
use MediaWiki\Extension\LDAPProvider\ClientFactory;
use MediaWiki\Extension\LDAPProvider\DomainConfigFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use RequestContext;
use Status;
use User;

class SyncLDAPUsers extends RunJobsTriggerHandler {

	public function doRun() {
		$status = Status::newGood();
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
		}

		return $status;
	}
}