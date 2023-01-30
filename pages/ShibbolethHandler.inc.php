<?php

/**
 * @file plugins/generic/shibboleth/pages/ShibbolethHandler.inc.php
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2 or later. For full terms see the file docs/COPYING.
 *
 * @class ShibbolethHandler
 * @ingroup plugins_generic_shibboleth
 *
 * @brief Handle Shibboleth responses
 */

import('classes.handler.Handler');

class ShibbolethHandler extends Handler {
	/** @var ShibbolethAuthPlugin */
	var $_plugin;

	/** @var int */
	var $_contextId;

	/**
	* Intercept normal login/registration requests; defer to Shibboleth.
	*
	* @param $args array
	* @param $request Request
	* @return bool
	*/
	function activateUser($args, $request) {
		return $this->_shibbolethRedirect($request);
	}

	/**
	* @copydoc ShibbolethHandler::activateUser()
	*/
	function changePassword($args, $request) {
		return $this->_shibbolethRedirect($request);
	}

	/**
	* @copydoc ShibbolethHandler::activateUser()
	*/
	function index($args, $request) {
		$this->_plugin = $this->_getPlugin();
		$this->_shibbolethOptionalTitle = $this->_plugin->getSetting(
			$this->_contextId,
		'shibbolethOptionalTitle'
		);
		$this->_shibbolethOptionalButtonLabel = $this->_plugin->getSetting(
			$this->_contextId,
			'shibbolethOptionalButtonLabel'
		);
			$this->_shibbolethOptionalDescription = $this->_plugin->getSetting(
			$this->_contextId,
			'shibbolethOptionalDescription'
		);

		if ( $this->_isShibbolethOptional() ) {
			/**
			 * This section is based off the code found in
			 * pkp-lib's LoginHandler.inc.php
			 * https://github.com/pkp/pkp-lib/blob/f64f302f8bef4f6c2e40275af717884c643f995b/pages/login/LoginHandler.inc.php#L37-L68
			 */
			$this->setupTemplate($request);
			if (Validation::isLoggedIn()) {
				$this->sendHome($request);
			}

			if (Config::getVar('security', 'force_login_ssl') && $request->getProtocol() != 'https') {
				// Force SSL connections for login
				$request->redirectSSL();
			}

			$sessionManager = SessionManager::getManager();
			$session = $sessionManager->getUserSession();

			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign(array(
				'loginMessage' => $request->getUserVar('loginMessage'),
				'username' => $request->getUserVar('username'),
				'remember' => $request->getUserVar('remember'),
				'source' => $request->getUserVar('source'),
				'showRemember' => Config::getVar('general', 'session_lifetime') > 0,
			));

			// For force_login_ssl with base_url[...]: make sure SSL used for login form
			$loginUrl = $request->url(null, 'login', 'signIn');
			if (Config::getVar('security', 'force_login_ssl')) {
				$loginUrl = PKPString::regexp_replace('/^http:/', 'https:', $loginUrl);
			}
			$templateMgr->assign('loginUrl', $loginUrl);
			$templateMgr->display('frontend/pages/userLogin.tpl');
		} else {
			return $this->_shibbolethRedirect($request);
		}
	}

	/**
	 * @copydoc ShibbolethHandler::activateUser()
	 */
	
	// Use lostPassword function from LoginHandler instead of _shibbolethRedirect
	// Otherwise there would always be a redirect to Shib Sign-in 
	function lostPassword($args, $request) {
			                $this->setupTemplate($request);
			                $templateMgr = TemplateManager::getManager($request);
			                $templateMgr->display('frontend/pages/userLostPassword.tpl');
	}


	/**
	 * @copydoc ShibbolethHandler::activateUser()
	 */
	function register($args, $request) {
		if ( $this->_isShibbolethOptional() ) {
			if (Config::getVar('security', 'force_login_ssl') && $request->getProtocol() != 'https') {
				// Force SSL connections for registration
				$request->redirectSSL();
			}

			// If the user is logged in, show them the registration success page
			if (Validation::isLoggedIn()) {
				$this->setupTemplate($request);
				$templateMgr = TemplateManager::getManager($request);
				$templateMgr->assign('pageTitle', 'user.login.registrationComplete');
				return $templateMgr->display('frontend/pages/userRegisterComplete.tpl');
			}

			$this->validate(null, $request);
			$this->setupTemplate($request);

			import('lib.pkp.classes.user.form.RegistrationForm');
			$regForm = new RegistrationForm($request->getSite());

			// Initial GET request to register page
			if (!$request->isPost()) {
				$regForm->initData();
				return $regForm->display($request);
			}

			// Form submitted
			$regForm->readInputData();
			if (!$regForm->validate()) {
				return $regForm->display($request);
			}

			$regForm->execute();

			// Inform the user of the email validation process. This must be run
			// before the disabled account check to ensure new users don't see the
			// disabled account message.
			if (Config::getVar('email', 'require_validation')) {
				$this->setupTemplate($request);
				$templateMgr = TemplateManager::getManager($request);
				$templateMgr->assign(array(
					'requireValidation' => true,
					'pageTitle' => 'user.login.registrationPendingValidation',
					'messageTranslated' => __('user.login.accountNotValidated', array('email' => $regForm->getData('email'))),
				));
				return $templateMgr->display('frontend/pages/message.tpl');
			}

			$reason = null;
			if (Config::getVar('security', 'implicit_auth')) {
				Validation::login('', '', $reason);
			} else {
				Validation::login($regForm->getData('username'), $regForm->getData('password'), $reason);
			}

			if ($reason !== null) {
				$this->setupTemplate($request);
				$templateMgr = TemplateManager::getManager($request);
				$templateMgr->assign(array(
					'pageTitle' => 'user.login',
					'errorMsg' => $reason==''?'user.login.accountDisabled':'user.login.accountDisabledWithReason',
					'errorParams' => array('reason' => $reason),
					'backLink' => $request->url(null, 'login'),
					'backLinkLabel' => 'user.login',
				));
				return $templateMgr->display('frontend/pages/error.tpl');
			}

			$source = $request->getUserVar('source');
			if (preg_match('#^/\w#', $source) === 1) {
				return $request->redirectUrl($source);
			} else {
				// Make a new request to update cookie details after login
				$request->redirect(null, 'user', 'register');
			}
		} else {
			return $this->_shibbolethRedirect($request);
		}
	}

	/**
	 * @copydoc ShibbolethHandler::activateUser()
	 */
	function registerUser($args, $request) {
		return $this->register($request);
	}



    /**
    * Helper function from LoginHandler- set mail From
    * can be overriden by child classes
    * @param $request PKPRequest
    * @param MailTemplate $mail
    * @param $site Site
    */
    function _setMailFrom($request, $mail, $site) {
		$mail->setReplyTo($site->getLocalizedContactEmail(), $site->getLocalizedContactName());
        return true;
    }


	/**
	 * @copydoc ShibbolethHandler::activateUser()
	 */
	
	/**
	 * From Login Handler - to avoid constant redirect to Shib Login
	 * Send a request to reset a user's password
	 */
	function requestResetPassword($args, $request) {
			$this->setupTemplate($request);
			$templateMgr = TemplateManager::getManager($request);

			$email = $request->getUserVar('email');
			$userDao = DAORegistry::getDAO('UserDAO'); /* @var $userDao UserDAO */
			$user = $userDao->getUserByEmail($email);

			if ($user == null || ($hash = Validation::generatePasswordResetHash($user->getId())) == false) {
					$templateMgr->assign('error', 'user.login.lostPassword.invalidUser');
					$templateMgr->display('frontend/pages/userLostPassword.tpl');

			} else {
					// Send email confirming password reset
					import('lib.pkp.classes.mail.MailTemplate');
					$mail = new MailTemplate('PASSWORD_RESET_CONFIRM');
					$site = $request->getSite();
					$this->_setMailFrom($request, $mail, $site);
					$mail->assignParams([
							'url' => $request->url(null, 'login', 'resetPassword', $user->getUsername(), array('confirm' => $hash)),
							'siteTitle' => htmlspecialchars($site->getLocalizedTitle()),
					]);
					$mail->addRecipient($user->getEmail(), $user->getFullName());
					$mail->send();

					$templateMgr->assign(array(
							'pageTitle' => 'user.login.resetPassword',
							'message' => 'user.login.lostPassword.confirmationSent',
							'backLink' => $request->url(null, $request->getRequestedPage()),
							'backLinkLabel' => 'user.login',
					));
					$templateMgr->display('frontend/pages/message.tpl');
			}
	}
                                                                                              

	/**
	 * @copydoc ShibbolethHandler::activateUser()
	 */

	/**
	 * From Login Handler - to avoid constant redirect to Shib Login
	 * Save user's new password.
	 */
	function savePassword($args, $request) {
			$this->_isBackendPage = true;
			$this->setupTemplate($request);

			import('lib.pkp.classes.user.form.LoginChangePasswordForm');

			$passwordForm = new LoginChangePasswordForm($request->getSite());
			$passwordForm->readInputData();

			if ($passwordForm->validate()) {
					if ($passwordForm->execute()) {
							$user = Validation::login($passwordForm->getData('username'), $passwordForm->getData('password'), $reason);
					}
					$this->sendHome($request);
			} else {
					$passwordForm->display($request);
			}
	}


	/**
	 * From LoginHandler
	 * Send the user "home" (typically to the dashboard, but that may not
	 * always be available).
	 * @param $request PKPRequest
	 */
	protected function sendHome($request) {
			if ($request->getContext()) $request->redirect(null, 'submissions');
			else $request->redirect(null, 'user');
	}

	

	/**
	 * Login handler; receives post-validation Shibboleth redirect.
	 *
	 * @param $args array
	 * @param $request Request
	 * @return bool
	 */
	function shibLogin($args, $request) {
		$this->_plugin = $this->_getPlugin();
		$this->_contextId = $this->_plugin->getCurrentContextId();
		$uinHeader = $this->_plugin->getSetting(
			$this->_contextId,
			'shibbolethHeaderUin'
		);
		$emailHeader = $this->_plugin->getSetting(
			$this->_contextId,
			'shibbolethHeaderEmail'
		);

		// We rely on these headers being present.
		if (!isset($_SERVER[$uinHeader])) {
			error_log(
				"Shibboleth plugin enabled, but not properly configured; failed to find $uinHeader"
			);
			Validation::logout();
			Validation::redirectLogin();
			return false;
		}
		if (!isset($_SERVER[$emailHeader])) {
			error_log(
				"Shibboleth plugin enabled, but not properly configured; failed to find $emailHeader"
			);
			Validation::logout();
			Validation::redirectLogin();
			return false;
		}

		$uin = $_SERVER[$uinHeader];
		$userEmail = $_SERVER[$emailHeader];

		// The UIN must be set; otherwise login failed.
		if (is_null($uin)) {
			Validation::logout();
			Validation::redirectLogin();
			return false;
		}

		// Try to locate the user by UIN.
		$userDao = DAORegistry::getDAO('UserDAO');
		$user = $userDao->getUserByAuthStr($uin, true);
		if (isset($user)) {
			syslog(LOG_INFO, "Shibboleth located returning user $uin");
		} else {
			// We use the e-mail as a key.
			if (empty($userEmail)) {
				error_log(
					"Shibboleth failed to find UIN $uin and no email given."
				);
				Validation::logout();
				Validation::redirectLogin();
				return false;
			}
			$user = $userDao->getUserByEmail($userEmail);

			if (isset($user)) {
				syslog(LOG_INFO, "Shibboleth located returning email $userEmail");

				if ($user->getAuthStr() != "") {
					error_log(
						"Shibboleth user with email $userEmail already has UID"
					);
					Validation::logout();
					Validation::redirectLogin();
					return false;
				} else {
					$user->setAuthStr($uin);
					$userDao->updateObject($user);
				}
			} else {
				$user = $this->_registerFromShibboleth();
			}
		}

		if (isset($user)) {
			$this->_checkAdminStatus($user);

			$disabledReason = null;
			$success = Validation::registerUserSession($user, $disabledReason);

			if (!$success) {
				// @@@ TODO: present user with disabled reason
				error_log(
					"Disabled user $uin attempted Shibboleth login" .
						(is_null($disabledReason) ? "" : ": $disabledReason")
				);
				Validation::logout();
				Validation::redirectLogin();
				return false;
			}

			return $this->_redirectAfterLogin($request);
		}

		return false;
	}

	/**
	 * @copydoc ShibbolethHandler::activateUser()
	 */
	function signIn($args, $request) {
		$this->_plugin = $this->_getPlugin();
		$this->_shibbolethOptional= $this->_plugin->getSetting(
			$this->_contextId,
			'shibbolethOptional'
		);

		if ( $this->_shibbolethOptional ) {
		/**
		 * This section is based off the code found in
		 * pkp-lib's LoginHandler.inc.php
		 * https://github.com/pkp/pkp-lib/blob/f64f302f8bef4f6c2e40275af717884c643f995b/pages/login/LoginHandler.inc.php#L90-L133
		 */
		$this->setupTemplate($request);
		if (Validation::isLoggedIn()) $this->sendHome($request);

		if (Config::getVar('security', 'force_login_ssl') && $request->getProtocol() != 'https') {
			// Force SSL connections for login
			$request->redirectSSL();
		}

		$user = Validation::login($request->getUserVar('username'), $request->getUserVar('password'), $reason, $request->getUserVar('remember') == null ? false : true);
		if ($user !== false) {
		if ($user->getMustChangePassword()) {
				// User must change their password in order to log in
				Validation::logout();
				$request->redirect(null, null, 'changePassword', $user->getUsername());
			} else {
				$source = $request->getUserVar('source');
				$redirectNonSsl = Config::getVar('security', 'force_login_ssl') && !Config::getVar('security', 'force_ssl');
				if (preg_match('#^/\w#', $source) === 1) {
					$request->redirectUrl($source);
				}
				if ($redirectNonSsl) {
					$request->redirectNonSSL();
				} else {
					$this->_redirectAfterLogin($request);
				}
			}
		} else {
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign(array(
				'username' => $request->getUserVar('username'),
				'remember' => $request->getUserVar('remember'),
				'source' => $request->getUserVar('source'),
				'showRemember' => Config::getVar('general', 'session_lifetime') > 0,
				'error' => $reason===null?'user.login.loginError':($reason===''?'user.login.accountDisabled':'user.login.accountDisabledWithReason'),
				'reason' => $reason,
			));
			$templateMgr->display('frontend/pages/userLogin.tpl');
		}
	}
	return $this->_shibbolethRedirect($request);
}

	/**
	 * Intercept normal logout; redirect to context home page instead
	 * of login (which would send back to Shibboleth again).
	 *
	 * @param $args array
	 * @param $request Request
	 * @return bool
	 */
	function signOut($args, $request) {
		$context = $this->getTargetContext($request);
		$router = $request->getRouter();

		Validation::logout();

		$contextPath = is_null($context) ? "" : $context->getPath();
		$returnUrl = $router->url($request, $contextPath);
		return $request->redirectUrl($returnUrl);
	}

	/**
	 * @copydoc ShibbolethHandler::activateUser()
	 */
	function validate($requiredContexts = null, $request = null) {
		import('lib.pkp.pages.user.RegistrationHandler');
		if ( True ) {
			return RegistrationHandler::validate($requiredContexts, $request);
		} else {
			return $this->_shibbolethRedirect($request);
		}
	}


	//
	// Private helper methods
	//
	/**
	 * Get the Shibboleth plugin object
	 *
	 * @return ShibbolethAuthPlugin
	 */
	function _getPlugin() {
		$plugin = PluginRegistry::getPlugin('generic', SHIBBOLETH_PLUGIN_NAME);
		return $plugin;
	}

	/**
	 * Check if the user should be an admin according to the
	 * Shibboleth plugin settings, and adjust the User object
	 * accordingly.
	 *
	 * @param $user User
	 */
	function _checkAdminStatus($user) {
		$adminsStr = $this->_plugin->getSetting(
			$this->_contextId,
			'shibbolethAdminUins'
		);
		$admins = explode(' ', $adminsStr);

		$uin = $user->getAuthStr();
		if (empty($uin)) {
			return;
		}

		$userId = $user->getId();
		$adminFound = array_search($uin, $admins);

		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');

		// should be unique
		$adminGroup = $userGroupDao->getByRoleId(0, ROLE_ID_SITE_ADMIN)->next();
		$adminId = $adminGroup->getId();

		// If they are in the list of users who should be admins
		if ($adminFound !== false) {
			// and if they are not already an admin
			if(!$userGroupDao->userInGroup($userId, $adminId)) {
				syslog(LOG_INFO, "Shibboleth assigning admin to $uin");
				$userGroupDao->assignUserToGroup($userId, $adminId);
			}
		} else {
			// If they are not in the admin list - then be sure they
			// are not an admin in the role table
			error_log("removing admin for $uin");
			$userGroupDao->removeUserFromGroup($userId, $adminId, 0);
		}
	}

	/**
	 * @copydoc LoginHandler::_redirectAfterLogin
	 */
	function _redirectAfterLogin($request) {
		$context = $this->getTargetContext($request);
		// If there's a context, send them to the dashboard after login.
		if ($context && $request->getUserVar('source') == '' &&
			array_intersect(
				array(ROLE_ID_SITE_ADMIN, ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_AUTHOR, ROLE_ID_REVIEWER, ROLE_ID_ASSISTANT),
				(array) $this->getAuthorizedContextObject(ASSOC_TYPE_USER_ROLES)
			)) {
			return $request->redirect($context->getPath(), 'dashboard');
		}

		return $request->redirectHome();
	}

	/**
	 * Create a new user from the Shibboleth-provided information.
	 *
	 * @return User
	 */
	function _registerFromShibboleth() {
		$uinHeader = $this->_plugin->getSetting(
			$this->_contextId,
			'shibbolethHeaderUin'
		);
		$emailHeader = $this->_plugin->getSetting(
			$this->_contextId,
			'shibbolethHeaderEmail'
		);
		$firstNameHeader = $this->_plugin->getSetting(
			$this->_contextId,
			'shibbolethHeaderFirstName'
		);
		$lastNameHeader = $this->_plugin->getSetting(
			$this->_contextId,
			'shibbolethHeaderLastName'
		);
		$initialsHeader = $this->_plugin->getSetting(
			$this->_contextId,
			'shibbolethHeaderInitials'
		);
		$phoneHeader = $this->_plugin->getSetting(
			$this->_contextId,
			'shibbolethHeaderPhone'
		);
		$mailingHeader = $this->_plugin->getSetting(
			$this->_contextId,
			'shibbolethHeaderMailing'
		);

		// We rely on these headers being present.	Redundant with the
		// login handler, but we need to check for more headers than
		// these; better safe than sorry.
		if (!isset($_SERVER[$uinHeader])) {
			error_log(
				"Shibboleth plugin enabled, but not properly configured; failed to find $uinHeader"
			);
			Validation::logout();
			Validation::redirectLogin();
			return false;
		}
		if (!isset($_SERVER[$emailHeader])) {
			error_log(
				"Shibboleth plugin enabled, but not properly configured; failed to find $emailHeader"
			);
			Validation::logout();
			Validation::redirectLogin();
			return false;
		}

		// required values
		$uin = $_SERVER[$uinHeader];
		$userEmail = $_SERVER[$emailHeader];
		$userFirstName = $_SERVER[$firstNameHeader];
		

		if (empty($uin) || empty($userEmail) || empty($userFirstName)) {
			error_log("Shibboleth failed to find required fields for new user");
		}

		// optional values
		$userInitials = isset($_SERVER[$initialsHeader]) ? $_SERVER[$initialsHeader] : null;
		$userPhone = isset($_SERVER[$phoneHeader]) ? $_SERVER[$phoneHeader] : null;
		$userMailing = isset($_SERVER[$mailingHeader]) ? $_SERVER[$mailingHeader] : null;
		$userLastName = isset($_SERVER[$lastNameHeader]) ? $_SERVER[$lastNameHeader] : null;

		$userDao = DAORegistry::getDAO('UserDAO');
		$user = $userDao->newDataObject();
		$user->setAuthStr($uin);
		$user->setUsername($userEmail);
		$user->setEmail($userEmail);

		// Get the site primary locale, needed for setting the given name
		// and family name of the user.
		$request = Application::getRequest();
		$site = $request->getSite();
		$sitePrimaryLocale = $site->getPrimaryLocale();

		$user->setGivenName($userFirstName, $sitePrimaryLocale);
		
		if (!empty($userLastName)) {
			$user->setFamilyName($userLastName, $sitePrimaryLocale);
		}
		if (!empty($userInitials)) {
			$user->setInitials($userInitials);
		}
		if (!empty($userPhone)) {
			$user->setPhone($userPhone);
		}
		if (!empty($userMailing)) {
			$user->setMailingAddress($userMailing);
		}		
		

		$user->setDateRegistered(Core::getCurrentDate());
		$user->setPassword(
			Validation::encryptCredentials(
				Validation::generatePassword(40),
				Validation::generatePassword(40)
			)
		);

		$userDao->insertObject($user);
		$userId = $user->getId();
		if ($userId) {
			return $user;
		} else {
			return null;
		}
	}

	/**
	 * Intercept normal login/registration requests; defer to Shibboleth.
	 *
	 * @param $request Request
	 * @return bool
	 */
	function _shibbolethRedirect($request) {
		return $request->redirectUrl($this->_shibbolethLoginUrl($request));
	}

	/**
	 * Generate Shibboleth Request Url
	 *
	 * @param $request Request
	 * @return string
	 */
	function _shibbolethLoginUrl($request) {
		$this->_plugin = $this->_getPlugin();
		$this->_contextId = $this->_plugin->getCurrentContextId();
		$context = $this->getTargetContext($request);
		$router = $request->getRouter();

		$wayfUrl = $this->_plugin->getSetting(
			$this->_contextId,
			'shibbolethWayfUrl'
		);
		$shibLoginUrl = $router->url(
			$request,
			null,
			'shibboleth',
			'shibLogin',
			null,
			null,
			true
		);
		return $wayfUrl . '?target=' . $shibLoginUrl;
	}

	function _isShibbolethOptional() {
		$this->_plugin = $this->_getPlugin();
		return $this->_plugin->getSetting(
			$this->_contextId,
			'shibbolethOptional'
		);
	}
}
