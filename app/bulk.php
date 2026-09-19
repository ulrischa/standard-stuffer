<?php
// Resumable, single-request work queue. Maintainer: Uli.
declare(strict_types=1);
function sitemap_entries(string $xml): array {
    if (substr($xml, 0, 2) === "\x1f\x8b") {
        if (!function_exists('gzdecode')) throw new RuntimeException('Gzip sitemaps require PHP zlib.');
        $xml = @gzdecode($xml, 8000001);
        if ($xml === false) throw new RuntimeException('Invalid gzip sitemap.');
    }
    if (strlen($xml) > 8000000 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) throw new RuntimeException('Sitemap is too large or contains prohibited XML declarations.');
    $previous = libxml_use_internal_errors(true);
    try {
        $dom = new DOMDocument();
        if (!$dom->loadXML($xml, LIBXML_NONET) || $dom->doctype) throw new RuntimeException('Invalid XML sitemap.');
        $root = $dom->documentElement;
        if (!in_array($root->localName, ['urlset', 'sitemapindex'], true) || !in_array($root->namespaceURI, [null, '', 'http://www.sitemaps.org/schemas/sitemap/0.9'], true)) throw new RuntimeException('XML must contain urlset or sitemapindex.');
        $index = $root->localName === 'sitemapindex'; $urls = [];
        foreach ($root->childNodes as $entry) {
            if (!$entry instanceof DOMElement || $entry->namespaceURI !== $root->namespaceURI || $entry->localName !== ($index ? 'sitemap' : 'url')) continue;
            foreach ($entry->childNodes as $node) if ($node instanceof DOMElement && $node->localName === 'loc' && $node->namespaceURI === $root->namespaceURI) {
                $url = trim($node->textContent);
                if (strlen($url) > 2048) throw new RuntimeException('Sitemap contains an oversized URL.');
                $urls[$url] = true; break;
            }
        }
        return ['index' => $index, 'urls' => array_keys($urls)];
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
}
function bulk_start(array &$state, string $mode, string $sitemap = ''): void {
    if (!in_array($mode, ['update', 'sitemap'], true)) throw new RuntimeException('Unknown bulk job.');
    if (($state['bulk']['status'] ?? '') === 'running') throw new RuntimeException('Complete or stop the active bulk job first.');
    if (empty($state['publication']['cid']) || $state['publication']['pending']) throw new RuntimeException('Finish publishing the publication first.');
    if ($mode === 'update') require_account($state);
    $queue = [];
    if ($mode === 'sitemap') {
        $parts = https_url($sitemap);
        if (strtolower($parts['host']) !== strtolower(parse_url($state['publication']['record']['url'], PHP_URL_HOST))) throw new RuntimeException('The sitemap must be on the configured website.');
        $queue[] = ['kind' => 'sitemap', 'url' => $sitemap];
    } else foreach ($state['documents'] as $id => $item) if ($item['status'] !== 'removed') $queue[] = ['kind' => 'update', 'id' => (string) $id, 'url' => $item['url']];
    $state['bulk'] = ['id' => bin2hex(random_bytes(12)), 'mode' => $mode, 'status' => $queue ? 'running' : 'done', 'queue' => $queue, 'cursor' => 0, 'seen' => $sitemap !== '' ? [$sitemap => true] : [], 'sitemaps' => $sitemap !== '' ? 1 : 0, 'urls' => 0, 'log' => [], 'counts' => ['ok' => 0, 'error' => 0, 'skip' => 0], 'started_at' => now_iso()];
    state_save($state);
}
function bulk_document(array &$state, array $task): string {
    $id = $task['id'] ?? '';
    if ($task['kind'] === 'import') {
        foreach ($state['documents'] as $existing) if ($existing['url'] === $task['url']) return 'skip: URL is already managed.';
    } else {
        if (!isset($state['documents'][$id]) || $state['documents'][$id]['status'] === 'removed') return 'skip: Entry has already been removed.';
        if ($state['documents'][$id]['pending']) return 'skip: Complete the pending PDS operation in the article first.';
    }
    $url = $task['url']; $publication = $state['publication'];
    document_path($url, $publication['record']['url']);
    $response = http_request($url, 'GET', null, [], 4000000, true);
    if (in_array($response['status'], [404, 410], true) && $task['kind'] === 'update') {
        if ($state['documents'][$id]['cid']) sync_item($state, $state['documents'][$id], 'delete');
        else unset($state['documents'][$id]);
        return 'ok: HTTP ' . $response['status'] . ' – Record deleted. Redeploy the mapping.';
    }
    if ($response['status'] !== 200) throw new RuntimeException('HTTP ' . $response['status'] . ' – Existing record preserved.');
    if (stripos($response['type'], 'text/html') !== 0 && stripos($response['type'], 'application/xhtml+xml') !== 0) throw new RuntimeException('Not HTML; entry unchanged.');
    $preview = extract_with_selectors($response['body'], $url, $state['selectors'] ?? selector_defaults());
    $old = $id !== '' ? $state['documents'][$id] : null;
    if ($old && !empty($old['record']['textContent']) && $preview['textContent'] === '') throw new RuntimeException('No article text found; existing content preserved. Check selectors.');
    if ($preview['publishedAt'] === '' && $old) $preview['publishedAt'] = $old['record']['publishedAt'] ?? '';
    // Missing publication dates remain editable drafts; sitemap lastmod is not a publication date.
    $missing_date = $preview['publishedAt'] === '';
    if ($missing_date && $old && $old['cid']) throw new RuntimeException('Publication date missing; entry unchanged.');
    $record = document_record($preview, $publication, $missing_date);
    if ($old && $old['cid']) {
        require_account($state);
        if ($preview['cover_url'] !== '') $record['coverImage'] = upload_cover($preview['cover_url'], $state);
        $compare = $old['published_record']; unset($compare['updatedAt']);
        if ($compare != $record) $record['updatedAt'] = now_iso();
        elseif (isset($old['published_record']['updatedAt'])) $record['updatedAt'] = $old['published_record']['updatedAt'];
    }
    if (!$old) { $item = new_item($state['did'], 'site.standard.document'); $id = $item['rkey']; $state['documents'][$id] = $item; }
    $item = &$state['documents'][$id];
    $item['record'] = $record; $item['url'] = $url; $item['cover_url'] = $preview['cover_url'];
    $item['source_html'] = $preview['source_html']; $item['matches'] = $preview['matches']; $item['check'] = null; $item['edited_at'] = now_iso();
    if ($item['cid'] && $record != $item['published_record']) sync_item($state, $item, 'put');
    return 'ok: ' . ($item['cid'] ? 'Content synchronized with the PDS.' : 'Draft saved.' . ($missing_date ? ' Add the publication date before publishing.' : ''));
}
function bulk_step(array &$state, string $job_id): string {
    if (($state['bulk']['id'] ?? '') !== $job_id || ($state['bulk']['status'] ?? '') !== 'running') throw new RuntimeException('This bulk job is no longer active.');
    $job = &$state['bulk']; $task = $job['queue'][$job['cursor']];
    try {
        if ($task['kind'] === 'sitemap') {
            $response = http_request($task['url'], 'GET', null, [], 8000000, true);
            if ($response['status'] !== 200) throw new RuntimeException('Sitemap returned HTTP ' . $response['status']);
            $entries = sitemap_entries($response['body']);
            $next = []; $seen = $job['seen']; $sitemaps = $job['sitemaps']; $urls = $job['urls']; $excluded = 0;
            foreach ($entries['urls'] as $url) {
                try {
                    $parts = https_url($url);
                    if (strtolower($parts['host']) !== strtolower(parse_url($state['publication']['record']['url'], PHP_URL_HOST))) throw new RuntimeException('External domain');
                    if (!$entries['index']) $url = $state['publication']['record']['url'] . document_path($url, $state['publication']['record']['url']);
                } catch (RuntimeException $e) { $excluded++; continue; }
                $key = ($entries['index'] ? 'map:' : 'url:') . $url;
                if (isset($seen[$key]) || ($entries['index'] && isset($seen[$url]))) continue;
                $seen[$key] = true;
                if ($entries['index']) $sitemaps++; else $urls++;
                if ($sitemaps > 100 || $urls > 10000) throw new RuntimeException('Import limit reached (100 sitemaps / 10,000 URLs). Use a smaller sitemap; this file was not partially imported.');
                $next[] = ['kind' => $entries['index'] ? 'sitemap' : 'import', 'url' => $url];
            }
            $job['queue'] = array_merge($job['queue'], $next); $job['seen'] = $seen; $job['sitemaps'] = $sitemaps; $job['urls'] = $urls;
            $message = 'ok: ' . count($next) . ' entries queued; ' . $excluded . ' ineligible URLs skipped.';
        } else $message = bulk_document($state, $task);
    } catch (RuntimeException $e) { $message = 'error: ' . $e->getMessage(); }
    $kind = explode(':', $message, 2)[0]; $job['counts'][$kind]++;
    $job['log'][] = ['url' => $task['url'], 'result' => $message, 'at' => now_iso()];
    $job['cursor']++;
    if ($job['cursor'] >= count($job['queue'])) { $job['status'] = 'done'; $job['finished_at'] = now_iso(); }
    state_save($state);
    return $message;
}
