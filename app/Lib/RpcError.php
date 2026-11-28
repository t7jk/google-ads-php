<?php
declare(strict_types=1);

namespace App\Lib;

/**
 * JSON-RPC protocol error: `new RpcError(-32601, 'Unknown method: x')`.
 * Argument order is (code, message) like in the JSON-RPC error object — the reverse of \Exception,
 * hence this constructor (passing an int as the message would throw a TypeError).
 */
final class RpcError extends \Exception
{
    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}
