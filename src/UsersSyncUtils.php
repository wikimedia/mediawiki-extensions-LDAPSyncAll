<?php

namespace LDAPSyncAll;

use LDAPSyncAll\UserListProvider\LdapToolsBackend;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\LDAPProvider\ClientConfig;
use MediaWiki\Extension\LDAPProvider\DomainConfigFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use Status;

class UsersSyncUtils {

	/**
	 * @var IContextSource
	 */
	private $context;

	/**
	 *
	 * @var UserGroupManager
	 */
	private $groupManager;

	/**
	 * @param IContextSource $context
	 * @param UserGroupManager $groupManager
	 */
	public function __construct( IContextSource $context, $groupManager ) {
		$this->context = $context;
		$this->groupManager = $groupManager;
	}

	/**
	 * @param string $domain
	 * @return IUserListProvider
	 */
	public function makeUserListProvider( string $domain ): IUserListProvider {
		$config = DomainConfigFactory::getInstance()->factory(
			$domain,
			ClientConfig::DOMAINCONFIG_SECTION
		);

		// TODO: Make registry
		$userlistProvider = LdapToolsBackend::factory( $config );
		return $userlistProvider;
	}

	/**
	 * @param User $user
	 * @return Status
	 */
	public function disableUser( User $user ) {
		$blockUser = MediaWikiServices::getInstance()->getBlockUserFactory()
			->newBlockUser(
				$user->getName(),
				$this->context->getAuthority(),
				'infinity',
				wfMessage( 'user-is-not-in-ad-block-reason' )->plain(),
				[
					'isHardBlock' => true,
					'isCreateAccountBlocked' => false,
					'isAutoblocking' => false,
					'isEmailBlocked' => false,
					'isHideUser' => false,
					'isUserTalkEditBlocked' => true
				],
				[],
				[ 'ldap' ]
			);

		return $blockUser->placeBlock();
	}

	/**
	 *
	 * @var array
	 */
	private $userGroups = [];

	/**
	 *
	 * @param User $user
	 * @param string $groupName
	 * @return bool
	 */
	public function isInGroup( $user, $groupName ) {
		$userId = $user->getId();
		if ( !isset( $this->userGroups[$userId] ) ) {
			$userGroupMemberships = $this->groupManager->getUserGroupMemberships( $user );
			$this->userGroups[$userId] = array_keys( $userGroupMemberships );
		}

		return in_array( $groupName, $this->userGroups[$userId] );
	}

}
