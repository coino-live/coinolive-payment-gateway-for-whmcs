<?php
/**
 * Coino Live API v2 client (cURL, no dependencies).
 */

namespace CoinoLive\Whmcs;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** An API call that failed: the message is safe to show to the admin. */
class ApiException extends \Exception
{
    /** @var int HTTP status (0 when the request did not reach the API). */
    public $status;

    public function __construct($message, $status = 0)
    {
        parent::__construct($message);
        $this->status = (int) $status;
    }
}

class Client
{
    const DEFAULT_URL = 'https://coino.live/api/v2';

    const VERSION = '1.0.0';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl;

    public function __construct($apiKey, $baseUrl = '')
    {
        $this->apiKey = trim((string) $apiKey);
        $baseUrl = trim((string) $baseUrl);
        $this->baseUrl = rtrim(self::isAllowedUrl($baseUrl) ? $baseUrl : self::DEFAULT_URL, '/');
    }

    /** https only (plain http just for a local API while developing). */
    public static function isAllowedUrl($url)
    {
        return strpos($url, 'https://') === 0 || (bool) preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?/#', $url);
    }

    public function baseUrl()
    {
        return $this->baseUrl;
    }

    /** The same idempotency key always returns the same payment. */
    public function createPayment(array $data, $idempotencyKey)
    {
        return $this->request('POST', '/payments', $data, $idempotencyKey);
    }

    public function getPayment($id)
    {
        return $this->request('GET', '/payments/' . rawurlencode($id));
    }

    /** @return array List of ['code' => 'EUR', 'usd_per_unit' => '1.08']. */
    public function currencies()
    {
        return $this->request('GET', '/currencies');
    }

    /** @return array List of ['asset', 'symbol', 'network', 'balance', 'usd_value'] for the whole account. */
    public function balances()
    {
        return $this->request('GET', '/balances');
    }

    /**
     * One page of payments, newest first.
     *
     * @param array $query status, order_id, cursor.
     * @return array ['data' => payments, 'meta' => ['next_cursor' => …, 'prev_cursor' => …]].
     */
    public function listPayments(array $query = [])
    {
        $query = array_filter($query, function ($v) {
            return $v !== null && $v !== '';
        });

        return $this->request('GET', '/payments' . ($query ? '?' . http_build_query($query) : ''), null, null, true);
    }

    private function request($method, $path, $body = null, $idempotencyKey = null, $full = false)
    {
        if ($this->apiKey === '') {
            throw new ApiException(Lang::t('error_no_key'));
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: CoinoLive-WHMCS/' . self::VERSION . '; PHP/' . PHP_VERSION,
        ];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new ApiException(sprintf(Lang::t('error_unreachable'), $error));
        }
        $json = json_decode((string) $raw, true);

        // A redirect or an HTML 404 means the API URL points at a website, not at the API.
        if (($status >= 300 && $status < 400) || ($status === 404 && !is_array($json))) {
            throw new ApiException(sprintf(Lang::t('error_not_api'), $this->baseUrl, $status), $status);
        }
        if ($status < 200 || $status >= 300) {
            $message = is_array($json) && isset($json['message']) ? (string) $json['message'] : 'HTTP ' . $status;
            if (is_array($json) && !empty($json['errors']) && is_array($json['errors'])) {
                $first = reset($json['errors']);
                $message = is_array($first) ? (string) reset($first) : $message;
            }
            throw new ApiException($message, $status);
        }
        if (!is_array($json) || !array_key_exists('data', $json)) {
            throw new ApiException(Lang::t('error_unexpected'), $status);
        }

        return $full ? $json : $json['data'];
    }
}
