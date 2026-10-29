# Disposable Flarum 2 member qualification

Requires PHP 8.4 with PDO MySQL, GD, mbstring, XML/DOM, curl, fileinfo and zip;
Node 22+, Composer 2 and a loopback MariaDB instance. Use a disposable database
account that may create uniquely named test databases. The fixture refuses a
non-loopback host or an existing target database. It never imports production
identities or credentials.

Provide absolute directories for the five companion sources in
`F2_SOURCES_JSON`, keyed `navigation`, `messaging`, `dm`, `live`, `media`.
OAuth source is the calling checkout. Exact companion pins are in the CI
workflow. Set `PLAYWRIGHT_PACKAGE_JSON` to the Navigation `js/package.json`
after `npm ci` and `npx playwright install chromium` in that directory.

```sh
export F2_LAB_ROOT=/tmp/f2-member-qualification
export F2_DB_NAME=fr_f2_acceptance_local_unique
export F2_DB_HOST=127.0.0.1
export F2_DB_USER=root
export F2_BASE_URL=http://127.0.0.1:18767
node test/f2-member-harness.mjs
```

Pass the disposable database password through `F2_DB_PASSWORD`, not a command
log. For a local socket, use `F2_DB_SOCKET` and configure the PHP PDO default
socket in `F2_PHP_INI`. `PHP_BINARY` and `COMPOSER_BINARY` may select local
executables. The manifest and lock are copied into the new application;
external versions/references are asserted unchanged when path metadata is
refreshed. No Composer generated metadata is synthesized.

The fixture intentionally selects the MariaDB driver. Choosing MySQL grammar
against MariaDB can emit unsupported `CAST(... AS JSON)` expressions.
Core installation precedes enabling the FoF/custom extensions in separate
processes, so their migrations run with an initialized ORM. Formatter/cache
directories are created as part of the application skeleton.

Results and screenshots are under `$F2_LAB_ROOT/evidence`. `auth.json`,
`install.json`, configuration, database contents and server error logs stay
outside that directory and must not become CI artifacts. The harness stops
its HTTP child on exit and retains the disposable DB for inspection. It does
not stop or reconfigure the shared database server.

For the explicitly authorized physical Pixel, run
`test/f2-member-pixel.mjs` with `F2_AUTH_FILE`, `F2_EVIDENCE_DIR`,
`PLAYWRIGHT_PACKAGE_JSON`, `ANDROID_SERIAL` and its loopback CDP connection.
Use ADB reverse for the fixture HTTP port. Only the fixture's local tab and
cookies are used. Device rotation is restored after testing.
