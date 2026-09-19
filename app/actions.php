<?php
declare(strict_types=1);
function handle_action(string $action, array &$state): string {
    global $config;
    if ($action === 'deploy_mapping' || $action === 'check_deployment') {
        $payload = mapping_payload($state);
        $settings = $config['deployment'] ?? [];
        if ($action === 'deploy_mapping') {
            if (input_string($_POST, 'confirm', 10) !== 'yes') throw new RuntimeException('Das Ersetzen der angegebenen Remote-Datei bitte bestätigen.');
            $state['deployment'] = deploy_mapping($settings, $payload);
            $state['deployment_check'] = ['ok' => true, 'at' => now_iso(), 'message' => 'Remote-Datei übertragen und per SHA-256 bestätigt.'];
        } else {
            try {
                $validated = validate_deployment($settings);
                $remote = deployment_transfer($validated, 'read', $validated['remote_path']);
                $ok = hash_equals(hash('sha256', $payload), hash('sha256', $remote));
                $state['deployment_check'] = ['ok' => $ok, 'at' => now_iso(), 'message' => $ok ? 'Remote-Zuordnung stimmt mit dem aktuellen Stand überein.' : 'Remote-Datei weicht ab. Bitte die aktuelle Zuordnung deployen.'];
                if ($ok) $state['deployment'] = ['sha256' => hash('sha256', $payload), 'at' => now_iso(), 'target' => deployment_fingerprint($validated), 'bytes' => strlen($payload)];
            } catch (RuntimeException $e) {
                $state['deployment_check'] = ['ok' => false, 'at' => now_iso(), 'message' => $e->getMessage()];
            }
        }
        state_save($state);
        return $state['deployment_check']['message'];
    }
    if ($action === 'bulk_start') {
        if (input_string($_POST, 'confirm', 10) !== 'yes') throw new RuntimeException('Die beschriebenen Sammeländerungen bitte bestätigen.');
        bulk_start($state, input_string($_POST, 'mode', 20), input_string($_POST, 'sitemap', 2048));
        return 'Sammellauf vorbereitet. Jetzt Verarbeitung starten.';
    }
    if ($action === 'bulk_step') return bulk_step($state, input_string($_POST, 'job_id', 50));
    if ($action === 'bulk_stop') {
        if (($state['bulk']['id'] ?? '') !== input_string($_POST, 'job_id', 50)) throw new RuntimeException('Sammellauf wurde inzwischen ersetzt.');
        $state['bulk']['status'] = 'stopped'; state_save($state);
        return 'Sammellauf gestoppt. Bereits ausgeführte Änderungen bleiben bestehen.';
    }
    $id = input_string($_POST, 'id', 100);
    if ($action === 'disconnect') { unset($_SESSION['pds']); return 'Verbindung getrennt. Die Veröffentlichungen bleiben erhalten.'; }
    if ($action === 'connect') {
        connect_pds(input_string($_POST, 'pds', 2048), input_string($_POST, 'identifier', 300), input_password($_POST, 'app_password'), $state['did']);
        if ($state['did'] === '') { $state['did'] = $_SESSION['pds']['did']; state_save($state); }
        return 'PDS-Verbindung hergestellt. Das App-Passwort wird nicht gespeichert.';
    }
    if ($action === 'selectors') {
        $selectors = [];
        foreach (selector_defaults() as $field => $defaults) {
            $value = $_POST['selectors'][$field] ?? '';
            if (!is_string($value) || strlen($value) > 6000) throw new RuntimeException('Ungültige Selektorliste.');
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $value))));
            if (count($lines) > 20) throw new RuntimeException('Maximal 20 Selektoren pro Feld.');
            foreach ($lines as $line) css_xpath($line);
            $selectors[$field] = $lines;
        }
        $hosts = array_values(array_filter(array_map('strtolower', array_map('trim', preg_split('/[\r\n,]+/', input_string($_POST, 'image_hosts', 2000))))));
        foreach ($hosts as $host) if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) throw new RuntimeException('Bild-Domains ohne https:// oder Pfad eintragen.');
        $state['selectors'] = $selectors; $state['image_hosts'] = array_unique($hosts); state_save($state);
        return 'Extraktionsregeln gespeichert. Sie gelten beim nächsten Einlesen.';
    }
    if ($action === 'publication') {
        require_account($state);
        $url = publication_url(input_string($_POST, 'url', 2048));
        $name = limited_text(input_string($_POST, 'name'), 'Name', 5000, 500, true);
        $description = limited_text(input_string($_POST, 'description', 30000), 'Beschreibung', 30000, 3000);
        if (!$state['publication']) $state['publication'] = new_item($state['did'], 'site.standard.publication');
        $publication = &$state['publication'];
        if ($publication['pending']) throw new RuntimeException('Bitte zuerst den offenen Publication-Vorgang wiederholen.');
        if ($publication['cid'] && $publication['record']['url'] !== $url) throw new RuntimeException('Die Website-Adresse einer veröffentlichten Publication bleibt unverändert.');
        $publication['record'] = ['$type' => 'site.standard.publication', 'url' => $url, 'name' => $name];
        if ($description !== '') $publication['record']['description'] = $description;
        sync_item($state, $publication, 'put');
        return 'Publication gespeichert. Jetzt die angezeigte Verifikationsdatei auf deiner Website hinterlegen und prüfen.';
    }
    if ($action === 'verify_publication' || $action === 'retry_publication') {
        if (!$state['publication']) throw new RuntimeException('Zuerst eine Publication anlegen.');
        if ($action === 'retry_publication') sync_item($state, $state['publication'], 'put');
        else { $state['publication']['check'] = verify_item($state['publication'], true, $state); state_save($state); }
        return $action === 'retry_publication' ? 'Publication-Vorgang abgeschlossen.' : $state['publication']['check']['message'];
    }
    if ($action === 'analyze' || $action === 'reimport') {
        if (!$state['publication'] || !$state['publication']['cid']) throw new RuntimeException('Bitte zuerst die Publication anlegen.');
        if ($action === 'reimport') {
            if (!isset($state['documents'][$id]) || $state['documents'][$id]['pending'] || $state['documents'][$id]['status'] === 'removed') throw new RuntimeException('Dieser Artikel kann gerade nicht neu eingelesen werden.');
            $url = $state['documents'][$id]['url'];
        } else $url = input_string($_POST, 'url', 2048);
        $path = document_path($url, $state['publication']['record']['url']);
        $url = $state['publication']['record']['url'] . $path;
        if ($action === 'analyze') foreach ($state['documents'] as $existing) if ($existing['url'] === $url) throw new RuntimeException('Diese URL wird bereits verwaltet. Den vorhandenen Artikel öffnen.');
        $preview = extract_with_selectors(fetch_html($url), $url, $state['selectors'] ?? selector_defaults());
        $preview['id'] = $action === 'reimport' ? $id : '';
        if ($action === 'reimport' && $preview['publishedAt'] === '') $preview['publishedAt'] = $state['documents'][$id]['record']['publishedAt'];
        $_SESSION['preview'] = $preview;
        return 'Seite eingelesen. Vorschau prüfen und als Entwurf speichern; es wurde noch nichts veröffentlicht.';
    }
    if ($action === 'save_document') {
        if (!$state['publication']) throw new RuntimeException('Publication fehlt.');
        $record = document_record($_POST, $state['publication']);
        $url = $state['publication']['record']['url'] . $record['path'];
        $cover_url = input_string($_POST, 'cover_url', 2048); if ($cover_url !== '') https_url($cover_url);
        if ($id === '') {
            foreach ($state['documents'] as $existing) if ($existing['url'] === $url) throw new RuntimeException('Diese URL wird bereits verwaltet.');
            $item = new_item($state['did'], 'site.standard.document'); $id = $item['rkey'];
            $state['documents'][$id] = $item;
        }
        if (!isset($state['documents'][$id])) throw new RuntimeException('Artikel nicht gefunden.');
        $item = &$state['documents'][$id];
        if ($item['pending'] || $item['status'] === 'removed') throw new RuntimeException('Bitte zuerst den offenen Vorgang abschließen.');
        if (isset($item['url']) && $item['url'] !== $url) throw new RuntimeException('Die Artikel-URL bleibt unverändert.');
        if ($item['cid']) $record['updatedAt'] = now_iso();
        if (($item['cover_url'] ?? '') === $cover_url && isset($item['record']['coverImage'])) $record['coverImage'] = $item['record']['coverImage'];
        $item['record'] = $record; $item['url'] = $url; $item['cover_url'] = $cover_url; $item['edited_at'] = now_iso();
        $preview = $_SESSION['preview'] ?? [];
        if (($preview['url'] ?? '') === $url) { $item['source_html'] = $preview['source_html']; $item['matches'] = $preview['matches']; }
        state_save($state); unset($_SESSION['preview']); $_SESSION['open_id'] = $id;
        return 'Entwurf gespeichert. Mit „Veröffentlichen“ wird dieser Stand zum PDS übertragen.';
    }
    if ($action === 'discard_preview') { unset($_SESSION['preview']); return 'Vorschau verworfen. Gespeicherte Inhalte bleiben erhalten.'; }
    if (!isset($state['documents'][$id])) throw new RuntimeException('Artikel nicht gefunden.');
    $item = &$state['documents'][$id];
    if ($action === 'publish') {
        if ($item['status'] === 'removed') throw new RuntimeException('Dieser Datensatz wurde bereits entfernt.');
        if (!$item['pending']) {
            require_account($state);
            document_record(array_merge($item['record'], ['url' => $item['url'], 'tags' => implode(', ', $item['record']['tags'] ?? [])]), $state['publication']);
            if (!empty($item['cover_url'])) $item['record']['coverImage'] = upload_cover($item['cover_url'], $state);
        }
        sync_item($state, $item, 'put');
        return 'Artikel veröffentlicht. Jetzt unter „Deployment“ die zentrale Zuordnung übertragen (oder den Link manuell setzen), danach die Artikel-Verifikation prüfen.';
    }
    if ($action === 'verify') { $item['check'] = verify_item($item, false, $state); state_save($state); return $item['check']['message']; }
    if ($action === 'delete') {
        if (input_string($_POST, 'confirm', 10) !== 'yes') throw new RuntimeException('Bitte die Löschung ausdrücklich bestätigen.');
        if (!$item['cid'] && !$item['pending']) { unset($state['documents'][$id]); state_save($state); return 'Lokaler Entwurf gelöscht.'; }
        sync_item($state, $item, 'delete');
        return 'Datensatz am PDS gelöscht. Jetzt die zentrale Zuordnung neu deployen oder den Verifikations-Link manuell aus deiner Webseite entfernen. Kopien bei anderen Diensten werden dadurch nicht garantiert gelöscht.';
    }
    if ($action === 'purge') {
        if ($item['status'] !== 'removed' || !($item['check']['ok'] ?? false)) throw new RuntimeException('Bitte zuerst prüfen, dass der alte Link entfernt wurde.');
        unset($state['documents'][$id]); state_save($state); return 'Erledigter Eintrag aus der Übersicht entfernt.';
    }
    throw new RuntimeException('Unbekannte Aktion.');
}
