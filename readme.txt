=== SharDB ===
Contributors: wpmuguru
Tags: database, sharding, multiple, database, multisite, network 
Requires at least: 3.0
Tested up to: 3.1
Stable tag: 2.7.5

Implements a MD5 hash based multiple database sharding structure for WordPress network blog tables.

== Description ==

This is intended for larger WordPress Network installs using an existing 1-3 character MD5 hash (by blog id) based multi-DB sharding structure. It supports 16, 256 or 4096 database shards. It also supports a separate database for blog id 1 and multiple VIP databases (home & VIP code contribution by Luke Poland).

It has been tested with over 50 plugins including BuddyPress 1.1 through 1.2.8. I have not found any issues with any of the tested plugins. It should support any plugin that works with (and accesses all data via) the regular WordPress database code. 

It has been used to power MU version 2.7.1 through WordPress 3.1 sites and upgrade sites from 2.7.1 through to the WordPress 3.1.

A detailed [installation guide](http://wpebooks.com/shardb-installation-guide/) can be purchased for a nominal fee. 

This plugin is based on [HyperDB](http://wordpress.org/extend/plugins/hyperdb) which is the database plugin used by [WordPress.com](http://wordpress.com/). Like HyperDB, this plugin autodetects whether a query is requesting a global or blog table. 

== Installation ==

1. The database configuration instructions are at the top of db-settings.php. The configuration instructions assume that:
	1. your databases all have the same database server, user & password. 
	2. your blog shard databases are named according to the same prefix and a suffix of md5 hash, global, home or vipX.
2. Instructions for adding VIP databases are at the bottom of db-settings.php.
3. Once finished editing db-setting.php upload it to the same folder as wp-config.php for your WordPress install.
4. Edit your wp-config.php and add the following line after the database settings are defined:
	require_once('db-settings.php');
5. upload shardb-admin.php to /wp-content/mu-plugins/.
6. Migrate your data to your DB shards using the SharDB migration screen under Tools on the main site. 
7. upload db.php to /wp-content/.

== Screenshots ==

1. Site admin blogs screen showing dataset / partition for each blog.

== Changelog ==

= 2.7.5 =
* Added 3.1.X support.
* Migration script.

= 2.7.4 =
* Added 3.0.X support.
* Fixed warning.

= 2.7.3 =
* Added 2.9.1.1 comment meta support.

= 2.7.2 =
* Added dataset / partition to site admin blogs screen.

= 2.7.1 =
* Original version.

