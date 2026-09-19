<?php
// Ordered CSS selectors intentionally support a documented, safe subset.
declare(strict_types=1);
function selector_defaults(): array {
    return [
        'title' => ['meta[property="og:title"]', 'h1', 'title'],
        'description' => ['meta[name="description"]', 'meta[property="og:description"]'],
        'publishedAt' => ['meta[property="article:published_time"]', 'time[datetime]'],
        'cover_url' => ['meta[property="og:image"]', 'meta[name="twitter:image"]', 'article img', 'main img'],
        'textContent' => ['article', 'main', '[role="main"]', '#content', 'body']
    ];
}
function xpath_literal(string $value): string {
    if (strpos($value, "'") === false) return "'" . $value . "'";
    if (strpos($value, '"') === false) return '"' . $value . '"';
    return 'concat(' . implode(', "\'", ', array_map(static function ($part) { return "'" . $part . "'"; }, explode("'", $value))) . ')';
}
function css_xpath(string $selector): string {
    $selector = trim($selector);
    if ($selector === '' || strlen($selector) > 300) throw new RuntimeException('Empty or oversized CSS selector.');
    $xpath = '//'; $need_compound = true;
    while ($selector !== '') {
        if (!$need_compound) {
            if (!preg_match('/^\s*(>)\s*|^\s+/', $selector, $match)) throw new RuntimeException('Unsupported CSS selector. Allowed: element, .class, #id, [attribute], [attribute="value"], spaces and >.');
            $xpath .= strpos($match[0], '>') !== false ? '/' : '//';
            $selector = substr($selector, strlen($match[0])); $need_compound = true;
            if ($selector === '') throw new RuntimeException('Incomplete CSS selector.');
        }
        $tag = '*'; $consumed = false;
        if (preg_match('/^(\*|[a-zA-Z][a-zA-Z0-9_-]*)/', $selector, $match)) { $tag = strtolower($match[1]); $selector = substr($selector, strlen($match[0])); $consumed = true; }
        $predicates = [];
        while ($selector !== '') {
            if (preg_match('/^([.#])([a-zA-Z_][a-zA-Z0-9_-]*)/', $selector, $match)) {
                $predicates[] = $match[1] === '#' ? '@id=' . xpath_literal($match[2]) : 'contains(concat(" ", normalize-space(@class), " "), ' . xpath_literal(' ' . $match[2] . ' ') . ')';
            } elseif (preg_match('/^\[\s*([a-zA-Z_][a-zA-Z0-9_:-]*)\s*(?:=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s\]]+))\s*)?\]/', $selector, $match)) {
                $attribute = '@' . strtolower($match[1]);
                $predicates[] = strpos($match[0], '=') !== false ? $attribute . '=' . xpath_literal($match[2] !== '' ? $match[2] : (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? ''))) : $attribute;
            } else break;
            $selector = substr($selector, strlen($match[0])); $consumed = true;
        }
        if (!$consumed) throw new RuntimeException('Unsupported CSS selector. Do not use pseudo-classes, groups or CSS escapes.');
        $xpath .= $tag . ($predicates ? '[' . implode(' and ', $predicates) . ']' : ''); $need_compound = false;
    }
    return $xpath;
}
function absolute_url(string $value, string $page_url): string {
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)) { https_url($value); return $value; }
    $base = https_url($page_url);
    if (strpos($value, '//') === 0) { $url = 'https:' . $value; https_url($url); return $url; }
    $origin = 'https://' . $base['host'];
    if ($value[0] === '?') return $origin . ($base['path'] ?? '/') . $value;
    $path = $value[0] === '/' ? $value : preg_replace('~/[^/]*$~', '/', $base['path'] ?? '/') . $value;
    $parts = explode('?', $path, 2); $segments = [];
    foreach (explode('/', $parts[0]) as $segment) { if ($segment === '..') array_pop($segments); elseif ($segment !== '' && $segment !== '.') $segments[] = $segment; }
    $url = $origin . '/' . implode('/', $segments) . (isset($parts[1]) ? '?' . $parts[1] : ''); https_url($url); return $url;
}
function content_parts(DOMNode $node): array {
    $dom = new DOMDocument('1.0', 'UTF-8'); $root = $dom->appendChild($dom->importNode($node, true));
    $xpath = new DOMXPath($dom); $remove = [];
    foreach ($xpath->query('//script|//style|//nav|//footer|//aside|//form|//noscript|//*[@hidden]|//*[@aria-hidden="true"]') as $child) $remove[] = $child;
    foreach ($remove as $child) if ($child->parentNode) $child->parentNode->removeChild($child);
    $html = $dom->saveHTML($root);
    foreach ($xpath->query('//p|//div|//section|//h1|//h2|//h3|//h4|//h5|//h6|//li|//br|//tr|//blockquote') as $child) {
        if ($child->parentNode) $child->parentNode->insertBefore($dom->createTextNode("\n"), $child);
        $child->appendChild($dom->createTextNode("\n"));
    }
    $text = trim(preg_replace('/\n[ \t]*\n(?:[ \t]*\n)+/u', "\n\n", preg_replace('/[^\S\n]+/u', ' ', $root->textContent)));
    return ['text' => $text, 'html' => $html];
}
function extract_with_selectors(string $html, string $url, array $selectors): array {
    $xpath = new DOMXPath(html_dom($html));
    $out = ['url' => $url, 'title' => '', 'description' => '', 'publishedAt' => '', 'cover_url' => '', 'textContent' => '', 'source_html' => '', 'matches' => [], 'warnings' => []];
    foreach (selector_defaults() as $field => $defaults) {
        foreach (($selectors[$field] ?? $defaults) as $selector) {
            $nodes = $xpath->query(css_xpath($selector));
            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) continue;
                $value = ''; $parts = null;
                if ($field === 'textContent') { $parts = content_parts($node); $value = $parts['text']; }
                elseif ($field === 'cover_url') {
                    $image = $node;
                    if (!in_array(strtolower($image->tagName), ['meta', 'img', 'source', 'link'], true)) $image = $node->getElementsByTagName('img')->item(0);
                    if (!$image) continue;
                    foreach (['content', 'data-src', 'data-lazy-src', 'src', 'href'] as $attribute) { $candidate = trim($image->getAttribute($attribute)); if ($candidate !== '' && strpos($candidate, 'data:') !== 0) { $value = $candidate; break; } }
                    if ($value === '' && $image->hasAttribute('srcset')) $value = preg_split('/\s+/', trim(explode(',', $image->getAttribute('srcset'))[0]))[0];
                    try { $value = absolute_url($value, $url); } catch (RuntimeException $e) { continue; }
                } else $value = trim($node->hasAttribute('content') ? $node->getAttribute('content') : ($node->hasAttribute('datetime') ? $node->getAttribute('datetime') : $node->textContent));
                if ($value === '') continue;
                if ($field === 'publishedAt') { try { $value = iso_date($value); } catch (RuntimeException $e) { continue; } }
                $out[$field] = $value; $out['matches'][$field] = $selector;
                if ($parts) $out['source_html'] = $parts['html'];
                break 2;
            }
        }
    }
    $keywords = xpath_text($xpath, '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="keywords"]/@content');
    $out['tags'] = implode(', ', array_values(array_unique(array_filter(array_map('trim', explode(',', $keywords))))));
    $out['canonical'] = xpath_text($xpath, '//link[@rel="canonical"]/@href');
    if ($out['canonical'] !== '' && $out['canonical'] !== $url) $out['warnings'][] = 'The page specifies a different canonical URL: ' . $out['canonical'] . '. Review it before publishing; URLs are not changed automatically.';
    if ($out['publishedAt'] === '') $out['warnings'][] = 'No valid date found. Add the publication date (YYYY-MM-DD or ISO date with a time zone).';
    if ($out['textContent'] === '') $out['warnings'][] = 'No text found. Check the selectors or add text; JavaScript is not executed.';
    if (($out['matches']['textContent'] ?? '') === 'body') $out['warnings'][] = 'Text was extracted from body. Check for navigation and other unrelated content.';
    return $out;
}
