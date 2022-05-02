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

class PipeStreamDelegatorClosure extends PipeStreamDelegator
{
    /**
     * @param bool $isBlocking
     * @param \Closure $closure
     */
    public function __construct(bool $isBlocking, \Closure $closure)
    {
        parent::__construct($isBlocking);
        $this->closure = $closure;
    }

    /**
     * @return void
     */
    public function readChunk()
    {
        if (false !== ($chunk = stream_get_contents($this->stream))) { /** @phpstan-ignore-line */
            $this->data = ($this->closure)($this->data, $chunk);
        }
    }

    /**
     * @var \Closure
     */
    protected $closure;
}
