<?php

require_once(INCLUDE_DIR.'class.plugin.php');
require_once('config.php');

class OpenIDAuthMS extends Plugin {
    var $config_class = "OpenIDAuthMSPluginConfig";

    function bootstrap() {
        $config = $this->getConfig();
        $clientAccess = $config->get('PLUGIN_ENABLED_CLIENT');
        $staffAccess = $config->get('PLUGIN_ENABLED_STAFF');
        if ($staffAccess) {
            require_once('openid_ms.php');
            StaffAuthenticationBackend::register(
                new MicrosoftOpenIDStaffAuthBackend($this->getConfig()));
        }
        if ($clientAccess) {
            require_once('openid_ms.php');
            UserAuthenticationBackend::register(
                new MicrosoftOpenIDClientAuthBackend($this->getConfig()));
        }

        require_once('microsoft_graph.php');

        $this->bootstrapAvatarSource($config);
    }

    private function bootstrapAvatarSource($config) {
        $tenantId = $config->get('TENANT_ID');
        $clientId = $config->get('CLIENT_ID');
        $clientSecret = $config->get('CLIENT_SECRET');

        if (is_null($tenantId) || is_null($clientId) || is_null($clientSecret)) {
            return;
        }

        // Set up caching using the local filesystem
        $filesystemAdapter = new League\Flysystem\Adapter\Local('/tmp/osticket');
        $filesystem = new League\Flysystem\Filesystem($filesystemAdapter);

        $filesystemPool = new Cache\Adapter\Filesystem\FilesystemCachePool($filesystem);

        $credentialsCachePool = $filesystemPool;

        // Use APCu for in-memory caching of the access token, if possible
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            $apcuPool = new Cache\Adapter\Apcu\ApcuCachePool();
            $pool = new Cache\Adapter\Chain\CachePoolChain([$apcuPool, $filesystemPool]);
        }

        $clientApp = new ConfidentialClientApplication($tenantId, $clientId, $clientSecret, $credentialsCachePool);

        Signal::connect('api', function($dispatcher) use ($clientApp, $filesystemPool) {
            $dispatcher->append(
                url_get('^/ms365-avatar/(?P<email>.+)$', function($email) use ($clientApp, $filesystemPool) {
                    $currentUser = UserAuthenticationBackend::getUser() ?? StaffAuthenticationBackend::getUser();

                    if (is_null($currentUser)) {
                        echo 'Unauthenticated!';
                        return;
                    }

                    // Convert the e-mail address to a valid file name
                    $sanitizedEmail = str_replace('@', '_', $email);
                    $sanitizedEmail = str_replace('-', '_', $sanitizedEmail);

                    $contentCacheItem = $filesystemPool->getItem('MS365_Avatar_' . $sanitizedEmail . '_Content');
                    $contentTypeCacheItem = $filesystemPool->getItem('MS365_Avatar_' . $sanitizedEmail . '_Content_Type');

                    // We want these profile pictures to be cached for up to one week.
                    $profilePictureExpirationTime = 604_800;

                    if ($contentCacheItem->isHit()) {
                        $content = $contentCacheItem->get();

                        // User doesn't have a profile picture defined, use the fallback image
                        if (empty($content)) {
                            $content = file_get_contents(__DIR__ . '/images/user.png');
                            $contentType = 'image/png';
                        } else {
                            $contentType = $contentTypeCacheItem->get();
                        }
                    } else {
                        $accessToken = $clientApp->acquireToken();

                        $guzzle = new GuzzleHttp\Client();
                        $url = 'https://graph.microsoft.com/v1.0/users/' . $email . '/photos/96x96/$value';

                        try {
                            $response = $guzzle->get($url, [
                                'headers' => [
                                    'Authorization' => "Bearer $accessToken"
                                ]
                            ]);

                            $content = $response->getBody();
                            $contentType = $response->getHeader('Content-Type');

                            $cachedContent = $content->getContents();
                            $cachedContentType = $contentType;
                        } catch (GuzzleHttp\Exception\ClientException $clientException) {
                            $code = $clientException->getCode();
                            if ($code == 404) {
                                $content = file_get_contents(__DIR__ . '/images/user.png');
                                $contentType = 'image/png';

                                $cachedContent = '';
                                $cachedContentType = $contentType;
                            } else {
                                error_log($clientException->getMessage());
                                echo "Failed to fetch user's profile picture; error code $code";
                                return;
                            }
                        }

                        $contentCacheItem->set($cachedContent);
                        $contentCacheItem->expiresAfter($profilePictureExpirationTime);
                        $filesystemPool->save($contentCacheItem);

                        $contentTypeCacheItem->set($cachedContentType);
                        $contentTypeCacheItem->expiresAfter($profilePictureExpirationTime);
                        $filesystemPool->save($contentTypeCacheItem);
                    }

                    header('Content-Type: ' . $contentType);
                    header('Cache-Control: private, max-age=' . $profilePictureExpirationTime);
                    header_remove('Expires');
                    header_remove('Pragma');

                    echo $content;
                })
            );
        });

        require_once('avatar_source.php');
    }
}

require_once(INCLUDE_DIR.'UniversalClassLoader.php');
use Symfony\Component\ClassLoader\UniversalClassLoader_osTicket;
$loader = new UniversalClassLoader_osTicket();
$loader->registerNamespaceFallbacks(array(
    dirname(__file__).'/lib'));
$loader->register();
