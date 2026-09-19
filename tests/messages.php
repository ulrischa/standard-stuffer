<?php
// Historical messages must render in English without modifying stored content. Maintainer: Uli.
declare(strict_types=1);
require __DIR__ . '/../app/messages.php';
$cases = [
    'Domain-Rückverweis stimmt überein. PDS-Datensatz bestätigt.' => 'Domain backlink matches. PDS record confirmed.',
    'HTTP 404: Die Antwort muss ausschließlich die Publication-AT-URI enthalten.' => 'HTTP 404: The response must contain only the publication AT-URI.',
    'ok: HTTP 410 – Datensatz gelöscht. Zuordnung neu deployen.' => 'ok: HTTP 410 – Record deleted. Redeploy the mapping.',
    'ok: 5 Einträge vorgemerkt; 2 unzulässige URLs übersprungen.' => 'ok: 5 entries queued; 2 ineligible URLs skipped.',
    'Titel: bitte Länge prüfen (maximal 500 Zeichen).' => 'Title: check the length (maximum 500 characters).',
    'error: HTTP 503 – vorhandener Datensatz bleibt erhalten.' => 'error: HTTP 503 – Existing record preserved.',
    'The page specifies a different canonical URL: https://example.de/Titel' => 'The page specifies a different canonical URL: https://example.de/Titel',
];
foreach ($cases as $source => $expected) {
    if (english_message($source) !== $expected || english_message($expected) !== $expected) throw new RuntimeException('Message translation or idempotence failed: ' . $source);
}
echo count($cases) . " message compatibility checks passed.\n";
