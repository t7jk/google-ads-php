<?php
declare(strict_types=1);

namespace App\Google;

/** Google is not connected or the token was revoked; the message goes to the model as-is. */
final class ReauthRequiredError extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Google is not connected (or access was revoked). Open ' . base_url() . '/ and click "Connect with Google", then retry the command.');
    }
}
