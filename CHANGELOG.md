# Changelog

## 1.2.1 – 2026-09-19

- Translate all dashboard controls, instructions, accessibility labels, setup prompts and application messages into English.
- Display historical application messages in English without changing stored article content.
- Update README control references while preserving previous deletions.

## 1.2.0 – 2026-09-19

- Add resumable bulk refresh with PDS updates and deletion only on HTTP 404/410.
- Import XML/gzip sitemaps and nested indexes as drafts, with deduplication and scope checks.
- Add progress, pause/resume, full result logs and contextual instructions.
- Keep undated imports editable and prevent publication until a date is supplied.
- Allow cleanup confirmation for removed pages returning HTTP 404/410.

## 1.1.0 – 2026-09-18

- Add explicit HTTP(S) CONNECT proxy configuration for page, cover and verification fetches.
- Deploy the consolidated URL-to-AT-URI JSON mapping over SFTP or explicit FTPS.
- Verify temporary upload and active file checksums; preserve the old file on pre-replacement failures.
- Provide a website PHP include that reads local JSON on each request, without auto_prepend_file.
- Add deployment instructions, remote checks and downloads to the dashboard.
- Add English setup documentation, protocol integration fixtures and CI.

## 1.0.0 – 2026-09-18

- PHP and vanilla JavaScript dashboard for one website and one AT Protocol account.
- JSON storage with file locking, revisions and resumable PDS operations.
- Publication creation and updates, domain verification file generation and checks.
- Article import, draft editing, publishing, updates and deletion.
- Ordered CSS selector lists for title, description, date, cover image and text.
- Tags from meta keywords; plain-text conversion with locally retained HTML excerpts.
- Cover image upload as PDS blobs.
- Article verification and old-link removal checks.
- Contextual instructions for manual setup steps.
- Local authentication, JSON backups and responsive interface.
