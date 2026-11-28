<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Lib\LogReader;

/** Logs tab: the newest application events, read-only. All the work is done by LogReader; the filter comes from ?f=. */
final class LogsController
{
    public function index(): void
    {
        require_admin();
        $filter = LogReader::normalizeFilter((string) ($_GET['f'] ?? 'all'));
        view('logs', [
            'title' => 'Logs',
            'filter' => $filter,
            'filters' => LogReader::FILTERS,
            'rows' => LogReader::recent($filter),
            'limit' => LogReader::LIMIT,
        ]);
    }
}
