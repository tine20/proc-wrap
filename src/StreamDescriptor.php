<?php

declare(strict_types=1);

namespace Tine20\ProcWrap;

class StreamDescriptor extends IODescriptor
{
    /**
     * StreamDescriptor constructor.
     *
     * be aware that this resource will not be closed on Cmd termination
     *
     * @param int $descriptorNumber
     * @param resource $stream
     * @throws CmdException
     */
    public function __construct(int $descriptorNumber, $stream)
    {
        if (!is_resource($stream)) {
            throw new CmdException('parameter stream needs to be a valid resource');
        }
        $this->stream = $stream;

        parent::__construct($descriptorNumber);
    }

    /**
     * @return resource
     */
    public function getDescription()
    {
        return $this->stream;
    }

    /**
     * @var resource
     */
    protected $stream;
}
