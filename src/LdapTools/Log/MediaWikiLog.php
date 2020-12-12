<?php

namespace LDAPSyncAll\LdapTools\Log;

use LdapTools\Log\LdapLoggerInterface as LogLdapLoggerInterface;
use LdapTools\Log\LogOperation;
use LdapTools\Operation\CacheableOperationInterface;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

class MediaWikiLog implements LogLdapLoggerInterface {

	/**
	 *
	 * @var LoggerInterface
	 */
	private $logger = null;

	public function __construct() {
		$this->logger = LoggerFactory::getInstance( 'LDAPSyncAll' );
	}

	/**
	 * The start method is called against a LDAP operation prior to the operation being executed.
	 *
	 * @param LogOperation $operation
	 */
	public function start( LogOperation $operation ) {
		$message = "(" . $operation->getDomain() . ") -- Start Operation Type: "
			. $operation->getOperation()->getName() . PHP_EOL;

		foreach ( $operation->getOperation()->getLogArray() as $key => $value ) {
			$message .= "\t$key: $value" . PHP_EOL;
		}

		$this->logger->debug( $message );
	}

	/**
	 * The end method is called against a LDAP operation after the operation has finished executing.
	 *
	 * @param LogOperation $operation
	 */
	public function end( LogOperation $operation ) {
		$duration = $operation->getStopTime() - $operation->getStartTime();

		if ( $operation->getOperation() instanceof CacheableOperationInterface ) {
			echo "\tCache Hit: " . var_export( $operation->getUsedCachedResult(), true ) . PHP_EOL;
		}
		if ( $operation->getError() !== null ) {
			echo "\tError: " . $operation->getError() . PHP_EOL;
		}

		$message = "(" . $operation->getDomain() . ") -- End Operation Type: "
			. $operation->getOperation()->getName() . " -- ($duration seconds)" . PHP_EOL;

		$this->logger->debug( $message );
	}
}
