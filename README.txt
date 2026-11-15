Coino Live crypto payments for WHMCS
Version 1.0.0 - requires WHMCS 8.0 or newer, PHP 7.4 or newer with cURL

Clients pay invoices with USDT, Bitcoin, Ethereum, TRON, TON, Solana and more on
the Coino Live payment page. The invoice is marked paid once the payment is
confirmed on chain, and the payment is credited to your Coino Live balance.


INSTALL

1. Upload the "modules" and "includes" folders from this zip into your WHMCS
   folder (the one that contains configuration.php). They only add files.

2. Setup > Payments > Payment Gateways > All Payment Gateways:
   click "Coino Live (crypto)". Paste your API key (Coino Live dashboard >
   Developers) and save. Saving checks the key with Coino Live.

3. Optional, to see your balance and payments inside WHMCS:
   Setup > Addon Modules > "Coino Live" > Activate, then Configure and tick
   the admin roles that may see it. It appears under Addons > Coino Live.

Nothing else to configure. The callback URL
(/modules/gateways/callback/coinolive.php) is sent with every payment. If
callbacks cannot reach your WHMCS, open payments are still checked on every
WHMCS cron run and whenever the client opens the invoice.


HOW INVOICES UPDATE

- Paid: the payment is added to the invoice (transaction ID = Coino Live
  payment ID) and WHMCS marks the invoice paid.
- Sent but not confirmed yet, or only part paid so far: noted in the Gateway
  Log; the client sees how much is still missing and can send the rest.
- The payment window closed with part of the money: that part is added to
  the invoice (converted at the rate used for the payment) and the client
  can pay the rest with a new payment.
- Invoice amount changed: the client gets a new payment for the new amount.

Every change comes from the payment as read back from the Coino Live API with
your key, never from the content of a callback, so a forged callback cannot
mark an invoice paid. The optional webhook signing secret adds a signature
check on top.

Invoices can be in any currency Coino Live supports (USD, EUR, GBP, TRY,
BRL, RUB and more); amounts are converted at the current rate.

Logs: Billing > Gateway Log.


FILES

modules/gateways/coinolive.php               payment gateway
modules/gateways/coinolive/                  library and translations
modules/gateways/callback/coinolive.php      callback endpoint
modules/addons/coinolivedashboard/           Addons > Coino Live (optional)
includes/hooks/coinolive.php                 cron safety net

Uninstall: deactivate the gateway and the addon, then delete these files. The
table mod_coinolive (which payment belongs to which invoice) can be dropped.
