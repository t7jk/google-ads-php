<?php
declare(strict_types=1);

namespace App\Plans;

/**
 * Lightweight validation of tool arguments against JSON Schema (replaces zod validation from Node). Supports the subset used
 * by the plan tools: type, enum, required, properties, additionalProperties(false|schema), propertyNames.enum, items, anyOf,
 * minLength, maxLength, const, pattern, minimum/maximum, minItems/maxItems. Returns a list of messages (empty = valid).
 */
final class SchemaCheck
{
    /** @return list<string> */
    public static function validate(array $schema, mixed $value, string $path = ''): array
    {
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $alt) {
                if (!self::validate($alt, $value, $path)) {
                    return [];
                }
            }
            return [self::at($path) . 'does not match any of the allowed shapes'];
        }
        $type = $schema['type'] ?? null;
        $err = self::checkType($type, $value, $path);
        if ($err !== null) {
            return [$err];
        }
        $errors = [];
        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = self::at($path) . 'must have the value ' . json_encode($schema['const'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = self::at($path) . 'must be one of: ' . implode(', ', $schema['enum']);
        }
        if (is_string($value)) {
            array_push($errors, ...self::checkString($schema, $value, $path));
        }
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = self::at($path) . "must be at least {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = self::at($path) . "must be at most {$schema['maximum']}";
            }
        }
        if ($type === 'array') {
            array_push($errors, ...self::checkArray($schema, $value, $path));
        }
        if ($type === 'object') {
            array_push($errors, ...self::checkObject($schema, (array) $value, $path));
        }
        return $errors;
    }

    private static function at(string $path): string
    {
        return $path === '' ? 'Parametry: ' : "$path: ";
    }

    private static function checkType(?string $type, mixed $v, string $path): ?string
    {
        $ok = match ($type) {
            'string' => is_string($v), 'boolean' => is_bool($v), 'number' => is_int($v) || is_float($v),
            'integer' => is_int($v) || (is_float($v) && floor($v) === $v),
            'array' => is_array($v) && array_is_list($v), 'object' => is_array($v) || $v instanceof \stdClass,
            default => true,
        };
        return $ok ? null : self::at($path) . match ($type) {
            'string' => 'must be text', 'boolean' => 'must be true or false', 'number', 'integer' => 'must be a number',
            'array' => 'must be a list', default => 'must be an object',
        };
    }

    private static function checkString(array $s, string $v, string $path): array
    {
        $e = [];
        if (isset($s['minLength']) && mb_strlen($v) < $s['minLength']) {
            $e[] = self::at($path) . 'must not be empty';
        }
        if (isset($s['maxLength']) && mb_strlen($v) > $s['maxLength']) {
            $e[] = self::at($path) . "must be at most {$s['maxLength']} characters";
        }
        if (isset($s['pattern']) && !preg_match('/' . str_replace('/', '\/', $s['pattern']) . '/u', $v)) {
            $e[] = self::at($path) . 'has the wrong format';
        }
        return $e;
    }

    private static function checkArray(array $s, array $v, string $path): array
    {
        $e = [];
        if (isset($s['minItems']) && count($v) < $s['minItems']) {
            $e[] = self::at($path) . "must have at least {$s['minItems']} items";
        }
        if (isset($s['maxItems']) && count($v) > $s['maxItems']) {
            $e[] = self::at($path) . "must have at most {$s['maxItems']} items";
        }
        foreach ($v as $i => $item) {
            if (isset($s['items'])) {
                array_push($e, ...self::validate($s['items'], $item, "$path" . "[$i]"));
            }
        }
        return $e;
    }

    private static function checkObject(array $s, array $v, string $path): array
    {
        $e = [];
        $props = $s['properties'] ?? [];
        foreach ($s['required'] ?? [] as $r) {
            if (!array_key_exists($r, $v)) {
                $e[] = self::at($path) . "missing required field $r";
            }
        }
        foreach ($v as $k => $x) {
            $sub = $path === '' ? (string) $k : "$path.$k";
            if (isset($props[$k])) {
                array_push($e, ...self::validate($props[$k], $x, $sub));
            } elseif (($s['additionalProperties'] ?? null) === false) {
                $e[] = self::at($path) . "unknown field $k";
            } elseif (is_array($s['additionalProperties'] ?? null)) {
                if (isset($s['propertyNames']['enum']) && !in_array($k, $s['propertyNames']['enum'], true)) {
                    $e[] = self::at($path) . "disallowed key $k";
                } else {
                    array_push($e, ...self::validate($s['additionalProperties'], $x, $sub));
                }
            }
        }
        return $e;
    }
}
