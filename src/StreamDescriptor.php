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
