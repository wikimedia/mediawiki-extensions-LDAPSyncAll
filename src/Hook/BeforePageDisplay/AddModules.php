<?php

namespace LDAPSyncAll\Hook\BeforePageDisplay;

use BlueSpice\Hook\BeforePageDisplay;

class AddModules extends BeforePageDisplay {

	protected function doProcess() {
		$this->out->addModuleStyles( 'ext.lDAPSyncAll.styles' );
		$this->out->addModules( 'ext.lDAPSyncAll' );

		return  true;
	}
}
