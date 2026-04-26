<?php

namespace Uengage\Logger\Transporters;

/**
 * POSTs log entries to an HTTP endpoint via cURL.
 *
 * Batch mode (default — batchSize=5):
 *   Entries queue up and flush when the batch is full or destroy() is called.
 *   The request body is a JSON array.
 *
 * Immediate mode (batchSize=1):
 *   Each send() call fires one POST immediately. The request body is a plain JSON object.
 *
 * NOTE — flushIntervalMs is accepted for config parity with the Node package but
 * has no effect in PHP. PHP runs in a synchronous request/response cycle with no
 * event loop; timer-based flushes are not possible. Use destroy() (or register it
 * as a shutdown function) to flush any remaining queued entries before the script ends.
 *
 * Errors are written to error_log() with the prefix [uengage-logger][http].
 * The host application is never interrupted.
 */
class HttpTransporter extends BaseTransporter
{
    const DEFAULT_HTTP_ENDPOINT  = 'https://observability.platform.uengage.in/logs/ingest';
    const DEFAULT_BATCH_SIZE     = 5;
    const DEFAULT_FLUSH_INTERVAL = 5000; // ms — accepted but unused; PHP has no event loop
    const DEFAULT_TIMEOUT_MS     = 5000;
    const LOG_PREFIX             = '[uengage-logger][http]';

    /** @var string */
    private $_endpoint;

    /** @var string|null */
    private $_apiKey;

    /** @var int */
    private $_batchSize;

    /** @var int */
    private $_timeoutMs;

    /** @var bool */
    private $_isBatching;

    /** @var array */
    private $_queue;

    /**
     * @param array $config {
     *   @type string $endpoint        Optional. Default: https://observability.platform.uengage.in/logs/ingest
     *   @type string $apiKey          Optional. Sent as x-api-key header. Header omitted when absent.
     *   @type int    $batchSize       Optional. Entries to accumulate before flushing. Default: 5.
     *   @type int    $flushIntervalMs Optional. Accepted for config parity; no-op in PHP. Default: 5000.
     *   @type int    $timeoutMs       Optional. Per-request cURL timeout in milliseconds. Default: 5000.
     * }
     */
    public function __construct(array $config)
    {
        $this->_endpoint  = (isset($config['endpoint']) && $config['endpoint'] !== '')
                            ? $config['endpoint']
                            : self::DEFAULT_HTTP_ENDPOINT;
        $this->_apiKey    = isset($config['apiKey']) ? $config['apiKey'] : null;
        $this->_batchSize = isset($config['batchSize'])
                            ? (int) $config['batchSize']
                            : self::DEFAULT_BATCH_SIZE;
        $this->_timeoutMs = isset($config['timeoutMs'])
                            ? (int) $config['timeoutMs']
                            : self::DEFAULT_TIMEOUT_MS;
        // flushIntervalMs accepted for parity but has no functional effect in PHP.
        $this->_isBatching = $this->_batchSize > 1;
        $this->_queue      = array();
    }

    /**
     * @param array $entry
     * @return void
     */
    public function send(array $entry)
    {
        if (!$this->_isBatching) {
            $this->_post(array($entry));
            return;
        }

        $this->_queue[] = $entry;

        if (count($this->_queue) >= $this->_batchSize) {
            $this->_flush();
        }
        // NOTE: No timer-based flush — PHP has no event loop.
        // Remaining entries flush when destroy() is called or the batch fills up.
    }

    /**
     * Flush the queue and release resources. Always call this before script exit
     * to avoid silently dropping queued entries.
     *
     * In CodeIgniter 2, register this via:
     *   register_shutdown_function(array($logger, 'destroy'));
     * or via a post_system hook.
     *
     * @return void
     */
    public function destroy()
    {
        $this->_flush();
    }

    /**
     * @return void
     */
    private function _flush()
    {
        if (empty($this->_queue)) {
            return;
        }
        $batch        = $this->_queue;
        $this->_queue = array(); // drain atomically before POSTing
        $this->_post($batch);
    }

    /**
     * @param array $entries
     * @return void
     */
    private function _post(array $entries)
    {
        // Single entry -> plain object body; multiple entries -> array body.
        $payload = count($entries) === 1 ? $entries[0] : $entries;
        $body    = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // Use mb_strlen with '8bit' to get byte length — guards against mbstring.func_overload=2.
        $contentLength = function_exists('mb_strlen')
                         ? mb_strlen($body, '8bit')
                         : strlen($body);

        $headers = array(
            'Content-Type: application/json',
            'Content-Length: ' . $contentLength,
        );
        if ($this->_apiKey !== null) {
            $headers[] = 'x-api-key: ' . $this->_apiKey;
        }

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $this->_endpoint,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => $this->_timeoutMs,
            CURLOPT_HTTPHEADER     => $headers,
        ));

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            error_log(self::LOG_PREFIX . ' send failed: ' . $curlError);
            return;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log(self::LOG_PREFIX . ' server returned HTTP ' . $httpCode
                      . ' for endpoint ' . $this->_endpoint);
        }
    }
}
