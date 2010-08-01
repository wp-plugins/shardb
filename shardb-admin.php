<?php
/*
Plugin Name: SharDB site admin utilities for WordPress MU 
Plugin URI: http://wpmututorials.com/plugins/shardb/
Description: A Multi-database plugin for WordPress MU
Version: 2.7.4
Author: Ron Rennick
Author URI: http://ronandandrea.com/
Donate link: http://wpmututorials.com/
*/
/* Copyright:	(C) 2009 Ron Rennick, All rights reserved.  
	Contributions by Luke Poland copyright:	(C) 2009 Luke Poland, All rights reserved.
	
*/
function shardb_get_ds_part_from_blog_id( $blog_id ) {
	global $shardb_hash_length, $shardb_dataset, $shardb_num_db, $vip_db, $shardb_prefix;
	
	if( !isset( $shardb_hash_length ) ) 
		return false;

	$dataset = $shardb_dataset; 
	$hash = substr( md5( $blog_id ), 0, $shardb_hash_length );
	$partition = hexdec( $hash );
// VIP Blog Check.
// Added by: Luke Poland
	if ( is_array( $vip_db ) && array_key_exists( $blog_id, $vip_db ) )
		$partition = $shardb_num_db + intval( $vip_db[ $blog_id ] );
// End VIP Addition
	return compact( 'dataset', 'hash', 'partition' );
}

// show dataset/partition on site admin blogs screen
function shardb_blog_columns( $columns ) {
	if( class_exists( 'db' ) )
		$columns[ 'shardb' ] = __( 'Dataset / Partition' );
	else
		remove_action( 'manage_blogs_custom_column', 'shardb_blog_field' );
	return $columns;
}
add_filter( 'wpmu_blogs_columns', 'shardb_blog_columns' );

function shardb_blog_field( $column, $blog_id ) {
	global $wpdb, $db_servers;
	
	if ( $column == 'shardb' ) {
		$ds_part = shardb_get_ds_part_from_blog_id( $blog_id );
		echo $ds_part[ 'dataset' ] . ' / ' . $db_servers[ $ds_part[ 'dataset' ] ][ $ds_part[ 'partition' ] ][ 0 ][ 'name' ];
	}
}
add_action( 'manage_blogs_custom_column', 'shardb_blog_field', 10, 3 );
?>
