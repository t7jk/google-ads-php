<?php
declare(strict_types=1);

namespace App\Lib;

/**
 * MCP tool registry: loads all app/Tools/*.php (each file returns an array of definitions; the contract is in CLAUDE.md).
 * Adding a tool group = a new file in Tools/, no edits to shared files.
 */
final class ToolRegistry
{
    /** @var array<string, array>|null */
    private static ?array $tools = null;

    /** @return array<string, array> name => definition (with 'handler') */
    public static function all(): array
    {
        if (self::$tools === null) {
            self::$tools = [];
            $files = glob(APP_PATH . '/Tools/*.php') ?: [];
            sort($files);
            foreach ($files as $file) {
                foreach ((array) require $file as $tool) {
                    self::$tools[$tool['name']] = $tool;
                }
            }
        }
        return self::$tools;
    }

    public static function find(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    /** Definitions for tools/list: without 'handler'; an empty "properties" must be a JSON object, not []. */
    public static function listing(): array
    {
        $out = [];
        foreach (self::all() as $tool) {
            unset($tool['handler']);
            $schema = $tool['inputSchema'] ?? ['type' => 'object'];
            if (empty($schema['properties'])) {
                $schema['properties'] = new \stdClass();
            }
            if (empty($schema['required'])) {
                unset($schema['required']);
            }
            $tool['inputSchema'] = $schema;
            if (empty($tool['_meta'])) {
                unset($tool['_meta']); // a PHP [] would be sent as a JSON array, but _meta must be an object
            }
            $out[] = $tool;
        }
        return $out;
    }
}
