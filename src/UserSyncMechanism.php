<?php
namespace LDAPSyncAll;

use MediaWiki\Extension\LDAPProvider\Client;
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
	 * UserSyncMechanism constructor.
	 * @param Client $ldapClient
	 * @param LoggerInterface $logger
	 * @param LoadBalancer $loadBalancer
	 */
	public function __construct( Client $ldapClient, LoggerInterface $logger, LoadBalancer $loadBalancer ) {
		$this->ldapClient = $ldapClient;
		$this->logger = $logger;
		$this->loadBalancer = $loadBalancer;
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

	}

	protected function getUsersFromLDAP() {
		$users = $this->ldapClient->search(
			"(&(objectClass=User)(objectCategory=Person))"
		);


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
}