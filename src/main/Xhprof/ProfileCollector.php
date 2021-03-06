<?php
/**
 * Xhprof Client
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace Xhprof;

class ProfileCollector
{
    const TYPE_WEB = 1;
    const TYPE_WORKER = 2;

    private $backend;
    private $starter;
    private $started = false;
    private $shutdownRegistered = false;
    private $operationName;
    private $customTimers = array();
    private $operationType = self::TYPE_WEB;
    private $error = false;

    public function __construct(Backend $backend, StartDecision $starter)
    {
        $this->backend = $backend;
        $this->starter = $starter;
    }

    public function start()
    {
        if ($this->started) {
            return;
        }

        $this->operationName = null;
        $this->customMeasurements = array();
        $this->started = microtime(true);
        $this->profiling = $this->starter->shouldProfile();
        $this->error = false;
        $this->operationType = php_sapi_name() === 'cli' ? self::TYPE_WORKER : self::TYPE_WEB;

        if ( ! $this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(array($this, 'shutdown'));
        }

        if ( ! $this->profiling) {
            return;
        }

        xhprof_enable();
    }

    public function shutdown()
    {
        $lastError = error_get_last();

        if ($this->isFatal($lastError)) {
            $this->logFatal($lastError['message'], $lastError['file'], $lastError['line'], $lastError['type']);
        } else if (function_exists('http_response_code') && http_response_code() >= 500) {
            $this->logFatal(sprintf('PHP request set error HTTP resonse code "%d".', http_response_code()));
        }

        $this->stop();
    }

    private function isFatal($lastError)
    {
        return $lastError['type'] === E_ERROR || $lastError['type'] === E_PARSE || $lastError['type'] === E_COMPILE_ERROR;
    }

    public function logFatal($message, $file = false, $line = false, $type = E_USER_ERROR)
    {
        if ($this->error) { // logging fatal allowed once
            return;
        }

        $this->error = array('message' => $message, 'file' => $file, 'line' => $line, 'type' => $type);
    }

    public function setOperationType($operationType)
    {
        $this->operationType = $operationType;
    }

    public function setOperationName($operationName)
    {
        $this->operationName = $operationName;
    }

    /**
     * Start a custom timer
     *
     * Custom Timers are aggregated by groups, such as PDO queries, curl
     * calls and so on.
     *
     * @param string $group
     * @param string $identifier
     */
    public function startCustomTimer($group, $identifier)
    {
        if ( ! $this->started || ! $this->profiling) {
            return;
        }

        $this->customTimers[] = array('s' => microtime(true), 'id' => $identifier, 'group' => $group);

        return count($this->customTimers) - 1;
    }

    public function stopCustomTimer($id)
    {
        if (!isset($this->customTimers[$id]) || isset($this->customTimers[$id]['wt'])) {
            return;
        }

        $this->customTimers[$id]['wt'] = (int)round((microtime(true) - $this->customTimers[$id]['s']) * 1000000);
        unset($this->customTimers[$id]['s']);
    }

    public function stop($operationName = null)
    {
        if ( ! $this->started) {
            return;
        }

        $data = ($this->profiling) ?  xhprof_disable() : null;

        $duration = microtime(true) - $this->started;
        $this->started = false;

        if ($operationName) {
            $this->operationName = $operationName;
        }

        if ( ! $this->operationName) {
            $this->operationName = $this->guessOperationName();
        }

        if ($this->error) {
            // do nothing for now
        } else if ($this->profiling) {
            $this->backend->storeProfile($this->operationName, $data, $this->customTimers);
        } else {
            $this->backend->storeMeasurement($this->operationName, (int)round($duration * 1000), $this->operationType);
        }
    }

    private function guessOperationName()
    {
        if (php_sapi_name() === 'cli') {
            return basename($_SERVER['argv'][0]);
        }

        $uri = strpos($_SERVER['REQUEST_URI'], '?')
            ? substr($_SERVER['REQUEST_URI'], 0, strpos($_SERVER['REQUEST_URI'], '?'))
            : $_SERVER['REQUEST_URI'];

        return $_SERVER['REQUEST_METHOD'] . ' ' . $uri;
    }

    public function isStarted()
    {
        return $this->started;
    }
}
