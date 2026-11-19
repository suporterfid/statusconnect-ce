<?php

namespace App\Http\Views;

/**
 * Turns resources/api/openapi.json into plain arrays for the /api reference page (#151).
 *
 * Only the parts of OpenAPI 3.1 the document uses are understood: local $refs,
 * object/array schemas, enums, nullability as a type list, and one example per
 * request or response. The page is rendered on the server, so it needs no
 * third-party script.
 */
final class ApiReference
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /** @var array<string, mixed> */
    private array $doc;

    public function __construct(?string $path = null)
    {
        $path ??= resource_path('api/openapi.json');
        $decoded = json_decode((string) @file_get_contents($path), true);
        $this->doc = is_array($decoded) ? $decoded : [];
    }

    public static function path(): string
    {
        return resource_path('api/openapi.json');
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return $this->doc;
    }

    public function title(): string
    {
        return (string) ($this->doc['info']['title'] ?? 'API');
    }

    public function version(): string
    {
        return (string) ($this->doc['info']['version'] ?? '');
    }

    public function description(): string
    {
        return (string) ($this->doc['info']['description'] ?? '');
    }

    public function baseUrl(): string
    {
        return rtrim((string) ($this->doc['servers'][0]['url'] ?? config('app.url')), '/');
    }

    public function securityDescription(): string
    {
        return (string) ($this->doc['components']['securitySchemes']['apiKey']['description'] ?? '');
    }

    /**
     * Tags in document order, each with its operations, skipping empty tags.
     *
     * @return list<array{name: string, slug: string, description: string, operations: list<array<string, mixed>>}>
     */
    public function sections(): array
    {
        $byTag = [];
        foreach ($this->doc['paths'] ?? [] as $path => $item) {
            foreach (self::METHODS as $method) {
                if (! isset($item[$method]) || ! is_array($item[$method])) {
                    continue;
                }
                $operation = $this->operation((string) $path, $method, $item[$method], $item['parameters'] ?? []);
                $byTag[$operation['tag']][] = $operation;
            }
        }

        $sections = [];
        $seen = [];
        foreach ($this->doc['tags'] ?? [] as $tag) {
            $name = (string) ($tag['name'] ?? '');
            if ($name === '' || ! isset($byTag[$name])) {
                continue;
            }
            $seen[$name] = true;
            $sections[] = ['name' => $name, 'slug' => self::slug($name), 'description' => (string) ($tag['description'] ?? ''), 'operations' => $byTag[$name]];
        }
        foreach ($byTag as $name => $operations) {
            if (! isset($seen[$name])) {
                $sections[] = ['name' => (string) $name, 'slug' => self::slug((string) $name), 'description' => '', 'operations' => $operations];
            }
        }

        return $sections;
    }

    /**
     * Every documented "METHOD /path" pair, for the route coverage test.
     *
     * @return list<string>
     */
    public function operationKeys(): array
    {
        $keys = [];
        foreach ($this->doc['paths'] ?? [] as $path => $item) {
            foreach (self::METHODS as $method) {
                if (isset($item[$method])) {
                    $keys[] = strtoupper($method).' '.$path;
                }
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $op
     * @param  list<array<string, mixed>>  $shared
     * @return array<string, mixed>
     */
    private function operation(string $path, string $method, array $op, array $shared): array
    {
        $params = [];
        foreach (array_merge($shared, $op['parameters'] ?? []) as $param) {
            $param = $this->resolve($param);
            $schema = $this->resolve($param['schema'] ?? []);
            $params[] = [
                'name' => (string) ($param['name'] ?? ''),
                'in' => (string) ($param['in'] ?? ''),
                'required' => (bool) ($param['required'] ?? false),
                'type' => $this->typeLabel($schema),
                'description' => (string) ($param['description'] ?? ''),
                'constraints' => $this->constraints($schema),
            ];
        }

        $body = null;
        if (isset($op['requestBody'])) {
            $requestBody = $this->resolve($op['requestBody']);
            $mediaType = (string) array_key_first($requestBody['content'] ?? []);
            $media = $requestBody['content'][$mediaType] ?? [];
            $schema = $this->resolve($media['schema'] ?? []);
            $body = [
                'mediaType' => $mediaType,
                'required' => (bool) ($requestBody['required'] ?? false),
                'description' => (string) ($requestBody['description'] ?? ''),
                'fields' => $this->fields($schema),
                'example' => $this->example($media),
            ];
        }

        $responses = [];
        foreach ($op['responses'] ?? [] as $code => $response) {
            $response = $this->resolve($response);
            $mediaType = (string) (array_key_first($response['content'] ?? []) ?? '');
            $media = $response['content'][$mediaType] ?? [];
            $responses[] = [
                'code' => (string) $code,
                'description' => (string) ($response['description'] ?? ''),
                'example' => $media === [] ? null : $this->example($media),
            ];
        }
        $success = null;
        foreach ($responses as $response) {
            if ($response['code'][0] === '2') {
                $success = $response;
                break;
            }
        }

        $public = isset($op['security']) && $op['security'] === [];

        return [
            'id' => (string) ($op['operationId'] ?? strtolower($method).'-'.self::slug($path)),
            'method' => strtoupper($method),
            'path' => $path,
            'tag' => (string) ($op['tags'][0] ?? 'Outros'),
            'summary' => (string) ($op['summary'] ?? ''),
            'description' => (string) ($op['description'] ?? ''),
            'deprecated' => (bool) ($op['deprecated'] ?? false),
            'public' => $public,
            'roles' => array_values(array_map('strval', $op['x-roles'] ?? [])),
            // #154: API key permission the endpoint needs; null when keys are refused (session only).
            'apiKeyPermission' => array_key_exists('x-api-key-permission', $op) ? ($op['x-api-key-permission'] === null ? false : (string) $op['x-api-key-permission']) : null,
            'rateLimit' => isset($op['x-rate-limit']) ? (string) $op['x-rate-limit'] : null,
            'params' => $params,
            'body' => $body,
            'responses' => $responses,
            'success' => $success,
            'curl' => $this->curl(strtoupper($method), $path, $public, $body),
        ];
    }

    /**
     * Flattens an object schema into rows; nested objects and arrays of objects
     * become dotted names (channel.config.url, rules[].metric).
     *
     * @param  array<string, mixed>  $schema
     * @return list<array{name: string, type: string, required: bool, description: string, constraints: list<string>}>
     */
    private function fields(array $schema, string $prefix = '', int $depth = 0): array
    {
        $schema = $this->merged($schema);
        $rows = [];
        $required = array_flip(array_map('strval', $schema['required'] ?? []));
        foreach ($schema['properties'] ?? [] as $name => $property) {
            $property = $this->merged($this->resolve($property));
            $full = $prefix.$name;
            $rows[] = [
                'name' => $full,
                'type' => $this->typeLabel($property),
                'required' => isset($required[$name]),
                'description' => (string) ($property['description'] ?? ''),
                'constraints' => $this->constraints($property),
            ];
            if ($depth >= 3) {
                continue;
            }
            if (isset($property['properties'])) {
                array_push($rows, ...$this->fields($property, $full.'.', $depth + 1));
            } elseif (isset($property['items'])) {
                $items = $this->merged($this->resolve($property['items']));
                if (isset($items['properties'])) {
                    array_push($rows, ...$this->fields($items, $full.'[].', $depth + 1));
                }
            }
        }

        return $rows;
    }

    /**
     * allOf members merged into one schema; oneOf/anyOf keep their first object variant's properties.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function merged(array $schema): array
    {
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $merged = $schema;
            unset($merged['allOf']);
            foreach ($schema['allOf'] as $part) {
                $part = $this->merged($this->resolve($part));
                $merged['properties'] = array_merge($merged['properties'] ?? [], $part['properties'] ?? []);
                $merged['required'] = array_merge($merged['required'] ?? [], $part['required'] ?? []);
                $merged['type'] ??= $part['type'] ?? null;
            }

            return $merged;
        }
        foreach (['oneOf', 'anyOf'] as $key) {
            if (isset($schema[$key]) && ! isset($schema['properties'])) {
                foreach ($schema[$key] as $variant) {
                    $variant = $this->resolve($variant);
                    if (isset($variant['properties'])) {
                        return $variant + ['description' => $schema['description'] ?? ''];
                    }
                }
            }
        }

        return $schema;
    }

    /** @param array<string, mixed> $schema */
    private function typeLabel(array $schema): string
    {
        $schema = $this->merged($schema);
        $types = $schema['type'] ?? null;
        if ($types === null) {
            foreach (['oneOf', 'anyOf'] as $key) {
                if (isset($schema[$key])) {
                    $labels = array_map(fn ($s) => $this->typeLabel($this->resolve($s)), $schema[$key]);

                    return implode(' | ', array_unique($labels));
                }
            }

            return isset($schema['properties']) ? 'object' : 'any';
        }
        $types = (array) $types;
        $labels = [];
        foreach ($types as $type) {
            if ($type === 'array') {
                $items = $this->resolve($schema['items'] ?? []);
                $labels[] = $this->typeLabel($items).'[]';
            } else {
                $labels[] = (string) $type;
            }
        }
        $label = implode(' | ', $labels);
        if (isset($schema['format'])) {
            $label .= ' ('.$schema['format'].')';
        }

        return $label;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function constraints(array $schema): array
    {
        $out = [];
        if (isset($schema['enum'])) {
            $out[] = 'um de: '.implode(', ', array_map(fn ($v) => $v === null ? 'null' : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v), $schema['enum']));
        }
        foreach (['minimum' => 'mín.', 'maximum' => 'máx.', 'minLength' => 'mín.', 'maxLength' => 'máx.', 'minItems' => 'mín.', 'maxItems' => 'máx.'] as $key => $label) {
            if (isset($schema[$key])) {
                $unit = str_contains($key, 'Length') ? ' caracteres' : (str_contains($key, 'Items') ? ' itens' : '');
                $out[] = $label.' '.$schema[$key].$unit;
            }
        }
        if (array_key_exists('default', $schema)) {
            $default = $schema['default'];
            $out[] = 'padrão: '.(is_bool($default) ? ($default ? 'true' : 'false') : (is_scalar($default) ? (string) $default : json_encode($default)));
        }
        if (isset($schema['pattern'])) {
            $out[] = 'formato: '.$schema['pattern'];
        }

        return $out;
    }

    /** @param array<string, mixed> $media */
    private function example(array $media): ?string
    {
        if (array_key_exists('example', $media)) {
            $value = $media['example'];
        } elseif (isset($media['examples']) && is_array($media['examples'])) {
            $first = $this->resolve((array) reset($media['examples']));
            $value = $first['value'] ?? null;
        } else {
            $schema = $this->resolve($media['schema'] ?? []);
            if (! array_key_exists('example', $schema)) {
                return null;
            }
            $value = $schema['example'];
        }

        return is_string($value) ? $value : (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed>|null $body */
    private function curl(string $method, string $path, bool $public, ?array $body): string
    {
        $lines = ['curl -X '.$method." '".$this->baseUrl().$path."'"];
        if (! $public) {
            $lines[] = "  -H 'Authorization: Bearer sc_…'";
        }
        if ($body !== null) {
            $type = $body['mediaType'] ?: 'application/json';
            if ($type === 'multipart/form-data') {
                foreach ($body['fields'] as $field) {
                    $lines[] = "  -F '".$field['name'].'=@arquivo'."'";
                }
            } else {
                $lines[] = "  -H 'Content-Type: ".$type."'";
                if ($body['example'] !== null) {
                    $lines[] = "  --data '".str_replace("'", "'\\''", $this->compact($body['example'], $type))."'";
                }
            }
        } elseif (in_array($method, ['GET', 'DELETE'], true) && ! $public) {
            $lines[] = "  -H 'Accept: application/json'";
        }

        return implode(" \\\n", $lines);
    }

    private function compact(string $example, string $type): string
    {
        if (str_contains($type, 'json')) {
            $decoded = json_decode($example, true);
            if ($decoded !== null) {
                return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        return $example;
    }

    /**
     * Follows local "#/..." references, a few levels deep.
     *
     * @param  mixed  $node
     * @return array<string, mixed>
     */
    private function resolve(mixed $node, int $depth = 0): array
    {
        if (! is_array($node)) {
            return [];
        }
        if (isset($node['$ref']) && is_string($node['$ref']) && str_starts_with($node['$ref'], '#/') && $depth < 8) {
            $target = $this->doc;
            foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                if (! is_array($target) || ! array_key_exists($segment, $target)) {
                    return [];
                }
                $target = $target[$segment];
            }
            $extra = $node;
            unset($extra['$ref']);

            return $extra + $this->resolve($target, $depth + 1);
        }

        return $node;
    }

    /**
     * Escapes description text and turns `code` and **bold** spans into HTML.
     * Nothing else in the document is trusted as markup.
     */
    public static function inline(string $text): string
    {
        $html = e($text);
        $html = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);

        return (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html);
    }

    public static function slug(string $text): string
    {
        $slug = \Illuminate\Support\Str::slug($text);

        return $slug === '' ? 'secao' : $slug;
    }
}
