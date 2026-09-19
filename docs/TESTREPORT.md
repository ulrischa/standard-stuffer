# Test report — standard-stuffer 1.2.1

Date: 2026-09-19

## English interface verification (1.2.1)

- All 109 existing PHP checks still pass; 7 additional checks cover historical message translation and idempotence. PHP and JavaScript syntax checks pass.
- Chromium: all main views display English headings and navigation; document language is `en`. Historical verification text displays in English. Automatic bulk processing, log download and sitemap prepare/stop controls still work. Layout checked at 320/768/1440 px without page overflow; no JavaScript runtime errors.
- README changes only update existing control references. Previously deleted lines were not restored. Article content and stored JSON are not translated or rewritten.

## Bulk update verification (1.2.0)

- Native PHP 8.3: 64 core/bulk checks, 33 mapping/deployment checks and 12 transport checks pass (109 total), plus PHP and JavaScript syntax checks.
- New coverage: sitemap namespaces/gzip/deduplication/cycles/scope, malformed XML and external-entity rejection, undated drafts and publication guard, repeat imports, stable PDS IDs, unchanged records, missing text protection, 301/403/429/500/503 preservation, interrupted writes, pending-operation skip, 404/410 deletion and persisted progress.
- Chromium against native PHP: automatic queue completion, authenticated full-log download, sitemap job preparation and stopping; 320/768/1440 px without horizontal page overflow; no JavaScript runtime errors. Screenshot visually inspected.
- PDS responses remain simulated; no production PDS credentials were supplied. The browser queue fixture uses duplicate local entries, so no production URLs are fetched by that test.


## Executed successfully

- Native PHP 8.3: all PHP files pass syntax parsing; **39 behavior tests**, **12 transport checks** and **33 deployment/mapping/proxy checks** pass.
- **13 live local protocol checks** using native PHP cURL, a Paramiko SFTP server, a pyftpdlib explicit-TLS FTP server, and an HTTP CONNECT proxy with a local TLS origin. Both transfer protocols successfully deploy twice to the same filename, verify the active contents and leave no temporary files. Invalid credentials and invalid certificate/SSH host-key trust are rejected without overwriting the active file. Proxy tunneling targets the pinned IP, preserves the original TLS SNI hostname, and rejects an untrusted certificate.
- Earlier PHP 8.2 and 8.5 WebAssembly runs: 39 core behavior tests passed. PHP 8.2 transport tests passed. PHP 7.4 syntax checks passed for the 1.0 files; the 7.4 WebAssembly/libxml runtime could not complete the behavior suite.
- Chromium against native PHP 8.3: new Deployment page renders, explains the explicit include, downloads the PHP helper through an authenticated request, and has no horizontal overflow at 320, 768 or 1440 px. No JavaScript runtime errors. Screenshot inspected.
- Earlier Chromium tests against PHP 8.2: login, selector add/reorder/save, article filtering, draft editing, HTML escaping, local draft deletion, verification snippets and contextual instructions. Forms also work with JavaScript disabled. Selector/editor layouts checked at 320, 768, 1024 and 1440 px; screenshots inspected.
- HTTP integration: login/session handling, CSRF rejection and stale form revision protection checked.
- Independent static review covered proxy pinning, TLS, SSH trust, transfer paths, public mapping projection and helper escaping. The default-port deployment fingerprint issue found during development was fixed and has regression coverage.

## Reproduce

```sh
php tests/lint.php
php tests/bulk.php
php tests/transport.php
php tests/deployment.php
python3 -m venv /tmp/stuffer-tests
/tmp/stuffer-tests/bin/pip install -r tests/requirements.txt
/tmp/stuffer-tests/bin/python tests/protocols.py
```

The protocol fixtures bind local random ports and use disposable credentials, keys and certificates. They never publish to a PDS or connect to a production transfer server. Browser tooling is a development dependency, not part of the PHP application.

## Limits

- No real PDS account or production hosting credentials were supplied. PDS API operations, conflict handling, blob upload and interrupted writes use simulated responses. Confirm real account permissions and publication/article verification after installation.
- Live SFTP tested password authentication and host-key checks. Private-key configuration is implemented but was not exercised in the protocol fixture. Live proxy testing uses an HTTP CONNECT proxy without authentication; HTTPS-proxy and Basic/Digest/NTLM authentication options have not been integration-tested.
- Rename/replace behavior and atomicity depend on the hosting server and its filesystem. The SFTP fixture explicitly supports replacement. Servers that reject replacement fail safely; the app never deletes the old active mapping to emulate rename.
- CI configuration covers PHP 8.2–8.5; a configured workflow is not itself evidence of a successful remote CI run.
- No large-collection load test, NFS/SMB locking test, IIS/Windows deployment test or full accessibility certification.
- Current supported PHP versions are recommended. No PHP 7.4 runtime support claim is made.
