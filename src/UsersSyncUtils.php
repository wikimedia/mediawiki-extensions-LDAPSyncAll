<?php

namespace LDAPSyncAll;

use IContextSource;
use LDAPSyncAll\UserListProvider\LdapToolsBackend;
use MediaWiki\Extension\LDAPProvider\ClientConfig;
use MediaWiki\Extension\LDAPProvider\DomainConfigFactory;
use SpecialBlock;
use User;
use UserGroupMembership;

class UsersSyncUtils {

	/**
	 * @var IContextSource
	 */
	private $context;

	/**
	 * @param IContextSource $context
	 */
	public function __construct( IContextSource $context ) {
		$this->context = $context;
	}

	/**
	 * @param string $domain
	 * @return IUserListProvider
	 */
	public function makeUserListProvider( $domain ): IUserListProvider {
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
	 * @return bool|array
	 */
	public function disableUser( User $user ) {
		$data = [
			'PreviousTarget' => $user->getName(),
			'Target' => $user->getName(),
			'Reason' => [
				wfMessage( 'user-is-not-in-ad-block-reason' )->plain()
			],
			'Expiry' => 'infinity',
			'HardBlock' => true,
			'CreateAccount' => false,
			'AutoBlock' => false,
			'DisableEmail' => false,
			'HideUser' => false,
			'DisableUTEdit' => true,
			'Reblock' => false,
			'Watch' => false,
			'Confirm' => '',
			'Tags' => [ 'ldap' ],
		];

		return SpecialBlock::processForm( $data, $this->context );
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
			$userGroupMemberships = UserGroupMembership::getMembershipsForUser( $userId );
			$this->userGroups[$userId] = array_keys( $userGroupMemberships );
		}

		return in_array( $groupName, $this->userGroups[$userId] );
	}

}
