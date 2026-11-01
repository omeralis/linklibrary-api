<?php

namespace Tests\Support;

use App\Services\Http\DnsResolver;

/**
 * Deterministic DNS. Supports answer sequences to simulate DNS rebinding (first lookup public,
 * subsequent lookups private). Unknown hosts resolve to a public documentation-free address.
 */
class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<list<string>>> */
    private array $answers = [];

    /** @var array<string, int> */
    public array $lookups = [];

    /** @param list<string> ...$sequence */
    public function set(string $host, array ...$sequence): self
    {
        $this->answers[$host] = array_values($sequence);

        return $this;
    }

    public function resolve(string $host): array
    {
        $n = $this->lookups[$host] = ($this->lookups[$host] ?? 0) + 1;
        $sequence = $this->answers[$host] ?? [['93.184.216.34']];

        return $sequence[min($n - 1, count($sequence) - 1)];
    }
}
