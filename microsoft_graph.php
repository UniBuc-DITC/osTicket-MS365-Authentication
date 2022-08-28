<?php

use GuzzleHttp\Client;
use Psr\Cache\CacheItemPoolInterface;

class ConfidentialClientApplication {
    private string $tenantId;
    private string $clientId;
    private string $clientSecret;
    private CacheItemPoolInterface $pool;

    function __construct(string $tenantId, string $clientId, string $clientSecret,
                         CacheItemPoolInterface $pool) {
        $this->tenantId = $tenantId;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->pool = $pool;
    }

    /**
     * Obtains an access token for Microsoft Graph using the client credentials flow.
     *
     * @return string The access token
     */
    function acquireToken(): string {
        $item = $this->pool->getItem('MS365_ConfidentialClientAccessToken');

        if ($item->isHit()) {
            $token = $item->get();
        } else {
            error_log('Acquiring access token from MS365 API');

            $guzzle = new Client();
            $url = 'https://login.microsoftonline.com/' . $this->tenantId . '/oauth2/v2.0/token';
            $token = json_decode($guzzle->post($url, [
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ],
            ])->getBody()->getContents());

            $accessToken = $token->access_token;

            $parts = explode('.', $accessToken);
            $payload = $parts[1];
            $payload = base64_decode($payload);
            $payload = json_decode($payload);
            $expires = $payload->exp;

            $expiresAt = new DateTimeImmutable("@$expires");

            $item->set($accessToken);
            $item->expiresAt($expiresAt);
            $this->pool->save($item);

            $token = $accessToken;
        }

        return $token;
    }
}
