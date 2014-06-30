<?php
/*
Plugin Name: Smart eCart Payment Processor - VirtualMerchant
Version: 1
Plugin URI: http://sproutventure.com/wordpress/smart-ecart
Description: VirtualMerchant Add-on.
Author: Sprout Venture
Author URI: http://sproutventure.com/wordpress
Plugin Author: Dan Cameron
Contributors: Dan Cameron
Text Domain: group-buying
Domain Path: /lang
*/

add_action('gb_register_processors', 'sec_load_vm');

function sec_load_vm() {
	require_once('secVirtualMerchant.class.php');
}