# FOSSBilling Crypto Payment Gateway: USDT TRC20 via SnapEX Pay

[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![FOSSBilling](https://img.shields.io/badge/FOSSBilling-0.8%2B-blue.svg)](https://github.com/FOSSBilling/FOSSBilling)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)](https://www.php.net/)
[![USDT](https://img.shields.io/badge/USDT-TRC20%20%7C%20ERC20%20%7C%20BEP20%20%7C%20Solana%20%7C%20TON%20%2B-26a17b.svg)](https://pay.snapex.pro)

[Русская версия](README.ru.md)

A payment adapter for [FOSSBilling](https://github.com/FOSSBilling/FOSSBilling) that lets clients pay invoices in **USDT (TRC20, ERC20, BEP20, Solana, TON, Polygon and more)** and other coins through [SnapEX Pay](https://snapex.pro). When a client chooses SnapEX Pay on an invoice, the adapter creates a SnapEX Pay invoice and sends the client to the hosted payment page. Once the payment is confirmed, a signed webhook arrives at FOSSBilling's IPN URL, the client is credited and the FOSSBilling invoice is marked paid. You don't have to run a wallet, a node or a blockchain watcher. Funds go to your SnapEX Pay merchant balance.

> **Connecting to SnapEX Pay?** Create a merchant account, check the pricing and see every ready-made module on **[pay.snapex.pro](https://pay.snapex.pro)**.

## Features

- A single-file FOSSBilling payment adapter (`Payment_Adapter_SnapexPay`) that shows up under **System → Payment gateways**.
- A hosted SnapEX Pay checkout for USDT on TRC20, ERC20, BEP20, Solana, TON and other networks, plus other coins offered on the payment page.
- Webhooks are verified with HMAC-SHA256 (`Webhook-Signature`), compared in constant time, and rejected if the timestamp is more than 5 minutes off. On current FOSSBilling builds the signature is checked before the callback is even stored.
- The webhook body is never trusted on its own. The invoice is read back from the SnapEX API with your key before anything is credited.
- Payments are booked once. A repeated or retried webhook doesn't credit the client again, and settlement runs under a database lock.
- The amount credited is the invoice total the client was billed for, in the invoice currency. If the invoice total changed after checkout, nothing is credited automatically.
- Several FOSSBilling sites can share one SnapEX account. Each site only settles its own invoices.
- FOSSBilling's **Enable Test Mode** switch creates SnapEX test invoices you can confirm without real money. Test payments are never applied while the gateway is live.
- Non-USD invoices are converted with a fixed rate or with the currency rates already configured in FOSSBilling.
- Works with FOSSBilling 0.8.x and with the current development builds.

## How it works

1. A client opens an unpaid invoice and picks **SnapexPay**.
2. The adapter calls `POST /api/v1/merchant/invoices` with the amount in USD, an `Idempotency-Key`, and an `external_id` that identifies this site, the invoice and the billed total. The client gets a *Pay with crypto* button that opens the returned `pay_url`, or is redirected straight away if FOSSBilling asks for auto-redirect.
3. The client pays on the SnapEX Pay page and comes back to the invoice.
4. SnapEX Pay sends `invoice.paid` to FOSSBilling's IPN URL: `https://<your-fossbilling>/ipn.php?gateway_id=<id>`.
5. FOSSBilling stores the callback as a transaction and hands it to the adapter. The adapter checks the signature, fetches `GET /api/v1/merchant/invoices/{id}`, and only proceeds if the status is `paid`. It adds the invoice total to the client's balance and pays the invoice from it. Deposit (add funds) invoices are marked paid directly. The SnapEX invoice id (`INV-…`) becomes the transaction id.

Reloading the invoice page reuses the same SnapEX invoice for half of the payment window. After that, or if the total changes, a new SnapEX invoice is created.

## Requirements

- FOSSBilling 0.8 or newer
- PHP 8.0+ with the cURL extension (FOSSBilling itself needs a newer PHP)
- A [SnapEX Pay](https://pay.snapex.pro) merchant account
- A FOSSBilling site reachable over HTTPS, so the webhook can be delivered

## Installation

1. Copy [`SnapexPay.php`](SnapexPay.php) into your FOSSBilling root (the folder that contains `ipn.php` and `config.php`) so that it ends up at:

   ```
   library/Payment/Adapter/SnapexPay/SnapexPay.php
   ```

   The folder and the file must both be named `SnapexPay` (case-sensitive). For example:

   ```bash
   cd /var/www/fossbilling          # /var/www/html in the official Docker image
   mkdir -p library/Payment/Adapter/SnapexPay
   curl -fsSLO https://pay.snapex.pro/downloads/fossbilling/snapexpay-fossbilling-1.2.0.zip
   unzip -p snapexpay-fossbilling-1.2.0.zip snapexpay-fossbilling-1.2.0/SnapexPay.php > library/Payment/Adapter/SnapexPay/SnapexPay.php
   chown -R www-data:www-data library/Payment/Adapter/SnapexPay   # your web server user
   ```

2. In the admin area go to **System → Payment gateways → New payment gateway**, and install **SnapexPay**.
3. Open the gateway, fill in the settings below, tick **Enabled** and **Allow One Time Payments**, and save.

## Configuration

| Field (key) | Default | Description |
|---|---|---|
| API key (`api_key`) | — | Required. From the SnapEX Pay cabinet → API keys, with scopes `invoices.read` and `invoices.write`. |
| Webhook secret (`webhook_secret`) | — | Required. The `whsec_…` secret shown when you create the webhook endpoint. Unsigned or wrongly signed webhooks are rejected. |
| Network fee paid by (`fee_on`) | `merchant` | `merchant`: the client pays the invoice amount. `customer`: the network fee is added on top. |
| Payment window, minutes (`expires_minutes`) | `60` | 1–1440. How long the SnapEX invoice stays payable. |
| USD per 1 unit of invoice currency (`usd_rate`) | empty | Ignored for USD invoices. For other currencies, enter a fixed rate (for EUR at 1.08 USD, enter `1.08`), or leave it empty to use FOSSBilling's own currency rates. That needs USD to exist under **System → Currencies**. SnapEX invoices are always in USD. |
| Partner ID (`partner_id`) | empty | Optional. The Partner ID of the integrator who set this up (SnapEX Pay referral program). Leave empty if you set it up yourself. |
| API URL (`api_url`) | `https://snapex.pro/api/v1/merchant` | Leave empty. |
| Enable Test Mode (FOSSBilling switch) | off | On: SnapEX test invoices, confirmable without real money. Off: live invoices. Test payments are ignored while it is off. |

## Webhook (callback URL) setup

The gateway page in FOSSBilling shows an **IPN Callback URL**, for example:

```
https://billing.example.com/ipn.php?gateway_id=3
```

In the SnapEX Pay cabinet, add this URL as a webhook endpoint, subscribe it to `invoice.paid` (`invoice.partially_paid` and `invoice.extra_payment` are useful too, since they show up as transactions that need review), and paste the `whsec_…` secret into the gateway settings. Other events are accepted and quietly dropped.

## Testing

- **Without money:** turn on **Enable Test Mode**, pay an invoice and confirm the test payment on the SnapEX Pay page. The invoice should switch to *Paid*, with one processed transaction whose id is `INV-…`.
- **Unit checks:** `php tests/run.php` (PHP 8.1+, no dependencies). This covers the adapter contract against FOSSBilling 0.8 models and against current Doctrine entities, signature verification and the replay window, the API request shape, double webhooks, test/live separation, multi-site isolation, amount checks and deposit invoices, using a mocked SnapEX API.

## FAQ / Troubleshooting

**SnapexPay isn't offered under "New payment gateway".**
Check that the path is `library/Payment/Adapter/SnapexPay/SnapexPay.php` (case-sensitive), relative to the folder that contains `ipn.php`, and that the web server can read the file.

**The client paid but the invoice is still unpaid.**
Open **Invoices → Transactions** in FOSSBilling. If there is no transaction, check the webhook deliveries in the SnapEX Pay cabinet and make sure the URL includes `?gateway_id=`. *SnapEX Pay webhook signature is invalid* means the secret doesn't match, or the server clock is more than 5 minutes off. *was paid while the gateway is not in test mode* means a test invoice was paid on a live gateway. After fixing the cause you can process the transaction again from the admin area.

**A transaction says "partially paid", "already paid" or "total changed".**
These are never credited automatically. Check the payment in the SnapEX cabinet, then add funds or mark the invoice paid by hand. *Already paid* means the client paid a second SnapEX invoice for an invoice that was already settled.

**"Amount is below the … USD minimum".**
SnapEX Pay sets a minimum invoice amount. The error comes straight from the API.

**I changed the FOSSBilling URL.**
Each site's invoices are tagged with a hash of its URL (without `http://`/`https://`). SnapEX invoices created before the change won't settle automatically, so add those payments by hand.

**Upgrading from 1.0.0.**
The *Environment* field is gone. Use FOSSBilling's **Enable Test Mode** switch instead. SnapEX invoices opened with 1.0.0 (`external_id` `fossbilling-<id>`) aren't settled by 1.1, so apply any still-open ones by hand.

**Do I need my own wallet or node?**
No. SnapEX Pay watches the blockchain and credits your merchant balance.

## Other SnapEX Pay integrations

All modules, pricing and sign-up: **[pay.snapex.pro](https://pay.snapex.pro)**.

- [Marzban](https://pay.snapex.pro/integrations/marzban)
- [3x-ui](https://pay.snapex.pro/integrations/3x-ui)
- [Xboard / V2Board](https://pay.snapex.pro/integrations/xboard)
- [Remnawave](https://pay.snapex.pro/integrations/remnawave)
- [Telegram VPN bot](https://pay.snapex.pro/integrations/telegram-vpn-bot)
- [Paymenter](https://pay.snapex.pro/integrations/paymenter)
- [n8n](https://pay.snapex.pro/integrations/n8n)

## Support

- Website, cabinet and API docs: https://snapex.pro
- Bugs and feature requests: write to us in the chat on [pay.snapex.pro](https://pay.snapex.pro).

## License

[MIT](LICENSE)
