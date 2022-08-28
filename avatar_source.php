<?php

require_once(INCLUDE_DIR.'class.avatar.php');

class Microsoft365AvatarSource
extends AvatarSource {
    static $name = 'Microsoft 365';
    static $id = 'ms365';
    var $mode;

    function __construct($mode=null) {
        $this->mode = $mode ?: 'default';
    }

    static function getModes() {
        return array(
            'default' => __('Default'),
        );
    }

    function getAvatar($user) {
        return new Microsoft365Avatar($user);
    }
}
AvatarSource::register('Microsoft365AvatarSource');

class Microsoft365Avatar
extends Avatar {
    var $email;

    function __construct($user) {
        parent::__construct($user);
        $this->email = $user->getEmail();
    }

    function getUrl($size) {
        return ROOT_PATH . 'api/ms365-avatar/' . $this->email;
    }
}
