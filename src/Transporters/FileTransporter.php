<?php

namespace Uengage\Logger\Transporters;

/**
 * Writes log entries as NDJSON (one JSON object per line) to a local file.
 *
 * File path is derived by Logger and passed in config:
 *   {basePath}/{product}-{service}.log
 *
 * File rotation: when the file reaches maxFileSizeBytes, numbered backups are
 * shifted up (.1 -> .2 -> ... -> .N) and the live file is renamed to .1.
 * The oldest backup (slot N+1) is discarded.
 *
 * Writes are synchronous (PHP has no setImmediate / event loop). In FPM
 * multi-process environments, LOCK_EX prevents torn writes from concurrent workers.
 */
class FileTransporter extends BaseTransporter
{
    const DEFAULT_MAX_FILE_SIZE = 10485760; // 10 MB
    const DEFAULT_MAX_ROTATIONS = 5;
    const LOG_PREFIX            = '[uengage-logger][file]';

    /** @var string */
    private $_filePath;

    /** @var int */
    private $_maxFileSizeBytes;

    /** @var int */
    private $_maxRotations;

    /**
     * @param array $config {
     *   @type string $filePath         Full path to log file (derived by Logger from basePath + product + service)
     *   @type int    $maxFileSizeBytes Optional. Rotate when file exceeds this size. Default: 10485760 (10 MB)
     *   @type int    $maxRotations     Optional. Number of rotated backups to keep. Default: 5
     * }
     */
    public function __construct(array $config)
    {
        $this->_filePath         = $config['filePath'];
        $this->_maxFileSizeBytes = isset($config['maxFileSizeBytes'])
                                   ? (int) $config['maxFileSizeBytes']
                                   : self::DEFAULT_MAX_FILE_SIZE;
        $this->_maxRotations     = isset($config['maxRotations'])
                                   ? (int) $config['maxRotations']
                                   : self::DEFAULT_MAX_ROTATIONS;
    }

    /**
     * Append a JSON line to the log file, rotating first if the size threshold is reached.
     *
     * @param array $entry
     * @return void
     */
    public function send(array $entry)
    {
        // PHP is synchronous — writes happen inline per call.
        // This is the correct behaviour for request-lifecycle PHP (Apache/FPM).
        $this->_write($entry);
    }

    /**
     * @param array $entry
     * @return void
     */
    private function _write(array $entry)
    {
        try {
            // JSON_UNESCAPED_UNICODE keeps non-ASCII chars (e.g. Hindi restaurant names) as-is,
            // matching Node's JSON.stringify output which does not escape Unicode by default.
            $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";

            if (file_exists($this->_filePath)) {
                // Clear PHP's internal stat cache so filesize() is not stale across
                // concurrent FPM workers that may have just written to the same file.
                clearstatcache(true, $this->_filePath);
                $size = filesize($this->_filePath);
                if ($size !== false && $size >= $this->_maxFileSizeBytes) {
                    $this->_rotate();
                }
            }

            $result = file_put_contents($this->_filePath, $line, FILE_APPEND | LOCK_EX);
            if ($result === false) {
                error_log(self::LOG_PREFIX . ' write failed: file_put_contents returned false for ' . $this->_filePath);
            }
        } catch (\Exception $e) {
            // Catch \Exception (not \Throwable — that requires PHP 7) to stay PHP 5.6 compatible.
            error_log(self::LOG_PREFIX . ' write failed: ' . $e->getMessage());
        }
    }

    /**
     * Rotate log files: shift numbered backups up by one slot, rename live file to .1.
     *
     * Example with maxRotations=5:
     *   app.log.5 → deleted (falls off)
     *   app.log.4 → app.log.5  ...  app.log.1 → app.log.2
     *   app.log   → app.log.1  (live file starts fresh on next write)
     *
     * @return void
     */
    private function _rotate()
    {
        try {
            // Walk from (maxRotations-1) down to 1, shifting each slot up.
            for ($i = $this->_maxRotations - 1; $i >= 1; $i--) {
                $src = $this->_filePath . '.' . $i;
                $dst = $this->_filePath . '.' . ($i + 1);
                if (file_exists($src)) {
                    // On Windows, rename() fails if the destination already exists — unlink first.
                    // The @ operator suppresses warnings; non-existence of $dst is expected.
                    if (file_exists($dst)) {
                        @unlink($dst);
                    }
                    @rename($src, $dst);
                }
            }

            // Rename the live log file to slot 1.
            if (file_exists($this->_filePath)) {
                $dst = $this->_filePath . '.1';
                if (file_exists($dst)) {
                    @unlink($dst);
                }
                rename($this->_filePath, $dst);
            }
        } catch (\Exception $e) {
            error_log(self::LOG_PREFIX . ' rotation failed: ' . $e->getMessage());
        }
    }

    /**
     * No-op — no handles or queue are held between individual writes.
     *
     * @return void
     */
    public function destroy()
    {
        // no-op
    }
}
