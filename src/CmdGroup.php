<?php

declare(strict_types=1);

namespace Tine20\ProcWrap;

class CmdGroup
{
    public function setNumProcesses(int $value): self
    {
        if ($value < 1) throw new CmdException('numProc can\'t be less than 1');
        $this->numProc = $value;
        return $this;
    }

    public function addCmd(Cmd $cmd): self
    {
        $this->cmds[] = $cmd;
        return $this;
    }

    public function recycle(): self
    {
        foreach ($this->cmds as $cmd) $cmd->recycle();
        $this->isRunning = false;

        return $this;
    }

    public function runInBackground(bool $value = true): self
    {
        if ($this->isRunning) {
            throw new CmdException('CmdGroup is already running');
        }

        $this->background = $value;
        return $this;
    }

    public function exec()
    {
        if ($this->isRunning) {
            throw new CmdException('CmdGroup is already running, use recycle to reset cmd groupd before exec');
        }

        $this->isRunning = true;
        $this->queue = $this->cmds;
        reset($this->queue);
        $this->startProcesses();

        if ($this->background) {
            return;
        }

        do {
            $streams = [];
            $w = [];
            $e = [];
            $cmds = [];
            $minTimeout = PHP_FLOAT_MAX;
            foreach ($this->proccesses as $cmd) {
                $minTimeout = min($minTimeout, $cmd->getTimeoutTS());
                foreach ($cmd->getIODescriptors() as $descriptor) {
                    if ($descriptor instanceof PipeDescriptor && $descriptor->isWPipe() && !feof($stream = $descriptor->getStream())) {
                        $cmds[$stream] = $cmd;
                        $streams[] = $stream;
                    }
                }
            }

            /*/ do signal dispatching before and after select to minimize chances to delay a signal
            if ($this->doSignalDispatch) {
                pcntl_signal_dispatch();
            }*/
            // a signal interrupting the system call may cause a warning => @
            // TODO is the timeout = float_max case a 32 bit issue?
            $success = @stream_select($streams, $w, $e, 0, max(0, (int)(($minTimeout - microtime(true)) * 1000 * 1000))); /** @phpstan-ignore-line */
            /*if ($this->doSignalDispatch) {
                pcntl_signal_dispatch();
            }*/

            if ($success) {
                foreach ($streams as $stream) {
                    $cmd = $cmds[$stream];
                    if (!$cmd->poll()) {
                        unset($this->proccesses[array_search($cmd, $this->proccesses, true)]);
                    }
                }
            }

            $this->startProcesses();
        } while (!empty($this->proccesses));
    }

    public function poll(): bool
    {
        if (!$this->isRunning) {
            throw new CmdException('CmdGroup is not running, can\'t poll');
        }

        foreach ($this->proccesses as $key => $cmd) {
            if (!$cmd->poll()) {
                unset($this->proccesses[$key]);
            }
        }
        $this->startProcesses();

        if (empty($this->proccesses)) {
            return false;
        }
        return true;
    }

    protected function startProcesses()
    {
        while (count($this->proccesses) < $this->numProc && current($this->queue)) {
            $cmd = array_shift($this->queue);
            $cmd->runInBackground(true);
            $cmd->exec();
            $this->proccesses[] = $cmd;
        }
    }

    /**
     * @var array<Cmd>
     */
    protected $cmds = [];

    /**
     * @var array<Cmd>
     */
    protected $queue = [];

    /**
     * @var array<Cmd>
     */
    protected $proccesses = [];

    /**
     * @var int
     */
    protected $numProc = PHP_INT_MAX;

    /**
     * @var bool
     */
    protected $background = false;

    /**
     * @var bool
     */
    protected $isRunning = false;
}
