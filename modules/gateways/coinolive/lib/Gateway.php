<?php
/**
 * Invoice <-> Coino Live payment logic shared by the gateway, the callback, the cron hook and the dashboard.
 *
 * An invoice only changes from a payment as read back from the API with the shop's key:
 * a callback is just a trigger, so a forged or replayed callback cannot mark anything paid.
 */

namespace CoinoLive\Whmcs;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Gateway
{
    const MODULE = 'coinolive';

    const TABLE = 'mod_coinolive';

    /** Signatures older than this are refused (replays). */
    const TOLERANCE = 300;

    /** Payment states after which the payment never changes again. */
    const FINAL_STATES = ['paid', 'expired', 'cancelled'];

    /** @return array Gateway settings (apiKey, webhookSecret, expiresIn, apiUrl, name, type, …). */
    public static function params()
    {
        if (!function_exists('getGatewayVariables')) {
            require_once ROOTDIR . '/includes/gatewayfunctions.php';
        }

        return (array) getGatewayVariables(self::MODULE);
    }

    public static function client(array $params = null)
    {
        $params = $params ?: self::params();

        return new Client(isset($params['apiKey']) ? $params['apiKey'] : '', isset($params['apiUrl']) ? $params['apiUrl'] : '');
    }

    /** One row per payment we created: invoice, price it was made for, attempt, last handled state. */
    public static function ensureTable()
    {
        $schema = Capsule::schema();
        if ($schema->hasTable(self::TABLE)) {
            return;
        }
        $schema->create(self::TABLE, function ($table) {
            $table->increments('id');
            $table->unsignedInteger('invoice_id')->index();
            $table->string('payment_id', 32)->unique();
            $table->string('checkout_url', 2048);
            $table->string('price', 40);
            $table->unsignedInteger('attempt')->default(0);
            $table->string('nonce', 32);
            $table->string('state', 64)->default('');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * The open payment for an invoice: the current one when it still fits, otherwise a new one.
     *
     * @param array $params The gateway's _link() parameters.
     * @return array The payment (status open, confirming, partially_paid or paid).
     * @throws ApiException
     */
    public static function paymentFor(array $params)
    {
        self::ensureTable();
        $invoiceId = (int) $params['invoiceid'];
        $amount = number_format((float) $params['amount'], 2, '.', '');
        $currency = strtoupper((string) $params['currency']);
        $price = $amount . ' ' . $currency;
        $client = self::client($params);

        $last = Capsule::table(self::TABLE)->where('invoice_id', $invoiceId)->orderBy('id', 'desc')->first();
        if ($last) {
            try {
                $payment = $client->getPayment($last->payment_id);
                $result = self::apply($payment, $params);
                // Money on its way: never start a second payment next to it.
                if (in_array($payment['status'], ['confirming', 'partially_paid'], true)) {
                    return $payment;
                }
                // Paid just now (the page is out of date) or for this amount. A paid payment for a different
                // amount means the invoice grew afterwards: the rest gets a new payment.
                if ($payment['status'] === 'paid' && ($result === 'credited' || $last->price === $price)) {
                    return $payment;
                }
                if ($payment['status'] === 'open' && $last->price === $price) {
                    return $payment;
                }
            } catch (ApiException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }

        // First payment, a changed amount, or the last one expired: a new attempt with a new key.
        $attempt = $last ? (int) $last->attempt + 1 : 0;
        // A random per-invoice nonce keeps keys unique even if invoice numbers repeat (a reset database).
        $nonce = $last ? $last->nonce : bin2hex(random_bytes(8));
        $key = 'whmcs-' . $invoiceId . '-' . $attempt . '-' . substr(md5($nonce . '|' . $price . '|' . self::systemUrl($params)), 0, 16);

        $data = [
            'amount' => $amount,
            'currency' => $currency,
            'order_id' => (string) $invoiceId,
            'description' => mb_substr(sprintf(Lang::t('invoice_description'), $invoiceId, html_entity_decode((string) (isset($params['companyname']) ? $params['companyname'] : ''), ENT_QUOTES)), 0, 500),
            'customer_email' => !empty($params['clientdetails']['email']) ? (string) $params['clientdetails']['email'] : null,
            'success_url' => self::systemUrl($params) . 'viewinvoice.php?id=' . $invoiceId . '&paymentsuccess=true',
            'cancel_url' => self::systemUrl($params) . 'viewinvoice.php?id=' . $invoiceId,
            'expires_in' => max(15, min(10080, (int) (isset($params['expiresIn']) && $params['expiresIn'] !== '' ? $params['expiresIn'] : 60))),
            'metadata' => [
                'whmcs_invoice_id' => (string) $invoiceId,
                'site' => self::systemUrl($params),
            ],
        ];
        $notify = self::systemUrl($params) . 'modules/gateways/callback/' . self::MODULE . '.php';
        if (self::isPublicUrl($notify)) {
            $data['notify_url'] = $notify;
        }

        $payment = $client->createPayment($data, $key);
        $owner = isset($payment['metadata']['whmcs_invoice_id']) ? (string) $payment['metadata']['whmcs_invoice_id'] : '';
        if ($owner !== (string) $invoiceId || $payment['status'] !== 'open') {
            throw new ApiException(Lang::t('error_wrong_payment'));
        }

        $now = date('Y-m-d H:i:s');
        try {
            Capsule::table(self::TABLE)->insert([
                'invoice_id' => $invoiceId,
                'payment_id' => $payment['id'],
                'checkout_url' => $payment['checkout_url'],
                'price' => $price,
                'attempt' => $attempt,
                'nonce' => $nonce,
                'state' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Exception $e) {
            // The invoice opened twice at once: the shared key gave the same payment, which is saved already.
            if (!Capsule::table(self::TABLE)->where('payment_id', $payment['id'])->exists()) {
                throw $e;
            }

            return $payment;
        }
        self::log($params, 'Created payment ' . $payment['id'] . ' for invoice ' . $invoiceId . ' (' . $price . ')', $payment, 'Created');

        return $payment;
    }

    /**
     * Brings the invoice in line with the payment (as read from the API). Safe to call any number of times.
     *
     * @return string What happened: credited, partial_credited, logged, unchanged, unknown.
     */
    public static function apply(array $payment, array $params = null)
    {
        self::ensureTable();
        $params = $params ?: self::params();
        $row = Capsule::table(self::TABLE)->where('payment_id', (string) $payment['id'])->first();
        $invoiceId = isset($payment['metadata']['whmcs_invoice_id']) ? (int) $payment['metadata']['whmcs_invoice_id'] : 0;
        // Only payments this installation created, for the invoice they were created for.
        if (!$row || (int) $row->invoice_id !== $invoiceId) {
            return 'unknown';
        }

        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$invoice) {
            return 'unknown';
        }

        $state = $payment['status'] . '|' . $payment['received_usd'];
        if ($row->state === $state) {
            return 'unchanged';
        }

        $status = $payment['status'];
        $result = 'logged';
        $currency = self::invoiceCurrency($invoice);

        if ($status === 'paid' || (in_array($status, ['expired', 'cancelled'], true) && self::cmp((string) $payment['received_usd'], '0') > 0)) {
            $result = self::credit($payment, $invoice, $currency, $params);
        } elseif ($status === 'confirming') {
            self::log($params, sprintf('Payment %s for invoice %d was sent and is waiting for network confirmations.', $payment['id'], $invoiceId), $payment, 'Confirming');
        } elseif ($status === 'partially_paid') {
            self::log($params, sprintf('Payment %s for invoice %d: %s of %s USD received so far. The customer was asked to send the rest.', $payment['id'], $invoiceId, $payment['received_usd'], $payment['amount']), $payment, 'Partially paid');
        } elseif (in_array($status, ['expired', 'cancelled'], true)) {
            self::log($params, sprintf('Payment %s for invoice %d closed (%s) without payment.', $payment['id'], $invoiceId, $status), $payment, ucfirst($status));
        }

        Capsule::table(self::TABLE)->where('id', $row->id)->update(['state' => $state, 'updated_at' => date('Y-m-d H:i:s')]);

        return $result;
    }

    /** Records the payment on the invoice once (the payment ID is the transaction ID). */
    private static function credit(array $payment, $invoice, $currency, array $params)
    {
        $invoiceId = (int) $invoice->id;
        if (Capsule::table('tblaccounts')->where('gateway', self::MODULE)->where('transid', $payment['id'])->exists()) {
            return 'unchanged';
        }
        if (strtoupper((string) $payment['requested_currency']) !== $currency) {
            self::log($params, sprintf('Payment %s was made in %s but invoice %d is in %s. Not recorded: check it in Coino Live.', $payment['id'], $payment['requested_currency'], $invoiceId, $currency), $payment, 'Currency mismatch');

            return 'logged';
        }

        if ($payment['status'] === 'paid') {
            $amount = (string) $payment['requested_amount'];
            $note = 'Success';
        } else {
            // Closed with part of the money: record what arrived, in the invoice currency (rounded down).
            $rate = $payment['exchange_rate_usd'] !== null && $payment['exchange_rate_usd'] !== '' ? (string) $payment['exchange_rate_usd'] : '1';
            $amount = self::floor2(self::div((string) $payment['received_usd'], $rate));
            $note = 'Partial payment';
            if (self::cmp($amount, '0') <= 0) {
                return 'logged';
            }
        }

        $open = ['Unpaid', 'Payment Pending', 'Overdue', 'Collections'];
        if (!in_array((string) $invoice->status, $open, true)) {
            self::log($params, sprintf('Payment %s received %s %s but invoice %d is %s. Not recorded: check it in Coino Live.', $payment['id'], $amount, $currency, $invoiceId, $invoice->status), $payment, 'Invoice not open');

            return 'logged';
        }

        if (!function_exists('addInvoicePayment')) {
            require_once ROOTDIR . '/includes/invoicefunctions.php';
        }
        addInvoicePayment($invoiceId, $payment['id'], $amount, 0, self::MODULE);
        self::log($params, sprintf('Payment %s recorded on invoice %d: %s %s. %s', $payment['id'], $invoiceId, $amount, $currency, self::depositSummary($payment)), $payment, $note);

        return $payment['status'] === 'paid' ? 'credited' : 'partial_credited';
    }

    /**
     * Callback endpoint logic.
     *
     * @return array [HTTP status, response body]
     */
    public static function handleCallback($body, $signatureHeader, array $params = null)
    {
        $params = $params ?: self::params();
        if (empty($params['type'])) {
            return [503, ['message' => 'Module not active.']];
        }
        $secret = trim((string) (isset($params['webhookSecret']) ? $params['webhookSecret'] : ''));
        if ($secret !== '' && !self::validSignature((string) $signatureHeader, (string) $body, $secret)) {
            self::log($params, 'Callback refused: bad signature.', null, 'Invalid signature');

            return [401, ['message' => 'Invalid signature.']];
        }

        $event = json_decode((string) $body, true);
        $id = is_array($event) && isset($event['data']['id']) ? (string) $event['data']['id'] : '';
        if (strpos(is_array($event) && isset($event['event']) ? (string) $event['event'] : '', 'payment.') !== 0 || !preg_match('/^[0-9a-z]{26}$/', $id)) {
            return [200, ['ignored' => true]];
        }

        self::ensureTable();
        if (!Capsule::table(self::TABLE)->where('payment_id', $id)->exists()) {
            return [200, ['ignored' => true]]; // Not a payment of this installation.
        }

        try {
            $payment = self::client($params)->getPayment($id);
        } catch (ApiException $e) {
            return [503, ['message' => 'Try again later.']]; // Coino Live retries.
        }

        return [200, ['ok' => true, 'result' => self::apply($payment, $params)]];
    }

    /** Safety net for missed callbacks (cron): re-checks recent payments that can still change. */
    public static function syncOpen($limit = 50)
    {
        $params = self::params();
        if (empty($params['type']) || empty($params['apiKey'])) {
            return 0;
        }
        self::ensureTable();
        $rows = Capsule::table(self::TABLE)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3 * 86400))
            ->where(function ($q) {
                $q->where('state', '')->orWhere('state', 'like', 'open|%')->orWhere('state', 'like', 'confirming|%')->orWhere('state', 'like', 'partially_paid|%');
            })
            ->orderBy('id', 'desc')->limit($limit)->get();

        $client = self::client($params);
        $checked = 0;
        foreach ($rows as $row) {
            try {
                self::apply($client->getPayment($row->payment_id), $params);
                $checked++;
            } catch (ApiException $e) {
                if ($e->status === 0 || $e->status >= 500) {
                    break; // API unreachable: try again on the next run.
                }
            }
        }

        return $checked;
    }

    /** X-Coino-Signature: t=<unix time>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")> */
    public static function validSignature($header, $body, $secret)
    {
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            $kv = explode('=', trim($pair), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }
        if (empty($parts['t']) || empty($parts['v1']) || !ctype_digit($parts['t']) || abs(time() - (int) $parts['t']) > self::TOLERANCE) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $parts['t'] . '.' . $body, $secret), $parts['v1']);
    }

    public static function invoiceCurrency($invoice)
    {
        $code = Capsule::table('tblclients')
            ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
            ->where('tblclients.id', $invoice->userid)
            ->value('tblcurrencies.code');

        return strtoupper((string) ($code ?: Capsule::table('tblcurrencies')->where('default', 1)->value('code')));
    }

    public static function systemUrl(array $params = null)
    {
        $url = $params && !empty($params['systemurl']) ? $params['systemurl'] : (isset($GLOBALS['CONFIG']['SystemURL']) ? $GLOBALS['CONFIG']['SystemURL'] : '');

        return rtrim((string) $url, '/') . '/';
    }

    /** False for local development sites, which Coino Live cannot (and must not) call. */
    public static function isPublicUrl($url)
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || $host === 'localhost' || preg_match('/\.(local|localhost|test|internal)$/', $host)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }

    private static function depositSummary(array $payment)
    {
        $lines = [];
        foreach ((array) (isset($payment['deposits']) ? $payment['deposits'] : []) as $d) {
            $lines[] = sprintf('%s %s (~%s USD) tx %s', $d['amount'], isset($d['symbol']) ? $d['symbol'] : strtoupper((string) $d['asset']), $d['usd_value'], $d['tx_hash']);
        }

        return $lines ? 'Deposits: ' . implode('; ', $lines) : '';
    }

    /** Gateway Log (Billing > Gateway Log). */
    private static function log($params, $message, $payment, $status)
    {
        if (!function_exists('logTransaction')) {
            require_once ROOTDIR . '/includes/gatewayfunctions.php';
        }
        $name = $params && !empty($params['name']) ? $params['name'] : self::MODULE;
        $data = ['message' => $message];
        if ($payment) {
            $data += [
                'payment' => $payment['id'],
                'status' => $payment['status'],
                'amount' => $payment['requested_amount'] . ' ' . $payment['requested_currency'] . ' (' . $payment['amount'] . ' USD)',
                'received_usd' => $payment['received_usd'],
            ];
        }
        logTransaction($name, $data, $status);
    }

    private static function cmp($a, $b)
    {
        return function_exists('bccomp') ? bccomp($a, $b, 18) : ((float) $a <=> (float) $b);
    }

    private static function div($a, $b)
    {
        return function_exists('bcdiv') ? bcdiv($a, $b, 8) : (string) ((float) $a / (float) $b);
    }

    private static function floor2($n)
    {
        $parts = explode('.', (string) $n, 2);

        return $parts[0] . '.' . str_pad(substr(isset($parts[1]) ? $parts[1] : '', 0, 2), 2, '0');
    }
}
