# uengage/logger

Structured observability logging for uEngage platform services. Write logs to a local file (for server-side services where a cloud agent ships them) or POST them over HTTP (for environments without a cloud agent).

---

## Table of Contents

- [uengage/logger](#uengagelogger)
  - [Table of Contents](#table-of-contents)
  - [Overview](#overview)
  - [Requirements](#requirements)
  - [Installation](#installation)
  - [Quick Start](#quick-start)
    - [Backend service (File transporter)](#backend-service-file-transporter)
    - [Frontend / mobile (HTTP transporter)](#frontend--mobile-http-transporter)
  - [Log Schema](#log-schema)
  - [Initialization](#initialization)
    - [Config Reference](#config-reference)
  - [Transporters](#transporters)
    - [File Transporter](#file-transporter)
    - [HTTP Transporter](#http-transporter)
  - [Log Methods](#log-methods)
    - [Method Signature](#method-signature)
    - [Log Options Reference](#log-options-reference)
  - [Level Filtering](#level-filtering)
  - [Graceful Shutdown](#graceful-shutdown)
  - [CodeIgniter 2 Integration](#codeigniter-2-integration)
  - [PHP-Specific Notes](#php-specific-notes)
  - [Examples](#examples)
    - [Business event — order placed](#business-event--order-placed)
    - [Engineering error — payment gateway timeout](#engineering-error--payment-gateway-timeout)
    - [Warning — rate limit approaching](#warning--rate-limit-approaching)
    - [Debug — database query](#debug--database-query)
  - [Architecture](#architecture)
  - [Running Tests](#running-tests)

---

## Overview

`uengage/logger` provides a single `Logger` class with four log-level methods (`info`, `error`, `debug`, `warn`). On initialization you choose a **transport** — the mechanism used to deliver each log entry:

| Transport type | How it works                                                   | Best for                                                                         |
| -------------- | -------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| `file`         | Appends NDJSON lines to `{basePath}/application/{product}.log` | EC2/server services — CloudWatch Agent, Datadog Agent, or Fluentd ships the file |
| `http`         | POSTs JSON to an HTTP endpoint                                 | Environments without a cloud agent                                               |

The log schema is identical to the Node.js `@uengage/logger` package — both packages produce the same JSON structure, making cross-service log analysis uniform.

---

## Requirements

- PHP >= 5.6.0
- `ext-curl` (standard on virtually all hosting environments)
- Composer

---

## Installation

```bash
composer require uengage/logger
```

---

## Quick Start

### Backend service (File transporter)

```php
<?php
use Uengage\Logger\Logger;

$logger = new Logger(array(
    'product'     => 'edge',
    'service'     => 'ordering',
    'component'   => 'api-server',
    'version'     => '1.4.2',
    'environment' => 'production',
    'source'      => 'server',
    'transport'   => array('type' => 'file'),
    // transport.config is optional — basePath defaults to /var/log/uengage/
    // log file created at: /var/log/uengage/application/edge.log
));

$logger->warn('Order placed', array(
    'context' => array('order_id' => 'ord_8x2k', 'amount' => 450.00),
    'tenant'  => array('business_id' => '456', 'parent_id' => '123'),
    'user_id' => 'usr_7x9k2m',
));
```

### Frontend / mobile (HTTP transporter)

```php
<?php
use Uengage\Logger\Logger;

$logger = new Logger(array(
    'product'     => 'edge',
    'service'     => 'ordering',
    'component'   => 'mobile-app',
    'version'     => '3.0.0',
    'environment' => 'production',
    'source'      => 'client',
    'transport'   => array('type' => 'http', 'config' => array(
        // endpoint defaults to https://observability.platform.uengage.in/logs/ingest
        'apiKey'    => 'your-api-key-here',  // optional — omit to send without auth
        'batchSize' => 5,                    // default: 5
    )),
));

$logger->error('Payment webhook timeout', array(
    'error'   => array(
        'code'     => 'PAYMENT_WEBHOOK_TIMEOUT',
        'category' => 'engineering',
        'stack'    => 'TimeoutError: ...',
        'upstream' => 'razorpay',
    ),
    'context' => array('order_id' => 'ord_8x2k', 'amount' => 450.00, 'latency_ms' => 30012),
    'tenant'  => array('business_id' => '456', 'parent_id' => '123'),
    'user_id' => 'usr_7x9k2m',
));
```

---

## Log Schema

Every log entry — whether written to a file or POSTed over HTTP — has the following shape:

```json
{
  "timestamp": "2026-04-07T14:32:01.847Z",
  "level": "ERROR",
  "product": "edge",
  "service": "ordering",
  "component": "mobile-app",
  "version": "1.4.2",
  "environment": "production",
  "trace_id": "abc-123-def-456",
  "tenant": {
    "business_id": "456",
    "parent_id": "123"
  },
  "source": "server",
  "message": "Payment webhook timeout",

  "user_id": "usr_7x9k2m",

  "error": {
    "code": "PAYMENT_WEBHOOK_TIMEOUT",
    "category": "engineering",
    "stack": "TimeoutError: ...",
    "upstream": "razorpay"
  },

  "context": {
    "order_id": "ord_8x2k",
    "amount": 450.0,
    "latency_ms": 30012
  }
}
```

**Field rules:**

- `timestamp`, `level`, `product`, `service`, `component`, `version`, `environment`, `trace_id`, `tenant`, `source`, `message` — always present
- `user_id` — omitted when not passed
- `error` — omitted when not passed; include it for `error` and `warn` events
- `context` — omitted when not passed

---

## Initialization

```php
$logger = new Logger($config);
```

The constructor validates the config synchronously and throws `\InvalidArgumentException` immediately if anything required is missing or invalid. If `new Logger(...)` completes without throwing, the instance is ready.

### Config Reference

```php
array(
    // ── Required ────────────────────────────────────────────────────────────

    'product'     => string,   // e.g. 'edge'
    'service'     => string,   // e.g. 'ordering'
    'component'   => string,   // e.g. 'mobile-app'
    'version'     => string,   // e.g. '1.4.2'
    'environment' => string,   // e.g. 'production' | 'staging' | 'development'
    'source'      => string,   // 'server' | 'client'
    'transport'   => array(
        'type'   => string,    // Required. 'file' | 'http'
        'config' => array(),   // Optional. All fields have defaults — see sections below.
    ),

    // ── Optional ────────────────────────────────────────────────────────────

    'minLevel' => string,      // 'debug' | 'info' | 'warn' | 'error'. Default: 'warn'
)
```

---

## Transporters

### File Transporter

Appends one JSON object per line (NDJSON format) to a log file. The **filename is derived automatically** from your `product` config — you only provide the base directory. All services for the same product on a host share one file (writes use `FILE_APPEND | LOCK_EX`, so concurrent service processes are safe).

```
{basePath}/application/{product}.log
```

```php
'transport' => array(
    'type'   => 'file',
    'config' => array(
        'basePath'         => '/var/log/uengage',  // Optional. Default: /var/log/uengage/
        'maxFileSizeBytes' => 10 * 1024 * 1024,    // Optional. Default: 10 MB
        'maxRotations'     => 5,                   // Optional. Default: 5
    ),
),
```

The directory is **created automatically** if it does not exist. A `\RuntimeException` is thrown only if the directory cannot be created (e.g. permission denied).

**File rotation**

When the file reaches `maxFileSizeBytes`, it is rotated before the next write:

```
edge-ordering.log     → edge-ordering.log.1   (previous live file)
edge-ordering.log.1   → edge-ordering.log.2
...
edge-ordering.log.5   → deleted               (oldest, falls off)
```

Configure your cloud agent (CloudWatch Agent, Datadog Agent, Fluentd) to watch `edge-ordering.log*` so it picks up rotated files before they are removed.

**Concurrent writes**

File writes use `LOCK_EX` via `file_put_contents`. This prevents torn writes when multiple PHP-FPM workers log to the same file simultaneously.

---

### HTTP Transporter

POSTs log entries to an HTTP endpoint using cURL.

```php
'transport' => array(
    'type'   => 'http',
    'config' => array(
        'endpoint'        => 'https://observability.platform.uengage.in/logs/ingest',
        //                   ^ Optional. This is the default.
        'apiKey'          => 'your-api-key',   // Optional. Sent as x-api-key header. Omit to send without auth.
        'batchSize'       => 5,                // Optional. Default: 5
        'flushIntervalMs' => 5000,             // Optional. Accepted for config parity; no-op in PHP (see PHP-Specific Notes)
        'timeoutMs'       => 5000,             // Optional. Default: 5000 ms
    ),
),
```

**Immediate mode**

Set `batchSize: 1`. Each log call fires one POST immediately. The request body is a plain JSON object.

**Batch mode (default)**

With `batchSize > 1` (default is 5), entries queue up and flush when the batch is full. The request body becomes a JSON array. Call `$logger->destroy()` before script exit to flush any remaining queued entries.

**Request headers**

```
Content-Type: application/json
x-api-key: <your apiKey>     (only when apiKey is set)
```

**Silent failures**

cURL errors, non-2xx responses, and timeouts are written to `error_log()` with the prefix `[uengage-logger][http]`. The host application is never interrupted.

---

## Log Methods

### Method Signature

All four methods share the same signature:

```php
$logger->info ($message, $options = array())
$logger->error($message, $options = array())
$logger->debug($message, $options = array())
$logger->warn ($message, $options = array())
```

| Parameter  | Type     | Required | Description                                         |
| ---------- | -------- | -------- | --------------------------------------------------- |
| `$message` | `string` | Yes      | Human-readable description of the event             |
| `$options` | `array`  | No       | See [Log Options Reference](#log-options-reference) |

### Log Options Reference

```php
array(
    'trace_id' => string,
    // UUID for distributed tracing across services.
    // Auto-generated if not provided.

    'user_id' => string,
    // Authenticated user identifier.
    // Field is omitted from the log entry when not provided.

    'tenant' => array(
        'business_id' => string,
        'parent_id'   => string,
    ),

    'error' => array(
        'code'      => string,   // Machine-readable error code, e.g. 'PAYMENT_WEBHOOK_TIMEOUT'
        'category'  => string,   // 'business' | 'engineering'
        'stack'     => string,   // Stack trace string
        'upstream'  => string,   // External service that caused the error, e.g. 'razorpay'
    ),
    // Include for error and warn events. Field is omitted when not provided.

    'context' => array(...),
    // Arbitrary key-value pairs for this specific event.
    // Deep-cloned at log time — mutating the array after the call has no effect.
    // Field is omitted when not provided.
)
```

---

## Level Filtering

Use `minLevel` to suppress low-priority logs in production without changing call sites:

```php
$logger = new Logger(array(
    ...
    'minLevel' => 'warn',  // DEBUG and INFO calls are silently dropped (this is the default)
));
```

Level hierarchy (highest → lowest):

```
error  →  warn  →  info  →  debug
```

| minLevel               | DEBUG | INFO | WARN | ERROR |
| ---------------------- | :---: | :--: | :--: | :---: |
| `'warn'` **(default)** |   —   |  —   |  ✓   |   ✓   |
| `'debug'`              |   ✓   |  ✓   |  ✓   |   ✓   |
| `'info'`               |   —   |  ✓   |  ✓   |   ✓   |
| `'error'`              |   —   |  —   |  —   |   ✓   |

---

## Graceful Shutdown

### File transporter

No action needed. Writes are synchronous and complete inline on each call.

### HTTP transporter (immediate mode, batchSize=1)

Each log call fires a cURL request synchronously. No shutdown action needed.

### HTTP transporter (batch mode, default batchSize=5)

Always call `$logger->destroy()` before script exit — it flushes the remaining queue so entries are not silently dropped.

```php
register_shutdown_function(function () use ($logger) {
    $logger->destroy();
});
```

---

## CodeIgniter 2 Integration

CI2 does not load Composer's autoloader by default. Add one of the following:

**Option A — in `application/config/config.php`** (recommended):

```php
<?php
// At the top of config.php, before CI sets up anything:
require_once APPPATH . '../vendor/autoload.php';
```

**Option B — in `index.php`** (before the CI bootstrap):

```php
require_once 'vendor/autoload.php';
```

Then use the logger in any Controller, Model, or Library:

```php
<?php
use Uengage\Logger\Logger;

class Order_model extends CI_Model
{
    private $logger;

    public function __construct()
    {
        parent::__construct();
        $this->logger = new Logger(array(
            'product'     => 'edge',
            'service'     => 'ordering',
            'component'   => 'order-model',
            'version'     => '1.0.0',
            'environment' => ENVIRONMENT,  // CI2 constant: 'development' | 'testing' | 'production'
            'source'      => 'server',
            'transport'   => array('type' => 'file', 'config' => array(
                'basePath' => '/var/log/uengage',
            )),
        ));
    }

    public function place_order($data)
    {
        $this->logger->warn('Order placed', array(
            'tenant'  => array('business_id' => $data['business_id'], 'parent_id' => $data['parent_id']),
            'user_id' => $data['user_id'],
            'context' => array('order_id' => $data['order_id'], 'amount' => $data['amount']),
        ));
    }
}
```

**Flushing HTTP batch mode in CI2**

Register `destroy()` via a `post_system` hook so the queue flushes before CI2 exits:

```php
// application/config/config.php
$config['enable_hooks'] = TRUE;

// application/config/hooks.php
$hook['post_system'][] = array(
    'function' => array($logger, 'destroy'),
    'filename' => '',
    'filepath' => '',
);
```

Or use `register_shutdown_function` in your base controller:

```php
register_shutdown_function(array($this->logger, 'destroy'));
```

---

## PHP-Specific Notes

| Behaviour              | PHP implementation                                              | Note                                                                                    |
| ---------------------- | --------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| Non-blocking writes    | Synchronous — writes complete inline                            | PHP has no event loop. Acceptable for request-lifecycle PHP                             |
| `flushIntervalMs`      | Accepted in config but no-op                                    | Timer-based batch flush is not possible in PHP; use `destroy()` instead                 |
| UUID generation        | `openssl_random_pseudo_bytes` (preferred) or `mt_rand` fallback | Uses openssl when available for cryptographic quality                                   |
| Deep clone             | `json_decode(json_encode($val), true)`                          | Equivalent to JS `structuredClone()`                                                    |
| Timestamp              | `gmdate()` + `microtime(true)` ms                               | Produces `Z` suffix matching ISO 8601 / Node output                                     |
| Error output           | `error_log()`                                                   | Writes to PHP error log, visible in `/var/log/php_errors.log` or Apache/Nginx error log |
| Concurrent file writes | `file_put_contents(..., FILE_APPEND \| LOCK_EX)`                | Prevents torn writes from concurrent FPM workers                                        |

---

## Examples

### Business event — order placed

```php
$logger->warn('Order placed', array(
    'trace_id' => isset($_SERVER['HTTP_X_TRACE_ID']) ? $_SERVER['HTTP_X_TRACE_ID'] : null,
    'user_id'  => $user->id,
    'tenant'   => array('business_id' => '456', 'parent_id' => '123'),
    'context'  => array('order_id' => 'ord_8x2k', 'amount' => 450.00, 'items' => 3),
));
```

```json
{
  "timestamp": "2026-04-07T14:30:00.000Z",
  "level": "WARN",
  "product": "edge",
  "service": "ordering",
  "component": "api-server",
  "version": "1.4.2",
  "environment": "production",
  "trace_id": "abc-123-def-456",
  "tenant": { "business_id": "456", "parent_id": "123" },
  "source": "server",
  "message": "Order placed",
  "user_id": "usr_7x9k2m",
  "context": { "order_id": "ord_8x2k", "amount": 450.0, "items": 3 }
}
```

### Engineering error — payment gateway timeout

```php
try {
    $razorpay->capturePayment($payload);
} catch (Exception $e) {
    $logger->error('Payment webhook timeout', array(
        'trace_id' => isset($_SERVER['HTTP_X_TRACE_ID']) ? $_SERVER['HTTP_X_TRACE_ID'] : null,
        'user_id'  => $user->id,
        'tenant'   => array('business_id' => '456', 'parent_id' => '123'),
        'error'    => array(
            'code'     => 'PAYMENT_WEBHOOK_TIMEOUT',
            'category' => 'engineering',
            'stack'    => $e->getTraceAsString(),
            'upstream' => 'razorpay',
        ),
        'context'  => array('order_id' => 'ord_8x2k', 'amount' => 450.00, 'latency_ms' => 30012),
    ));
}
```

### Warning — rate limit approaching

```php
$logger->warn('Rate limit approaching', array(
    'tenant'  => array('business_id' => '456', 'parent_id' => '123'),
    'error'   => array(
        'code'     => 'RATE_LIMIT_NEAR_THRESHOLD',
        'category' => 'engineering',
    ),
    'context' => array(
        'endpoint'           => '/v1/orders',
        'requests_remaining' => 12,
        'window_resets_at'   => '2026-04-07T15:00:00Z',
    ),
));
```

### Debug — database query

```php
$logger->debug('DB query executed', array(
    'context' => array('table' => 'orders', 'duration_ms' => 45, 'rows_returned' => 1),
));
```

> **Note:** `debug` entries are dropped when using the default `minLevel: 'warn'`. Set `'minLevel' => 'debug'` to see them.

---

## Architecture

```
Logger
  ├── _validateConfig()     validates required fields and transport config
  ├── _log()                builds entry, applies minLevel gate
  │     ├── strtoupper($level)
  │     ├── _generateUuid()          auto trace_id when not supplied
  │     └── json_decode(json_encode(...), true)   deep-clones context/error/tenant
  └── _transporter->send($entry)
        ├── FileTransporter
        │     ├── path: {basePath}/application/{product}.log   (derived at init, dir auto-created)
        │     └── file_put_contents(..., FILE_APPEND | LOCK_EX)
        │           └── _rotate() when file exceeds maxFileSizeBytes
        └── HttpTransporter
              ├── immediate (batchSize=1): _post([$entry])  one cURL POST per call
              └── batching (default batchSize=5): queue → _flush()
                    triggered by batchSize threshold or destroy()
```

**Error contract:** every transporter catches all internal errors and writes to `error_log()`. A logging failure never throws to the caller.

**Adding a new transporter:** extend `BaseTransporter` in `src/Transporters/`, implement `send(array $entry)` and optionally `destroy()`, then add a branch in `Logger::_createTransporter()`.

---

## Running Tests

```bash
composer install
./vendor/bin/phpunit
```

Expected output:

```
PHPUnit 5.7.x by Sebastian Bergmann and contributors.

.................                                                 17 / 17 (100%)

Time: Xs, Memory: XMb

OK (17 tests, X assertions)
```
