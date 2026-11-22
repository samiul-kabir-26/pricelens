<?php

namespace App\Scraping\Fetching;

final class ResolvedUrl
{
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public string $ip,
    ) {}

    /** CURLOPT_RESOLVE entry pinning the connection to the validated IP (prevents DNS rebinding). */
    public function curlResolve(): string
    {
        $ip = str_contains($this->ip, ':') ? "[{$this->ip}]" : $this->ip;

        return "{$this->host}:{$this->port}:{$ip}";
    }
}
