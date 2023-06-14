<?php

namespace LDAPSyncAll;

use Config;
use IContextSource;
use MediaWiki\Extension\LDAPProvider\Client;
use MediaWiki\Extension\LDAPProvider\DomainConfigFactory;
use MediaWiki\Extension\LDAPProvider\UserDomainStore;
use Psr\Log\LoggerInterface;
use Status;
use User;
use Wikimedia\Rdbms\LoadBalancer;

abstract class UsersSyncMechanism {

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
	 * @var IContextSource
	 */
	protected $context;

	/**
	 * @var DomainConfigFactory
	 */
	protected $domainConfigFactory = null;

	/**
	 * @var array
	 */
	protected $usernameDomainMap = [];

	/**
	 * @var UserDomainStore
	 */
	protected $userDomainStore = null;

	/**
	 *
	 * @var Client[]
	 */
	protected $clients = null;

	/**
	 * @var UsersSyncUtils
	 */
	protected $utils;

	/**
	 * @var UsersSyncDAO
	 */
	protected $dao;

	/**
	 * @var IUserListProvider[]
	 */
	protected $domainUserListProviders;

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
	 * @return Status
	 */
	abstract public function sync();

	/**
	 * @param Config $config
	 * @param IContextSource $context
	 * @return UsersSyncMechanism
	 */
	abstract public static function factory( Config $config, IContextSource $context ): UsersSyncMechanism;

	/**
	 *
	 * @param User $user
	 * @return void
	 */
	protected function disableLocalUser( User $user ) {
		if ( $user->getBlock() ) {
			return;
		}
		if ( in_array( $user->getName(), $this->excludedUsernames ) ) {
			$this->logger->info( 'User "{username}" excluded by "ExcludedUsernames" configuration', [
				'username' => $user->getName()
			] );
			return;
		}
		foreach ( $this->excludedGroups as $group ) {
			if ( $this->utils->isInGroup( $user, $group ) ) {
				$this->logger->info( 'User "{username}" excluded by "ExcludedGroups" configuration', [
					'username' => $user->getName()
				] );
				return;
			}
		}

		$result = $this->utils->disableUser( $user );
		if ( $result === true ) {
			$this->disabledUsersCount++;
		} else {
			$this->disabledUsersFailsCount++;
			$this->logger->error(
				'Error while disabling user "{username}": {message}',
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
