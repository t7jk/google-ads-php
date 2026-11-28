<?php
declare(strict_types=1);

namespace App\Install;

/** A problem the installer can explain to the visitor in plain words (the message is shown on the page). */
final class InstallError extends \RuntimeException
{
}
