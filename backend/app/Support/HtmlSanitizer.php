<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Strict allowlist HTML sanitizer for CMS rich-text fields.
 * Strips <script>, <iframe>, <style>, <svg>, <object>, <form>, all event
 * handlers (on*), inline styles, and unsafe URI schemes (javascript:, data:, vbscript:).
 */
final class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li',
        'h2', 'h3', 'h4', 'blockquote', 'a', 'code', 'pre', 'hr', 'span',
    ];

    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math',
        'form', 'input', 'button', 'select', 'textarea', 'head', 'title', 'meta', 'link',
    ];

    public static function clean(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }
        $trimmed = trim($html);
        if ($trimmed === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8" ?><html><body><div id="__root">'.$trimmed.'</div></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $dom->getElementById('__root');
        if (! $root) {
            return htmlspecialchars(strip_tags($trimmed), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        self::sanitizeNode($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    private static function sanitizeNode(DOMNode $node): void
    {
        $children = iterator_to_array($node->childNodes);
        foreach ($children as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);

                continue;
            }
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $node->removeChild($child);

                    continue;
                }
                self::sanitizeNode($child);

                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    // Unwrap disallowed tag but keep sanitized text/child nodes.
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);

                    continue;
                }

                self::sanitizeAttributes($child, $tag);
            }
        }
    }

    private static function sanitizeAttributes(DOMElement $el, string $tag): void
    {
        $attrs = [];
        foreach ($el->attributes as $attr) {
            $attrs[] = $attr->name;
        }
        foreach ($attrs as $name) {
            $lower = strtolower($name);
            if ($tag !== 'a' || ! in_array($lower, ['href', 'title', 'target', 'rel'], true)) {
                $el->removeAttribute($name);

                continue;
            }
            if ($lower === 'href') {
                $val = trim($el->getAttribute($name));
                $cleanVal = preg_replace('/[\x00-\x20]+/', '', $val) ?? '';
                if (! self::isSafeUrl($cleanVal)) {
                    $el->removeAttribute($name);
                }
            } elseif ($lower === 'target') {
                $target = $el->getAttribute($name);
                if (! in_array($target, ['_blank', '_self'], true)) {
                    $el->removeAttribute($name);
                }
            }
        }
        if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    public static function isSafeUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }
        if (str_starts_with($url, '#')) {
            return true;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }
}
