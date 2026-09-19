<?php
// Batch controls and persisted progress. Maintainer: Uli.
$job = $state['bulk'] ?? null;
?>
<p class="eyebrow">SAMMELVERARBEITUNG</p><h1>Seiten gemeinsam pflegen.</h1>
<p class="lead">Alle verwalteten URLs abgleichen oder neue Seiten aus einer Sitemap als Entwürfe übernehmen.</p>
<?php if (!$job || $job['status'] !== 'running'): ?>
<div class="two-columns"><section class="panel"><h2>Alle URLs aktualisieren</h2>
<p>Jede aktive URL neu abrufen und mit den aktuellen Selektoren einlesen. Vorhandene lokale Änderungen werden ersetzt. Bereits veröffentlichte Artikel werden am PDS aktualisiert, Entwürfe bleiben Entwürfe.</p>
<p>Nur HTTP 404/410 löst eine Löschung aus: veröffentlichte Datensätze am PDS, Entwürfe lokal. Bei Weiterleitungen, 403/429/5xx, ungültigem HTML oder Verbindungsfehlern bleibt der Eintrag erhalten. Offene PDS-Vorgänge werden übersprungen.</p>
<?php form_start('bulk_start', $state); ?><input type="hidden" name="mode" value="update"><label class="checkbox"><input type="checkbox" name="confirm" value="yes" required> Inhalte überschreiben und bei HTTP 404/410 die Datensätze löschen.</label><button>Sammelabgleich vorbereiten</button></form></section>
<section class="panel soft"><h2>Aus Sitemap hinzufügen</h2><p>XML-Sitemap, Sitemap-Index und Gzip werden unterstützt. Nur HTTPS-Seiten innerhalb deiner Publication werden importiert; vorhandene URLs werden übersprungen, nicht überschrieben. Fehlende Sitemap-Einträge führen niemals zur Löschung.</p>
<?php form_start('bulk_start', $state); ?><input type="hidden" name="mode" value="sitemap"><?php field('sitemap', 'Sitemap-URL', '', 'url', true, 'Auch ein Index mit mehreren Sitemaps; gleiche Domain wie die Website.'); ?><label class="checkbox"><input type="checkbox" name="confirm" value="yes" required> Alle zulässigen neuen URLs als Entwürfe einlesen.</label><button>Sitemap-Import vorbereiten</button></form>
<p class="muted">Maximal 100 Sitemap-Dateien, 10.000 eindeutige Artikel-URLs und 8 MB XML pro Datei. Ungültige Dateien werden als Fehler protokolliert. Kein Veröffentlichungsdatum gefunden? Der neue Entwurf bleibt ohne Datum; vor Veröffentlichung ergänzen.</p></section></div>
<?php endif ?>
<?php if ($job): ?><section class="panel"><h2><?=$job['mode'] === 'update' ? 'Sammelabgleich' : 'Sitemap-Import'?>: <?=h(['running' => 'bereit / in Bearbeitung', 'done' => 'abgeschlossen', 'stopped' => 'gestoppt'][$job['status']] ?? $job['status'])?></h2>
<p role="status"><?=h($job['cursor'])?> von <?=count($job['queue'])?> Aufgaben verarbeitet · <?=h($job['counts']['ok'])?> erfolgreich · <?=h($job['counts']['skip'])?> übersprungen · <?=h($job['counts']['error'])?> Fehler</p>
<progress value="<?=h($job['cursor'])?>" max="<?=max(1, count($job['queue']))?>" aria-label="Fortschritt"></progress>
<?php if ($job['status'] === 'running'): ?>
<p>Das Dashboard verarbeitet jeweils eine Seite. Mit JavaScript läuft es automatisch weiter, solange diese Ansicht geöffnet bleibt. Ohne JavaScript „Nächste URL verarbeiten“ wiederholt anklicken. Nach einem Abbruch hier fortsetzen; bei offenem PDS-Vorgang zuerst den betroffenen Artikel öffnen.</p>
<div class="actions"><?php form_start('bulk_step', $state); ?><input type="hidden" name="job_id" value="<?=h($job['id'])?>"><button>Nächste URL verarbeiten</button><button type="button" class="secondary" data-bulk-run="<?=h($job['id'])?>">Automatisch fortsetzen</button></form><button type="button" class="secondary" data-bulk-pause>Automatik pausieren</button>
<?php form_start('bulk_stop', $state); ?><input type="hidden" name="job_id" value="<?=h($job['id'])?>"><button class="secondary">Auftrag stoppen</button></form></div>
<?php endif ?>
<p>Nach PDS-Löschungen die <a href="?page=deployment">zentrale Zuordnung neu deployen</a>. Die Domain-Verifikation bleibt bestehen. Neue Entwürfe prüfen, fehlende Angaben ergänzen und bei Bedarf einzeln veröffentlichen. Ein neuer Sammellauf ersetzt dieses Protokoll.</p>
<?php if ($job['log']): ?><details open><summary>Letzte <?=min(100, count($job['log']))?> Ergebnisse</summary><div class="table-wrap"><table><thead><tr><th>URL</th><th>Ergebnis</th></tr></thead><tbody><?php foreach (array_reverse(array_slice($job['log'], -100)) as $entry): ?><tr><td class="wrap"><?=h($entry['url'])?></td><td><?=h($entry['result'])?></td></tr><?php endforeach ?></tbody></table></div></details><a class="button secondary" href="?download=bulk_log">Vollständiges Protokoll herunterladen</a><?php endif ?>
</section><?php endif ?>
