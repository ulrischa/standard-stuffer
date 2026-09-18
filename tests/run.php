<?php
// Behavioral tests with an in-memory PDS; no network or real credentials.
declare(strict_types=1);
require __DIR__ . '/../app/core.php';
require __DIR__ . '/../app/extract.php';
require __DIR__ . '/../app/http.php';
require __DIR__ . '/../app/actions.php';
$passed = 0;
function check(bool $condition, string $message): void { global $passed; if (!$condition) throw new RuntimeException('FAIL: ' . $message); $passed++; echo 'PASS: ' . $message . "\n"; }
function throws(callable $call, string $message): void { try { $call(); } catch (RuntimeException $e) { check(true, $message); return; } check(false, $message); }
$mock = ['records' => [], 'writes' => 0, 'deletes' => 0, 'drop' => false, 'pages' => []];
function http_request(string $url, string $method = 'GET', ?string $body = null, array $headers = [], int $limit = 4000000): array {
    global $mock;
    if (strpos($url, '/xrpc/') === false) return $mock['pages'][$url] ?? ['status' => 404, 'body' => '', 'type' => 'text/html'];
    $endpoint = basename(parse_url($url, PHP_URL_PATH)); parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query); $args = $body !== null ? json_decode($body, true) : $query;
    $key = ($args['collection'] ?? '') . '/' . ($args['rkey'] ?? ''); $old = $mock['records'][$key] ?? null;
    if ($endpoint === 'com.atproto.server.getSession') return ['status' => 200, 'body' => '{}', 'type' => 'application/json'];
    if ($endpoint === 'com.atproto.repo.uploadBlob') return ['status' => 200, 'body' => json_encode_safe(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafy-cover'], 'mimeType' => 'image/png', 'size' => strlen($body)]]), 'type' => 'application/json'];
    if ($endpoint === 'com.atproto.repo.getRecord') return ['status' => $old ? 200 : 400, 'body' => json_encode_safe($old ?? ['error' => 'RecordNotFound']), 'type' => 'application/json'];
    if ($endpoint === 'com.atproto.repo.putRecord') {
        if (($old['cid'] ?? null) !== $args['swapRecord']) throw new RuntimeException('mock swap mismatch');
        $mock['writes']++; $value = ['uri' => 'at://' . $args['repo'] . '/' . $key, 'cid' => 'cid-' . $mock['writes'], 'value' => $args['record']]; $mock['records'][$key] = $value;
        if ($mock['drop']) { $mock['drop'] = false; throw new RuntimeException('Simulated timeout after successful write'); }
        return ['status' => 200, 'body' => json_encode_safe($value), 'type' => 'application/json'];
    }
    if ($endpoint === 'com.atproto.repo.deleteRecord') { $mock['deletes']++; unset($mock['records'][$key]); return ['status' => 200, 'body' => '{}', 'type' => 'application/json']; }
    throw new RuntimeException('Unexpected mock endpoint ' . $endpoint);
}
function fetch_html(string $url): string { $r = http_request($url); if ($r['status'] !== 200) throw new RuntimeException('HTTP ' . $r['status']); return $r['body']; }

check(document_path('https://example.de/blog/artikel.php?a=1', 'https://example.de/blog') === '/artikel.php?a=1', 'Publication subpath and query preserved');
check(verification_url('https://example.de/blog') === 'https://example.de/.well-known/site.standard.publication/blog', 'Non-root publication verification endpoint');
throws(static function () { document_path('https://example.de/blog-other/a', 'https://example.de/blog'); }, 'Similar path prefix rejected');
throws(static function () { document_path('https://other.de/a', 'https://example.de'); }, 'Cross-origin article rejected');
throws(static function () { https_url('https://user:password@example.de/a'); }, 'URL credentials rejected');
throws(static function () { https_url('http://example.de/a'); }, 'Plain HTTP rejected');
throws(static function () { iso_date('2026-02-30'); }, 'Impossible date rejected');
check(iso_date('2026-09-18T12:00:00+02:00') === '2026-09-18T10:00:00Z', 'Date normalized to UTC');
check(preg_match('/^[234567a-z]{13}$/', new_rkey()) === 1, 'TID-compatible record key');
$html = '<!doctype html><html><head><meta charset="utf-8"><title>Fallback</title><meta property="og:title" content=""><meta name="keywords" content="Klima, Energie, Klima"><meta property="article:tag" content="Wrong"><meta property="article:published_time" content="bad"><meta property="og:image" content="/cover.jpg"></head><body><h1>Titel mit Ä</h1><time datetime="2026-09-18">Heute</time><article class="story"><nav>Noise</nav><h2>Überschrift</h2><p>Erster Absatz</p><p>Zweiter Absatz</p><script>alert(1)</script><p hidden>Geheim</p><img src="/second.jpg"></article><main>Later</main></body></html>';
$result = extract_with_selectors($html, 'https://example.de/blog/article', selector_defaults());
check($result['title'] === 'Titel mit Ä' && $result['matches']['title'] === 'h1', 'Empty selector result falls through, Unicode intact');
check($result['tags'] === 'Klima, Energie', 'Only meta keywords become tags, duplicates removed');
check($result['publishedAt'] === '2026-09-18T00:00:00Z', 'Invalid date falls through to time selector');
check($result['cover_url'] === 'https://example.de/cover.jpg', 'First cover selector wins and relative URL resolves');
check(strpos($result['textContent'], 'Noise') === false && strpos($result['textContent'], 'alert') === false && strpos($result['textContent'], 'Geheim') === false, 'Navigation, scripts and hidden content excluded');
check(strpos($result['textContent'], "\n") !== false && strpos($result['textContent'], '<p>') === false, 'Plain text contains paragraph breaks but no markup');
check(strpos($result['source_html'], '<p>Erster Absatz</p>') !== false, 'HTML preserved separately');
$selectors = selector_defaults(); $selectors['textContent'] = ['#missing', 'article.story > p']; $selectors['cover_url'] = ['article'];
$result2 = extract_with_selectors($html, 'https://example.de/blog/article', $selectors);
check($result2['textContent'] === 'Erster Absatz', 'CSS child, class, fallback and first matching node');
check($result2['cover_url'] === 'https://example.de/second.jpg', 'Container selector finds image');
throws(static function () { css_xpath('article:first-child'); }, 'Unsupported pseudo-class explicitly rejected');
throws(static function () { css_xpath('article, main'); }, 'Comma groups explicitly rejected');
$uri = 'at://did:plc:example/site.standard.document/3abc';
check(has_document_link('<html><head><link href="' . $uri . '" rel="alternate site.standard.document"></head><body></body></html>', $uri), 'Exact AT-URI and relation token accepted in head');
check(!has_document_link('<html><head></head><body><link href="' . $uri . '" rel="site.standard.document"></body></html>', $uri), 'Body-only verification link rejected');
check(!has_document_link('<html><head><link href="' . $uri . 'x" rel="site.standard.document"></head></html>', $uri), 'Similar AT-URI is not accepted');

$test_dir = sys_get_temp_dir() . '/standard-stuffer-test-' . bin2hex(random_bytes(5)); mkdir($test_dir, 0700);
$config = ['data_dir' => $test_dir];
$_SESSION = ['pds' => ['did' => 'did:plc:example', 'url' => 'https://pds.example.de', 'access' => 'mock', 'refresh' => 'mock', 'refreshed' => time()]];
$state = initial_state(); $state['did'] = 'did:plc:example'; $state['publication'] = new_item($state['did'], 'site.standard.publication');
$state['publication']['record'] = ['$type' => 'site.standard.publication', 'url' => 'https://example.de', 'name' => 'Example'];
sync_item($state, $state['publication'], 'put');
check($state['publication']['status'] === 'published' && $mock['writes'] === 1, 'Publication created with stable key');
$pub = $state['publication'];
$mock['pages'][verification_url($pub['record']['url'])] = ['status' => 200, 'body' => "\n" . $pub['uri'] . "\n", 'type' => 'text/plain'];
check(verify_item($pub, true, $state)['ok'], 'Publication checks both public endpoint and PDS');
$mock['pages'][verification_url($pub['record']['url'])]['body'] = '<html>' . $pub['uri'] . '</html>';
check(!verify_item($pub, true, $state)['ok'], 'HTML wrapper cannot verify publication');
$mock['pages']['https://example.de/cover.png'] = ['status' => 200, 'body' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jJ1kAAAAASUVORK5CYII='), 'type' => 'image/png'];
check(upload_cover('https://example.de/cover.png', $state)['ref']['$link'] === 'bafy-cover', 'Cover is uploaded as a PDS blob');
throws(static function () use ($state) { upload_cover('https://unapproved.example/cover.png', $state); }, 'Unapproved cover domain blocked');
$mock['pages']['https://example.de/not-image'] = ['status' => 200, 'body' => '<html>Not an image</html>', 'type' => 'image/png'];
throws(static function () use ($state) { upload_cover('https://example.de/not-image', $state); }, 'Image content validated independently of HTTP MIME');
$input = ['url' => 'https://example.de/article', 'title' => 'Article', 'description' => '', 'publishedAt' => '2026-09-18', 'tags' => 'Klima', 'textContent' => 'Text'];
$doc = new_item($state['did'], 'site.standard.document'); $id = $doc['rkey']; $doc['record'] = document_record($input, $pub); $doc['url'] = $input['url'];
$state['documents'][$id] = $doc; $mock['drop'] = true;
throws(static function () use (&$state, $id) { sync_item($state, $state['documents'][$id], 'put'); }, 'Timeout after remote write is surfaced');
check(read_json($test_dir . '/dashboard.json', [])['documents'][$id]['pending'] !== null, 'Pending journal persisted before write');
$state = read_json($test_dir . '/dashboard.json', []);
sync_item($state, $state['documents'][$id], 'put');
check($mock['writes'] === 2 && $state['documents'][$id]['pending'] === null, 'Retry recovers without duplicate write');
$original_uri = $state['documents'][$id]['uri'];
$state['documents'][$id]['record']['title'] = 'Updated'; sync_item($state, $state['documents'][$id], 'put');
check($mock['writes'] === 3 && $state['documents'][$id]['uri'] === $original_uri, 'Update retains AT-URI');
$mock['pages'][$input['url']] = ['status' => 200, 'body' => '<html><head><link rel="site.standard.document" href="' . $original_uri . '"></head><body>Article</body></html>', 'type' => 'text/html'];
check(verify_item($state['documents'][$id], false, $state)['ok'], 'Document verification checks HTML and PDS');
$key = 'site.standard.document/' . $id;
$mock['records'][$key]['cid'] = 'external'; $mock['records'][$key]['value']['title'] = 'External edit';
check(!verify_item($state['documents'][$id], false, $state)['ok'], 'Remote drift invalidates verification');
throws(static function () use (&$state, $id) { sync_item($state, $state['documents'][$id], 'put'); }, 'External edit protected against overwrite');
$mock['records'][$key]['cid'] = $state['documents'][$id]['cid']; $mock['records'][$key]['value'] = $state['documents'][$id]['record'];
sync_item($state, $state['documents'][$id], 'put'); sync_item($state, $state['documents'][$id], 'delete');
check($mock['deletes'] === 1 && $state['documents'][$id]['status'] === 'removed', 'Delete retains cleanup instructions locally');
check(!verify_item($state['documents'][$id], false, $state)['ok'], 'Deleted record with lingering HTML link needs cleanup');
$mock['pages'][$input['url']]['body'] = '<html><head></head><body>Article</body></html>';
check(verify_item($state['documents'][$id], false, $state)['ok'], 'Removed link is verified');
foreach (glob($test_dir . '/*') as $file) unlink($file); rmdir($test_dir);
echo "\n" . $passed . " tests passed.\n";
