<?php

namespace Uengage\Logger\Transporters;

/**
 * Abstract base class for all log transporters.
 *
 * Contract: implementations MUST NOT throw exceptions from send() or destroy().
 * All internal errors must be caught and written to error_log().
 */
abstract class BaseTransporter
{
    /**
     * Send a structured log entry.
     *
     * @param array $entry Structured log entry (will be JSON-encoded by the implementation)
     * @return void
     */
    abstract public function send(array $entry);

    /**
     * Flush pending logs and release resources (timers, queues, file handles, etc.).
     * Called when the Logger instance is destroyed.
     * Default is a no-op — override if the transporter holds resources.
     *
     * @return void
     */
    public function destroy()
    {
        // no-op
    }
}
