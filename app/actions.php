<?php
declare(strict_types=1);
function handle_action(string $action, array &$state): string {
    global $config;
    if ($action === 'deploy_mapping' || $action === 'check_deployment') {
        $payload = mapping_payload($state);
        $settings = $config['deployment'] ?? [];
        if ($action === 'deploy_mapping') {
            if (input_string($_POST, 'confirm', 10) !== 'yes') throw new RuntimeException('Confirm replacement of the specified remote file.');
            $state['deployment'] = deploy_mapping($settings, $payload);
            $state['deployment_check'] = ['ok' => true, 'at' => now_iso(), 'message' => 'Remote file transferred and verified using SHA-256.'];
        } else {
            try {
                $validated = validate_deployment($settings);
                $remote = deployment_transfer($validated, 'read', $validated['remote_path']);
                $ok = hash_equals(hash('sha256', $payload), hash('sha256', $remote));
                $state['deployment_check'] = ['ok' => $ok, 'at' => now_iso(), 'message' => $ok ? 'Remote mapping matches the current version.' : 'Remote file differs. Deploy the current mapping.'];
                if ($ok) $state['deployment'] = ['sha256' => hash('sha256', $payload), 'at' => now_iso(), 'target' => deployment_fingerprint($validated), 'bytes' => strlen($payload)];
            } catch (RuntimeException $e) {
                $state['deployment_check'] = ['ok' => false, 'at' => now_iso(), 'message' => $e->getMessage()];
            }
        }
        state_save($state);
        return $state['deployment_check']['message'];
    }
    if ($action === 'bulk_start') {
        if (input_string($_POST, 'confirm', 10) !== 'yes') throw new RuntimeException('Confirm the bulk changes described above.');
        bulk_start($state, input_string($_POST, 'mode', 20), input_string($_POST, 'sitemap', 2048));
        return 'Bulk job prepared. Start processing now.';
    }
    if ($action === 'bulk_step') return bulk_step($state, input_string($_POST, 'job_id', 50));
    if ($action === 'bulk_stop') {
        if (($state['bulk']['id'] ?? '') !== input_string($_POST, 'job_id', 50)) throw new RuntimeException('This bulk job has been replaced.');
        $state['bulk']['status'] = 'stopped'; state_save($state);
        return 'Bulk job stopped. Completed changes remain in place.';
    }
    $id = input_string($_POST, 'id', 100);
    if ($action === 'disconnect') { unset($_SESSION['pds']); return 'Disconnected. Published records remain in place.'; }
    if ($action === 'connect') {
        connect_pds(input_string($_POST, 'pds', 2048), input_string($_POST, 'identifier', 300), input_password($_POST, 'app_password'), $state['did']);
        if ($state['did'] === '') { $state['did'] = $_SESSION['pds']['did']; state_save($state); }
        return 'Connected to the PDS. The app password is not stored.';
    }
    if ($action === 'selectors') {
        $selectors = [];
        foreach (selector_defaults() as $field => $defaults) {
            $value = $_POST['selectors'][$field] ?? '';
            if (!is_string($value) || strlen($value) > 6000) throw new RuntimeException('Invalid selector list.');
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $value))));
            if (count($lines) > 20) throw new RuntimeException('Maximum 20 selectors per field.');
            foreach ($lines as $line) css_xpath($line);
            $selectors[$field] = $lines;
        }
        $hosts = array_values(array_filter(array_map('strtolower', array_map('trim', preg_split('/[\r\n,]+/', input_string($_POST, 'image_hosts', 2000))))));
        foreach ($hosts as $host) if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) throw new RuntimeException('Enter image domains without https:// or a path.');
        $state['selectors'] = $selectors; $state['image_hosts'] = array_unique($hosts); state_save($state);
        return 'Extraction rules saved. They apply to the next import.';
    }
    if ($action === 'publication') {
        require_account($state);
        $url = publication_url(input_string($_POST, 'url', 2048));
        $name = limited_text(input_string($_POST, 'name'), 'Name', 5000, 500, true);
        $description = limited_text(input_string($_POST, 'description', 30000), 'Description', 30000, 3000);
        if (!$state['publication']) $state['publication'] = new_item($state['did'], 'site.standard.publication');
        $publication = &$state['publication'];
        if ($publication['pending']) throw new RuntimeException('Retry the pending publication operation first.');
        if ($publication['cid'] && $publication['record']['url'] !== $url) throw new RuntimeException('The website URL of a published publication cannot be changed.');
        $publication['record'] = ['$type' => 'site.standard.publication', 'url' => $url, 'name' => $name];
        if ($description !== '') $publication['record']['description'] = $description;
        sync_item($state, $publication, 'put');
        return 'Publication saved. Place the verification file shown on your website, then verify it.';
    }
    if ($action === 'verify_publication' || $action === 'retry_publication') {
        if (!$state['publication']) throw new RuntimeException('Create a publication first.');
        if ($action === 'retry_publication') sync_item($state, $state['publication'], 'put');
        else { $state['publication']['check'] = verify_item($state['publication'], true, $state); state_save($state); }
        return $action === 'retry_publication' ? 'Publication operation completed.' : $state['publication']['check']['message'];
    }
    if ($action === 'analyze' || $action === 'reimport') {
        if (!$state['publication'] || !$state['publication']['cid']) throw new RuntimeException('Create the publication first.');
        if ($action === 'reimport') {
            if (!isset($state['documents'][$id]) || $state['documents'][$id]['pending'] || $state['documents'][$id]['status'] === 'removed') throw new RuntimeException('This article cannot be reimported right now.');
            $url = $state['documents'][$id]['url'];
        } else $url = input_string($_POST, 'url', 2048);
        $path = document_path($url, $state['publication']['record']['url']);
        $url = $state['publication']['record']['url'] . $path;
        if ($action === 'analyze') foreach ($state['documents'] as $existing) if ($existing['url'] === $url) throw new RuntimeException('This URL is already managed. Open the existing article.');
        $preview = extract_with_selectors(fetch_html($url), $url, $state['selectors'] ?? selector_defaults());
        $preview['id'] = $action === 'reimport' ? $id : '';
        if ($action === 'reimport' && $preview['publishedAt'] === '') $preview['publishedAt'] = $state['documents'][$id]['record']['publishedAt'];
        $_SESSION['preview'] = $preview;
        return 'Page imported. Review the preview and save it as a draft; nothing has been published yet.';
    }
    if ($action === 'save_document') {
        if (!$state['publication']) throw new RuntimeException('Publication missing.');
        $record = document_record($_POST, $state['publication']);
        $url = $state['publication']['record']['url'] . $record['path'];
        $cover_url = input_string($_POST, 'cover_url', 2048); if ($cover_url !== '') https_url($cover_url);
        if ($id === '') {
            foreach ($state['documents'] as $existing) if ($existing['url'] === $url) throw new RuntimeException('This URL is already managed.');
            $item = new_item($state['did'], 'site.standard.document'); $id = $item['rkey'];
            $state['documents'][$id] = $item;
        }
        if (!isset($state['documents'][$id])) throw new RuntimeException('Article not found.');
        $item = &$state['documents'][$id];
        if ($item['pending'] || $item['status'] === 'removed') throw new RuntimeException('Complete the pending operation first.');
        if (isset($item['url']) && $item['url'] !== $url) throw new RuntimeException('The article URL cannot be changed.');
        if ($item['cid']) $record['updatedAt'] = now_iso();
        if (($item['cover_url'] ?? '') === $cover_url && isset($item['record']['coverImage'])) $record['coverImage'] = $item['record']['coverImage'];
        $item['record'] = $record; $item['url'] = $url; $item['cover_url'] = $cover_url; $item['edited_at'] = now_iso();
        $preview = $_SESSION['preview'] ?? [];
        if (($preview['url'] ?? '') === $url) { $item['source_html'] = $preview['source_html']; $item['matches'] = $preview['matches']; }
        state_save($state); unset($_SESSION['preview']); $_SESSION['open_id'] = $id;
        return 'Draft saved. Use “Publish” to transfer this version to the PDS.';
    }
    if ($action === 'discard_preview') { unset($_SESSION['preview']); return 'Preview discarded. Saved content remains unchanged.'; }
    if (!isset($state['documents'][$id])) throw new RuntimeException('Article not found.');
    $item = &$state['documents'][$id];
    if ($action === 'publish') {
        if ($item['status'] === 'removed') throw new RuntimeException('This record has already been removed.');
        if (!$item['pending']) {
            require_account($state);
            document_record(array_merge($item['record'], ['url' => $item['url'], 'tags' => implode(', ', $item['record']['tags'] ?? [])]), $state['publication']);
            if (!empty($item['cover_url'])) $item['record']['coverImage'] = upload_cover($item['cover_url'], $state);
        }
        sync_item($state, $item, 'put');
        return 'Article published. Deploy the central mapping under “Deployment” (or add the link manually), then verify the article.';
    }
    if ($action === 'verify') { $item['check'] = verify_item($item, false, $state); state_save($state); return $item['check']['message']; }
    if ($action === 'delete') {
        if (input_string($_POST, 'confirm', 10) !== 'yes') throw new RuntimeException('Confirm deletion explicitly.');
        if (!$item['cid'] && !$item['pending']) { unset($state['documents'][$id]); state_save($state); return 'Local draft deleted.'; }
        sync_item($state, $item, 'delete');
        return 'Record deleted from the PDS. Redeploy the central mapping or remove the verification link from your page manually. Copies held by other services are not guaranteed to be deleted.';
    }
    if ($action === 'purge') {
        if ($item['status'] !== 'removed' || !($item['check']['ok'] ?? false)) throw new RuntimeException('Verify that the old link has been removed first.');
        unset($state['documents'][$id]); state_save($state); return 'Completed entry removed from the overview.';
    }
    throw new RuntimeException('Unknown action.');
}
