<?php
// Copy to config.php outside the public document root. Maintainer: Uli.
return [
    'admin_password_hash' => 'REPLACE_WITH_PASSWORD_HASH',
    'data_dir' => __DIR__ . '/var',
    // Enable only for a local development server bound to 127.0.0.1.
    'allow_http_localhost' => false,
    // Optional HTTP(S) CONNECT proxy, only for pages, covers and verification.
    // The proxy must allow CONNECT to the pinned public origin IP on port 443.
    'fetch_proxy' => [
        'url' => '', // Example: http://proxy.example.net:8080
        'username' => getenv('STANDARD_STUFFER_PROXY_USER') ?: '',
        'password' => getenv('STANDARD_STUFFER_PROXY_PASSWORD') ?: '',
        'auth' => 'basic', // basic, digest or ntlm
        'proxy_ca_file' => '', // CA bundle for an HTTPS proxy's own certificate.
        'origin_ca_file' => '', // CA bundle for origin certificates / TLS inspection.
    ],
    // One explicitly configured destination; secrets never enter dashboard.json.
    'deployment' => [
        'protocol' => 'sftp', // sftp or ftps (explicit AUTH TLS, never plain FTP).
        'host' => '',
        'port' => 22, // Use 21 for explicit FTPS, or your provider's custom port.
        'username' => getenv('STANDARD_STUFFER_DEPLOY_USER') ?: '',
        'password' => getenv('STANDARD_STUFFER_DEPLOY_PASSWORD') ?: '',
        'remote_path' => '/private/standard-stuffer-map.json',
        'known_hosts' => __DIR__ . '/credentials/known_hosts', // Mandatory for SFTP.
        'private_key' => '', // Optional SFTP private key; otherwise use password.
        'public_key' => '',
        'key_passphrase' => getenv('STANDARD_STUFFER_KEY_PASSPHRASE') ?: '',
        'ca_file' => '', // Optional FTPS CA bundle; default is system trust store.
    ],
];
