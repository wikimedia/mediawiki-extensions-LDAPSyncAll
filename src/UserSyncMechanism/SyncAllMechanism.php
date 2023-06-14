<?php

namespace LDAPSyncAll\UserSyncMechanism;

use CommentStoreComment;
use Config;
use ContentHandler;
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
use MediaWiki\Revision\SlotRecord;
use MWException;
use Psr\Log\LoggerInterface;
use RuntimeException;
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
	 * Content of user wiki page, which will be created in case of adding new user from Active Directory
	 *
	 * @var string
	 */
	protected $userPageContent = '';

	/**
	 * UsersSyncMechanism constructor.
	 * @param string[] $domains
	 * @param array $LDAPGroupsSyncMechanismRegistry
	 * @param array $LDAPUserInfoModifierRegistry
	 * @param array $excludedUsernames
	 * @param array $excludedGroups
	 * @param string $userPageContent
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
		string $userPageContent,
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
		$this->userPageContent = $userPageContent;
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

		$userPageContent = $config->get( 'LDAPSyncAllUserPageContent' );

		// If there is "CreateUserPage" extension enabled - we should use page content from it
		if ( !empty( $GLOBALS['wgCreateUserPage_PageContent'] ) ) {
			$userPageContent = $GLOBALS['wgCreateUserPage_PageContent'];
		}

		return new self(
			$domains,
			$config->get( 'LDAPGroupsSyncMechanismRegistry' ),
			$config->get( 'LDAPUserInfoModifierRegistry' ),
			$config->get( 'LDAPSyncAllExcludedUsernames' ),
			$config->get( 'LDAPSyncAllExcludedGroups' ),
			$userPageContent,
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
		} catch ( Exception $ex ) {
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
		foreach ( $this->domains as $domain ) {
			$userListProvider = $this->utils->makeUserListProvider( $domain );
			$usernames = $userListProvider->getWikiUsernames();
			sort( $usernames );
			foreach ( $usernames as $username ) {
				$this->usernameDomainMap[$username] = $domain;
				if ( $this->shouldAddOrEnable( $username, $domain ) ) {
					$usersToAddOrEnable[] = $username;
				}
			}
		}

		$localUsers = $this->dao->getLocalDBUsers();
		$localDBUsernames = array_keys( $localUsers );
		$usersToDisableLocally = array_diff( $localDBUsernames, $usersToAddOrEnable );

		foreach ( $usersToDisableLocally as $username ) {
			$userToDisable = $localUsers[$username];
			$this->disableLocalUser( $userToDisable );
		}

		$userFactory = MediaWikiServices::getInstance()->getUserFactory();
		foreach ( $usersToAddOrEnable as $username ) {
			if ( isset( $localUsers[$username] ) ) {
				$userToAddOrEnable = $localUsers[$username];
			} else {
				$userToAddOrEnable = $userFactory->newFromName( $username );
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
			if ( $user->getBlock() ) {
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
			if ( $user->getBlock() ) {
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
			if ( $user->getBlock() ) {
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
			$this->createUserPage( $user );

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
	 * Creates user page for specified user.
	 *
	 * @param User $user User who user page is created for
	 * @return void
	 */
	private function createUserPage( User $user ) {
		$title = $user->getUserPage();

		if ( $title->exists() ) {
			$this->logger->debug( 'User page {title} already exists', [
				'title' => $title->getFullText()
			] );
			return;
		}

		$contentHandler = ContentHandler::makeContent( $this->userPageContent, $title );
		$wikipage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );

		$updater = $wikipage->newPageUpdater( User::newSystemUser( 'MediaWiki default' ) );
		$updater->setContent( SlotRecord::MAIN, $contentHandler );

		$commentStore = CommentStoreComment::newUnsavedComment( 'LDAPSyncAll' );

		try {
			$result = $updater->saveRevision( $commentStore, EDIT_NEW );
		} catch ( MWException | RuntimeException $e ) {
			$this->logger->error( 'User page creation exception: ' . $e->getMessage() );
		}

		if ( $result === null || !$updater->wasSuccessful() ) {
			$status = $updater->getStatus();

			if ( $status->getErrors() ) {
				// If status is okay but there are errors - they are not fatal, just warnings
				if ( $status->isOK() ) {
					$this->logger->warning( 'User page creation warning: ' . $status->getMessage() );
				} else {
					$this->logger->error( 'User page creation error: ' . $status->getMessage() );
				}
			}
		} else {
			$this->logger->debug( 'User page is created for user: ' . $user->getName() );
		}
	}

	/**
	 * @param User $user
	 */
	private function maybeEnableUser( $user ) {
		try {
			$block = $user->getBlock();
			if ( $block ) {
				$result = $block->delete();
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
