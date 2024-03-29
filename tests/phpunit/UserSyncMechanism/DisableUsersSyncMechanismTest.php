<?php

namespace LDAPSyncAll\Tests\UserSyncMechanism;

use LDAPSyncAll\IUserListProvider;
use LDAPSyncAll\UsersSyncDAO;
use LDAPSyncAll\UsersSyncUtils;
use LDAPSyncAll\UserSyncMechanism\DisableUsersSyncMechanism;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RequestContext;
use User;
use Wikimedia\Rdbms\LoadBalancer;

/**
 * @covers \LDAPSyncAll\UserSyncMechanism\DisableUsersSyncMechanism
 */
class DisableUsersSyncMechanismTest extends TestCase {

	/**
	 * @var LoggerInterface
	 */
	private $loggerMock;

	/**
	 * @var LoadBalancer
	 */
	private $loadBalancerMock;

	/**
	 * @var RequestContext
	 */
	private $contextMock;

	/**
	 * @var string[]
	 */
	private $domains;

	protected function setUp(): void {
		$this->loggerMock = $this->createMock( LoggerInterface::class );
		$this->loadBalancerMock = $this->createMock( LoadBalancer::class );

		$userMock = $this->createMock( User::class );
		$userMock->method( 'getName' )->willReturn( 'BSMaintenance' );

		$this->contextMock = $this->createMock( RequestContext::class );
		$this->contextMock->method( 'getUser' )->willReturn( $userMock );

		$this->domains = [ 'LDAP' ];
	}

	/**
	 * @covers \LDAPSyncAll\UserSyncMechanism\DisableUsersSyncMechanism::sync()
	 * @dataProvider provideSyncData
	 */
	public function testDisable( $localUserNames, $activeDirectoryUsers, $disabledUsers ) {
		$userListProviderMock = $this->createMock( IUserListProvider::class );
		$userListProviderMock->method( 'getWikiUsernames' )->willReturn( $activeDirectoryUsers );

		$domainUserListProviders = [
			'LDAP' => $userListProviderMock
		];

		$localUsers = [];
		foreach ( $localUserNames as $userName ) {
			$localUsers[ $userName ] = User::newFromName( $userName );
		}

		$daoMock = $this->createMock( UsersSyncDAO::class );
		$daoMock->method( 'getLocalDBUsers' )->willReturn( $localUsers );

		$utilMock = $this->createMock( UsersSyncUtils::class );

		$disabledUsersAmount = count( $disabledUsers );
		$utilMock->expects( $this->exactly( $disabledUsersAmount ) )->method( 'disableUser' );

		for ( $i = 0; $i < $disabledUsersAmount; $i++ ) {
			$utilMock->expects( $this->at( $i ) )->method( 'disableUser' )->with( $disabledUsers[$i] );
		}

		$usersSyncMechanism = new DisableUsersSyncMechanism(
			$this->domains,
			[],
			[],
			$this->loggerMock,
			$this->loadBalancerMock,
			$this->contextMock,
			$utilMock,
			$daoMock,
			$domainUserListProviders
		);

		$usersSyncMechanism->sync();
	}

	public function provideSyncData(): array {
		return [
			'no-users-disable' => [
				[ 'TestUser1', 'TestUser2' ],
				[ 'TestUser1', 'TestUser2' ],
				[]
			],
			'one-user-disable' => [
				[ 'TestUser1' ],
				[ 'TestUser2', 'TestUser3' ],
				[ 'TestUser1' ]
			],
			'two-users-disable' => [
				[ 'TestUser1', 'TestUser2' ],
				[ 'TestUser3', 'TestUser4', 'TestUser5' ],
				[ 'TestUser1', 'TestUser2' ]
			]
		];
	}

}
