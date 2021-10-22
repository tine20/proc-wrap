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

class PipeDescriptor extends IODescriptor
{
    public function __construct(int $descriptorNumber, string $endToPassToProcess, PipeStreamDelegatorInterface $streamDelegator = null)
    {
        if ($endToPassToProcess !== 'r' && $endToPassToProcess !== 'w') {
            throw new \Exception('valid values are "r" or "w"');
        }
        $this->rw = $endToPassToProcess;
        if (null === $streamDelegator) {
            $this->streamDelegator = new PipeStreamDelegator(!$this->isWPipe());
        } else {
            $this->streamDelegator = $streamDelegator;
        }

        parent::__construct($descriptorNumber);
    }

    /**
     * @return array<string>
     */
    public function getDescription()
    {
        return ['pipe', $this->rw];
    }

    public function isWPipe(): bool
    {
        return $this->rw === 'w';
    }

    /**
     * @return resource|null
     */
    public function getStream()
    {
        return $this->streamDelegator->getStream();
    }

    /**
     * @param resource $stream
     * @return void
     * @throws CmdException
     */
    public function setStream($stream)
    {
        $this->streamDelegator->setStream($stream);
    }

    /**
     * @return void
     */
    public function readChunk()
    {
        $this->streamDelegator->readChunk();
    }

    public function getData(): string
    {
        return $this->streamDelegator->getData();
    }

    /**
     * @return void
     */
    public function makeStreamBlocking()
    {
        $this->streamDelegator->makeStreamBlocking();
    }

    /**
     * @return void
     */
    public function close()
    {
        $this->streamDelegator->close();
    }

    /**
     * @var string
     */
    protected $rw;

    /**
     * @var PipeStreamDelegatorInterface
     */
    protected $streamDelegator;
}
