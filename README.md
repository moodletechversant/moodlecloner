# Moodle Clone (`tool_moodleclone`)

Moodle Clone creates a portable, verified package of a whole Moodle site: the code, the persistent moodledata and the database. A standalone `installer.php` restores that package on another server, with no Moodle dependency before restoration. It works like WordPress Duplicator, but for Moodle.

**Status: Phase 3.1 (0.3.1, ALPHA).** The plugin creates and verifies complete clone packages, and the standalone installer restores them, migrates the URL and paths, and verifies the result. The whole migration can be done in a browser: the installer is authorized with a password chosen when the package is created, so nothing has to be read on the destination server (see "Phase 3.1"). A real package produced by the browser/cron workflow (96 MB, 510 database tables, 26,441 code files) has been restored end to end through Apache/mod_php into an isolated Moodle 4.1 + MySQL environment; see "What was verified" in the Phase 3 section below.

- Component: `tool_moodleclone`, in `admin/tool/moodleclone/`
- Adds one plugin table, `tool_moodleclone_jobs`. No core files or core tables are changed.
- **Tested on:** Moodle 4.1 (Build 20221128, version 2022112800), PHP 8.4.23 CLI, MySQL 8.0.46 through the `mysqli` driver, on Linux. See "What was verified" below. Nothing else has been tested. Officially, Moodle 4.1 supports PHP 7.4 to 8.1. The plugin code is written for PHP 7.4 or later, but it has only been run on 8.4.

---

## Contents

1. [Package format](#package-format)
2. [Architecture](#architecture)
3. [Package lifecycle](#package-lifecycle)
4. [Database dump and consistency model](#database-dump-and-consistency-model)
5. [Moodledata: what is and is not copied](#moodledata-what-is-and-is-not-copied)
6. [Disk space](#disk-space)
7. [Jobs, locking and the background task](#jobs-locking-and-the-background-task)
7a. [Background worker and OS user (Phase 2.1)](#background-worker-and-os-user-phase-21)
8. [Admin page](#admin-page)
9. [CLI](#cli)
10. [Security model](#security-model)
11. [Installation and upgrade](#installation-and-upgrade)
12. [Testing](#testing)
13. [Known limitations](#known-limitations)
14. [Phase 3: the standalone installer](#phase-3-the-standalone-installer)
15. [Phase 3.1: browser-only authorization](#phase-31-browser-only-authorization-of-the-installer)

---

## Package format

```
moodle-clone-YYYY-MM-DD-HHMMSS.zip        (UTC timestamp; ZIP64 when needed)
├── moodle/...             $CFG->dirroot, except config.php, .git/, node_modules/
├── database.sql.gz        gzipped SQL dump (MySQL), one consistent snapshot
├── moodledata/...         persistent part of $CFG->dataroot (see the policy below)
├── manifest.json          what the package is and where it came from (format 3; format 2 packages are still read)
└── checksums.sha256       SHA-256 of every regular file above

moodle-clone-YYYY-MM-DD-HHMMSS.zip.sha256 (next to the zip, not inside it: SHA-256 of the zip itself)
```

- **Order of entries:** code, then the database, then moodledata, then `manifest.json`, then `checksums.sha256`. Within a tree, entries are in sorted depth-first order, so the same tree always produces the same order.
- **Preserved in the archive:** Unix permissions, file types (regular file, directory, symlink) and modification times (a UTC extended-timestamp field). File names are UTF-8.
- **Symlinks** are stored as links, never followed. A link is stored only when its target stays inside the tree. Absolute targets inside the tree are rewritten as relative ones, so they survive a move to a different path.
- **Compression:** code and text are deflated. The file pool (`filedir`, which is mostly already-compressed media) and `database.sql.gz` are stored as they are.

### manifest.json (format 3)

Format 1 was the Phase 1 draft and was never written into a package. Format 2 is the format of the packages made by Phases 2 to 3. **Format 3** (Phase 3.1) adds one field, `installer_auth`, and is what new packages use; the plugin and the installer still read format 2 packages (their installer is authorized with the key file, see below). The format is closed: unknown keys are rejected, so adding a key requires a new format number.

| Field | Why it exists |
|---|---|
| `format`, `product` | Let the installer reject unrelated or unsupported files immediately. |
| `created` | Package creation time, in UTC ISO 8601. |
| `generator` | The `tool_moodleclone` version and release that wrote the package, so format bugs can be traced. |
| `moodle_version`, `moodle_release`, `moodle_branch` | Tell the installer which PHP and database versions the destination needs. |
| `php_version` | Lets the installer warn about a PHP mismatch. |
| `database_type`, `database_family`, `database_version` | Driver and SQL dialect. A MySQL dump cannot be restored into PostgreSQL. |
| `wwwroot`, `dirroot`, `dataroot` | The old URL and paths, which Phase 3 migrates (they also appear inside database values). |
| `table_prefix` | Needed to restore the dump. |
| `package_contents` | Which of `moodle`, `moodledata` and `database` are included. The CLI can leave components out. |
| `statistics` | Files, directories, symlinks and bytes per component, plus `recovered_from_trash`; table and row counts for the database. The verifier checks these against the archive. The installer can use them for its disk-space check. |
| `database_dump` | Dump format (`mysql` 1), `gzip` compression, the schema's default `charset` and `collation` (for creating the destination database), `consistency: snapshot`, and `max_statement_bytes` (the destination's `max_allowed_packet` must be at least this). |
| `moodledata_excluded` | What was deliberately left out of moodledata, so the installer can recreate those directories empty. |
| `installer_auth` (format 3) | How the installer authorizes its user: `{"mode": "keyfile"}` or `{"mode": "password", "kdf": "pbkdf2-sha256", "iterations": 600000, "salt": …, "verifier": …}`. The salt and verifier are public offline-verification data, never the password. |

These are never included: database credentials or host, `passwordsaltmain`, any other `config.php` value, session data, OAuth or API secrets, and the installer password itself. The validator also rejects any key, at any depth, that matches `pass|secret|token|api_key|private_key|salt|cookie|session|credential`. The one exemption is the top-level `installer_auth` object (it must carry a `salt`), which is checked against its own strict schema instead: only the listed keys, a known KDF, an iteration count between 100,000 and 2,000,000, and a salt and verifier that are canonical base64 of exactly 16 and 32 bytes.

### checksums.sha256

This file uses GNU coreutils format (`<sha256>  <path>`), one line per regular file entry, in archive order, including `manifest.json` and `database.sql.gz`.

- **What each digest covers:** it is computed from the exact bytes written into the archive, while they are written. It can therefore never disagree with the stored data, even if a source file changes during the backup.
- **What the file leaves out:**
  - Itself, since a file cannot list its own digest.
  - Directories.
  - Symlinks. Their stored data is the target path, and after extraction `sha256sum` would follow the link. The installer must validate link targets separately (see Phase 3).
  - The zip. Its digest is in the `.sha256` file next to it, which avoids a circular dependency.
- **Checking by hand:** after extraction, run `sha256sum -c checksums.sha256`. To check the transferred zip, run `sha256sum -c moodle-clone-….zip.sha256`.

---

## Architecture

```
admin/tool/moodleclone/
├── index.php, download.php, settings.php, version.php
├── cli/backup.php
├── db/            access.php, install.xml, upgrade.php, tasks.php
├── classes/
│   ├── event/            backup_started|completed|failed, package_downloaded|deleted
│   ├── output/           index_page (templatable)
│   ├── privacy/          provider (metadata + request provider)
│   ├── task/             process_backups (scheduled, every minute)
│   └── local/
│       ├── access.php                    capability checks
│       ├── environment/                  collector, snapshot (never reads the DB password)
│       ├── filesystem/                   path_validator, tree_walker, tree_entry
│       ├── package/                      layout, manifest(_validator), checksums, checksum_writer,
│       │                                 zip_writer, zip_entry_stream, package_verifier, workspace
│       ├── database/                     connection_factory, mysql_dumper, sink, gzip_sink
│       ├── backup/                       manager, backup_state, preflight, size_estimator,
│       │   │                             source_paths, content_policy, stage, options, exceptions
│       │   ├── progress/                 reporter, cli_reporter, multi_reporter, null_reporter
│       │   └── step/                     step, optional_step, tree_collector and the 7 steps
│       ├── job/                          job (persistent), queue, runner, job_reporter
│       └── log/                          logger, base_logger, redactor, mtrace_logger, memory_logger
├── templates/index_page.mustache
└── tests/
```

### Pipeline

```
Admin page ──(queue job)──► tool_moodleclone_jobs ◄──(progress)──┐
                                   │                             │
       scheduled task (cron) ──┐   │   ┌── cli/backup.php --execute
                               ▼   ▼   ▼
                      job\runner  (holds the backup lock)
                        1. recover interrupted jobs, remove orphaned files
                        2. claim the job (pending → running)
                        3. PREFLIGHT: size estimate (walks both trees), checks, disk space
                        4. backup\manager runs the steps; stops at the first failure
                             [1/7] code_collector      moodle/            ─┐ streamed straight into
                             [2/7] database_dumper     database.sql.gz     │ work/job-N/package.zip.part,
                             [3/7] dataroot_collector  moodledata/        ─┘ SHA-256 taken while writing
                             [4/7] manifest_generator  manifest.json
                             [5/7] checksum_generator  checksums.sha256
                             [6/7] package_writer      central directory, fsync
                             [7/7] finaliser           verify → hash → atomic rename → .sha256 file
                        5. mark completed (the only thing that makes it downloadable)
                        on any error: mark failed or cancelled, delete work dir and any unconfirmed package
```

The database is dumped **before** moodledata is collected, which differs from the order in the Phase 1 draft. This order is what makes the file pool consistent with the database (see the next section).

### Streaming zip writer

`local\package\zip_writer` is a custom writer. ZipArchive (libzip) collects every entry in memory and only writes in `close()`, with no progress reporting. The ZipStream library bundled with Moodle keeps one central-directory record per entry in PHP memory. Neither can handle a site with millions of files. This writer:

- reads each file once, in 1 MB chunks, computing CRC-32 and SHA-256 from the same bytes it writes;
- writes the local header first, then seeks back to patch the CRC and sizes, so no data descriptors are needed;
- spools central-directory records to a temporary file, so memory stays constant;
- uses ZIP64 automatically for entries larger than about 3.75 GB, for offsets above 4 GB, and for more than 65,535 entries.

Its output is verified by an independent reader: every package is read back with PHP's ZipArchive (libzip) before it is published. Tests also check both normal and forced-ZIP64 archives with ZipArchive, Info-ZIP `unzip -t` and Python's `zipfile`.

---

## Package lifecycle

| Where | State | Visible or downloadable? |
|---|---|---|
| `moodledata/moodleclone/work/job-N/package.zip.part` (+ spools) | being written | no: not in the packages directory, and the job is not completed |
| same | finished, verified, hashed | no |
| `moodledata/moodleclone/packages/moodle-clone-….zip` | renamed into place (same filesystem, so atomic); the job record already names it | no: the job is still `running` |
| `packages/….zip.sha256` | written to the work dir, then renamed into place | no |
| job status `completed` | published | **yes**, through `download.php` for authorised users |

If anything fails at any point, the runner deletes the working directory and, if a package was already renamed into place, deletes that too (the job record names it for exactly this purpose). A package with a missing or unverified part is never shown as complete.

Verification (`package_verifier`) before publishing checks:
- libzip opens the archive with consistency checks;
- the entry count matches what was written;
- every name is allowed by the layout;
- `manifest.json` is valid and its contents and statistics match the archive;
- `checksums.sha256` lists every regular file exactly once, in archive order;
- every file's content, read back and decompressed, matches its SHA-256;
- `database.sql.gz` decompresses completely and ends with the dump's completion marker.

This reads every byte once more, which costs time on large sites but proves the package.

Packages stay until an administrator deletes them. Use **Delete** on the admin page; it asks for confirmation and removes the zip and its `.sha256` file.

---

## Database dump and consistency model

MySQL (the `mysql` family) only in this release. PostgreSQL is not implemented, and preflight refuses it. `mariadb` and `auroramysql` are accepted with a warning because they are untested.

**How it works**

- **Separate connection.** The dump runs on a second connection (`connection_factory`) with the same driver settings as `$DB`, minus the read-replica option. Reads from a lagging replica would break consistency. It has to be a separate connection, because the dump holds one long transaction while job progress keeps being committed through `$DB`.
- **Snapshot.** The dumper sets `time_zone = '+00:00'` and `REPEATABLE READ`, then runs `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY`. This is the technique `mysqldump --single-transaction` uses.
- **No external tools.** The dump is pure PHP through Moodle's DML layer. `mysqldump` is not needed, and no password is ever put on a command line.
- **Structure.** The dumper reads every table's `SHOW CREATE TABLE` right after the snapshot starts. That includes indexes, constraints, charset, collation, row format and the `AUTO_INCREMENT` counter.
- **Rows.** Rows are streamed with `moodle_database::export_table_recordset()`, which is unbuffered on MySQL, so memory does not depend on table size. They are written as multi-row `INSERT`s of about 1 MB (at most 1,000 rows each) and gzipped on the fly straight into the zip. No temporary copy of the database ever reaches the disk.
- **Encoding.** No database value is ever placed in SQL as text:
  - strings, dates and JSON are written as `_utf8mb4 X'…'` hex literals, using the connection's result charset;
  - binary, BIT and spatial values are written as `X'…'`;
  - numbers are written only if they match a strict numeric pattern.

  The dump is therefore immune to quoting, escaping, `sql_mode` and charset tricks in the data. Identifiers are backtick-quoted, with any backticks doubled.
- **Scope.** Only tables with `$CFG->prefix` are dumped.
- **Sessions.** `mdl_sessions` is dumped as structure only: session ids are security-sensitive and meaningless on another site. This matches the exclusion of `moodledata/sessions`.
- **Views** are reported as warnings and not dumped. Moodle does not create any.
- **Generated columns** are left out of `INSERT`s because they are recomputed.
- **Refused data.** INVISIBLE columns and unknown column types make the dump fail rather than lose data.
- **Output framing.** The dump ends with `-- Moodle Clone dump completed: N tables, M rows`. The verifier (and later the installer) uses this line to detect truncation.

**What is guaranteed**

- All tables reflect **one point in time**. InnoDB MVCC serves every read from the same snapshot, so writes the site commits during the dump are invisible to it. A test checks exactly this.
- This holds only when:
  - **every table is InnoDB.** Preflight and the dumper refuse non-transactional tables (for example MyISAM) with "Unable to obtain a consistent database snapshot".
  - **no DDL changes the tables during the dump.** MySQL DDL is not transactional. Reading every definition at the start also takes MySQL metadata locks, so from then on `ALTER`, `DROP`, `RENAME` and `TRUNCATE` on those tables **wait until the dump ends** instead of breaking it. At the end, the table list and every definition are compared with those captured at the start. A change in the short window before the locks, or a new table, fails the backup with a clear message.

**Operational consequence**

For the length of the dump, a plugin install or upgrade, or a scheduled task that truncates a table, waits. In Moodle 4.1, `$DB->delete_records()` without conditions issues `TRUNCATE`: `context_temp` during context rebuilding, `tag_correlation` in the tag cron, `tool_monitor_events`, and `sessions` when all sessions are killed. Queries that queue behind such a waiting statement on the same table wait too. So:

- Do not upgrade or install plugins while a backup runs.
- On large sites, run backups at quiet times. The dump phase is usually much shorter than the file collection.
- A long-running transaction also makes InnoDB keep undo history on the database server for its duration.

**Database and files together**

The database snapshot is taken first, and moodledata is walked afterwards:

- A file uploaded after the snapshot ends up in the package without a database record. That is harmless.
- A file deleted after the snapshot is still referenced by the dumped database. Moodle moves deleted content into `trashdir` (by `rename`). The moodledata step takes files from the trash **whose inode change time is after the snapshot (with a 60 s margin), whose name is a valid content hash and whose SHA-1 matches it**, and stores them at their normal `moodledata/filedir/…` location. `trashdir` itself is never packaged.

This is best effort. It relies on `rename` updating the inode change time, which Linux filesystems do. It also misses one edge case: content deleted, re-added and deleted again during the backup, where Moodle keeps only the older trash copy.

---

## Moodledata: what is and is not copied

The policy is `content_policy::for_site_dataroot()`, which is the Phase 1 list plus directories that `config.php` relocates. **Anything not listed is included**, because losing persistent data that some plugin keeps in an unexpected place is worse than a larger package.

| Path | Decision | Reason |
|---|---|---|
| `filedir/` | include (stored, not deflated) | All uploaded content. When `$CFG->filedir` points elsewhere, that directory is collected as `moodledata/filedir/` and `dataroot/filedir` is ignored. |
| `lang/`, `.htaccess`, `models/`, `geoip/`, `repository/`, unknown dirs | include | Persistent data, or unknown and therefore kept. |
| `cache/`, `localcache/` | exclude | Regenerated by Moodle, and specific to this server. |
| `temp/` | exclude | Temporary files. |
| `sessions/` | exclude | Security-sensitive, and invalid on another site. |
| `trashdir/` | exclude | Deleted content. Content the dumped database still references is recovered into `filedir/` (see above). |
| `lock/`, `climaintenance.html` | exclude | Runtime state. The maintenance flag would put the clone into maintenance mode. |
| `muc/` | exclude | Cache store configuration. It may hold Redis or Memcached credentials. |
| `antivirus_quarantine/` | exclude | Infected files. |
| `moodleclone/` | exclude | This plugin's own working files and packages. |
| `$CFG->tempdir`, `cachedir`, `localcachedir`, `backuptempdir`, `trashdir` wherever they sit inside dataroot | exclude | These are the same kinds of runtime data, relocated by `config.php`. **New in Phase 2**, reason `exclude:configureddir`. |

For the code tree, `content_policy::for_site_code()` excludes `config.php` (only at the root), `.git/` and `node_modules/`, plus any data directory nested inside dirroot (**new in Phase 2**, `exclude:nesteddata`). Everything else is copied: the real tree, not a list of known directories.

**Which entries fail the backup and which are skipped**

- **External symlinks fail the backup.** Skipping one would silently produce an incomplete clone. For a file pool on another disk, set `$CFG->filedir` to its real path instead of using a symlink.
- **Other things that fail the backup:**
  - unreadable files or directories;
  - names with control characters, backslashes or invalid UTF-8;
  - a file swapped for another inode between listing and reading.
- **Skipped with a warning:**
  - files deleted while the backup runs (normal on a live site);
  - sockets, FIFOs and device files.

---

## Disk space

Working files and the package are both on the dataroot filesystem, under `moodledata/moodleclone/`. The final rename is on the same filesystem, so publishing needs no extra space. Before anything is written, every job and `--check` walk both trees exactly as the collectors will, and compute:

```
required = 1.10 × (code bytes + moodledata bytes + database data_length)
         + 1 KB × number of entries
         + 256 MB reserve
```

This is conservative:
- It assumes no compression. Code usually deflates to about 30%.
- It treats the compressed dump as no larger than the tables' `DATA_LENGTH`. Hex encoding doubles the text, then gzip shrinks it well below that.
- The 1 KB per entry covers local headers, central-directory records (written twice: spool and archive) and checksum lines (spool and archive).
- There is no staging copy of files and no temporary copy of the database.

If free space is below `required`, the backup fails in preflight. The admin page's quick check only reports free space, because walking the whole tree on every page view would be too slow. `DATA_LENGTH` comes from MySQL statistics and is approximate.

---

## Jobs, locking and the background task

**Job statuses**

`tool_moodleclone_jobs` holds these columns:
- `userid`, `origin` (web or cli), `status`;
- `currentstep`, overall `progress`, and `steps` (JSON per stage: status, progress, error);
- `options`;
- `filename` (base name only, never a path), `packagesize`, `packagehash`;
- `errorstep`, `errormessage` (redacted), `cancelrequested`;
- `timestarted`, `timefinished`, `timecreated`, `timemodified` (which doubles as the heartbeat), `usermodified`.

```
pending ──► running ──► completed
   │           ├──────► failed
   │           └──────► cancelled
   ├──► cancelled
   └──► failed
```

Completed, failed and cancelled are final. Status changes use a conditional `UPDATE … WHERE status = <expected>`, so a cancel click and the worker can never overwrite each other. Progress writes touch only progress columns, and only while the job is running.

**Locks** use Moodle's lock API (`\core\lock\lock_config`, type `tool_moodleclone`):

- `backup` is held for the whole run by the scheduled task or the CLI, so only one backup ever runs per site. With the default MySQL, PostgreSQL and file lock factories it is released automatically if the process dies. With `db_record_lock_factory`, a dead worker's lock lasts up to 2 days.
- `queue` is taken briefly while queuing. Together with the "at most one pending or running job" rule, two administrators clicking at once cannot queue two backups.

**Scheduled task**

`\tool_moodleclone\task\process_backups` runs every minute. Unless there is work, it returns at once. With the backup lock held it:

1. **Recovers interrupted jobs.** Any job still marked `running` cannot have a live worker, so it becomes `failed` ("interrupted"). The stage where it stopped is kept, and its working directory and any unconfirmed package are deleted.
2. **Removes orphans.** Working directories and package files that no completed job owns are deleted.
3. **Runs the oldest pending job.** Failed jobs are recorded, not retried.

In the worker, a shutdown handler also marks the job failed and cleans up after fatal errors such as memory or time limits. Nothing can run after `SIGKILL`, but step 1 of the next run handles that case. Temporary multi-gigabyte directories therefore do not outlive the next task run.

**Cancelling**

A pending job is cancelled at once. A running job gets `cancelrequested`, and checks it every few seconds and before every step. It then stops, cleans up and ends as `cancelled`. In the CLI, Ctrl-C (SIGINT) or SIGTERM does the same when the pcntl extension is available.

---

## Background worker and OS user (Phase 2.1)

**Production mechanism: Moodle cron.** A backup requested in the browser is queued; the `process_backups` scheduled task, run by Moodle cron every minute, executes it. No backup runs inside a web request, and no browser polling performs work (the page only reloads to show progress). `cli/backup.php --execute` remains for manual and maintenance runs and uses the same job, lock and pipeline.

```
Browser: Create Clone Package ──► job "pending" ──► cron (every minute, as www-data)
                                                        │  process_backups → runner
                                                        ▼
                                  running → completed ──► Download (web server reads its own 0600 file)
```

This requires a cron entry for the **web server's OS user** (Moodle's own requirement for cron):

```bash
sudo crontab -u www-data -e
* * * * * /usr/bin/php /var/www/html/school/admin/cli/cron.php >/dev/null 2>&1
```

The admin page shows the exact line for the site (dirroot, `$CFG->pathtophp` or `/usr/bin/php`, web user). It reports, both in the checks list and on a pending job, when queued backups will **not** start and why:

| Condition | Detection |
|---|---|
| Cron not running | `tool_task/lastcronstart` older than twice `$CFG->expectedcronfrequency` (200 s default), or never |
| Cron disabled | `cron_enabled` = 0 (Site administration › Server › Tasks › Task processing) |
| Task disabled | the scheduled task's disabled flag |
| Cron runs as the wrong OS user | recorded by the task itself when it refuses to run (shown for 15 min, cleared when a correct run happens) |

After queuing, the page says "Backup job N queued" only if a worker is ready. Otherwise it warns that the job will not start yet.

**One OS identity for all workers.** Packages are private to the OS user that writes them: mode 0600, inside 0700 directories. The web server can only serve a package it owns, so:

- The admin page records the OS user of the web process (`webuid`/`webuser` in the plugin config) whenever it is opened or a backup is queued. Only a web request can know this. Before it has been recorded, the owner of `moodledata/moodleclone` (or of dataroot) is used, unless that owner is root.
- The scheduled task and `cli/backup.php` refuse to run as root or as any other user. The job stays **pending** (nothing is written), the problem is recorded for the admin page, and the message names the fix, e.g. `sudo -u www-data php …/cli/backup.php --execute`. The runner checks again before claiming a job.
- An existing workspace directory owned by another user is refused. One with broader permissions (e.g. after `chmod 777`) is tightened back to 0700 before use. The plugin never makes packages group- or world-readable.
- If a package is nevertheless unreadable (created by an older version, or by hand), the admin page and `download.php` say which OS user owns it and which user the web server runs as, and the job can be deleted and redone.

Where the posix extension is missing (e.g. Windows), identities cannot be checked. `--check` warns about this.

**Graceful stop:** the runner honours Moodle's core CLI graceful-exit API (`\core\local\cli\shutdown`). Ctrl-C or SIGTERM on `cron.php`, `scheduled_task.php` or `cli/backup.php` cancels a running backup at its next check, cleans up and records it as cancelled.

---

## Admin page

**Site administration → Plugins → Admin tools → Moodle Clone** requires `tool/moodleclone:manage`.

- **Backup status:**
  - when a job is running: its current step, a progress bar, per-step status, start time and last update;
  - a warning if there has been no progress for 10 minutes;
  - when a job is pending: "Waiting for the background worker (Moodle cron)". If no worker will pick it up, a red box shows the reason (cron not running, disabled, task disabled, wrong OS user) and, for cron, the exact `crontab -u <webuser>` command and line;
  - a **Cancel backup** button;
  - the page refreshes itself every 10 s while a job is pending or running (`$PAGE->set_periodic_refresh_delay()`, no JavaScript).
- **Create Clone Package** only queues a job (POST with sesskey). The backup itself never runs inside a web request. The button is disabled while a job is active or when the quick checks report errors. Above it, **Installer protection** chooses how the standalone installer will authorize its user (see "Phase 3.1"): an **installer password** (the default; typed twice, at least 12 characters, not the administrator's own Moodle password) or the **key file** alternative.
- **Backups and packages** lists recent jobs with status, requester, errors (with the failing step), file name, size and SHA-256. Its **Download** link (and a **SHA-256 file** link for the `.sha256` file next to the package) appears only when the package is really servable, using the same check as `download.php`. Each package shows how its installer is protected. Below the list, **Download installer.php** serves the standalone installer. Otherwise the reason is shown (for example "belongs to OS user vishnu … web server runs as www-data"). **Delete** asks for confirmation.
- The **pre-flight checks** list includes a **background worker** row.
- **Pre-flight checks** and **Source information** are also shown, as in Phase 1.

---

## CLI

```bash
sudo -u www-data php admin/tool/moodleclone/cli/backup.php --help
sudo -u www-data php admin/tool/moodleclone/cli/backup.php --check
sudo -u www-data php admin/tool/moodleclone/cli/backup.php --execute [--no-code] [--no-dataroot] [--no-database] \
    [--installer-protection=password|keyfile] [--installer-password-stdin]
```

- `--check` runs the full preflight and prints `Environment check: PASS/FAIL`. That includes the tree walk, database engine check, disk-space estimate, workspace check, and a warning when you are not the owner of moodledata. The exit code is 1 on failure. It writes nothing.
- `--execute` runs a backup now, in-process, without cron. It runs the job queued from the admin page if there is one, otherwise it queues a new one. It uses the same lock, job record and pipeline as the task, so the admin page shows its progress.
- `--installer-protection` defaults to `password`: unless a job queued from the admin page is run (it has its own choice), `--execute` asks for the installer password, twice, with no echo. `--installer-password-stdin` reads it from one line of standard input instead, for scripts. It is never a command line argument, which would end up in the process list and the shell history. `--installer-protection=keyfile` is the explicit alternative.
- It must run as the **web server's OS user**. `--execute` refuses root and any other user before touching anything. `--check` reports the identity as its own check line (OK, ERROR, or a WARNING when it cannot be verified).
- After a successful run it prints the package's owner and mode (for example `www-data, 0600`).

Example output:

```
== Moodle Clone ==
[OK     ] All required PHP extensions are loaded.
…
[OK     ] Disk space: about 584.2 MB needed (conservative estimate), 21.6 GB free.
[OK     ] All backup stages are available.
Estimated contents: 26420 files, 255.7 MB of files, database data 11.6 MB.

Environment check: PASS

Starting backup (job 3)...

[1/7] Collecting Moodle files         100%
[2/7] Dumping database                100%
[3/7] Collecting Moodledata           100%
[4/7] Generating manifest             100%
[5/7] Generating checksums            100%
[6/7] Creating archive                100%
[7/7] Finalising package              100%

Backup completed.

Package:
  /var/moodledata/moodleclone/packages/moodle-clone-2026-09-24-134501.zip
Size:
  212.4 MB (222712345 bytes)
SHA-256:
  1103f2fc…
```

---

## Security model

A package is the whole site: every user's personal data, password hashes, and every secret stored in the database (for example SMTP passwords, OAuth client secrets and web service tokens). Anyone holding a package effectively controls the source site.

- **Access**
  - Capability `tool/moodleclone:manage`, at system context, with `RISK_CONFIG | RISK_PERSONAL | RISK_DATALOSS`, granted to no archetype. Only site administrators have it by default.
  - `index.php` checks it through `admin_externalpage_setup` and `local\access`.
  - `download.php` requires login, the capability and a sesskey.
  - The CLI is trusted as a shell user on the server (the standard Moodle CLI model) and refuses root.
- **CSRF:** create, cancel and delete are POST actions with `require_sesskey()`. The delete also has a confirmation step. Download links carry the sesskey.
- **Download:** the only input is a job id. The file served is always `dataroot/moodleclone/packages/<filename>`. `<filename>` comes from a *completed* job record, and is validated both by the persistent and against `moodle-clone-YYYY-MM-DD-HHMMSS.zip` before `path_validator::resolve_within()`. No path can come from a request, and temporary files, which live in another directory, can never be served. The file is sent with Moodle's `send_file()` after `\core\session\manager::write_close()`.
- **Files on disk:**
  - `moodledata/moodleclone` and its subdirectories are mode 0700 and packages are 0600, all owned by the web server's OS user;
  - directories owned by another user are refused, and broader modes are tightened before use;
  - nothing is ever created through a symlink;
  - workers running as root or as any user other than the web server are refused (see "Background worker and OS user").
- **Paths:**
  - every source path is the `realpath()` of a `$CFG` value;
  - the tree walker never follows symlinks;
  - it checks, for every directory it enters, that `realpath` equals the expected path, so no parent directory can be a symlink;
  - it checks each opened file's inode against the one it listed;
  - archive entry names are validated before writing (no `..`, absolute paths, backslashes or control characters);
  - deletion (`workspace::remove_tree`) uses `lstat`, never follows links, and refuses paths outside the workspace.
- **SQL:** all Moodle queries use DML placeholders. The only SQL built from variables uses whitelisted column names (`job::change_status`) or backtick-quoted identifiers from `information_schema`. Dump values are hex-encoded (see above).
- **No shell and no `eval`:** nothing is executed through a shell, and no code is generated.
- **Credentials:**
  - `$CFG->dbpass` is read only in `connection_factory`, to open the dump connection;
  - it is never stored, logged or written to the manifest, job, events or checksums;
  - every log line and stored error goes through `redactor`, which masks the values of `dbpass`, `passwordsaltmain` and `cronremotepassword`, `password=`/`token:`/`api_key=` style pairs, MySQL `user 'x'@'host'`, and `scheme://user:pass@` credentials;
  - the database username is never shown on the admin page (a test confirms it only appears inside label words such as "dataroot").
- **Events:** they contain the job id, size, hash and failing stage only. They never contain error text, paths or configuration.
- **XSS:** all page output goes through Mustache escaping. A test confirms that an error message containing HTML is rendered escaped.

### Security review (Phase 2)

| Area | Finding |
|---|---|
| Path traversal | Mitigated. There are no request paths. Entry names are validated when written (writer) and when read (verifier/layout). The download path is built only from a validated package name. |
| Symlink traversal | Mitigated. Links are never followed. External targets (checked lexically and via `realpath`, including chains) fail the backup. Symlinked directories are archived as links. The workspace refuses to be a symlink. |
| Arbitrary file read | Mitigated for requests: download serves only completed packages from a fixed directory. **Residual TOCTOU:** a local attacker who can write inside dirroot or dataroot, and who swaps a directory for a symlink in the microseconds between the walker's `realpath` check and a file open, could get one outside file (readable by the web server user) into a package. Per-file inode checks shrink the window, but PHP has no `O_NOFOLLOW`/`openat`. The attacker would need write access to the site's own directories. |
| Arbitrary file write | Mitigated. All writes go to `moodledata/moodleclone/work/job-<int>/` or `packages/` with generated names. Archives are opened with `x` mode, so nothing is ever overwritten. The publish target must not exist. **Fixed in 2.1:** Phase 2 wrote into these directories if they were merely writable. It now refuses directories owned by another user and restores mode 0700, so a world-writable workspace (e.g. after `chmod 777`) can no longer be used by other local users to plant files or links. |
| Cross-user ownership | **Fixed in 2.1:** workers run only as the web server's OS user, so packages are always readable by the web server and by nobody else. |
| Command injection | Not applicable: no shell or process execution anywhere. |
| SQL injection | Mitigated: placeholders everywhere, whitelisted columns, hex-encoded dump values, quoted identifiers. Injection strings round-trip exactly (verified against MySQL). |
| Credential leakage | Mitigated for everything the plugin writes itself. **By design, the package contains the database's secrets.** Protect packages accordingly; encryption is Phase 4. |
| Log leakage | Mitigated by the redactor on every logger and stored error. Warnings contain relative paths only. |
| Race conditions / TOCTOU | Creation: queue lock plus the single-active-job rule. Execution: the backup lock. Status: conditional updates. Files: inode checks and atomic rename. Remaining window: see arbitrary file read above. |
| Partial archive exposure | Mitigated. The archive is built in the work directory, verified, then renamed. It is downloadable only once the job is completed. Any failure deletes both the work directory and an unconfirmed published file. |
| Concurrent backups | Prevented by the backup lock. **Caveat:** with `db_record_lock_factory` (the fallback when the database lock factory is unavailable), a backup running longer than 2 days could see its lock expire. |
| Permission bypass | Every web entry point checks the capability. The scheduled task only runs jobs queued by authorised users. The check happens when the job is queued, not re-checked when it runs. |

---

## Installation and upgrade

1. Place the plugin in `admin/tool/moodleclone/`.
2. Visit **Site administration → Notifications**, or run `sudo -u www-data php admin/cli/upgrade.php`. Upgrading from Phase 1 (2026092400) creates `tool_moodleclone_jobs` through `db/upgrade.php`. New installs use `db/install.xml`. Both produce the same schema (checked with `admin/cli/check_database_schema.php`).
3. **Set up cron for the web server user** (required for backups started in the browser). See "Background worker and OS user". On this server, Moodle's cron had not run since October 2024. The first cron run after enabling it will also run every overdue core task, so it may take several minutes.
4. Open the Moodle Clone page once, so that it records the web server's OS user.

Updating the plugin code requires the usual upgrade, or at least a cache purge, because Moodle caches its class map.

On this server, the CLI upgrade stopped at Moodle's environment check because the **CLI** PHP has `max_input_vars` below 5000. That setting only affects web forms. It was run once with `php -d max_input_vars=5000 admin/cli/upgrade.php --non-interactive`, and no configuration file was changed.

---

## Testing

### PHPUnit

Setup: add `$CFG->phpunit_prefix` and `$CFG->phpunit_dataroot` to `config.php`, then run `composer install` and `php admin/tool/phpunit/cli/init.php`.

```bash
vendor/bin/phpunit --testsuite tool_moodleclone_testsuite
```

| Test file | Covers | Needs |
|---|---|---|
| `local/package/manifest_test` | format 2 building, JSON round trip, 27 invalid cases (secret keys, statistics/dump consistency, format 1/3) | – |
| `local/package/layout_test`, `checksums_test`, `checksum_writer_test` | names, entry rules, checksum generation, parsing and changed-file detection | – |
| `local/package/zip_writer_test` | round trip with libzip (normal and forced ZIP64), modes, symlinks, Unicode, no-overwrite, abort, unsafe names, vanished/replaced files | – |
| `local/package/package_verifier_test` | valid package; missing or extra checksum lines, wrong order, wrong statistics, dump without marker or truncated, tampered data, truncated zip, wrong entry count | – |
| `local/package/workspace_test` | 0700 dirs, package-name validation, `remove_tree` not following links, symlinked workspace refused, orphan cleanup | – |
| `local/filesystem/path_validator_test`, `tree_walker_test` | traversal, link-target resolution, internal/external/chained symlinks, pruning, unsafe names, special files | – |
| `local/backup/content_policy_test`, `size_estimator_test`, `preflight_test`, `manager_test` | policies incl. `$CFG`-relocated dirs, estimate formula, disk/engine/driver/workspace checks, step order, skipping, failure, cancellation | – |
| `local/backup/step/code_collector_test`, `dataroot_collector_test` | `config.php` excluded, nested files, external symlinks rejected, persistent/excluded/unknown dirs, custom filedir, trash recovery | – |
| `local/log/redactor_test`, `local/environment/collector_test`, `access_test` | redaction, snapshot, capability | DB (access) |
| `local/database/mysql_dumper_test` | real dump → restore round trip (quotes, NUL, emoji, binary, NULL, decimals, indexes), snapshot isolation, MyISAM refused, DDL blocked or detected, new table detected, sessions structure only, encoding and injection | MySQL |
| `local/job/job_test`, `queue_test` | creation, the full transition matrix, conditional updates, concurrent-job prevention, cancel, delete | DB |
| `local/job/runner_test` | end-to-end run on fixture trees, failure cleanup, preflight failure, cancellation, recovery, shutdown handler, exclusive lock, progress weights | DB (+MySQL for two cases) |
| `event/events_test`, `privacy/provider_test`, `task/process_backups_test` | events, privacy API, task lock and recovery, and wrong-OS-user refusal (job stays pending, problem recorded, then cleared) | DB |
| **`integration_test`** | **the production path:** `queue::create_from_web()` (same entry point as the page) → job pending → the scheduled task run through Moodle's cron sequence (cron lock factory, task lock, `cron_run_inner_scheduled_task()`) → completed without any CLI → `package_download::resolve()` (same check as `download.php`) → file owned by the web (test) process, 0600 in 0700 directories, readable, verified. Also: cron as the wrong user leaves the job pending and shows the reason. | DB + MySQL |
| `local/environment/os_identity_test`, `local/job/worker_status_test`, `local/package/package_download_test` | identity detection and mismatch/root refusal, cron diagnostics (not running, disabled, task disabled, recorded problem), download resolution (not completed, missing, unreadable with owner named, symlink refused) | DB (parts) |

### What was verified during development (on this server)

- **PHPUnit itself was not run.** The checkout has no `vendor/`, and setting up the PHPUnit test database was not authorised. Instead, the 227 test cases that need no database writes were run through a small assertion shim that implements the PHPUnit methods used. All pass. That covers every test file above except the ones marked DB or MySQL.
- **The complete pipeline ran end to end on fixture trees**, with a real snapshot transaction on MySQL 8.0.46 but no table data. The resulting packages were checked with `unzip -t`, `sha256sum -c` (both the package list and the `.sha256` file next to the zip), and full extraction. `config.php` and excluded directories were absent, symlinks and permissions were preserved, and trash recovery worked. A fixture with an external symlink failed with nothing published.
- **Dump value encoding** was round-tripped through MySQL 8.0.46 using `SELECT <literal>` only. The dumper was run on the plugin's own empty table to check the structure output.
- **The plugin was installed** (upgrade from Phase 1) and `cli/backup.php --check` was run against the real site: PASS in 1.6 s, 510 InnoDB tables, about 584 MB estimated requirement.
- **The admin page template** was rendered through Moodle's renderer for idle, running and failed states.
- **Not done, because not authorised:** a real backup of this site, importing a dump into a scratch database, and running the DB-dependent PHPUnit tests.

Phase 2.1, on this server:
- As the wrong OS user (`vishnu`, while the web server runs as `www-data`):
  - `--check` reports both the identity and the workspace-ownership errors;
  - `--execute` refuses before touching anything;
  - running the task through Moodle's own `admin/cli/scheduled_task.php` refuses, records the problem and leaves the job table untouched.
- The live admin page, opened in a browser, recorded the web identity as `www-data` (uid 33).
- The pending-job panel was rendered with the real diagnosis ("cron not running since 9 October 2024", with the `crontab -u www-data` line).
- 232 non-database test cases pass in the shim, with 0 failures. Three identity cases were skipped: two need a fresh PHPUnit database (the live site already has `www-data` recorded) and one writes config.
- The **positive** cron path (a backup as `www-data`) could not be run here: this session cannot use `sudo`. `integration_test` covers it under PHPUnit.

---

## Known limitations

- MySQL only. The installer restores a MySQL dump into MySQL or MariaDB; it does not support other database engines.
- **Tested only** on Moodle 4.1, PHP 8.1 (web) / 8.4 (CLI), MySQL 8.0.46 and Linux. Other versions, operating systems and database drivers are untested.
- `ROW_FORMAT=COMPRESSED` and other table options are copied as they are. The destination needs a compatible MySQL (`innodb_file_per_table`, a similar version).
- A single `INSERT` can exceed 1 MB when one row is larger. `database_dump.max_statement_bytes` records the largest statement for the installer.
- Views, triggers, routines, events and invisible columns are not dumped. Moodle creates none of them.
- Schema changes and `TRUNCATE` wait while the dump runs (see the consistency model).
- Trash recovery is best effort (it depends on inode change time; one edge case is described above).
- The residual symlink TOCTOU window is described in the security review.
- There is no resume: an interrupted backup starts again from scratch.
- Packages are not encrypted and are never deleted automatically.
- Queued backups need Moodle cron running as the web server user. The plugin detects and explains a missing or misconfigured cron, but cannot install the OS crontab itself.
- OS identity checks need the posix extension (not available on Windows).
- Progress is estimated from byte counts. The database part uses MySQL's approximate row statistics.
- Custom cache-store (MUC) configuration is not carried over.

---

## Phase 3: the standalone installer

`installer/installer.php` inside this plugin is a template. Download a copy from the admin page ("Download installer.php", next to the packages list) and copy it, together with a `moodle-clone-…zip` package and its `.sha256`, into an **empty** directory on the destination server. The copy shipped inside the plugin refuses to run in place: at the top of the file it checks whether `../version.php` belongs to `tool_moodleclone` and, if so, returns HTTP 403 instead of doing anything — that is what stops someone accidentally running the installer against the live source site.

The installer is a single namespaced file (`MoodleCloneInstaller`), has no Composer dependencies and no `require` of any Moodle file until after the database has been restored and a fresh `config.php` written. It works in time-limited slices (20 seconds each) across ordinary POST requests, driven by an auto-submitting form (with a `<noscript>` fallback), so it survives normal PHP and proxy timeouts even for a large site.

### Flow

1. **Access.** Before it does anything, the installer authorizes its user, in the browser, the way the package says: with the installer password chosen when the package was created, or (for the explicit alternative, and for packages made before Phase 3.1) with a key written to a file on the destination server. See "Phase 3.1".
2. **Package verification.** Only files matching `moodle-clone-YYYY-MM-DD-HHMMSS.zip` next to the installer are offered. The zip is opened and every entry name is re-checked against the layout rules independently of what the source plugin already validated (never trust the archive): traversal, absolute paths, backslashes, symlinks, unexpected top-level entries, `config.php`, and the installer's own reserved filenames are all rejected. `checksums.sha256` is required to list every code/data file in archive order with no gaps, and `manifest.json` is validated with the same rules as the backup side (formats 2 and 3) (format, product, `dbtype` allow-list, no forbidden secret keys). The `.sha256` sidecar is verified against the whole zip.
3. **Environment checks.** PHP version against Moodle 4.1's supported range, required extensions, `max_input_vars`, whether the destination directory is empty, disk space from the manifest's `statistics`.
4. **Destination settings.** Site URL, dataroot (absolute, outside the docroot and the installer's own directory, new-or-empty, parent writable), and MySQL host/name/user/password. The table prefix is **not** user-editable — it is fixed to the one recorded in the manifest, so the installer never has to guess which tables belong to it. Settings are validated for real: a live DB connection, MySQL ≥ 5.7, no tables already using that prefix, `max_allowed_packet`, and a probe `CREATE`/`INSERT`/`ALTER`/`DROP` against a throwaway table.
5. **Confirm, then restore**, task by task, each its own request/slice:
   - **prepare** — create the dataroot (`02770`) and a private work directory inside it (`0700`, never inside the web root), write a temporary protective `.htaccess` in the destination that denies everything except `installer.php` (Apache only — see Limitations), extract `checksums.sha256`.
   - **extract** — in archive order, each file's SHA-256 checked as it streams out (write to `*.mci-part`, verify, then rename), so a truncated or tampered entry is caught before it is used. Code files land `0644`/dirs `0755`, data files `0660`/dirs `02770`. The package's own `moodle/.htaccess` is saved aside rather than applied immediately (our temporary one stays in force until the restore finishes). `database.sql.gz` is inflated to the work directory with the completion marker checked before anything is executed against MySQL.
   - **database** — the SQL is replayed through a quote-aware reader (resumable by byte offset across slices) and a statement allow-list (`sql_guard`): only the dump's `SET` lines, `DROP TABLE IF EXISTS`, `CREATE TABLE` (InnoDB only; `DATA/INDEX DIRECTORY`, `CONNECTION`, `TABLESPACE`, `ENCRYPTION` are rejected), and `INSERT` with the expected per-row literal shape are accepted — nothing else in the dump can execute. Table and row counts are checked against the manifest afterwards, plus a non-empty config table, and the temporary SQL file is deleted.
   - **config** — a brand new `config.php` is generated with `var_export`, never copied from the package (the package never contains one). Written atomically, mode `0640`.
   - **moodle_paths / moodle_urls / moodle_verify** — only now does the installer `require` anything from the restored Moodle. Each runs in its own request with `NO_MOODLE_COOKIES`, `$_SERVER` emulated from the destination's wwwroot, and a registered shutdown handler so a PHP fatal during bootstrap is still reported back cleanly instead of hanging. `moodle_paths` checks the restored version against the manifest, calls `moodle_needs_upgrading()`, and migrates `dirroot`/`dataroot` wherever they appear inside `config`/`config_plugins` via `set_config()`. `moodle_urls` walks the database row by row with `sql_like()` for the old `wwwroot`, skipping log tables, hash/token columns and a short list of tables handled separately (`config`, `sessions`, `files`, …), tries both the raw and JSON-escaped form of the old URL, and is **serialized-data aware**: it unserializes a candidate value (with `allowed_classes => false`), rewrites string members in place, and re-serializes with the correct updated length — there is no blind `REPLACE()` across the database, which would silently corrupt any serialized PHP array or object containing the old URL. Blocks' own `*_global_db_replace` callbacks are also invoked. `moodle_verify` purges all caches and checks: table count against the manifest, the site record, at least one non-deleted site administrator, whether an upgrade is still needed, a 500-row sample of `{files}` (`filesize > 0`) against the restored `filedir` on disk, moodledata writability, and whether `noemailever` took effect.
   - **finish** — remove the temporary protective `.htaccess` and restore the package's own one, delete the work directory, do a best-effort HTTP check of `wwwroot/login/index.php`, then delete the key file, the run lock, the package (if the admin asked for that), and finally `installer.php` itself. If any of those can't be deleted (permissions), it writes a lock file and says so plainly instead of pretending everything is clean.
6. **Retry / Reset on failure.** A failed step shows the real error and two choices: *Retry this step* (re-runs exactly that task; nothing before it is touched) or *Reset the destination* (removes every file the installer extracted, the moodledata directory it created, and every table under the package's prefix from the destination database, then returns to the settings step). Reset was verified directly against the filesystem and a `SHOW TABLES` check, not just by trusting its own success message.

### What was verified

- **Sandbox** (synthetic fixture, PHP 8.1 built-in server, driven like a browser with real cookies/CSRF/forms): full wizard through prepare/extract/database/config succeeds; the Moodle-bootstrap steps fail cleanly (as expected, since the fixture has no real Moodle code) without ever reporting a partial install as complete; Retry re-runs the failed step and reports the real error; Reset removes the extracted files, the moodledata directory and the restored tables, confirmed by listing the filesystem and querying the database afterwards, not by trusting the installer's own report.
- **Real environment**: a real package produced by the browser → cron → `www-data` workflow (`moodle-clone-2026-09-24-113503.zip`, 96 MB compressed, 26,441 code files, 510 database tables, 42,285+ rows) was restored through real Apache + mod_php as `www-data`, into a fresh MySQL database and fresh moodledata, on an isolated destination (`/var/www/html/clone-test`, separate from the source site). The result answers `/login/index.php` with HTTP 200 and the source site's real name. The installer's own `moodle_verify` check flagged one item ("File pool: 339 of 500 sampled files missing"); this was root-caused by directly comparing the same 500-row sample against the **source** site's own filedir, which is missing the identical 339 files — a pre-existing gap in the source site's data, faithfully reproduced (source and destination `filedir` have identical file counts and byte size), not something the backup or restore lost. Installer self-deletion, key/lock cleanup, and the untouched original source package were all confirmed on disk afterwards.
- A real state-tracking bug was found and fixed during this verification: retrying a failed Moodle-bootstrap step didn't clear an internal "already started" flag, so the first Retry click reported "stopped without a result" instead of actually retrying. Fixed and re-verified before the real-environment run.

### Limitations specific to the installer

- **Apache only** for the protective `.htaccess` during restore. On Nginx (which ignores `.htaccess` by default) the destination directory is not access-restricted while the restore runs; put it behind auth or a firewall rule for that window instead.
- The table prefix cannot be changed during restore; the destination always gets the prefix recorded in the manifest.
- Symlinks inside a package are rejected outright, not restored (see the backup side's known limitations for why they cannot be safely captured either).
- A single file larger than one 20-second slice can allow is extracted across several requests using the resumable `.mci-part`/checksum mechanism, but a database statement that cannot fit in one slice (a huge single row) is retried whole from its byte offset next slice, not split.
- If `moodle_needs_upgrading()` is still true after restore, the installer reports it but does not run `admin/cli/upgrade.php` itself; the administrator runs the upgrade manually (link/command given on the verification page).
- URL/path migration only rewrites values it can recognise (raw string match, JSON-escaped match, or unserializable PHP data); a value transformed in some other encoding will not be found.

---

## Phase 3.1: browser-only authorization of the installer

### What the Phase 3 key did, and why it had to change

Phase 3 authorized the installer with a key it wrote into its own folder (`moodleclone-installer-key.php`, mode 0600, behind a `<?php exit; ?>` line, 128 random bits). The administrator had to read that file on the destination server (`sudo cat …`) and type it in.

- **What it protected against.** The installer is a web page in a folder that anyone can reach. Between the moment it is copied there and the moment the administrator uses it, a stranger who found the URL could otherwise point it at any MySQL host with any credentials (a port and credential scanner run from the server), write a package's files into the web root, or restore over a site. The key proves the visitor can read the server's files, which a stranger cannot.
- **What state it kept.** The key file, plus the PHP session (`$_SESSION['mci']`: a CSRF token, an `auth` boolean, the wizard's progress and the settings) and a 2-second `sleep()` after a wrong key. There was no persistent rate limiting and nothing on the server that could revoke a session.
- **Why it had to change.** It made a browser-to-browser migration impossible: the administrator needs SSH or a file manager on the destination. Simply removing it would have left the installer open to whoever got there first, so it was replaced by something the administrator can do in a browser and a stranger cannot.

### The design

The administrator chooses how the installer will be authorized **when the package is created**:

| Mode | What the installer asks for | What must be readable on the destination server |
|---|---|---|
| **password** (the default in the UI and the CLI) | The installer password chosen on the source site | Nothing |
| **keyfile** (the explicit, documented alternative) | A key the installer writes into its folder | A file, through SSH, FTP or a hosting file manager |

Packages made before Phase 3.1 (format 2) behave as **keyfile**. There is deliberately no third "no authorization" mode: an installer that anyone who finds the URL can run is exactly what the key was there to prevent.

**The password.**

- It is typed twice in the create form (or read from standard input or a hidden prompt by the CLI; never a command line argument). At least 12 characters, at least 6 different ones, at most 1024 bytes. The web form also refuses the current administrator's own Moodle password, so it is not reused.
- It is hashed **in the request that receives it**, with **PBKDF2-HMAC-SHA256, 600,000 iterations and a random 16-byte salt**, and only the derived 32-byte verifier is kept. The plaintext exists only in memory during that request. It is never written to the package, the job record, the manifest, a log, an event, the session or any configuration. (PBKDF2 was chosen over Argon2 or bcrypt because it is part of core PHP, so the destination can always verify it; a package whose password cannot be checked would lock the administrator out.) Where it can, the plugin marks the password parameters `#[\SensitiveParameter]` so they are hidden from stack traces on PHP 8.2+.
- The verifier goes into `manifest.json` (`installer_auth`). The job record holds it only while the job waits (the cron worker runs later, in another process); **the worker removes it from the record before the database dump starts** (the job table is itself part of the dump), and cancelling a job removes it too. Afterwards the record keeps only the mode.
- The installer verifies offline, against the package itself: no connection to the source site and no Moodle. Verification is constant time (`hash_equals`) and costs about 0.9 s on the development server, which also slows guessing.

**Before anyone is authorized** the installer shows a password (or key) field and nothing else: no package name, site name, version, server path or error detail. It reads exactly one bounded entry of the package (`manifest.json`) to learn the mode. A package that cannot be read (for example an upload still in progress), or whose `installer_auth` is missing or malformed, authorizes **nobody**: it never falls back to a weaker way in. A wrong answer looks the same whatever the reason. If a Moodle `config.php` already exists in the folder, the installer says so and does nothing else.

**Rate limiting** is on the server, in one file (`moodleclone-installer-auth.php`, mode 0600, behind an exit guard), updated under an exclusive lock:

- An attempt is **recorded before it is checked** and given back only if it was right, so parallel requests cannot all pass while the counter still reads zero.
- Per client (a hash of the remote address): 4 free failures, then a lock-out of 30 s that doubles with every further failure up to 15 minutes. Failures are forgotten after an hour without any.
- Globally: more than 30 failed attempts in 15 minutes locks everyone out for 5 minutes, which limits guessing spread over many addresses (at the price that an attacker can make the administrator wait; deleting the file over SSH or FTP resets it).
- A locked client is refused (HTTP 429) **before** any password is looked at, so a correct password is refused too during a lock-out and the lock cannot be used as an oracle.

**The installer session.** After a correct password the installer regenerates the PHP session id, then issues a random 256-bit token. The PHP session holds only that token. Whether it is authorized, for **which packages**, and until when is recorded on the server, in the same file, as the SHA-256 of the token with an idle expiry of 30 minutes (renewed by each request), an absolute expiry of 12 hours, and a hash of the browser's User-Agent. So a forged session cookie, a tampered token, a token from another browser, or a PHP session edited to say "authorized" are all worthless; and deleting the file revokes every session at once. The PHP session runs with strict mode, cookie-only ids, `HttpOnly`, `SameSite=Strict` and (over HTTPS) `Secure`. If the folder is not served over HTTPS the login page warns that the password can be read on the network.

**Finishing invalidates the authorization.** The last step deletes the state file (or, if it cannot be deleted, empties it), then the key file, the run lock, the package if asked, and `installer.php` itself; if any of them cannot be removed it writes a lock file that makes the installer refuse to do anything. A leftover copy of `installer.php` in a finished site therefore has no authorized sessions, sees `config.php` and refuses. (It also does not lay a `.htaccess` over the site: see below.)

**The package is not a secret the installer can hide.** The verifier sits inside the package, and the package sits next to the installer under a predictable name. On Apache the installer writes its protective `.htaccess` (deny everything except `installer.php`) on the very first request, before anyone is authenticated, but only into a folder that holds nothing besides the installer and its package; on other web servers `.htaccess` does nothing, so keep the package out of the web root or block direct access to it. The password protects the **installer**, not the package: anyone who can download the package has the whole site, and could attack the verifier offline (which is why it is slow, salted and requires 12 characters).

### Also fixed in this phase

- A clone inherited the source's Moodle Clone job records, including a backup that was "running" when the dump was taken, which stayed running forever on the clone and disabled its Create button. The installer now closes queued and running jobs as failed on the restored copy. (On a site restored earlier, the plugin's own worker does the same at its next run.)
- The admin page now also serves the `.sha256` file next to a package, so the installer's checksum verification does not depend on file access on the source server.
- The installer no longer shows the absolute path of its key file to visitors who have not authenticated.

### Tests

- `tests/local/package/installer_auth_test.php`, `manifest_test.php`, `local/backup/manager_test.php`: the verifier and the format (correct and incorrect passwords; the description never holds the password; each verifier has its own salt; hostile descriptions such as absurd iteration counts are rejected; the manifest and every entry of a pipeline-built package are free of the password).
- `tests/installer_authorization_test.php`: the standalone installer's half: reading the verifier from a package (fail closed on anything unexpected), the rate limiter (back-off, cap, per-client and global limits, lock-out refuses a correct answer, attempts counted before they are checked, forgetting), forged and expired sessions, revocation when finishing, and damaged state files.
- `tests/installer_security_test.php`: the Phase 3 security tests, kept (paths, hostile packages, the SQL allow-list and reader, URL rewriting, the manifest check).
- `tests/local/job/queue_test.php`, `runner_test.php`: the verifier is removed from the job before the dump and when a job is cancelled. These need Moodle's PHPUnit database.
- `tests/e2e/installer_e2e.py`: a real Chrome (Playwright) driving the whole migration between two web sites (see below).

### What was verified (Phase 3.1)

Run on this server (Apache with mod_php 8.1, PHP 8.4 CLI, MySQL 8.0.46, headless Chrome 150 through Playwright).

- **Browser end to end, 99 of 99 checks**, `tests/e2e/installer_e2e.py password keyfile both legacy`, between two isolated sites: a SOURCE (the restored copy of the school site, upgraded to this plugin version, logged in as its administrator) and an empty DESTINATION with its own vhost, database and moodledata. Only running the site's cron (the scheduled task, as the web server user) and copying the downloaded files between the two folders happened outside the browser.
  - *Password package:* the create form's rules (mismatch, too short, too repetitive, the administrator's own Moodle password, nothing typed: each refused with no job queued); the job waits holding a verifier and never the password; the worker removes the verifier from the job record; the package, the `.sha256` file and `installer.php` are downloaded through the browser (checksums match what the page shows); the package holds a format 3 manifest with a PBKDF2 verifier and the random test password appears in **no entry of the package, decompressed, including the database dump**; the installer's first page shows nothing about the package or the server, and asks for no key; the package and the installer's state file cannot be fetched over HTTP; five wrong passwords, then HTTP 429 on the sixth; the correct password refused during the lock-out; the correct password accepted after it; a made-up session id, a forged POST, posted "auth" fields and a stolen cookie used from a different browser all get nothing, and none of them disturbs the real session; a second browser authorizes independently; a complete restore of the 96 MB package (83 s) with every verification check passing; the installer, its state file and its lock are gone afterwards, the site serves its login page, and its `config.php` is the destination's own; with a leftover `installer.php` put back, neither authorized browser gets anything and the finished site is not locked down; the password is in no PHP session file, no Apache log, no destination file and no Moodle log record.
  - *Key-file package* made through the UI: only a key is asked for, the key file is mode 0600, a wrong key is refused, the key authorizes.
  - *A folder holding both kinds:* the page offers both fields without naming either package; the password unlocks only the password package, the key only the key-file package; a wrong password names nothing.
  - *The real format 2 package* (96 MB, 510 tables) with the key file: the same key flow as Phase 3, then a complete restore through the browser (93 s).
- **Unit level, 288 tests, 0 failures**, run with a PHPUnit stand-in because this server has no PHPUnit installation: the verifier and manifest tests, the installer authorization tests (31), the Phase 3 security tests (38, ported into the repository), and the earlier database-free plugin tests. The original 50-check scratch suite for the installer also still passes against the modified installer.
- **Not run:** the tests that need Moodle's PHPUnit database (`local/job/queue_test.php` and `runner_test.php`, including the two new ones for the verifier's lifecycle; the older ones were never run either). The behaviour they cover was instead checked in the browser run against the source site's real database.
- **Bugs the browser run found**, fixed before it passed: a refused session check (a stolen cookie from another browser) removed the token from the shared PHP session and so logged the real user out; a leftover `installer.php` in a finished site would have written a deny-all `.htaccess` over it (now only in a clean folder); an inherited "running" backup job disabled the Create button on every clone (now closed by the installer); an unreadable `.sha256` file was reported as "not valid".

### Limitations

- The installer's state (the wizard's progress) still lives in the PHP session, which the web server may garbage-collect after about 24 minutes without a request. An abandoned installation therefore has to be cleaned up by hand.
- Session tokens are bound to the User-Agent only, not to the network address, so a cookie stolen together with the User-Agent still works until it expires. Use HTTPS.
- PBKDF2 is not memory-hard. The protection against offline attack on a stolen package rests on the length rule and on the iteration count.
- A global lock-out lets an attacker who can reach the installer keep the administrator waiting.
- No password strength meter or breached-password check; only the length and variety rules above.
- Revocation when an installation finishes is exercised at the unit level (the state file is removed and every token dies) and in the browser (the file is gone, and a put-back installer refuses because `config.php` exists); the browser cannot show a valid token being refused *only* because the file was removed, since the `config.php` check answers first.
