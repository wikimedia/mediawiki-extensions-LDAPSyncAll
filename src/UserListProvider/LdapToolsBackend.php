<?php

namespace LDAPSyncAll\UserListProvider;

use Config;
use LDAPSyncAll\IUserListProvider;
use LDAPSyncAll\LdapTools\Log\MediaWikiLog;
use LdapTools\Configuration;
use LdapTools\LdapManager;
use LdapTools\Object\LdapObject;
use MediaWiki\Extension\LDAPProvider\ClientConfig;
use MediaWiki\Extension\LDAPProvider\EncType;
use MediaWiki\MediaWikiServices;

class LdapToolsBackend implements IUserListProvider {

	/**
	 *
	 * @var LdapManager
	 */
	private $ldapManager = null;

	/**
	 *
	 * @var string
	 */
	private $usernameAttribute = '';

	/**
	 *
	 * @param Config $connectionSettings
	 * @return IUserListProvider
	 */
	public static function factory( $connectionSettings ) {
		return new static( $connectionSettings );
	}

	/**
	 *
	 * @param Config $config
	 */
	public function __construct( $config ) {
		$ldapConfig = ( new Configuration() )->loadFromArray( [
			'domains' => [
				'dummy' => [
					'domain_name' => "dummy",
					'username' => $config->get( ClientConfig::USER ),
					'password' => $config->get( ClientConfig::PASSWORD ),
					'base_dn' => $config->get( ClientConfig::USER_BASE_DN ),
					'servers' => [ $config->get( ClientConfig::SERVER ) ],
					'port' => $config->get( ClientConfig::PORT ),
					'use_ssl' => $config->get( ClientConfig::ENC_TYPE ) === EncType::SSL,
					'use_tls' => $config->get( ClientConfig::ENC_TYPE ) === EncType::TLS
				]
			]
		] );
		$ldapConfig->setLogger( new MediaWikiLog() );
		$this->ldapManager = new LdapManager( $ldapConfig );
		$this->usernameAttribute = $config->get( ClientConfig::USERINFO_USERNAME_ATTR );
	}

	/**
	 *
	 * @return string[]
	 */
	public function getWikiUsernames() {
		// We use this kind of LDAP access to overcome "paging" issues with very large LDAP backends
		$query = $this->ldapManager
			->buildLdapQuery()
			->select( [ $this->usernameAttribute ] )
			->fromUsers()
			->getLdapQuery();

		/** @var LdapObject[] */
		$users = $query->execute();
		$usernames = [];
		foreach ( $users as $user ) {
			$ldapUsername = $user->get( $this->usernameAttribute );
			$usernames[] = $this->normalizeToWikiUsername( $ldapUsername );
		}
		return $usernames;
	}

	/**
	 *
	 * @param string $ldapUsername
	 * @return string
	 */
	private function normalizeToWikiUsername( $ldapUsername ) {
		$normalUsername = $ldapUsername;

		// This is very hacky! A generic "LDAPProviderUsernameNormalizer" must be created!
		$normalizerCallbacks = [];
		if ( isset( $GLOBALS['LDAPAuthorizationAutoAuthUsernameNormalizer'] ) ) {
			$normalizerCallbacks[] = $GLOBALS['LDAPAuthorizationAutoAuthUsernameNormalizer'];
		}
		if ( isset( $GLOBALS['LDAPAuthentication2UsernameNormalizer'] ) ) {
			$normalizerCallbacks[] = $GLOBALS['LDAPAuthentication2UsernameNormalizer'];
		}

		foreach ( $normalizerCallbacks as $normalizerCallback ) {
			if ( is_callable( $normalizerCallback ) ) {
				$normalUsername = call_user_func_array( $normalizerCallback, [ $normalUsername ] );
			}
		}

		$user = MediaWikiServices::getInstance()->getUserFactory()->newFromName( $normalUsername );
		return $user->getName();
	}
}
