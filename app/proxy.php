<?php
// Explicit proxy configuration for Uli; never inherited from process environment.
declare(strict_types=1);
function proxy_options(array $proxy, string $host, string $ip): array {
    if (empty($proxy['url'])) return [CURLOPT_PROXY => ''];
    $url = $proxy['url']; $parts = is_string($url) ? parse_url($url) : false;
    if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || preg_match('/[\s\\\\]/', $url) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) throw new RuntimeException('Invalid proxy URL in config.php. Use an HTTP(S) host and port without embedded credentials.');
    if (!defined('CURLOPT_CONNECT_TO')) throw new RuntimeException('Proxy mode requires cURL 7.49 or later with CONNECT_TO.');
    $options = [CURLOPT_PROXY => $url, CURLOPT_NOPROXY => '', CURLOPT_HTTPPROXYTUNNEL => true,
        CURLOPT_CONNECT_TO => [$host . ':443:' . $ip . ':443']];
    $auth = $proxy['auth'] ?? 'basic';
    $auth_types = ['basic' => CURLAUTH_BASIC, 'digest' => CURLAUTH_DIGEST, 'ntlm' => CURLAUTH_NTLM];
    if (!isset($auth_types[$auth])) throw new RuntimeException('Proxy authentication must be basic, digest or ntlm.');
    if (($proxy['username'] ?? '') !== '') {
        $options[CURLOPT_PROXYUSERNAME] = (string) $proxy['username'];
        $options[CURLOPT_PROXYPASSWORD] = (string) ($proxy['password'] ?? '');
        $options[CURLOPT_PROXYAUTH] = $auth_types[$auth];
    }
    if ($parts['scheme'] === 'https') {
        if (!defined('CURLOPT_PROXY_SSL_VERIFYPEER')) throw new RuntimeException('This cURL version does not support verified HTTPS proxies.');
        $options[CURLOPT_PROXY_SSL_VERIFYPEER] = true; $options[CURLOPT_PROXY_SSL_VERIFYHOST] = 2;
        if (!empty($proxy['proxy_ca_file'])) $options[CURLOPT_PROXY_CAINFO] = readable_config_file($proxy['proxy_ca_file']);
    }
    if (!empty($proxy['origin_ca_file'])) $options[CURLOPT_CAINFO] = readable_config_file($proxy['origin_ca_file']);
    return $options;
}
function readable_config_file(string $path): string {
    if (!is_file($path) || !is_readable($path)) throw new RuntimeException('A configured certificate, host key or key file is not readable.');
    return $path;
}
