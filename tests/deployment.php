<?php
// Deterministic deployment, proxy and website-integration tests for Uli.
declare(strict_types=1);
require __DIR__ . '/../app/core.php';
require __DIR__ . '/../app/mapping.php';
require __DIR__ . '/../app/deployment.php';
require __DIR__ . '/../website/standard-stuffer-links.php';
$passed = 0;
function check(bool $ok, string $message): void { global $passed; if (!$ok) throw new RuntimeException('FAIL: ' . $message); $passed++; echo 'PASS: ' . $message . "\n"; }
function throws(callable $action, string $message): void { try { $action(); } catch (RuntimeException $e) { check(true, $message); return; } check(false, $message); }
$state = initial_state(); $state['did'] = 'did:plc:example';
$pub = new_item($state['did'], 'site.standard.publication');
$pub['record'] = ['$type' => 'site.standard.publication', 'url' => 'https://example.de/blog', 'name' => 'Example'];
$pub['published_record'] = $pub['record']; $pub['cid'] = 'pub-cid'; $pub['status'] = 'published'; $state['publication'] = $pub;
$doc = new_item($state['did'], 'site.standard.document'); $doc['record'] = ['$type' => 'site.standard.document', 'site' => $pub['uri'], 'path' => '/story', 'title' => 'Private draft title'];
$doc['published_record'] = $doc['record']; $doc['cid'] = 'doc-cid'; $doc['status'] = 'published'; $doc['url'] = 'https://example.de/blog/story'; $doc['source_html'] = 'Private source';
$state['documents']['doc'] = $doc;
$state['documents']['draft'] = new_item($state['did'], 'site.standard.document');
$payload = mapping_payload($state); $map = json_decode($payload, true);
check($map['documents']['https://example.de/blog/story'] === $doc['uri'] && count($map['documents']) === 1, 'Only published associations exported, subpath retained');
check(strpos($payload, 'Private') === false && strpos($payload, 'source_html') === false, 'No drafts, content or private metadata in mapping');
check(mapping_payload($state) === $payload, 'Identical state produces identical bytes');
$state['documents']['doc']['pending'] = ['operation' => 'delete'];
throws(static function () use ($state) { mapping_payload($state); }, 'Uncertain pending delete blocks deployment');
$state['documents']['doc']['pending'] = null;
$temp = sys_get_temp_dir() . '/stuffer-map-test-' . bin2hex(random_bytes(5)); mkdir($temp, 0700);
file_put_contents($temp . '/map.json', $payload);
check(strpos(standard_stuffer_link($temp . '/map.json', 'https://example.de/blog', '/blog/story'), $doc['uri']) !== false, 'PHP head integration uses current path');
check(strpos(standard_stuffer_link($temp . '/map.json', 'https://example.de/blog', '/blog/story?utm_source=test'), $doc['uri']) !== false, 'Plain article mapping tolerates tracking query');
check(standard_stuffer_link($temp . '/map.json', 'https://other.de', '/blog/story') === '', 'Publication mismatch emits no link');
check(standard_stuffer_link($temp . '/map.json', 'https://example.de/blog', '//attacker/path') === '', 'Invalid request target emits no link');
check(standard_stuffer_link($temp . '/missing.json', 'https://example.de/blog', '/blog/story') === '', 'Missing mapping never breaks website');
file_put_contents($temp . '/corrupt.json', 'bad json');
check(standard_stuffer_link($temp . '/corrupt.json', 'https://example.de/blog', '/blog/story') === '', 'Corrupt mapping never breaks website');
$map['documents']['https://example.de/blog/story'] = '" onload="alert(1)'; file_put_contents($temp . '/unsafe.json', json_encode_safe($map));
check(standard_stuffer_link($temp . '/unsafe.json', 'https://example.de/blog', '/blog/story') === '', 'Malformed AT-URI cannot inject HTML');
$map['documents'] = ['https://example.de/blog/story?id=1' => $doc['uri']]; file_put_contents($temp . '/query.json', json_encode_safe($map));
check(standard_stuffer_link($temp . '/query.json', 'https://example.de/blog', '/blog/story?id=2') === '', 'Query-specific page never matches a different ID');
check(strpos(standard_stuffer_link($temp . '/query.json', 'https://example.de/blog', '/blog/story?id=1'), $doc['uri']) !== false, 'Exact query-specific mapping works');
$state['documents']['doc']['status'] = 'removed'; $state['documents']['doc']['cid'] = null;
$empty_payload = mapping_payload($state); file_put_contents($temp . '/removed.json', $empty_payload);
check(strpos($empty_payload, '"documents": {}') !== false, 'Empty published set is a JSON object');
check(standard_stuffer_link($temp . '/removed.json', 'https://example.de/blog', '/blog/story') === '', 'Deploying deletion removes generated link');
$proxy = proxy_options(['url' => 'http://proxy.example:8080', 'username' => 'user', 'password' => 'test', 'auth' => 'ntlm'], 'example.de', '1.1.1.1');
check($proxy[CURLOPT_CONNECT_TO] === ['example.de:443:1.1.1.1:443'] && $proxy[CURLOPT_HTTPPROXYTUNNEL], 'Proxy tunnels to pinned public IP while URL keeps TLS identity');
check($proxy[CURLOPT_NOPROXY] === '' && $proxy[CURLOPT_PROXYAUTH] === CURLAUTH_NTLM, 'Explicit proxy cannot be bypassed by environment no_proxy');
check(proxy_options([], 'example.de', '1.1.1.1')[CURLOPT_PROXY] === '', 'Disabled proxy ignores ambient proxy environment');
throws(static function () { proxy_options(['url' => 'http://secret:pass@proxy.example'], 'example.de', '1.1.1.1'); }, 'Embedded proxy credentials rejected');
throws(static function () { proxy_options(['url' => 'socks5://proxy.example'], 'example.de', '1.1.1.1'); }, 'Unsupported proxy scheme rejected');
$settings = ['protocol' => 'ftps', 'host' => 'deploy.example.de', 'username' => 'tester', 'password' => 'test-only', 'remote_path' => '/private/map.json'];
$options = deployment_options($settings);
check($options[CURLOPT_USE_SSL] === CURLUSESSL_ALL && $options[CURLOPT_FTPSSLAUTH] === CURLFTPAUTH_TLS, 'FTPS requires explicit TLS on control and data channels');
check($options[CURLOPT_SSL_VERIFYPEER] && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'FTPS certificate and hostname verification cannot be disabled');
check($options[CURLOPT_FTP_SKIP_PASV_IP] && $options[CURLOPT_PROXY] === '', 'FTPS data connection ignores foreign PASV address and page proxy');
check(deployment_url($settings + ['port' => 21], '/private/map.json') === 'ftp://deploy.example.de:21/%2Fprivate/map.json', 'FTPS uses absolute server path and explicit TLS URL scheme');
throws(static function () use ($settings) { validate_deployment(array_merge($settings, ['protocol' => 'ftp'])); }, 'Plain FTP is never permitted');
throws(static function () use ($settings) { validate_deployment(array_merge($settings, ['remote_path' => "/file.json\r\nDELE other"])); }, 'FTP command injection rejected');
throws(static function () use ($settings) { validate_deployment(array_merge($settings, ['remote_path' => '/safe/../other.json'])); }, 'Remote parent traversal rejected');
if (defined('CURLOPT_SSH_KNOWNHOSTS')) {
    $ssh = deployment_options(['protocol' => 'sftp', 'username' => 'tester', 'password' => 'test', 'known_hosts' => '/private/known_hosts']);
    check($ssh[CURLOPT_SSH_KNOWNHOSTS] === '/private/known_hosts' && $ssh[CURLOPT_SSH_AUTH_TYPES] === CURLSSH_AUTH_PASSWORD, 'SFTP enforces configured known-hosts trust file');
}
if (in_array('ftp', curl_version()['protocols'], true)) {
    $remote = ['/private/map.json' => 'old']; $calls = [];
    $transfer = static function ($settings, $operation, $path, $body = '', $destination = null) use (&$remote, &$calls): string {
        $calls[] = $operation;
        if ($operation === 'upload') $remote[$path] = $body;
        if ($operation === 'rename') { $remote[$destination] = $remote[$path]; unset($remote[$path]); }
        return $operation === 'read' ? ($remote[$path] ?? '') : '';
    };
    $result = deploy_mapping($settings, $payload, $transfer);
    check($calls === ['upload', 'read', 'rename', 'read'] && $remote['/private/map.json'] === $payload, 'Readback verified before replacement and active file checked afterwards');
    $state['deployment'] = $result; $state['documents']['doc'] = $doc;
    check(mapping_is_deployed($state, $settings), 'Default port and checksum yield correct deployment freshness');
    $bad_transfer = static function ($settings, $operation, $path, $body = '', $destination = null) use ($transfer): string { if ($operation === 'read') return 'corrupted'; return $transfer($settings, $operation, $path, $body, $destination); };
    $calls = []; $remote['/private/map.json'] = 'preserved';
    throws(static function () use ($settings, $payload, $bad_transfer) { deploy_mapping($settings, $payload, $bad_transfer); }, 'Corrupt temporary upload aborts deployment');
    check($remote['/private/map.json'] === 'preserved' && !in_array('rename', $calls, true), 'Active mapping remains intact after failed readback');
    $lost_reply = static function ($settings, $operation, $path, $body = '', $destination = null) use ($transfer): string { $result = $transfer($settings, $operation, $path, $body, $destination); if ($operation === 'rename') throw new RuntimeException('Lost reply'); return $result; };
    check(deploy_mapping($settings, $payload, $lost_reply)['sha256'] === hash('sha256', $payload), 'Lost rename reply recovered by active-file checksum');
} else echo "SKIP: transfer orchestration needs cURL FTP support.\n";
foreach (glob($temp . '/*') as $path) unlink($path); rmdir($temp);
echo $passed . " checks passed.\n";
