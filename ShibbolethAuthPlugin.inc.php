<?php

/**
 * @file plugins/generic/shibboleth/ShibbolethAuthPlugin.inc.php
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class ShibbolethAuthPlugin
 * @ingroup plugins_generic_shibboleth
 *
 * @brief Shibboleth authentication plugin.
 *
 * Assumes Apache mod_shib and appropriate configuration.
 */

import('lib.pkp.classes.plugins.GenericPlugin');
define('SHIBBOLETH_PLUGIN_NAME', 'ShibbolethAuthPlugin');

class ShibbolethAuthPlugin extends GenericPlugin {
	// @@@ TODO: Is there a way to disable delete and upgrade actions
	// when the user does not have permission to disable?

	// @@@ TODO: The profile password tab should just be hidden
	// completely when the plugin is enabled.

	/** @var int */
	var $_contextId;

	/** @var bool */
	var $_globallyEnabled;

	var $settingsRequired = ["shibbolethWayfUrl", "shibbolethHeaderUin", "shibbolethHeaderFirstName", "shibbolethHeaderEmail"];
	/**
	 * @copydoc Plugin::__construct()
	 */
	function __construct() {
		parent::__construct();
		$this->_contextId = $this->getCurrentContextId();
		$this->_globallyEnabled = $this->getSetting(CONTEXT_SITE, 'enabled');
		if ($this->_globallyEnabled) {
			$this->_contextId = CONTEXT_SITE;
		}
	}

	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		$this->addLocaleData();
		if ($success && $this->getEnabled() && $this->isShibbolethConfigured()) {
			// Register pages to handle login.
			HookRegistry::register('LoadHandler',	array($this, 'handleRequest'));

			// Register callback for smarty filters
			HookRegistry::register('TemplateManager::display', array($this, 'handleTemplateDisplay'));
		}
		return $success;
	}

	/**
	 * @copydoc LazyLoadPlugin::getName()
	 */
	function getName() {
		return 'ShibbolethAuthPlugin';
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.generic.shibboleth.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.generic.shibboleth.description');
	}

	/**
	 * @copydoc Plugin::isSitePlugin()
	 */
	function isSitePlugin() {
		return true;
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	function manage($args, $request) {
		switch ($request->getUserVar('verb')) {
			case 'settings':
				AppLocale::requireComponents(
					LOCALE_COMPONENT_APP_COMMON,
					LOCALE_COMPONENT_PKP_MANAGER
				);
				$templateMgr = TemplateManager::getManager($request);
				$templateMgr->register_function(
					'plugin_url',
					array($this, 'smartyPluginUrl')
				);

				$this->import('ShibbolethSettingsForm');
				$form = new ShibbolethSettingsForm(
					$this,
					$this->_contextId
				);

				if ($request->getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						return new JSONMessage(true);
					}
				} else {
					$form->initData();
				}
				return new JSONMessage(true, $form->fetch($request));
		}
		return parent::manage($args, $request);
	}

	/**
	 * @copydoc Plugin::getSetting()
	 */
	function getSetting($contextId, $name) {
		if ($this->_globallyEnabled) {
			return parent::getSetting(CONTEXT_SITE, $name);
		} else {
			return parent::getSetting($contextId, $name);
		}
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
	function getActions($request, $verb) {
		// Don’t allow settings unless enabled in this context.
		if (!$this->getEnabled() || !$this->getCanDisable()) {
			return parent::getActions($request, $verb);
		}

		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		return array_merge(
			array(
				new LinkAction(
					'settings',
					new AjaxModal(
						$router->url(
							$request,
							null,
							null,
							'manage',
							null,
							array(
								'verb' => 'settings',
								'plugin' => $this->getName(),
								'category' => 'generic'
							)
						),
						$this->getDisplayName()
					),
					__('manager.plugins.settings'),
					null
				),
			),
			parent::getActions($request, $verb)
		);
	}


	//
	// Public methods required to support lazy load.
	//
	/**
	 * @copydoc LazyLoadPlugin::getCanEnable()
	 */
	function getCanEnable() {
		return !$this->_globallyEnabled || $this->_contextId == CONTEXT_SITE;
	}

	/**
	 * @copydoc LazyLoadPlugin::getCanDisable()
	 */
	function getCanDisable() {
		return !$this->_globallyEnabled || $this->_contextId == CONTEXT_SITE;
	}

	/**
	 * @copydoc LazyLoadPlugin::setEnabled()
	 */
	function setEnabled($enabled) {
		$this->updateSetting($this->_contextId, 'enabled', $enabled, 'bool');
	}
	/**
	 * Determine whether or not this plugin is currently enabled.
	 * @param $contextId integer is ignored
	 * @return boolean
	 */
	function getEnabled($contextId = null) {
		return $this->getSetting($this->_contextId, 'enabled');
	}

        /**
         * @copydoc Plugin::isShibbolethConfigured()
         * Determine whether or not this plugin is currently configured.
         * @return boolean
         */
        function isShibbolethConfigured() {
		foreach ($this->settingsRequired as $setting){
			if ($this->getSetting($this->_contextId, $setting) == null) {
				return false;
			}
		}
		return true;
        }

	//
	// Callback handler
	// 
	/**
	 * Hook callback: register pages for each login method.
	 * This URL is of the form: shibboleth/{$shibrequest}
	 * @see PKPPageRouter::route()
	 */
	function handleRequest($hookName, $params) {
		$page = $params[0];
		$op = $params[1];
		
		// modify user login
		$loginOps = ['index', 'signIn', 'signOut'];
		if (!$this->_isShibbolethOptional()) {
			// If Shibboleth is required, override password functionality
			$loginOps = array_merge($loginOps, ['changePassword', 'lostPassword', 'requestResetPassword', 'savePassword']);
		}
		
		if ($this->getEnabled()
			&& ($page == 'shibboleth'
				|| ($page == 'login'
					&& array_search(
						$op,
						$loginOps
					))
				|| ($page == 'user'
					&& array_search(
						$op,
						array(
							'activateUser',
							'register',
							'registerUser',
							'validate',
						)
					)
				)
			)
		) {
			$this->import('pages/ShibbolethHandler');
			define('HANDLER_CLASS', 'ShibbolethHandler');
			return true;
		}
		return false;
	}

	/**
	 * Hook callback: register output filter for user registration 
	 *
	 * @param $hookName string
	 * @param $args array
	 * @return bool
	 * @see TemplateManager::display()
	 *
	 */
	function handleTemplateDisplay($hookName, $args) {
		$templateMgr =& $args[0];
		$template =& $args[1];
		$request = PKPApplication::get()->getRequest();

		switch ($template) {
			case 'frontend/pages/userRegister.tpl':
				$templateMgr->registerFilter("output", array($this, 'registrationFilter'));
				break;
			case 'frontend/pages/userLogin.tpl':
				$templateMgr->registerFilter("output", array($this, 'loginFilter'));
				break;
		}
		return false;
	}
	
	function registrationFilter($output, $templateMgr) {
		$isRegistration = True;
		return $this->registrationAndLoginFilter($output, $templateMgr, $isRegistration);
	}

	function loginFilter($output, $templateMgr) {
		$isRegistration = False;
		return $this::registrationAndLoginFilter($output, $templateMgr, $isRegistration);
	}
	
	/**
	 * Output filter adds Shibboleth interaction to registration and login form.
	 *
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @param $isRegistration boolean
	 * @return string
	 */
	function registrationAndLoginFilter($output, $templateMgr, $isRegistration) {
		if ($isRegistration) {
			$htmlId = "register";
		} else {
			$htmlId = "login";
		}
		if (preg_match('/<form[^>]+id="' . $htmlId . '"[^>]+>/', $output, $matches, PREG_OFFSET_CAPTURE)) {
			$this->_plugin = $this->_getPlugin();
			$this->_shibbolethOptionalTitle = $this->_plugin->getSetting(
				$this->_contextId,
				'shibbolethOptionalTitle'
			);
			$this->_shibbolethOptionalButtonLabel = $this->_plugin->getSetting(
				$this->_contextId,
				'shibbolethOptionalButtonLabel'
			);
			if ($isRegistration) {
				$this->_shibbolethOptionalDescription = $this->_plugin->getSetting(
					$this->_contextId,
					'shibbolethOptionalRegistrationDescription'
				);
			} else {
				$this->_shibbolethOptionalDescription = $this->_plugin->getSetting(
					$this->_contextId,
					'shibbolethOptionalLoginDescription'
				);
			}
			$match = $matches[0][0];
			$offset = $matches[0][1];
			$request = Application::get()->getRequest();

			$templateMgr->assign('shibbolethLoginUrl', $this->_shibbolethLoginUrl($request));
			$templateMgr->assign('shibbolethTitle', $this->_shibbolethOptionalTitle);
			$templateMgr->assign('shibbolethButtonLabel', $this->_shibbolethOptionalButtonLabel);
			$templateMgr->assign('shibbolethDescription', $this->_shibbolethOptionalDescription);
			$templateMgr->assign('isRegistration', $isRegistration);

			$newOutput = substr($output, 0, $offset + strlen($match));
			$newOutput .= $templateMgr->fetch($this->getTemplateResource('shibbolethProfile.tpl'));
			$newOutput .= substr($output, $offset + strlen($match));
			$output = $newOutput;
			$templateMgr->unregisterFilter('output', array($this, 'registrationFilter'));
		}
		return $output;
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
	 * Generate Shibboleth Request Url
	 *
	 * @param $request Request
	 * @return string
	 */
	function _shibbolethLoginUrl($request) {
		$this->_plugin = $this->_getPlugin();
		$this->_contextId = $this->_plugin->getCurrentContextId();
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
