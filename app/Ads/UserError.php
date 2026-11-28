<?php
declare(strict_types=1);

namespace App\Ads;

/** An error "to show as-is" (e.g. campaign not found): Errors::describe returns its message without a prefix. Equivalent of NotFound in the Node version. */
class UserError extends \RuntimeException
{
}
