<?php
declare(strict_types=1);

namespace App\Plans;

/** Missing image/file referenced in a plan operation (thrown by the D2 materializer); the executor refuses to run and returns its message. */
final class MissingBlob extends \RuntimeException
{
}
