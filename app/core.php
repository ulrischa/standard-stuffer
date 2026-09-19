<?php
// Standard.site Dashboard for Uli. All persistent application data uses JSON.
declare(strict_types=1);

function h($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function json_encode_safe($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR); }
function now_iso(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function initial_state(): array { return ['version' => 1, 'revision' => 0, 'did' => '', 'publication' => null, 'documents' => []]; }
function read_json(string $path, array $fallback): array {
    if (!is_file($path)) return $fallback;
    $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException('The JSON file is corrupt. Restore a backup.');
    return $value;
}
function atomic_json(string $path, array $value): void {
    $json = json_encode_safe($value);
    $tmp = tempnam(dirname($path), '.write-');
    if ($tmp === false) throw new RuntimeException('Could not create a temporary file.');
    try {
        chmod($tmp, 0600);
        if (file_put_contents($tmp, $json) !== strlen($json)) throw new RuntimeException('Could not save the complete JSON data.');
        if (!rename($tmp, $path)) throw new RuntimeException('Could not replace the JSON file atomically.');
    } finally { if (is_file($tmp)) unlink($tmp); }
}
function normalized_path(string $path): string {
    $path = str_replace('\\', '/', $path);
    return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
}
function state_save(array &$state): void {
    global $config;
    $state['revision']++;
    atomic_json($config['data_dir'] . '/dashboard.json', $state);
}
function storage_lock(string $name, int $mode = LOCK_EX) {
    global $config;
    $lock = fopen($config['data_dir'] . '/' . $name . '.lock', 'c');
    if (!$lock || !flock($lock, $mode)) throw new RuntimeException('Data storage is unavailable.');
    return $lock;
}
function read_state(): array {
    global $config;
    $lock = storage_lock('dashboard', LOCK_SH);
    try { return read_json($config['data_dir'] . '/dashboard.json', initial_state()); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
}
function input_string(array $input, string $key, int $max = 10000): string {
    $value = $input[$key] ?? '';
    if (!is_string($value) || strlen($value) > $max || !mb_check_encoding($value, 'UTF-8')) throw new RuntimeException('Invalid or oversized value: ' . $key);
    return trim($value);
}
function input_password(array $input, string $key): string {
    $value = $input[$key] ?? '';
    if (!is_string($value) || strlen($value) > 1000) throw new RuntimeException('Invalid password.');
    return $value;
}
function limited_text(string $value, string $label, int $bytes, int $graphemes, bool $required = false): string {
    preg_match_all('/\X/u', $value, $matches);
    if (($required && $value === '') || strlen($value) > $bytes || count($matches[0]) > $graphemes) throw new RuntimeException($label . ': check the length (maximum ' . $graphemes . ' characters).');
    return $value;
}
function https_url(string $url): array {
    if (strlen($url) > 2048 || preg_match('/[\x00-\x20\\\\]/', $url)) throw new RuntimeException('Invalid URL.');
    $parts = parse_url($url);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) throw new RuntimeException('Use a public HTTPS URL without credentials or a fragment (port 443).');
    if (!preg_match('/^[a-zA-Z0-9.-]+$/', $parts['host'])) throw new RuntimeException('Use Punycode for international domain names.');
    return $parts;
}
function publication_url(string $url): string {
    $parts = https_url($url);
    if (isset($parts['query'])) throw new RuntimeException('The website URL must not contain query parameters.');
    return 'https://' . strtolower($parts['host']) . rtrim($parts['path'] ?? '', '/');
}
function document_path(string $url, string $base): string {
    $parts = https_url($url);
    $base_parts = https_url($base);
    if (strtolower($parts['host']) !== strtolower($base_parts['host'])) throw new RuntimeException('Only articles from the configured website can be imported.');
    $base_path = $base_parts['path'] ?? '';
    $path = $parts['path'] ?? '/';
    if ($path === '') $path = '/';
    if ($base_path !== '' && strpos($path, $base_path . '/') !== 0) throw new RuntimeException('The article URL must be within the configured website path.');
    foreach (explode('/', rawurldecode($path)) as $segment) if ($segment === '..' || $segment === '.') throw new RuntimeException('Relative path segments are not allowed.');
    return substr($path, strlen($base_path)) . (isset($parts['query']) ? '?' . $parts['query'] : '');
}
function verification_url(string $base): string {
    $parts = https_url($base);
    return 'https://' . strtolower($parts['host']) . '/.well-known/site.standard.publication' . rtrim($parts['path'] ?? '', '/');
}
function new_rkey(): string {
    $value = ((int) floor(microtime(true) * 1000000) << 10) | random_int(0, 1023);
    $alphabet = '234567abcdefghijklmnopqrstuvwxyz';
    $key = '';
    for ($i = 0; $i < 13; $i++) { $key = $alphabet[$value & 31] . $key; $value >>= 5; }
    return $key;
}
function new_item(string $did, string $collection): array {
    $key = new_rkey();
    return ['rkey' => $key, 'uri' => 'at://' . $did . '/' . $collection . '/' . $key, 'cid' => null, 'record' => [], 'published_record' => null, 'pending' => null, 'status' => 'draft', 'check' => null];
}
function html_dom(string $html): DOMDocument {
    $previous = libxml_use_internal_errors(true);
    try {
        $dom = new DOMDocument();
        $encoding = 'UTF-8';
        if (preg_match('/<meta[^>]+charset\s*=\s*["\x27]?([a-zA-Z0-9_-]+)/i', $html, $match)) $encoding = $match[1];
        try { $html = mb_convert_encoding($html, 'UTF-8', $encoding); } catch (ValueError $e) { throw new RuntimeException('Unknown page character encoding.'); }
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        return $dom;
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
}
function xpath_text(DOMXPath $xpath, string $expression): string {
    $nodes = @$xpath->query($expression);
    if ($nodes === false) throw new RuntimeException('Invalid XPath expression.');
    return $nodes->length ? trim($nodes->item(0)->textContent) : '';
}
function iso_date(string $input): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2}))?$/', $input)) throw new RuntimeException('Enter a date as YYYY-MM-DD or an ISO date with a time zone.');
    try { $date = new DateTimeImmutable($input, new DateTimeZone('UTC')); }
    catch (Exception $e) { throw new RuntimeException('Invalid publication date.'); }
    $errors = DateTimeImmutable::getLastErrors();
    if ($errors && ($errors['warning_count'] || $errors['error_count'])) throw new RuntimeException('Invalid publication date.');
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}
function has_document_link(string $html, string $uri): bool {
    $xpath = new DOMXPath(html_dom($html));
    foreach ($xpath->query('//head/link[@href]') as $node) {
        $rels = preg_split('/\s+/', strtolower(trim($node->getAttribute('rel'))));
        if (in_array('site.standard.document', $rels, true) && trim($node->getAttribute('href')) === $uri) return true;
    }
    return false;
}
function document_record(array $input, array $publication, bool $allow_missing_date = false): array {
    $title = limited_text(input_string($input, 'title'), 'Title', 5000, 500, true);
    $description = limited_text(input_string($input, 'description', 30000), 'Description', 30000, 3000);
    $tags = array_values(array_unique(array_filter(array_map('trim', explode(',', input_string($input, 'tags', 20000))))));
    foreach ($tags as $tag) limited_text($tag, 'Tag', 1280, 128);
    $url = input_string($input, 'url', 2048);
    $record = ['$type' => 'site.standard.document', 'site' => $publication['uri'], 'path' => document_path($url, $publication['record']['url']), 'title' => $title];
    $date = input_string($input, 'publishedAt');
    if ($date !== '' || !$allow_missing_date) $record['publishedAt'] = iso_date($date);
    if ($description !== '') $record['description'] = $description;
    if ($tags) $record['tags'] = $tags;
    $text = input_string($input, 'textContent', 800000);
    if ($text !== '') $record['textContent'] = $text;
    if (strlen(json_encode_safe($record)) > 900000) throw new RuntimeException('The record is too large. Shorten the text.');
    return $record;
}
