<?php

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
