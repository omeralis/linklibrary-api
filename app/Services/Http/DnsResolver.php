<?php

namespace App\Services\Http;

interface DnsResolver
{
    /**
     * Resolve a hostname to all of its A and AAAA addresses.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
