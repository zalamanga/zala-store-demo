<?php
declare(strict_types=1);

namespace ZalaStore;

final class Problem extends \RuntimeException
{
    public function __construct(public int $status, string $message)
    {
        parent::__construct($message);
    }
}
