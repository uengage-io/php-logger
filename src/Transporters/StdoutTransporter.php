<?php

namespace Uengage\Logger\Transporters;

/**
 * Writes NDJSON log entries to PHP's standard output stream.
 *
 * Intended for Lambda (Bref / custom runtimes), Docker, and any runtime that
 * captures stdout as logs. No batching, no rotation, no file path — one
 * JSON line per entry, flushed by the runtime's own stdout handling.
 *
 * Uses `php://stdout` rather than the `STDOUT` constant so it works under
 * PHP-FPM and embedded SAPIs where the constant is not defined. CLI mode
 * also resolves `php://stdout` to the real stdout, so the same code path
 * works in both contexts.
 */
class StdoutTransporter extends BaseTransporter
{
    const LOG_PREFIX = '[uengage-logger][stdout]';

    /** @var resource|null */
    private $_handle;

    public function __construct()
    {
        // Open once per process. fopen() is safe to call repeatedly but
        // caching the handle avoids per-write overhead in tight loops.
        $this->_handle = @fopen('php://stdout', 'w');
        if ($this->_handle === false) {
            error_log(self::LOG_PREFIX . ' fopen(php://stdout) failed; entries will be dropped');
            $this->_handle = null;
        }
    }

    /**
     * @param array $entry
     * @return void
     */
    public function send(array $entry)
    {
        if ($this->_handle === null) {
            return;
        }
        try {
            // JSON_UNESCAPED_UNICODE matches Node's JSON.stringify default —
            // non-ASCII characters pass through verbatim so downstream log
            // tooling (CloudWatch Logs, jq) sees readable values rather than
            // \uXXXX escapes.
            $line   = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
            $result = fwrite($this->_handle, $line);
            if ($result === false) {
                error_log(self::LOG_PREFIX . ' write failed: fwrite returned false');
            }
        } catch (\Exception $e) {
            error_log(self::LOG_PREFIX . ' write failed: ' . $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function destroy()
    {
        if ($this->_handle !== null) {
            @fclose($this->_handle);
            $this->_handle = null;
        }
    }
}
