<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Lib\Health;

/** GET /health: JSON status for uptime monitors (see Lib\Health). No authentication: it reveals only ok/fail labels and the version. */
final class HealthController
{
    public function show(): void
    {
        $result = Health::run();
        json_out($result, Health::httpStatus($result), ['Cache-Control' => 'no-store']);
    }
}
