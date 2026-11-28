<?php
declare(strict_types=1);

namespace App\Plans\Build;

/**
 * Preview rows grouped into sections (flatten() sets the section order). add() returns a stable row id,
 * so the builder can drop a row later without shifting the others. This is needed when adding and removing
 * the same file cancel each other out.
 */
final class SectionRows
{
    /** @var array<string, array<int, array>> */
    private array $rows = [];

    /** @param array $d row fields other than the section: label, before, after, change, optionally note/level/beforeImage/afterImage/imageAlt */
    public function add(string $section, array $d): int
    {
        $this->rows[$section][] = ['section' => $section] + $d;
        return array_key_last($this->rows[$section]);
    }

    public function drop(string $section, int $id): void
    {
        unset($this->rows[$section][$id]);
    }

    public function has(string $section): bool
    {
        return !empty($this->rows[$section]);
    }

    /** @return list<array> */
    public function in(string $section): array
    {
        return array_values($this->rows[$section] ?? []);
    }

    /** Section rows in the given order. @param list<string> $order @return list<array> */
    public function flatten(array $order): array
    {
        return array_merge(...array_map(fn($s) => $this->in($s), $order));
    }
}
