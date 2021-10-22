<?php

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
