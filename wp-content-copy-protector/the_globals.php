<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
//Get The Plugins URL as http://www.yrsite.com/dir/subdir
	$wccp_free_pluginsurl = plugins_url( '', __FILE__ );
	if(is_ssl())
	{
		$wccp_free_pluginsurl = str_replace("https:", "http:", $wccp_free_pluginsurl);// just to make sure that there is no https there
		$wccp_free_pluginsurl = str_replace("http:", "https:", $wccp_free_pluginsurl);
	}
?>