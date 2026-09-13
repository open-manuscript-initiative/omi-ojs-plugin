<?php
namespace APP\plugins\generic\studioIntegration\classes\Core;

use DOMDocument;
use InvalidArgumentException;

/** Restricted, self-contained HTML accepted by the first galley transfer protocol. */
final class HtmlGalleyDocument
{
    public static function validate(string $html): void
    {
        if (strlen($html) > 8 * 1024 * 1024 || !preg_match('/^<!doctype html>/i', $html)
            || !preg_match('/<head(?:\s[^>]*)?>/i', $html) || !preg_match('/<body(?:\s[^>]*)?>/i', $html)) {
            throw new InvalidArgumentException('A standalone HTML document of at most 8 MiB is required.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            if (!$dom->loadHTML($html, LIBXML_NONET)) {
                throw new InvalidArgumentException('Invalid HTML.');
            }
            foreach ($dom->getElementsByTagName('*') as $element) {
                if (in_array(strtolower($element->tagName), ['script', 'iframe', 'object', 'embed', 'base', 'link', 'form', 'svg'], true)) {
                    throw new InvalidArgumentException('Active content and external resources are not supported.');
                }
                foreach ($element->attributes as $attribute) {
                    $name = strtolower($attribute->name);
                    $value = trim($attribute->value);
                    if (str_starts_with($name, 'on') || in_array($name, ['srcdoc', 'srcset', 'background'], true)) {
                        throw new InvalidArgumentException('Active HTML attributes are not supported.');
                    }
                    if ($name === 'src' && !preg_match('#^data:image/(png|jpeg|gif|webp);base64,[a-zA-Z0-9+/=]+$#D', $value)) {
                        throw new InvalidArgumentException('Images must be embedded PNG, JPEG, GIF or WebP files.');
                    }
                    if ($name === 'href' && !preg_match('#^(https?://|mailto:|\#)#i', $value)) {
                        throw new InvalidArgumentException('Only web, email and internal links are supported.');
                    }
                }
                if ($element->tagName === 'meta' && $element->hasAttribute('http-equiv')) {
                    throw new InvalidArgumentException('HTTP-equivalent metadata is not supported.');
                }
                $css = $element->tagName === 'style' ? $element->textContent : $element->getAttribute('style');
                if (preg_match('/@import|url\s*\(|expression\s*\(|\\\\/i', $css)) {
                    throw new InvalidArgumentException('External CSS resources are not supported.');
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function path(string $manuscriptId, string $locale): string
    {
        return 'omi-html-' . substr(hash('sha256', $manuscriptId . ':' . $locale), 0, 40);
    }
}
