<?php

// Standalone tests: php tests/run.php
// Minimal stand-ins for the FOSSBilling classes the adapter touches (real signatures from
// FOSSBilling 0.8.x / main), plus a fake SnapEX Pay API that mirrors routes/api.php.

namespace FOSSBilling {
    interface InjectionAwareInterface
    {
        public function setDi(\Pimple\Container $di): void;
    }

    class Exception extends \Exception
    {
        public function __construct(string $message, ?array $variables = null, int $code = 0, bool $protected = false)
        {
            parent::__construct($variables ? strtr($message, $variables) : $message, $code);
        }
    }
}

namespace Pimple {
    class Container implements \ArrayAccess
    {
        private array $values = [];

        public function offsetExists(mixed $offset): bool
        {
            return isset($this->values[$offset]);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->values[$offset];
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->values[$offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->values[$offset]);
        }
    }
}

namespace Box\Mod\Invoice\Entity {
    // Doctrine-style entities (FOSSBilling main). Only loaded for the ORM part of the run.
    if (getenv('SNAPEX_ORM') === '1') {
        class Invoice
        {
            public bool $deposit = false;

            public function __construct(public int $id, public int $clientId, public string $currency, public float $total, public string $status = 'unpaid', public bool $issued = true)
            {
            }

            public function getId(): int { return $this->id; }
            public function getClientId(): int { return $this->clientId; }
            public function getCurrency(): string { return $this->currency; }
            public function getStatus(): string { return $this->status; }
            public function getSerie(): string { return 'FOSS'; }
            public function getNr(): string { return (string) $this->id; }
            public function isIssued(): bool { return $this->issued; }
        }

        class Transaction
        {
            public ?string $txnId = null;
            public ?string $txnStatus = null;
            public ?string $amount = null;
            public ?string $currency = null;
            public ?string $type = null;
            public ?string $error = null;
            public ?int $errorCode = null;
            public string $status = 'received';
            public ?Invoice $invoice = null;
            public ?\DateTime $createdAt;
            public ?\DateTime $updatedAt = null;

            public function __construct(public int $id, public int $gatewayId = 1)
            {
                $this->createdAt = new \DateTime();
            }

            public function getId(): int { return $this->id; }
            public function getInvoice(): ?Invoice { return $this->invoice; }
            public function setInvoice(?Invoice $invoice): self { $this->invoice = $invoice; return $this; }
            public function getTxnId(): ?string { return $this->txnId; }
            public function setTxnId(?string $v): self { $this->txnId = $v; return $this; }
            public function setTxnStatus(?string $v): self { $this->txnStatus = $v; return $this; }
            public function setAmount($v): self { $this->amount = (string) $v; return $this; }
            public function setCurrency(?string $v): self { $this->currency = $v; return $this; }
            public function setType(?string $v): self { $this->type = $v; return $this; }
            public function setError(?string $v): self { $this->error = $v; return $this; }
            public function setErrorCode(?int $v): self { $this->errorCode = $v; return $this; }
            public function getStatus(): string { return $this->status; }
            public function setStatus(string $v): self { $this->status = $v; return $this; }
            public function getCreatedAt(): ?\DateTime { return $this->createdAt; }
            public function setUpdatedAt(?\DateTime $v): self { $this->updatedAt = $v; return $this; }
        }
    }
}

namespace Box\Mod\Client\Entity {
    if (getenv('SNAPEX_ORM') === '1') {
        class Client
        {
            public function __construct(public int $id)
            {
            }

            public function getId(): int { return $this->id; }
        }
    }
}

namespace {
    class Payment_Exception extends FOSSBilling\Exception
    {
        public function __construct(string $message, ?array $variables = null, int $code = 0)
        {
            parent::__construct($message, $variables, $code, true);
        }
    }

    define('SYSTEM_URL', 'https://billing.example/');

    require dirname(__DIR__).'/SnapexPay.php';

    $orm = getenv('SNAPEX_ORM') === '1';
    $failures = 0;
    function check($cond, string $label): void
    {
        global $failures;
        echo ($cond ? '  ok   ' : '  FAIL ').$label."\n";
        if (! $cond) {
            $failures++;
        }
    }

    function throws(callable $fn, ?string $contains = null): bool
    {
        try {
            $fn();
        } catch (Payment_Exception $e) {
            return $contains === null || str_contains($e->getMessage(), $contains);
        }

        return false;
    }

    function signed(string $secret, array $payload, ?int $ts = null): array
    {
        $ts = (string) ($ts ?? time());
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return [$body, [
            'HTTP_WEBHOOK_ID' => 'evt_1',
            'HTTP_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_WEBHOOK_SIGNATURE' => 'v1='.hash_hmac('sha256', $ts.'.'.$body, $secret),
            'CONTENT_TYPE' => 'application/json',
        ]];
    }

    // ---------------------------------------------------------------- fake SnapEX API

    $GLOBALS['remote'] = [];
    $GLOBALS['keys'] = [];
    $GLOBALS['calls'] = [];
    $GLOBALS['api_down'] = false;
    Payment_Adapter_SnapexPay::$transport = function ($method, $url, $headers, $body) {
        $GLOBALS['calls'][] = compact('method', 'url', 'headers', 'body');
        if ($GLOBALS['api_down']) {
            return [503, '<html>down</html>'];
        }
        if (! str_starts_with($url, 'https://snapex.pro/api/v1/merchant/')) {
            return [404, json_encode(['error' => ['code' => 'not_found', 'message' => 'The requested resource was not found.']])];
        }
        if (($headers['Authorization'] ?? '') !== 'Bearer sk_test') {
            return [401, json_encode(['error' => ['code' => 'unauthorized', 'message' => 'A valid merchant API key is required.']])];
        }
        $path = substr($url, strlen('https://snapex.pro/api/v1/merchant'));
        if ($method === 'POST' && $path === '/invoices') {
            $key = (string) ($headers['Idempotency-Key'] ?? '');
            if ($key === '' || strlen($key) > 191) {
                return [422, json_encode(['error' => ['code' => 'validation_error', 'message' => 'A valid Idempotency-Key header is required.']])];
            }
            $data = json_decode((string) $body, true);
            if (! preg_match('/^[0-9]+(?:\.[0-9]{1,8})?$/', (string) ($data['amount_usd'] ?? ''))
                || ! is_int($data['expires_in'] ?? 0) || $data['expires_in'] < 60 || $data['expires_in'] > 86400
                || strlen((string) ($data['external_id'] ?? '')) > 191) {
                return [422, json_encode(['error' => ['code' => 'validation_error', 'message' => 'The request is invalid.', 'details' => ['amount_usd' => ['bad']]]])];
            }
            if (isset($GLOBALS['keys'][$key])) {
                return [200, json_encode(['data' => $GLOBALS['remote'][$GLOBALS['keys'][$key]]])];
            }
            foreach ($GLOBALS['remote'] as $row) {
                if ($row['external_id'] === $data['external_id']) {
                    return [409, json_encode(['error' => ['code' => 'conflict', 'message' => 'external_id has already been used by this merchant.']])];
                }
            }
            $id = 'inv_'.(count($GLOBALS['remote']) + 1);
            $GLOBALS['remote'][$id] = [
                'invoice_id' => $id,
                'environment' => $data['environment'] ?? 'live',
                'amount_usd' => number_format((float) $data['amount_usd'], 2, '.', ''),
                'fee_on' => $data['fee_on'] ?? 'merchant',
                'status' => 'open',
                'pay_url' => 'https://pay.snapex.pro/'.bin2hex(random_bytes(4)),
                'external_id' => $data['external_id'],
                'description' => $data['description'] ?? null,
            ];
            $GLOBALS['keys'][$key] = $id;

            return [201, json_encode(['data' => $GLOBALS['remote'][$id]])];
        }
        if ($method === 'GET' && preg_match('#^/invoices/([^/]+)$#', $path, $m)) {
            $id = rawurldecode($m[1]);

            return isset($GLOBALS['remote'][$id])
                ? [200, json_encode(['data' => $GLOBALS['remote'][$id]])]
                : [404, json_encode(['error' => ['code' => 'not_found', 'message' => 'The requested resource was not found.']])];
        }

        return [404, json_encode(['error' => ['code' => 'not_found', 'message' => 'The requested resource was not found.']])];
    };

    // ---------------------------------------------------------------- fake FOSSBilling

    $state = (object) ['funds' => 0.0, 'paid' => [], 'approved' => [], 'trashed' => [], 'marked' => []];
    $invoices = [];
    $txs = [];

    function newInvoice(int $id, float $total, string $currency = 'USD', string $status = 'unpaid', bool $issued = true): object
    {
        global $orm, $invoices;
        $invoices[$id] = $orm
            ? new Box\Mod\Invoice\Entity\Invoice($id, 7, $currency, $total, $status, $issued)
            : (object) ['id' => $id, 'client_id' => 7, 'currency' => $currency, 'total' => $total, 'status' => $status, 'approved' => $issued ? 1 : 0, 'serie' => 'FOSS', 'nr' => (string) $id, 'deposit' => false];

        return $invoices[$id];
    }

    function newTx(int $id, ?int $createdAt = null): object
    {
        global $orm, $txs;
        if ($orm) {
            $tx = new Box\Mod\Invoice\Entity\Transaction($id);
            if ($createdAt !== null) {
                $tx->createdAt = (new DateTime())->setTimestamp($createdAt);
            }
        } else {
            $tx = (object) ['id' => $id, 'gateway_id' => 1, 'invoice_id' => null, 'txn_id' => null, 'status' => 'received', 'created_at' => date('Y-m-d H:i:s', $createdAt ?? time())];
        }

        return $txs[$id] = $tx;
    }

    function prop(object $o, string $orm, string $redbean)
    {
        return property_exists($o, $orm) ? $o->{$orm} : ($o->{$redbean} ?? null);
    }

    $di = new Pimple\Container();
    $invoiceService = new class($state)
    {
        public function __construct(private $state)
        {
        }

        public function getTotalWithTax($invoice): float
        {
            return (float) $invoice->total;
        }

        public function isInvoiceTypeDeposit($invoice): bool
        {
            return ! empty($invoice->deposit);
        }

        public function issueInvoice($invoice, array $data): bool
        {
            $invoice->issued = true;
            $this->state->approved[] = $invoice->id;

            return true;
        }

        public function approveInvoice($invoice, array $data): bool
        {
            $invoice->approved = 1;
            $this->state->approved[] = $invoice->id;

            return true;
        }

        public function payInvoiceWithCredits($invoice): bool
        {
            $issued = method_exists($invoice, 'isIssued') ? $invoice->isIssued() : (bool) $invoice->approved;
            if (! $issued || $invoice->status === 'paid' || $this->state->funds < $invoice->total) {
                return false;
            }
            $this->state->funds -= $invoice->total;
            $invoice->status = 'paid';
            $this->state->paid[] = $invoice->id;

            return true;
        }

        public function markAsPaid($invoice, $charge = true, $execute = false): bool
        {
            $invoice->status = 'paid';
            $this->state->marked[] = $invoice->id;

            return true;
        }
    };
    $clientService = new class($state)
    {
        public array $calls = [];

        public function __construct(private $state)
        {
        }

        public function addFunds($client, $amount, $description, array $data = []): bool
        {
            $this->state->funds += (float) $amount;
            $this->calls[] = [$amount, $description, $data];

            return true;
        }
    };
    $currencyRepo = new class
    {
        public array $rates = ['USD' => 1.0];

        public function getRateByCode(string $code): ?float
        {
            return $this->rates[$code] ?? null;
        }
    };
    $di['mod_service'] = function ($name) use ($invoiceService, $clientService, $currencyRepo) {
        return match (strtolower((string) $name)) {
            'client' => $clientService,
            'currency' => new class($currencyRepo)
            {
                public function __construct(private $repo)
                {
                }

                public function getCurrencyRepository()
                {
                    return $this->repo;
                }
            },
            default => $invoiceService,
        };
    };
    $locks = new class
    {
        public array $taken = [];

        public function fetchOne(string $sql, array $params = [])
        {
            if (str_starts_with($sql, 'SELECT GET_LOCK')) {
                $this->taken[] = $params[0];

                return 1;
            }

            return 1;
        }
    };
    $di['dbal'] = $locks;

    if ($orm) {
        $di['em'] = new class($state)
        {
            public function __construct(private $state)
            {
            }

            public function getRepository(string $class)
            {
                return new class($class)
                {
                    public function __construct(private string $class)
                    {
                    }

                    public function find($id)
                    {
                        global $invoices, $txs;

                        return match ($this->class) {
                            'Box\\Mod\\Invoice\\Entity\\Invoice' => $invoices[(int) $id] ?? null,
                            'Box\\Mod\\Invoice\\Entity\\Transaction' => $txs[(int) $id] ?? null,
                            'Box\\Mod\\Client\\Entity\\Client' => new Box\Mod\Client\Entity\Client((int) $id),
                        };
                    }
                };
            }

            public function createQuery(string $dql)
            {
                return new class($dql)
                {
                    private array $p = [];

                    public function __construct(public string $dql)
                    {
                    }

                    public function setParameter($k, $v): self
                    {
                        $this->p[$k] = $v;

                        return $this;
                    }

                    public function setMaxResults($n): self
                    {
                        return $this;
                    }

                    public function getOneOrNullResult()
                    {
                        global $txs;
                        foreach ($txs as $tx) {
                            if ($tx->txnId === $this->p['txn'] && $tx->gatewayId === $this->p['gateway'] && $tx->id !== $this->p['id'] && $tx->status === $this->p['status']) {
                                return ['id' => $tx->id];
                            }
                        }

                        return null;
                    }
                };
            }

            public function persist($e): void
            {
            }

            public function flush(): void
            {
            }

            public function remove($e): void
            {
                global $txs;
                unset($txs[$e->id]);
                $this->state->trashed[] = $e->id;
            }
        };
    } else {
        $di['db'] = new class($state)
        {
            public function __construct(private $state)
            {
            }

            public function getExistingModelById(string $type, $id, string $message = 'Model not found')
            {
                global $invoices, $txs;
                $model = match ($type) {
                    'Invoice' => $invoices[(int) $id] ?? null,
                    'Transaction' => $txs[(int) $id] ?? null,
                    'Client' => (object) ['id' => (int) $id],
                };
                if ($model === null) {
                    throw new FOSSBilling\Exception($message);
                }

                return $model;
            }

            public function findOne(string $type, string $where, array $params)
            {
                global $txs;
                foreach ($txs as $tx) {
                    if ($tx->txn_id === $params[':txn'] && $tx->gateway_id === $params[':gateway'] && $tx->id !== $params[':id'] && $tx->status === $params[':status']) {
                        return $tx;
                    }
                }

                return null;
            }

            public function store($model): int
            {
                return $model->id;
            }

            public function trash($model): void
            {
                global $txs;
                unset($txs[$model->id]);
                $this->state->trashed[] = $model->id;
            }
        };
    }

    echo $orm ? "== FOSSBilling main (Doctrine entities)\n" : "== FOSSBilling 0.8 (RedBean models)\n";

    $cfg = [
        'api_key' => 'sk_test',
        'webhook_secret' => 'whsec_x',
        'fee_on' => 'merchant',
        'expires_minutes' => '30',
        'usd_rate' => '',
        'test_mode' => false,
        'auto_redirect' => false,
        'return_url' => 'https://billing.example/invoice/payment-return?status=ok&hash=abc',
        'cancel_url' => 'https://billing.example/invoice/payment-return?status=cancel&hash=abc',
    ];
    $gw = new Payment_Adapter_SnapexPay($cfg);
    $gw->setDi($di);
    $site = Payment_Adapter_SnapexPay::siteTag();

    // ------------------------------------------------------------ adapter contract
    $conf = Payment_Adapter_SnapexPay::getConfig();
    check($conf['supports_one_time_payments'] === true && $conf['supports_subscriptions'] === false, 'getConfig: one-time only');
    check(array_keys($conf['form']) === ['api_key', 'webhook_secret', 'fee_on', 'expires_minutes', 'usd_rate', 'partner_id', 'api_url'], 'getConfig: form fields');
    check($conf['form']['api_key'][0] === 'password' && $conf['form']['api_key'][1]['secret'] === true && $conf['form']['webhook_secret'][1]['secret'] === true, 'getConfig: secrets are password fields');
    check(isset($conf['form']['fee_on'][1]['multiOptions']['merchant'], $conf['form']['fee_on'][1]['multiOptions']['customer']), 'getConfig: fee_on select');
    check($gw instanceof FOSSBilling\InjectionAwareInterface, 'implements InjectionAwareInterface');
    check($gw instanceof FOSSBilling\Payment\JsonWebhookAuthenticatorInterface, 'implements JsonWebhookAuthenticatorInterface');
    check(Payment_Adapter_SnapexPay::requiresManualApproval() === false, 'not a manual-approval gateway');
    check($gw->getDi() === $di, 'DI container kept');

    // ------------------------------------------------------------ amounts
    check($gw->amountUsd(10, 'USD') === '10.00', 'USD amount');
    check((new Payment_Adapter_SnapexPay(array_merge($cfg, ['usd_rate' => '9'])))->amountUsd(10, 'USD') === '10.00', 'USD ignores usd_rate');
    check((new Payment_Adapter_SnapexPay(array_merge($cfg, ['usd_rate' => '1,1'])))->amountUsd(10, 'EUR') === '11.00', 'EUR with usd_rate');
    $currencyRepo->rates = ['EUR' => 1.0, 'USD' => 1.08];
    check($gw->amountUsd(10, 'EUR') === '10.80', 'EUR via FOSSBilling currency rates');
    $currencyRepo->rates = ['EUR' => 1.0];
    check(throws(fn () => $gw->amountUsd(10, 'EUR'), 'USD rate'), 'EUR without any rate is refused');
    check(throws(fn () => (new Payment_Adapter_SnapexPay(array_merge($cfg, ['usd_rate' => 'abc'])))->amountUsd(10, 'EUR')), 'non-numeric usd_rate is refused');
    check(throws(fn () => $gw->amountUsd(0, 'USD')), 'zero total is refused');
    $currencyRepo->rates = ['USD' => 1.0];

    // ------------------------------------------------------------ getHtml / create invoice
    newInvoice(12, 15.0);
    $api = new stdClass();
    $GLOBALS['calls'] = [];
    $html = $gw->getHtml($api, 12, false);
    $call = $GLOBALS['calls'][0];
    $sent = json_decode((string) $call['body'], true);
    $remote1 = $GLOBALS['remote']['inv_1'];
    check($call['method'] === 'POST' && $call['url'] === 'https://snapex.pro/api/v1/merchant/invoices', 'POST /api/v1/merchant/invoices');
    check($call['headers']['Authorization'] === 'Bearer sk_test' && $call['headers']['Content-Type'] === 'application/json', 'Bearer auth + JSON');
    check($sent['amount_usd'] === '15.00' && is_int($sent['expires_in']) && $sent['expires_in'] === 1800, 'amount_usd string, expires_in seconds');
    check($sent['environment'] === 'live' && $sent['fee_on'] === 'merchant', 'live environment when test mode is off');
    check($sent['success_url'] === $cfg['return_url'] && $sent['cancel_url'] === $cfg['cancel_url'], 'success/cancel URLs from FOSSBilling');
    check($sent['description'] === 'FOSSBilling invoice FOSS12', 'description');
    check($sent['external_id'] === $call['headers']['Idempotency-Key'], 'external_id equals Idempotency-Key');
    check((bool) preg_match('/^fossbilling-'.$site.'-12-15\.00USD-15\.00-live-\d+$/', $sent['external_id']), 'external_id format: site, invoice, total, usd, env');
    check(Payment_Adapter_SnapexPay::parseExternalId($sent['external_id'])['invoice_id'] === 12, 'external_id parses back');
    check(str_contains($html, 'href="'.$remote1['pay_url'].'"') && ! str_contains($html, '<script'), 'getHtml links to pay_url, no auto redirect');
    $html2 = $gw->getHtml($api, 12, false);
    check(count($GLOBALS['remote']) === 1 && str_contains($html2, $remote1['pay_url']), 'reload reuses the same SnapEX invoice');
    $auto = new Payment_Adapter_SnapexPay(array_merge($cfg, ['auto_redirect' => true]));
    $auto->setDi($di);
    check(str_contains($auto->getHtml($api, 12, false), 'window.location.href = "'.$remote1['pay_url'].'"'), 'auto_redirect script');
    check(throws(fn () => $gw->getHtml($api, 12, true), 'subscriptions'), 'subscriptions refused');
    $noUrls = new Payment_Adapter_SnapexPay(array_merge($cfg, ['return_url' => '', 'cancel_url' => '', 'test_mode' => '1']));
    $noUrls->setDi($di);
    newInvoice(13, 20.0);
    $noUrls->getHtml($api, 13, false);
    $sent13 = json_decode((string) end($GLOBALS['calls'])['body'], true);
    check(! array_key_exists('success_url', $sent13) && ! array_key_exists('cancel_url', $sent13), 'empty URLs are not sent');
    check($sent13['environment'] === 'test', 'test environment when FOSSBilling test mode is on');
    check(! array_key_exists('partner_ref', $sent13), 'no Partner ID → no partner_ref');
    $ref = new Payment_Adapter_SnapexPay(array_merge($cfg, ['partner_id' => ' int-42 ']));
    $ref->setDi($di);
    newInvoice(14, 25.0);
    $ref->getHtml($api, 14, false);
    check((json_decode((string) end($GLOBALS['calls'])['body'], true)['partner_ref'] ?? null) === 'int-42', 'Partner ID → partner_ref');
    $bad = new Payment_Adapter_SnapexPay(array_merge($cfg, ['api_key' => 'sk_wrong']));
    $bad->setDi($di);
    check(throws(fn () => $bad->getHtml($api, 12, false), 'A valid merchant API key is required.'), 'API error message surfaces');
    $noKey = new Payment_Adapter_SnapexPay(array_merge($cfg, ['api_key' => '']));
    $noKey->setDi($di);
    check(throws(fn () => $noKey->getHtml($api, 12, false), 'API key is missing'), 'missing API key');

    // ------------------------------------------------------------ signatures
    $event = ['id' => 'evt_1', 'type' => 'invoice.paid', 'created_at' => date('c'), 'data' => $remote1];
    [$body, $server] = signed('whsec_x', $event);
    $h = ['Webhook-Timestamp' => $server['HTTP_WEBHOOK_TIMESTAMP'], 'Webhook-Signature' => $server['HTTP_WEBHOOK_SIGNATURE']];
    check(Payment_Adapter_SnapexPay::verifyWebhook($body, 'whsec_x', $h), 'valid signature');
    check(Payment_Adapter_SnapexPay::verifyWebhook($body, 'whsec_x', array_change_key_case($h, CASE_LOWER)), 'header names are case-insensitive');
    check(! Payment_Adapter_SnapexPay::verifyWebhook($body, 'whsec_y', $h), 'wrong secret rejected');
    check(! Payment_Adapter_SnapexPay::verifyWebhook($body.' ', 'whsec_x', $h), 'tampered body rejected');
    check(! Payment_Adapter_SnapexPay::verifyWebhook($body, 'whsec_x', ['Webhook-Timestamp' => $h['Webhook-Timestamp'], 'Webhook-Signature' => 'v1=bad']), 'bad signature rejected');
    check(! Payment_Adapter_SnapexPay::verifyWebhook($body, 'whsec_x', ['Webhook-Signature' => $h['Webhook-Signature']]), 'missing timestamp rejected');
    check(! Payment_Adapter_SnapexPay::verifyWebhook($body, '', $h), 'empty secret rejected');
    [$oldBody, $oldServer] = signed('whsec_x', $event, time() - 3600);
    $oldH = ['Webhook-Timestamp' => $oldServer['HTTP_WEBHOOK_TIMESTAMP'], 'Webhook-Signature' => $oldServer['HTTP_WEBHOOK_SIGNATURE']];
    check(! Payment_Adapter_SnapexPay::verifyWebhook($oldBody, 'whsec_x', $oldH), 'stale timestamp rejected');
    check(Payment_Adapter_SnapexPay::verifyWebhook($oldBody, 'whsec_x', $oldH, time() - 3590), 'timestamp checked against the receive time');
    $ipn = fn (string $b, array $s) => ['source' => 'ipn', 'get' => ['gateway_id' => '1'], 'post' => [], 'server' => $s, 'http_raw_post_data' => $b];
    $gw->authenticateJsonWebhook($ipn($body, $server));
    check(true, 'authenticateJsonWebhook accepts a signed callback');
    check(throws(fn () => $gw->authenticateJsonWebhook($ipn($body, ['HTTP_WEBHOOK_TIMESTAMP' => $server['HTTP_WEBHOOK_TIMESTAMP'], 'HTTP_WEBHOOK_SIGNATURE' => 'v1=x']))), 'authenticateJsonWebhook rejects a forged callback');
    check(throws(fn () => $gw->authenticateJsonWebhook($ipn($oldBody, $oldServer))), 'authenticateJsonWebhook rejects a replay');
    check($gw->getInvoiceId($ipn($body, $server)) === 12, 'getInvoiceId from external_id');

    // ------------------------------------------------------------ settlement
    $GLOBALS['remote']['inv_1']['status'] = 'paid';
    $event['data']['status'] = 'paid';
    [$body, $server] = signed('whsec_x', $event);
    newTx(3);
    check(throws(fn () => $gw->processTransaction($api, 3, $ipn($body, array_merge($server, ['HTTP_WEBHOOK_SIGNATURE' => 'v1=nope'])), 1), 'signature'), 'forged webhook throws');
    check($state->funds === 0.0, 'forged webhook credits nothing');

    $GLOBALS['api_down'] = true;
    check(throws(fn () => $gw->processTransaction($api, 3, $ipn($body, $server), 1), 'HTTP 503'), 'API outage throws (retry later)');
    check($state->funds === 0.0 && $invoices[12]->status === 'unpaid', 'API outage credits nothing');
    $GLOBALS['api_down'] = false;

    $GLOBALS['calls'] = [];
    $gw->processTransaction($api, 3, $ipn($body, $server), 1);
    check($GLOBALS['calls'][0]['method'] === 'GET' && $GLOBALS['calls'][0]['url'] === 'https://snapex.pro/api/v1/merchant/invoices/inv_1', 'status re-read via GET /invoices/{id}');
    check($invoices[12]->status === 'paid' && $state->paid === [12], 'invoice paid with the credited funds');
    check($clientService->calls[0][0] === 15.0 && $clientService->calls[0][2]['rel_id'] === 3, 'client credited invoice total, linked to the transaction');
    $tx3 = $txs[3];
    check(prop($tx3, 'status', 'status') === 'processed' && prop($tx3, 'txnId', 'txn_id') === 'inv_1', 'transaction processed with txn_id = SnapEX invoice id');
    check(prop($tx3, 'amount', 'amount') === '15.00' && prop($tx3, 'currency', 'currency') === 'USD', 'transaction amount and currency');
    check($orm ? $tx3->invoice === $invoices[12] : $tx3->invoice_id === 12, 'transaction linked to the invoice');
    check(count($locks->taken) === 1, 'settlement runs under a named lock');

    // Second delivery of the same event (SnapEX retry): new FOSSBilling transaction, no new money.
    [$body2, $server2] = signed('whsec_x', $event);
    newTx(4);
    $gw->processTransaction($api, 4, $ipn($body2, $server2), 1);
    check($state->funds === 0.0 && count($clientService->calls) === 1, 'double webhook does not double-credit');
    check(in_array(4, $state->trashed, true), 'duplicate transaction removed');
    // Same transaction reprocessed by hand: also a no-op.
    $gw->processTransaction($api, 3, $ipn($body, $server), 1);
    check(count($clientService->calls) === 1, 'reprocessing the booked transaction does not credit again');

    // Informational events never hit the API and are dropped.
    $GLOBALS['calls'] = [];
    [$b, $s] = signed('whsec_x', ['id' => 'e', 'type' => 'invoice.created', 'data' => ['invoice_id' => 'inv_1']]);
    newTx(5);
    $gw->processTransaction($api, 5, $ipn($b, $s), 1);
    check($GLOBALS['calls'] === [] && in_array(5, $state->trashed, true), 'invoice.created ignored');

    // Invoice of another FOSSBilling install on the same SnapEX account.
    $GLOBALS['remote']['inv_other'] = array_merge($remote1, ['invoice_id' => 'inv_other', 'status' => 'paid', 'external_id' => 'fossbilling-00000000-12-15.00USD-15.00-live-1']);
    [$b, $s] = signed('whsec_x', ['id' => 'e', 'type' => 'invoice.paid', 'data' => ['invoice_id' => 'inv_other']]);
    newTx(6);
    $gw->processTransaction($api, 6, $ipn($b, $s), 1);
    check(in_array(6, $state->trashed, true) && count($clientService->calls) === 1, 'other site invoice ignored');

    // A webhook body that lies about the status: the API says open.
    newInvoice(20, 30.0);
    $gw->getHtml($api, 20, false);
    $id20 = array_key_last($GLOBALS['remote']);
    [$b, $s] = signed('whsec_x', ['id' => 'e', 'type' => 'invoice.paid', 'data' => array_merge($GLOBALS['remote'][$id20], ['status' => 'paid'])]);
    newTx(7);
    $gw->processTransaction($api, 7, $ipn($b, $s), 1);
    check($invoices[20]->status === 'unpaid' && count($clientService->calls) === 1, 'webhook body alone never pays');

    $GLOBALS['remote'][$id20]['status'] = 'partially_paid';
    newTx(8);
    check(throws(fn () => $gw->processTransaction($api, 8, $ipn($b, $s), 1), 'partially paid'), 'partially_paid flagged for review');

    $GLOBALS['remote'][$id20]['status'] = 'paid';
    $GLOBALS['remote'][$id20]['amount_usd'] = '1.00';
    newTx(9);
    check(throws(fn () => $gw->processTransaction($api, 9, $ipn($b, $s), 1), 'amount does not match'), 'remote amount mismatch refused');
    $GLOBALS['remote'][$id20]['amount_usd'] = '30.00';

    $invoices[20]->total = 45.0;
    newTx(10);
    check(throws(fn () => $gw->processTransaction($api, 10, $ipn($b, $s), 1), 'total changed'), 'invoice total changed after checkout refused');
    $invoices[20]->total = 30.0;

    $GLOBALS['remote'][$id20]['environment'] = 'test';
    newTx(11);
    check(throws(fn () => $gw->processTransaction($api, 11, $ipn($b, $s), 1)), 'test invoice is not applied in live mode');
    $GLOBALS['remote'][$id20]['environment'] = 'live';

    $invoices[20]->status = 'canceled';
    newTx(14);
    check(throws(fn () => $gw->processTransaction($api, 14, $ipn($b, $s), 1), 'canceled'), 'canceled invoice not paid');
    $invoices[20]->status = 'unpaid';
    check(count($clientService->calls) === 1, 'no credit from any refused webhook');

    // Unissued (draft) invoice is issued before credits are applied.
    if ($orm) {
        $invoices[20]->issued = false;
    } else {
        $invoices[20]->approved = 0;
    }
    newTx(15);
    $gw->processTransaction($api, 15, $ipn($b, $s), 1);
    check(in_array(20, $state->approved, true) && $invoices[20]->status === 'paid', 'draft invoice issued, then paid');

    // FOSSBilling invoice already paid by another SnapEX invoice: flagged, not credited again.
    $GLOBALS['remote']['inv_dup'] = array_merge($GLOBALS['remote'][$id20], ['invoice_id' => 'inv_dup', 'external_id' => preg_replace('/-\d+$/', '-1', $GLOBALS['remote'][$id20]['external_id'])]);
    [$b, $s] = signed('whsec_x', ['id' => 'e', 'type' => 'invoice.paid', 'data' => ['invoice_id' => 'inv_dup']]);
    newTx(16);
    $creditsBefore = count($clientService->calls);
    check(throws(fn () => $gw->processTransaction($api, 16, $ipn($b, $s), 1), 'already paid'), 'second SnapEX payment for a paid invoice flagged');
    check(count($clientService->calls) === $creditsBefore, 'second SnapEX payment not credited automatically');

    // Deposit (add funds) invoice.
    newInvoice(30, 50.0);
    $invoices[30]->deposit = true;
    $gw->getHtml($api, 30, false);
    $id30 = array_key_last($GLOBALS['remote']);
    $GLOBALS['remote'][$id30]['status'] = 'paid';
    [$b, $s] = signed('whsec_x', ['id' => 'e', 'type' => 'invoice.paid', 'data' => ['invoice_id' => $id30]]);
    newTx(17);
    $gw->processTransaction($api, 17, $ipn($b, $s), 1);
    check(in_array(30, $state->marked, true) && end($clientService->calls)[0] === 50.0, 'deposit invoice: funds added and invoice marked paid');

    // Cron/manual retry long after receipt still verifies (timestamp vs receive time).
    newInvoice(40, 5.0);
    $gw->getHtml($api, 40, false);
    $id40 = array_key_last($GLOBALS['remote']);
    $GLOBALS['remote'][$id40]['status'] = 'paid';
    $received = time() - 7200;
    [$b, $s] = signed('whsec_x', ['id' => 'e', 'type' => 'invoice.paid', 'data' => ['invoice_id' => $id40]], $received);
    newTx(18, $received);
    $gw->processTransaction($api, 18, $ipn($b, $s), 1);
    check($invoices[40]->status === 'paid', 'late retry of a stored webhook settles');

    echo $failures === 0 ? "all ok\n" : "$failures failed\n";
    if ($failures !== 0) {
        exit(1);
    }
    if (! $orm) {
        // Same suite against Doctrine-style entities (FOSSBilling main).
        putenv('SNAPEX_ORM=1');
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__), $code);
        exit($code);
    }
}
