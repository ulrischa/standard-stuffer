<?php
// Batch controls and persisted progress. Maintainer: Uli.
$job = $state['bulk'] ?? null;
?>
<p class="eyebrow">BULK OPERATIONS</p><h1>Manage pages in bulk.</h1>
<p class="lead">Refresh all managed URLs or import new pages from a sitemap as drafts.</p>
<?php if (!$job || $job['status'] !== 'running'): ?>
<div class="two-columns"><section class="panel"><h2>Update all URLs</h2>
<p>Fetch every active URL using the current selectors. Existing local edits will be replaced. Published articles are updated on the PDS; drafts remain drafts.</p>
<p>Only HTTP 404/410 triggers deletion: published records on the PDS, drafts locally. Redirects, 403/429/5xx, invalid HTML and connection errors preserve the entry. Pending PDS operations are skipped.</p>
<?php form_start('bulk_start', $state); ?><input type="hidden" name="mode" value="update"><label class="checkbox"><input type="checkbox" name="confirm" value="yes" required> Overwrite content and delete records when their URL returns HTTP 404/410.</label><button>Prepare bulk update</button></form></section>
<section class="panel soft"><h2>Import from sitemap</h2><p>XML sitemaps, sitemap indexes and gzip are supported. Only HTTPS pages within your publication are imported. Existing URLs are skipped, not overwritten. Missing sitemap entries never trigger deletion.</p>
<?php form_start('bulk_start', $state); ?><input type="hidden" name="mode" value="sitemap"><?php field('sitemap', 'Sitemap URL', '', 'url', true, 'A sitemap index is also supported; use the same domain as your website.'); ?><label class="checkbox"><input type="checkbox" name="confirm" value="yes" required> Import all eligible new URLs as drafts.</label><button>Prepare sitemap import</button></form>
<p class="muted">Maximum 100 sitemap files, 10,000 unique article URLs and 8 MB XML per file. Invalid files are logged as errors. If no publication date is found, the draft remains undated; add the date before publishing.</p></section></div>
<?php endif ?>
<?php if ($job): ?><section class="panel"><h2><?=$job['mode'] === 'update' ? 'Bulk update' : 'Sitemap import'?>: <?=h(['running' => 'ready / in progress', 'done' => 'completed', 'stopped' => 'stopped'][$job['status']] ?? $job['status'])?></h2>
<p role="status"><?=h($job['cursor'])?> of <?=count($job['queue'])?> tasks processed · <?=h($job['counts']['ok'])?> successful · <?=h($job['counts']['skip'])?> skipped · <?=h($job['counts']['error'])?> errors</p>
<progress value="<?=h($job['cursor'])?>" max="<?=max(1, count($job['queue']))?>" aria-label="Progress"></progress>
<?php if ($job['status'] === 'running'): ?>
<p>The dashboard processes one page at a time. With JavaScript, it continues automatically while this view stays open. Without JavaScript, click “Process next URL” repeatedly. Resume here after an interruption; resolve pending PDS operations in the affected article first.</p>
<div class="actions"><?php form_start('bulk_step', $state); ?><input type="hidden" name="job_id" value="<?=h($job['id'])?>"><button>Process next URL</button><button type="button" class="secondary" data-bulk-run="<?=h($job['id'])?>">Continue automatically</button></form><button type="button" class="secondary" data-bulk-pause>Pause automatic processing</button>
<?php form_start('bulk_stop', $state); ?><input type="hidden" name="job_id" value="<?=h($job['id'])?>"><button class="secondary">Stop job</button></form></div>
<?php endif ?>
<p>After PDS deletions, <a href="?page=deployment">redeploy the central mapping</a>. Domain verification remains in place. Review new drafts, fill in missing details and publish them individually when ready. Starting a new job replaces this log.</p>
<?php if ($job['log']): ?><details open><summary>Last <?=min(100, count($job['log']))?> results</summary><div class="table-wrap"><table><thead><tr><th>URL</th><th>Result</th></tr></thead><tbody><?php foreach (array_reverse(array_slice($job['log'], -100)) as $entry): ?><tr><td class="wrap"><?=h($entry['url'])?></td><td><?=h(english_message($entry['result']))?></td></tr><?php endforeach ?></tbody></table></div></details><a class="button secondary" href="?download=bulk_log">Download full log</a><?php endif ?>
</section><?php endif ?>
