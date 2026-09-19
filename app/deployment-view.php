<?php
// Deployment UI for Uli; intentionally never renders authentication secrets.
$settings = $config['deployment'] ?? []; $mapping_error = ''; $payload = '';
try { $payload = mapping_payload($state); } catch (RuntimeException $e) { $mapping_error = $e->getMessage(); }
$target_error = '';
try { validate_deployment($settings); } catch (RuntimeException $e) { $target_error = $e->getMessage(); }
?>
<p class="eyebrow">CENTRAL MAPPING</p><h1>Include once. Update centrally.</h1>
<p class="lead">One JSON file links all published articles to their AT-URIs. Your website reads it locally on every page request.</p>
<div class="two-columns"><section class="panel"><h2>Deploy current mapping</h2>
<?php if ($mapping_error): ?><div class="check warning"><?=h($mapping_error)?></div><?php else: ?>
<p><strong><?=count((array) json_decode($payload, true)['documents'])?> mappings</strong> · <?=strlen($payload)?> Bytes</p>
<p class="status <?=mapping_is_deployed($state, $settings) ? '' : 'warning'?>"><?=mapping_is_deployed($state, $settings) ? 'Current mapping last confirmed remotely' : 'Deployment required / not yet confirmed'?></p>
<dl class="matches"><dt>Protocol</dt><dd><?=h(($settings['protocol'] ?? '') === 'ftps' ? 'FTP with explicit TLS (AUTH TLS)' : 'SFTP')?></dd><dt>Server</dt><dd><?=h($settings['host'] ?? 'Not configured')?>:<?=h($settings['port'] ?? '')?></dd><dt>Remote file</dt><dd><code><?=h($settings['remote_path'] ?? '')?></code></dd></dl>
<?php if ($target_error): ?><div class="check warning"><?=h($target_error)?><p>Configure the <code>deployment</code> section in your private <code>config.php</code>. The README includes SFTP and explicit FTPS examples.</p></div><?php else: ?>
<?php form_start('deploy_mapping', $state); ?><label class="checkbox"><input type="checkbox" name="confirm" value="yes" required> Replace the remote file shown above with the current mapping.</label><button>Deploy mapping file</button></form>
<div class="actions"><?php action_button('check_deployment', 'Check remote file', $state); ?></div>
<?php endif ?>
<div class="actions"><a class="button secondary" href="?page=deployment&download=mapping">Download mapping file</a></div>
<details><summary>View JSON mapping</summary><pre><?=h($payload)?></pre></details>
<?php endif ?>
<?php check_view($state['deployment_check'] ?? null); ?>
<p class="muted">Upload temporary file → read it back → compare SHA-256 → rename file → verify active file. Existing files are never deleted before replacement. Interrupted attempts may leave temporary .upload files.</p>
</section><section class="panel soft"><h2>One-time website setup</h2><ol class="steps">
<li>Download the PHP helper and place it on your website, preferably outside the document root.<div class="actions"><a class="button secondary" href="?download=website_helper">Download PHP include</a></div></li>
<li>In the shared <code>&lt;head&gt;</code> of your PHP pages, include the helper and output its result. Adjust both file paths for your web server:<pre id="mapping-snippet"><?=h("<?php\nrequire_once '/absolute/path/standard-stuffer-links.php';\necho standard_stuffer_link(\n    '/absolute/path/standard-stuffer-map.json',\n    " . var_export($publication['record']['url'] ?? 'https://example.de', true) . "\n);\n?>")?></pre><button type="button" class="secondary" data-copy="mapping-snippet">Copy example</button></li>
<li>Create the destination directory for the JSON file. The deployment user needs write and rename permissions; your website’s PHP user needs read permissions. SFTP/FTPS paths may differ from local PHP paths because of a server chroot.</li>
<li>Configure deployment and transfer the mapping. Then check article verification. This checks the HTML actually served by your website; a successful file deployment alone is not sufficient.</li>
<li>Redeploy after each new publication or PDS deletion. Draft edits do not affect the mapping. Set up the domain verification file once under “Website & verification”.</li>
</ol><p>No <code>auto_prepend_file</code>. No HTTP request for the mapping on each page visit. If the JSON file is missing or invalid, the include emits no link; your page continues to work.</p><p>Use either this include or a manual link for each article to avoid duplicate or outdated links in the HTML head.</p></section></div>
