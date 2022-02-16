# osTicket plugin for Microsoft 365 authentication

This repository provides an [osTicket](https://osticket.com/) plugin for authentication using Microsoft 365 accounts, registerd in an [Azure Active Directory](https://azure.microsoft.com/en-us/services/active-directory/) tenant.

## Motivation

The code is based on [the `auth-openid-MS` plugin](https://github.com/cbasolutions/osTicket-Plugins/tree/master/auth-openid-MS). The primary change is that the original didn't validate the received JSON Web Token, leaving it exposed to token forgery attacks. This version validates them using [`OpenID-Connect-PHP`](https://github.com/jumbojett/OpenID-Connect-PHP).


## Installation/upgrade instructions

To install the plugin, clone this repository into your osTicket instance's `include/plugins/` directory. To upgrade to newer versions, simply use `git pull`.

After installing it for the first time or upgrading to a newer version, use [Composer](https://getcomposer.org/) to also update the dependencies:

```sh
composer install
```

You can then enable the plugin in your osTicket admin dashboard.
