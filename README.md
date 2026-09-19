# standard-stuffer

Publish selected pages of an existing website to [Standard.site](https://standard.site/docs/introduction/) through an AT Protocol Personal Data Server (PDS).

## Features

- Create/update your publication; download and check its domain-verification file.
- Fetch an article, review the extracted fields, save a draft, then explicitly publish.
- Update and delete records using stable AT-URIs; recover interrupted writes without creating duplicates.
- Prioritized CSS-selector lists for title, description, date, cover and text: the first usable result wins.
- Tags taken exclusively from HTML meta keywords; editable after extraction.
- Convert article HTML to paragraph-preserving plaintext; keep the HTML excerpt locally.
- Upload cover images as PDS blobs.
- Check actual domain/article verification and link removal after deletion.
- Optional HTTP(S) proxy for website, image and verification fetches.
- Export a consolidated URL → AT-URI JSON mapping, deploy it over **SFTP** or **FTP with explicit TLS (AUTH TLS)**, and load it through a small PHP helper in your website's shared `<head>`.
- File locks, atomic local writes, stale-form protection, dashboard login and JSON backups.

## Requirements

- 64-bit PHP. Source syntax is compatible with PHP 7.4; use a currently supported PHP version in production.
- Extensions: cURL, DOM/libxml, mbstring, JSON, Session. `getimagesizefromstring` is used for image validation; GD is not required.
- HTTPS for the dashboard and public source pages. Public IPv4 DNS resolution for website/PDS requests.
- A local filesystem supporting `flock` and atomic rename. Do not assume equivalent behavior on NFS/SMB.
- For **SFTP**, PHP's cURL/libcurl must include the `sftp` protocol, an SSH backend and `CURLOPT_SSH_KNOWNHOSTS`. The PHP SSH2 extension is not required.
- For **explicit FTPS**, PHP's cURL must include FTP and TLS support. Both control and data connections must support TLS.
- For the page proxy, cURL 7.49+ with `CURLOPT_CONNECT_TO`; HTTPS proxies additionally need proxy TLS-verification support.

Check the **PHP** cURL build, which may differ from the command-line `curl` binary:

```sh
php -r 'print_r(curl_version()["protocols"]);'
```

The application has no package dependencies. Development-only testing tools are not needed on your server.

## Install the dashboard

1. Unpack the project, for example into `/srv/standard-stuffer`.
2. Run `php setup.php` in that directory. Enter a dashboard password of at least 14 characters. The input is visible but is not passed as a shell argument. Only its hash is stored.
3. Serve **only `public/`** as the document root of a dedicated HTTPS site, such as `https://stuffer.example.de`.
4. Keep `app/`, `website/`, `config.php`, `var/` and credentials outside the public document root. Give the PHP user read access to `config.php` and write access to `var/`. Adjust ownership if setup ran under another user; do not use blanket `777` permissions.
5. Open the dashboard, log in, and follow the instructions under **Connection**, then **Website & verification**.

`setup.php` creates a private `config.php` from `config.example.php`. Review it to configure proxy and deployment. Existing configurations are never overwritten: when upgrading, manually add the new `fetch_proxy` and `deployment` sections from the example. Environment-variable secret lookups remain in the generated file.

### Existing hosting / no SSH

You may run `setup.php` locally with PHP and upload the resulting configuration, adjusting `data_dir` to an absolute path on the server.

For a fixed `public_html/` document root:

- Put `app/`, `website/` and `config.php` in `/home/account/standard-stuffer/` outside `public_html/`.
- Copy the **contents** of `public/` to `/home/account/public_html/stuffer/`.
- Set the private root at the top of the copied `index.php`:

```php
$private_root = '/home/account/standard-stuffer';
```

Alternatively set the server environment variable `STANDARD_STUFFER_ROOT`. The application refuses to run when its configuration or data directory is inside `DOCUMENT_ROOT`; `.htaccess` alone is not a substitute. Configure the equivalent public/private layout on IIS or Nginx. Behind a trusted reverse proxy, the web server must correctly pass `HTTPS=on` to PHP; arbitrary client-forwarded headers are not trusted.

`allow_http_localhost` defaults to `false`. Enable it only for local development bound to `127.0.0.1`.

## Connect your PDS

Under **Connection**, enter your handle/DID and a dedicated app password. For Bluesky, the default login service is `https://bsky.social`; the actual PDS endpoint is taken from the login response. For another provider, enter its trusted PDS endpoint. Do not submit credentials to an unrelated server.

The first successful connection binds this dashboard to that account's DID. One installation manages **one account and one publication**. Existing records created by other tools are not automatically imported. OAuth and interactive 2FA login are not implemented.

Access/refresh tokens are kept only in the server-side session. The app password is not stored. Sessions expire after one hour of inactivity; reconnect when needed. No PDS credentials are included in backups or the website mapping.

## One-time domain verification

1. Under **Website & verification**, create the publication with its base URL, name and description.
2. Download the generated `site.standard.publication` text file.
3. Make its contents available at the **exact endpoint shown in the dashboard**, returning only the publication's AT-URI, without HTML.
4. Run **Verify domain**. This checks the public response and the published PDS record.

A root publication uses:

```text
https://example.de/.well-known/site.standard.publication
```

For a non-root publication, the publication path is appended:

```text
Publication: https://example.de/blog
Endpoint:   https://example.de/.well-known/site.standard.publication/blog
```

Use an appropriate web-server route for the second case if needed. This domain-verification endpoint remains separate from the article mapping and is **not** automatically deployed. Keep it in place permanently. The publication base URL cannot be changed after its first successful publication.

## Extract and publish articles

1. Configure **Extraction** for your website structure.
2. Under **Articles**, enter the final public HTTPS article URL.
3. Review the preview, its selector matches and warnings. Supply a publication date if none was found. Save the draft.
4. Click **Publish saved version** to create/update the public PDS record. Unsaved form edits are not published.
5. Deploy the mapping as described below, or manually place the generated link in the article's `<head>`.
6. Run **Verify article** on the article. A successful file deployment alone does not prove that your HTML serves the correct link.

For updates, **Reimport page** creates a preview first. Review, save and publish. The AT-URI is stable. For deletion, confirm the PDS deletion, redeploy the mapping or remove the manual link, check removal, then close the local entry. PDS deletion cannot guarantee deletion of copies made by third parties.

There is no automatic polling or background synchronization.

### Ordered CSS selectors

Lists are searched from top to bottom. Within each selector, nodes are inspected in document order. The first usable, non-empty value ends the search. JavaScript adds selection suggestions and move/add/remove controls; without JavaScript, enter one selector per line.

Supported selectors:

```css
article
#content
.article-body
article.story
meta[property="og:image"]
img[data-src]
[role="main"]
.article-body img
article > .content
```

This is a documented CSS subset: no pseudo-classes, sibling combinators, comma groups, CSS escapes or complex attribute operators. Unsupported input is rejected. Put alternatives on separate lines instead of using comma groups.

| Field | Extraction |
| --- | --- |
| Title / description | `content`, then `datetime`, otherwise element text. |
| Date | Valid ISO date from `content`, `datetime` or text. No fallback to today's date. A date without time is stored as midnight UTC. |
| Cover | Meta `content`, `data-src`, `data-lazy-src`, `src`, `href`, then first `srcset` candidate. A container selector uses its first `img`. Relative URLs are resolved against the article URL. |
| Text | First non-empty content region. Navigation, scripts, styles, footer, aside, forms and explicitly hidden elements are removed. Paragraph breaks remain. |
| Tags | Only `meta[name="keywords"]`, split at commas, trimmed and deduplicated. No categories or generated keywords are added. |

An image result becomes usable once it provides a valid HTTPS URL. Download/file validation happens when publishing; a failed image does not silently switch to a different selector. Correct its URL or clear the cover field. JavaScript-rendered content, computed CSS visibility, `<base>` tags and other lazy-loading attributes are not evaluated.

### HTML and images

Standard.site's `textContent` is **plaintext**, not HTML or Markdown. standard-stuffer keeps the extracted HTML excerpt locally and offers it as a text download, but never executes it. Its content cleanup is not a general-purpose XSS sanitizer for other applications.

The extensible `content` field supports typed content formats; it does not provide a universally understood raw HTML format. This application therefore publishes plaintext, without inventing a custom HTML extension. Check complex tables and layouts after extraction.

`coverImage` is a **PDS blob**, not a URL. The image is downloaded, validated and uploaded using `com.atproto.repo.uploadBlob`. JPEG, PNG, WebP and GIF are supported, strictly below 1,000,000 bytes. No automatic resizing. Each new publication fetches the cover again, including when its URL is unchanged; retries of an already pending operation reuse that operation's recorded blob.

## Optional proxy for PHP page fetches

Edit the private `config.php`:

```php
'fetch_proxy' => [
    'url' => 'http://proxy.example.net:8080',
    'username' => getenv('STANDARD_STUFFER_PROXY_USER') ?: '',
    'password' => getenv('STANDARD_STUFFER_PROXY_PASSWORD') ?: '',
    'auth' => 'basic', // basic, digest or ntlm
    'proxy_ca_file' => '',
    'origin_ca_file' => '',
],
```

Set `url` to `''` to disable it. Use `https://proxy.example.net:8443` if your proxy supports TLS to the proxy itself. Basic authentication to an HTTP proxy does not encrypt proxy credentials on that hop; prefer HTTPS when available. Credentials are supplied separately, never inside the URL.

- Applies to article HTML, cover images and public domain/article verification endpoints.
- **PDS API calls and SFTP/FTPS deployment remain direct.** No automatic proxying of those credentials.
- No ambient `HTTP_PROXY`/`NO_PROXY` fallback. A configured proxy is not silently bypassed.
- The origin is resolved locally, checked for a public IPv4 address, then pinned using `CURLOPT_CONNECT_TO`. The proxy must allow **CONNECT to that numeric IP on port 443**. Original hostname/SNI/certificate validation remain unchanged. Proxies that require a hostname in CONNECT need a compatible policy; DNS pinning is not disabled to work around them.
- `proxy_ca_file`: optional CA bundle for the HTTPS proxy's own certificate.
- `origin_ca_file`: optional CA bundle for HTTPS origins, including an organization-approved TLS-inspection CA if required. Use a suitable complete CA bundle. Certificate verification is never disabled.
- Only HTTP(S) CONNECT proxies are supported; SOCKS and proxy-only DNS resolution are not.

The **Extraction** screen indicates whether the fetch proxy is configured. Test it by importing a public article or running verification.

## Central article mapping and website integration

Instead of editing each article, install the helper once in your shared PHP head template.

### 1. Install the website helper

Copy `website/standard-stuffer-links.php` to the website server. It can also be downloaded from **Deployment**. Keep it and the JSON mapping outside the public document root where possible.

Inside the common `<head>`, add:

```php
<?php
require_once '/srv/site-private/standard-stuffer-links.php';
echo standard_stuffer_link(
    '/srv/site-private/standard-stuffer-map.json',
    'https://www.example.de'
);
?>
```

Use your publication's exact base URL, including a publication subpath if applicable. Adapt both absolute **local PHP paths**. SFTP/FTP paths may differ because of a server chroot.

This is a normal explicit `require_once`, **not `auto_prepend_file`**. The helper:

- reads the local JSON once per PHP request, with a 5 MB read limit;
- selects the current request URL without trusting the incoming Host header;
- prefers an exact query-string mapping, otherwise allows a queryless article mapping for tracking parameters;
- emits the validated, HTML-escaped `site.standard.document` link;
- emits nothing for an unmapped article, missing/corrupt mapping or publication mismatch.

There is no network request on each page view and no long-lived mapping cache. Full-page/CDN caches on your website still need invalidation after changing the mapping. Remove any old manually inserted article links when switching to the helper.

### 2. Configure SFTP or explicit FTPS

Connection settings belong in the private `config.php`, never `dashboard.json`. Environment-variable examples are included. You can instead fill private configuration values directly if your host cannot set environment variables; do not commit that file.

Create the destination directory in advance. The transfer user needs read, write and rename/replace permission; the website PHP user needs read permission on the resulting JSON. The server must support replacing an existing destination through rename. No delete-then-upload fallback is used.

Remote paths must be absolute paths visible to the transfer server and end in `.json`; supported characters are ASCII letters/digits, `/`, `.`, `_`, `-`. Spaces, traversal and control characters are intentionally rejected because paths also appear in FTP/SFTP rename commands.

#### SFTP

```php
'deployment' => [
    'protocol' => 'sftp',
    'host' => 'sftp.example.de',
    'port' => 22,
    'username' => getenv('STANDARD_STUFFER_DEPLOY_USER') ?: '',
    'password' => getenv('STANDARD_STUFFER_DEPLOY_PASSWORD') ?: '',
    'remote_path' => '/private/standard-stuffer-map.json',
    'known_hosts' => '/srv/standard-stuffer/credentials/known_hosts',
    'private_key' => '',
    'public_key' => '',
    'key_passphrase' => getenv('STANDARD_STUFFER_KEY_PASSPHRASE') ?: '',
    'ca_file' => '',
],
```

`known_hosts` is mandatory and must be readable by PHP, in OpenSSH format. Obtain the host key and verify its fingerprint through a trusted channel such as your provider's panel before adding it. A nonstandard port is normally represented as `[host]:port`. Unknown or changed host keys must fail; no automatic trust-on-first-use is performed.

For key authentication, set `private_key` to a readable private key file, optionally `public_key` to its matching public key and `key_passphrase` if encrypted. If `private_key` is empty, password authentication is used. Keep all keys outside the public document root and restrict filesystem access.

#### FTP with explicit TLS

```php
'deployment' => [
    'protocol' => 'ftps',
    'host' => 'ftp.example.de',
    'port' => 21,
    'username' => getenv('STANDARD_STUFFER_DEPLOY_USER') ?: '',
    'password' => getenv('STANDARD_STUFFER_DEPLOY_PASSWORD') ?: '',
    'remote_path' => '/private/standard-stuffer-map.json',
    'ca_file' => '',
],
```

This uses **`ftp://` plus mandatory AUTH TLS**, equivalent to explicit FTP over TLS. It is not plain FTP and not implicit FTPS on port 990. `CURLUSESSL_ALL` requires TLS on both control and data connections. Server certificates and hostnames are verified against the system trust store or the optional `ca_file`. Passive/EPSV mode is used; a foreign PASV IP is ignored. Configure your server/firewall accordingly.

### 3. Deploy and check

1. In **Deployment**, inspect the destination and current mapping.
2. Confirm replacing that specific remote JSON file and click **Deploy mapping file**.
3. The app uploads a random temporary sibling, downloads it, verifies SHA-256, renames it into place, downloads the active file and verifies it again.
4. **Check remote file** compares the remote bytes against the current mapping without writing. It reports a missing file before the first deployment.
5. Run article verification to check the actual HTML output. Domain verification remains independent.

The mapping contains only confirmed published associations, not drafts, HTML, credentials or private dashboard metadata. Pending PDS writes/deletions block export/deployment until reconciled. Deleted records disappear from the next deployment. Empty mappings are valid and remove all generated article links.

Redeploy after a new publication or PDS deletion. Editing a draft does not change the mapping. The dashboard compares stable mapping checksums with the last verified deployment and flags changes; this is a timestamped observation, not continuous monitoring.

Interrupted transfers may leave `*.upload-*` files beside the target. They are never read by the helper and can be removed after confirming that no transfer is running. A rename whose response is lost is recovered by checking the active file. If the server cannot replace an existing file by rename, deployment fails rather than deleting the old file first. Atomicity ultimately depends on the server's filesystem/rename implementation.

For a manual deployment, download **Download mapping file**, upload it using the same safe replacement principle, then use **Check remote file** if a transfer connection is configured. Back up the previous mapping before your first migration; reverting the file reverts the emitted links, not the PDS records.

## Bulk updates and sitemap import

Open **Bulk operations** in the dashboard:

- **Update all URLs** fetches every active stored URL with the configured proxy and selectors. It replaces local edits, updates already published PDS records with their stable AT-URIs, and keeps drafts unpublished. A confirmation checkbox describes these changes before starting.
- Only an explicit HTTP **404 or 410** deletes a record (published records on the PDS, drafts locally). Redirects, 403/429/5xx, transport errors and invalid content are reported without deletion. Pending PDS operations are skipped for manual reconciliation. Empty extraction does not erase existing article text.
- **Import from sitemap** accepts an HTTPS XML sitemap or sitemap index on the publication host, including gzip. It follows nested indexes, avoids cycles and duplicates, and imports new in-scope URLs as drafts. Existing URLs, including removed records, are skipped. An absent sitemap entry never causes deletion.
- New pages without publication dates remain editable drafts. Add the date before publishing; sitemap `lastmod` is not used as a publication date. Failed extractions appear in the log.
- Limits: 100 sitemap files, 10,000 unique article URLs per import, and 8 MB per compressed/decompressed sitemap. Unsupported or oversized files are reported, not silently truncated. Split larger collections into smaller sitemaps.

Click **Continue automatically** to process one task per authenticated POST while the page is open. You can pause, close the tab and resume later; without JavaScript, repeatedly click **Process next URL**. The JSON queue persists progress, results and job IDs; normal revision/CSRF protection also applies to bulk operations. This is not a background daemon or scheduled job. Request duration for a single article still depends on website, cover and PDS response times.

A stopped job keeps completed changes. Reconnect to the PDS if needed, reconcile pending writes in the article editor, then start another update. The dashboard shows the latest 100 results and offers the full JSON log; a new job replaces the previous log. Back up before overwriting manually edited content. After deletions, redeploy the mapping and check link cleanup. Domain verification is unchanged.

The importer follows the [Sitemaps XML structure](https://www.sitemaps.org/protocol.html), with the narrower limits above for JSON-based hosting.

## Verification behavior

- Domain verification compares the trimmed public response with the exact publication AT-URI, then checks the PDS record.
- Article verification checks an exact link in the HTML **head**, then checks the PDS record.
- Deleted-article cleanup checks that the old link is gone; HTTP 404/410 also confirms that the original page is gone.
- Checks are timestamped snapshots. A PDS connection is needed for remote record confirmation.
- Redirects are not followed automatically. Enter the final HTTPS URL and expose verification endpoints directly.

## Storage, credentials and recovery

| File | Purpose |
| --- | --- |
| `var/dashboard.json` | Records, HTML excerpts, selectors, verification/deployment status and pending writes. |
| `var/dashboard.lock` | Shared/exclusive locks for application state. |
| `var/login.json` | Short-lived login attempt counters: 10 failures per IP in 15 minutes. |
| `var/sessions/` | Server-side sessions and temporary PDS tokens. |
| `config.php` / `credentials/` | Private configuration, keys and trust files; excluded from Git. |

Writes use temporary files and rename; forms carry revisions to reject stale edits. Transfer/deployment actions hold the state lock to prevent publication changes mid-export. The complete state is loaded per request; JSON storage is intended for modest collections, not millions of pages.

PDS writes are journaled before transmission and use stable record keys. Retry an interrupted operation to reconcile it. `swapRecord` prevents silent overwrites of external changes. Conflicts require checking the PDS externally; this version has no merge or general import function. Do not blindly edit CIDs in JSON.

Download a JSON backup from the dashboard. To restore, stop dashboard access, back up the current file, replace `var/dashboard.json`, set permissions and reopen. An old backup may conflict with newer PDS records and correctly refuse overwrites. It also does not restore a remote mapping file; check/redeploy that separately.

To change the dashboard password, replace `admin_password_hash` with a new `password_hash(..., PASSWORD_DEFAULT)` and invalidate existing session files while the app is stopped.

HTTPS, CSRF protection, output escaping, CSP, secure session cookies, size/time limits and public-DNS pinning are included. Intranet source pages and IPv6-only origins are not supported. Transfer destinations are trusted administrator configuration and may be private hosts; they are not entered by untrusted article content.

## Tests

```sh
php tests/lint.php
php tests/bulk.php # includes the core suite
php tests/transport.php
php tests/deployment.php
```

Optional local SFTP, explicit FTPS and HTTP CONNECT integration fixtures (Python 3.10+):

```sh
python3 -m venv /tmp/stuffer-tests
/tmp/stuffer-tests/bin/pip install -r tests/requirements.txt
/tmp/stuffer-tests/bin/python tests/protocols.py
```

These fixtures create disposable local servers, keys, certificates and files. PHP cURL must support FTP and SFTP. No production credentials are used. GitHub Actions runs the PHP checks on PHP 8.2–8.5 and protocol fixtures on 8.3.

The PHP suites do not contact an external PDS or publish content. Tests cover extraction, verification, URL boundaries, blob handling, interrupted operations, mapping projection/helper, proxy policy and deployment ordering. See [the test report](docs/TESTREPORT.md) for executed browser and protocol integration checks and their limitations.

## Protocol references

- [Standard.site introduction](https://standard.site/docs/introduction/)
- [Publication lexicon](https://standard.site/docs/lexicons/publication)
- [Document lexicon](https://standard.site/docs/lexicons/document)
- [Verification](https://standard.site/docs/verification/)
- [cURL CONNECT_TO and proxy tunneling](https://curl.se/libcurl/c/CURLOPT_CONNECT_TO.html)
- [cURL mandatory FTP TLS](https://curl.se/libcurl/c/CURLOPT_USE_SSL.html)
- [cURL SSH known-host verification](https://curl.se/libcurl/c/CURLOPT_SSH_KNOWNHOSTS.html)

