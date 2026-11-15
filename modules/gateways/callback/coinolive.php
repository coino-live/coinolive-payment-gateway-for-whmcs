<?php
/**
 * Coino Live callback: POST /modules/gateways/callback/coinolive.php
 *
 * A callback only says "this payment changed"; the invoice is updated from the payment as read
 * back from the API with the shop's key. With a signing secret set, the signature is checked too.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../coinolive.php';

use CoinoLive\Whmcs\Gateway;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$body = (string) file_get_contents('php://input', false, null, 0, 65536);
list($status, $response) = Gateway::handleCallback($body, $_SERVER['HTTP_X_COINO_SIGNATURE'] ?? '');

http_response_code($status);
header('Content-Type: application/json');
echo json_encode($response);
