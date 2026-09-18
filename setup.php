<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (is_file(__DIR__ . '/config.php')) { fwrite(STDERR, "config.php existiert bereits; keine Änderung vorgenommen.\n"); exit(1); }
fwrite(STDOUT, "standard-stuffer – Einrichtung\nDashboard-Passwort (mindestens 14 Zeichen; Eingabe ist sichtbar): ");
$password = rtrim((string) fgets(STDIN), "\r\n");
if (strlen($password) < 14) { fwrite(STDERR, "Passwort zu kurz.\n"); exit(1); }
$config = require __DIR__ . '/config.example.php';
$config['admin_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
$handle = fopen(__DIR__ . '/config.php', 'x');
if (!$handle) exit(1);
chmod(__DIR__ . '/config.php', 0600);
$template = file_get_contents(__DIR__ . '/config.example.php');
$template = str_replace("'REPLACE_WITH_PASSWORD_HASH'", var_export($config['admin_password_hash'], true), $template);
fwrite($handle, $template); fclose($handle);
if (!is_dir(__DIR__ . '/var')) mkdir(__DIR__ . '/var', 0700);
fwrite(STDOUT, "Fertig. Nur public/ als Webverzeichnis ausliefern. config.php und var/ müssen für den PHP-Benutzer lesbar bzw. beschreibbar sein.\n");
