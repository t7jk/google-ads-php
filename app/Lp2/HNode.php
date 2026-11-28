<?php
declare(strict_types=1);

namespace App\Lp2;

/** Tree node from Lp2\Html::parse (type = el | text | comment). */
final class HNode
{
    /** @param array<string,string> $attrs @param list<HNode> $children */
    public function __construct(
        public string $type,
        public string $tag = '',
        public array $attrs = [],
        public array $children = [],
        public ?HNode $parent = null,
        public string $text = '',
    ) {
    }

    public function isEl(): bool
    {
        return $this->type === 'el';
    }
}
