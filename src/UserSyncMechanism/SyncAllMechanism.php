<?php

namespace LDAPSyncAll\UserSyncMechanism;

use Config;
use Exception;
use IContextSource;
use LDAPSyncAll\UsersSyncDAO;
use LDAPSyncAll\UsersSyncMechanism;
use LDAPSyncAll\UsersSyncUtils;
use MediaWiki\Extension\LDAPAuthorization\Config as LDAPAuthorizationConfig;
use MediaWiki\Extension\LDAPAuthorization\RequirementsChecker;
use MediaWiki\Extension\LDAPGroups\Config as LDAPGroupsConfig;
use MediaWiki\Extension\LDAPGroups\GroupSyncProcess;
use MediaWiki\Extension\LDAPProvider\ClientFactory;
use MediaWiki\Extension\LDAPProvider\DomainConfigFactory;
use MediaWiki\Extension\LDAPProvider\UserDomainStore;
use MediaWiki\Extension\LDAPUserInfo\Config as LDAPUserInfoConfig;
use MediaWiki\Extension\LDAPUserInfo\UserInfoSyncProcess;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;
use Status;
use User;
use Wikimedia\Rdbms\LoadBalancer;

class SyncAllMechanism extends UsersSyncMechanism {

	/**
	 * @var array null
	 */
	protected $LDAPGroupsSyncMechanismRegistry = null;

	/**
	 * @var array null
	 */
	protected $LDAPUserInfoModifierRegistry = null;

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
	 * @param UsersSyncUtils $utils
	 * @param UsersSyncDAO $dao
	 * @param array $domainUserListProviders
	 */
	public function __construct(
		array $domains,
		array $LDAPGroupsSyncMechanismRegistry,
		array $LDAPUserInfoModifierRegistry,
		array $excludedUsernames,
		array $excludedGroups,
		LoggerInterface $logger,
		LoadBalancer $loadBalancer,
		IContextSource $context,
		UsersSyncUtils $utils,
		UsersSyncDAO $dao,
		array $domainUserListProviders
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
		$this->utils = $utils;
		$this->dao = $dao;
		$this->domainUserListProviders = $domainUserListProviders;
	}

	/**
	 * @inheritDoc
	 */
	public static function factory( Config $config, IContextSource $context ): UsersSyncMechanism {
		$domains = DomainConfigFactory::getInstance()->getConfiguredDomains();
		$loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$groupManager = MediaWikiServices::getInstance()->getUserGroupManager();

		$utils = new UsersSyncUtils( $context, $groupManager );

		$domainUserListProviders = [];
		foreach ( $domains as $domain ) {
			$domainUserListProviders[$domain] = $utils->makeUserListProvider( $domain );
		}

		return new self(
			$domains,
			$config->get( 'LDAPGroupsSyncMechanismRegistry' ),
			$config->get( 'LDAPUserInfoModifierRegistry' ),
			$config->get( 'LDAPSyncAllExcludedUsernames' ),
			$config->get( 'LDAPSyncAllExcludedGroups' ),
			LoggerFactory::getInstance( 'LDAPSyncAll' ),
			MediaWikiServices::getInstance()->getDBLoadBalancer(),
			$context,
			$utils,
			new UsersSyncDAO( $loadBalancer ),
			$domainUserListProviders
		);
	}

	/**
	 * @inheritDoc
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
			$userListProvider = $this->utils->makeUserListProvider( $domain );
			$usernames = $userListProvider->getWikiUsernames();
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

		$localUsers = $this->dao->getLocalDBUsers();
		$localDBUsernames = array_keys( $localUsers );
		// This will only disable users that are actually in the LDAP, but not "local users"
		// TODO: fix
		$usersToDisableLocally = array_intersect( $usersToDisable, $localDBUsernames );

		foreach ( $usersToDisableLocally as $username ) {
			$userToDisable = $localUsers[$username];
			$this->disableLocalUser( $userToDisable );
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
		$localUsers = $this->dao->getLocalDBUsers();
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
		$usersToSync = $this->dao->getLocalDBUsers();
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
			if ( $domain ) {
				$this->syncUserInfo( $user, $domain );
			}
		}
	}

	private function syncAllUsersGroups() {
		$this->logger->debug( 'Syncing all user groups' );
		$usersToSync = $this->dao->getLocalDBUsers();
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
			if ( $domain ) {
				$this->syncUserGroups( $user, $domain );
			}
		}
	}

	/**
	 *
	 * @param string $username
	 * @param string $domain
	 * @return bool
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
}
