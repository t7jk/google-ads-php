<?php
declare(strict_types=1);

namespace App\Ads;

/** Operation refused by the guard before any HTTP request was sent (see Ads::mutate). */
final class MutationRefused extends \RuntimeException
{
}
