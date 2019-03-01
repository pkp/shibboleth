<?php
/**
 * @defgroup plugins_generic_shibboleth Shibboleth Authentication Plugin
 */
 
/**
 * @file plugins/generic/shibboleth/index.php
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_generic_shibboleth
 * @brief Wrapper for loading the Shibboleth authentication plugin.
 *
 */

require_once('ShibbolethAuthPlugin.inc.php');

return new ShibbolethAuthPlugin();
