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

class FileDescriptor extends IODescriptor
{
    public function __construct(int $descriptorNumber, string $fileName)
    {
        $this->fileName = $fileName;

        parent::__construct($descriptorNumber);
    }

    /**
     * @return array<string>
     */
    public function getDescription()
    {
        return ['file', $this->fileName];
    }

    /**
     * @var string
     */
    protected $fileName;
}
