<?php

/**
 * Modifies the request_uri to avoid the plugin redirecting pages when used with query vars.
 */
class wbz404_Bypass_404_Redirect {

	private $uri = '';

	/**
	 * Class constructor.
	 */
	public function __construct(){
		add_action( 'template_redirect', array( $this, 'strip' ), 9998 );
		add_action( 'template_redirect', array( $this, 'restore' ), 10000 );
	}

	/**
	 * Strip the query vars from the REQUEST_URI.
	 */
	public function strip(){
		$this->uri = $_SERVER['REQUEST_URI'];
		$_SERVER['REQUEST_URI'] = preg_replace( '/\?.*/', '', $_SERVER['REQUEST_URI'] );
	}

	/**
	 * Add original REQUEST_URI back in.
	 */
	public function restore(){
		$_SERVER['REQUEST_URI'] = $this->uri;
	}

}
new wbz404_Bypass_404_Redirect();
