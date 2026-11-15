<?php
/**
 * Coino Live: safety net for missed callbacks. Every WHMCS cron run re-checks recent payments that can still change.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('AfterCronJob', 1, function () {
    $lib = ROOTDIR . '/modules/gateways/coinolive/lib/';
    if (!is_file($lib . 'Gateway.php')) {
        return;
    }
    require_once $lib . 'Lang.php';
    require_once $lib . 'Client.php';
    require_once $lib . 'Gateway.php';

    try {
        \CoinoLive\Whmcs\Gateway::syncOpen();
    } catch (\Throwable $e) {
        logActivity('Coino Live sync failed: ' . $e->getMessage());
    }
});
