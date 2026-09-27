# Synology Uploader

Synology Uploader is a small web application for uploading large files to a Synology NAS.
It splits files into resumable chunks, allowing uploads to pass through reverse proxies with
request-size or timeout limits, including Cloudflare.

Users authenticate through Synology SSO Server. They can add files, pause or resume individual
uploads, and cancel unfinished uploads. Completed files are saved to one configured directory;
the application does not provide file browsing, downloading, renaming, or deletion.

## Features

- Resumable, chunked uploads using the tus protocol
- Automatic retry after connection failures
- Resume after reloading the page by adding the same file again
- Per-file pause, resume, and cancel controls
- Authentication through Synology SSO Server using OIDC
- Optional per-user destination directories
- Automatic filename sanitization and collision handling
- No database required

## Requirements

### Synology NAS

- DSM 7.4
- Web Station
- Synology SSO Server
- PHP 8.2, 8.3, or 8.4
- PHP `curl` extension
- PHP `openssl` extension (recommended)
- HTTPS (strongly recommended)

The Web Station user, normally `http`, needs read access to the application and write access to
the destination and application state directories.

### Build computer

Build releases on another computer before copying them to the NAS. You need either:

- Composer, npm, and optionally `zip`; or
- Docker and optionally `zip`.

## Build

Clone the repository and run:

```sh
scripts/build.sh
```

The script creates:

- `build/synology-uploader/`: deployable application directory
- `build/synology-uploader.zip`: deployable archive, when `zip` is installed

The build contains all PHP and JavaScript dependencies. Composer and Node are not required on
the NAS.

## Install

### 1. Copy the application

Copy the built directory or extract the built archive somewhere accessible to Web Station, such
as a directory in the `web` shared folder.

Only the application's `public` directory should be exposed by the web server.

### 2. Create the configuration

In the deployed application directory, copy `config/config.example.php` to
`config/config.php`, then edit it and set at least:

- `base_url`: public HTTPS URL of the uploader, including a trailing slash
- `destination_dir`: absolute NAS path where completed files will be stored
- `oidc.issuer`: Synology SSO issuer URL
- `oidc.client_id`: registered OIDC client ID
- `oidc.client_secret`: registered OIDC client secret

The example configuration documents the remaining upload, session, storage, and access options.
Keep `config.php` readable only by administrators and the Web Station user.

### 3. Register the OIDC application

Open Synology SSO Server, enable its OIDC service, and register a new application.

Use this redirect URI:

```text
<base_url>auth.php
```

For example, if `base_url` is `https://uploads.example.com/`, register
`https://uploads.example.com/auth.php`.

Copy the client ID and client secret into `config.php`. Set `oidc.issuer` to the issuer shown by
SSO Server, without the `/.well-known/openid-configuration` suffix. If SSO Server requests a
post-logout redirect URI, use `base_url`.

### 4. Configure PHP in Web Station

Create or edit a Web Station PHP profile:

- Select PHP 8.2, 8.3, or 8.4.
- Enable `curl` and, preferably, `openssl`.
- Set `max_execution_time` to at least 300 seconds.
- Add the application, destination, staging, state, and `/tmp` directories to `open_basedir`.

DSM does not allow paths below a user's `homes` directory in `open_basedir`; use a shared folder
for the destination instead.

PHP's `upload_max_filesize` and `post_max_size` do not limit these uploads because chunks are
streamed directly from the request body.

### 5. Create the Web Station portal

Create a Web Service Portal using the PHP profile from the previous step. Set its document root
to the deployed application's `public` directory.

Configure HTTPS and make its public URL match `base_url` exactly, including any path prefix.

### 6. Set permissions

Grant the Web Station user:

- read access to the deployed application;
- write access to the configured destination directory;
- write access to `var`, or to the configured `var_dir`;
- write access to the staging directory, when configured separately.

Do not make `config.php` or directories outside `public` web-accessible.

### 7. Check the web-server request limit

The web server must accept request bodies at least as large as `upload.max_chunk_size`. The
default browser chunk is 32 MiB and the default server limit is 64 MiB.

If the proxy or web server returns HTTP 413, lower `upload.chunk_size` or raise the web-server
body limit.

## Use

Open `base_url` in a browser and sign in with a DSM account. Add files by dragging them onto the
page or using the file picker, then start the upload.

- Pause or resume a file from its upload controls.
- Cancel removes an unfinished upload from staging.
- After a connection failure, the client retries from the server's recorded offset.
- After reloading the page, add the same local file again to resume it.

Existing destination files are never overwritten. A numeric suffix is added when a filename is
already in use.

## Storage and cleanup

Incomplete uploads are stored in `staging_dir`. By default this is `.upload-staging` inside the
destination directory. Keep staging and destination on the same volume so completion can use an
atomic move.

Expired uploads are cleaned up periodically when new uploads are created. For predictable
cleanup, add a daily DSM Task Scheduler job that runs as the Web Station user:

```text
<php-binary> <application-directory>/bin/cleanup.php
```

Choose the PHP binary matching the Web Station profile.

## Reverse proxies

Allow the HTTP methods `POST`, `PATCH`, `HEAD`, `DELETE`, and `OPTIONS`, along with tus and CSRF
request headers. Do not cache uploader responses.

Set `upload.chunk_size` below the proxy's per-request body limit and low enough that one chunk
can finish within the proxy's request timeout. The default 32 MiB chunk size is suitable for
Cloudflare plans with a 100 MB request limit.

## Development

Install dependencies and build the frontend:

```sh
composer install
cd frontend
npm ci
npm run build
cd ..
```

Run the test suites with Docker:

```sh
scripts/test.sh
scripts/e2e.sh 8.2
```

`scripts/test.sh` runs PHPUnit on every supported PHP version. The end-to-end suite starts a
local mock OIDC provider and tests authentication and real tus uploads.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
