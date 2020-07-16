<?php

use MediaWiki\Extension\LDAPProvider\ClientFactory;
use MediaWiki\Extension\LDAPProvider\DomainConfigFactory;
use MediaWiki\Extension\LDAPProvider\UserDomainStore;
use MediaWiki\Extension\LDAPGroups\GroupSyncProcess;
use LDAPSyncAll\UserSyncMechanism;

$maintPath = ( getenv( 'MW_INSTALL_PATH' ) !== false
        ? getenv( 'MW_INSTALL_PATH' )
        : __DIR__ . '/../../..' ) . '/maintenance/Maintenance.php';
if ( !file_exists( $maintPath ) ) {
    echo "Please set the environment variable MW_INSTALL_PATH "
        . "to your MediaWiki installation.\n";
    exit( 1 );
}
require_once $maintPath;

class SyncUsers extends Maintenance {

    public function execute() {
        $domain = DomainConfigFactory::getInstance()->getConfiguredDomains();
        $ldapClient = ClientFactory::getInstance()->getForDomain();
        $userSyncMechanism = new UserSyncMechanism();
    }
}