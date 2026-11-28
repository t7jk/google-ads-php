<?php
declare(strict_types=1);

namespace App\Ads;

/** Google Ads API error response. $codes = error codes (e.g. DUPLICATE_NAME), $details = raw JSON body (no tokens). */
final class AdsApiError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly array $codes, public readonly mixed $details)
    {
        parent::__construct($message);
    }
}
