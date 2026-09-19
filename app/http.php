<?php
declare(strict_types=1);
function api_raw(string $pds, string $method, ?array $body = null, string $token = '', array $query = []): array {
    $url = $pds . '/xrpc/' . $method . ($query ? '?' . http_build_query($query) : '');
    $headers = ['Content-Type: application/json'];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    $response = http_request($url, $body === null ? 'GET' : 'POST', $body === null ? null : ($body === [] ? '{}' : json_encode_safe($body)), $headers);
    $decoded = json_decode($response['body'], true);
    if ($response['status'] < 200 || $response['status'] >= 300) {
        $error = is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : 'HTTP' . $response['status'];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $error)) $error = 'UnknownError';
        throw new RuntimeException('PDS: ' . $error, $response['status']);
    }
    if (!is_array($decoded) && trim($response['body']) !== '') throw new RuntimeException('Der PDS liefert kein gültiges JSON.');
    return $decoded ?? [];
}
function connect_pds(string $pds, string $identifier, string $password, string $expected_did): void {
    $pds = publication_url($pds);
    if (parse_url($pds, PHP_URL_PATH)) throw new RuntimeException('PDS-Adresse bitte ohne Pfad eingeben.');
    $session = api_raw($pds, 'com.atproto.server.createSession', ['identifier' => $identifier, 'password' => $password]);
    if (!is_string($session['did'] ?? null) || !preg_match('/^did:[a-z]+:[a-zA-Z0-9._:%-]+$/', $session['did']) || empty($session['accessJwt']) || empty($session['refreshJwt'])) throw new RuntimeException('Die PDS-Anmeldung lieferte keine vollständige Sitzung.');
    if ($expected_did !== '' && $session['did'] !== $expected_did) throw new RuntimeException('Dieses Dashboard ist bereits an einen anderen AT-Protocol-Account gebunden.');
    foreach (($session['didDoc']['service'] ?? []) as $service) {
        if (($service['type'] ?? '') === 'AtprotoPersonalDataServer' && is_string($service['serviceEndpoint'] ?? null)) $pds = publication_url($service['serviceEndpoint']);
    }
    $_SESSION['pds'] = ['url' => $pds, 'did' => $session['did'], 'handle' => $session['handle'] ?? $identifier, 'access' => $session['accessJwt'], 'refresh' => $session['refreshJwt'], 'refreshed' => time()];
}
function api_call(string $method, ?array $body = null, array $query = []): array {
    if (empty($_SESSION['pds'])) throw new RuntimeException('Bitte zuerst unter „Verbindung“ am PDS anmelden.');
    $session = &$_SESSION['pds'];
    if (time() - $session['refreshed'] > 3000) refresh_pds();
    try { return api_raw($session['url'], $method, $body, $session['access'], $query); }
    catch (RuntimeException $e) {
        if ($e->getMessage() !== 'PDS: ExpiredToken') throw $e;
        refresh_pds(); return api_raw($session['url'], $method, $body, $session['access'], $query);
    }
}
function refresh_pds(): void {
    $session = &$_SESSION['pds'];
    $result = api_raw($session['url'], 'com.atproto.server.refreshSession', [], $session['refresh']);
    if (empty($result['accessJwt']) || empty($result['refreshJwt']) || ($result['did'] ?? '') !== $session['did']) throw new RuntimeException('Sitzung konnte nicht verlängert werden. Bitte neu verbinden.');
    $session['access'] = $result['accessJwt']; $session['refresh'] = $result['refreshJwt']; $session['refreshed'] = time();
}
function remote_record(array $item): ?array {
    $collection = strpos($item['uri'], '/site.standard.publication/') !== false ? 'site.standard.publication' : 'site.standard.document';
    try { return api_call('com.atproto.repo.getRecord', null, ['repo' => $_SESSION['pds']['did'], 'collection' => $collection, 'rkey' => $item['rkey']]); }
    catch (RuntimeException $e) { if (in_array($e->getMessage(), ['PDS: RecordNotFound', 'PDS: NotFound'], true)) return null; throw $e; }
}
function require_account(array $state): void {
    if (empty($_SESSION['pds']) || ($state['did'] !== '' && $_SESSION['pds']['did'] !== $state['did'])) throw new RuntimeException('Bitte mit dem zugehörigen AT-Protocol-Account verbinden.');
}
function sync_item(array &$state, array &$item, string $operation): void {
    require_account($state);
    if (!$item['pending']) {
        $item['pending'] = ['operation' => $operation, 'record' => $item['record'], 'previous_cid' => $item['cid'], 'started' => now_iso()];
        state_save($state);
    }
    $pending = $item['pending'];
    if ($pending['operation'] !== $operation) throw new RuntimeException('Bitte zuerst den noch offenen Vorgang wiederholen.');
    $remote = remote_record($item);
    $collection = $item['record']['$type'];
    if ($operation === 'delete') {
        if ($remote !== null) {
            if (($remote['cid'] ?? '') !== $pending['previous_cid']) throw new RuntimeException('Der PDS-Datensatz wurde außerhalb des Dashboards geändert. Löschen wurde zum Schutz dieser Änderung abgebrochen.');
            api_call('com.atproto.repo.deleteRecord', ['repo' => $state['did'], 'collection' => $collection, 'rkey' => $item['rkey'], 'swapRecord' => $remote['cid']]);
        }
        $item['status'] = 'removed'; $item['cid'] = null; $item['published_record'] = null;
    } else {
        if ($remote !== null && ($remote['value'] ?? null) == $pending['record']) $result = $remote;
        else {
            if (($remote['cid'] ?? null) !== $pending['previous_cid']) throw new RuntimeException('Der PDS-Datensatz wurde außerhalb des Dashboards geändert. Er wird nicht überschrieben. Bitte zuerst den Konflikt am PDS klären.');
            $result = api_call('com.atproto.repo.putRecord', ['repo' => $state['did'], 'collection' => $collection, 'rkey' => $item['rkey'], 'record' => $pending['record'], 'swapRecord' => $pending['previous_cid']]);
        }
        if (empty($result['cid']) || ($result['uri'] ?? '') !== $item['uri']) throw new RuntimeException('Unerwartete PDS-Antwort. Den offenen Vorgang erneut prüfen.');
        $item['cid'] = $result['cid']; $item['published_record'] = $pending['record']; $item['status'] = 'published';
    }
    $item['pending'] = null; $item['check'] = null; $item['synced_at'] = now_iso();
    state_save($state);
}
function verify_item(array $item, bool $publication, array $state): array {
    $checked = ['at' => now_iso(), 'ok' => false, 'message' => ''];
    try {
        if ($publication) {
            $response = http_request(verification_url($item['record']['url']), 'GET', null, [], 4000000, true);
            $match = $response['status'] === 200 && trim($response['body']) === $item['uri'];
            $checked['message'] = $match ? 'Domain-Rückverweis stimmt überein.' : 'HTTP ' . $response['status'] . ': Die Antwort muss ausschließlich die Publication-AT-URI enthalten.';
        } else {
            if ($item['status'] === 'removed') {
                $response = http_request($item['url'], 'GET', null, [], 4000000, true);
                if (in_array($response['status'], [404, 410], true)) return ['at' => now_iso(), 'ok' => true, 'message' => 'Die Originalseite liefert HTTP ' . $response['status'] . '; kein aktiver Artikel-Link mehr erreichbar. Zentrale Zuordnung gegebenenfalls neu deployen.'];
            }
            $html = fetch_html($item['url']); $match = has_document_link($html, $item['uri']);
            if ($item['status'] === 'removed') {
                $checked['ok'] = !$match;
                $checked['message'] = $match ? 'Der alte Link steht noch im HTML-Kopf. Bitte entfernen.' : 'Der alte Link ist nicht mehr im HTML-Kopf vorhanden.';
                return $checked;
            }
            $checked['message'] = $match ? 'Artikel-Rückverweis im HTML-Kopf stimmt überein.' : 'Der passende Link fehlt im HTML-Kopf oder zeigt auf eine andere AT-URI.';
        }
        if ($match) {
            require_account($state); $remote = remote_record($item);
            if (!$remote || ($remote['value'] ?? null) != $item['published_record']) throw new RuntimeException('Rückverweis vorhanden, aber der PDS-Datensatz fehlt oder weicht vom letzten veröffentlichten Stand ab.');
            $checked['ok'] = true; $checked['message'] .= ' PDS-Datensatz bestätigt.';
        }
    } catch (RuntimeException $e) { $checked['message'] = $e->getMessage(); }
    return $checked;
}
function upload_cover(string $url, array $state): array {
    $parts = https_url($url); $allowed = array_merge([strtolower(parse_url($state['publication']['record']['url'], PHP_URL_HOST))], $state['image_hosts'] ?? []);
    if (!in_array(strtolower($parts['host']), $allowed, true)) throw new RuntimeException('Die Bild-Domain ist noch nicht unter „Extraktion“ freigegeben.');
    $response = http_request($url, 'GET', null, [], 999999, true);
    if ($response['status'] !== 200) throw new RuntimeException('Coverbild liefert HTTP ' . $response['status'] . '.');
    $info = @getimagesizefromstring($response['body']);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) throw new RuntimeException('Coverbild muss ein gültiges JPEG, PNG, WebP oder GIF unter 1 MB sein.');
    api_call('com.atproto.server.getSession');
    $session = $_SESSION['pds'];
    $result = http_request($session['url'] . '/xrpc/com.atproto.repo.uploadBlob', 'POST', $response['body'], ['Authorization: Bearer ' . $session['access'], 'Content-Type: ' . $info['mime']]);
    $data = json_decode($result['body'], true);
    if ($result['status'] !== 200 || !isset($data['blob']['ref']['$link'])) throw new RuntimeException('Das Coverbild konnte nicht zum PDS hochgeladen werden.');
    return $data['blob'];
}
