<?php

require __DIR__ . '/vendor/autoload.php';

use Jumbojett\OpenIDConnectClient;

class MicrosoftProviderAuth {

    var $config;
    var $access_token;

    function __construct($config) {
        $this->config = $config;
    }

    function triggerAuth() {
        error_log('Performing authentication using MS365');

        global $ost;
        $self = $this;

        $login_type = $_SESSION['ext:bk:login_type'];

        $redirectUri = rawurlencode(rtrim($ost->getConfig()->getURL(), '/') . '/api/auth/ext');
        $clientId = $this->config->get('CLIENT_ID');
        $clientSecret = $this->config->get('CLIENT_SECRET');
        $scopes = rawurlencode($this->config->get('SCOPES'));
        $resourceUrl = $this->config->get('RESOURCE_ID') . $this->config->get('RESOURCE_ENDPOINT');
        $nonce = $_COOKIE['OSTSESSID'];
        if (!isset($_REQUEST['id_token'])) {
            $authUrl = $this->config->get('AUTHORITY_URL') . $this->config->get('AUTHORIZE_ENDPOINT') . '?client_id='. $clientId . '&response_type=id_token%20code&redirect_uri=' . $redirectUri . '&response_mode=form_post&scope=' . $scopes . '&state=' . $login_type . '&nonce=' . $nonce;
            header('Location: ' . $authUrl);
            echo 'Redirecting to MS365 authentication page...';
            exit;
        } else {
            error_log('Validating ID token');

            $jwt = $_REQUEST['id_token'];

            $oidc = new OpenIDConnectClient(
                $this->config->get('AUTHORITY_URL'),
                $clientId,
                $clientSecret,
            );

            if (!$oidc->verifyJWTsignature($jwt)) {
                http_response_code(400);
                echo('Token de autentificare invalid.');
                exit;
            }

            error_log('MS365 ID token is valid, constructing user session');

            $jwt = explode('.', $jwt);
            $authInfo = json_decode(base64_decode($jwt[1]), true);
            $_SESSION[':openid-ms']['name'] = $authInfo['name'];
            $_SESSION[':openid-ms']['oid'] = $authInfo['oid'];
            if (isset($authInfo['email'])) {
                $_SESSION[':openid-ms']['email'] = $authInfo['email'];
            } elseif (isset($authInfo['preferred_username']) && (filter_var($authInfo['preferred_username'], FILTER_VALIDATE_EMAIL))) {
                $_SESSION[':openid-ms']['email'] = $authInfo['preferred_username'];
            }
            $_SESSION[':openid-ms']['nonce'] = $authInfo['nonce'];

            error_log('Performing redirect for login type ' . $login_type);

            if ($login_type == 'CLIENT') {
                Http::redirect(ROOT_PATH . 'home.php');
                echo 'Redirecting to client home page';
            } else if ($login_type == 'STAFF') {
                Http::redirect(ROOT_PATH . 'scp/login.php');
                echo 'Redirecting to staff home page';
            } else {
                http_response_code(400);
                echo 'Invalid login type!';
            }

            exit;
        }
    }
}
class MicrosoftOpenIDClientAuthBackend extends ExternalUserAuthenticationBackend {
    static $id = "openid_ms.client";
    static $name = "Microsoft OpenID Auth - Client";

    static $sign_in_image_url = "https://docs.microsoft.com/en-us/azure/active-directory/develop/media/active-directory-branding-guidelines/sign-in-with-microsoft-light.png";
    static $service_name = "Microsoft OpenID Auth - Client";

    function __construct($config) {
        $this->config = $config;
        if ($_SERVER['SCRIPT_NAME'] === '/login.php' || $_SERVER['SCRIPT_NAME'] === '/open.php') {
            $_SESSION['ext:bk:login_type'] = 'CLIENT';
            if ($this->config->get('HIDE_LOCAL_CLIENT_LOGIN')) {
                if ($this->config->get('PLUGIN_ENABLED_AWESOME')) {
                    ?>
                    <script>
                        window.onload = function () {
                            "use strict";
                            document.getElementById("one-view-page").remove();
                            document.getElementById("middle-view-page").remove();
                            /*something odd happens to this DIV when using these hacks.*/
                            document.getElementById("header-logo-subtitle").remove();
                            var eAuth = document.getElementsByClassName("external-auth");
                            while (eAuth[0].nextSibling) {
                                eAuth[0].nextSibling.remove();
                            }
                        };
                    </script>
                    <?php
                } else {
                    ?>
                    <script>window.onload = function() {
                            var loginBox = document.getElementsByClassName('login-box');
                            loginBox[0].remove();
                            var eAuth = document.getElementsByClassName('external-auth');
                            while (eAuth[0].nextSibling) {
                                eAuth[0].nextSibling.remove();
                            }
                        };
                    </script>
                    <?php
                }
            }
        }
        $this->MicrosoftAuth = new MicrosoftProviderAuth($config);
    }

    function supportsInteractiveAuthentication() {
        return false;
    }

    function signOn() {
        global $errors;
        $self = $this;

        if (isset($_SESSION[':openid-ms']['email'])) {
            // Check email for access
            $emailDomain = substr(strrchr($_SESSION[':openid-ms']['email'], "@"), 1);
            $allowedDomains = explode(',', $this->config->get('ALLOWED_CLIENT_DOMAINS'));
            if (in_array(strtolower($emailDomain), array_map('strtolower', $allowedDomains)) || ($this->config->get('ALLOWED_CLIENT_DOMAINS') == '')) {
                if (($acct = ClientAccount::lookupByUsername($_SESSION[':openid-ms']['email'])) && $acct->getId() && ($client = new ClientSession(new EndUser($acct->getUser())))) {
                    return $client;
                } else {
                    $info = array(
                        'email' => $_SESSION[':openid-ms']['email'],
                        'name' => $_SESSION[':openid-ms']['name'],
                    );
                    return new ClientCreateRequest($this, $info['email'], $info);
                }
            }
        }
    }

    static function signOut($user) {
        parent::signOut($user);
        unset($_SESSION[':openid-ms']);
        //https://login.microsoftonline.com/common/oauth2/logout?post_logout_redirect_uri=http%3A%2F%2Flocalhost%2Fmyapp%2F
    }

    function triggerAuth() {
        parent::triggerAuth();
        $MicrosoftAuth = $this->MicrosoftAuth->triggerAuth();
    }
}

class MicrosoftOpenIDStaffAuthBackend extends ExternalStaffAuthenticationBackend {
    static $id = "openid_ms.staff";
    static $name = "Micrsoft OpenID Auth - Staff";
    static $service_name = "Microsoft OpenID Auth - Staff";
    static $sign_in_image_url = "https://docs.microsoft.com/en-us/azure/active-directory/develop/media/active-directory-branding-guidelines/sign-in-with-microsoft-light.png";

    function __construct($config) {
        $this->config = $config;
        $sign_in_image_url = $this->config->get('LOGIN_LOGO');
        if ($_SERVER['SCRIPT_NAME'] === '/scp/login.php') {
            $_SESSION['ext:bk:login_type'] = 'STAFF';
            if ($this->config->get('HIDE_LOCAL_STAFF_LOGIN')) {
                ?>
                <script>window.onload = function() {
                        var login = document.getElementById('login');
                        login.remove();
                    };
                </script>
                <?php
            }
        }
        $this->MicrosoftAuth = new MicrosoftProviderAuth($config);
    }

    function supportsInteractiveAuthentication() {
        return false;
    }

    function signOn() {
        if (isset($_SESSION[':openid-ms']['email'])) {
            $emailDomain = substr(strrchr($_SESSION[':openid-ms']['email'], "@"), 1);
            $allowedDomains = explode(',', $this->config->get('ALLOWED_STAFF_DOMAINS'));
            if (in_array(strtolower($emailDomain), array_map('strtolower', $allowedDomains)) || ($this->config->get('ALLOWED_STAFF_DOMAINS') == '')) {
                if (($staff = StaffSession::lookup(array('email' => $_SESSION[':openid-ms']['email'])))
                    && $staff->getId()
                ) {
                    if (!$staff instanceof StaffSession) {
                        // osTicket <= v1.9.7 or so
                        $staff = new StaffSession($user->getId());
                    }
                    return $staff;
                }
                else
                    $_SESSION['_staff']['auth']['msg'] = 'Have your administrator create a local account';
            }
        }
    }

    static function signOut($user) {
        parent::signOut($user);
        unset($_SESSION[':openid-ms']);
        //https://login.microsoftonline.com/common/oauth2/logout?post_logout_redirect_uri=http%3A%2F%2Flocalhost%2Fmyapp%2F
    }

    function triggerAuth() {
        parent::triggerAuth();
        $MicrosoftAuth = $this->MicrosoftAuth->triggerAuth();
    }
}
