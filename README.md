# osTicket plugin for Azure AD authentication

This repository provides an [osTicket](https://osticket.com/) plugin for authentication using [Azure Active Directory](https://azure.microsoft.com/en-us/services/active-directory/) accounts.

The code is based on [the `auth-openid-MS` plugin](https://github.com/cbasolutions/osTicket-Plugins/tree/master/auth-openid-MS). The primary change is that the original didn't validate the received JSON Web Token, leaving it exposed to token forgery attacks. This version validates them using [`OpenID-Connect-PHP`](https://github.com/jumbojett/OpenID-Connect-PHP).
