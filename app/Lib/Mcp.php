<?php
declare(strict_types=1);

namespace App\Lib;

/** MCP tool result helpers + the context of the current /mcp request. A handler returns the result of one of the methods below. */
final class Mcp
{
    /** id of the MCP client making the current request (set by McpController); null outside /mcp. */
    public static ?string $clientId = null;

    /** Scope of that client (Clients::SCOPE_*): a read-only client may only call tools annotated readOnlyHint. */
    public static string $scope = Clients::SCOPE_WRITE;

    /**
     * Whether the current client may see a record owned by $ownerClientId (plans, uploads, events).
     * Rows without an owner (created before ownership was recorded) stay visible to every client; outside /mcp
     * (CLI, the admin panel) there is no client and everything is visible.
     */
    public static function canSee(?string $ownerClientId): bool
    {
        return self::$clientId === null || $ownerClientId === null || $ownerClientId === self::$clientId;
    }

    public static function text(string $text, ?array $structured = null): array
    {
        $result = ['content' => [['type' => 'text', 'text' => $text]]];
        if ($structured !== null) {
            $result['structuredContent'] = $structured;
        }
        return $result;
    }

    /** Text for the model + structured data (for the widget/MCP Apps). */
    public static function json(string $text, array $structured): array
    {
        return self::text($text, $structured);
    }

    public static function error(string $text): array
    {
        return ['isError' => true, 'content' => [['type' => 'text', 'text' => $text]]];
    }
}
