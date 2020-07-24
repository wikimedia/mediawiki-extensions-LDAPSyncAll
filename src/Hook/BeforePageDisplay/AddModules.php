<?php

namespace LDAPSyncAll\Hook\BeforePageDisplay;

use BlueSpice\Hook\BeforePageDisplay;
use LDAPSyncAll\UsersSyncMechanism;
use MediaWiki\Extension\LDAPProvider\ClientFactory;
use MediaWiki\Extension\LDAPProvider\DomainConfigFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

class AddModules extends BeforePageDisplay {

	protected function doProcess() {
        $configuredDomains = DomainConfigFactory::getInstance()->getConfiguredDomains();

        $config = new \GlobalVarConfig('');

        $domain = $configuredDomains[0];
        foreach ($configuredDomains as $domain) {
            $usersSyncMechanism = new UsersSyncMechanism(
                ClientFactory::getInstance()->getForDomain($domain),
                $domain,
                $config->get('LDAPGroupsSyncMechanismRegistry'),
                $config->get('LDAPUserInfoModifierRegistry'),
                LoggerFactory::getInstance( 'ldapusersync' ),
                MediaWikiServices::getInstance()->getDBLoadBalancer()
            );
            $usersSyncMechanism->sync();
        }



		return  true;
	}
}
