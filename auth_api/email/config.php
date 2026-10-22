<?php
require_once __DIR__.'/../../config/EnvConfig.php';
return ['cpanel' => [
    'username' => EnvConfig::get('CPANEL_USERNAME', ''),
    'password' => EnvConfig::get('CPANEL_PASSWORD', ''),
    'domain' => EnvConfig::get('CPANEL_DOMAIN', ''),
    'url' => EnvConfig::get('CPANEL_URL', '')
]];
