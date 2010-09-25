=== SharDB ===
Contributors: wpmuguru
Tags: database, sharding, multiple, wpmu 
Requires at least: 2.7.1
Tested up to: 3.0.1
Stable tag: 2.7.4

Implements a MD5 hash based multiple database sharding structure for WordPress MU blog tables.

== Description ==

This is intended for larger WordPress MU or WordPress 3.0 Network installs using an existing 1-3 character MD5 hash (by blog id) based multi-DB sharding structure. It supports 16, 256 or 4096 database shards. It also supports a separate database for blog id 1 and multiple VIP databases (home & VIP code contribution by Luke Poland).

It has been tested with over 50 plugins including BuddyPress 1.1 through 1.2.5.2. I have not found any issues with any of the tested plugins. It should support any plugin that works with (and accesses all data via) the regular WordPress database code. 

It has been used to power MU version 2.7.1 through WordPress 3.0 sites and upgrade sites from 2.7.1 through to the WordPress 3.0.

This plugin is based on [HyperDB](http://wordpress.org/extend/plugins/hyperdb) which is the database plugin used by [WordPress.com](http://wordpress.com/). Like HyperDB, this plugin autodetects whether a query is requesting a global or blog table. 

== Installation ==

1. The database configuration instructions are at the top of db-settings.php. The configuration instructions assume that:
	1. the DB_ defines in wp-config.php are the connection details for your global database. If not, then modify line 91 of db-settings (add_db_server('global', ...) to the connection details for your global database.
	2. your databases all have the same database server, user & password. 
	3. your blog shard databases are named according to the same prefix and a suffix of md5 hash, home or vipX.
2. Instructions for adding VIP databases are at the bottom of db-settings.php.
3. Once finished editing db-setting.php upload it to the web root of your WPMU install.
4. Edit your wp-config.php and add the following line after the database settings are defined:
	require_once('db-settings.php');
5. upload db.php to /wp-content/.
6. upload shardb-admin.php to /wp-content/mu-plugins/.

== Screenshots ==

1. Site admin blogs screen showing dataset / partition for each blog.

== Changelog ==

= 2.7.4 =
* Added 3.0.X support.
* Fixed warning.

= 2.7.3 =
* Added 2.9.1.1 comment meta support.

= 2.7.2 =
* Added dataset / partition to site admin blogs screen.

= 2.7.1 =
* Original version.

