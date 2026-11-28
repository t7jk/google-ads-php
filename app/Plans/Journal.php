<?php
declare(strict_types=1);

namespace App\Plans;

use App\Lib\Events;

/**
 * Plan execution journal (port of src/store/journal.ts). Entries go to the event log (events, type "plan"):
 * a single source of truth, read by server_events. Types: execute, execute_started, execute_failed, refused, undo_unavailable.
 */
final class Journal
{
    public static function append(string $type, ?string $planId, string $summary, ?int $ops = null, mixed $detail = null): void
    {
        Events::log('plan', array_filter([
            'event' => $type, 'planId' => $planId, 'summary' => $summary, 'ops' => $ops, 'detail' => $detail,
            'ok' => in_array($type, ['execute', 'execute_started'], true),
        ], fn($v) => $v !== null));
    }
}
