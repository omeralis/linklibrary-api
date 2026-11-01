<?php

namespace Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Minimal OpenAPI 3.1 response validator for contract tests. Supports the JSON-Schema subset used in
 * docs/openapi.yaml ($ref, allOf, oneOf, type incl. null, enum, const, properties, required, items).
 * It also reports keys returned by the API that the schema does not document, so the published
 * contract cannot silently drift from the implementation.
 */
class OpenApiValidator
{
    /** @var array<string, mixed> */
    private array $spec;

    /** @var list<string> */
    private array $errors = [];

    public function __construct(string $file)
    {
        $this->spec = Yaml::parseFile($file);
    }

    /**
     * @return list<string> problems (empty when the payload matches)
     */
    public function validateResponse(string $method, string $path, int $status, mixed $payload): array
    {
        $this->errors = [];
        $operation = $this->spec['paths'][$path][strtolower($method)] ?? null;
        if ($operation === null) {
            return ["operation {$method} {$path} is not documented"];
        }
        $response = $operation['responses'][(string) $status] ?? null;
        if ($response === null) {
            return ["status {$status} is not documented for {$method} {$path}"];
        }
        if (isset($response['$ref'])) {
            $response = $this->resolve($response['$ref']);
        }
        $schema = $response['content']['application/json']['schema'] ?? null;
        if ($schema === null) {
            return [];
        }
        $this->check($payload, $schema, '$');

        return $this->errors;
    }

    /** @return array<string, mixed> */
    private function resolve(string $ref): array
    {
        $node = $this->spec;
        foreach (explode('/', substr($ref, 2)) as $part) {
            $node = $node[$part];
        }

        return $node;
    }

    /** @param array<string, mixed> $schema */
    private function check(mixed $value, array $schema, string $at): void
    {
        if ($schema === []) {
            return; // any
        }
        if (isset($schema['$ref'])) {
            $this->check($value, $this->resolve($schema['$ref']), $at);

            return;
        }
        if (isset($schema['allOf'])) {
            $merged = $this->mergeAllOf($schema['allOf']);
            $this->check($value, $merged, $at);

            return;
        }
        if (isset($schema['oneOf'])) {
            $saved = $this->errors;
            foreach ($schema['oneOf'] as $option) {
                $this->errors = [];
                $this->check($value, $option, $at);
                if ($this->errors === []) {
                    $this->errors = $saved;

                    return;
                }
            }
            $this->errors = [...$saved, "{$at}: matches none of oneOf"];

            return;
        }
        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $this->errors[] = "{$at}: expected const ".json_encode($schema['const']).', got '.json_encode($value);
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $this->errors[] = "{$at}: ".json_encode($value).' not in enum';
        }
        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            if (! $this->typeMatches($value, $types)) {
                $this->errors[] = "{$at}: expected ".implode('|', $types).', got '.get_debug_type($value);

                return;
            }
        }
        if (is_array($value) && array_is_list($value) && isset($schema['items']) && ! isset($schema['properties'])) {
            foreach ($value as $i => $item) {
                $this->check($item, $schema['items'], "{$at}[{$i}]");
            }
        }
        if (is_array($value) && (! array_is_list($value) || $value === []) && isset($schema['properties'])) {
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists($required, $value)) {
                    $this->errors[] = "{$at}: missing required '{$required}'";
                }
            }
            foreach ($value as $key => $child) {
                if (! array_key_exists($key, $schema['properties'])) {
                    $this->errors[] = "{$at}.{$key}: returned but not documented";

                    continue;
                }
                $this->check($child, $schema['properties'][$key], "{$at}.{$key}");
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     * @return array<string, mixed>
     */
    private function mergeAllOf(array $parts): array
    {
        $merged = ['type' => 'object', 'properties' => [], 'required' => []];
        foreach ($parts as $part) {
            $part = isset($part['$ref']) ? $this->resolve($part['$ref']) : $part;
            if (isset($part['allOf'])) {
                $part = $this->mergeAllOf($part['allOf']);
            }
            foreach ($part['properties'] ?? [] as $k => $v) {
                // later (more specific) definitions win, e.g. Envelope.data → concrete schema
                $merged['properties'][$k] = $v;
            }
            $merged['required'] = array_values(array_unique([...$merged['required'], ...($part['required'] ?? [])]));
        }

        return $merged;
    }

    /** @param list<string> $types */
    private function typeMatches(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'null' => $value === null,
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && (! array_is_list($value) || $value === []),
                default => true,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
