<?php
declare(strict_types=1);

namespace App\Lp2;

/**
 * Minimal, tolerant HTML parser (port of src/lp2/html.ts) — for text analysis only, executes nothing.
 * Tree: App\Lp2\HNode nodes (type = el | text | comment). The parser works on bytes (tags are ASCII, UTF-8 passes through unchanged).
 */
final class Html
{
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
    private const RAW = ['script', 'style', 'textarea', 'title'];
    private const SELF_CLOSING_PEERS = ['option', 'li', 'p', 'tr', 'td', 'th', 'dt', 'dd'];
    private const ENTITIES = ['amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', 'apos' => "'", 'nbsp' => ' ', 'oacute' => 'ó', 'Oacute' => 'Ó'];

    public static function decodeEntities(string $s): string
    {
        return (string) preg_replace_callback('/&(#x[0-9a-f]+|#\d+|[a-z]+);/i', function (array $m): string {
            $e = $m[1];
            if ($e[0] === '#') {
                $n = strtolower($e[1]) === 'x' ? hexdec(substr($e, 2)) : (int) substr($e, 1);
                return ($n > 0 && $n < 0x110000) ? (string) mb_chr((int) $n, 'UTF-8') : $m[0];
            }
            return self::ENTITIES[$e] ?? self::ENTITIES[strtolower($e)] ?? $m[0];
        }, $s);
    }

    /** @return array<string,string> */
    private static function parseAttrs(string $src): array
    {
        $out = [];
        preg_match_all('/([^\s"\'<>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/', $src, $all, PREG_SET_ORDER);
        foreach ($all as $m) {
            $name = strtolower($m[1]);
            if (!array_key_exists($name, $out)) {
                $out[$name] = self::decodeEntities(($m[2] ?? '') !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? '')));
            }
        }
        return $out;
    }

    public static function parse(string $html): HNode
    {
        $root = new HNode('el', '#root');
        $stack = [$root];
        $top = function () use (&$stack): HNode {
            return $stack[count($stack) - 1];
        };
        $pushText = function (string $t, bool $raw = false) use ($top): void {
            if ($t !== '') {
                $p = $top();
                $p->children[] = new HNode('text', parent: $p, text: $raw ? $t : self::decodeEntities($t));
            }
        };
        $i = 0;
        $n = strlen($html);
        while ($i < $n) {
            $lt = strpos($html, '<', $i);
            if ($lt === false) {
                $pushText(substr($html, $i));
                break;
            }
            if ($lt > $i) {
                $pushText(substr($html, $i, $lt - $i));
            }
            if (substr_compare($html, '<!--', $lt, 4) === 0) {
                $end = strpos($html, '-->', $lt + 4);
                $stop = $end === false ? $n : $end;
                $p = $top();
                $p->children[] = new HNode('comment', parent: $p, text: substr($html, $lt + 4, $stop - $lt - 4));
                $i = $end === false ? $n : $end + 3;
                continue;
            }
            $next = $html[$lt + 1] ?? '';
            if ($next === '!' || $next === '?') {
                $end = strpos($html, '>', $lt);
                $i = $end === false ? $n : $end + 1;
                continue;
            }
            if ($next === '/') {
                if (!preg_match('/\G<\/([a-zA-Z][^\s>\/]*)[^>]*>?/', $html, $m, 0, $lt)) {
                    $pushText('<');
                    $i = $lt + 1;
                    continue;
                }
                $tag = strtolower($m[1]);
                for ($k = count($stack) - 1; $k > 0; $k--) {
                    if ($stack[$k]->tag === $tag) {
                        array_splice($stack, $k);
                        break;
                    }
                }
                $i = $lt + strlen($m[0]);
                continue;
            }
            if (!preg_match('/\G<([a-zA-Z][^\s>\/]*)((?:"[^"]*"|\'[^\']*\'|[^\'">])*+)>/', $html, $open, 0, $lt)) {
                $pushText('<');
                $i = $lt + 1;
                continue;
            }
            $tag = strtolower($open[1]);
            $attrSrc = $open[2];
            $selfClose = str_ends_with(rtrim($attrSrc), '/');
            if ($selfClose) {
                $attrSrc = substr(rtrim($attrSrc), 0, -1);
            }
            if (in_array($tag, self::SELF_CLOSING_PEERS, true) && $top()->tag === $tag) {
                array_pop($stack);
            }
            $parent = $top();
            $el = new HNode('el', $tag, self::parseAttrs($attrSrc), [], $parent);
            $parent->children[] = $el;
            $i = $lt + strlen($open[0]);
            if (in_array($tag, self::VOID, true) || $selfClose) {
                continue;
            }
            if (in_array($tag, self::RAW, true)) {
                $rest = substr($html, $i);
                $hasClose = preg_match('/<\/' . $tag . '\s*>/i', $rest, $cm, PREG_OFFSET_CAPTURE);
                $body = $hasClose ? substr($rest, 0, $cm[0][1]) : $rest;
                if ($body !== '') {
                    $el->children[] = new HNode('text', parent: $el, text: ($tag === 'script' || $tag === 'style') ? $body : self::decodeEntities($body));
                }
                $i += $hasClose ? $cm[0][1] + strlen($cm[0][0]) : strlen($rest);
                continue;
            }
            $stack[] = $el;
        }
        return $root;
    }

    /** Calls $fn for every descendant (depth-first, in document order). */
    public static function walk(HNode $node, callable $fn): void
    {
        foreach ($node->children as $c) {
            $fn($c);
            if ($c->isEl()) {
                self::walk($c, $fn);
            }
        }
    }

    /** @return list<HNode> */
    public static function allElements(HNode $root): array
    {
        $out = [];
        self::walk($root, function (HNode $n) use (&$out): void {
            if ($n->isEl()) {
                $out[] = $n;
            }
        });
        return $out;
    }

    /** Ancestors starting from the nearest (for an element: itself first), without #root. @return list<HNode> */
    public static function ancestors(HNode $n): array
    {
        $out = [];
        for ($p = $n->isEl() ? $n : $n->parent; $p !== null && $p->tag !== '#root'; $p = $p->parent) {
            $out[] = $p;
        }
        return $out;
    }

    public static function textOf(HNode $el): string
    {
        $s = '';
        self::walk($el, function (HNode $n) use (&$s): void {
            if ($n->type === 'text' && !in_array($n->parent->tag, ['script', 'style'], true)) {
                $s .= $n->text;
            }
        });
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** Hidden check (heuristic, also inherited from ancestors): hidden attribute, display:none, visibility:hidden, classes hidden/d-none/is-hidden, input type=hidden. */
    public static function hiddenReason(HNode $n): ?string
    {
        foreach (self::ancestors($n) as $el) {
            $label = "<{$el->tag}" . (($el->attrs['id'] ?? '') !== '' ? "#{$el->attrs['id']}" : '') . '>';
            if (array_key_exists('hidden', $el->attrs)) {
                return "hidden attribute on $label";
            }
            if ($el->tag === 'input' && strtolower($el->attrs['type'] ?? '') === 'hidden') {
                return 'input type=hidden';
            }
            $st = $el->attrs['style'] ?? '';
            if (preg_match('/display\s*:\s*none/i', $st)) {
                return "style display:none na $label";
            }
            if (preg_match('/visibility\s*:\s*hidden/i', $st)) {
                return "style visibility:hidden na $label";
            }
            $cls = preg_split('/\s+/', $el->attrs['class'] ?? '') ?: [];
            foreach (['hidden', 'd-none', 'is-hidden'] as $c) {
                if (in_array($c, $cls, true)) {
                    return "klasa $c na $label";
                }
            }
        }
        return null;
    }
}
