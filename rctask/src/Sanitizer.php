<?php
declare(strict_types=1);

/**
 * Allow-list HTML cleaner for rich-text notes: keeps basic formatting tags,
 * drops every attribute, script, style and unknown element.
 */
final class Sanitizer
{
    private const ALLOWED = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'h3', 'div', 'span'];
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'svg', 'math', 'head', 'title'];

    public static function html(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '';

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();

        $root = $doc->getElementById('__root');
        if (!$root) return '';
        self::clean($root);

        $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return trim(strip_tags($out)) === '' ? '' : $out;
    }

    private static function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $node->removeChild($child);
                    continue;
                }
                self::clean($child);
                if (!in_array($tag, self::ALLOWED, true)) {
                    while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) $child->removeAttribute($attr->name);
            } elseif (!($child instanceof DOMText)) {
                $node->removeChild($child); // comments, processing instructions, CDATA
            }
        }
    }
}
