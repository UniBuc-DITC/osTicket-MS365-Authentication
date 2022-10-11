<?php

use GuzzleHttp\Exception\GuzzleException;
use Psr\SimpleCache\CacheInterface;

require_once('microsoft_graph.php');

/**
 * Implements the loading of profile pictures from Microsoft Graph,
 * by registering a new `GET` route, `/api/ms365-avatar/<user-email>`.
 */
class AvatarLoader {
    private CacheInterface $cachePool;
    private ConfidentialClientApplication $clientApp;
    private array $allowedDomains;

    // We want profile pictures to be cached for up to one week.
    private static int $profilePictureExpirationTime = 604_800;

    function __construct(CacheInterface $cachePool,
                         ConfidentialClientApplication $clientApp,
                         array $allowedDomains = array()) {
        $this->cachePool = $cachePool;
        $this->clientApp = $clientApp;
        $this->allowedDomains = array_map('strtolower', $allowedDomains);
    }

    /**
     * Registers the API handler for the avatar loading route.
     *
     * @return void
     */
    function bootstrap() {
        Signal::connect('api', function($dispatcher) {
            $dispatcher->append(
                url_get('^/ms365-avatar/(?P<email>.+)$', array($this, 'handleGetAvatarRequest'))
            );
        });
    }

    /**
     * Processes an avatar loading request received from the client.
     *
     * @param $email string The e-mail address of the user to load the profile picture for.
     * @return void
     * @throws GuzzleException
     */
    function handleGetAvatarRequest(string $email) {
        $currentUser = UserAuthenticationBackend::getUser() ?? StaffAuthenticationBackend::getUser();

        if (is_null($currentUser)) {
            echo 'Unauthenticated!';
            return;
        }

        if (!empty($this->allowedDomains)) {
            $emailDomain = substr(strrchr($email, '@'), 1);
            if (!in_array(strtolower($emailDomain), $this->allowedDomains)) {
                $this->sendFallbackPictureResponse();
                return;
            }
        }

        // Convert the e-mail address to a valid file name
        $sanitizedEmail = str_replace('@', '_', $email);
        $sanitizedEmail = str_replace('-', '_', $sanitizedEmail);

        $contentCacheItem = $this->cachePool->getItem('MS365_Avatar_' . $sanitizedEmail . '_Content');
        $contentTypeCacheItem = $this->cachePool->getItem('MS365_Avatar_' . $sanitizedEmail . '_Content_Type');

        if ($contentCacheItem->isHit()) {
            $content = $contentCacheItem->get();

            // User doesn't have a profile picture defined, use the fallback image
            if (empty($content)) {
                $this->sendFallbackPictureResponse();
                return;
            } else {
                $contentType = $contentTypeCacheItem->get();
            }
        } else {
            $accessToken = $this->clientApp->acquireToken();

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
            $contentCacheItem->expiresAfter(self::$profilePictureExpirationTime);
            $this->cachePool->save($contentCacheItem);

            $contentTypeCacheItem->set($cachedContentType);
            $contentTypeCacheItem->expiresAfter(self::$profilePictureExpirationTime);
            $this->cachePool->save($contentTypeCacheItem);
        }

        $this->sendResponse($contentType, $content);
    }

    private function sendFallbackPictureResponse() {
        $content = file_get_contents(__DIR__ . '/images/user.png');
        $this->sendResponse('image/png', $content);
    }

    private function sendResponse($contentType, $content) {
        header('Content-Type: ' . $contentType);
        header('Cache-Control: private, max-age=' . self::$profilePictureExpirationTime);
        header_remove('Expires');
        header_remove('Pragma');

        echo $content;
    }
}
