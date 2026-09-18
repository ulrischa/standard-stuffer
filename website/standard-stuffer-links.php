<?php
// Website integration for Uli. Read a local JSON mapping, never remote executable code.
declare(strict_types=1);
function standard_stuffer_link(string $mapping_file, string $publication_url, ?string $request_uri = null): string {
    $request_uri = $request_uri ?? ($_SERVER['REQUEST_URI'] ?? '');
    if ($request_uri === '' || $request_uri[0] !== '/' || strpos($request_uri, '//') === 0 || preg_match('/[\x00-\x20\\\\#]/', $request_uri)) return '';
    static $cache = [];
    $cache_key = $mapping_file . "\0" . $publication_url;
    if (!array_key_exists($cache_key, $cache)) {
        $cache[$cache_key] = null;
        if (is_file($mapping_file) && is_readable($mapping_file)) {
            // A bounded read also protects against unexpectedly large replacement files.
            $json = @file_get_contents($mapping_file, false, null, 0, 5000001);
            if (is_string($json) && strlen($json) <= 5000000) {
                $value = json_decode($json, true);
                if (is_array($value) && ($value['version'] ?? null) === 1 && ($value['publication']['url'] ?? null) === rtrim($publication_url, '/') && is_array($value['documents'] ?? null)) $cache[$cache_key] = $value;
            }
        }
    }
    $mapping = $cache[$cache_key];
    if (!$mapping) return '';
    $parts = parse_url($publication_url);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) return '';
    // Never trust an incoming Host header to choose a publication.
    $origin = 'https://' . strtolower($parts['host']);
    $url = $origin . $request_uri;
    $uri = $mapping['documents'][$url] ?? null;
    if ($uri === null) $uri = $mapping['documents'][$origin . explode('?', $request_uri, 2)[0]] ?? null;
    if (!is_string($uri) || strlen($uri) > 1024 || !preg_match('#^at://did:[a-z]+:[A-Za-z0-9._:%-]+/site\.standard\.document/[A-Za-z0-9._~:\-]+$#D', $uri)) return '';
    return '<link rel="site.standard.document" href="' . htmlspecialchars($uri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . "\n";
}
