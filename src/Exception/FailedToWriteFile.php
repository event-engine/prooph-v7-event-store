<?php
declare(strict_types=1);

namespace EventEngine\Prooph\V7\EventStore\Exception;

final class FailedToWriteFile extends \RuntimeException
{
    public static function with(string $filename, string $msg): self
    {
        return new self("Failed to write file $filename. $msg");
    }
}
