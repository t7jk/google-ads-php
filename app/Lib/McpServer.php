<?php
declare(strict_types=1);

namespace App\Lib;

use App\Ads\Errors;

/**
 * JSON-RPC 2.0 for MCP (Streamable HTTP transport, stateless mode: no sessions, plain JSON responses).
 * handle() returns a response array or null (a notification -> HTTP 202).
 */
final class McpServer
{
    private const SUPPORTED_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
    private const SERVER_NAME = 'google-ads-mcp';
    // Plan preview widget (MCP Apps). URI and mimeType as in the original (tools-plan.ts).
    public const PREVIEW_URI = 'ui://google-ads/plan-preview.html';
    private const UI_MIME = 'text/html;profile=mcp-app';
    private const UI_IMAGE_HOSTS = ['https://tpc.googlesyndication.com', 'https://*.googleusercontent.com'];

    public static function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = (string) ($message['method'] ?? '');
        if (!array_key_exists('id', $message)) {
            return null; // notification (e.g. notifications/initialized): no response
        }
        try {
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => self::dispatch($method, (array) ($message['params'] ?? []))];
        } catch (RpcError $e) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $e->getCode(), 'message' => $e->getMessage()]];
        }
    }

    private static function dispatch(string $method, array $params): array|object
    {
        return match ($method) {
            'initialize' => self::initialize($params),
            'ping' => new \stdClass(),
            'tools/list' => ['tools' => self::visibleTools()],
            'tools/call' => self::callTool($params),
            'resources/list' => ['resources' => [[
                'uri' => self::PREVIEW_URI, 'name' => 'plan-preview', 'mimeType' => self::UI_MIME,
                'description' => 'Preview of the change plan (the Approve button only for pending plans)',
            ]]],
            'resources/read' => self::readResource($params),
            default => throw new RpcError(-32601, "Unknown method: $method"),
        };
    }

    /**
     * What tools/list shows this client:
     *  - ui_event: only clients with the MCP Apps window (app_clients);
     *  - approve_plan: additionally only when config 'allow_tool_approve' is true (default false: approval is a human act on the
     *    plan page, a tool call would be made by the model itself; docs/adr/0005);
     *  - a read-only client (scope ads.read): only tools annotated readOnlyHint.
     * The list is a convenience, not the guard: callTool() enforces the same rules again.
     */
    private static function visibleTools(): array
    {
        return array_values(array_filter(ToolRegistry::listing(), fn(array $t) => self::refusalFor($t) === null));
    }

    /** Why the current client may not use this tool (definition with or without handler), or null when it may. */
    private static function refusalFor(array $tool): ?string
    {
        $isApp = \App\Plans\Cfg::isAppClient(Mcp::$clientId);
        if ($tool['name'] === 'ui_event' && !$isApp) {
            return 'Preview window events are accepted only from the window';
        }
        if ($tool['name'] === 'approve_plan' && !($isApp && \App\Plans\Cfg::toolApprovalEnabled())) {
            return 'Approval is possible only on the plan page, by the administrator.';
        }
        if (Mcp::$scope === Clients::SCOPE_READ && empty($tool['annotations']['readOnlyHint'])) {
            return 'This connection is read-only (scope ads.read); the tool "' . $tool['name'] . '" can change data.';
        }
        return null;
    }

    private static function initialize(array $params): array
    {
        $asked = (string) ($params['protocolVersion'] ?? '');
        return [
            'protocolVersion' => in_array($asked, self::SUPPORTED_VERSIONS, true) ? $asked : self::SUPPORTED_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false], 'resources' => ['listChanged' => false]],
            'serverInfo' => ['name' => self::SERVER_NAME, 'version' => Version::current()],
            // The original (Node) does not set the instructions field, so neither do we.
        ];
    }

    /** An exception from a handler -> an isError result (the model sees the message), not a protocol error. */
    private static function callTool(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $tool = ToolRegistry::find($name);
        if ($tool === null) {
            throw new RpcError(-32602, "Unknown tool: $name");
        }
        $args = (array) ($params['arguments'] ?? []);
        $started = microtime(true);
        if (($refusal = self::refusalFor($tool)) !== null) {
            Events::log('mcp_tool', ['tool' => $name, 'client' => Mcp::$clientId, 'ok' => false, 'error' => $refusal]);
            return Mcp::error($refusal);
        }
        try {
            $missing = array_diff($tool['inputSchema']['required'] ?? [], array_keys($args));
            $result = $missing
                ? Mcp::error('Missing required parameter: ' . implode(', ', $missing))
                : $tool['handler']($args);
        } catch (\Throwable $e) {
            if (!$e instanceof \App\Ads\UserError) { // UserError is a plain user-facing message, not a failure
                log_exception($e, "tool $name");
            }
            $readOnly = !empty($tool['annotations']['readOnlyHint']);
            $result = Mcp::error(Errors::describe($e, $readOnly ? 'read' : 'write'));
        }
        Events::log('mcp_tool', [
            'tool' => $name, 'client' => Mcp::$clientId, 'ok' => empty($result['isError']),
            'ms' => (int) round((microtime(true) - $started) * 1000),
            // Error text (truncated in Events) — so the server log shows what went wrong.
            'error' => empty($result['isError']) ? null : ($result['content'][0]['text'] ?? null),
        ]);
        return $result;
    }

    private static function readResource(array $params): array
    {
        if (($params['uri'] ?? '') !== self::PREVIEW_URI) {
            throw new RpcError(-32002, 'Resource not found');
        }
        $mcpUrl = base_url() . '/mcp';
        return ['contents' => [[
            'uri' => self::PREVIEW_URI,
            'mimeType' => self::UI_MIME,
            'text' => (string) file_get_contents(APP_PATH . '/ui/preview.html'),
            '_meta' => ['ui' => [
                'domain' => substr(hash('sha256', $mcpUrl), 0, 32) . '.claudemcpcontent.com',
                'csp' => ['resourceDomains' => [self::origin(base_url()), ...self::UI_IMAGE_HOSTS]],
            ]],
        ]]];
    }

    private static function origin(string $url): string
    {
        $p = parse_url($url);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    }
}
