<?php
/*
 * Copyright � 2008-2012 Garrett Brown <http://www.mediawiki.org/wiki/User:Gbruin>
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License along
 * with this program. If not, see <http://www.gnu.org/licenses/>.
 */


/*
 * Not a valid entry point, skip unless MEDIAWIKI is defined.
 */
if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}


/**
 * Class FacebookAPI
 * 
 * This class is a thin wrapper for the Facebook PHP SDK. It encapsulates the
 * initialization of the library and provides a method to verify that this
 * extension was configured properly.
 */
class FacebookAPI extends Facebook {
	// Constructor
	public function __construct() {
		global $wgFbAppId, $wgFbSecret;
		// Check to make sure config.default.php was renamed properly, unless we
		// are running update.php from the command line
		// TODO: use $wgCommandLineMode, if it is propper to do so
		if ( !defined( 'MW_CMDLINE_CALLBACK' ) && !$this->isConfigSetup() ) {
			die ( '<strong>Please update $wgFbAppId and $wgFbSecret.</strong>' );
		}
		$config = array(
			'appId'      => $wgFbAppId,
			'secret'     => $wgFbSecret,
			'fileUpload' => false, // optional
		);
		parent::__construct( $config );
	}
	
	/**
	 * Check to make sure config.sample.php was properly renamed to config.php
	 * and the instructions to fill out the first two important variables were
	 * followed correctly.
	 */
	public function isConfigSetup() {
		global $wgFbAppId, $wgFbSecret;
		$isSetup = isset( $wgFbAppId ) && $wgFbAppId != 'YOUR_APP_KEY' &&
		           isset( $wgFbSecret ) && $wgFbSecret != 'YOUR_SECRET';
		if( !$isSetup ) {
			// Check to see if they are still using the old variables
			global $fbApiKey, $fbApiSecret;
			if ( isset( $fbApiKey ) ) {
				$wgFbAppId = $fbApiKey;
			}
			if ( isset( $fbApiSecret ) ) {
				$wgFbSecret= $fbApiSecret;
			}
			$isSetup = isset( $wgFbAppId ) && $wgFbAppId != 'YOUR_APP_KEY' &&
		               isset( $wgFbSecret ) && $wgFbSecret != 'YOUR_SECRET';
		}
		return $isSetup;
	}
	
	/*
	 * Publish message on Facebook wall.
	 */
	public function publishStream( $href, $description, $short, $link, $img ) {
		/*
		// Retrieve the message and substitute the params for the actual values
		$msg = wfMsg( $message_name ) ;
		foreach ($params as $key => $value) {
		 	$msg = str_replace($key, $value, $msg);
		}
		// If $FB_NAME isn't provided, simply blank it out
		$msg = str_replace('$FB_NAME', '', $msg);
		
		/**/
		$attachment = array(
			'name' => $link,
			'href' => $href,
			'description' => $description,
			'media' => array(array(
				'type' => 'image',
				'src' => $img,
				'href' => $href,
			)),
		);
		/*
		if( count( $media ) > 0 ) {
			foreach ( $media as $value ) {
				$attachment['media'][] = $value;
			}
		}
		/**/
		
		$query = array(
			'method' => 'stream.publish',
			'message' => $short,
			'attachment' => json_encode( $attachment ),
			/*
			'action_links' => json_encode( array(
				'text' => $link_title,
				'href' => $link
			)),
			/**/
		);
		
		// Submit the query and decode the result
		$result = json_decode( $this->api( $query ) );
		
		if ( is_array( $result ) ) {
			// Error
			#error_log( FacebookAPIErrorCodes::$api_error_descriptions[$result] );
			error_log( "stream.publish returned error code $result->error_code" );
			return $result->error_code;
		}
		else if ( is_string( $result ) ) {
			// Success! Return value is "$UserId_$PostId"
			return 0;
		} else {
			error_log( 'stream.publish: Unknown return type: ' . gettype( $result ) );
			return -1;
		}
	}
}
