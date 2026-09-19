<?php
// HTTPS transport pins a public DNS address to prevent SSRF and DNS rebinding.
declare(strict_types=1);
require_once __DIR__ . '/proxy.php';
function public_ip(string $ip): bool {
    return (int) explode('.', $ip)[0] < 224 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
        && !preg_match('/^(0\.|127\.|169\.254\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|192\.0\.0\.|198\.(18|19)\.)/', $ip);
}
function http_request(string $url, string $method = 'GET', ?string $body = null, array $headers = [], int $limit = 4000000, bool $use_proxy = false): array {
    global $config;
    $parts = https_url($url); $host = strtolower($parts['host']);
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);
    if (!$ips) throw new RuntimeException('Cannot resolve the domain (a public IPv4 address is required).');
    foreach ($ips as $ip) if (!public_ip($ip)) throw new RuntimeException('Requests to private or reserved IP addresses are blocked.');
    $proxy_settings = $use_proxy ? ($config['fetch_proxy'] ?? []) : [];
    $proxy_options = proxy_options($proxy_settings, $host, $ips[0]);
    $handle = curl_init($url); $result = ''; $too_large = false; $location = '';
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROXY => '', CURLOPT_RESOLVE => [$host . ':443:' . $ips[0]],
        CURLOPT_USERAGENT => 'standard-stuffer/1.1', CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json, text/html;q=0.9, */*;q=0.5', 'Cache-Control: no-cache'], $headers),
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$result, &$too_large, $limit): int {
            if (strlen($result) + strlen($chunk) > $limit) { $too_large = true; return 0; }
            $result .= $chunk; return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location): int {
            if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
            return strlen($line);
        }
    ]);
    if (!curl_setopt_array($handle, $proxy_options)) { curl_close($handle); throw new RuntimeException('Could not enable proxy options.'); }
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    $ok = curl_exec($handle); $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE); $type = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE); curl_close($handle);
    if ($too_large) throw new RuntimeException('Response exceeds the size limit of ' . $limit . ' bytes.');
    if ($ok === false) throw new RuntimeException('HTTPS request failed (connection, certificate or timeout).');
    if ($code >= 300 && $code < 400) throw new RuntimeException('HTTP redirect (' . $code . '). Use the final HTTPS URL; redirects are not followed automatically.' . ($location ? ' Destination: ' . mb_substr($location, 0, 300) : ''));
    return ['status' => $code, 'body' => $result, 'type' => $type];
}
function fetch_html(string $url): string {
    $response = http_request($url, 'GET', null, [], 4000000, true);
    if ($response['status'] !== 200) throw new RuntimeException('Page returned HTTP ' . $response['status'] . '.');
    if (stripos($response['type'], 'text/html') !== 0 && stripos($response['type'], 'application/xhtml+xml') !== 0) throw new RuntimeException('The URL does not return HTML.');
    return $response['body'];
}
