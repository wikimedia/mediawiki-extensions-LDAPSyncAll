<?php

namespace LDAPSyncAll;

use Exception;
use IContextSource;
use Wikimedia\Rdbms\LoadBalancer;
use LDAPSyncAll\UserListProvider\LdapToolsBackend;
use MediaWiki\Extension\LDAPAuthorization\Config as LDAPAuthorizationConfig;
use MediaWiki\Extension\LDAPAuthorization\RequirementsChecker;
use MediaWiki\Extension\LDAPGroups\Config as LDAPGroupsConfig;
use MediaWiki\Extension\LDAPGroups\GroupSyncProcess;
use MediaWiki\Extension\LDAPProvider\Client;
use MediaWiki\Extension\LDAPProvider\ClientConfig;
use MediaWiki\Extension\LDAPProvider\ClientFactory;
use MediaWiki\Extension\LDAPProvider\DomainConfigFactory;
use MediaWiki\Extension\LDAPProvider\UserDomainStore;
use MediaWiki\Extension\LDAPUserInfo\UserInfoSyncProcess;
use MediaWiki\Extension\LDAPUserInfo\Config as LDAPUserInfoConfig;
use Psr\Log\LoggerInterface;
use SpecialBlock;
use Status;
use User;
use UserGroupMembership;

class UsersSyncMechanism {

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
	 * @var array
	 */
	protected $excludedUsernames = [];

	/**
	 * @var array
	 */
	protected $excludedGroups = [];

	/**
	 * @var string[]
	 */
	protected $domains;

	/**
	 * @var IContextSource;
	 */
	protected $context;

	/**
	 * @var int
	 */
	public $disabledUsersCount = 0;

	/**
	 * @var int
	 */
	public $disabledUsersFailsCount = 0;

	/**
	 * @var int
	 */
	public $addedUsersCount = 0;

	/**
	 * @var int
	 */
	public $addedUsersFailsCount = 0;

	/**
	 * @var DomainConfigFactory
	 */
	private $domainConfigFactory = null;

	/**
	 * @var array
	 */
	private $usernameDomainMap = [];

	/**
	 * @var UserDomainStore
	 */
	private $userDomainStore = null;

	/**
	 *
	 * @var Client[]
	 */
	private $clients = null;

	/**
	 * UsersSyncMechanism constructor.
	 * @param string[] $domains
	 * @param array $LDAPGroupsSyncMechanismRegistry
	 * @param array $LDAPUserInfoModifierRegistry
	 * @param array $excludedUsernames
	 * @param array $excludedGroups
	 * @param LoggerInterface $logger
	 * @param LoadBalancer $loadBalancer
	 * @param IContextSource $context
	 */
	public function __construct(
		array $domains,
		$LDAPGroupsSyncMechanismRegistry,
		$LDAPUserInfoModifierRegistry,
		$excludedUsernames,
		$excludedGroups,
		LoggerInterface $logger,
		LoadBalancer $loadBalancer,
		IContextSource $context
	) {
		$this->domains = $domains;
		$this->LDAPGroupsSyncMechanismRegistry = $LDAPGroupsSyncMechanismRegistry;
		$this->LDAPUserInfoModifierRegistry = $LDAPUserInfoModifierRegistry;
		$this->excludedUsernames = $excludedUsernames;
		$this->excludedGroups = $excludedGroups;
		$this->logger = $logger;
		$this->loadBalancer = $loadBalancer;
		$this->context = $context;
		$this->domainConfigFactory = DomainConfigFactory::getInstance();
		$this->userDomainStore = new UserDomainStore( $this->loadBalancer );
	}

	/**
	 * @return Status
	 */
	public function sync() {
		try {
			$this->doSync();

			$results = [
				'addedUsersCount' => $this->addedUsersCount,
				'disabledUsersCount' => $this->disabledUsersCount,
				'addedUsersFailsCount' => $this->addedUsersFailsCount,
				'disabledUsersFailsCount' => $this->disabledUsersFailsCount
			];
			$this->logger->debug(
				'LDAPSyncAll completed.
				{addedUsersCount} users added;
				{disabledUsersCount} users disabled;
				{addedUsersFailsCount} users failed to add;
				{disabledUsersFailsCount} users failed to disable;',
				$results
			);
			$this->status = Status::newGood( $results );
		}
		catch ( Exception $ex ) {
			$this->status = Status::newFatal( $ex->getMessage() );
			$this->logger->error(
				'Error: `{message}`',
				[
					'message' => $ex->getMessage()
				]
			);
		}

		return $this->status;
	}

	protected function doSync() {
		$this->initClients();
		$this->syncLocalUserDB();
		$this->updateUserDomainStore();
		$this->syncAllUsersInfo();
		$this->syncAllUsersGroups();
	}

	private function initClients() {
		$factory = ClientFactory::getInstance();
		foreach ( $this->domains as $domain ) {
			$this->clients[$domain] = $factory->getForDomain( $domain );
		}
	}

	private function syncLocalUserDB() {
		$usersToAddOrEnable = [];
		$usersToDisable = [];
		foreach ( $this->domains as $domain ) {
			$userlistProvider = $this->makeUserListProvider( $domain );
			$usernames = $userlistProvider->getWikiUsernames();
			sort( $usernames );
			foreach ( $usernames as $username ) {
				$this->usernameDomainMap[$username] = $domain;
				if ( $this->shouldAddOrEnable( $username, $domain ) ) {
					$usersToAddOrEnable[] = $username;
				} else {
					$usersToDisable[] = $username;
				}
			}
		}

		$localUsers = $this->getLocalDBUsers();
		$localDBUsernames = array_keys( $localUsers );
		// This will only disable users that are actually in the LDAP, but not "local users"
		// TODO: fix
		$usersToDisableLocally = array_intersect( $usersToDisable, $localDBUsernames );

		foreach ( $usersToDisableLocally as $username ) {
			$userToDisable = $localUsers[$username];
			$this->disableUser( $userToDisable );
		}

		foreach ( $usersToAddOrEnable as $username ) {
			if ( isset( $localUsers[$username] ) ) {
				$userToAddOrEnable = $localUsers[$username];
			} else {
				$userToAddOrEnable = User::newFromName( $username );
			}
			if ( $userToAddOrEnable->getId() !== 0 ) {
				$this->maybeEnableUser( $userToAddOrEnable );
			} else {
				$this->addUser( $userToAddOrEnable );
			}
		}
	}

	private function updateUserDomainStore() {
		$this->logger->debug( 'Setting domains' );
		$localUsers = $this->getLocalDBUsers();
		foreach ( $this->usernameDomainMap as $username => $domain ) {
			$user = null;
			if ( isset( $localUsers[$username] ) ) {
				$user = $localUsers[$username];
			}
			if ( $user === null ) {
				continue;
			}
			if ( $user->isBlocked() ) {
				continue;
			}
			if ( $user->getId() !== 0 ) {
				$this->logger->debug(
					'Set domain `{domain} for `{username}`',
					[
						'username' => $user->getName(),
						'domain' => $domain
					]
				);
				$this->userDomainStore->setDomainForUser( $user, $domain );
			}
		}
	}

	private function syncAllUsersInfo() {
		$this->logger->debug( 'Syncing all user info' );
		$usersToSync = $this->getLocalDBUsers();
		foreach ( $usersToSync as $user ) {
			if ( $user->isBlocked() ) {
				continue;
			}
			$this->logger->debug(
				'Sync info for `{username}`',
				[
					'username' => $user->getName()
				]
			);
			$domain = $this->userDomainStore->getDomainForUser( $user );
			$this->syncUserInfo( $user, $domain );
		}
	}

	private function syncAllUsersGroups() {
		$this->logger->debug( 'Syncing all user groups' );
		$usersToSync = $this->getLocalDBUsers();
		foreach ( $usersToSync as $user ) {
			if ( $user->isBlocked() ) {
				continue;
			}
			$this->logger->debug(
				'Sync groups for `{username}`',
				[
					'username' => $user->getName()
				]
			);
			$domain = $this->userDomainStore->getDomainForUser( $user );
			$this->syncUserGroups( $user, $domain );
		}
	}

	/**
	 *
	 * @param string $username
	 * @param string $domain
	 * @return boolean
	 */
	private function shouldAddOrEnable( $username, $domain ) {
		$domainConfig = $this->domainConfigFactory->factory(
			$domain,
			LDAPAuthorizationConfig::DOMAINCONFIG_SECTION
		);
		$client = $this->clients[$domain];
		$requirementsChecker = new RequirementsChecker( $client, $domainConfig );

		$result = $requirementsChecker->allSatisfiedBy( $username );

		return $result;
	}

	/**
	 *
	 * @return User[]
	 */
	private function getLocalDBUsers() {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$res = $dbr->select( 'user', '*', '', __METHOD__ );
		$localUsers = [];
		foreach ( $res as $row ) {
			$localUser = User::newFromRow( $row );
			$localUsers[$localUser->getName()] = $localUser;
		}
		return $localUsers;
	}

	/**
	 *
	 * @param string $domain
	 * @return IUserListProvider
	 */
	private function makeUserListProvider( $domain ) {
		$config = $this->domainConfigFactory->factory(
			$domain,
			ClientConfig::DOMAINCONFIG_SECTION
		);

		// TODO: Make registry
		$userlistProvider = LdapToolsBackend::factory( $config );
		return $userlistProvider;
	}

	/**
	 * @param User $user
	 */
	private function addUser( $user ) {
		try {
			$this->logger->debug(
				'Add `{username}`',
				[
					'username' => $user->getName()
				]
			);
			$user->addToDatabase();
			$this->addedUsersCount++;
		} catch ( Exception $exception ) {
			$this->addedUsersFailsCount++;
			$this->logger->error(
				'User `{username}` creation error {message}',
				[
					'username' => $user->getName(),
					'message' => $exception->getMessage()
				]
			);
		}
	}

	/**
	 * @param User $user
	 */
	private function maybeEnableUser( $user ) {
		try {
			if ( $user->isBlocked() ) {
				$result = $user->getBlock()->delete();
				$this->logger->debug( 'Enabling `{username}`: {result}',
				[
					'username' => $user->getName(),
					'result' => $result ? 'OK' : 'FAIL'
				] );
			} else {
				$this->logger->debug( 'Enabling `{username}`: NOT DISABLED',
				[
					'username' => $user->getName(),
				] );
			}
		} catch ( Exception $exception ) {
			$this->addedUsersFailsCount++;
			$this->logger->error(
				'Error while enabling `{username}`: {message}',
				[
					'username' => $user->getName(),
					'message' => $exception->getMessage()
				]
			);
		}
	}

	/**
	 * @param User $user
	 * @param string $domain
	 */
	protected function syncUserGroups( User $user, $domain ) {
		$domainConfig = $this->domainConfigFactory->factory(
			$domain,
			LDAPGroupsConfig::DOMAINCONFIG_SECTION
		);
		$client = $this->clients[$domain];
		$process = new GroupSyncProcess(
			$user,
			$domainConfig,
			$client,
			$this->LDAPGroupsSyncMechanismRegistry
		);
		$process->run();
	}

	/**
	 * @param User $user
	 * @param string $domain
	 */
	protected function syncUserInfo( User $user, $domain ) {
		$domainConfig = $this->domainConfigFactory->factory(
			$domain,
			LDAPUserInfoConfig::DOMAINCONFIG_SECTION
		);
		$client = $this->clients[$domain];
		$process = new UserInfoSyncProcess(
			$user,
			$domainConfig,
			$client,
			$this->LDAPUserInfoModifierRegistry
		);
		$process->run();
	}

	/**
	 * @param User $user
	 */
	protected function disableUser( User $user ) {
		foreach ( $this->excludedGroups as $group ) {
			if ( UserGroupMembership::getMembership( $user->getId(), $group ) ) {
				return;
			}
		}

		if ( $user->isBlocked() ) {
			return;
		}

		$data = [
			'PreviousTarget' => $user->getName(),
			'Target' => $user->getName(),
			'Reason' => [
				wfMessage( 'user-is-not-in-ad-block-reason' )->plain()
			],
			'Expiry' => 'infinity',
			'HardBlock' => false,
			'CreateAccount' => false,
			'AutoBlock' => true,
			'DisableEmail' => false,
			'HideUser' => false,
			'DisableUTEdit' => true,
			'Reblock' => false,
			'Watch' => false,
			'Confirm' => '',
			'Tags' => [ 'ldap' ],
		];

		$result = SpecialBlock::processForm( $data, $this->context );
		if ( $result === true ) {
			$this->disabledUsersCount++;
		} else {
			$this->disabledUsersFailsCount++;
			$this->logger->error(
				'Error while disabling user {username}: {message}',
				[
					'username' => $user->getName(),
					'message' => $result
				]
			);
		}
		$this->logger->debug(
			'Disabling `{username}`: {result}',
			[
				'username' => $user->getName(),
				'result' => $result ? 'OK' : 'FAIL'
			]
		);
	}
}
