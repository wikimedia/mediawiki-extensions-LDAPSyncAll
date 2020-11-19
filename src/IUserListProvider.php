<?php

namespace LDAPSyncAll;

interface IUserListProvider {

	/**
	 *
	 * @return string[]
	 */
	public function getWikiUsernames();
}
