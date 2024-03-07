<?php
// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');
App::load_function('invoice');

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$server = $_SERVER;
$post = $_POST;

logTransaction($gatewayParams['name'], $post, 'Request accepted from CoinoLive.');

function checkIpnRequest()
{
    global $gatewayParams;
    global $server;
    global $post;

    if (isset($_SERVER['HTTP_X_COINOLIVE_SIG']) && !empty($_SERVER['HTTP_X_COINOLIVE_SIG'])) {
        $recived_hmac = $_SERVER['HTTP_X_COINOLIVE_SIG'];

        $request_json = file_get_contents('php://input');
        $request_data = json_decode($request_json, true);
        ksort($request_data);
        $sorted_request_json = json_encode($request_data);


        if ($request_json !== false && !empty($request_json)) {
            $hmac = hash_hmac("sha512", $sorted_request_json, trim($gatewayParams['ipnSecret']));

            if ($hmac == $recived_hmac) {
                return true;
            } else {
                logTransaction($gatewayParams['name'], $post, 'HMAC signature does not match');
                die('HMAC signature does not match');
            }
        } else {
            logTransaction($gatewayParams['name'], $post, 'Error reading POST data');
            die('Error reading POST data');
        }
    } else {
        logTransaction($gatewayParams['name'], $post, 'No HMAC signature sent.');
        die('No HMAC signature sent');
    }
}

$success = checkIpnRequest();

$requestJson = file_get_contents('php://input');
$requestData = json_decode($requestJson, true);

$transactionId = $requestData['payment_id'];
$invoiceId = str_replace('WHMCS-', '', $requestData['order_id']);
$priceAmount = $requestData['price_amount'];
$paymentAmount = $requestData['pay_amount'];
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);



//updateInvoice
if ($success) {
    $status = $requestData["payment_status"];

    if ($status == "finished") {
         addInvoicePayment(
            $invoiceId,
            $transactionId,
            $priceAmount,
            null,
            $gatewayModuleName
        );
        $currency = $requestData['pay_currency'];
        $upper = mb_strtoupper($currency);
        $message = "Invoice ${invoiceId} has been paid. Amount received: ${paymentAmount} ${upper}";
        logTransaction($gatewayParams['name'], $post, $message);

    } else if ($status == "partially_paid") {
	    $actuallyPaid = $requestData["actually_paid"];
	    $actuallyPaidAtFiat = $requestData["actually_paid_at_fiat"];

        addInvoicePayment(
            $invoiceId,
            $transactionId,
	        $actuallyPaidAtFiat,
            null,
            $gatewayModuleName
        );
        $currency = $requestData['pay_currency'];
        $upper = mb_strtoupper($currency);
        $message = "Your payment ${$invoiceId} is partially paid. Please contact support@coino.live. Expected amount received: ${paymentAmount} ${upper}. Amount received: ${actuallyPaid} ${upper}. " . "ID invoice: " . $requestData['payment_id'] . ".";
        logTransaction($gatewayParams['name'], $post, $message);

    } else if ($status == "confirming") {
        logTransaction($gatewayParams['name'], $post, 'Order is processing (confirming).');
    } else if ($status == "confirmed") {
        logTransaction($gatewayParams['name'], $post, 'Order is processing (confirmed).');
    } else if ($status == "sending") {
        logTransaction($gatewayParams['name'], $post, 'Order is processing (sending).');
    } else if ($status == "failed") {
        logTransaction($gatewayParams['name'], $post, 'Order is failed. Please contact support@coino.live');
    } else if ($status == "waiting") {
        logTransaction($gatewayParams['name'], $post, 'Waiting for payment.');
    }
} else {
    die('IPN Verification Failure');
}
