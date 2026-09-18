<?php
// Verified temporary upload followed by server-side replacement, created for Uli.
declare(strict_types=1);
require_once __DIR__ . '/proxy.php';
function validate_deployment(array $settings): array {
    if (!in_array($settings['protocol'] ?? '', ['sftp', 'ftps'], true)) throw new RuntimeException('Deployment-Protokoll in config.php auf sftp oder ftps setzen.');
    $host = $settings['host'] ?? '';
    if (!is_string($host) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*$/D', $host)) throw new RuntimeException('Deployment-Host ohne Schema oder Pfad eintragen.');
    $port = $settings['port'] ?? ($settings['protocol'] === 'sftp' ? 22 : 21);
    if (!is_int($port) || $port < 1 || $port > 65535) throw new RuntimeException('Ungültiger Deployment-Port.');
    $path = $settings['remote_path'] ?? '';
    // Restrict command paths instead of interpolating arbitrary FTP/SFTP commands.
    if (!is_string($path) || !preg_match('~^/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\.json$~D', $path) || strlen($path) > 1000 || preg_match('~/(?:\.|\.\.)(?:/|$)~', $path)) throw new RuntimeException('Absoluten Remote-Pfad zu einer JSON-Datei verwenden: nur Buchstaben, Ziffern, /, Punkt, _ und -.');
    if (!is_string($settings['username'] ?? null) || $settings['username'] === '' || preg_match('/[\r\n\x00]/', $settings['username'])) throw new RuntimeException('Deployment-Benutzer fehlt oder ist ungültig.');
    $protocols = curl_version()['protocols'];
    if (!in_array($settings['protocol'] === 'sftp' ? 'sftp' : 'ftp', $protocols, true)) throw new RuntimeException('Das PHP-cURL dieses Servers unterstützt ' . strtoupper($settings['protocol']) . ' nicht.');
    if ($settings['protocol'] === 'sftp') {
        if (!defined('CURLOPT_SSH_KNOWNHOSTS')) throw new RuntimeException('cURL unterstützt keine SFTP-Hostkey-Prüfung.');
        readable_config_file((string) ($settings['known_hosts'] ?? ''));
        if (!empty($settings['private_key'])) readable_config_file($settings['private_key']);
        elseif (($settings['password'] ?? '') === '') throw new RuntimeException('SFTP benötigt einen privaten Schlüssel oder ein Passwort.');
        if (!empty($settings['public_key'])) readable_config_file($settings['public_key']);
    } elseif (($settings['password'] ?? '') === '') throw new RuntimeException('FTPS benötigt ein Passwort.');
    if (!empty($settings['ca_file'])) readable_config_file($settings['ca_file']);
    $settings['port'] = $port;
    return $settings;
}
function deployment_url(array $settings, string $path): string {
    $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
    $scheme = $settings['protocol'] === 'sftp' ? 'sftp' : 'ftp';
    // ftp:// plus mandatory AUTH TLS means explicit FTPS, not implicit FTPS.
    return $scheme . '://' . $settings['host'] . ':' . $settings['port'] . ($scheme === 'ftp' ? '/%2F' . ltrim($encoded, '/') : $encoded);
}
function deployment_options(array $settings): array {
    $options = [CURLOPT_USERNAME => $settings['username'], CURLOPT_PROXY => '',
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true];
    if ($settings['protocol'] === 'sftp') {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_SFTP;
        $options[CURLOPT_SSH_KNOWNHOSTS] = $settings['known_hosts'];
        if (!empty($settings['private_key'])) {
            $options[CURLOPT_SSH_AUTH_TYPES] = CURLSSH_AUTH_PUBLICKEY;
            $options[CURLOPT_SSH_PRIVATE_KEYFILE] = $settings['private_key'];
            if (!empty($settings['public_key'])) $options[CURLOPT_SSH_PUBLIC_KEYFILE] = $settings['public_key'];
            $options[CURLOPT_KEYPASSWD] = $settings['key_passphrase'] ?? '';
        } else { $options[CURLOPT_SSH_AUTH_TYPES] = CURLSSH_AUTH_PASSWORD; $options[CURLOPT_PASSWORD] = $settings['password']; }
    } else {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_FTP;
        $options[CURLOPT_PASSWORD] = $settings['password'];
        $options[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
        $options[CURLOPT_FTPSSLAUTH] = CURLFTPAUTH_TLS;
        $options[CURLOPT_FTP_SKIP_PASV_IP] = true;
        $options[CURLOPT_FTP_USE_EPSV] = true;
        if (!empty($settings['ca_file'])) $options[CURLOPT_CAINFO] = $settings['ca_file'];
    }
    return $options;
}
function deployment_transfer(array $settings, string $operation, string $path, string $payload = '', ?string $destination = null): string {
    if (!in_array($operation, ['read', 'upload', 'rename'], true)) throw new LogicException('Unknown deployment operation');
    $handle = curl_init(deployment_url($settings, $path)); $response = ''; $stream = null;
    $options = deployment_options($settings);
    $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use (&$response): int {
        if (strlen($response) + strlen($chunk) > 5000000) return 0;
        $response .= $chunk; return strlen($chunk);
    };
    try {
        if ($operation === 'upload') {
            $stream = fopen('php://temp', 'w+b');
            if (!$stream || fwrite($stream, $payload) !== strlen($payload)) throw new RuntimeException('Deployment-Puffer konnte nicht erstellt werden.');
            rewind($stream); $options[CURLOPT_UPLOAD] = true; $options[CURLOPT_INFILE] = $stream; $options[CURLOPT_INFILESIZE] = strlen($payload);
        } elseif ($operation === 'rename') {
            // Rename is deliberately not emulated by deleting the existing active file.
            $options[CURLOPT_QUOTE] = $settings['protocol'] === 'sftp'
                ? ['rename ' . $path . ' ' . $destination]
                : ['RNFR ' . $path, 'RNTO ' . $destination];
            curl_setopt($handle, CURLOPT_URL, deployment_url($settings, (string) $destination));
            $options[CURLOPT_NOBODY] = true;
        }
        if (!curl_setopt_array($handle, $options)) throw new RuntimeException('Deployment-Optionen werden von PHP-cURL nicht unterstützt.');
        if (curl_exec($handle) === false) throw new RuntimeException('Deployment fehlgeschlagen (cURL ' . curl_errno($handle) . '). Zugangsdaten, Hostkey/Zertifikat, Remote-Pfad und Serverrechte prüfen.');
        return $response;
    } finally { curl_close($handle); if (is_resource($stream)) fclose($stream); }
}
function deploy_mapping(array $settings, string $payload, ?callable $transfer = null): array {
    $settings = validate_deployment($settings);
    $transfer = $transfer ?? 'deployment_transfer';
    $sha = hash('sha256', $payload); $path = $settings['remote_path'];
    $temp_path = $path . '.upload-' . bin2hex(random_bytes(8));
    $transfer($settings, 'upload', $temp_path, $payload);
    $uploaded = $transfer($settings, 'read', $temp_path);
    if (!hash_equals($sha, hash('sha256', $uploaded))) throw new RuntimeException('Remote-Prüfsumme der temporären Datei stimmt nicht. Die aktive Datei wurde nicht verändert.');
    try { $transfer($settings, 'rename', $temp_path, '', $path); }
    catch (RuntimeException $e) {
        // A server may have renamed successfully before its reply was lost.
        try { $active = $transfer($settings, 'read', $path); }
        catch (RuntimeException $ignored) { throw $e; }
        if (!hash_equals($sha, hash('sha256', $active))) throw $e;
    }
    $active = $transfer($settings, 'read', $path);
    if (!hash_equals($sha, hash('sha256', $active))) throw new RuntimeException('Aktive Remote-Datei konnte nicht bestätigt werden. Deployment erneut prüfen.');
    return ['sha256' => $sha, 'at' => now_iso(), 'target' => deployment_fingerprint($settings), 'bytes' => strlen($payload)];
}
