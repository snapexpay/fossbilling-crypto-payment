# Криптоплатежи для FOSSBilling: USDT TRC20 через SnapEX Pay

[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![FOSSBilling](https://img.shields.io/badge/FOSSBilling-0.8%2B-blue.svg)](https://github.com/FOSSBilling/FOSSBilling)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)](https://www.php.net/)
[![USDT](https://img.shields.io/badge/USDT-TRC20%20%7C%20ERC20%20%7C%20BEP20-26a17b.svg)](https://pay.snapex.pro)

[English version](README.md)

Платёжный адаптер для [FOSSBilling](https://github.com/FOSSBilling/FOSSBilling): клиенты оплачивают счета в **USDT (TRC20, ERC20, BEP20)** и других монетах через [SnapEX Pay](https://snapex.pro). Когда клиент выбирает SnapEX Pay в счёте, адаптер создаёт инвойс SnapEX Pay и отправляет клиента на страницу оплаты. После подтверждения платежа подписанный вебхук приходит на IPN URL FOSSBilling: баланс клиента пополняется, счёт FOSSBilling отмечается оплаченным. Поднимать кошелёк, ноду или следить за блокчейном не нужно. Деньги приходят на мерчант-баланс SnapEX Pay.

> **Подключаетесь к SnapEX Pay?** Мерчант-аккаунт, тарифы и все готовые модули — на **[pay.snapex.pro](https://pay.snapex.pro)**.

## Возможности

- Адаптер FOSSBilling в одном файле (`Payment_Adapter_SnapexPay`), появляется в **System → Payment gateways**.
- Оплата на странице SnapEX Pay: USDT в сетях TRC20, ERC20 и BEP20 и другие монеты, доступные на странице оплаты.
- Вебхуки проверяются по HMAC-SHA256 (`Webhook-Signature`) со сравнением за постоянное время. Если метка времени расходится больше чем на 5 минут, вебхук отклоняется. В свежих сборках FOSSBilling подпись проверяется ещё до сохранения коллбэка.
- Телу вебхука адаптер не доверяет. Прежде чем что-то зачислить, он перечитывает инвойс через API SnapEX своим ключом.
- Платёж проводится один раз. Повторный вебхук ничего не зачисляет второй раз, а сама проводка идёт под блокировкой в базе.
- Зачисляется сумма счёта, выставленная клиенту, в валюте счёта. Если сумма счёта изменилась после перехода к оплате, автоматически ничего не зачисляется.
- Несколько сайтов FOSSBilling могут работать с одним аккаунтом SnapEX: каждый проводит только свои счета.
- Переключатель FOSSBilling **Enable Test Mode** создаёт тестовые инвойсы SnapEX, их можно подтвердить без реальных денег. В боевом режиме тестовые оплаты не проводятся.
- Счета не в USD пересчитываются по фиксированному курсу или по курсам валют, уже настроенным в FOSSBilling.
- Работает с FOSSBilling 0.8.x и с текущими сборками из разработки.

## Как это работает

1. Клиент открывает неоплаченный счёт и выбирает **SnapexPay**.
2. Адаптер вызывает `POST /api/v1/merchant/invoices` с суммой в USD, заголовком `Idempotency-Key` и `external_id`, по которому видны сайт, номер счёта и выставленная сумма. Клиент видит кнопку *Pay with crypto*, ведущую на полученный `pay_url`. Если FOSSBilling просит автопереход, клиента перенаправляет сразу.
3. Клиент платит на странице SnapEX Pay и возвращается к счёту.
4. SnapEX Pay отправляет `invoice.paid` на IPN URL FOSSBilling: `https://<ваш-fossbilling>/ipn.php?gateway_id=<id>`.
5. FOSSBilling сохраняет коллбэк как транзакцию и передаёт её адаптеру. Адаптер проверяет подпись, запрашивает `GET /api/v1/merchant/invoices/{id}` и идёт дальше только при статусе `paid`. Сумма счёта зачисляется на баланс клиента, и счёт оплачивается с баланса. Счета на пополнение баланса (deposit) сразу отмечаются оплаченными. ID инвойса SnapEX (`inv_…`) становится ID транзакции.

Если клиент обновляет страницу счёта, первую половину окна оплаты используется тот же инвойс SnapEX. Позже, или если сумма изменилась, создаётся новый.

## Требования

- FOSSBilling 0.8 или новее
- PHP 8.0+ с расширением cURL (самому FOSSBilling нужна более новая версия PHP)
- Мерчант-аккаунт [SnapEX Pay](https://pay.snapex.pro)
- FOSSBilling, доступный по HTTPS, чтобы до него доходили вебхуки

## Установка

1. Скопируйте [`SnapexPay.php`](SnapexPay.php) в корень FOSSBilling (папку, где лежат `ipn.php` и `config.php`), чтобы получился путь:

   ```
   library/Payment/Adapter/SnapexPay/SnapexPay.php
   ```

   И папка, и файл называются `SnapexPay`, регистр важен. Например:

   ```bash
   cd /var/www/fossbilling          # в официальном Docker-образе /var/www/html
   mkdir -p library/Payment/Adapter/SnapexPay
   curl -fsSLO https://pay.snapex.pro/downloads/fossbilling/snapexpay-fossbilling-1.2.0.zip
   unzip -p snapexpay-fossbilling-1.2.0.zip snapexpay-fossbilling-1.2.0/SnapexPay.php > library/Payment/Adapter/SnapexPay/SnapexPay.php
   chown -R www-data:www-data library/Payment/Adapter/SnapexPay   # пользователь веб-сервера
   ```

2. В админке откройте **System → Payment gateways → New payment gateway** и установите **SnapexPay**.
3. Откройте шлюз, заполните настройки из таблицы ниже, включите **Enabled** и **Allow One Time Payments** и сохраните.

## Настройки

| Поле (ключ) | По умолчанию | Описание |
|---|---|---|
| API key (`api_key`) | — | Обязательно. Кабинет SnapEX Pay → API-ключи, скоупы `invoices.read` и `invoices.write`. |
| Webhook secret (`webhook_secret`) | — | Обязательно. Секрет `whsec_…`, который показывается при создании вебхука. Вебхуки без подписи или с неверной подписью отклоняются. |
| Network fee paid by (`fee_on`) | `merchant` | `merchant`: клиент платит сумму счёта. `customer`: сетевая комиссия добавляется сверху. |
| Payment window, minutes (`expires_minutes`) | `60` | 1–1440. Сколько минут инвойс SnapEX доступен для оплаты. |
| USD per 1 unit of invoice currency (`usd_rate`) | пусто | Для счетов в USD не используется. Для других валют укажите фиксированный курс (EUR по 1,08 USD — `1.08`) или оставьте пустым, тогда возьмутся курсы FOSSBilling. Для этого USD должен быть в **System → Currencies**. Инвойсы SnapEX всегда в USD. |
| Partner ID (`partner_id`) | пусто | Необязательно. Partner ID интегратора, который настроил модуль (реферальная программа SnapEX Pay). Оставьте пустым, если ставите сами. |
| API URL (`api_url`) | `https://snapex.pro/api/v1/merchant` | Оставьте пустым. |
| Enable Test Mode (переключатель FOSSBilling) | выкл. | Вкл.: тестовые инвойсы SnapEX, подтверждаются без денег. Выкл.: боевые. В боевом режиме тестовые оплаты не проводятся. |

## Настройка вебхука (callback URL)

На странице шлюза в FOSSBilling есть поле **IPN Callback URL**, например:

```
https://billing.example.com/ipn.php?gateway_id=3
```

В кабинете SnapEX Pay добавьте этот адрес как вебхук, подпишите его на `invoice.paid` (полезны также `invoice.partially_paid` и `invoice.extra_payment`: они появятся как транзакции для ручной проверки) и вставьте секрет `whsec_…` в настройки шлюза. Остальные события принимаются и тихо отбрасываются.

## Проверка

- **Без денег:** включите **Enable Test Mode**, оплатите счёт и подтвердите тестовый платёж на странице SnapEX Pay. Счёт должен стать *Paid*, а в транзакциях появится одна проведённая с ID `inv_…`.
- **Юнит-тесты:** `php tests/run.php` (PHP 8.1+, без зависимостей). Тесты проходят контракт адаптера на моделях FOSSBilling 0.8 и на текущих Doctrine-сущностях, проверку подписи и окна повтора, формат запроса к API, повторные вебхуки, разделение test/live, изоляцию сайтов, проверку сумм и счета на пополнение. API SnapEX в тестах заменён заглушкой.

## FAQ и решение проблем

**SnapexPay нет в списке «New payment gateway».**
Проверьте путь `library/Payment/Adapter/SnapexPay/SnapexPay.php` (регистр важен) относительно папки с `ipn.php`, и что файл доступен веб-серверу на чтение.

**Клиент заплатил, а счёт не оплачен.**
Откройте в FOSSBilling **Invoices → Transactions**. Если транзакции нет, проверьте доставку вебхуков в кабинете SnapEX Pay и что в URL есть `?gateway_id=`. *SnapEX Pay webhook signature is invalid* значит, что секрет не совпадает или часы сервера расходятся больше чем на 5 минут. *was paid while the gateway is not in test mode* значит, что тестовый инвойс оплачен на боевом шлюзе. Устраните причину и проведите транзакцию повторно из админки.

**В транзакции «partially paid», «already paid» или «total changed».**
Такие платежи автоматически не проводятся. Проверьте платёж в кабинете SnapEX и пополните баланс или отметьте счёт оплаченным вручную. *Already paid* значит, что клиент оплатил второй инвойс SnapEX за уже закрытый счёт.

**«Amount is below the … USD minimum».**
В SnapEX Pay есть минимальная сумма инвойса. Ошибка приходит прямо из API.

**Я сменил адрес FOSSBilling.**
Инвойсы каждого сайта помечаются хешем его адреса (без `http://`/`https://`). Инвойсы SnapEX, созданные до смены, автоматически не проведутся, их нужно провести вручную.

**Обновление с 1.0.0.**
Поля *Environment* больше нет, вместо него переключатель FOSSBilling **Enable Test Mode**. Инвойсы SnapEX, открытые в 1.0.0 (`external_id` вида `fossbilling-<id>`), версия 1.1 не проводит, оставшиеся открытыми проведите вручную.

**Нужен свой кошелёк или нода?**
Нет. SnapEX Pay сам следит за блокчейном и зачисляет деньги на мерчант-баланс.

## Другие интеграции SnapEX Pay

Все модули, тарифы и регистрация: **[pay.snapex.pro](https://pay.snapex.pro)**.

- [Marzban](https://pay.snapex.pro/integrations/marzban)
- [3x-ui](https://pay.snapex.pro/integrations/3x-ui)
- [Xboard / V2Board](https://pay.snapex.pro/integrations/xboard)
- [Remnawave](https://pay.snapex.pro/integrations/remnawave)
- [Telegram VPN-бот](https://pay.snapex.pro/integrations/telegram-vpn-bot)
- [Paymenter](https://pay.snapex.pro/integrations/paymenter)
- [n8n](https://pay.snapex.pro/integrations/n8n)

## Поддержка

- Сайт, кабинет и документация API: https://snapex.pro
- Ошибки и предложения — пишите в чат на [pay.snapex.pro](https://pay.snapex.pro).

## Лицензия

[MIT](LICENSE)
