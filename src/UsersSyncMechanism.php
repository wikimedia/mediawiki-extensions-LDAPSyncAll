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
use MediaWiki\Extension\LDAPUserInfo\Config as LDAPUserInfoConfig;
use MediaWiki\Extension\LDAPGroups\Config as LDAPGroupsConfig;

class UsersSyncMechanism
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
	 * @var string
	 */
	protected $domain;

	/**
	 * UsersSyncMechanism constructor.
	 * @param Client $ldapClient
	 * @param string $domain
	 * @param $LDAPGroupsSyncMechanismRegistry
	 * @param $LDAPUserInfoModifierRegistry
	 * @param LoggerInterface $logger
	 * @param LoadBalancer $loadBalancer
	 */
	public function __construct(
		Client $ldapClient,
		$domain,
		$LDAPGroupsSyncMechanismRegistry,
		$LDAPUserInfoModifierRegistry,
		LoggerInterface $logger,
		LoadBalancer $loadBalancer
	) {
		$this->ldapClient = $ldapClient;
		$this->logger = $logger;
		$this->loadBalancer = $loadBalancer;
		$this->domain = $domain;
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

		foreach( $ldapUsers as $ldapUsername => $ldapUser ) {
			if ( !in_array( $ldapUsername, $localUsers ) ) {
				$this->addUser( $ldapUser['username'], $ldapUser['email'] );
			}
		}
	}

	/**
	 * @return array
	 */
	protected function getUsersFromLDAP() {
		$memberOf = '';
		$authConfig = DomainConfigFactory::getInstance()->factory( $this->domain, 'authorization' );
		if( $authConfig instanceof Config && $authConfig->has( 'rules' ) ) {
			$rules = $authConfig->get( 'rules' );
			if ( array_key_exists('groups', $rules ) && array_key_exists('required', $rules['groups'] ) ) {
				if ( count($rules['groups']['required']) > 0 ) {
					$memberOf = '(|';
					foreach ( $rules['groups']['required'] as $group ) {
						$memberOf .= '(memberOf=' . $group . ')';
					}
					$memberOf .= ')';
				}
			}
		}

		$ldapUsersDirty = $this->ldapClient->search(
			"(&(objectClass=User)(objectCategory=Person){$memberOf})"
		);

		$ldapUsers = [];
		foreach($ldapUsersDirty as $user) {
			$ldapUsers[$user['samaccountname'][0]] = [
				'user_name' => $user['samaccountname'][0],
				'user_real_name' => $user['cn'][0],
				'user_email' => $user['userprincipalname'][0]
			];
		}
		echo "<pre>";
		print_r($ldapUsersDirty);die;

		return $users;
	}

	/**
	 * @return array
	 */
	protected function getUsersFromDB() {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$result = $dbr->select(
			'user',
			[ 'user_id', 'user_name' ]
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

	/**
	 * @param $username
	 * @param string $email
	 * @throws \MWException
	 */
	protected function addUser( $username, $email = '' ) {
		try {
			$user = User::newFromName( $username );
			$user->addToDatabase();

			$user->saveSettings();
			$user->setEmail( $email );

			$this->syncUserInfo( $user );
			$this->syncUserGroups( $user );
		} catch ( \Exception $exception ) {
			$this->logger->alert(
				'User `{username}` creation error {message}',
				[
					'username' => $username,
					'message' => $exception->getMessage()
				]
			);
		}

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
		$domainConfig = DomainConfigFactory::getInstance()->factory( $domain, LDAPGroupsConfig::DOMAINCONFIG_SECTION );
		$callbackRegistry = MediaWikiServices::getInstance()->getMainConfig()->get( 'LDAPGroupsSyncMechanismRegistry' );
		$process = new GroupSyncProcess( $user, $domainConfig, $client, $callbackRegistry );
		$process->run();
	}

	/**
	 * @param $user
	 */
	protected function syncUserInfo( $user ) {
		$process = new UserInfoSyncProcess(
			$user,
			DomainConfigFactory::getInstance()->factory( $this->domain, LDAPUserInfoConfig::DOMAINCONFIG_SECTION),
			$this->ldapClient,
			$this->LDAPUserInfoModifierRegistry
		);
		$process->run();
	}

	protected function disableUser( $userId ) {

	}
}