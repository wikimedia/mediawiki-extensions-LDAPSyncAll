( function( mw, $, bs, undefined ) {

	function _someInternalFunction() {
		alert( 'Hallo Welt!' );
	}

	bs.registerNamespace( 'bs.lDAPSyncAll.util' );

	bs.lDAPSyncAll.util = {
		somePublicInterface: _someInternalFunction
	}

} )( mediaWiki, jQuery, blueSpice );