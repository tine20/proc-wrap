<?php
/*
 * This file is part of tine20/proc-wrap.
 *
 * (c) Metaways Infosystems GmbH (http://www.metaways.de)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tine20\ProcWrap;

/**
 * Class represents a single, scoped command. On objects destruction a running process will be terminated
 * can be executed multiple times in a row if recycled() after each execution.
 *
 * relies on
 * pcntl_async_signals(true);
 * pcntl_signal(SIGALRM, ...);
 */
class Cmd
{
    public const STDIN  = 0;
    public const STDOUT = 1;
    public const STDERR = 2;

    /** @var bool */
    public static $useAsyncSignals = true;

    /**
     * Cmd constructor.
     * This is a single cmd.
     *
     * @param mixed $cmd Command to exec and its parameters
     * @param array<IODescriptor> $ioDescriptors
     */
    public function __construct(mixed $cmd, array $ioDescriptors = [])
    {
        $this->cmd = $cmd;
        $this->ioDescriptors = $ioDescriptors ?: [
            self::STDIN  => new PipeDescriptor(self::STDIN,  'r'),
            self::STDOUT => new PipeDescriptor(self::STDOUT, 'w'),
            self::STDERR => new PipeDescriptor(self::STDERR, 'w'),
        ];

        if (null === self::$objs) {
            self::$objs = new \SplObjectStorage(); /** @phpstan-ignore-line */
        }

        self::$objs->attach(\WeakReference::create($this));
        self::registerSignalHandler();
    }

    /**
     * scoped object, will shut down cleanly on destruct
     */
    public function __destruct()
    {
        if ($this->isRunning()) {
            $this->shutdown();
        }

        if (null !== self::$objs) {
            foreach (self::$objs as $obj) {
                if (!$obj->get() || $obj->get() === $this) {
                    self::$objs->detach($obj);
                }
            }
        }
    }

    public function getStdOut(): string
    {
        if (!isset($this->ioDescriptors[self::STDOUT]) || ! $this->ioDescriptors[self::STDOUT] instanceof PipeDescriptor) {
            return '';
        }
        return $this->ioDescriptors[self::STDOUT]->getData();
    }

    public function getStdErr(): string
    {
        if (!isset($this->ioDescriptors[self::STDERR]) || ! $this->ioDescriptors[self::STDERR] instanceof PipeDescriptor) {
            return '';
        }
        return $this->ioDescriptors[self::STDERR]->getData();
    }

    /**
     * @return array<IODescriptor>
     */
    public function getIODescriptors(): array
    {
        return $this->ioDescriptors;
    }

    public function getIODescriptor(int $descriptorNumber): IODescriptor
    {
        return $this->ioDescriptors[$descriptorNumber];
    }

    public function setIODescriptor(IODescriptor $descriptor): self
    {
        if ($this->isRunning()) {
            throw new CmdException('cmd is already executing');
        }

        $this->ioDescriptors[$descriptor->getDescriptorNumber()] = $descriptor;
        return $this;
    }

    public function removeIODescriptor(int $descriptorNumber): self
    {
        if ($this->isRunning()) {
            throw new CmdException('cmd is already executing');
        }

        unset($this->ioDescriptors[$descriptorNumber]);
        return $this;
    }

    public function getExitCode(): int
    {
        if (null === $this->exitCode) {
            throw new CmdException('Cmd has no exit code');
        }

        return $this->exitCode;
    }

    public function getTotalExecutionTimeMilli(): int
    {
        if (null === $this->procClosed) {
            throw new CmdException('Cmd has not been closed properly');
        }

        return (int)(($this->procClosed - $this->procStarted) * 1000);
    }

    public function getExecutionTimeMilli(): int
    {
        if (null === $this->procClosed) {
            throw new CmdException('Cmd has not been closed properly');
        }

        return (int)(($this->procClosed - $this->procOpened) * 1000);
    }

    public function runInBackground(bool $value = true): self
    {
        if ($this->isRunning()) {
            throw new CmdException('cmd is already executing');
        }

        $this->background = $value;
        return $this;
    }

    public function setTimeoutInSeconds(int $seconds): self
    {
        if ($this->isRunning()) {
            throw new CmdException('cmd is already executing');
        }

        $this->timeout = $seconds * 1000;
        return $this;
    }

    public function setTimeoutInMilliSeconds(int $milliseconds): self
    {
        if ($this->isRunning()) {
            throw new CmdException('cmd is already executing');
        }

        $this->timeout = $milliseconds;
        return $this;
    }

    public function startTimeoutBeforeProcOpen(bool $value = true): self
    {
        if ($this->isRunning()) {
            throw new CmdException('cmd is already executing');
        }

        $this->startTimeoutBeforeProcOpen = $value;
        return $this;
    }

    public function recycle(): self
    {
        if ($this->isRunning()) {
            // this may become more graceful than the hard shutdown, yet, currently it's just a shutdown
            $this->timeoutProcedure();
        }

        $this->wpipes = [];
        $this->procHandle = null;
        $this->procStarted = null;
        $this->procOpened = null;
        $this->procClosed = null;
        $this->exitCode = null;

        return $this;
    }

    public function exec(): self
    {
        if (null !== $this->procStarted) {
            throw new CmdException('Cmd was already executed, but has not yet been recycled.');
        }

        $descriptor_spec = [];
        foreach ($this->ioDescriptors as $descriptorNumber => $ioDescriptor) {
            $descriptor_spec[$descriptorNumber] = $ioDescriptor->getDescription();
        }

        $this->procStarted = microtime(true);
        if ($this->startTimeoutBeforeProcOpen) {
            self::setAlarm();
        }

        $pipes = [];
        $this->procHandle = proc_open($this->cmd, $descriptor_spec, $pipes);

        if (!is_resource($this->procHandle)) {
            throw new CmdException('proc_open failed');
        }

        $this->procOpened = microtime(true);
        if (!$this->startTimeoutBeforeProcOpen) {
            self::setAlarm();
        }

        // set pipes to none blocking
        try {
            foreach ($pipes as $descriptorNumber => $stream) {
                $pipeDescriptor = $this->ioDescriptors[$descriptorNumber];
                assert($pipeDescriptor instanceof PipeDescriptor);
                $pipeDescriptor->setStream($stream);
                if ($pipeDescriptor->isWPipe()) {
                    $this->wpipes[(int)$stream] = $pipeDescriptor;
                }
            }
        } catch (CmdException $cmdE) {
            $this->shutdown();
            throw $cmdE;
        }

        // if this is a background process, return and let the consumer poll whenever desired
        if ($this->background) {
            return $this;
        }

        if ($this->timeout > 0) {
            if ($this->internalPoll(($this->startTimeoutBeforeProcOpen ? $this->procStarted : $this->procOpened) +
                    $this->timeout / 1000)) {
                $this->timeoutProcedure();
            }
        } else {
            $this->internalPoll(null);
        }

        $this->gracefulEnd();

        return $this;
    }

    public function getTimeoutTS(): float
    {
        return $this->timeout > 0 ? ($this->startTimeoutBeforeProcOpen ? $this->procStarted : $this->procOpened) +
            $this->timeout / 1000 : PHP_FLOAT_MAX;
    }

    /**
     * do non-blocking I/O (i.e. read stdout / stderr, write to stdin) if I/O data is available
     * check timeout and eventually terminate process
     * returns true if more polling should be done
     * returns false once the process has terminated
     *
     * @return bool
     */
    public function poll(): bool
    {
        if (!is_resource($this->procHandle)) {
            return false;
        }

        if ($this->timeout > 0 && $this->timeout -
            (microtime(true) - ($this->startTimeoutBeforeProcOpen ? $this->procStarted : $this->procOpened)) * 1000 < 1) {
            $this->timeoutProcedure();
            return false;
        }

        if (!$this->internalPoll(0)) {
            $this->gracefulEnd();
            return false;
        }

        return true;
    }

    public function isRunning(): bool
    {
        if (!is_resource($this->procHandle)) {
            return false;
        }

        $status = proc_get_status($this->procHandle);
        if (!$status['running']) {
            if (null === $this->exitCode) {
                $this->exitCode = $status['exitcode'];
            }
            return false;
        }

        return true;
    }

    public function getRemainingTimeoutInSec(): int
    {
        if (!$this->isRunning() || 0 === $this->timeout) return 0;
        return (int)ceil(($this->timeout -
            (microtime(true) - ($this->startTimeoutBeforeProcOpen ? $this->procStarted : $this->procOpened)) * 1000)
            / 1000);
    }

    public static function setAlarm(): void
    {
        if (null === self::$objs) {
            pcntl_alarm(0);
            return;
        }
        $alarm = null;
        foreach (self::$objs as $obj) {
            /** @var self $cmd */
            if (!($cmd = $obj->get())) {
                self::$objs->detach($obj);
            } else {
                $timeout = $cmd->getRemainingTimeoutInSec();
                if ($timeout < 1 && $cmd->isRunning()) $timeout = 1;
                if ($timeout > 0 && (null === $alarm || $timeout < $alarm)) {
                    $alarm = $timeout;
                }
            }
        }
        pcntl_alarm($alarm ?: 0);
    }

    public static function sigAlarm(): void
    {
        self::setAlarm();

        if (null === self::$objs) return;

        foreach (self::$objs as $obj) {
            /** @var self $cmd */
            if (!($cmd = $obj->get())) {
                self::$objs->detach($obj);
            } else {
                $cmd->poll();
            }
        }
    }

    protected static function registerSignalHandler(): void
    {
        static $registered = false;
        if (!$registered) {
            if (self::$useAsyncSignals) {
                pcntl_async_signals(true);
            }
            pcntl_signal(SIGALRM, function() {
                self::sigAlarm();
            });
            $registered = true;
        }
    }

    protected function timeoutProcedure(): void
    {
        // TODO make this more configurable, add grace period, use SIGTERM first, then SIGKILL, etc.
        // TODO add delegator interface?

        $this->shutdown();
    }

    /**
     * returns true if timeout occurred, returns false if process terminated
     *
     * @param ?float $timeout as microtime(true) timestamp, 0 means return immediately after one attempt to do I/O, null means no timeout
     * @return bool
     * @throws CmdException
     */
    protected function internalPoll(?float $timeout): bool
    {
        do {
            if (!$this->isRunning()) {
                return false;
            }
            $read = [];
            foreach ($this->wpipes as $pipeDescriptor) {
                if ($stream = $pipeDescriptor->getStream())
                    $read[] = $stream;
            }
            if (empty($read)) {
                if (0 === (int)$timeout) {
                    return true;
                }
                @usleep(1000); // sleep 1 ms to save cpu time, wait for isRunning() to fail or timeout to occur
            } else {
                $w = null;
                $e = null;

                if (0 === (int)$timeout) {
                    $sec = $micro = 0;
                } elseif (null === $timeout) {
                    $sec = null;
                    $micro = 0; // PHP8 fuckup :-/, fixed in PHP8.1 -> change this to null
                } else {
                    $tDiffMS = $timeout - microtime(true);
                    if ($tDiffMS < 0) $tDiffMS = 0;
                    $sec = (int)floor($tDiffMS);
                    $micro = (int)($tDiffMS * 1000 * 1000 - $sec * 1000 * 1000);
                }
                // a signal interrupting the system call may cause a warning => @
                $success = @stream_select($read, $w, $e, $sec, $micro);
                if ($success) {
                    foreach ($read as $stream) {
                        if (feof($stream)) {
                            unset($this->wpipes[(int)$stream]);
                        } else {
                            $this->wpipes[(int)$stream]->readChunk();
                        }
                    }
                }
            }

        } while(null === $timeout || microtime(true) < $timeout);

        return $this->isRunning();
    }

    protected function gracefulEnd(): void
    {
        if (!is_resource($this->procHandle)) {
            return;
        }

        // Set stream to blocking and collect the last bits of output
        foreach ($this->wpipes as $pipeDescriptor) {
            $pipeDescriptor->makeStreamBlocking();
            $pipeDescriptor->readChunk();
        }

        // as of now, we do not want to be disturbed!
        $oldAsyncVal = pcntl_async_signals(false);
        try {
            $this->closePipes();
            if (!is_resource($this->procHandle)) {
                return;
            }
            if (null === $this->exitCode) {
                $status = proc_get_status($this->procHandle);
                $this->exitCode = proc_close($this->procHandle);
                if (!$status['running'] && -1 !== $status['exitcode']) {
                    $this->exitCode = $status['exitcode'];
                }
            } else {
                proc_close($this->procHandle);
            }

            $this->procClosed = microtime(true);
            $this->procHandle = null;
        } finally {
            pcntl_async_signals($oldAsyncVal);
        }
        pcntl_signal_dispatch();
        self::setAlarm();
    }

    protected function shutdown(): void
    {
        // we do not want to be disturbed!
        $oldAsyncVal = pcntl_async_signals(false);
        try {
            $this->closePipes();
            if (is_resource($this->procHandle)) {
                if (null === $this->exitCode) {
                    proc_terminate($this->procHandle, SIGKILL);
                    $status = proc_get_status($this->procHandle);
                    $this->exitCode = proc_close($this->procHandle);
                    if (false !== $status && !$status['running'] && -1 !== $status['exitcode']) {
                        $this->exitCode = $status['exitcode'];
                    }
                } else {
                    proc_close($this->procHandle);
                }
            }
            $this->procClosed = microtime(true);
            $this->procHandle = null;
        } finally {
            pcntl_async_signals($oldAsyncVal);
        }
        pcntl_signal_dispatch();
        self::setAlarm();
    }

    protected function closePipes(): void
    {
        foreach ($this->ioDescriptors as $pipeDescriptor) {
            // TODO close all descriptors?
            if ($pipeDescriptor instanceof PipeDescriptor) {
                $pipeDescriptor->close();
            }
        }
        $this->wpipes = [];
    }

    /**
     * @var array<string> Command to exec
     */
    protected $cmd;

    /**
     * @var int Timeout in milliseconds, defaults to 0 / no timeout
     */
    protected $timeout = 0;

    /**
     * @var bool Whether to start the timeout before proc_open, or after, defaults to false / after
     */
    protected $startTimeoutBeforeProcOpen = false;

    /**
     * @var bool Whether to run as an async background tasks, defaults to false
     */
    protected $background = false;

    /**
     * @var array<PipeDescriptor> PipeDescriptors->isWPipe() === true
     */
    protected $wpipes = [];

    /**
     * @var null|false|resource
     */
    protected $procHandle = null;

    /**
     * @var null|float
     */
    protected $procStarted = null;

    /**
     * @var null|float
     */
    protected $procOpened = null;

    /**
     * @var null|float
     */
    protected $procClosed = null;

    /**
     * @var null|int
     */
    protected $exitCode = null;

    /**
     * @var array<IODescriptor>
     */
    protected $ioDescriptors = [];

    /**
     * @var \SplObjectStorage<\WeakReference<Cmd>, \WeakReference<Cmd>>
     */
    protected static ?\SplObjectStorage $objs = null;
}
