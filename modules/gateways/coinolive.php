<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


function coinolive_MetaData()
{
    return array(
        'DisplayName' => 'CoinoLive',
        'APIVersion' => '1.1', 
    );
}


function coinolive_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'CoinoLive',
        ),
        'apiKey' => array(
            'FriendlyName' => 'API key',
            'Type' => 'text',
            'Default' => '',
            'Description' => 'Enter your API key here',
        ),
        'ipnSecret' => array(
            'FriendlyName' => 'Secret',
            'Type' => 'text',
            'Default' => '',
            'Description' => 'Enter your Secret here',
        )
    );
}


function coinolive_link($params)
{
    $origin = $_SERVER['HTTP_ORIGIN'];
    $path = array_filter(explode('/', parse_url($_SERVER['REQUEST_URI'])['path']));
    $logoUrl = $params['systemurl'] . 'modules/gateways/coinolive/logo.png';
    $ipnUrl = $params['systemurl'] . 'modules/gateways/callback/coinolive.php';
    if(empty($params['systemurl'])) {
        if(count($path) > 1) {
            array_pop($path);
            $prefix = implode('/', $path);
            $logoUrl = '/' . $prefix . '/modules/gateways/coinolive/logo.png';
            $ipnUrl = $origin . '/' . $prefix . '/modules/gateways/callback/coinolive.php';
        } else {
            $logoUrl = '/modules/gateways/coinolive/logo.png';
            $ipnUrl = $origin . '/modules/gateways/callback/coinolive.php';
        }
    }

    $orderId = 'WHMCS-' . $params['invoiceid'];
    $coinoliveArgs = [
        'ipnURL' => $ipnUrl,
        'successURL' => $params['returnurl'],
        'cancelURL' => $params['systemurl'],
        'dataSource' => 'whmcs',
        'paymentCurrency' => mb_strtoupper($params['currency']),
        'apiKey' => $params['apiKey'],
        'customerName' => $params['clientdetails']['firstname'],
        'customerEmail' => $params['clientdetails']['email'],
        'paymentAmount' => $params['amount'],
        'orderID' => $orderId,
        'gateID'=>$params['ipnSecret']
    ];

    $url = 'https://coino.live/api/v1/order?data=';
    $coinolive_adr = $url . urlencode(json_encode($coinoliveArgs));
    $htmlOutput = '<a href="' . $coinolive_adr . '" target="_blank">';
    $htmlOutput .= '<img  src="'.$logoUrl.'" alt="CoinoLive" />';
    $htmlOutput .= ' </a>';

    return $htmlOutput;
}

