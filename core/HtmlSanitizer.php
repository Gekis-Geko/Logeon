<?php

declare(strict_types=1);

namespace Core;

class HtmlSanitizer
{
    private static function defaultAllowedTags(): array
    {
        return [
            'p' => [],
            'br' => [],
            'strong' => [],
            'b' => [],
            'em' => [],
            'i' => [],
            'u' => [],
            'ul' => [],
            'ol' => [],
            'li' => [],
            'blockquote' => [],
            'h1' => [],
            'h2' => [],
            'h3' => [],
            'h4' => [],
            'h5' => [],
            'h6' => [],
            'hr' => [],
            'a' => ['href', 'title', 'target', 'rel'],
            'img' => ['src', 'alt', 'title'],
            'span' => ['class'],
            'div' => ['class'],
        ];
    }

    private static function normalizeAllowedTags($options = []): array
    {
        $allowed = static::defaultAllowedTags();
        if (!empty($options['allowed_tags']) && is_array($options['allowed_tags'])) {
            $allowed = static::normalizeAllowedTagList($options['allowed_tags']);
        }
        if (isset($options['allow_images']) && $options['allow_images'] === false) {
            unset($allowed['img']);
        }

        return static::normalizeAllowedTagList($allowed);
    }

    public static function sanitize($html, $options = []): string
    {
        if ($html === null) {
            return '';
        }

        $allowed = static::normalizeAllowedTags($options);
        $html = (string) $html;
        $html = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $html);
        $html = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $html);

        if (!class_exists('\DOMDocument')) {
            return static::fallbackSanitize($html, $allowed);
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $wrapped = '<div id="__root__">' . $html . '</div>';
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $dom->documentElement;
        if (!$root) {
            return static::fallbackSanitize($html, $allowed);
        }

        static::sanitizeNode($root, $allowed, $options);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }

        return trim((string) $result);
    }

    private static function sanitizeNode($node, $allowed, $options): void
    {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if (!$child) {
                continue;
            }

            if ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $tag = strtolower($child->nodeName);
            if (!array_key_exists($tag, $allowed)) {
                static::unwrapNode($child);
                continue;
            }

            static::sanitizeAttributes($child, $tag, $allowed, $options);
            static::sanitizeNode($child, $allowed, $options);
        }
    }

    private static function unwrapNode($node): void
    {
        if (!$node || !$node->parentNode) {
            return;
        }

        while ($node->firstChild) {
            $node->parentNode->insertBefore($node->firstChild, $node);
        }
        $node->parentNode->removeChild($node);
    }

    private static function sanitizeAttributes($node, $tag, $allowed, $options): void
    {
        $allowedAttrs = $allowed[$tag] ?? [];
        if (!is_array($allowedAttrs)) {
            $allowedAttrs = [];
        }
        if (!$node->hasAttributes()) {
            return;
        }

        $toRemove = [];
        foreach ($node->attributes as $attr) {
            $name = strtolower($attr->nodeName);
            $value = (string) $attr->nodeValue;

            if (strpos($name, 'on') === 0) {
                $toRemove[] = $name;
                continue;
            }

            if (!in_array($name, $allowedAttrs, true)) {
                $toRemove[] = $name;
                continue;
            }

            if ($name === 'href' || $name === 'src') {
                $safeUrl = static::sanitizeUrl($value, $options);
                if ($safeUrl === '') {
                    $toRemove[] = $name;
                } else {
                    $node->setAttribute($name, $safeUrl);
                }
                continue;
            }

            if ($name === 'class') {
                $safeClass = preg_replace('/[^a-zA-Z0-9\-\_\s]/', '', $value);
                if ($safeClass === null || trim($safeClass) === '') {
                    $toRemove[] = $name;
                } else {
                    $node->setAttribute($name, trim($safeClass));
                }
                continue;
            }
        }

        foreach ($toRemove as $attrName) {
            $node->removeAttribute($attrName);
        }

        if ($tag === 'a') {
            $target = strtolower((string) $node->getAttribute('target'));
            if ($target === '_blank') {
                $node->setAttribute('rel', 'noopener noreferrer');
            }
        }
    }

    private static function sanitizeUrl($url, $options = []): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        if ($url[0] === '/' || $url[0] === '#') {
            return $url;
        }
        if (strpos($url, './') === 0 || strpos($url, '../') === 0) {
            return $url;
        }

        $allowDataImage = !empty($options['allow_data_image']);
        if ($allowDataImage && stripos($url, 'data:image/') === 0) {
            return $url;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === null) {
            return '';
        }

        $scheme = strtolower($scheme);
        if (in_array($scheme, ['http', 'https', 'mailto'], true)) {
            return $url;
        }

        return '';
    }

    private static function fallbackSanitize($html, $allowed): string
    {
        $tagList = '';
        foreach (array_keys($allowed) as $tag) {
            $tagList .= '<' . $tag . '>';
        }

        $clean = strip_tags($html, $tagList);
        $clean = preg_replace('/\s+on[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $clean);
        $clean = preg_replace('/\s+style\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $clean);
        $clean = preg_replace('/javascript:/i', '', $clean);

        return trim((string) $clean);
    }

    private static function normalizeAllowedTagList(array $allowed): array
    {
        $normalized = [];
        foreach ($allowed as $tag => $attrs) {
            if (is_int($tag)) {
                $tagName = strtolower(trim((string) $attrs));
                if ($tagName !== '') {
                    $normalized[$tagName] = [];
                }
                continue;
            }

            $tagName = strtolower(trim((string) $tag));
            if ($tagName === '') {
                continue;
            }

            if (!is_array($attrs)) {
                $normalized[$tagName] = [];
                continue;
            }

            $attrList = [];
            foreach ($attrs as $attr) {
                $attrName = strtolower(trim((string) $attr));
                if ($attrName !== '') {
                    $attrList[] = $attrName;
                }
            }

            $normalized[$tagName] = array_values(array_unique($attrList));
        }

        return $normalized;
    }
}
