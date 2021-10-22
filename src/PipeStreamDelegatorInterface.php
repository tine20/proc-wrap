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

interface PipeStreamDelegatorInterface
{
    /**
     * @return null|resource
     */
    public function getStream();
    /**
     * @param resource $stream
     * @return void
     */
    public function setStream($stream);
    /**
     * @return void
     */
    public function readChunk();
    public function getData(): string;
    /**
     * @return void
     */
    public function makeStreamBlocking();
    /**
     * @return void
     */
    public function close();
}
