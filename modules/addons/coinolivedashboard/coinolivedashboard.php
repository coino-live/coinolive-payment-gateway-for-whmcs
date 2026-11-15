<?php
/**
 * Addons > Coino Live: balance and payments of the Coino Live gateway, without leaving WHMCS.
 * Uses the API key of the "Coino Live (crypto)" payment gateway; nothing to configure here.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$coinoliveLib = ROOTDIR . '/modules/gateways/coinolive/lib/';
if (is_file($coinoliveLib . 'Dashboard.php')) {
    require_once $coinoliveLib . 'Lang.php';
    require_once $coinoliveLib . 'Client.php';
    require_once $coinoliveLib . 'Gateway.php';
    require_once $coinoliveLib . 'Dashboard.php';
}

function coinolivedashboard_config()
{
    return [
        'name' => 'Coino Live',
        'description' => 'Balance and payments of the Coino Live crypto payment gateway.',
        'version' => '1.0.0',
        'author' => 'Coino Live',
        'fields' => [],
    ];
}

function coinolivedashboard_activate()
{
    return ['status' => 'success', 'description' => 'Open Addons > Coino Live.'];
}

function coinolivedashboard_deactivate()
{
    return ['status' => 'success'];
}

function coinolivedashboard_output($vars)
{
    if (!class_exists('\CoinoLive\Whmcs\Dashboard')) {
        echo '<div class="alert alert-warning">Upload the Coino Live payment gateway (modules/gateways/coinolive) first.</div>';

        return;
    }
    echo \CoinoLive\Whmcs\Dashboard::render($vars['modulelink'], $_GET);
}
