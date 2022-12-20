<?php

require_once(__DIR__.'/vendor/autoload.php');
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

        $this->bootstrapAvatarSource($config);
    }

    private function bootstrapAvatarSource($config) {
        $tenantId = $config->get('TENANT_ID');
        $clientId = $config->get('CLIENT_ID');
        $clientSecret = $config->get('CLIENT_SECRET');
        $allowedDomains = explode(',', $config->get('ALLOWED_AVATAR_DOMAINS'));

        // Cannot activate this feature if the required configuration keys are missing
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
            $credentialsCachePool = new Cache\Adapter\Chain\CachePoolChain([$apcuPool, $filesystemPool]);
        }

        require_once('microsoft_graph.php');

        $clientApp = new ConfidentialClientApplication($tenantId, $clientId, $clientSecret, $credentialsCachePool);

        require_once('avatar_source.php');
        require_once('avatar_loader.php');

        $avatarLoader = new AvatarLoader($filesystemPool, $clientApp, $allowedDomains);
        $avatarLoader->bootstrap();
    }
}

require_once(INCLUDE_DIR.'UniversalClassLoader.php');
use Symfony\Component\ClassLoader\UniversalClassLoader_osTicket;
$loader = new UniversalClassLoader_osTicket();
$loader->registerNamespaceFallbacks(array(
    dirname(__file__).'/lib'));
$loader->register();
