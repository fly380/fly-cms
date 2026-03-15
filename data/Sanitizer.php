<?php
/**
 * data/Sanitizer.php — XSS-санітизація виводу
 *
 * Виділено з templates/page_wrapper.php в окремий клас.
 * Використовується через Twig-фільтри в TwigFactory:
 *   {{ content|sanitize_html }}
 *   {{ css|sanitize_css }}
 *   {{ js|sanitize_js }}
 */
class Sanitizer
{
    /**
     * Санітизація HTML з TinyMCE через DOMDocument.
     * Видаляє script/iframe/form та on*-атрибути.
     * Fallback на regex якщо DOMDocument недоступний.
     */
    public static function html(string $html): string
    {
        if (empty(trim($html))) return '';

        if (class_exists('DOMDocument')) {
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML(
                '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
            );
            libxml_clear_errors();

            $blocked = [
                'script','style','iframe','object','embed','form',
                'input','button','textarea','select','base','meta',
                'link','applet','frameset','frame','svg',
            ];

            $xpath = new DOMXPath($dom);

            foreach ($blocked as $tag) {
                $nodes = $xpath->query("//{$tag}");
                if ($nodes) {
                    foreach (iterator_to_array($nodes) as $node) {
                        $node->parentNode?->removeChild($node);
                    }
                }
            }

            $all = $xpath->query('//*');
            if ($all) {
                foreach ($all as $el) {
                    $toRemove = [];
                    foreach ($el->attributes as $attr) {
                        $name  = strtolower($attr->nodeName);
                        $value = $attr->nodeValue;

                        if (str_starts_with($name, 'on')) {
                            $toRemove[] = $attr->nodeName;
                            continue;
                        }
                        if (in_array($name, ['href','src','action','data'], true)) {
                            $stripped = strtolower(preg_replace('/\s+/', '', $value));
                            if (str_starts_with($stripped, 'javascript:')
                                || str_starts_with($stripped, 'vbscript:')) {
                                $el->setAttribute($attr->nodeName, '#');
                                continue;
                            }
                        }
                        if ($name === 'style' && preg_match('/expression\s*\(/i', $value)) {
                            $toRemove[] = $attr->nodeName;
                        }
                    }
                    foreach ($toRemove as $a) {
                        $el->removeAttribute($a);
                    }
                }
            }

            $body = $dom->getElementsByTagName('body')->item(0);
            if (!$body) return '';
            $result = '';
            foreach ($body->childNodes as $child) {
                $result .= $dom->saveHTML($child);
            }
            return $result;
        }

        // Fallback regex
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<(iframe|object|embed|form|input|button|textarea|select|base|meta|link|applet|frameset|frame|svg)\b[^>]*>.*?<\/\1>/is', '', $html);
        $html = preg_replace('/<(iframe|object|embed|form|input|button|textarea|select|base|meta|link|applet|frameset|frame)\b[^>]*\/?>/is', '', $html);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*(["\']).*?\1/is', '', $html);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*[^\s>]+/is', '', $html);
        $html = preg_replace('/(\b(?:href|src|action|data)\s*=\s*["\'])\s*(?:javascript|vbscript|data)\s*:/is', '$1#', $html);
        $html = preg_replace('/style\s*=\s*(["\'])[^"\']*expression\s*\([^"\']*\1/is', '', $html);
        return $html;
    }

    /**
     * Санітизація CSS (custom_css з БД).
     */
    public static function css(string $css): string
    {
        if (empty($css)) return '';
        $css = str_ireplace('</style', '<\\/style', $css);
        $css = preg_replace('/expression\s*\(/i', 'expression_blocked(', $css);
        $css = preg_replace('/@import\b/i', '/* @import blocked */', $css);
        $css = preg_replace('/url\s*\(\s*["\']?\s*(?!\/uploads\/)(?:https?:|\/\/)[^)]*\)/i', 'url()', $css);
        return $css;
    }

    /**
     * Санітизація JS (custom_js з БД, лише для admin).
     */
    public static function js(string $js): string
    {
        if (empty($js)) return '';
        $js = str_ireplace('</script', '<\\/script', $js);
        return $js;
    }
}
