<?php

namespace LDAPSyncAll;

use User;
use Wikimedia\Rdbms\LoadBalancer;

class UsersSyncDAO {

	/**
	 * @var LoadBalancer
	 */
	private $loadBalancer;

	/**
	 * @param LoadBalancer $loadBalancer
	 */
	public function __construct( LoadBalancer $loadBalancer ) {
		$this->loadBalancer = $loadBalancer;
	}

	/**
	 * @return User[]
	 */
	public function getLocalDBUsers(): array {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$res = $dbr->select( 'user', '*', '', __METHOD__ );
		$localUsers = [];
		foreach ( $res as $row ) {
			$localUser = User::newFromRow( $row );
			$localUsers[$localUser->getName()] = $localUser;
		}
		return $localUsers;
	}

}
