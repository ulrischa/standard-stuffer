<?php
// Front controller for standard-stuffer. Maintainer: Uli.
declare(strict_types=1);
$private_root = getenv('STANDARD_STUFFER_ROOT') ?: dirname(__DIR__);
require $private_root . '/app/bootstrap.php';
require $private_root . '/app/actions.php';
$error = ''; $notice = ''; $state = initial_state();
$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'articles';
$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
if (!$setup_error) {
    $notice = $_SESSION['notice'] ?? ''; unset($_SESSION['notice']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            check_csrf(); $action = input_string($_POST, 'action', 50);
            if ($action === 'login') login_admin(input_password($_POST, 'password'));
            elseif (empty($_SESSION['admin'])) throw new RuntimeException('Sign in to the dashboard first.');
            elseif ($action === 'logout') { $_SESSION = []; session_destroy(); header('Location: index.php', true, 303); exit; }
            else {
                $lock = storage_lock('dashboard');
                try {
                    $state = read_json($config['data_dir'] . '/dashboard.json', initial_state());
                    if (input_string($_POST, 'revision', 30) !== (string) $state['revision']) throw new RuntimeException('The data changed in another window. Reload and try the action again.');
                    $_SESSION['notice'] = handle_action($action, $state);
                } finally { flock($lock, LOCK_UN); fclose($lock); }
            }
            $destination = 'index.php?page=' . rawurlencode($page);
            if (in_array($action, ['analyze', 'reimport'], true)) $destination = 'index.php?page=editor&preview=1';
            if ($action === 'save_document') { $destination = 'index.php?page=editor&id=' . rawurlencode($_SESSION['open_id']); unset($_SESSION['open_id']); }
            elseif ($id !== '' && !in_array($action, ['purge', 'delete'], true)) $destination .= '&id=' . rawurlencode($id);
            if ($action === 'delete' || $action === 'purge' || $action === 'discard_preview') $destination = 'index.php';
            header('Location: ' . $destination, true, 303); exit;
        } catch (RuntimeException $e) { $error = $e->getMessage(); }
        catch (Throwable $e) { $error = 'Internal error. Data was not confirmed. Check write permissions and the JSON backup, then retry any pending PDS operation.'; error_log('standard-stuffer: ' . get_class($e)); }
    }
    if (!empty($_SESSION['admin'])) {
        try { $state = read_state(); $state_valid = true; } catch (Throwable $e) { $state_valid = false; $error = 'Could not read JSON storage. Restore your dashboard.json backup.'; }
        $download = $state_valid ? ($_GET['download'] ?? '') : '';
        if ($download === 'bulk_log') {
            header('Content-Type: application/json; charset=UTF-8'); header('Content-Disposition: attachment; filename="standard-stuffer-bulk-log.json"'); $log = $state['bulk']['log'] ?? []; foreach ($log as &$entry) $entry['result'] = english_message($entry['result']); unset($entry); echo json_encode_safe($log); exit;
        }
        if ($download === 'mapping') {
            try { $mapping_json = mapping_payload($state); header('Content-Type: application/json; charset=UTF-8'); header('Content-Disposition: attachment; filename="standard-stuffer-map.json"'); echo $mapping_json; exit; }
            catch (RuntimeException $e) { $error = $e->getMessage(); }
        }
        if ($download === 'website_helper') {
            header('Content-Type: text/plain; charset=UTF-8'); header('Content-Disposition: attachment; filename="standard-stuffer-links.php"'); readfile($private_root . '/website/standard-stuffer-links.php'); exit;
        }
        if ($download === 'verification' && $state['publication']) {
            header('Content-Type: text/plain; charset=UTF-8'); header('Content-Disposition: attachment; filename="site.standard.publication"'); echo $state['publication']['uri'] . "\n"; exit;
        }
        if ($download === 'backup') {
            header('Content-Type: application/json; charset=UTF-8'); header('Content-Disposition: attachment; filename="standard-stuffer-backup.json"'); echo json_encode_safe($state); exit;
        }
        if ($download === 'html' && isset($state['documents'][$id])) {
            header('Content-Type: text/plain; charset=UTF-8'); header('Content-Disposition: attachment; filename="article-source.html.txt"'); echo $state['documents'][$id]['source_html'] ?? ''; exit;
        }
    }
}
function form_start(string $action, array $state, string $id = ''): void {
    echo '<form method="post"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '"><input type="hidden" name="revision" value="' . h($state['revision']) . '"><input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="id" value="' . h($id) . '">';
}
function action_button(string $action, string $label, array $state, string $id = '', string $class = 'secondary'): void {
    form_start($action, $state, $id); echo '<button class="' . h($class) . '">' . h($label) . '</button></form>';
}
function field(string $name, string $label, string $value = '', string $type = 'text', bool $required = false, string $help = '', bool $readonly = false): void {
    echo '<div class="field"><label for="' . h($name) . '">' . h($label) . ($required ? ' <span class="required">*</span>' : '') . '</label>';
    $attributes = ' id="' . h($name) . '" name="' . h($name) . '"' . ($required ? ' required' : '') . ($readonly ? ' readonly' : '') . ($help ? ' aria-describedby="' . h($name) . '-help"' : '');
    if ($type === 'textarea') echo '<textarea' . $attributes . ' rows="' . ($name === 'textContent' ? '16' : '3') . '">' . h($value) . '</textarea>';
    else echo '<input' . $attributes . ' type="' . h($type) . '" value="' . h($value) . '"' . ($type === 'password' ? ' autocomplete="current-password"' : '') . '>';
    if ($help) echo '<small id="' . h($name) . '-help">' . h($help) . '</small>';
    echo '</div>';
}
function check_view(?array $check): void {
    if (!$check) { echo '<p class="status neutral">Not checked yet</p>'; return; }
    echo '<div class="check ' . ($check['ok'] ? 'good' : 'warning') . '"><strong>' . ($check['ok'] ? 'Verification successful' : 'Action required') . '</strong><p>' . h(english_message($check['message'])) . '</p><small>Checked: ' . h($check['at']) . ' · Snapshot</small></div>';
}
$publication = $state['publication'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>standard-stuffer · Publishing dashboard</title><link rel="stylesheet" href="assets/app.css"><link rel="icon" href="assets/icon.svg" type="image/svg+xml"><script src="assets/app.js" defer></script></head><body>
<a class="skip" href="#main">Skip to content</a>
<header class="topbar"><a class="brand" href="index.php"><span class="brand-icon" aria-hidden="true">s<span>.</span></span><span>standard-stuffer<small>YOUR WEBSITE ON AT PROTOCOL</small></span></a><span class="edition">PHP + JSON</span></header>
<?php if ($setup_error): ?>
<main id="main" class="standalone"><p class="eyebrow">SETUP</p><h1>Set up once.</h1><div class="check warning"><?=h($setup_error)?></div><section class="panel"><h2>Getting started</h2><ol class="steps"><li>Extract the package. Run <code>php setup.php</code> on the server and set your dashboard password.</li><li>Set the document root to <code>public/</code>. <code>app/</code>, <code>config.php</code> and <code>var/</code> remain outside it. Without SSH, run setup.php locally and upload config.php with the correct data path.</li><li>Give the PHP user read access to config.php and write access to var/. Enable HTTPS.</li><li>Reload this page and sign in. The dashboard will guide you through connecting your account, domain verification and article publishing.</li></ol><p>Detailed instructions, including existing hosting setups: <strong>README.md in the package</strong>.</p></section></main>
<?php elseif (empty($_SESSION['admin'])): ?>
<main id="main" class="standalone login"><p class="eyebrow">YOUR PUBLISHING TOOL</p><h1>Connect your content.<br>Stay in control.</h1><p class="lead">Publish, update and verify selected web pages.</p><section class="panel"><h2>Open dashboard</h2><?php if ($error): ?><p class="check warning" role="alert"><?=h($error)?></p><?php endif ?><?php form_start('login', $state); field('password', 'Dashboard password', '', 'password', true); ?><button>Sign in</button></form><p class="muted">Your local dashboard password is separate from your AT Protocol account.</p></section></main>
<?php else: ?>
<div class="layout"><aside class="sidebar"><nav aria-label="Main navigation"><?php foreach (['articles' => 'Articles', 'publication' => 'Website & verification', 'selectors' => 'Extraction', 'bulk' => 'Bulk operations', 'deployment' => 'Deployment', 'connection' => 'Connection', 'help' => 'Guide'] as $nav_page => $nav_label): ?><a href="?page=<?=h($nav_page)?>" <?=($page === $nav_page || ($page === 'editor' && $nav_page === 'articles')) ? 'aria-current="page"' : ''?>><?=h($nav_label)?></a><?php endforeach ?></nav><div class="sidebar-bottom"><p class="connection-dot"><?=!empty($_SESSION['pds']) ? 'PDS connected' : 'PDS not connected'?></p><small><?=h($_SESSION['pds']['handle'] ?? 'Connect to publish')?></small><?php action_button('logout', 'Sign out', $state); ?></div></aside>
<main id="main" class="content">
<?php if ($error): ?><div class="check warning" role="alert"><?=h($error)?></div><?php endif ?>
<?php if ($notice): ?><div class="notice" role="status"><?=h(english_message($notice))?></div><?php endif ?>
<?php if ($page === 'connection'): ?>
<p class="eyebrow">STEP 1</p><h1>Connect to your PDS</h1><p class="lead">Your account’s Standard.site records will be stored here.</p>
<div class="two-columns"><section class="panel"><h2>Connect account</h2><?php form_start('connect', $state); field('pds', 'PDS or Bluesky login server', 'https://bsky.social', 'url', true); field('identifier', 'Handle or DID', $_SESSION['pds']['handle'] ?? '', 'text', true); field('app_password', 'App password', '', 'password', true, 'Use a dedicated app password, not your regular account password.'); ?><button>Connect</button></form></section><section class="panel soft"><h2>One-time setup</h2><ol class="steps"><li>For Bluesky, create a dedicated standard-stuffer password under Settings → Privacy and security → App passwords. For other PDS providers, use their login server.</li><li>Enter your handle and app password on the left. For Bluesky, the dashboard discovers your actual PDS during login.</li><li>Then create the publication under <a href="?page=publication">Website & verification</a>.</li></ol><p>Access tokens are stored only in the server-side session. The app password is not stored. Reconnect after the session ends.</p><?php if ($state['did']): ?><p>Linked account:</p><code class="wrap"><?=h($state['did'])?></code><?php endif ?><?php if (!empty($_SESSION['pds'])): ?><div class="actions"><?php action_button('disconnect', 'Disconnect PDS', $state); ?></div><?php endif ?></section></div>
<?php elseif ($page === 'publication'): ?>
<p class="eyebrow">STEP 2</p><h1>Website & verification</h1><p class="lead">The publication describes your website. A public file verifies the association.</p>
<div class="two-columns"><section class="panel"><h2>Publication</h2><?php form_start('publication', $state); field('url', 'Website URL', $publication['record']['url'] ?? '', 'url', true, 'No trailing slash. A subpath such as https://example.de/blog is also supported.', !empty($publication['cid'])); field('name', 'Website name', $publication['record']['name'] ?? '', 'text', true); field('description', 'Description', $publication['record']['description'] ?? '', 'textarea'); ?><button><?=$publication && $publication['cid'] ? 'Update publication' : 'Create publication'?></button></form><?php if ($publication && $publication['pending']): ?><div class="check warning">The last operation is still pending. Do not create another publication.</div><?php action_button('retry_publication', 'Retry pending operation', $state); endif ?></section>
<section class="panel soft"><h2>Verify your domain</h2><?php if (!$publication): ?><p>Create the publication first. Its exact verification URL and file contents will appear here.</p><?php else: ?>
<ol class="steps"><li>Your website must serve a plain-text response at this URL:<code class="block"><?=h(verification_url($publication['record']['url']))?></code></li><li>The response must contain only this AT-URI, without HTML:<code class="block" id="publication-uri"><?=h($publication['uri'])?></code><div class="actions"><button class="secondary" type="button" data-copy="publication-uri">Copy URI</button><a class="button secondary" href="?download=verification">Download file</a></div></li><li>Place the file in the <code>.well-known</code> directory on your website. For a publication in a subdirectory, also include the path extension shown above; configure a server route if needed.</li><li>Run verification when the URL is accessible. Keep the file in place permanently.</li></ol>
<?php action_button('verify_publication', 'Verify domain', $state, '', 'primary'); check_view($publication['check']); endif ?></section></div>
<?php elseif ($page === 'bulk'): ?>
<?php require $private_root . '/app/bulk-view.php'; ?>
<?php elseif ($page === 'deployment'): ?>
<?php require $private_root . '/app/deployment-view.php'; ?>
<?php elseif ($page === 'selectors'): ?>
<p class="eyebrow">EXTRACTION RULES</p><h1>Your pages. Your selectors.</h1><p class="lead">Search from top to bottom. The first usable match wins.</p><p class="check neutral">Page requests: <?=empty($config['fetch_proxy']['url']) ? 'Direct, without a proxy' : 'Through the configured HTTP(S) proxy'?>. Settings are in the <code>fetch_proxy</code> section of your private config.php. PDS and deployment use separate direct connections.</p>
<section class="panel"><?php form_start('selectors', $state); ?><p>Choose, add and reorder selectors. Without JavaScript: one CSS selector per line.</p><p class="muted">Supported: elements, <code>.class</code>, <code>#id</code>, <code>[attribute]</code>, <code>[attribute="value"]</code>, descendants and <code>&gt;</code>. No pseudo-classes or comma-separated groups.</p>
<?php foreach (['title' => 'Title', 'description' => 'Description', 'publishedAt' => 'Publication date', 'cover_url' => 'Cover image', 'textContent' => 'Article text'] as $key => $label): ?><div class="selector-field"><label for="selectors-<?=h($key)?>"><?=h($label)?></label><textarea id="selectors-<?=h($key)?>" name="selectors[<?=h($key)?>]" rows="4" data-selector-list="<?=h($key)?>" data-presets="<?=h(json_encode(selector_defaults()[$key]))?>"><?=h(implode("\n", ($state['selectors'] ?? selector_defaults())[$key]))?></textarea></div><?php endforeach ?>
<div class="check neutral"><strong>Tags: meta keywords only</strong><p><code>&lt;meta name="keywords" content="Climate, Energy"&gt;</code> is split on commas. Categories and automatically generated tags are not used.</p></div>
<?php field('image_hosts', 'Additional image domains', implode("\n", $state['image_hosts'] ?? []), 'textarea', false, 'For CDN images only: one exact domain per line, without https://. Your website domain is automatically allowed.'); ?><button>Save extraction rules</button></form></section>
<section class="panel soft"><h2>How are matches read?</h2><p>Title and description: <code>content</code> attribute, otherwise text. Date: <code>content</code>, <code>datetime</code> or text, as a valid ISO date only. Image: meta content, <code>data-src</code>, <code>data-lazy-src</code>, <code>src</code>, <code>href</code> or the first srcset candidate. A container selector uses its first image.</p><p>Text uses the first nonempty content area. Navigation, scripts, forms and hidden elements are removed. The winning selector and result appear in the preview before you save.</p><p>For images, “usable” means a valid HTTPS image URL. An HTTP or file error during the later image request is reported; it does not silently skip the selected match.</p></section>
<?php elseif ($page === 'editor'): ?>
<?php
$preview_mode = isset($_GET['preview']) && isset($_SESSION['preview']);
$item = $id !== '' ? ($state['documents'][$id] ?? null) : null;
$values = $preview_mode ? $_SESSION['preview'] : ($item ? array_merge($item['record'], ['url' => $item['url'], 'cover_url' => $item['cover_url'] ?? '', 'tags' => implode(', ', $item['record']['tags'] ?? [])]) : null);
if ($preview_mode) { $id = $values['id']; $item = $id !== '' ? ($state['documents'][$id] ?? null) : null; }
if ($error && ($_POST['action'] ?? '') === 'save_document' && $values) foreach (['title','description','publishedAt','textContent','tags','cover_url'] as $key) if (is_string($_POST[$key] ?? null)) $values[$key] = $_POST[$key];
?>
<p class="eyebrow"><?= $preview_mode ? 'PREVIEW · NOT SAVED YET' : 'EDIT ARTICLE' ?></p><h1><?= $preview_mode ? 'Review the result.' : 'Manage article.' ?></h1>
<?php if (!$values): ?><section class="panel"><p>No article selected.</p><a href="index.php">Back to articles</a></section><?php else: ?>
<?php foreach ($values['warnings'] ?? [] as $warning): ?><div class="check warning"><?=h(english_message($warning))?></div><?php endforeach ?>
<div class="editor-grid"><section class="panel"><h2>Content & metadata</h2><?php form_start('save_document', $state, $id); field('url', 'Article URL', $values['url'], 'url', true, '', true); field('title', 'Title', $values['title'] ?? '', 'text', true); field('description', 'Description', $values['description'] ?? '', 'textarea'); field('publishedAt', 'Published on', $values['publishedAt'] ?? '', 'text', true, 'YYYY-MM-DD or an ISO date with a time zone, e.g. 2026-09-18T10:00:00+02:00. Date-only values mean 00:00 UTC.'); field('tags', 'Tags from meta keywords', $values['tags'] ?? '', 'text', false, 'Imported exclusively from meta keywords. Edit them in the draft if needed.'); field('cover_url', 'Cover image URL', $values['cover_url'] ?? '', 'url', false, 'JPEG, PNG, WebP or GIF under 1 MB. Leave empty to omit the cover upload.'); field('textContent', 'Article text (plain text)', $values['textContent'] ?? '', 'textarea'); ?><button>Save draft</button><p class="muted">Saving does not publish anything. Published content changes only after you select “Publish”.</p></form></section>
<aside class="editor-aside"><section class="panel soft"><h2>Extraction</h2><?php $matches = $values['matches'] ?? ($item['matches'] ?? []); if (!$matches): ?><p>No saved selector matches.</p><?php else: ?><dl class="matches"><?php foreach ($matches as $key => $match): ?><dt><?=h(['title'=>'Title','description'=>'Description','publishedAt'=>'Date','cover_url'=>'Cover image','textContent'=>'Text'][$key] ?? $key)?></dt><dd><code><?=h($match)?></code></dd><?php endforeach ?></dl><?php endif ?><p>HTML is kept locally as a source excerpt. The PDS receives plain text with paragraph breaks. Review tables and complex layouts.</p><?php if ($item): ?><a href="?download=html&id=<?=h($id)?>">Download HTML source excerpt</a><?php endif ?><?php if ($preview_mode): ?><div class="actions"><?php action_button('discard_preview', 'Discard preview', $state); ?></div><?php endif ?></section>
<?php if ($item && !$preview_mode): ?><section class="panel"><h2>Publication</h2><p class="status"><?=h($item['pending'] ? 'Operation pending' : ($item['status'] === 'removed' ? 'Removed from PDS' : ($item['cid'] ? (($item['record'] != $item['published_record']) ? 'Local changes available' : 'Published on PDS') : 'Local draft')))?></p><code class="block"><?=h($item['uri'])?></code>
<?php if ($item['status'] !== 'removed'): ?><div class="actions"><?php if (!$item['pending'] || $item['pending']['operation'] === 'put') action_button('publish', $item['pending'] ? 'Retry pending operation' : 'Publish saved version', $state, $id, 'primary'); if (!$item['pending']) action_button('reimport', 'Reimport page', $state, $id); ?></div><p class="muted">These buttons use the saved version. Save form changes as a draft first.</p><?php endif ?>
<details><summary>View record as JSON</summary><pre><?=h(json_encode_safe($item['record']))?></pre></details></section>
<?php if ($item['cid'] || $item['status'] === 'removed'): ?><section class="panel soft"><h2><?=$item['status'] === 'removed' ? 'Remove link' : 'Verify article'?></h2><p><?=$item['status'] === 'removed' ? 'Redeploy the central mapping or remove this link manually. Then run verification.' : 'For the central include, deploy the mapping under Deployment. Alternatively, add this link once to the <head> of the original page. Then run verification here.'?></p><code class="block" id="article-link"><?=h('<link rel="site.standard.document" href="' . $item['uri'] . '">')?></code><button class="secondary" type="button" data-copy="article-link">Copy link</button><div class="actions"><?php action_button('verify', $item['status'] === 'removed' ? 'Verify link removal' : 'Verify article', $state, $id); ?></div><?php check_view($item['check']); ?><p class="muted">Domain verification is checked separately under “Website & verification”.</p></section><?php endif ?>
<section class="panel danger-zone"><h2><?=$item['status'] === 'removed' ? 'Close entry' : 'Remove article'?></h2><?php if ($item['status'] === 'removed'): ?><p>After confirming link removal, you can remove the entry from the overview.</p><?php action_button('purge', 'Close entry', $state, $id); else: ?><?php form_start('delete', $state, $id); ?><label class="checkbox"><input type="checkbox" name="confirm" value="yes" required> <?=$item['cid'] || $item['pending'] ? 'Delete this PDS record. The original page remains unchanged.' : 'Delete this local draft.'?></label><button class="danger">Confirm deletion</button></form><?php endif ?></section>
<?php endif ?></aside></div><?php endif ?>
<?php elseif ($page === 'help'): ?>
<p class="eyebrow">GUIDE</p><h1>From article to record.</h1><section class="panel"><ol class="steps"><li><strong>Connect:</strong> Sign in to the PDS under “Connection” using your handle and an app password.</li><li><strong>Set up website:</strong> Create your publication under “Website & verification”. Place the file shown there on your website and run domain verification.</li><li><strong>Configure extraction:</strong> Choose and reorder selectors for your website structure. The first usable match wins. Tags come from meta keywords.</li><li><strong>Import article:</strong> Enter the exact HTTPS URL under “Articles”. Review the title, text, date, tags and cover in the preview. Save as a draft.</li><li><strong>Publish:</strong> Transfer the saved draft to the PDS as a public record. A cover image, if provided, is uploaded as a blob.</li><li><strong>Verify article:</strong> Add the displayed link to your page’s HTML head. Upload the page, clear caches if needed, then verify the article.</li><li><strong>Update:</strong> “Reimport page” first creates a preview. Review, save and publish it. The AT-URI and existing link stay the same.</li><li><strong>Delete:</strong> Confirm PDS deletion. Then redeploy the central mapping or remove the link manually, verify removal and close the completed entry.</li></ol></section>
<section class="panel soft"><h2>HTML, plain text and images</h2><p><code>textContent</code> is plain text according to Standard.site. HTML is retained locally as a source excerpt; it is never executed in the dashboard. <code>content</code> supports typed custom content formats, but no universally understood HTML format. This dashboard therefore publishes interoperable plain text without inventing an HTML extension.</p><p>The first HTTPS image URL found wins. When publishing, the dashboard checks file type and size. Resize oversized images on your website or provide a smaller image URL in the draft. An invalid cover prevents publication; alternatively, clear the cover field.</p><p>There is no background synchronization. Checks show the state at the displayed time. Redirects, JavaScript-generated content and password-protected source pages are not imported automatically.</p></section>
<section class="panel"><h2>Backups & pending operations</h2><p>The backup contains your publication, articles, HTML excerpts, selectors and check results; no access tokens or passwords. To restore it, stop the dashboard and replace <code>var/dashboard.json</code> with the backup file. Back up the current version first.</p><a class="button secondary" href="?download=backup">Download JSON backup</a><p>Interrupted PDS transfers remain recorded. “Retry pending operation” first checks whether the transfer already arrived. External PDS changes are never silently overwritten.</p><p><a href="https://standard.site/docs/introduction/" target="_blank" rel="noopener noreferrer">Standard.site documentation</a> · <a href="https://standard.site/docs/verification/" target="_blank" rel="noopener noreferrer">Verification</a></p></section>
<?php else: ?>
<p class="eyebrow">PUBLISHING OVERVIEW</p><div class="heading-row"><div><h1>Your articles.</h1><p class="lead">Publish selected pages. Track every step.</p></div><a class="button secondary" href="?download=backup">Back up JSON</a></div>
<?php $published_count = 0; $checked_count = 0; foreach ($state['documents'] as $doc) { if ($doc['cid']) $published_count++; if ($doc['cid'] && ($doc['check']['ok'] ?? false)) $checked_count++; } ?>
<div class="stats"><div><strong><?=count($state['documents'])?></strong><span>managed articles</span></div><div><strong><?=$published_count?></strong><span>published on the PDS</span></div><div><strong><?=$checked_count?></strong><span>last verified successfully</span></div></div>
<?php if (!$publication || !$publication['cid']): ?><section class="panel soft"><h2>Start with your website</h2><p>Connect your PDS and create the publication first. You can then add articles.</p><div class="actions"><a class="button" href="?page=connection">1. Connect account</a><a class="button secondary" href="?page=publication">2. Set up website</a></div></section><?php else: ?>
<?php if (!mapping_is_deployed($state, $config['deployment'] ?? [])): ?><p class="check neutral">Using the central include? <a href="?page=deployment">Deploy current mapping</a>. This step is unnecessary if you only use manual links.</p><?php endif ?>
<?php if (!($publication['check']['ok'] ?? false)): ?><div class="check warning">The domain has not been verified yet. <a href="?page=publication">Complete domain verification</a>.</div><?php endif ?>
<section class="panel"><h2>Add article</h2><p><a href="?page=bulk">Update all URLs or import a sitemap</a></p><?php form_start('analyze', $state); ?><div class="import-row"><?php field('url', 'Public article URL', '', 'url', true); ?><button>Import page</button></div></form><p class="muted">Preview first, then save a draft. Importing does not publish anything.</p></section><?php endif ?>
<section class="panel article-list"><div class="list-heading"><h2>Managed pages</h2><label class="search-label">Filter <input type="search" id="article-search" placeholder="Title or URL"></label></div>
<?php if (!$state['documents']): ?><div class="empty"><span aria-hidden="true">↗</span><h3>Ready for your first article.</h3><p>After setting up your website, enter a URL above.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th scope="col">Articles</th><th scope="col">Publication</th><th scope="col">Verification</th><th scope="col">Action</th></tr></thead><tbody><?php foreach (array_reverse($state['documents'], true) as $doc_id => $doc): ?><tr data-article-row><td><strong><?=h($doc['record']['title'])?></strong><small class="url"><?=h($doc['url'])?></small></td><td><span class="status <?= $doc['pending'] || $doc['status'] === 'removed' ? 'warning' : '' ?>"><?=h($doc['pending'] ? 'Operation pending' : ($doc['status'] === 'removed' ? 'Remove link' : ($doc['cid'] ? ($doc['record'] != $doc['published_record'] ? 'Changes pending' : 'Published') : 'Draft')))?></span></td><td><?=h($doc['check'] ? ($doc['check']['ok'] ? ($doc['status'] === 'removed' ? 'Link removed' : 'Verified successfully') : 'Action required') : 'Not checked yet')?><small><?=h($doc['check']['at'] ?? '')?></small></td><td><a class="button secondary" href="?page=editor&id=<?=h($doc_id)?>">Open<span class="sr-only">: <?=h($doc['record']['title'])?></span></a></td></tr><?php endforeach ?></tbody></table></div><p id="no-results" hidden>No matching articles found.</p><?php endif ?></section>
<?php endif ?>
<footer class="footer">standard-stuffer · Your website on AT Protocol.<span>Version 1.2.1</span></footer>
</main></div><?php endif ?><div id="live-message" class="sr-only" aria-live="polite"></div></body></html>
