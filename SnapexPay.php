<?php

use FOSSBilling\InjectionAwareInterface;

// FOSSBilling 0.8 has no JSON webhook authenticator interface yet; newer builds reject
// JSON callbacks from adapters that do not implement it. Alias a local copy on old builds.
if (! interface_exists('FOSSBilling\\Payment\\JsonWebhookAuthenticatorInterface')) {
    interface Payment_Adapter_SnapexPay_JsonWebhookAuthenticator
    {
        public function authenticateJsonWebhook(array $data): void;
    }

    class_alias('Payment_Adapter_SnapexPay_JsonWebhookAuthenticator', 'FOSSBilling\\Payment\\JsonWebhookAuthenticatorInterface');
}

/**
 * FOSSBilling payment adapter for SnapEX Pay (USDT TRC20 and other coins).
 *
 * Install: copy this file to library/Payment/Adapter/SnapexPay/SnapexPay.php
 * Class name must stay Payment_Adapter_SnapexPay.
 */
class Payment_Adapter_SnapexPay implements InjectionAwareInterface, FOSSBilling\Payment\JsonWebhookAuthenticatorInterface
{
    public const VERSION = '1.2.0';

    private const DEFAULT_API_URL = 'https://snapex.pro/api/v1/merchant';

    private const WEBHOOK_TOLERANCE = 300;

    /** external_id / Idempotency-Key: fossbilling-{site}-{invoice}-{total}{CUR}-{usd}-{env}-{bucket} */
    private const EXTERNAL_ID = '/^fossbilling-([0-9a-f]{8})-(\d+)-(\d+\.\d{2})([A-Z]{3})-(\d+\.\d{2})-(live|test)-\d+$/';

    /** @var callable|null fn(string $method, string $url, array $headers, ?string $body): array{0:int,1:string} */
    public static $transport;

    protected $di;

    protected array $config = [];

    public function __construct($config)
    {
        $this->config = is_array($config) ? $config : [];
    }

    public function setDi($di): void
    {
        $this->di = $di;
    }

    public function getDi()
    {
        return $this->di;
    }

    public static function requiresManualApproval(): bool
    {
        return false;
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Accept USDT (TRC20, ERC20, BEP20) and other coins via SnapEX Pay. The customer pays on the SnapEX page; FOSSBilling marks the invoice paid from a signed webhook. Set the IPN callback URL below as a webhook endpoint in the SnapEX Pay cabinet. "Enable test mode" creates SnapEX test invoices.',
            'logo' => [
                'logo' => 'snapexpay.png',
                'height' => '30px',
                'width' => '65px',
            ],
            'form' => [
                'api_key' => ['password', [
                    'label' => 'API key (invoices.read, invoices.write):',
                    'required_when' => ['enabled' => true],
                    'secret' => true,
                ]],
                'webhook_secret' => ['password', [
                    'label' => 'Webhook secret (whsec_…):',
                    'required_when' => ['enabled' => true],
                    'secret' => true,
                ]],
                'fee_on' => ['select', [
                    'label' => 'Network fee paid by:',
                    'multiOptions' => [
                        'merchant' => 'Merchant (client pays the invoice amount)',
                        'customer' => 'Customer (fee added on top)',
                    ],
                ]],
                'expires_minutes' => ['text', [
                    'label' => 'Payment window, minutes (1–1440, default 60):',
                ]],
                'usd_rate' => ['text', [
                    'label' => 'USD per 1 unit of invoice currency (empty = FOSSBilling currency rates; ignored for USD invoices):',
                ]],
                'partner_id' => ['text', [
                    'label' => 'Partner ID (optional): the integrator\'s ID in the SnapEX Pay referral program. Leave empty if you set this up yourself.',
                ]],
                'api_url' => ['text', [
                    'label' => 'API URL (leave empty for https://snapex.pro/api/v1/merchant):',
                ]],
            ],
        ];
    }

    public function getInvoiceId($data)
    {
        $event = self::parseEvent((string) ($data['http_raw_post_data'] ?? ''));
        $parsed = self::parseExternalId((string) ($event['data']['external_id'] ?? ''));
        if ($parsed !== null && $parsed['site'] === self::siteTag()) {
            return $parsed['invoice_id'];
        }
        $fromGet = $data['get']['invoice_id'] ?? $data['invoice_id'] ?? null;

        return is_numeric($fromGet) ? (int) $fromGet : null;
    }

    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        if ($subscription) {
            throw new Payment_Exception('SnapEX Pay does not support subscriptions');
        }
        $invoiceId = (int) $invoice_id;
        $invoice = $this->invoiceModel($invoiceId);
        if ($this->invoiceStatus($invoice) === 'paid') {
            throw new Payment_Exception('This invoice is already paid');
        }
        $total = $this->money($this->invoiceTotal($invoice));
        $currency = strtoupper($this->invoiceCurrency($invoice));
        $amountUsd = $this->amountUsd((float) $total, $currency);
        $environment = $this->environment();
        $seconds = $this->expiresMinutes() * 60;
        // Same key for half the payment window: reloads reuse one SnapEX invoice that still has
        // at least half its time left; a later visit gets a fresh one.
        $bucket = intdiv(time(), max(60, intdiv($seconds, 2)));
        $key = sprintf('fossbilling-%s-%d-%s%s-%s-%s-%d', self::siteTag(), $invoiceId, $total, $currency, $amountUsd, $environment, $bucket);

        $payload = [
            'amount_usd' => $amountUsd,
            'fee_on' => $this->feeOn(),
            'environment' => $environment,
            'external_id' => $key,
            'description' => $this->description($invoice, $invoiceId),
            'expires_in' => $seconds,
        ];
        $partnerId = trim((string) ($this->config['partner_id'] ?? ''));
        if ($partnerId !== '') {
            $payload['partner_ref'] = $partnerId;
        }
        $success = trim((string) ($this->config['return_url'] ?? $this->config['thankyou_url'] ?? ''));
        $cancel = trim((string) ($this->config['cancel_url'] ?? ''));
        if ($success !== '') {
            $payload['success_url'] = $success;
        }
        if ($cancel !== '') {
            $payload['cancel_url'] = $cancel;
        }
        $created = $this->request('POST', '/invoices', $payload, ['Idempotency-Key' => $key]);

        $payUrl = (string) ($created['pay_url'] ?? '');
        if ($payUrl === '' || ! preg_match('#^https?://#i', $payUrl)) {
            throw new Payment_Exception('SnapEX Pay did not return a payment URL');
        }

        $safeUrl = htmlspecialchars($payUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<a class="btn btn-primary" id="snapexpay-pay" href="'.$safeUrl.'" rel="noopener">Pay with crypto (SnapEX Pay)</a>';
        if (! empty($this->config['auto_redirect'])) {
            $html .= '<script>window.location.href = '
                .json_encode($payUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES)
                .';</script>';
        }

        return $html;
    }

    /**
     * Called by FOSSBilling (newer builds) before a JSON callback is stored.
     */
    public function authenticateJsonWebhook(array $data): void
    {
        $secret = trim((string) ($this->config['webhook_secret'] ?? ''));
        if ($secret === '') {
            throw new Payment_Exception('SnapEX Pay webhook secret is not configured');
        }
        if (! self::verifyWebhook((string) ($data['http_raw_post_data'] ?? ''), $secret, $this->webhookHeaders($data))) {
            throw new Payment_Exception('SnapEX Pay webhook signature is invalid');
        }
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $data = is_array($data) ? $data : [];
        $body = (string) ($data['http_raw_post_data'] ?? '');
        $secret = trim((string) ($this->config['webhook_secret'] ?? ''));
        $tx = $this->transactionModel((int) $id);
        // Freshness is checked against the moment FOSSBilling received the callback, so a
        // cron retry or a manual "Process" later on still works; replays are harmless anyway.
        if ($secret === '' || ! self::verifyWebhook($body, $secret, $this->webhookHeaders($data), $this->createdAt($tx))) {
            throw new Payment_Exception('SnapEX Pay webhook signature is invalid');
        }
        $event = self::parseEvent($body);
        $type = (string) ($event['type'] ?? '');
        $snapexId = (string) ($event['data']['invoice_id'] ?? '');
        if ($snapexId === '' || ! in_array($type, ['invoice.paid', 'invoice.partially_paid', 'invoice.extra_payment'], true)) {
            $this->discard($tx); // informational event, nothing to book

            return;
        }

        // Never trust the webhook body alone: the invoice is re-read from the API.
        $remote = $this->request('GET', '/invoices/'.rawurlencode($snapexId));
        $parsed = self::parseExternalId((string) ($remote['external_id'] ?? ''));
        if ((string) ($remote['invoice_id'] ?? '') !== $snapexId || $parsed === null || $parsed['site'] !== self::siteTag()) {
            $this->discard($tx); // another shop on the same SnapEX account

            return;
        }
        $status = (string) ($remote['status'] ?? '');
        if ($status === 'partially_paid') {
            throw new Payment_Exception('SnapEX invoice :id was only partially paid; review it in the SnapEX Pay cabinet', [':id' => $snapexId]);
        }
        if ($status !== 'paid') {
            $this->discard($tx);

            return;
        }
        if (($remote['environment'] ?? '') !== $parsed['environment']
            || ($parsed['environment'] === 'test' && ! $this->isTestMode())) {
            throw new Payment_Exception('SnapEX test invoice :id was paid while the gateway is not in test mode; not applied', [':id' => $snapexId]);
        }
        if ($this->decimal($remote['amount_usd'] ?? '') !== $parsed['amount_usd']) {
            throw new Payment_Exception('SnapEX invoice :id amount does not match; not applied', [':id' => $snapexId]);
        }

        $this->withLock('snapexpay-'.substr(sha1($gateway_id.':'.$snapexId), 0, 40), function () use ($tx, $id, $gateway_id, $snapexId, $type, $parsed): void {
            $ownStatus = (string) (method_exists($tx, 'getStatus') ? $tx->getStatus() : ($tx->status ?? ''));
            $ownTxn = (string) (method_exists($tx, 'getTxnId') ? $tx->getTxnId() : ($tx->txn_id ?? ''));
            if ($ownStatus === 'processed' && $ownTxn === $snapexId) {
                return; // this very transaction was already booked
            }
            if ($this->processedDuplicate($snapexId, (int) $gateway_id, (int) $id)) {
                if ($type === 'invoice.extra_payment') {
                    throw new Payment_Exception('Extra payment received on SnapEX invoice :id; check the SnapEX Pay cabinet', [':id' => $snapexId]);
                }
                $this->discard($tx); // retry of a webhook that was already booked

                return;
            }

            $invoice = $this->invoiceModel($parsed['invoice_id']);
            $invoiceStatus = $this->invoiceStatus($invoice);
            if ($invoiceStatus === 'paid') {
                throw new Payment_Exception('Invoice #:invoice is already paid; SnapEX invoice :id needs manual review (refund or add funds)', [':invoice' => $parsed['invoice_id'], ':id' => $snapexId]);
            }
            if ($invoiceStatus !== 'unpaid') {
                throw new Payment_Exception('Invoice #:invoice is :status; SnapEX invoice :id was not applied', [':invoice' => $parsed['invoice_id'], ':status' => $invoiceStatus, ':id' => $snapexId]);
            }
            $total = $this->money($this->invoiceTotal($invoice));
            $currency = strtoupper($this->invoiceCurrency($invoice));
            if ($total !== $parsed['total'] || $currency !== $parsed['currency']) {
                throw new Payment_Exception('Invoice #:invoice total changed after SnapEX invoice :id was created; not applied', [':invoice' => $parsed['invoice_id'], ':id' => $snapexId]);
            }

            $this->creditAndPay($invoice, (float) $total, $snapexId, $tx);
            $this->markProcessed($tx, $invoice, $snapexId, $total, $currency);
        });
    }

    public static function verifyWebhook(string $body, string $secret, array $headers, ?int $now = null): bool
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_scalar($value) ? (string) $value : '';
        }
        $timestamp = $normalized['webhook-timestamp'] ?? '';
        $signature = $normalized['webhook-signature'] ?? '';
        if ($secret === '' || $body === '' || ! ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > self::WEBHOOK_TOLERANCE) {
            return false;
        }

        return hash_equals('v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature);
    }

    public static function parseEvent(string $body): array
    {
        $event = json_decode($body, true);

        return is_array($event) ? $event : [];
    }

    /**
     * @return array{site:string, invoice_id:int, total:string, currency:string, amount_usd:string, environment:string}|null
     */
    public static function parseExternalId(string $external): ?array
    {
        if (! preg_match(self::EXTERNAL_ID, $external, $m)) {
            return null;
        }

        return ['site' => $m[1], 'invoice_id' => (int) $m[2], 'total' => $m[3], 'currency' => $m[4], 'amount_usd' => $m[5], 'environment' => $m[6]];
    }

    /** Distinguishes FOSSBilling installs that share one SnapEX merchant account. */
    public static function siteTag(): string
    {
        $url = defined('SYSTEM_URL') ? (string) constant('SYSTEM_URL') : '';

        $url = (string) preg_replace('#^https?://#i', '', trim($url));

        return substr(sha1(rtrim(strtolower($url), '/')), 0, 8);
    }

    public function amountUsd(float $total, string $currency): string
    {
        if ($total <= 0) {
            throw new Payment_Exception('SnapEX Pay: nothing to pay on this invoice');
        }
        $usd = round($total * $this->usdPerUnit(strtoupper($currency)), 2);
        if ($usd <= 0) {
            throw new Payment_Exception('SnapEX Pay: invoice total is too small after USD conversion');
        }

        return number_format($usd, 2, '.', '');
    }

    private function usdPerUnit(string $currency): float
    {
        if ($currency === 'USD') {
            return 1.0;
        }
        $configured = trim(str_replace(',', '.', (string) ($this->config['usd_rate'] ?? '')));
        if ($configured !== '') {
            if (! is_numeric($configured) || (float) $configured <= 0) {
                throw new Payment_Exception('SnapEX Pay: the USD rate setting is not a positive number');
            }

            return (float) $configured;
        }
        // FOSSBilling rates are "units of this currency per 1 default currency".
        try {
            $repository = $this->di['mod_service']('currency')->getCurrencyRepository();
            $from = $repository->getRateByCode($currency);
            $usd = $repository->getRateByCode('USD');
        } catch (Throwable) {
            $from = $usd = null;
        }
        if (! $from || ! $usd || (float) $from <= 0 || (float) $usd <= 0) {
            throw new Payment_Exception('SnapEX Pay: set the USD rate, or add USD as a currency in FOSSBilling, so :currency invoices can be converted', [':currency' => $currency]);
        }

        return (float) $usd / (float) $from;
    }

    private function useOrm(string $entity): bool
    {
        return isset($this->di['em']) && class_exists('Box\\Mod\\'.$entity);
    }

    private function invoiceModel(int $invoiceId): object
    {
        if ($this->useOrm('Invoice\\Entity\\Invoice')) {
            $invoice = $this->di['em']->getRepository('Box\\Mod\\Invoice\\Entity\\Invoice')->find($invoiceId);
            if (is_object($invoice)) {
                return $invoice;
            }
            throw new Payment_Exception('Invoice not found');
        }
        if (isset($this->di['db'])) {
            return $this->di['db']->getExistingModelById('Invoice', $invoiceId, 'Invoice not found');
        }
        throw new Payment_Exception('Invoice not found');
    }

    private function transactionModel(int $id): object
    {
        if ($this->useOrm('Invoice\\Entity\\Transaction')) {
            $tx = $this->di['em']->getRepository('Box\\Mod\\Invoice\\Entity\\Transaction')->find($id);
            if (is_object($tx)) {
                return $tx;
            }
            throw new Payment_Exception('Transaction not found');
        }
        if (isset($this->di['db'])) {
            return $this->di['db']->getExistingModelById('Transaction', $id, 'Transaction not found');
        }
        throw new Payment_Exception('Transaction not found');
    }

    private function findClient(int $clientId): object
    {
        if ($this->useOrm('Client\\Entity\\Client')) {
            $client = $this->di['em']->getRepository('Box\\Mod\\Client\\Entity\\Client')->find($clientId);
            if (is_object($client)) {
                return $client;
            }
            throw new Payment_Exception('Client not found');
        }
        if (isset($this->di['db'])) {
            return $this->di['db']->getExistingModelById('Client', $clientId, 'Client not found');
        }
        throw new Payment_Exception('Client not found');
    }

    private function invoiceTotal(object $invoice): float
    {
        return (float) $this->di['mod_service']('Invoice')->getTotalWithTax($invoice);
    }

    private function invoiceCurrency(object $invoice): string
    {
        return (string) (method_exists($invoice, 'getCurrency') ? $invoice->getCurrency() : ($invoice->currency ?? 'USD'));
    }

    private function invoiceStatus(object $invoice): string
    {
        return (string) (method_exists($invoice, 'getStatus') ? $invoice->getStatus() : ($invoice->status ?? ''));
    }

    private function clientId(object $invoice): int
    {
        return (int) (method_exists($invoice, 'getClientId') ? $invoice->getClientId() : ($invoice->client_id ?? 0));
    }

    private function entityId(object $model): ?int
    {
        $id = method_exists($model, 'getId') ? $model->getId() : ($model->id ?? null);

        return $id === null ? null : (int) $id;
    }

    private function createdAt(object $tx): ?int
    {
        $created = method_exists($tx, 'getCreatedAt') ? $tx->getCreatedAt() : ($tx->created_at ?? null);
        if ($created instanceof DateTimeInterface) {
            return $created->getTimestamp();
        }
        $time = is_string($created) && $created !== '' ? strtotime($created) : false;

        return $time === false ? null : $time;
    }

    private function processedDuplicate(string $snapexId, int $gatewayId, int $excludeId): bool
    {
        if ($this->useOrm('Invoice\\Entity\\Transaction')) {
            $row = $this->di['em']->createQuery(
                'SELECT t.id FROM Box\\Mod\\Invoice\\Entity\\Transaction t'
                .' WHERE t.txnId = :txn AND IDENTITY(t.gateway) = :gateway AND t.id != :id AND t.status = :status'
            )->setParameter('txn', $snapexId)
                ->setParameter('gateway', $gatewayId)
                ->setParameter('id', $excludeId)
                ->setParameter('status', 'processed')
                ->setMaxResults(1)
                ->getOneOrNullResult();

            return $row !== null;
        }

        return $this->di['db']->findOne(
            'Transaction',
            'txn_id = :txn AND gateway_id = :gateway AND id != :id AND status = :status',
            [':txn' => $snapexId, ':gateway' => $gatewayId, ':id' => $excludeId, ':status' => 'processed']
        ) !== null;
    }

    private function creditAndPay(object $invoice, float $amount, string $snapexId, object $tx): void
    {
        $clientService = $this->di['mod_service']('client');
        $invoiceService = $this->di['mod_service']('Invoice');
        $client = $this->findClient($this->clientId($invoice));
        $desc = 'SnapEX Pay '.$snapexId;
        $clientService->addFunds($client, $amount, $desc, [
            'amount' => $amount,
            'description' => $desc,
            'type' => 'transaction',
            'rel_id' => $this->entityId($tx),
        ]);
        if ($invoiceService->isInvoiceTypeDeposit($invoice)) {
            $invoiceService->markAsPaid($invoice);

            return;
        }
        // Credits only cover issued (approved) invoices.
        if (method_exists($invoice, 'isIssued')) {
            if (! $invoice->isIssued()) {
                $invoiceService->issueInvoice($invoice, ['use_credits' => false]);
            }
        } elseif (empty($invoice->approved) && method_exists($invoiceService, 'approveInvoice')) {
            $invoiceService->approveInvoice($invoice, ['use_credits' => false]);
        }
        $invoiceService->payInvoiceWithCredits($invoice);
    }

    private function markProcessed(object $tx, object $invoice, string $snapexId, string $amount, string $currency): void
    {
        if (method_exists($tx, 'setStatus')) {
            if (method_exists($tx, 'getInvoice') && $tx->getInvoice() === null) {
                $tx->setInvoice($invoice);
            }
            $tx->setTxnId($snapexId);
            $tx->setTxnStatus('paid');
            $tx->setAmount($amount);
            $tx->setCurrency($currency);
            $tx->setType('payment');
            $tx->setError(null);
            $tx->setErrorCode(null);
            $tx->setStatus('processed');
            $tx->setUpdatedAt(new DateTime());
            $this->di['em']->persist($tx);
            $this->di['em']->flush();

            return;
        }
        if (empty($tx->invoice_id)) {
            $tx->invoice_id = $this->entityId($invoice);
        }
        $tx->txn_id = $snapexId;
        $tx->txn_status = 'paid';
        $tx->amount = $amount;
        $tx->currency = $currency;
        $tx->type = 'payment';
        $tx->error = null;
        $tx->error_code = null;
        $tx->status = 'processed';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
    }

    /** Removes a callback that books nothing, like the bundled Stripe adapter does. */
    private function discard(object $tx): void
    {
        if (method_exists($tx, 'setStatus')) {
            $this->di['em']->remove($tx);
            $this->di['em']->flush();

            return;
        }
        $this->di['db']->trash($tx);
    }

    private function withLock(string $name, callable $callback): void
    {
        $connection = isset($this->di['dbal']) ? $this->di['dbal'] : null;
        $release = null;
        if (is_object($connection) && class_exists('FOSSBilling\\Doctrine\\NamedLock')) {
            if (! FOSSBilling\Doctrine\NamedLock::acquire($connection, $name, 10)) {
                throw new Payment_Exception('SnapEX Pay payment is being processed by another request');
            }
            $release = fn () => FOSSBilling\Doctrine\NamedLock::release($connection, $name);
        } elseif (is_object($connection) && method_exists($connection, 'fetchOne')) {
            if ((int) $connection->fetchOne('SELECT GET_LOCK(?, 10)', [$name]) !== 1) {
                throw new Payment_Exception('SnapEX Pay payment is being processed by another request');
            }
            $release = fn () => $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
        }

        try {
            $callback();
        } finally {
            if ($release !== null) {
                $release();
            }
        }
    }

    private function webhookHeaders(array $data): array
    {
        $headers = [];
        foreach ((array) ($data['headers'] ?? []) as $name => $value) {
            $headers[(string) $name] = is_array($value) ? (string) reset($value) : (string) $value;
        }
        foreach ((array) ($data['server'] ?? []) as $name => $value) {
            $key = (string) $name;
            if (str_starts_with($key, 'HTTP_') && is_scalar($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }

        return $headers;
    }

    private function description(object $invoice, int $invoiceId): string
    {
        $serie = (string) (method_exists($invoice, 'getSerie') ? $invoice->getSerie() : ($invoice->serie ?? ''));
        $nr = (string) (method_exists($invoice, 'getNr') ? $invoice->getNr() : ($invoice->nr ?? ''));
        $label = trim($serie.($nr !== '' ? $nr : (string) $invoiceId));
        $text = 'FOSSBilling invoice '.$label;

        return function_exists('mb_substr') ? mb_substr($text, 0, 255) : substr($text, 0, 255);
    }

    private function money(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }

    private function decimal(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : '-1';
    }

    private function feeOn(): string
    {
        return (($this->config['fee_on'] ?? 'merchant') === 'customer') ? 'customer' : 'merchant';
    }

    private function isTestMode(): bool
    {
        return filter_var($this->config['test_mode'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function environment(): string
    {
        return $this->isTestMode() ? 'test' : 'live';
    }

    private function expiresMinutes(): int
    {
        $minutes = (int) ($this->config['expires_minutes'] ?? 60);

        return max(1, min(1440, $minutes ?: 60));
    }

    private function apiUrl(): string
    {
        $url = trim((string) ($this->config['api_url'] ?? ''));

        return rtrim($url !== '' ? $url : self::DEFAULT_API_URL, '/');
    }

    private function request(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        $apiKey = trim((string) ($this->config['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new Payment_Exception('SnapEX Pay API key is missing');
        }
        $headers = array_merge([
            'Authorization' => 'Bearer '.$apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'SnapEX-Pay-FOSSBilling/'.self::VERSION,
        ], $extraHeaders);
        $json = null;
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers['Content-Type'] = 'application/json';
        }
        $url = $this->apiUrl().$path;
        if (self::$transport !== null) {
            [$status, $raw] = (self::$transport)($method, $url, $headers, $json);
        } else {
            $curlHeaders = [];
            foreach ($headers as $name => $value) {
                $curlHeaders[] = $name.': '.$value;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $curlHeaders,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            if ($json !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            }
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                throw new Payment_Exception('SnapEX Pay is unreachable: :error', [':error' => $error]);
            }
        }
        $decoded = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300 || ! is_array($decoded) || ! isset($decoded['data']) || ! is_array($decoded['data'])) {
            $message = is_array($decoded) && is_string($decoded['error']['message'] ?? null) ? (string) $decoded['error']['message'] : '';
            $details = is_array($decoded) && is_array($decoded['error']['details'] ?? null) ? $decoded['error']['details'] : [];
            foreach ($details as $field => $messages) {
                $first = is_array($messages) ? reset($messages) : $messages;
                if (is_string($first) && $first !== '') {
                    $message .= ' '.$field.': '.$first;
                }
            }

            throw new Payment_Exception('SnapEX Pay error (HTTP :status): :message', [':status' => (string) $status, ':message' => trim($message) !== '' ? trim($message) : 'unexpected response']);
        }

        return $decoded['data'];
    }
}
