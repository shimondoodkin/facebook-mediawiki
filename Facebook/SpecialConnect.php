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


/**
 * Thrown when a FacebookUser or FacebookApplication encounters a problem.
 */
class FacebookModelException extends Exception
{
	protected $titleMsg;
	protected $textMsg;
	protected $msgParams;
	
	/**
	 * Make a new FacebookUser Exception with the given result.
	 */
	public function __construct($titleMsg, $textMsg, $msgParams = NULL) {
		$this->titleMsg  = $titleMsg;
		$this->textMsg   = $textMsg;
		$this->msgParams = $msgParams;
		
		// In general, $msg and $code are not meant to be used
		$msg = wfMsg( $this->titleMsg );
		$code = 0;
		
		parent::__construct($msg, $code);
	}
	
	public function getTitleMsg() {
		return $this->titleMsg;
	}
	
	public function getTextMsg() {
		return $this->textMsg;
	}
	
	public function getMsgParams() {
		return $this->msgParams;
	}
	
	public function getType() {
		return 'Exception';
	}
	
	public function __toString() {
		return wfMsg( $this->msg );
	}
}


/**
 * Class SpecialConnect
 * 
 * Special:Connect is where the magic of this extension takes place. All
 * authentication, adding and removing accounts happens through this special page
 * (with the small exception of FacebookHooks::UserLoadAfterLoadFromSession).
 * 
 * The entry point of this special page is execute($subPageName). Refer to the
 * documentation there for a description of how this special page operates.
 */
class SpecialConnect extends SpecialPage {
	/**
	 * Constructor. Invoke the super class's constructor with the default arguments.
	 */
	function __construct() {
		global $wgSpecialPageGroups;
		// Initiate SpecialPage's constructor
		parent::__construct( 'Connect' );
		// Add this special page to the "login" group of special pages
		$wgSpecialPageGroups['Connect'] = 'login';
	}
	
	/**
	 * Overrides getDescription() in SpecialPage. Looks in a different wiki message
	 * for this extension's description.
	 */
	function getDescription() {
		return wfMsg( 'facebook-title' );
	}
	
	/**
	 * Helper function. Always called when rendering this special page.
	 * 
	 * TODO: Don't return to special pages Connect, Userlogin and Userlogout
	 */
	private function setReturnTo() {
		global $wgRequest;
		
		$this->mReturnTo = $wgRequest->getVal( 'returnto' );
		$this->mReturnToQuery = $wgRequest->getVal( 'returntoquery' );
		
		/**
		 * Wikia BugId: 13709
		 * Before the fix, the logic and the usage of parse_str was wrong
		 * which had fatal side effects.
		 *
		 * The goal of the block below is to remove the fbconnected
		 * variable from the $this->mReturnToQuery (which is supposed
		 * to be a QUERY_STRING-like string.
		 */
		if( !empty($this->mReturnToQuery) ) {
			// A temporary array
			$aReturnToQuery = array();
			// Decompose the query string to the array
			parse_str( $this->mReturnToQuery, $aReturnToQuery );
			// Remove unwanted elements
			unset( $aReturnToQuery['fbconnected'] );
	
			//recompose the query string
			foreach ( $aReturnToQuery as $k => $v ) {
				$aReturnToQuery[$k] = "{$k}={$v}";
			}
			// Oh, parse_str implicitly urldecodes values which wasn't
			// mentioned in the PHP documentation.
			$this->mReturnToQuery = urlencode( implode( '&', $aReturnToQuery ) );
			// remove the temporary array
			unset( $aReturnToQuery );
		}
		
		// TODO: 302 redirect if returnto is a bad page
		$title = Title::newFromText($this->mReturnTo);
		if ($title instanceof Title) {
			$this->mResolvedReturnTo = strtolower(SpecialPage::resolveAlias($title->getDBKey()));
			if (in_array( $this->mResolvedReturnTo, array('userlogout', 'signup', 'connect') )) {
				$titleObj = Title::newMainPage();
				$this->mReturnTo = $titleObj->getText();
				$this->mReturnToQuery = '';
			}
		}
	}
	
	/**
	 * The controller interacts with the views through these three functions.
	 */
	public function sendPage($function, $arg = NULL) {
		global $wgOut;
		// Setup the page for rendering
		wfLoadExtensionMessages( 'Facebook' );
		$this->setHeaders();
		$wgOut->disallowUserJs();  # just in case...
		$wgOut->setRobotPolicy( 'noindex,nofollow' );
		$wgOut->setArticleRelated( false );
		// Call the specified function to continue generating the page
		if (is_null($arg)) {
			$this->$function();
		} else {
			$this->$function($arg);
		}
	}
	
	protected function sendError($titleMsg, $textMsg, $msgParams = NULL) {
		global $wgOut;
		// Special case: $titleMsg is a list of permission errors
		if ( is_array( $titleMsg ) )
			$wgOut->showPermissionsErrorPage( $titleMsg, $textMsg );
		// Special case: read only error, all parameters are null
		else if ( is_null( $titleMsg ) && is_null( $textMsg ) )
 			$wgOut->readOnlyPage();
		// General cases: normal error page
		else if ($msgParams)
 			$wgOut->showErrorPage($titleMsg, $textMsg, $msgParams);
		else
			$wgOut->showErrorPage($titleMsg, $textMsg);
	}
	
	protected function sendRedirect($specialPage) {
		global $wgOut, $wgUser, $wgFbDisableLogin;
		
		// $wgFbDisableLogin disables UserLogin page. Avoid infinite redirects.
		if ( !empty( $wgFbDisableLogin ) && $specialPage == 'UserLogin' ) {
			$this->sendPage('exclusiveLoginToFacebookView');
			return;
		}
		
		$urlaction = '';
		if ( !empty( $this->mReturnTo ) ) {
			$urlaction = "returnto=$this->mReturnTo";
			if ( !empty( $this->mReturnToQuery ) )
				$urlaction .= "&returntoquery=$this->mReturnToQuery";
		}
		$wgOut->redirect($wgUser->getSkin()->makeSpecialUrl($specialPage, $urlaction));
	}
	
	/**
	 * Entry point of the special page.
	 * 
	 * Special:Connect uses a MVC architecture, with execute() being the
	 * controller. The control flow happens by switching the subpage's name and
	 * then moving through a boatload of nested ifs (notice how early returns
	 * are avoided). Subpages are exclusively used for intermediate stages of the
	 * connecting process; that is, they are only invoked from Special:Connect
	 * itself. In the future, AJAX functions will also shorten the connecting
	 * process by sending the user directly to one of these subpages. Indeed,
	 * the control structure for this process has already been laid out within
	 * the login function of ext.facebook.js, but the AJAX calls are currently
	 * skipped and the user is simply always directed to Special:Connect's main page.
	 * 
	 * Now on to the MVC part.
	 * 
	 * Function execute() operates on three possible models: the MediaWiki User
	 * class, FacebookUser and FacebookApplication (TODO). At the end of a
	 * control path, the process is this: update the model, then call the view
	 * (which may depend on the model, but only in read-only mode).
	 * 
	 * Models are only updated from subpages, not Special:Connect. Subpages are
	 * the endpoint of any connecting/disconnecting process, and are only valid
	 * when POSTed to from Special:Connect (or, in the future, AJAX calls acting
	 * on behalf of Special:Connect). The one exception is if the user is logged
	 * in to Facebook and has a MediaWiki account, but isn't logged in to
	 * MediaWiki; they will then be logged in to MediaWiki. Oh, and a second
	 * exception is that Special:Connect/Debug can be navigated to, but it's
	 * only for testing purposes.
	 */
	public function execute( $subPageName ) {
		global $wgUser, $wgRequest;
		
		// Setup the session
		global $wgSessionStarted;
		if (!$wgSessionStarted) {
			wfSetupSession();
		}
		
		$this->setReturnTo();
		
		switch ( $subPageName ) {
		/**
		 * Special:Connect/ChooseName is POSTed to after the new Facebook user
		 * has chosen their MediaWiki account options (the wpNameChoice param),
		 * either to connect an existing account (if allowed) or to create a
		 * new account with the specified options.
		 * 
		 * TODO: Verify that the request is a POST, not a GET (currently they
		 * both do the same thing, I think).
		 * 
		 * TODO: Verify the user's status (not logged in, etc).
		 */
		case 'ChooseName':
			if ( $wgRequest->getCheck('wpCancel') ) {
				$this->sendError('facebook-cancel', 'facebook-canceltext');
			} else {
				$choice = $wgRequest->getText('wpNameChoice');
				try {
					// The model is updated in manageChooseNamePost()
					$this->manageChooseNamePost($choice);
					if ( $choice == 'existing' ) {
						$this->sendPage('displaySuccessAttachingView');
					} else {
						$this->sendPage('loginSuccessView', true);
					}
				} catch (FacebookModelException $e) {
					// HACK: If the title msg is 'connectNewUserView' then we
					// send the view instead of an error
					if ($e->getTitleMsg() == 'connectNewUserView') {
						$this->sendPage('connectNewUserView', $e->getTextMsg());
					} else {
						$this->sendError($e->getTitleMsg(), $e->getTextMsg(), $e->getMsgParams());
					}
				}
			}
			break;
		/**
		 * Special:Connect/LogoutAndContinue does just that -- logs the current
		 * MediaWiki user out and another MediaWiki user in based on the current
		 * Facebook credentials. No parameters, so if a non-Facebook users GETs
		 * this they will be logged out and sent to Special:UserLogin.
		 * 
		 * TODO: In the case above, redirect to Special:UserLogout.
		 */
		case 'LogoutAndContinue':
			// Update the model (MediaWiki user)
			$oldName = $wgUser->logout();
			$injected_html = ''; // unused
			wfRunHooks( 'UserLogoutComplete', array(&$wgUser, &$injected_html, $oldName) );
			
			$fbUser = new FacebookUser();
			if ($fbUser->getMWUser()->getId()) {
				// Update the model again (Facebook user)
				$fbUser->login();
				$this->sendPage('loginSuccessView');
			} else {
				$this->sendRedirect('UserLogin');
			}
			break;
		/**
		 * Special:Connect/MergeAccount takes care of connecting Facebook users
		 * to existing accounts.
		 * 
		 * TODO: Verify this is a POST request
		 */
		case 'MergeAccount':
			try {
				// The model is updated in manageMergeAccountPost()
				$this->manageMergeAccountPost();
				$this->sendPage('displaySuccessAttachingView');
			} catch (FacebookModelException $e) {
				$this->sendError($e->getTitleMsg(), $e->getTextMsg(), $e->getMsgParams());
			}
			break;
		/**
		 * Special:Connect/Deauth is a callback used by Facebook to notify the
		 * application that the Facebook user has deauthenticated the app
		 * (removed it from thier app settings page). If the request for this
		 * page isn't signed by Facebook, it will redirect to Special:Connect.
		 */
		case 'Deauth':
			// Facebook will include a signed_request param to verify authenticity
			global $facebook;
			$signed_request = $facebook->getSignedRequest();
			if ( $signed_request ) {
				// Update the model
				$fbUser = new FacebookUser($signed_request['user_id']);
				$fbUser->disconnect();
				// What view should we show to Facebook? It doesn't really matter...
				$this->setHeaders();
			} else {
				// signed_request not present or hash mismatch
				$this->sendRedirect('Connect');
			}
			break;
		/**
		 * Special:Connect/Debug allows an administrator to veriy both the
		 * Facebook application this extension are setup and working correctly.
		 * This page can only be accessed if $wgFbAllowDebug is true; see
		 * config.default.php for more information.
		 * 
		 * TODO: In the future, this will test the Deauth callback and a bunch
		 * of other things.
		 * 
		 * TODO: Add $wgFbAllowDebug configuration parameter to config.default.php.
		 */
		case 'Debug':
			global $wgFbAllowDebug;
			$wgFbAllowDebug = false; // until this is implemented
			if ( !empty( $wgFbAllowDebug ) ) {
				// In the future, class FacebookApplication will be the model
				$this->sendPage('debugView'); // TODO
				// no break until this subpage is implemented
			}
		/**
		 * Special:Connect was called with no subpage specified.
		 * 
		 * TODO: Verify that no subpage was specified, and redirect to
		 * Special:Connect if an invalid subpage was used.
		 */
		default:
			$fbUser = new FacebookUser();
			
			// Try fetching /me to see if our Facebook session is valid
			if ( !$fbUser->isLoggedIn($ping = true) ) {
				// The user isn't logged in to Facebook
				if ( !$wgUser->isLoggedIn() ) {
					// The user isn't logged in to Facebook or MediaWiki
					$this->sendRedirect('UserLogin'); // Nothing to see here, move along
				} else {
					// The user is logged in to MediaWiki but not Facebook
					$this->sendPage('loginToFacebookView');
				}
			} else {
				// The user is logged in to Facebook
				$mwId = $fbUser->getMWUser()->getId();
				if ( !$wgUser->isLoggedIn() ) {
					// The user is logged in to Facebook but not MediaWiki
					if ( !$mwId ) {
						// The Facebook user is new to MediaWiki
						$this->sendPage('connectNewUserView');
					} else {
						// The user is logged in to Facebook, but not MediaWiki.
						// The UserLoadAfterLoadFromSession hook might have misfired
						// if the user's "remember me" option was disabled.
						$fbUser->login();
						$this->sendPage('loginSuccessView');
					}
				} else {
					// The user is logged in to Facbook and MediaWiki
					if ( $mwId == $wgUser->getId() ) {
						// MediaWiki user belongs to the Facebook account
						$this->sendRedirect('UserLogin'); // Nothing to see here, move along
					} else {
						// Accounts don't agree
						if ( !$mwId ) {
							// Facebook user is new
							$fb_ids = FacebookDB::getFacebookIDs($wgUser);
							if ( count( $fb_ids ) == 0 ) {
								// MediaWiki user is free
								// Both accounts are free. Ask to merge
								$this->sendPage('mergeAccountView');
							} else {
								// MediaWiki user already associated with Facebook ID
								global $wgContLang;
								$param1 = '[[' . $wgContLang->getNsText( NS_USER ) . ":{$wgUser->getName()}|{$wgUser->getName()}]]";
								$param2 = $fbUser->getUserInfo('name');
								$this->sendError('errorpagetitle', 'facebook-error-wrong-id', array('$1' => $param1, '$2' => $param2));
							}
						} else {
							// Facebook account has a MediaWiki user
							// Ask to log out and continue as the new user ($mwId)
							$this->sendPage('logoutAndContinueView', $mwId);
						}
					}
				}
			}
		}
	} // function execute()
	
	/**
	 * Extends the control of execute() for the subpage Special:Connect/ChooseName.
	 * 
	 * The model operated upon is FacebookUser. To signal a diferent view should
	 * be shown, a FacebookModelException is thrown by the model or this function.
	 * The exception allows the model to be unmodified.
	 * 
	 * Note that we kind of cheat: 'connectNewUserView' isn't an error page title,
	 * but signals that we should go to the connectNewUserView() view.
	 * 
	 * @throws FacebookModelException
	 */
	private function manageChooseNamePost($choice) {
		global $wgRequest;
		$fbUser = new FacebookUser();
		
		switch ($choice) {
			// Check to see if the user opted to connect an existing account
			case 'existing':
				// Update the model
				$fbUser->attachUser($wgRequest->getText('wpExistingName'),
				                    $wgRequest->getText('wpExistingPassword'), $this->getUpdatePrefs());
				break;
			// Figure out the username to send to the model
			case 'nick':
			case 'first':
			case 'full':
				// Get the username from Facebook (note: not from the form)
				$username = FacebookUser::getOptionFromInfo($choice . 'name', $fbUser->getUserInfo());
				// no break
			case 'manual':
				// Use manual name if no username is set (even if manual wasn't chosen)
				if ( empty($username) || !FacebookUser::userNameOK($username) )
					$username = $wgRequest->getText('wpName2');
				// If no valid username was found, something's not right; ask again
				if (empty($username) || !FacebookUser::userNameOK($username)) {
					throw new FacebookModelException('connectNewUserView', 'facebook-invalidname');
				}
				// no break
			case 'auto':
				if ( empty($username) ) {
					// We got here if and only if $choice is 'auto'
					$username = FacebookUser::generateUserName();
				}
				// Just in case the automatically-generated username is a bad egg
				if ( empty($username) || !FacebookUser::userNameOK($username) ) {
					throw new FacebookModelException('connectNewUserView', 'facebook-invalidname');
				}
				
				// Handle accidental reposts (TODO: this check should happen in execute()!!!)
				global $wgUser;
				if ( $wgUser->isLoggedIn() ) {
					return;
				}
				
				// Now that we got our username, update the mode
				$fbUser->createUser($username, $wgRequest->getText( 'wpDomain' )); // wpDomain isn't currently set...
				break;
			// Nope
			default:
				throw new FacebookModelException('facebook-invalid', 'facebook-invalidtext');
		}
	}
	
	/**
	 * Helper function for manageChooseNamePost() and manageMergeAccountPost().
	 * 
	 * Returns an array representing the checkboxes specified by wpUpdateUserInfo*OPTION*.
	 */
	private function getUpdatePrefs() {
		global $wgRequest;
		$updatePrefs = array();
		foreach (FacebookUser::$availableUserUpdateOptions as $option) {
			if ( $wgRequest->getText("wpUpdateUserInfo$option", '0') == '1' ) {
				$updatePrefs[] = $option;
			}
		}
		return $updatePrefs();
	}
	
	/**
	 * Special:Connect/MergeAccount
	 * 
	 * In the future, all of the ***Post() functions should be renamed to
	 * reflect that they are more about updating the model and less about
	 * handling the control.
	 * 
	 * @throws FacebookModelException
	 */
	private function manageMergeAccountPost() {
		global $wgUser;
		if ( !$wgUser->isLoggedIn() ) {
			throw new FacebookModelException('facebook-error', 'facebook-errortext');
		}
		
		$fbUser = new FacebookUser();
		if ( !$fbUser->isLoggedIn() ) {
			throw new FacebookModelException('facebook-error', 'facebook-errortext');
		}
		
		// Make sure both accounts are free in the database
		// TODO: This should happen inside the model!!!
		$mwId = $fbUser->getMWUser()->getId();
		$fb_ids = FacebookDB::getFacebookIDs($wgUser);
		if ( $mwId || count($fb_ids) > 0 ) {
			throw new FacebookModelException('facebook-error', 'facebook-errortext'); // TODO: new error msg
		}
		
		// Update the model
		$fbUser->attachUser($wgUser->getName(), '', $this->getUpdatePrefs());
	}
	
	/**
	 * Special:Connect/Debug
	 * 
	 * This is the only subpage that can be called directly. It allows the user
	 * to verify that the app is set up correctly inside Facebook, and offers
	 * to automatically fix some of the problems it detects.
	 * 
	 * TODO: Implement FacebookApplication.php
	 * TODO: Finish this function
	 */
	private function debugView() {
		global $wgRequst, $facebook;
		
		// "Enter a page name to view it as an object in the Open Graph." Render a button that
		// submits the wpPageName field to Special:Connect/Debug and handle the submission here.
		// TODO: handle the redirect in execute() maybe
		// The following code is untested
		$pageName = $wgRequest->getText('wpPageName');
		if ( $pageName != '' ) {
			$pageName = 'Main Page';
			$title = Title::newFromText( $pageName );
			if ( !( $title instanceof Title ) ) {
				$title = Title::newMainPage();
			}
			$url = 'https://developers.facebook.com/tools/debug/og/object?q=' . urlencode( $title->getFullURL() );
			$wgOut->redirect( $url );
			return;
		}
		
		// Do some other stuff with the FacebookApplication class in
		// FacebookApplication.php (currently unimplemented).
		// Thow a 'not a Facebook and MediaWiki administrator' if the user isn't authorized
	}
	
	/**
	 * The user is logged in to MediaWiki but not Facebook.
	 * No Facebook user is associated with this MediaWiki account.
	 * 
	 * TODO: Facebook login button causes a post to a Special:Connect/ConnectUser or something
	 */
	private function loginToFacebookView() {
		global $wgOut, $wgSitename, $wgUser;
		$loginFormWidth = 400; // pixels
		
		$fb_ids = FacebookDB::getFacebookIDs($wgUser);
		
		$this->outputHeader();
		$html = '
<div id="userloginForm">
	<form style="width: ' . $loginFormWidth . 'px;">' . "\n";
		
		if ( !count( $fb_ids ) ) {
			// This message was added recently and might not be translated
			// In that case, fall back to an older, similar message
			$formTitle = wfMsg( 'facebook-merge-title' );
			// This test probably isn't correct. I'm open to ideas
			if ($formTitle == "&lt;facebook-merge-title&gt;") {
				$formTitle = wfMsg( 'login' );
			}
			$html .= '<h2>' . $formTitle . "</h2>\n";
			
			$formText = wfMsg( 'facebook-merge-text', $wgSitename );
			// This test probably isn't correct. I'm open to ideas
			if ($formText == "&lt;facebook-merge-text&gt;") {
				$formText = wfMsg( 'facebook-merge' );
			}
			$html .= '<p>' . $formText . "<br/><br/></p>\n";
		} else {
			$html .= '<h2>' . wfMsg( 'login' ) . "</h2>\n";
			// User is already connected to a Facebook account. Send a page asking
			// them to log in to one of their (possibly several) Facebook accounts
			// For now, scold them for trying to log in to a connected account
			// TODO
			$html .= '<p>' . wfMsg( 'facebook-connect-text' ) . "<br/><br/></p>\n";
		}
		$html .= '<fb:login-button show-faces="true" width="' . $loginFormWidth .
				'" max-rows="3" scope="' . FacebookAPI::getPermissions() . '" colorscheme="' .
				$this->getColorScheme() . '"></fb:login-button><br/><br/><br/>' . "\n";
		
		// Add a pretty Like box to entice the user to log in
		$html .= '<fb:like href="' . Title::newMainPage()->getFullURL() . '" send="false" width="' .
					 $loginFormWidth . '" show_faces="true"></fb:like>';
		$html .= '
	</form>
</div>';
		$wgOut->addHTML($html);
		
		// TODO: Add a returnto link
	}
	
	private function getColorScheme() {
		$skins = array();
		$darkSkins = array();
		wfRunHooks( 'SpecialConnectColorScheme', array( &$skins ) );
		
		foreach ($skins as $skin => $value) {
			if ( $value == 'dark' ) {
				$darkSkins[] = $skin;
			}
		}
		if ( in_array( $this->getSkin()->getSkinName(), $darkSkins ) ) {
			return 'dark';
		}
		return 'light';
	}
	
	/**
	 * This view is sent when $wgFbDisableLogin is true. In this case, the user
	 * must be logged in to Facebook to view the wiki, so we present a single
	 * login button.
	 */
	private function exclusiveLoginToFacebookView() {
		global $wgOut, $wgSitename, $wgUser;
		$loginFormWidth = 400; // pixels
		
		$this->outputHeader();
		$html = '
<div id="userloginForm">
	<form style="width: ' . $loginFormWidth . 'px;">
		<h2>' . wfMsg( 'userlogin' ) . '</h2>
		<p>' . wfMsg( 'facebook-only-text', $wgSitename ) . '<br/><br/></p>' . "\n";
		
		$html .= '<fb:login-button show-faces="true" width="' . $loginFormWidth .
				'" max-rows="3" scope="' . FacebookAPI::getPermissions() . '" colorscheme="' .
				$this->getColorScheme() . '"></fb:login-button><br/><br/><br/>' . "\n";
		
		// Add a pretty Like box to entice the user to log in
		$html .= '<fb:like href="' . Title::newMainPage()->getFullURL() . '" send="false" width="' .
				$loginFormWidth . '" show_faces="true"></fb:like>';
		$html .= '
	</form>
</div>';
		$wgOut->addHTML($html);
	}
	
	
	/**
	 * The user is logged in to Facebook, but not MediaWiki. The Facebook user
	 * is new to MediaWiki.
	 */
	private function connectNewUserView($messagekey = 'facebook-chooseinstructions') {
		global $wgUser, $wgOut, $wgFbDisableLogin;
		
		$titleObj = SpecialPage::getTitleFor( 'Connect' );
		if ( wfReadOnly() ) {
			// The wiki is in read-only mode
			$wgOut->readOnlyPage();
			return;
		}
		if ( empty( $wgFbDisableLogin ) ) {
			// These two permissions don't apply in $wgFbDisableLogin mode because
			// then technically no users can create accounts
			if ( $wgUser->isBlockedFromCreateAccount() ) {
				wfDebug("Facebook: Blocked user was attempting to create account via Facebook Connect.\n");
				// This is not an explicitly static method but doesn't use $this and can be called like static
				LoginForm::userBlockedMessage();
				return;
			} else {
				$permErrors = $titleObj->getUserPermissionsErrors('createaccount', $wgUser, true);
				if (count( $permErrors ) > 0) {
					// Special case for permission errors
					$this->sendError($permErrors, 'createaccount');
					return;
				}
			}
		}
		
		// Allow other code to have a custom form here (so that this extension
		// can be integrated with existing custom login screens). Hook must
		// output content if it returns false.
		if( !wfRunHooks( 'SpecialConnect::chooseNameForm', array( &$this, &$messagekey ) ) ){
			return;
		}
		
		// Connect to the Facebook API
		$fbUser = new FacebookUser();
		$userinfo = $fbUser->getUserInfo();
		
		// Keep track of when the first option visible to the user is checked
		$checked = false;
		
		// Outputs the canonical name of the special page at the top of the page
		$this->outputHeader();
		
		// If a different $messagekey was passed (like 'wrongpassword'), use it instead
		$wgOut->addWikiMsg( $messagekey );
		
		$html = '
<form action="' . $this->getTitle('ChooseName')->getLocalUrl() . '" method="POST">
	<fieldset id="mw-facebook-choosename">
		<legend>' . wfMsg('facebook-chooselegend') . '</legend>
		<table>';
		// Let them attach to an existing. If $wgFbDisableLogin is true, then
		// stand-alone account aren't allowed in the first place
		if (empty( $wgFbDisableLogin )) {
			// Grab the UserName from the cookie if it exists
			global $wgCookiePrefix;
			$name = isset($_COOKIE["{$wgCookiePrefix}UserName"]) ? trim($_COOKIE["{$wgCookiePrefix}UserName"]) : '';
			
			$updateChoices = $this->getUpdateOptions();
			
			// Create the HTML for the "existing account" option
			$html .= '
			<tr>
				<td class="wm-label">
					<input name="wpNameChoice" type="radio" value="existing" id="wpNameChoiceExisting"/>
				</td>
				<td class="mw-input">
					<label for="wpNameChoiceExisting">' . wfMsg('facebook-chooseexisting') . '</label>
					<div id="mw-facebook-choosename-update" class="fbInitialHidden">
						<label for="wpExistingName">' . wfMsgHtml('facebook-chooseusername') . '</label>
						<input name="wpExistingName" size="20" value="' . $name . '" id="wpExistingName" />&nbsp;
						<label for="wpExistingPassword">' . wfMsgHtml('facebook-choosepassword') . '</label>
						<input name="wpExistingPassword" size="20" value="" type="password" id="wpExistingPassword" /><br/>
						' . $updateChoices . '
					</div>
				</td>
			</tr>';
		}
		
		// Add the options for nick name, first name and full name if we can get them
		foreach (array('nick', 'first', 'full') as $option) {
			$nickname = FacebookUser::getOptionFromInfo($option . 'name', $userinfo);
			if ($nickname && FacebookUser::userNameOK($nickname)) {
				$html .= '
			<tr>
				<td class="mw-label">
					<input name="wpNameChoice" type="radio" value="' . $option . ($checked ? '' : '" checked="checked') .
						'" id="wpNameChoice' . $option . '" />
				</td>
				<td class="mw-input">
					<label for="wpNameChoice' . $option . '">' . wfMsg('facebook-choose' . $option, $nickname) . '</label>
				</td>
			</tr>';
				// When the first radio is checked, this flag is set and subsequent options aren't checked
				$checked = true;
			}
		}
		
		// The options for auto and manual usernames are always available
		$html .= '
			<tr>
				<td class="mw-label">
					<input name="wpNameChoice" type="radio" value="auto" ' . ($checked ? '' : 'checked="checked" ') .
						'id="wpNameChoiceAuto" />
				</td>
				<td class="mw-input">
					<label for="wpNameChoiceAuto">' . wfMsg('facebook-chooseauto', FacebookUser::generateUserName()) . '</label>
				</td>
			</tr>
			<tr>
				<td class="mw-label">
					<input name="wpNameChoice" type="radio" value="manual" id="wpNameChoiceManual" />
				</td>
				<td class="mw-input">
					<label for="wpNameChoiceManual">' . wfMsg('facebook-choosemanual') . '</label>&nbsp;
					<input name="wpName2" size="16" value="" id="wpName2" />
				</td>
			</tr>';
		// Finish with two options, "Log in" or "Cancel"
		$html .= '
			<tr>
				<td></td>
				<td class="mw-submit">
					<input type="submit" value="Log in" name="wpOK" />
					<input type="submit" value="Cancel" name="wpCancel" />';
		
		// Include returnto and returntoquery parameters if they are set
		if (!empty($this->mReturnTo)) {
			$html .= '
					<input type="hidden" name="returnto" value="' . $this->mReturnTo . '" />';
			// Only need returntoquery if returnto is set
			if (!empty($this->mReturnToQuery)) {
				$html .= '
					<input type="hidden" name="returntoquery" value="' . $this->mReturnToQuery . '" />';
			}
		}
		
		$html .= '
				</td>
			</tr>
		</table>
	</fieldset>
</form>' . "\n\n";
		$wgOut->addHTML($html);
	}
	
	/**
	 * TODO: Document me
	 */
	private function getUpdateOptions() {
		global $wgRequest;
		
		$fbUser = new FacebookUser();
		$userinfo = $fbUser->getUserInfo();
		
		// Build an array of attributes to update
		$updateOptions = array();
		foreach ($fbUser->getAvailableUserUpdateOptions() as $option) {
			
			// Translate the MW parameter into a FB parameter
			$value = FacebookUser::getOptionFromInfo($option, $userinfo);
			
			// If no corresponding value was received from Facebook, then continue
			if (!$value) {
				continue;
			}
			
			// Check to see if the option was checked on a previous page (default to true)
			$checked = ($wgRequest->getText("wpUpdateUserInfo$option", '0') != '1');
			
			// Build the list item for the update option
			$item  = '<li>';
			$item .= '<input name="wpUpdateUserInfo' . $option . '" type="checkbox" ' .
			         'value="1" id="wpUpdateUserInfo' . $option . '" ' .
			         ($checked ? 'checked="checked" ' : '') . '/>';
			$item .= '<label for="wpUpdateUserInfo' . $option . '">' . wfMsgHtml("facebook-$option") .
			         wfMsgExt('colon-separator', array('escapenoentities')) . " <i>$value</i></label></li>";
			$updateOptions[] = $item;
		}
		
		// Implode the update options into an unordered list
		$updateChoices = '';
		if ( count($updateOptions) > 0 ) {
			$updateChoices .= "<br/>\n";
			$updateChoices .= wfMsgHtml('facebook-updateuserinfo') . "\n";
			$updateChoices .= "<ul>\n" . implode("\n", $updateOptions) . "\n</ul>\n";
		}
		return $updateChoices;
	}
	
	/**
	 * The user has just been logged in by their Facebook account.
	 */
	private function loginSuccessView($newUser = false) {
		global $wgOut, $wgUser;
		$wgOut->setPageTitle( wfMsg('facebook-success') );
		$wgOut->addWikiMsg( 'facebook-successtext' );
		// Run any hooks for UserLoginComplete
		$injected_html = '';
		wfRunHooks( 'UserLoginComplete', array( &$wgUser, &$injected_html ) );
		
		if ( $injected_html !== '' ) {
			$wgOut->addHtml( $injected_html );
			// Render the "return to" text retrieved from the URL
			$wgOut->returnToMain(false, $this->mReturnTo, $this->mReturnToQuery);
		} else {
			$addParam = '';
			if ( $newUser ) {
				$addParam = '&fbconnected=1';
			}
			// Since there was no additional message for the user, we can just
			// redirect them back to where they came from
			$titleObj = Title::newFromText( $this->mReturnTo );
			if ( ($titleObj instanceof Title) && !$titleObj->isSpecial('Userlogout') &&
					!$titleObj->isSpecial('Signup') && !$titleObj->isSpecial('Connect') ) {
				$query = '';
				if ( !empty($this->mReturnToQuery) )
					$query .= $this->mReturnToQuery . '&';
				$query .= 'cb=' . rand(1, 10000);
				$wgOut->redirect( $titleObj->getFullURL( $query ) );
			} else {
				$titleObj = Title::newMainPage();
				$wgOut->redirect( $titleObj->getFullURL( 'cb=' . rand(1, 10000) . $addParam ) );
			}
		}
	}
	
	/**
	 * MediaWiki user and Facebook user are both unconnected. Ask to merge
	 * these two.
	 */
	private function mergeAccountView() {
		global $wgOut, $wgUser, $wgSitename;
		$wgOut->setPageTitle(wfMsg('facebook-merge-title'));
		
		$html = '
<form action="' . $this->getTitle('MergeAccount')->getLocalUrl() . '" method="POST">
	<fieldset id="mw-facebook-chooseoptions">
		<legend>' . wfMsg('facebook-updatelegend') . '</legend>
		' . wfMsgExt('facebook-merge-text', 'parse', array('$1' => $wgSitename )) .
		// TODO
		//<p>Not $user? Log in as a different facebook user...</p>
		'
		<input type="submit" value="' . wfMsg( 'facebook-merge-title' ) . '" /><br/>
		<div id="mw-facebook-choosename-update">
			' . $this->getUpdateOptions() . '
		</div>';
		if ( !empty( $this->mReturnTo ) ) {
			$html .= '
		<input type="hidden" name="returnto" value="' . $this->mReturnTo . '" />';
			// Only need returntoquery if returnto is set
			if ( !empty( $this->mReturnToQuery ) ) {
				$html .= '
		<input type="hidden" name="returntoquery" value="' . $this->mReturnToQuery . '" />';
			}
		}
		$html .= '
	</fieldset>
</form><br/>';
		
		$wgOut->addHTML($html);
		
		// Render the "Return to" text retrieved from the URL
		$wgOut->returnToMain(false, $this->mReturnTo, $this->mReturnToQuery);
		$wgOut->addHTML("<br/>\n");
	}
	
	/**
	 * This error page is shown when the user logs in to Facebook, but the
	 * Facebook account is associated with a different user.
	 * 
	 * A precondition is that a different MediaWiki user is logged in. So, ask
	 * them to log out and continue.
	 * 
	 * TODO: But what about the case where a Facebook user is logged in, but
	 * not as a wiki user, and then logs into the wiki with the wrong account?
	 */
	private function logoutAndContinueView($userId) {
		global $wgOut, $wgContLang;
		
		$wgOut->setPageTitle(wfMsg('facebook-logout-and-continue'));
		
		$html = '';
		
		$fbUser = new FacebookUser();
		$profile = $fbUser->getUserInfo();
		if ( $profile && isset($profile['first_name']) ) {
			$html = '
<p>' . wfMsg('facebook-welcome-name', array('$1' => $profile['first_name'])) . '</p>';
		}
		
		$username = User::newFromId($userId)->getName();
		$html .= "\n" . wfMsgExt('facebook-continue-text', 'parse', array(
				'$1' => '[[' . $wgContLang->getNsText( NS_USER ) . ":$username|$username]]")
		);
		$html .= '
<form action="' . $this->getTitle('LogoutAndContinue')->getLocalUrl() . '" method="post">
	<input type="submit" value="' . wfMsg( 'facebook-continue-button' ) . '" />';
		if ( !empty( $this->mReturnTo ) ) {
			$html .= '
	<input type="hidden" name="returnto" value="' . $this->mReturnTo . '" />';
			// Only need returntoquery if returnto is set
			if ( !empty( $this->mReturnToQuery ) ) {
				$html .= '
	<input type="hidden" name="returntoquery" value="' . $this->mReturnToQuery . '" />';
			}
		}
		$html .= '
</form><br/>' . "\n";
		
		// TODO
		//$html .= '<p>Not $user? Log in as a different facebook user...</p>';
		
		$wgOut->addHTML( $html );
		
		// Render the "Return to" text retrieved from the URL
		$wgOut->returnToMain(false, $this->mReturnTo, $this->mReturnToQuery);
	}
	
	
	
	
	
	/**
	 * Success page for attaching Facebook account to a pre-existing MediaWiki
	 * account. Shows a link to preferences and a link back to where the user
	 * came from.
	 */
	private function displaySuccessAttachingView() {
		global $wgOut, $wgUser, $wgRequest;
		wfProfileIn( __METHOD__ );
		
		$wgOut->setPageTitle( wfMsg('facebook-success') );
		
		$prefsLink = SpecialPage::getTitleFor('Preferences')->getLinkUrl();
		$wgOut->addHTML(wfMsg('facebook-success-connecting-existing-account', $prefsLink));
		
		// Run any hooks for UserLoginComplete
		$inject_html = '';
		wfRunHooks( 'UserLoginComplete', array( &$wgUser, &$inject_html ) );
		$wgOut->addHtml( $inject_html );
		
		// Since there was no additional message for the user, we can just
		// redirect them back to where they came from
		$titleObj = Title::newFromText( $this->mReturnTo );
		if ( ($titleObj instanceof Title) && !$titleObj->isSpecial('Userlogout') &&
				!$titleObj->isSpecial('Signup') && !$titleObj->isSpecial('Connect') ) {
			$query = '';
			if ( !empty($this->mReturnToQuery) )
				$query .=  $query = $this->mReturnToQuery . '&';
			$query .= 'fbconnected=1&cb=' . rand(1, 10000);
			$wgOut->redirect( $titleObj->getFullURL( $query ) );
		} else {
			/*
			 // Render a "return to" link retrieved from the URL
			$wgOut->returnToMain( false, $this->mReturnTo, $this->mReturnToQuery .
					(!empty($this->mReturnToQuery) ? '&' : '') .
					'fbconnected=1&cb=' . rand(1, 10000) );
			*/
			$titleObj = Title::newMainPage();
			$wgOut->redirect( $titleObj->getFullURL('fbconnected=1&cb=' . rand(1, 10000)) );
		}
		
		wfProfileOut(__METHOD__);
	}
	
	
	/**
	 * This is called when a user is logged into a Wikia account and has just gone through the Facebook Connect popups,
	 * but has not been connected inside the system.
	 *
	 * This function will connect them in the database, save default preferences and present them with "Congratulations"
	 * message and a link to modify their User Preferences. TODO: SHOULD WE JUST SHOW THE CHECKBOXES AGAIN?
	 * 
	 * This is different from attachUser because that is made to synchronously test a login at the same time as creating
	 * the account via the ChooseName form.  This function, however, is designed for when the existing user is already logged in
	 * and wants to quickly connect their Facebook account.  The main difference, therefore, is that this function uses default
	 * preferences while the other form should have already shown the preferences form to the user.
	 *
	public function connectExistingView() {
		global $wgUser, $facebook;
		wfProfileIn(__METHOD__);
		
		// Store the facebook-id <=> mediawiki-id mapping.
		// TODO: FIXME: What sould we do if this fb_id is already connected to a DIFFERENT mediawiki account.
		$fb_id = $facebook->getUser();
		FacebookDB::addFacebookID($wgUser, $fb_id);
		
		// Save the default user preferences.
		global $wgFbEnablePushToFacebook;
		if (!empty( $wgFbEnablePushToFacebook )) {
			global $wgFbPushEventClasses;
			if (!empty( $wgFbPushEventClasses )) {
				$DEFAULT_ENABLE_ALL_PUSHES = true;
				foreach($wgFbPushEventClasses as $pushEventClassName) {
					$pushObj = new $pushEventClassName;
					$prefName = $pushObj->getUserPreferenceName();
					
					$wgUser->setOption($prefName, $DEFAULT_ENABLE_ALL_PUSHES ? '1' : '0');
				}
			}
		}
		$wgUser->setOption('fbFromExist', '1');
		$wgUser->saveSettings();
		
		wfRunHooks( 'SpecialConnect::userAttached', array( &$this ) );
		
		$this->sendPage('displaySuccessAttachingView');
		wfProfileOut(__METHOD__);
	}
	
	
	/**
	 * Check to see if the user can create a Facebook-linked account.
	 *
	function checkCreateAccount() {
		global $wgUser, $facebook;
		// Response object to send return to the client
		$response = new AjaxResponse();
		
		$fb_user = $facebook->getUser();
		if (empty($fb_user)) {
			$response->addText(json_encode(array(
				'status' => 'error',
				'code' => 1,
				'message' => 'User is not logged into Facebook',
			)));
			return $response;
		}
		if(( (int)$wgUser->getId() ) != 0) {
			$response->addText(json_encode(array(
				'status' => 'error',
				'code' => 2,
				'message' => 'User is already logged into the wiki',
			)));
			return $response;
		}
		if( FacebookDB::getUser($fb_user) != null) {
			$response->addText(json_encode(array(
				'status' => 'error',
				'code' => 3,
				'message' => 'This Facebook account is connected to a different user',
			)));
			return $response;
		}
		if ( wfReadOnly() ) {
			$response->addText(json_encode(array(
				'status' => 'error',
				'code' => 4,
				'message' => 'The wiki is in read-only mode',
			)));
			return $response;
		}
		if ( $wgUser->isBlockedFromCreateAccount() ) {
			$response->addText(json_encode(array(
				'status' => 'error',
				'code' => 5,
				'message' => 'User does not have permission to create an account on this wiki',
			)));
			return $response;
		}
		$titleObj = SpecialPage::getTitleFor( 'Connect' );
		if ( count( $permErrors = $titleObj->getUserPermissionsErrors( 'createaccount', $wgUser, true ) ) > 0 ) {
			$response->addText(json_encode(array(
				'status' => 'error',
				'code' => 6,
				'message' => 'User does not have permission to create an account on this wiki',
			)));
			return $response;
		}
		
		// Success!
		$response->addText(json_encode(array('status' => 'ok')));
		return $response;
	}
	
	/**
	 * 
	 * 
	 *
	function ajaxModalChooseName() {
		global $wgRequest;
		wfLoadExtensionMessages('Facebook');
		$response = new AjaxResponse();
		
		$specialConnect = new SpecialConnect();
		$form = new ChooseNameForm($wgRequest, 'signup');
		$form->mainLoginForm( $specialConnect, '' );
		$tmpl = $form->getAjaxTemplate();
		$tmpl->set('isajax', true);
		ob_start();
		$tmpl->execute();
		$html = ob_get_clean();
		
		$response->addText( $html );
		return $response;
	}
	/**/
}
