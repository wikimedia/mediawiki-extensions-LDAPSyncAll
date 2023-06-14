<?php

namespace LDAPSyncAll\UserSyncMechanism;

use Config;
use Exception;
use IContextSource;
use LDAPSyncAll\IUserListProvider;
use LDAPSyncAll\UsersSyncDAO;
use LDAPSyncAll\UsersSyncMechanism;
use LDAPSyncAll\UsersSyncUtils;
use MediaWiki\Extension\LDAPProvider\DomainConfigFactory;
use MediaWiki\Extension\LDAPProvider\UserDomainStore;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;
use Status;
use Wikimedia\Rdbms\LoadBalancer;

class DisableUsersSyncMechanism extends UsersSyncMechanism {

	/**
	 * UsersSyncMechanism constructor.
	 * @param string[] $domains
	 * @param array $excludedUsernames
	 * @param array $excludedGroups
	 * @param LoggerInterface $logger
	 * @param LoadBalancer $loadBalancer
	 * @param IContextSource $context
	 * @param UsersSyncUtils $utils
	 * @param UsersSyncDAO $dao
	 * @param IUserListProvider[] $domainUserListProviders
	 */
	public function __construct(
		array $domains,
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

	public function sync() {
		try {
			$this->doSync();

			$results = [
				'disabledUsersCount' => $this->disabledUsersCount,
				'disabledUsersFailsCount' => $this->disabledUsersFailsCount
			];
			$this->logger->debug(
				'LDAPSyncAll completed.
				{disabledUsersCount} users disabled;
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
			$config->get( 'LDAPSyncAllExcludedUsernames' ),
			$config->get( 'LDAPSyncAllExcludedGroups' ),
			LoggerFactory::getInstance( 'LDAPSyncAll' ),
			$loadBalancer,
			$context,
			$utils,
			new UsersSyncDAO( $loadBalancer ),
			$domainUserListProviders
		);
	}

	private function doSync() {
		$ldapUsers = [];
		$userFactory = MediaWikiServices::getInstance()->getUserFactory();
		foreach ( $this->domains as $domain ) {
			$usernames = $this->domainUserListProviders[$domain]->getWikiUsernames();
			sort( $usernames );
			foreach ( $usernames as $username ) {
				$ldapUsers[$username] = $userFactory->newFromName( $username );
			}
		}

		$localUsers = $this->dao->getLocalDBUsers();

		$usersToDisable = array_diff_key( $localUsers, $ldapUsers );

		foreach ( $usersToDisable as $userToDisable ) {
			$this->disableLocalUser( $userToDisable );
		}
	}

}
