<?php
// Deployment UI for Uli; intentionally never renders authentication secrets.
$settings = $config['deployment'] ?? []; $mapping_error = ''; $payload = '';
try { $payload = mapping_payload($state); } catch (RuntimeException $e) { $mapping_error = $e->getMessage(); }
$target_error = '';
try { validate_deployment($settings); } catch (RuntimeException $e) { $target_error = $e->getMessage(); }
?>
<p class="eyebrow">ZENTRALE ZUORDNUNG</p><h1>Einmal einbinden. Zentral aktualisieren.</h1>
<p class="lead">Eine JSON-Datei verknüpft alle veröffentlichten Artikel mit ihren AT-URIs. Deine Website liest sie bei jedem Seitenaufruf lokal.</p>
<div class="two-columns"><section class="panel"><h2>Aktuellen Stand übertragen</h2>
<?php if ($mapping_error): ?><div class="check warning"><?=h($mapping_error)?></div><?php else: ?>
<p><strong><?=count((array) json_decode($payload, true)['documents'])?> Zuordnungen</strong> · <?=strlen($payload)?> Bytes</p>
<p class="status <?=mapping_is_deployed($state, $settings) ? '' : 'warning'?>"><?=mapping_is_deployed($state, $settings) ? 'Aktueller Stand zuletzt remote bestätigt' : 'Deployment erforderlich / noch nicht bestätigt'?></p>
<dl class="matches"><dt>Protokoll</dt><dd><?=h(($settings['protocol'] ?? '') === 'ftps' ? 'FTP mit explizitem TLS (AUTH TLS)' : 'SFTP')?></dd><dt>Server</dt><dd><?=h($settings['host'] ?? 'Nicht eingerichtet')?>:<?=h($settings['port'] ?? '')?></dd><dt>Remote-Datei</dt><dd><code><?=h($settings['remote_path'] ?? '')?></code></dd></dl>
<?php if ($target_error): ?><div class="check warning"><?=h($target_error)?><p>Den Abschnitt <code>deployment</code> in der privaten <code>config.php</code> einrichten. Die englische README enthält Beispiele für SFTP und explizites FTPS.</p></div><?php else: ?>
<?php form_start('deploy_mapping', $state); ?><label class="checkbox"><input type="checkbox" name="confirm" value="yes" required> Die oben angegebene Remote-Datei durch den aktuellen Stand ersetzen.</label><button>Zuordnungsdatei deployen</button></form>
<div class="actions"><?php action_button('check_deployment', 'Remote-Datei prüfen', $state); ?></div>
<?php endif ?>
<div class="actions"><a class="button secondary" href="?page=deployment&download=mapping">Zuordnungsdatei herunterladen</a></div>
<details><summary>JSON-Zuordnung ansehen</summary><pre><?=h($payload)?></pre></details>
<?php endif ?>
<?php check_view($state['deployment_check'] ?? null); ?>
<p class="muted">Temporär hochladen → Inhalt zurücklesen → SHA-256 vergleichen → Datei umbenennen → aktive Datei erneut prüfen. Bestehende Dateien werden nicht vorab gelöscht. Abgebrochene Versuche können temporäre .upload-Dateien hinterlassen.</p>
</section><section class="panel soft"><h2>Einmal auf der Website einrichten</h2><ol class="steps">
<li>Die PHP-Hilfsdatei herunterladen und auf deiner Website ablegen, vorzugsweise außerhalb des Webverzeichnisses.<div class="actions"><a class="button secondary" href="?download=website_helper">PHP-Include herunterladen</a></div></li>
<li>Im gemeinsamen <code>&lt;head&gt;</code> deiner PHP-Seiten die Hilfsdatei einbinden und die Ausgabe aufrufen. Die beiden Dateipfade an deinen Webserver anpassen:<pre id="mapping-snippet"><?=h("<?php\nrequire_once '/absolute/path/standard-stuffer-links.php';\necho standard_stuffer_link(\n    '/absolute/path/standard-stuffer-map.json',\n    " . var_export($publication['record']['url'] ?? 'https://example.de', true) . "\n);\n?>")?></pre><button type="button" class="secondary" data-copy="mapping-snippet">Beispiel kopieren</button></li>
<li>Das Zielverzeichnis für die JSON-Datei anlegen. Der Deployment-Benutzer braucht Schreib- und Umbenennungsrechte, der PHP-Benutzer deiner Website Leserechte. SFTP-/FTPS-Pfade können wegen einer Server-Chroot von lokalen PHP-Dateipfaden abweichen.</li>
<li>Deployment konfigurieren und die Zuordnung übertragen. Danach beim Artikel die Verifikation prüfen. Diese Prüfung bestätigt die tatsächlich ausgelieferte HTML-Seite; ein erfolgreiches Datei-Deployment allein genügt nicht.</li>
<li>Nach jeder neuen Veröffentlichung und PDS-Löschung erneut deployen. Entwurfsänderungen beeinflussen die Zuordnung nicht. Die Domain-Verifikationsdatei bleibt weiterhin einmalig unter „Website & Verifikation“ einzurichten.</li>
</ol><p>Kein <code>auto_prepend_file</code>. Kein HTTP-Abruf der Zuordnung bei jedem Seitenbesuch. Bei fehlender oder defekter JSON-Datei liefert das Include keinen Link; deine Seite läuft weiter.</p><p>Pro Artikel entweder dieses Include oder einen manuellen Link verwenden, damit keine doppelten bzw. alten Links im HTML-Kopf verbleiben.</p></section></div>
