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

abstract class IODescriptor
{
    /**
     * @return array<string>|resource
     */
    public abstract function getDescription(); /** TODO PHP8 add return value array|resource */

    public function getDescriptorNumber(): int
    {
        return $this->number;
    }

    protected function __construct(int $descriptorNumber) /** TODO PHP8 add return type void */
    {
        $this->number = $descriptorNumber;
    }

    /**
     * @var int
     */
    protected $number;
}
