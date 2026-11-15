<?php
/**
 * Coino Live crypto payments for WHMCS.
 *
 * Clients pay invoices with USDT, Bitcoin, Ethereum, TRON, TON, Solana and more on the
 * Coino Live checkout; invoices are marked paid once the payment is confirmed on chain.
 */

use CoinoLive\Whmcs\ApiException;
use CoinoLive\Whmcs\Client;
use CoinoLive\Whmcs\Gateway;
use CoinoLive\Whmcs\Lang;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/coinolive/lib/Lang.php';
require_once __DIR__ . '/coinolive/lib/Client.php';
require_once __DIR__ . '/coinolive/lib/Gateway.php';

function coinolive_MetaData()
{
    return [
        'DisplayName' => 'Coino Live (crypto)',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function coinolive_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => Lang::t('friendly_name'),
        ],
        'apiKey' => [
            'FriendlyName' => Lang::t('api_key'),
            'Type' => 'password',
            'Size' => '60',
            'Description' => Lang::t('api_key_desc'),
        ],
        'webhookSecret' => [
            'FriendlyName' => Lang::t('webhook_secret'),
            'Type' => 'password',
            'Size' => '60',
            'Description' => Lang::t('webhook_secret_desc'),
        ],
        'expiresIn' => [
            'FriendlyName' => Lang::t('expires_in'),
            'Type' => 'text',
            'Size' => '6',
            'Default' => '60',
            'Description' => Lang::t('expires_in_desc'),
        ],
        'apiUrl' => [
            'FriendlyName' => Lang::t('api_url'),
            'Type' => 'text',
            'Size' => '60',
            'Default' => Client::DEFAULT_URL,
            'Description' => Lang::t('api_url_desc'),
        ],
    ];
}

/** Saving the settings checks the key against the API, so a typo shows up at once. */
function coinolive_config_validate(array $params)
{
    $url = trim((string) (isset($params['apiUrl']) ? $params['apiUrl'] : ''));
    if ($url !== '' && !Client::isAllowedUrl($url)) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration(Lang::t('error_url_https'));
    }
    if (trim((string) (isset($params['apiKey']) ? $params['apiKey'] : '')) === '') {
        return;
    }
    try {
        (new Client($params['apiKey'], $url))->currencies();
    } catch (ApiException $e) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration(sprintf(Lang::t('error_key_refused'), $e->getMessage()));
    }
}

/** The invoice page: a button to the Coino Live checkout (WHMCS submits it by itself after checkout). */
function coinolive_link($params)
{
    try {
        $payment = Gateway::paymentFor($params);
    } catch (ApiException $e) {
        return '<div class="alert alert-warning">' . htmlspecialchars(sprintf(Lang::t('unavailable'), $e->getMessage())) . '</div>';
    } catch (\Throwable $e) {
        logActivity('Coino Live: ' . $e->getMessage());

        return '<div class="alert alert-warning">' . htmlspecialchars(sprintf(Lang::t('unavailable'), Lang::t('error_unexpected'))) . '</div>';
    }

    $button = '<form method="get" action="' . htmlspecialchars($payment['checkout_url']) . '">'
        . '<button type="submit" class="btn btn-success">%s</button></form>';

    switch ($payment['status']) {
        case 'paid':
            return '<div class="alert alert-success">' . htmlspecialchars(Lang::t('paid_text')) . '</div>';
        case 'confirming':
            return '<div class="alert alert-info">' . htmlspecialchars(Lang::t('confirming_text')) . '</div>';
        case 'partially_paid':
            return '<p>' . htmlspecialchars(sprintf(Lang::t('partial_text'), $payment['received_usd'], $payment['amount'])) . '</p>'
                . sprintf($button, htmlspecialchars(Lang::t('open_payment_page')));
        default:
            return sprintf($button, htmlspecialchars(Lang::t('pay_button')));
    }
}
