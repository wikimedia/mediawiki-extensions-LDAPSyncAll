# LDAPSyncAll

## Installation
Execute

    composer require hallowelt/ldapsyncall dev-REL1_31
within MediaWiki root or add `hallowelt/ldapsyncall` to the
`composer.json` file of your project

## Activation
Add

    wfLoadExtension( 'LDAPSyncAll' );
to your `LocalSettings.php` or the appropriate `settings.d/` file.