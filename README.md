# WHMCS Crypto Payment Gateway: Bitcoin & USDT | Coino Live

Accept crypto payments in WHMCS with the free [Coino Live](https://coino.live) payment gateway module. Your clients pay WHMCS invoices with USDT, USDC, Bitcoin, Ethereum, TRON, TON, Solana and more on the Coino Live payment page. The invoice is marked paid once the payment is confirmed on chain. The payment is credited to your Coino Live balance, and you withdraw it to your own wallet.

**Version 1.0.0** · requires WHMCS 8.0+ and PHP 7.4+ · GPL-2.0-or-later

- **Download the ready-to-install zip:** [coinolive-whmcs-1.0.0.zip](https://coino.live/downloads/coinolive-whmcs-1.0.0.zip)
- **Module information:** [WHMCS plugin page](https://coino.live/whmcs)
- **API reference:** [coino.live/api-guide](https://coino.live/api-guide)
- **Support:** [support@coino.live](mailto:support@coino.live)

> **Updating from the old version?** Earlier code in this repository used the Coino Live API v1, which has been retired. Its endpoints (`/api/v1/...`) no longer answer. This version uses the current [API v2](https://coino.live/api-guide). Replace the old files with these.

## Supported cryptocurrencies and networks

Coino Live supports **117 assets on 11 networks**: Bitcoin, Litecoin, Ethereum, BNB Smart Chain, opBNB, Polygon, Base, Arbitrum, TRON, TON and Solana. USDT and USDC are available on most of them. Dogecoin and Bitcoin Cash are not accepted.

Invoices can be in any currency Coino Live supports (USD, EUR, GBP, TRY, BRL, RUB and more). Amounts are converted at the current rate.

## Fees and account

- **Free module:** the WHMCS module is licensed under GPL-2.0-or-later.
- **Payment fee:** Coino Live charges a 0.25% platform fee per payment. Blockchain network fees are separate.
- **No KYC:** sign up with an email and password.
- **Settlement:** payments are credited to your Coino Live balance, and you withdraw them to your own wallet.

## Requirements

- WHMCS 8.0 or later
- PHP 7.4 or later
- A Coino Live account and API key

## Installation

1. If you don't have an account yet, sign up at [coino.live](https://coino.live) with your email and password.
2. Download [coinolive-whmcs-1.0.0.zip](https://coino.live/downloads/coinolive-whmcs-1.0.0.zip). Upload the `modules` and `includes` folders from the zip into your WHMCS folder (the one that contains `configuration.php`). They only add files.
3. Go to **Setup > Payments > Payment Gateways > All Payment Gateways** and click **Coino Live (crypto)**. Paste your API key (**Coino Live dashboard > Developers**) and save. Saving checks the key with Coino Live.
4. *Optional:* to see your balance and payments inside WHMCS, go to **Setup > Addon Modules > Coino Live > Activate**. Then click **Configure** and tick the admin roles that may see it. It appears under **Addons > Coino Live**.

Nothing else needs configuring. The callback URL (`/modules/gateways/callback/coinolive.php`) is sent with every payment. If callbacks cannot reach your WHMCS, open payments are still checked on every WHMCS cron run and whenever the client opens the invoice.

## How WHMCS invoices update

- **Paid:** the payment is added to the invoice (transaction ID = Coino Live payment ID) and WHMCS marks the invoice paid.
- **Sent but not confirmed yet, or only partly paid so far:** this is noted in the Gateway Log. The client sees how much is still missing and can send the rest.
- **Payment window closed with part of the money:** that part is added to the invoice, converted at the rate used for the payment. The client can pay the rest with a new payment.
- **Invoice amount changed:** the client gets a new payment for the new amount.

**Logs:** Billing > Gateway Log.

## Payment verification

Every change comes from the payment as read back from the Coino Live API with your key, never from the content of a callback. A forged callback therefore cannot mark an invoice paid. The optional webhook signing secret adds a signature check on top.

## Module files

```text
modules/gateways/coinolive.php               payment gateway
modules/gateways/coinolive/                  library and translations
modules/gateways/callback/coinolive.php      callback endpoint
modules/addons/coinolivedashboard/           Addons > Coino Live (optional)
includes/hooks/coinolive.php                 cron safety net
```

## Uninstall

Deactivate the gateway and the addon, then delete the files listed above. You can also drop the table `mod_coinolive`, which records which payment belongs to which invoice.

## License

GPL-2.0-or-later. See https://www.gnu.org/licenses/gpl-2.0.html
