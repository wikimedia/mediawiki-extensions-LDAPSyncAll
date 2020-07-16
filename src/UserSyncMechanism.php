<?php
namespace LDAPSyncAll;

use Config;
use MediaWiki\Extension\LDAPGroups\GroupSyncProcess;
use MediaWiki\Extension\LDAPProvider\Client;
use MediaWiki\Extension\LDAPProvider\ClientFactory;
use MediaWiki\Extension\LDAPProvider\DomainConfigFactory;
use MediaWiki\Extension\LDAPProvider\UserDomainStore;
use MediaWiki\Extension\LDAPUserInfo\UserInfoSyncProcess;
use MediaWiki\MediaWikiServices;
use LoadBalancer;
use Psr\Log\LoggerInterface;
use Status;
use User;

class UserSyncMechanism
{
    /**
     *
     * @var LoggerInterface
     */
    protected $logger = null;

    /**
     * @var LoadBalancer
     */
    protected $loadBalancer = null;

    /**
     * @var Client
     */
    protected $ldapClient;

    /**
     *
     * @var Status
     */
    protected $status = null;

    /**
     * @var array null
     */
    protected $LDAPGroupsSyncMechanismRegistry = null;

    /**
     * @var array null
     */
    protected $LDAPUserInfoModifierRegistry = null;

    /**
     * @var Config
     */
    protected $domainConfig;

    /**
     * UserSyncMechanism constructor.
     * @param Client $ldapClient
     * @param LoggerInterface $logger
     * @param LoadBalancer $loadBalancer
     * @param Config $domainConfig
     * @param array $LDAPGroupsSyncMechanismRegistry
     * @param array $LDAPUserInfoModifierRegistry
     */
    public function __construct(
        Client $ldapClient,
        LoggerInterface $logger,
        LoadBalancer $loadBalancer,
        Config $domainConfig,
        $LDAPGroupsSyncMechanismRegistry,
        $LDAPUserInfoModifierRegistry
    ) {
        $this->ldapClient = $ldapClient;
        $this->logger = $logger;
        $this->loadBalancer = $loadBalancer;
        $this->domainConfig = $domainConfig;
        $this->LDAPGroupsSyncMechanismRegistry = $LDAPGroupsSyncMechanismRegistry;
        $this->LDAPUserInfoModifierRegistry = $LDAPUserInfoModifierRegistry;
    }

    /**
     * @return Status
     */
    public function sync() {
        $this->status = \Status::newGood();
        $this->doSync();
        return $this->status;
    }

    protected function doSync() {
        $localUsers = $this->getUsersFromDB();
        $ldapUsers = $this->getUsersFromLDAP();

        foreach( $ldapUsers as $ldapUsername ) {
            if ( !in_array( $ldapUsername, $localUsers ) ) {

            }
        }
    }

    /**
     * @return array
     */
    protected function getUsersFromLDAP() {
        $users = $this->ldapClient->search(
            "(&(objectClass=User)(objectCategory=Person))"
        );

        return $users;
    }

    /**
     * @return array
     */
    protected function getUsersFromDB() {
        $dbr = $this->loadBalancer->getConnection( DB_REPLICA );
        $result = $dbr->select(
            'user',
            [ 'user_id', 'user_name', 'domain' ]
        );

        $users = [];
        foreach ( $result as $row ) {
            $user = User::newFromId($row->user_id);

            if (!is_object($user)) {
                continue;
            }
            $users[$row->user_name] = $user;
        }

        return $users;
    }

    protected function addUser( $username ) {
        $user = User::newFromName( $username );

        $user->addToDatabase();
        $user->saveSettings();

        $this->status = $user->changeAuthenticationData( [
            'username' => $user->getName(),
            'password' => '',
            'retype' => '',
        ] );

        $this->syncUserInfo( $user );
        $this->syncUserGroups( $user );
    }

    /**
     * @param $user
     */
    protected function syncUserGroups( $user ) {
        $domainStore = new UserDomainStore( $this->loadBalancer );
        $domain = $domainStore->getDomainForUser( $user );
        if ( $domain === null ) {
            return;
        }
        $client = ClientFactory::getInstance()->getForDomain( $domain );
        $domainConfig = DomainConfigFactory::getInstance()->factory( $domain, 'groupsync' );
        $callbackRegistry = $this->getConfig()->get( 'LDAPGroupsSyncMechanismRegistry' );
        $process = new GroupSyncProcess( $user, $domainConfig, $client, $callbackRegistry );
        $process->run();
    }

    /**
     * @param $user
     */
    protected function syncUserInfo( $user ) {
        $process = new UserInfoSyncProcess(
            $user,
            $this->domainConfig,
            $this->ldapClient,
            $this->LDAPUserInfoModifierRegistry
        );
        $process->run();
    }

    protected function disableUser( $userId ) {

    }
}