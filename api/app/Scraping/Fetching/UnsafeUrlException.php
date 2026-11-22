<?php

namespace App\Scraping\Fetching;

use RuntimeException;

class UnsafeUrlException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
