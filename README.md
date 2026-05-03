# uengage.io/php-logger

Structured observability logging for uEngage platform services. Write logs to a local file, POST them over HTTP, or emit to stdout - same JSON schema regardless of transport.

---

## Table of Contents

- [uengage.io/php-logger](#uengageiophp-logger)
  - [Table of Contents](#table-of-contents)
  - [Overview](#overview)
  - [Requirements](#requirements)
  - [Installation](#installation)
  - [Quick Start](#quick-start)
    - [File transporter (server / EC2)](#file-transporter-server--ec2)
    - [HTTP transporter (no cloud agent)](#http-transporter-no-cloud-agent)
    - [Stdout transporter (Lambda / Docker)](#stdout-transporter-lambda--docker)
  - [Log Schema](#log-schema)
  - [Initialization](#initialization)
    - [Config Reference](#config-reference)
  - [Transporters](#transporters)
    - [File Transporter](#file-transporter)
    - [HTTP Transporter](#http-transporter)
    - [Stdout Transporter](#stdout-transporter)
  - [Log Methods](#log-methods)
    - [Method Signature](#method-signature)
    - [Log Options Reference](#log-options-reference)
  - [Level Filtering](#level-filtering)
  - [Graceful Shutdown](#graceful-shutdown)
  - [CodeIgniter 2 Integration](#codeigniter-2-integration)
    - [Prerequisite - load Composer autoloader](#prerequisite--load-composer-autoloader)
    - [Logger_service library](#logger_service-library)
    - [Flushing HTTP batch mode in CI2](#flushing-http-batch-mode-in-ci2)
  - [CodeIgniter 4 Integration](#codeigniter-4-integration)
  - [PHP-Specific Notes](#php-specific-notes)
  - [Examples](#examples)
    - [Business event - order placed](#business-event--order-placed)
    - [Engineering error - payment gateway timeout](#engineering-error--payment-gateway-timeout)
    - [Warning - rate limit approaching](#warning--rate-limit-approaching)
    - [Debug - database query](#debug--database-query)
  - [Architecture](#architecture)
  - [Running Tests](#running-tests)

---

## Overview

`uengage/logger` provides a single `Logger` class with four log-level methods (`info`, `error`, `debug`, `warn`). On initialization you choose a **transport**:

| Transport | How it works                                                   | Best for                                                                |
| --------- | -------------------------------------------------------------- | ----------------------------------------------------------------------- |
| `file`    | Appends NDJSON lines to `{basePath}/application/{product}.log` | EC2/server - CloudWatch Agent, Datadog Agent, or Fluentd ships the file |
| `http`    | POSTs JSON to an HTTP endpoint                                 | Environments without a cloud agent                                      |
| `stdout`  | Writes one NDJSON line per entry to `php://stdout`             | Lambda (Bref / custom runtime), Docker                                  |

The log schema is identical to the Node.js `@uengage/logger` package - uniform cross-service log analysis.

---

## Requirements

- PHP >= 7.1
- `ext-curl` (required for HTTP transporter; standard on virtually all hosting environments)
- Composer

---

## Installation

```bash
composer require uengage.io/php-logger
```

---

## Quick Start

### File transporter (server / EC2)

```php
<?php
use Uengage\Logger\Logger;

$logger = new Logger([
    'product'     => 'edge',
    'service'     => 'ordering',
    'component'   => 'api-server',
    'version'     => '1.4.2',
    'environment' => 'production',
    'source'      => 'server',
    'transport'   => ['type' => 'file'],
    // basePath defaults to /var/log/uengage/
    // log written to: /var/log/uengage/application/edge.log
]);

$logger->warn('Order placed', [
    'context' => ['order_id' => 'ord_8x2k', 'amount' => 450.00],
    'tenant'  => ['business_id' => '456', 'parent_id' => '123'],
    'user_id' => 'usr_7x9k2m',
]);
```

### HTTP transporter (no cloud agent)

```php
<?php
use Uengage\Logger\Logger;

$logger = new Logger([
    'product'     => 'edge',
    'service'     => 'ordering',
    'component'   => 'mobile-app',
    'version'     => '3.0.0',
    'environment' => 'production',
    'source'      => 'client',
    'transport'   => ['type' => 'http', 'config' => [
        'apiKey'    => 'your-api-key-here',
        'batchSize' => 5,
    ]],
]);

$logger->error('Payment webhook timeout', [
    'error'   => ['code' => 'PAYMENT_WEBHOOK_TIMEOUT', 'category' => 'engineering', 'upstream' => 'razorpay'],
    'context' => ['order_id' => 'ord_8x2k', 'latency_ms' => 30012],
    'tenant'  => ['business_id' => '456', 'parent_id' => '123'],
]);
```

### Stdout transporter (Lambda / Docker)

```php
$logger = new Logger([
    'product' => 'edge', 'service' => 'ordering', 'component' => 'worker',
    'version' => '1.0.0', 'environment' => 'production', 'source' => 'server',
    'transport' => ['type' => 'stdout'],
]);
```

---

## Log Schema

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
  "tenant": { "business_id": "456", "parent_id": "123" },
  "source": "server",
  "message": "Payment webhook timeout",
  "user_id": "usr_7x9k2m",
  "error": {
    "code": "PAYMENT_WEBHOOK_TIMEOUT",
    "category": "engineering",
    "stack": "TimeoutError: ...",
    "upstream": "razorpay"
  },
  "context": { "order_id": "ord_8x2k", "amount": 450.0, "latency_ms": 30012 }
}
```

- `timestamp` … `message` - always present
- `user_id`, `error`, `context` - omitted when not passed

---

## Initialization

```php
$logger = new Logger($config);
```

Validates synchronously; throws `\InvalidArgumentException` immediately if anything required is missing or invalid.

### Config Reference

```php
[
    // ── Required ────────────────────────────────────────────────────────
    'product'     => string,   // e.g. 'edge'
    'service'     => string,   // e.g. 'ordering'
    'component'   => string,   // e.g. 'mobile-app'
    'version'     => string,   // e.g. '1.4.2'
    'environment' => string,   // 'production' | 'staging' | 'development'
    'source'      => string,   // 'server' | 'client'
    'transport'   => [
        'type'   => string,    // Required. 'file' | 'http' | 'stdout'
        'config' => [],        // Optional. All fields have defaults - see sections below.
    ],

    // ── Optional ────────────────────────────────────────────────────────
    'minLevel' => string,      // 'debug' | 'info' | 'warn' | 'error'. Default: 'warn'
]
```

---

## Transporters

### File Transporter

Appends one NDJSON line per entry to `{basePath}/application/{product}.log`. The directory is created automatically; all services for the same product on a host share one file.

```php
'transport' => [
    'type'   => 'file',
    'config' => [
        'basePath'         => '/var/log/uengage',  // Default: /var/log/uengage/
        'maxFileSizeBytes' => 10 * 1024 * 1024,    // Default: 10 MB
        'maxRotations'     => 5,                   // Default: 5
    ],
],
```

**File rotation** - when the file reaches `maxFileSizeBytes`:

```
edge.log   → edge.log.1   (previous live file)
edge.log.1 → edge.log.2
...
edge.log.5 → deleted
```

Configure your cloud agent to watch `application/edge.log*` to pick up rotated files. Writes use `FILE_APPEND | LOCK_EX` - safe for concurrent PHP-FPM workers.

---

### HTTP Transporter

POSTs log entries to an HTTP endpoint via cURL.

```php
'transport' => [
    'type'   => 'http',
    'config' => [
        'endpoint'        => 'https://observability.platform.uengage.in/logs',  // Default
        'apiKey'          => 'your-api-key',   // Optional. Sent as x-api-key header.
        'batchSize'       => 5,                // Default: 5
        'flushIntervalMs' => 5000,             // Accepted for config parity; no-op in PHP
        'timeoutMs'       => 5000,             // Default: 5000 ms
    ],
],
```

- **Immediate mode** (`batchSize: 1`) - one POST per call; body is a plain JSON object.
- **Batch mode** (default `batchSize: 5`) - entries queue; body is a JSON array. Call `$logger->destroy()` before exit to flush the remaining queue.
- cURL errors and non-2xx responses are written to `error_log()` with prefix `[uengage-logger][http]`; the host application is never interrupted.

---

### Stdout Transporter

Writes one NDJSON line per entry to `php://stdout`. No config knobs.

```php
'transport' => ['type' => 'stdout'],
```

Best for **AWS Lambda** (Bref / custom PHP runtime) and **Docker** - the runtime captures stdout into CloudWatch Logs or your log-aggregation service. Uses `php://stdout` rather than the `STDOUT` constant, which is undefined under PHP-FPM and most Lambda runtime adapters.

---

## Log Methods

### Method Signature

```php
$logger->info ($message, $options = [])
$logger->error($message, $options = [])
$logger->debug($message, $options = [])
$logger->warn ($message, $options = [])
```

### Log Options Reference

```php
[
    'trace_id' => string,   // UUID for distributed tracing. Auto-generated if not provided.
    'user_id'  => string,   // Omitted from the entry when not provided.

    'tenant' => [
        'business_id' => string,
        'parent_id'   => string,
    ],

    'error' => [
        'code'      => string,   // Machine-readable error code
        'category'  => string,   // 'business' | 'engineering'
        'stack'     => string,   // Stack trace string
        'upstream'  => string,   // External service that caused the error
    ],
    // Include for error and warn events. Omitted when not provided.

    'context' => [...],   // Arbitrary key-value pairs. Deep-cloned at log time. Omitted when not provided.
]
```

---

## Level Filtering

Set `minLevel` to suppress low-priority logs without changing call sites (default: `'warn'`):

```php
$logger = new Logger([..., 'minLevel' => 'warn']);
```

| minLevel               | DEBUG | INFO | WARN | ERROR |
| ---------------------- | :---: | :--: | :--: | :---: |
| `'warn'` **(default)** |   -   |  -   |  ✓   |   ✓   |
| `'info'`               |   -   |  ✓   |  ✓   |   ✓   |
| `'error'`              |   -   |  -   |  -   |   ✓   |
| `'debug'`              |   ✓   |  ✓   |  ✓   |   ✓   |

---

## Graceful Shutdown

| Transport                             | Action needed                                             |
| ------------------------------------- | --------------------------------------------------------- |
| `file`                                | None - writes are synchronous.                            |
| `http` (immediate, `batchSize=1`)     | None - each call fires synchronously.                     |
| `http` (batch, default `batchSize=5`) | Call `$logger->destroy()` before exit to flush the queue. |
| `stdout`                              | None - writes are synchronous.                            |

Register shutdown for HTTP batch mode:

```php
register_shutdown_function(function () use ($logger) {
    $logger->destroy();
});
```

---

## CodeIgniter 2 Integration

### Prerequisite - load Composer autoloader

CI2 does not load Composer's autoloader by default. Add one of the following:

**Option A - `application/config/config.php`** (recommended):

```php
require_once APPPATH . '../vendor/autoload.php';
```

**Option B - `index.php`** (before the CI bootstrap):

```php
require_once 'vendor/autoload.php';
```

---

### Logger_service library

`Logger_service` acts as a lazy factory for the logger. Load it once per controller; it caches one `Logger` instance per service name for the lifetime of the request.

**1. Load the library in your controller**

```php
class My_controller extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('logger_service');
    }
}
```

**2. Get a logger and write a log**

```php
// error
$this->logger_service->get('order-service')->error('Payment failed', [
    'error'   => ['code' => 'PAYMENT_GATEWAY_TIMEOUT', 'category' => 'engineering'],
    'tenant'  => ['business_id' => (string) $businessId, 'parent_id' => '0'],
    'context' => ['order_id' => $orderId],
]);

// warn
$this->logger_service->get('order-service')->warn('Retry attempt', [
    'context' => ['attempt' => 2],
]);
```

**3. Use from a library / helper (no `$this`)**

```php
$CI =& get_instance();
$CI->logger_service->get('cart-service')->info('Item added', ['context' => ['sku' => $sku]]);
```

**How it works internally**

| What           | Detail                                                                              |
| -------------- | ----------------------------------------------------------------------------------- |
| Factory method | `get(string $service, string $component = 'edge-server')`                           |
| Caching        | One `Logger` instance per `"service:component"` key per request                     |
| Log file       | `loggerlogs/edge-{service}.log`                                                     |
| Min level      | `warn` in production, `debug` in all other environments                             |
| Fallback       | If `Logger` construction fails, a `NullLogger` is returned - your code never throws |

**Tip - dynamic log level**

```php
$level = $isCritical ? 'error' : 'warn';
$this->logger_service->get('feed-service')->$level('Feed validation failed', $ctx);
```

---

### Flushing HTTP batch mode in CI2

Register `destroy()` via a `post_system` hook:

```php
// application/config/config.php
$config['enable_hooks'] = TRUE;

// application/config/hooks.php
$hook['post_system'][] = [
    'function' => [$logger, 'destroy'],
    'filename' => '',
    'filepath' => '',
];
```

Or via `register_shutdown_function` in your base controller:

```php
register_shutdown_function([$this->logger, 'destroy']);
```

---

## CodeIgniter 4 Integration

CI4 includes Composer autoloading out of the box - no manual `require` needed.

**Register as a CI4 Service** (`app/Config/Services.php`):

```php
<?php
namespace Config;

use Uengage\Logger\Logger;
use CodeIgniter\Config\BaseService;

class Services extends BaseService
{
    public static function uengageLogger(bool $getShared = true): Logger
    {
        if ($getShared) {
            return static::getSharedInstance('uengageLogger');
        }
        return new Logger([
            'product'     => 'edge',
            'service'     => 'ordering',
            'component'   => 'api-server',
            'version'     => '1.0.0',
            'environment' => ENVIRONMENT,   // 'production' | 'testing' | 'development'
            'source'      => 'server',
            'transport'   => ['type' => 'file'],
        ]);
    }
}
```

**Use in any Controller, Model, or Library:**

```php
service('uengageLogger')->warn('Order placed', [
    'tenant'  => ['business_id' => '456', 'parent_id' => '123'],
    'context' => ['order_id' => 'ord_8x2k', 'amount' => 450.00],
]);

service('uengageLogger')->error('Payment failed', [
    'error'   => ['code' => 'PAYMENT_GATEWAY_TIMEOUT', 'category' => 'engineering', 'upstream' => 'razorpay'],
    'context' => ['order_id' => $orderId],
]);
```

**Flushing HTTP batch mode** - register `destroy()` in a CI4 After-filter or your `BaseController` destructor:

```php
// app/Filters/LoggerShutdown.php
class LoggerShutdown implements FilterInterface
{
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        service('uengageLogger')->destroy();
    }
}
```

---

## PHP-Specific Notes

| Behaviour              | PHP implementation                                              | Note                                                           |
| ---------------------- | --------------------------------------------------------------- | -------------------------------------------------------------- |
| Non-blocking writes    | Synchronous - writes complete inline                            | PHP has no event loop                                          |
| `flushIntervalMs`      | Accepted in config but no-op                                    | Use `destroy()` instead                                        |
| UUID generation        | `openssl_random_pseudo_bytes` (preferred) or `mt_rand` fallback |                                                                |
| Deep clone             | `json_decode(json_encode($val), true)`                          | Equivalent to JS `structuredClone()`                           |
| Timestamp              | `gmdate()` + `microtime(true)` ms                               | Produces `Z` suffix matching ISO 8601 / Node output            |
| Error output           | `error_log()`                                                   | Visible in `/var/log/php_errors.log` or Apache/Nginx error log |
| Concurrent file writes | `file_put_contents(..., FILE_APPEND \| LOCK_EX)`                | Prevents torn writes from concurrent FPM workers               |

---

## Examples

### Business event - order placed

```php
$logger->warn('Order placed', [
    'trace_id' => $_SERVER['HTTP_X_TRACE_ID'] ?? null,
    'user_id'  => $user->id,
    'tenant'   => ['business_id' => '456', 'parent_id' => '123'],
    'context'  => ['order_id' => 'ord_8x2k', 'amount' => 450.00, 'items' => 3],
]);
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

### Engineering error - payment gateway timeout

```php
try {
    $razorpay->capturePayment($payload);
} catch (Exception $e) {
    $logger->error('Payment webhook timeout', [
        'trace_id' => $_SERVER['HTTP_X_TRACE_ID'] ?? null,
        'user_id'  => $user->id,
        'tenant'   => ['business_id' => '456', 'parent_id' => '123'],
        'error'    => [
            'code'     => 'PAYMENT_WEBHOOK_TIMEOUT',
            'category' => 'engineering',
            'stack'    => $e->getTraceAsString(),
            'upstream' => 'razorpay',
        ],
        'context'  => ['order_id' => 'ord_8x2k', 'amount' => 450.00, 'latency_ms' => 30012],
    ]);
}
```

### Warning - rate limit approaching

```php
$logger->warn('Rate limit approaching', [
    'tenant'  => ['business_id' => '456', 'parent_id' => '123'],
    'error'   => ['code' => 'RATE_LIMIT_NEAR_THRESHOLD', 'category' => 'engineering'],
    'context' => [
        'endpoint'           => '/v1/orders',
        'requests_remaining' => 12,
        'window_resets_at'   => '2026-04-07T15:00:00Z',
    ],
]);
```

### Debug - database query

```php
$logger->debug('DB query executed', [
    'context' => ['table' => 'orders', 'duration_ms' => 45, 'rows_returned' => 1],
]);
```

---

## Architecture

```
Logger
  ├── _validateConfig()     validates required fields and transport config
  ├── _log()                builds entry, applies minLevel gate
  │     ├── strtoupper($level)
  │     ├── _generateUuid()                              auto trace_id when not supplied
  │     └── json_decode(json_encode(...), true)          deep-clones context/error/tenant
  └── _transporter->send($entry)
        ├── FileTransporter
        │     ├── path: {basePath}/application/{product}.log   (dir auto-created)
        │     └── file_put_contents(..., FILE_APPEND | LOCK_EX)
        │           └── _rotate() when file exceeds maxFileSizeBytes
        ├── HttpTransporter
        │     ├── immediate (batchSize=1): _post([$entry])   one cURL POST per call
        │     └── batching  (batchSize>1): queue → _flush()
        │           triggered by batchSize threshold or destroy()
        └── StdoutTransporter
              └── fwrite($handle, json_encode($entry) . PHP_EOL)
```

**Error contract:** every transporter catches all internal errors and writes to `error_log()`. A logging failure never throws to the caller.

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
