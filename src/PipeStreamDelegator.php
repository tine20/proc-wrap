<?php

declare(strict_types=1);

namespace Tine20\ProcWrap;

class PipeStreamDelegator implements PipeStreamDelegatorInterface
{
    public function __construct(bool $isBlocking)
    {
        $this->isBlocking = $isBlocking;
    }

    /**
     * @return null|resource
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * @param resource $stream
     * @return void
     */
    public function setStream($stream)
    {
        if (!$this->isBlocking && !stream_set_blocking($stream, false)) {
            throw new CmdException('failed to set stream to none blocking');
        }

        $this->stream = $stream;
        $this->data = '';
    }

    /**
     * @return void
     */
    public function readChunk()
    {
        if (false !== ($chunk = stream_get_contents($this->stream))) { /** @phpstan-ignore-line */
            $this->data .= $chunk;
        }
    }

    public function getData(): string
    {
        return $this->data;
    }

    /**
     * @return void
     */
    public function makeStreamBlocking()
    {
        stream_set_blocking($this->stream, true); /** @phpstan-ignore-line */
    }

    /**
     * @return void
     */
    public function close()
    {
        if ($this->stream)
            fclose($this->stream);
    }

    /**
     * @var string
     */
    protected $data = '';

    /**
     * @var resource|null
     */
    protected $stream = null;

    /**
     * @var bool
     */
    protected $isBlocking;
}
