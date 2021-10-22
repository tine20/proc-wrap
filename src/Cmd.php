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

class Cmd
{
    public const STDIN  = 0;
    public const STDOUT = 1;
    public const STDERR = 2;

    /**
     * Cmd constructor.
     *
     * This is a single cmd, passing and array is recommended and will assemble one command, not multiple
     *
     * be aware of the implications of using a string here!
     * killing a child process is not possible if no 'exec [cmd]' is used.
     * string will not be escaped
     * in doubt, use an array as is recommended
     *
     * arrays will use exec and be escaped, arrays on php < 7.4 emulate the php7.4+ behavior
     *
     * TODO as of PHP 7.4, clean up this code
     *
     * @param string|array<string> $cmd Command to exec
     * @param array<IODescriptor> $ioDescriptors
     */
    public function __construct($cmd, array $ioDescriptors = []) /** TODO as of PHP 8 replace mixed with string|array */
    {
        if (is_array($cmd)) {
            if (empty($cmd)) {
                throw new CmdException('$cmd needs to be a non-empty array or string');
            }
            // As of PHP 7.4.0, cmd may be passed as array of command parameters.
            // the array will be escaped by php
            // the array will behave like an 'exec ...'
            if (PHP_VERSION_ID < 70400) {
                $assembledCmd = 'exec ' . escapeshellcmd(array_shift($cmd));
                foreach ($cmd as $c) {
                    $assembledCmd .= ' ' . escapeshellarg($c);
                }
                $cmd = $assembledCmd;
            }
        } elseif (!is_string($cmd)) {
            throw new CmdException('$cmd needs to be a non-empty array or string');
        }

        $this->cmd = $cmd;
        $this->ioDescriptors = $ioDescriptors ?: [
            self::STDIN  => new PipeDescriptor(self::STDIN,  'r'),
            self::STDOUT => new PipeDescriptor(self::STDOUT, 'w'),
            self::STDERR => new PipeDescriptor(self::STDERR, 'w'),
        ];

        if (!extension_loaded('pcntl')) {
            $this->doSignalDispatch = false;
            if (!defined('SIGKILL')) {
                define('SIGKILL', 9);
            }
        }
    }

    /**
     * scoped object, will shutdown down cleanly on destruct
     */
    public function __destruct()
    {
        if ($this->isRunning()) {
            $this->shutdown();
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
            throw new CmdException('Cmd may only be executed once. Use recycle() before reusing this object');
        }

        $descriptor_spec = [];
        foreach ($this->ioDescriptors as $descriptorNumber => $ioDescriptor) {
            $descriptor_spec[$descriptorNumber] = $ioDescriptor->getDescription();
        }

        $this->procStarted = microtime(true);

        $pipes = [];
        $this->procHandle =
            proc_open($this->cmd, /** @phpstan-ignore-line */ // TODO as of PHP 7.4 remove phpstan ignore
                $descriptor_spec, $pipes);

        if (!is_resource($this->procHandle)) {
            throw new CmdException('proc_open failed');
        }

        $this->procOpened = microtime(true);

        // set pipes to none blocking
        try {
            foreach ($pipes as $descriptorNumber => $stream) {
                $pipeDescriptor = $this->ioDescriptors[$descriptorNumber];
                assert($pipeDescriptor instanceof PipeDescriptor);
                $pipeDescriptor->setStream($stream);
                if ($pipeDescriptor->isWPipe()) {
                    $this->wpipes[$stream] = $pipeDescriptor;
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
            $this->internalPoll(PHP_FLOAT_MAX);
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
        if (!$this->internalPoll(0)) {
            $this->gracefulEnd();
            return false;
        }

        if ($this->timeout > 0 && $this->timeout -
                (microtime(true) - ($this->startTimeoutBeforeProcOpen ? $this->procStarted : $this->procOpened)) * 1000 < 1) {
            $this->timeoutProcedure();
            return false;
        }

        return true;
    }

    public function isRunning(): bool
    {
        if (!is_resource($this->procHandle)) {
            return false;
        }

        if (false === ($status = proc_get_status($this->procHandle))) {
            $this->shutdown();
            throw new CmdException('failed to read process status');
        }
        if (!$status['running']) {
            if (null === $this->exitCode) {
                $this->exitCode = $status['exitcode'];
            }
            return false;
        }

        return true;
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
     * @param float $timeout
     * @return bool
     * @throws CmdException
     */
    protected function internalPoll(float $timeout): bool
    {
        do {
            if (!$this->isRunning()) {
                return false;
            }
            $read = [];
            foreach ($this->wpipes as $pipeDescriptor) {
                $read[] = $pipeDescriptor->getStream();
            }
            if (empty($read)) {
                usleep(1000); // sleep 1 ms to save cpu time, wait for isRunning() to fail or timeout to occur
            } else {
                $w = null;
                $e = null;

                // do signal dispatching before and after select to minimize chances to delay a signal
                if ($timeout > 1 && $this->doSignalDispatch) {
                    pcntl_signal_dispatch();
                }
                // a signal interrupting the system call may cause a warning => @
                // TODO is the timeout ~= 0 case a 32 bit issue? result is around -1621330856827896
                $success = @stream_select($read, $w, $e, 0, max(0, (int)(($timeout - microtime(true)) * 1000 * 1000))); /** @phpstan-ignore-line */
                if ($timeout > 1 && $this->doSignalDispatch) {
                    pcntl_signal_dispatch();
                }

                if ($success) {
                    foreach ($read as $stream) {
                        if (feof($stream)) { /** @phpstan-ignore-line */
                            unset($this->wpipes[$stream]); /** @phpstan-ignore-line */
                        } else {
                            $this->wpipes[$stream]->readChunk(); /** @phpstan-ignore-line */
                        }
                    }
                }
            }

        } while(microtime(true) < $timeout);

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
        $this->closePipes();
        if (null === $this->exitCode) {
            $this->isRunning();
        }
        proc_close($this->procHandle); /** @phpstan-ignore-line */

        $this->procClosed = microtime(true);
        $this->procHandle = null;
    }

    protected function shutdown(): void
    {
        $this->closePipes();
        if (is_resource($this->procHandle)) {
            proc_terminate($this->procHandle, SIGKILL);
            $status = proc_get_status($this->procHandle);
            $this->exitCode = proc_close($this->procHandle);
            if (false !== $status && !$status['running']) {
                $this->exitCode = $status['exitcode'];
            }
        }
        $this->procClosed = microtime(true);
        $this->procHandle = null;
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
     * @var string|array<string> Command to exec
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
     * @var bool Whether to do signal dispatches after stream select returns in synchronous mode only
     */
    protected $doSignalDispatch = true;

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
}
