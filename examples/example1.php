<?php

/**
 * Manual smoke test for uengage/logger PHP package.
 *
 * Run from the package root:
 *   php examples/test.php
 *
 * What this script does:
 *   1. Logs all four levels to a file and prints the written lines
 *   2. Shows optional fields (user_id, tenant, error, context)
 *   3. Shows minLevel filtering in action
 *   4. Shows context isolation (mutating after log has no effect)
 *   5. Shows config validation errors
 *   6. Shows HTTP transporter in batch mode (posts to a public echo endpoint)
 *   7. Shows file rotation with a tiny size limit
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Uengage\Logger\Logger;

// ─── Helpers ─────────────────────────────────────────────────────────────────

/** Print a section header */
function section($title) {
    echo "\n" . str_repeat('─', 60) . "\n";
    echo "  " . $title . "\n";
    echo str_repeat('─', 60) . "\n";
}

/** Pretty-print a NDJSON log file */
function dump_log($path) {
    if (!file_exists($path)) {
        echo "  (file not found: $path)\n";
        return;
    }
    $lines = array_filter(explode("\n", trim(file_get_contents($path))));
    foreach ($lines as $i => $line) {
        $entry = json_decode($line, true);
        echo sprintf(
            "  [%d] %s  %-5s  %s\n",
            $i + 1,
            $entry['timestamp'],
            $entry['level'],
            $entry['message']
        );
        // Show optional fields if present
        if (isset($entry['user_id']))  echo "       user_id  : {$entry['user_id']}\n";
        if (!empty($entry['tenant']))  echo "       tenant   : business={$entry['tenant']['business_id']} parent={$entry['tenant']['parent_id']}\n";
        if (isset($entry['error']))    echo "       error    : [{$entry['error']['category']}] {$entry['error']['code']}\n";
        if (isset($entry['context'])) {
            $ctx = http_build_query($entry['context'], '', '  ');
            echo "       context  : $ctx\n";
        }
        echo "       trace_id : {$entry['trace_id']}\n";
    }
}

/** Create a fresh temp directory for log files */
function make_tmp_dir($suffix) {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uengage-test-' . $suffix;
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    return $dir;
}

// ─── 1. All four log levels ───────────────────────────────────────────────────

section('1. All four log levels → file transporter');

$dir = make_tmp_dir('levels');
$log = $dir . DIRECTORY_SEPARATOR . 'edge-ordering.log';
@unlink($log);

$logger = new Logger(array(
    'product'     => 'edge',
    'service'     => 'ordering',
    'component'   => 'api-server',
    'version'     => '1.4.2',
    'environment' => 'development',
    'source'      => 'server',
    'transport'   => array('type' => 'file', 'config' => array('basePath' => $dir)),
    'minLevel'    => 'debug',   // override default 'warn' to see all four levels
));

$logger->debug('DB query executed',        array('context' => array('table' => 'orders', 'duration_ms' => 45)));
$logger->info ('Order placed',             array('context' => array('order_id' => 'ord_8x2k', 'amount' => 450.00)));
$logger->warn ('Rate limit approaching',   array('context' => array('requests_remaining' => 12)));
$logger->error('Payment webhook timeout',  array('context' => array('latency_ms' => 30012)));

echo "  Log file: $log\n\n";
dump_log($log);

// ─── 2. All optional fields ───────────────────────────────────────────────────

section('2. Full entry with all optional fields');

$dir2 = make_tmp_dir('full');
$log2 = $dir2 . DIRECTORY_SEPARATOR . 'edge-ordering.log';
@unlink($log2);

$logger2 = new Logger(array(
    'product'     => 'edge',
    'service'     => 'ordering',
    'component'   => 'api-server',
    'version'     => '1.4.2',
    'environment' => 'production',
    'source'      => 'server',
    'transport'   => array('type' => 'file', 'config' => array('basePath' => $dir2)),
));

try {
    throw new RuntimeException('Gateway timed out after 30s');
} catch (RuntimeException $e) {
    $logger2->error('Payment webhook timeout', array(
        'trace_id' => 'abc-123-def-456',
        'user_id'  => 'usr_7x9k2m',
        'tenant'   => array('business_id' => '456', 'parent_id' => '123'),
        'error'    => array(
            'code'     => 'PAYMENT_WEBHOOK_TIMEOUT',
            'category' => 'engineering',
            'stack'    => $e->getTraceAsString(),
            'upstream' => 'razorpay',
        ),
        'context'  => array(
            'order_id'   => 'ord_8x2k',
            'amount'     => 450.00,
            'latency_ms' => 30012,
        ),
    ));
}

echo "  Log file: $log2\n\n";
dump_log($log2);

// Print the raw JSON so the full schema is visible
echo "\n  Raw JSON:\n";
$raw = trim(file_get_contents($log2));
$pretty = json_encode(json_decode($raw), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
foreach (explode("\n", $pretty) as $line) {
    echo "    $line\n";
}

// ─── 3. minLevel filtering ────────────────────────────────────────────────────

section('3. Default minLevel = warn  →  debug and info are silently dropped');

$dir3 = make_tmp_dir('minlevel');
$log3 = $dir3 . DIRECTORY_SEPARATOR . 'edge-ordering.log';
@unlink($log3);

// No minLevel specified — default is 'warn'
$logger3 = new Logger(array(
    'product'     => 'edge',
    'service'     => 'ordering',
    'component'   => 'api-server',
    'version'     => '1.4.2',
    'environment' => 'production',
    'source'      => 'server',
    'transport'   => array('type' => 'file', 'config' => array('basePath' => $dir3)),
));

$logger3->debug('this should be dropped');
$logger3->info ('this should be dropped');
$logger3->warn ('this should appear');
$logger3->error('this should appear');

$lines = array_filter(explode("\n", trim(file_get_contents($log3))));
echo "  Wrote 4 calls, default minLevel=warn → " . count($lines) . " line(s) written (expected 2)\n\n";
dump_log($log3);

// ─── 4. Context isolation ─────────────────────────────────────────────────────

section('4. Context isolation — mutating array after log() has no effect');

$dir4 = make_tmp_dir('isolation');
$log4 = $dir4 . DIRECTORY_SEPARATOR . 'edge-ordering.log';
@unlink($log4);

$logger4 = new Logger(array(
    'product'     => 'edge',
    'service'     => 'ordering',
    'component'   => 'api-server',
    'version'     => '1.0.0',
    'environment' => 'development',
    'source'      => 'server',
    'transport'   => array('type' => 'file', 'config' => array('basePath' => $dir4)),
));

$context = array('order_id' => 'ord_original', 'amount' => 100.00);
$logger4->warn('Order placed', array('context' => $context));

// Mutate AFTER the log call
$context['order_id'] = 'ord_MUTATED';
$context['amount']   = 999.99;

$entry = json_decode(trim(file_get_contents($log4)), true);
$logged_order_id = $entry['context']['order_id'];

echo "  context['order_id'] before log : ord_original\n";
echo "  context['order_id'] mutated to : ord_MUTATED\n";
echo "  value stored in the log file   : $logged_order_id\n";
echo "  isolation working correctly    : " . ($logged_order_id === 'ord_original' ? 'YES' : 'NO') . "\n";

// ─── 5. Config validation errors ─────────────────────────────────────────────

section('5. Config validation — constructor throws on bad config');

$cases = array(
    array('label' => 'missing product',       'config' => array('product' => '')),
    array('label' => 'missing service',       'config' => array('service' => '')),
    array('label' => 'transport missing',     'config' => array('no_transport' => true)),
    array('label' => 'invalid transport type','config' => array('transport' => array('type' => 'syslog'))),
);

$base = array(
    'product' => 'edge', 'service' => 'ordering', 'component' => 'c',
    'version' => '1.0',  'environment' => 'dev',  'source' => 'server',
    'transport' => array('type' => 'file', 'config' => array('basePath' => sys_get_temp_dir())),
);

foreach ($cases as $case) {
    $config = array_merge($base, $case['config']);
    if (isset($case['config']['no_transport'])) {
        unset($config['transport']);
    }
    try {
        new Logger($config);
        echo "  FAIL  {$case['label']} — no exception thrown\n";
    } catch (InvalidArgumentException $e) {
        echo "  OK    {$case['label']} → \"{$e->getMessage()}\"\n";
    }
}

// ─── 6. Auto directory creation ───────────────────────────────────────────────

section('6. Auto directory creation — logger creates basePath if missing');

$nestedDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
             . 'uengage-autocreate-' . uniqid('', true) . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'sub';

echo "  basePath (does not exist yet): $nestedDir\n";

$loggerAuto = new Logger(array(
    'product'     => 'edge',
    'service'     => 'ordering',
    'component'   => 'demo',
    'version'     => '1.0.0',
    'environment' => 'development',
    'source'      => 'server',
    'transport'   => array('type' => 'file', 'config' => array('basePath' => $nestedDir)),
));

$loggerAuto->warn('Directory auto-created by logger');

$createdLog = $nestedDir . DIRECTORY_SEPARATOR . 'edge-ordering.log';
echo "  Directory created : " . (is_dir($nestedDir) ? 'YES' : 'NO') . "\n";
echo "  Log file created  : " . (file_exists($createdLog) ? 'YES' : 'NO') . "\n";

// ─── 7. HTTP transporter — batch mode ────────────────────────────────────────

section('7. HTTP transporter — batch mode (posts to default endpoint)');

// Uses the default endpoint: https://observability.platform.uengage.in/logs
// Replace apiKey with your real key; omit it entirely to send without authentication.
$http_logger = new Logger(array(
    'product'     => 'edge',
    'service'     => 'ordering',
    'component'   => 'mobile-app',
    'version'     => '3.0.0',
    'environment' => 'development',
    'source'      => 'client',
    'transport'   => array('type' => 'http', 'config' => array(
        // endpoint defaults to https://observability.platform.uengage.in/logs
        // 'apiKey'    => 'your-api-key',
        'batchSize' => 3,       // flush when 3 entries have queued up
        'timeoutMs' => 5000,
    )),
));

echo "  Queuing 3 entries (batch will auto-flush on the 3rd)...\n";
$http_logger->warn('User opened app',     array('user_id' => 'usr_abc', 'context' => array('screen' => 'home')));
$http_logger->warn('User tapped product', array('user_id' => 'usr_abc', 'context' => array('product_id' => 'p_99')));
$http_logger->error('User checkout failed', array('user_id' => 'usr_abc', 'error' => array(
    'code'     => 'CHECKOUT_FAILED',
    'category' => 'engineering',
)));
// ↑ third entry triggers automatic flush

echo "  Calling destroy() to flush any remaining entries...\n";
$http_logger->destroy();

echo "  Done. Check stderr / error_log for any cURL errors.\n";

// ─── 8. File rotation ────────────────────────────────────────────────────────

section('8. File rotation — tiny 200-byte limit');

$dir8 = make_tmp_dir('rotation');
$log8 = $dir8 . DIRECTORY_SEPARATOR . 'edge-demo.log';
// Remove any leftovers
foreach (glob($log8 . '*') as $f) unlink($f);

$logger8 = new Logger(array(
    'product'     => 'edge',
    'service'     => 'demo',
    'component'   => 'rotation-test',
    'version'     => '1.0.0',
    'environment' => 'development',
    'source'      => 'server',
    'transport'   => array('type' => 'file', 'config' => array(
        'basePath'         => $dir8,
        'maxFileSizeBytes' => 200,   // rotate after every ~2 lines
        'maxRotations'     => 3,
    )),
    'minLevel'    => 'debug',
));

for ($i = 1; $i <= 8; $i++) {
    $logger8->warn("Entry $i", array('context' => array('seq' => $i)));
}

echo "  Wrote 8 entries with maxFileSizeBytes=200, maxRotations=3\n\n";
$files = glob($log8 . '*');
sort($files);
foreach ($files as $f) {
    $lines = count(array_filter(explode("\n", trim(file_get_contents($f)))));
    echo sprintf("  %-45s  %d line(s)  %d bytes\n",
        basename($f), $lines, filesize($f));
}

// ─── Done ────────────────────────────────────────────────────────────────────

section('All tests complete');
echo "  Log files written to: " . sys_get_temp_dir() . DIRECTORY_SEPARATOR . "uengage-test-*\n\n";
