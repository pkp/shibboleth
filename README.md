# Shibboleth plugin for PKP

With this plugin enabled, a Shibboleth single sign-on service can be used to register and authenticate users. You must have set up and configured the local service provider (SP).

## Requirements

* OJS 3.2 or later
* Shibboleth SP installed within the webserver
  * Your Shibboleth SP must be installed and configured to export Shibboleth attributes to environment variables exposed to the webserver
  * Minimal attributes required are: user id, email, and given name

## Installation

Install this as a "generic" plugin in OJS.  The preferred installation method is via the Plugin Gallery.

To install manually via the filesystem, extract the contents of this archive to a "shibboleth" directory under "plugins/generic" in your OJS root.  To install via Git submodule, target that same directory path: `git submodule add https://github.com/pkp/shibboleth plugins/generic/shibboleth`.  Run the installation script to register this plugin, e.g.: `php lib/pkp/tools/installPluginVersion.php plugins/generic/shibboleth/version.xml`.

## Configuration

You must be the site administrator in order to enable or configure this plugin.  After enabling this module you will minimally need to provide the path to the Shibboleth SP endpoint, and the environment variable names of the Shibboleth attributes.  The full configuration options include:

* Shibboleth SP configuration:
  * SP endpoint
  * HTTP Header ($_SERVER environment variable key):
    * Shibboleth UIN
    * first or given name
    * last, family, or surname
    * personal initials
    * e-mail address
    * telephone number
    * postal mailing address
  * List of Shibboleth user IDs or UINs who are OJS administrators
* Shibboleth login display:
  * Is Shibboleth usage optional?
  * Label for login
  * Description for login
  * Description for registration
  * Button label

## License

Released under a license of GPL v2 or later.
