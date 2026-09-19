<?php
// Public projection: only confirmed PDS associations, never drafts or credentials.
declare(strict_types=1);
function mapping_payload(array $state): string {
    $publication = $state['publication'];
    if (!$publication || !$publication['cid'] || !$publication['published_record']) throw new RuntimeException('Publish the publication successfully first.');
    if ($publication['pending']) throw new RuntimeException('Complete the pending publication operation before deploying.');
    $documents = [];
    foreach ($state['documents'] as $item) {
        if ($item['pending']) throw new RuntimeException('Complete all pending PDS operations before deploying.');
        if ($item['status'] === 'published' && $item['cid'] && $item['published_record']) {
            $url = $publication['published_record']['url'] . ($item['published_record']['path'] ?? '');
            $documents[$url] = $item['uri'];
        }
    }
    ksort($documents, SORT_STRING);
    $json = json_encode_safe(['version' => 1, 'publication' => ['url' => $publication['published_record']['url'], 'uri' => $publication['uri']], 'documents' => (object) $documents]) . "\n";
    if (strlen($json) > 5000000) throw new RuntimeException('The mapping file exceeds 5 MB.');
    return $json;
}
function deployment_fingerprint(array $settings): string {
    return hash('sha256', json_encode_safe([$settings['protocol'] ?? '', $settings['host'] ?? '', $settings['port'] ?? (($settings['protocol'] ?? '') === 'sftp' ? 22 : 21), $settings['username'] ?? '', $settings['remote_path'] ?? '']));
}
function mapping_is_deployed(array $state, array $settings): bool {
    try { return ($state['deployment']['sha256'] ?? '') === hash('sha256', mapping_payload($state)) && ($state['deployment']['target'] ?? '') === deployment_fingerprint($settings); }
    catch (RuntimeException $e) { return false; }
}
