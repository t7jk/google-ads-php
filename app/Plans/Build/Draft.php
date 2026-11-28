<?php
declare(strict_types=1);

namespace App\Plans\Build;

/**
 * Accumulator shared by the plan builders: operations, preview rows, validation errors and warnings.
 * A builder creates a Draft, appends via add()/op(), and finally assembles the plan (Plan::base() + fields + Undo::attach).
 */
final class Draft
{
    /** @var list<array> */
    public array $ops = [];
    /** @var list<array> */
    public array $diff = [];
    /** @var list<string> */
    public array $errors = [];
    /** @var list<string> */
    public array $warnings = [];

    /** Preview row: change = add|remove|change|info. */
    public function add(string $section, string $label, ?string $before, ?string $after, string $change): void
    {
        $this->diff[] = ['section' => $section, 'label' => $label, 'before' => $before, 'after' => $after, 'change' => $change];
    }

    public function op(array $op): void
    {
        $this->ops[] = $op;
    }

    public function error(string ...$messages): void
    {
        array_push($this->errors, ...$messages);
    }

    public function warn(string ...$messages): void
    {
        array_push($this->warnings, ...$messages);
    }

    /** @param list<array> $rows */
    public function rows(array $rows): void
    {
        array_push($this->diff, ...$rows);
    }

    /** Plan fields common to all builders that come from the draft: diff, warnings, validation. The builder assembles the steps itself. */
    public function common(): array
    {
        return ['diff' => $this->diff, 'warnings' => $this->warnings, 'validation' => ['ok' => !$this->errors, 'errors' => $this->errors]];
    }
}
