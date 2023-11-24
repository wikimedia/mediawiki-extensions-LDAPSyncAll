<?php

namespace LDAPSyncAll\Hook\ChangeTagsAllowedAdd;

use MediaWiki\ChangeTags\Hook\ChangeTagsAllowedAddHook;

class AddLdapTag implements ChangeTagsAllowedAddHook {

	/**
	 * @inheritDoc
	 */
	public function onChangeTagsAllowedAdd( &$allowedTags, $addTags, $user ) {
		$allowedTags[] = 'ldap';
	}
}
