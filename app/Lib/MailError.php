<?php
declare(strict_types=1);

namespace App\Lib;

/** E-mail sending error (Mailer). The message is in English and free of login data — it can be shown to the admin. */
final class MailError extends \RuntimeException
{
}
