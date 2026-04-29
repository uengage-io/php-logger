<?php

namespace Uengage\Logger;

use Uengage\Logger\Transporters\BaseTransporter;
use Uengage\Logger\Transporters\FileTransporter;
use Uengage\Logger\Transporters\HttpTransporter;
use Uengage\Logger\Transporters\StdoutTransporter;

/**
 * Structured observability logger for uEngage platform services.
 *
 * Usage:
 *   $logger = new Logger(array(
 *       'product'     => 'edge',
 *       'service'     => 'ordering',
 *       'component'   => 'api-server',
 *       'version'     => '1.4.2',
 *       'environment' => 'production',
 *       'source'      => 'server',
 *       'transport'   => array('type' => 'file'),
 *       // transport.config is optional — all fields have defaults
 *   ));
 *
 *   $logger->warn('Order placed', array(
 *       'tenant'  => array('business_id' => '456', 'parent_id' => '123'),
 *       'user_id' => 'usr_7x9k2m',
 *       'context' => array('order_id' => 'ord_8x2k', 'amount' => 450.00),
 *   ));
 */
class Logger
{
    /**
     * Priority order (highest to lowest): error -> warn -> info -> debug
     * @var array Level name -> numeric severity
     */
    private static $_levelSeverity = array(
        'error' => 3,
        'warn'  => 2,
        'info'  => 1,
        'debug' => 0,
    );

    const DEFAULT_MIN_LEVEL  = 'warn';
    const DEFAULT_BASE_PATH  = '/var/log/uengage/';

    /** @var array Immutable service identity stamped on every log entry */
    private $_metadata;

    /** @var int Minimum numeric severity to emit */
    private $_minSeverity;

    /** @var BaseTransporter */
    private $_transporter;

    /**
     * Validates config synchronously. Throws \InvalidArgumentException immediately
     * if anything required is missing or invalid. If the constructor completes without
     * throwing, the instance is ready to use.
     *
     * @param array $config {
     *   @type string $product      Required. e.g. 'edge'
     *   @type string $service      Required. e.g. 'ordering'
     *   @type string $component    Required. e.g. 'mobile-app'
     *   @type string $version      Required. e.g. '1.4.2'
     *   @type string $environment  Required. e.g. 'production' | 'staging' | 'development'
     *   @type string $source       Required. 'server' | 'client'
     *   @type array  $transport    Required. { type: 'file'|'http'|'stdout', config?: array }
     *   @type string $minLevel     Optional. 'error'|'warn'|'info'|'debug'. Default: 'warn'
     * }
     * @throws \InvalidArgumentException
     * @throws \RuntimeException if the log directory cannot be created
     */
    public function __construct(array $config)
    {
        $this->_validateConfig($config);

        $this->_metadata = array(
            'product'     => $config['product'],
            'service'     => $config['service'],
            'component'   => $config['component'],
            'version'     => $config['version'],
            'environment' => $config['environment'],
            'source'      => $config['source'],
        );

        $minLevel           = isset($config['minLevel']) ? $config['minLevel'] : self::DEFAULT_MIN_LEVEL;
        $this->_minSeverity = self::$_levelSeverity[$minLevel];
        $this->_transporter = $this->_createTransporter($config);
    }

    // ─── Public log methods ───────────────────────────────────────────────────

    /**
     * @param string $message Human-readable description of the event
     * @param array  $options See _log() for option keys
     * @return void
     */
    public function info($message, array $options = array())
    {
        $this->_log('info', $message, $options);
    }

    /**
     * @param string $message
     * @param array  $options
     * @return void
     */
    public function error($message, array $options = array())
    {
        $this->_log('error', $message, $options);
    }

    /**
     * @param string $message
     * @param array  $options
     * @return void
     */
    public function debug($message, array $options = array())
    {
        $this->_log('debug', $message, $options);
    }

    /**
     * @param string $message
     * @param array  $options
     * @return void
     */
    public function warn($message, array $options = array())
    {
        $this->_log('warn', $message, $options);
    }

    /**
     * Flush pending batches and release transporter resources.
     * Call this before script exit when using HTTP transport.
     *
     * In CodeIgniter 2 register via post_system hook or:
     *   register_shutdown_function(array($logger, 'destroy'));
     *
     * @return void
     */
    public function destroy()
    {
        $this->_transporter->destroy();
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    /**
     * @param string $level   'error' | 'warn' | 'info' | 'debug'
     * @param string $message
     * @param array  $options {
     *   @type string $trace_id Optional. UUID for distributed tracing. Auto-generated if omitted.
     *   @type string $user_id  Optional. Authenticated user identifier. Omitted from entry when absent.
     *   @type array  $tenant   Optional. { business_id: string, parent_id: string }
     *   @type array  $error    Optional. { code, category, stack?, upstream? }
     *   @type array  $context  Optional. Arbitrary key-value pairs. Deep-cloned at log time.
     * }
     * @return void
     */
    private function _log($level, $message, array $options = array())
    {
        if (self::$_levelSeverity[$level] < $this->_minSeverity) {
            return;
        }

        // ISO 8601 timestamp with milliseconds in UTC, e.g. "2026-04-07T14:32:01.847Z".
        // date('c') produces "+00:00" not "Z", so we build it manually with gmdate().
        $microtime = microtime(true);
        $sec       = (int) $microtime;
        $ms        = (int) round(($microtime - $sec) * 1000);
        $timestamp = gmdate('Y-m-d\TH:i:s', $sec) . '.' . str_pad($ms, 3, '0', STR_PAD_LEFT) . 'Z';

        $traceId = isset($options['trace_id']) && $options['trace_id'] !== ''
                   ? $options['trace_id']
                   : self::_generateUuid();

        // Deep-clone tenant using json round-trip (equivalent to Node's structuredClone).
        // Always pass true to json_decode to get an associative array, not stdClass.
        $rawTenant = isset($options['tenant']) ? $options['tenant'] : array();
        $tenant    = json_decode(json_encode($rawTenant), true);

        $entry = array(
            'timestamp'   => $timestamp,
            'level'       => strtoupper($level),
            'product'     => $this->_metadata['product'],
            'service'     => $this->_metadata['service'],
            'component'   => $this->_metadata['component'],
            'version'     => $this->_metadata['version'],
            'environment' => $this->_metadata['environment'],
            'trace_id'    => $traceId,
            'tenant'      => $tenant,
            'source'      => $this->_metadata['source'],
            'message'     => $message,
        );

        // Optional fields — omitted entirely when not provided to keep entries clean.
        if (isset($options['user_id'])) {
            $entry['user_id'] = $options['user_id'];
        }
        if (isset($options['error'])) {
            $entry['error'] = json_decode(json_encode($options['error']), true);
        }
        if (isset($options['context'])) {
            $entry['context'] = json_decode(json_encode($options['context']), true);
        }

        $this->_transporter->send($entry);
    }

    /**
     * @param array $config
     * @return BaseTransporter
     * @throws \RuntimeException
     */
    private function _createTransporter(array $config)
    {
        $transportConfig = isset($config['transport']['config']) ? $config['transport']['config'] : array();

        if ($config['transport']['type'] === 'http') {
            return new HttpTransporter($transportConfig);
        }

        if ($config['transport']['type'] === 'stdout') {
            // No config knobs today — runtime (Lambda, Docker, systemd)
            // owns buffering and rotation. Same shape as the JS logger.
            return new StdoutTransporter();
        }

        // File transport: resolve basePath default, create directory, derive log file path.
        // Layout is {basePath}/application/{product}.log so cwagent can route the
        // application log subtree as a single file_path glob without having to
        // distinguish per-service files. Multiple services for the same product
        // on a single host share one file (FileTransporter appends with LOCK_EX).
        $basePath = (isset($transportConfig['basePath']) && $transportConfig['basePath'] !== '')
                    ? $transportConfig['basePath']
                    : self::DEFAULT_BASE_PATH;

        $applicationDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'application';

        // Create directory if it does not exist. Throw if creation fails.
        if (!is_dir($applicationDir) && !mkdir($applicationDir, 0777, true) && !is_dir($applicationDir)) {
            throw new \RuntimeException(
                '[uengage-logger] Cannot create log directory "' . $applicationDir . '"'
            );
        }

        $transportConfig['filePath'] = $applicationDir
                                       . DIRECTORY_SEPARATOR
                                       . $config['product'] . '.log';

        return new FileTransporter($transportConfig);
    }

    /**
     * @param array $config
     * @return void
     * @throws \InvalidArgumentException
     */
    private function _validateConfig(array $config)
    {
        $requiredFields = array('product', 'service', 'component', 'version', 'environment', 'source');
        foreach ($requiredFields as $field) {
            // Use !isset || === '' rather than empty() to avoid swallowing the string '0'.
            if (!isset($config[$field]) || $config[$field] === '') {
                throw new \InvalidArgumentException('config.' . $field . ' is required');
            }
        }

        if (!isset($config['transport']) || !is_array($config['transport'])) {
            throw new \InvalidArgumentException('config.transport is required');
        }

        if (!isset($config['transport']['type'])
            || !in_array($config['transport']['type'], array('http', 'file', 'stdout'), true)) {
            throw new \InvalidArgumentException("config.transport.type must be 'http', 'file', or 'stdout'");
        }
    }

    /**
     * Generate a UUID v4 string.
     *
     * Uses openssl_random_pseudo_bytes when the openssl extension is available
     * (cryptographically secure). Falls back to mt_rand when openssl is absent
     * (not cryptographically secure, but functionally correct for trace IDs).
     *
     * @return string e.g. "550e8400-e29b-41d4-a716-446655440000"
     */
    private static function _generateUuid()
    {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $data    = openssl_random_pseudo_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant 10xx
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }

        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
