<?php
// Parse every shipped PHP file without executing it.
$root = dirname(__DIR__);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php' || strpos($file->getPathname(), '/var/') !== false || $file->getFilename() === 'config.php') continue;
    token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
    echo 'OK: ' . substr($file->getPathname(), strlen($root) + 1) . "\n";
}
