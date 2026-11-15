<?php
/**
 * Addons > Coino Live: the account balance and this installation's payments, read live from the API.
 */

namespace CoinoLive\Whmcs;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Dashboard
{
    const STATUSES = ['paid', 'partially_paid', 'confirming', 'open', 'expired', 'cancelled'];

    /**
     * @param string $link  The addon page URL (addonmodules.php?module=…).
     * @param array  $query Request parameters (status, invoice, cursor).
     */
    public static function render($link, array $query, array $params = null)
    {
        $params = $params ?: Gateway::params();
        $query = array_filter($query, 'is_string');
        $status = isset($query['status']) && in_array($query['status'], self::STATUSES, true) ? $query['status'] : '';
        $invoice = isset($query['invoice']) ? preg_replace('/\D/', '', (string) $query['invoice']) : '';
        $cursor = isset($query['cursor']) ? preg_replace('/[^A-Za-z0-9_\-=]/', '', (string) $query['cursor']) : '';
        $dashboard = self::dashboardUrl($params);

        $out = '<div class="coinolive-dashboard">' . self::style();
        $out .= '<p>';
        $out .= '<a class="btn btn-default btn-sm" href="configgateways.php">' . self::e(Lang::t('settings')) . '</a> ';
        if ($dashboard) {
            $out .= '<a class="btn btn-default btn-sm" href="' . self::e($dashboard) . '" target="_blank" rel="noopener">' . self::e(Lang::t('open_dashboard')) . '</a>';
        }
        $out .= '</p>';

        if (empty($params['type']) || trim((string) (isset($params['apiKey']) ? $params['apiKey'] : '')) === '') {
            return $out . '<div class="alert alert-warning">' . self::e(Lang::t('no_key')) . '</div></div>';
        }

        $client = Gateway::client($params);
        $out .= self::balances($client, $dashboard);
        $out .= self::payments($client, $link, $status, $invoice, $cursor);

        return $out . '</div>';
    }

    private static function balances(Client $client, $dashboard)
    {
        $out = '<h2>' . self::e(Lang::t('balance')) . '</h2>';
        try {
            $balances = $client->balances();
        } catch (ApiException $e) {
            return $out . self::error($e);
        }

        $held = array_values(array_filter($balances, function ($b) {
            return self::cmp((string) $b['balance'], '0') > 0;
        }));
        $total = '0';
        $allKnown = true;
        foreach ($held as $b) {
            if (!isset($b['usd_value']) || $b['usd_value'] === null) {
                $allKnown = false;
            } else {
                $total = function_exists('bcadd') ? bcadd($total, (string) $b['usd_value'], 2) : (string) ((float) $total + (float) $b['usd_value']);
            }
        }

        $out .= '<div class="coinolive-cards"><div class="coinolive-card coinolive-total"><span>' . self::e(Lang::t('total')) . '</span>';
        $out .= '<strong>' . self::e(($allKnown ? '' : '≥ ') . self::money($total) . ' USD') . '</strong>';
        if ($dashboard) {
            $out .= '<a href="' . self::e($dashboard . '/wallet/withdraw') . '" target="_blank" rel="noopener">' . self::e(Lang::t('withdraw')) . ' &rarr;</a>';
        }
        $out .= '</div>';
        foreach ($held as $b) {
            $out .= '<div class="coinolive-card"><span>' . self::e($b['symbol'] . ' · ' . $b['network']) . '</span><strong>' . self::e($b['balance'] . ' ' . $b['symbol']) . '</strong>';
            $out .= '<em>' . self::e(!isset($b['usd_value']) || $b['usd_value'] === null ? '—' : '≈ ' . self::money((string) $b['usd_value']) . ' USD') . '</em></div>';
        }
        $out .= '</div><p class="text-muted">' . self::e($held ? Lang::t('balance_note') : Lang::t('no_balance')) . '</p>';

        return $out;
    }

    private static function payments(Client $client, $link, $status, $invoice, $cursor)
    {
        $out = '<h2>' . self::e(Lang::t('payments')) . '</h2>';

        $labels = ['' => Lang::t('all')] + self::statusLabels();
        $tabs = [];
        foreach ($labels as $key => $label) {
            $url = $link . ($key !== '' ? '&status=' . $key : '');
            $tabs[] = $key === $status ? '<strong>' . self::e($label) . '</strong>' : '<a href="' . self::e($url) . '">' . self::e($label) . '</a>';
        }
        $out .= '<form method="get" action="addonmodules.php" class="form-inline coinolive-search">'
            . '<input type="hidden" name="module" value="' . self::e(self::moduleFromLink($link)) . '">'
            . ($status !== '' ? '<input type="hidden" name="status" value="' . self::e($status) . '">' : '')
            . '<input type="text" class="form-control input-sm" name="invoice" value="' . self::e($invoice) . '" placeholder="' . self::e(Lang::t('invoice_number')) . '"> '
            . '<button class="btn btn-default btn-sm">' . self::e(Lang::t('search')) . '</button></form>';
        $out .= '<p class="coinolive-tabs">' . implode(' | ', $tabs) . '</p>';

        try {
            $page = $client->listPayments(['status' => $status, 'order_id' => $invoice, 'cursor' => $cursor]);
        } catch (ApiException $e) {
            return $out . self::error($e);
        }

        $out .= '<div class="table-responsive"><table class="table table-striped table-condensed coinolive-payments"><thead><tr>';
        foreach ([Lang::t('date'), Lang::t('invoice'), Lang::t('status'), Lang::t('amount'), Lang::t('received'), Lang::t('transactions'), ''] as $h) {
            $out .= '<th>' . self::e($h) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        $payments = (array) (isset($page['data']) ? $page['data'] : []);
        if (!$payments) {
            $out .= '<tr><td colspan="7">' . self::e(Lang::t('no_payments')) . '</td></tr>';
        }
        // Payments created by this installation link to their invoice.
        $ids = array_map(function ($p) {
            return (string) $p['id'];
        }, $payments);
        $ours = $ids ? Capsule::table(Gateway::TABLE)->whereIn('payment_id', $ids)->pluck('invoice_id', 'payment_id') : [];
        $ours = is_object($ours) && method_exists($ours, 'all') ? $ours->all() : (array) $ours;
        foreach ($payments as $p) {
            $out .= self::row($p, isset($ours[$p['id']]) ? (int) $ours[$p['id']] : 0);
        }
        $out .= '</tbody></table></div>';

        $meta = (array) (isset($page['meta']) ? $page['meta'] : []);
        $base = $link . ($status !== '' ? '&status=' . $status : '') . ($invoice !== '' ? '&invoice=' . $invoice : '');
        $out .= '<p class="text-right">';
        if (!empty($meta['prev_cursor'])) {
            $out .= '<a class="btn btn-default btn-sm" href="' . self::e($base . '&cursor=' . rawurlencode($meta['prev_cursor'])) . '">&larr; ' . self::e(Lang::t('newer')) . '</a> ';
        }
        if (!empty($meta['next_cursor'])) {
            $out .= '<a class="btn btn-default btn-sm" href="' . self::e($base . '&cursor=' . rawurlencode($meta['next_cursor'])) . '">' . self::e(Lang::t('older')) . ' &rarr;</a>';
        }

        return $out . '</p>';
    }

    private static function row(array $p, $invoiceId)
    {
        $labels = self::statusLabels();
        $out = '<tr><td>' . self::e(date('Y-m-d H:i', strtotime((string) $p['created_at']))) . '</td>';
        if ($invoiceId) {
            $out .= '<td><a href="invoices.php?action=edit&amp;id=' . $invoiceId . '">#' . $invoiceId . '</a></td>';
        } else {
            $out .= '<td>' . self::e($p['order_id'] ? '#' . $p['order_id'] : '—') . '</td>';
        }
        $out .= '<td><span class="coinolive-status coinolive-status-' . self::e($p['status']) . '">' . self::e(isset($labels[$p['status']]) ? $labels[$p['status']] : $p['status']) . '</span></td>';
        $out .= '<td>' . self::e($p['requested_amount'] . ' ' . $p['requested_currency']);
        if ($p['requested_currency'] !== $p['currency']) {
            $out .= '<br><small class="text-muted">' . self::e($p['amount'] . ' ' . $p['currency']) . '</small>';
        }
        $out .= '</td><td>' . self::e(self::money((string) $p['received_usd']) . ' USD');
        if ($p['status'] === 'partially_paid' || ($p['status'] === 'expired' && self::cmp((string) $p['received_usd'], '0') > 0)) {
            $out .= '<br><small class="text-danger">' . self::e(sprintf(Lang::t('usd_missing'), self::money((string) $p['remaining_usd']))) . '</small>';
        }
        $out .= '</td><td>';
        $deposits = (array) (isset($p['deposits']) ? $p['deposits'] : []);
        if (!$deposits) {
            $out .= '—';
        }
        foreach ($deposits as $d) {
            $hash = (string) $d['tx_hash'];
            $text = strlen($hash) > 16 ? substr($hash, 0, 8) . '…' . substr($hash, -6) : $hash;
            $coin = isset($d['symbol']) ? (string) $d['symbol'] : strtoupper((string) $d['asset']);
            $out .= '<div class="coinolive-tx">' . self::e($d['amount'] . ' ' . $coin);
            if (!empty($d['network'])) {
                $out .= ' <small class="text-muted">' . self::e($d['network']) . '</small>';
            }
            $out .= '<br>' . (!empty($d['explorer_url'])
                ? '<a href="' . self::e($d['explorer_url']) . '" target="_blank" rel="noopener noreferrer" title="' . self::e($hash) . '"><code>' . self::e($text) . '</code></a>'
                : '<code title="' . self::e($hash) . '">' . self::e($text) . '</code>') . '</div>';
        }
        $out .= '</td><td>';
        if (in_array($p['status'], ['open', 'partially_paid'], true)) {
            $out .= '<a href="' . self::e($p['checkout_url']) . '" target="_blank" rel="noopener">' . self::e(Lang::t('payment_page')) . '</a>';
        }

        return $out . '</td></tr>';
    }

    public static function statusLabels()
    {
        return [
            'paid' => Lang::t('status_paid'),
            'partially_paid' => Lang::t('status_partially_paid'),
            'confirming' => Lang::t('status_confirming'),
            'open' => Lang::t('status_open'),
            'expired' => Lang::t('status_expired'),
            'cancelled' => Lang::t('status_cancelled'),
        ];
    }

    /** The merchant dashboard next to the configured API ("…/api/v2" → "…/{locale}/dashboard"), or null. */
    public static function dashboardUrl(array $params)
    {
        $api = rtrim(trim((string) (isset($params['apiUrl']) ? $params['apiUrl'] : '')), '/');
        $api = $api === '' ? Client::DEFAULT_URL : $api;
        if (!preg_match('#^(https://[^/]+)/api/v2$#', $api, $m)) {
            return null;
        }
        $map = ['farsi' => 'fa', 'turkish' => 'tr', 'portuguese-br' => 'pt', 'portuguese-pt' => 'pt', 'russian' => 'ru'];
        $lang = strtolower(Lang::current());

        return $m[1] . '/' . (isset($map[$lang]) ? $map[$lang] : 'en') . '/dashboard';
    }

    private static function moduleFromLink($link)
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);

        return isset($q['module']) ? (string) $q['module'] : '';
    }

    private static function error(ApiException $e)
    {
        return '<div class="alert alert-danger">' . self::e(sprintf(Lang::t('load_failed'), $e->getMessage())) . '</div>';
    }

    private static function e($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }

    private static function cmp($a, $b)
    {
        return function_exists('bccomp') ? bccomp($a, $b, 18) : ((float) $a <=> (float) $b);
    }

    private static function money($amount)
    {
        return number_format((float) $amount, 2);
    }

    private static function style()
    {
        return '<style>
.coinolive-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px;margin:12px 0 8px}
.coinolive-card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 14px;display:flex;flex-direction:column;gap:4px}
.coinolive-card span,.coinolive-card em{color:#777;font-size:12px;font-style:normal}
.coinolive-card strong{font-size:18px;word-break:break-all}
.coinolive-total{border-color:#337ab7;box-shadow:inset 3px 0 0 #337ab7}
.coinolive-total strong{font-size:22px}
.coinolive-search{float:right;margin-bottom:8px}
.coinolive-tabs{margin:6px 0 10px}
.coinolive-tx+.coinolive-tx{margin-top:6px}
.coinolive-status{display:inline-block;padding:2px 8px;border-radius:4px;background:#eee;color:#555;font-size:12px;white-space:nowrap}
.coinolive-status-paid{background:#c6e1c6;color:#2c4700}
.coinolive-status-partially_paid,.coinolive-status-confirming{background:#f8dda7;color:#573b00}
.coinolive-status-open{background:#e5f0fa;color:#0a4b78}
.coinolive-status-expired,.coinolive-status-cancelled{background:#eba3a3;color:#570000}
</style>';
    }
}
