<?php

class WP_Better_HipChat_Event_Payload {

	/**
	 * @var array
	 */
	private $setting;
	
	private $colour;

	public function __construct( array $setting, $color ) {
		$this->setting = $setting;
		$this->colour = $color;
	}

	public function get_url() {
		return add_query_arg(
			array( 'auth_token' => $this->setting['auth_token'] ),
			sprintf( 'https://api.hipchat.com/v2/room/%s/notification', rawurlencode($this->setting['room'] ))
		);
	}

	public function toJSON() {
		return json_encode( array(
			'notify'  => true,
			'message' => $this->setting['message'],
			'color'  => $this->colour
		) );
	}
}
