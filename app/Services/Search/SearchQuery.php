<?php

namespace App\Services\Search;

use App\Support\ArabicNormalizer;

/**
 * Turns user input into safe PostgreSQL tsquery parameters. Only letters, digits, '-' and '_' survive;
 * the number of terms is bounded to keep queries cheap.
 */
final class SearchQuery
{
    /** @param list<string> $terms */
    private function __construct(public readonly string $normalized, public readonly array $terms) {}

    public static function parse(string $input, int $maxTerms = 12): ?self
    {
        $normalized = ArabicNormalizer::normalize(trim($input));
        preg_match_all('/[\p{L}\p{N}_]+(?:-[\p{L}\p{N}_]+)*/u', $normalized, $m);
        $terms = array_values(array_unique(array_filter($m[0], fn ($t) => mb_strlen($t) >= 1)));
        $terms = array_slice($terms, 0, $maxTerms);

        if ($terms === []) {
            return null;
        }

        return new self(implode(' ', $terms), $terms);
    }

    /** Prefix query for the 'simple' configuration, e.g. "lara:* & test:*". */
    public function prefixTsquery(): string
    {
        return implode(' & ', array_map(fn ($t) => str_replace(['-', "'", '\\'], [' & ', '', ''], $t).':*', $this->terms));
    }

    /**
     * SQL fragment matching a tsvector column; returns [sql, bindings].
     *
     * @return array{0: string, 1: list<string>}
     */
    public function matchSql(string $column): array
    {
        return [
            "({$column} @@ to_tsquery('simple', ?) OR {$column} @@ plainto_tsquery('english', ?) OR {$column} @@ plainto_tsquery('arabic', ?))",
            [$this->prefixTsquery(), $this->normalized, $this->normalized],
        ];
    }

    /** @return array{0: string, 1: list<string>} */
    public function rankSql(string $column): array
    {
        return [
            "ts_rank({$column}, to_tsquery('simple', ?) || plainto_tsquery('english', ?) || plainto_tsquery('arabic', ?))",
            [$this->prefixTsquery(), $this->normalized, $this->normalized],
        ];
    }
}
