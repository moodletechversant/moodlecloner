<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English language strings for Moodle Clone.
 *
 * @package    tool_moodleclone
 * @category   string
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actions'] = 'Actions';
$string['backupcomplete'] = 'Backup complete';
$string['backupstatus'] = 'Backup status';
$string['cancelbackup'] = 'Cancel backup';
$string['cancelrequested'] = 'Stop requested';
$string['cancelrequestsent'] = 'Backup job {$a} was asked to stop. It stops at the next check and removes its temporary files.';
$string['check:database_ok'] = 'Database: {$a}. The dump uses one consistent InnoDB snapshot.';
$string['check:database_unsupported'] = 'Database type {$a} is not supported yet. Moodle Clone can currently dump MySQL only.';
$string['check:database_untested'] = 'Database driver {$a} belongs to the MySQL family but has not been tested with Moodle Clone.';
$string['check:diskspace'] = 'Free space on the moodledata filesystem: {$a}';
$string['check:diskspace_enough'] = 'Disk space: about {$a->required} needed (conservative estimate), {$a->free} free.';
$string['check:diskspace_low'] = 'Not enough disk space: about {$a->required} needed (conservative estimate), only {$a->free} free on the moodledata filesystem.';
$string['check:diskspace_unknown'] = 'Free disk space could not be determined.';
$string['check:engines_bad'] = 'Unable to obtain a consistent database snapshot: these tables do not use InnoDB: {$a}';
$string['check:engines_ok'] = '{$a} tables, all InnoDB (required for a consistent snapshot).';
$string['check:extensions_missing'] = 'Required PHP extensions are missing: {$a}';
$string['check:extensions_ok'] = 'All required PHP extensions are loaded.';
$string['check:notreadable'] = 'The {$a} directory is missing or not readable.';
$string['check:pipeline_incomplete'] = 'Not implemented yet: {$a}';
$string['check:pipeline_ok'] = 'All backup stages are available.';
$string['check:readable'] = 'The {$a} directory is readable.';
$string['check:workspace_bad'] = 'The package directory moodledata/moodleclone cannot be created or written, or it is a symbolic link.';
$string['check:workspace_ok'] = 'The package directory in moodledata is writable.';
$string['cli:completed'] = 'Backup completed.';
$string['cli:environment'] = 'Environment check: {$a}';
$string['cli:estimate'] = 'Estimated contents: {$a->files} files, {$a->data} of files, database data {$a->database}.';
$string['cli:failed'] = 'Backup {$a->status}: {$a->error}';
$string['cli:locked'] = 'Another backup is running (job {$a}). Only one backup can run at a time.';
$string['cli:owner'] = 'Owner and mode:';
$string['cli:package'] = 'Package:';
$string['cli:runningqueued'] = 'Running queued backup job {$a} (with the options it was queued with).';
$string['cli:size'] = 'Size:';
$string['cli:starting'] = 'Starting backup (job {$a})...';
$string['cli:warnings'] = '{$a} entries were skipped with a warning; see the output above.';
$string['clihelp'] = 'Create a Moodle Clone package of this site.

Options:
-h, --help        Print this help.
-c, --check       Run the full pre-flight checks (including a size estimate
                  and disk space check) without creating anything.
                  Exit code 1 if any check fails.
    --execute     Create a clone package now. Runs a job queued from the
                  admin page if there is one, otherwise queues a new one.
    --no-code     Do not include the Moodle code tree.
    --no-dataroot Do not include moodledata.
    --no-database Do not include the database dump.
    --installer-protection=password|keyfile
                  How the standalone installer authorizes its user.
                  password (default): you set an installer password that the
                  installer asks for in the browser. keyfile: the installer
                  asks for a key it writes on the destination server.
    --installer-password-stdin
                  Read the installer password from one line of standard input
                  (for scripts). Without it you are prompted, twice, with no
                  echo. It is never a command line argument.

Run as the web server user, never as root. Ctrl-C cancels cleanly.

Examples:
$ sudo -u www-data /usr/bin/php admin/tool/moodleclone/cli/backup.php --check
$ sudo -u www-data /usr/bin/php admin/tool/moodleclone/cli/backup.php --execute
$ sudo -u www-data /usr/bin/php admin/tool/moodleclone/cli/backup.php --execute --installer-protection=keyfile';
$string['confirmdelete'] = 'Delete backup job {$a} and its package file? This cannot be undone.';
$string['createblocked'] = 'A backup cannot be started: {$a}';
$string['created'] = 'Requested';
$string['createinfo'] = 'The backup runs in the background (cron). Large sites should use the command line instead:';
$string['createpackage'] = 'Create Clone Package';
$string['cronsetup'] = 'To run Moodle cron every minute as the web server user, open that user\'s crontab and add the line below:';
$string['currentstep'] = 'Current step';
$string['delete'] = 'Delete';
$string['download'] = 'Download';
$string['downloadchecksum'] = 'SHA-256 file';
$string['error:backupnotavailable'] = 'Backups cannot run. Stages not implemented: {$a}';
$string['error:cancelled'] = 'The backup was cancelled.';
$string['error:cannotcreate'] = 'The working file {$a} cannot be created.';
$string['error:cannotcreatedir'] = 'The directory {$a} cannot be created or is not writable.';
$string['error:cannotreadfile'] = 'A file could not be read.';
$string['error:changedduringread'] = '{$a} grew beyond 4 GB while being archived.';
$string['error:dbcharset'] = 'Database dump failed: unexpected character set "{$a}".';
$string['error:dbcolumnmissing'] = 'Database dump failed: column {$a} was not returned (invisible or changed column).';
$string['error:dbconnect'] = 'Database dump failed: a second database connection could not be opened.';
$string['error:dbdriver'] = 'The database driver {$a} is not available.';
$string['error:dbinvisiblecolumn'] = 'Database dump failed: column {$a} is INVISIBLE, which Moodle Clone cannot dump.';
$string['error:dbnontransactional'] = 'Database dump failed: unable to obtain a consistent database snapshot because these tables do not use InnoDB: {$a}';
$string['error:dbschemachanged'] = 'Database dump failed: the database structure changed during the backup ({$a}). Do not upgrade or install plugins while a backup runs, then retry.';
$string['error:dbsnapshot'] = 'Database dump failed: unable to obtain a consistent database snapshot.';
$string['error:dbtablename'] = 'Database dump failed: table {$a} has a name Moodle Clone cannot dump.';
$string['error:dbunexpectedvalue'] = 'Database dump failed: a value of type {$a} did not have the expected form.';
$string['error:dbunsupported'] = 'Database dump failed: database family {$a} is not supported yet.';
$string['error:dbunsupportedtype'] = 'Database dump failed: column type not supported: {$a}';
$string['error:externalsymlink'] = 'Symbolic link {$a} points outside the source directory. The clone would be incomplete, so the backup stops. Replace the link with the real files (or, for the file pool, set $CFG->filedir to the real path) and retry.';
$string['error:interrupted'] = 'The backup was interrupted: the process running it stopped before finishing.';
$string['error:invalidpackage'] = 'Invalid clone package: {$a}';
$string['error:invalidpath'] = 'Invalid path ({$a}).';
$string['error:installernotfound'] = 'The installer template is missing from this plugin. Reinstall Moodle Clone.';
$string['error:installerpassword_long'] = 'The installer password is too long (at most 1024 bytes).';
$string['error:installerpassword_mismatch'] = 'The two installer password entries do not match.';
$string['error:installerpassword_reused'] = 'Choose an installer password that is different from your Moodle password: a package can end up on servers you do not control.';
$string['error:installerpassword_short'] = 'The installer password must have at least {$a} characters.';
$string['error:installerpassword_weak'] = 'The installer password is too repetitive. Use a longer passphrase with more different characters.';
$string['error:installerprotection'] = 'Choose how the installer will be protected.';
$string['error:invalidtransition'] = 'Invalid job status change: {$a}';
$string['error:jobactive'] = 'Backup job {$a} is already queued or running.';
$string['error:nojob'] = 'That backup job does not exist.';
$string['error:notdownloadable'] = 'This package is not available for download.';
$string['error:nothingselected'] = 'Nothing selected: include at least one of code, moodledata or database.';
$string['error:notregular'] = '{$a} is not a regular file.';
$string['error:packageexists'] = 'A package named {$a} already exists.';
$string['error:packagemissing'] = 'The package file is no longer on the server.';
$string['error:packageunreadable'] = 'The package file belongs to OS user "{$a->owner}" and cannot be read by the web server, which runs as "{$a->user}". It was created by a worker running as the wrong user. Delete this backup and create it again: cron and cli/backup.php now refuse to run as any user other than the web server\'s.';
$string['error:pathescape'] = 'Directory {$a} resolves outside the source directory (a parent was replaced by a symbolic link).';
$string['error:preflightfailed'] = 'Pre-flight checks failed: {$a}';
$string['error:publishfailed'] = 'The verified package could not be moved into place ({$a}).';
$string['error:queuelocked'] = 'Another administrator is starting a backup; try again in a moment.';
$string['error:replaced'] = '{$a} was replaced while being archived.';
$string['error:sourcemissing'] = 'Source directory {$a} does not exist.';
$string['error:statuslost'] = 'The job record could not be marked as completed, so the package was withdrawn.';
$string['error:unreadable'] = '{$a} cannot be read by the user running the backup. Fix its permissions and retry.';
$string['error:unsafename'] = 'The name of {$a} cannot be archived safely (control characters, backslashes or invalid UTF-8). Rename it and retry.';
$string['error:vanished'] = '{$a} was deleted while being archived.';
$string['error:workspacemode'] = 'The permissions of {$a} could not be restricted to its owner (mode 0700).';
$string['error:workspaceowner'] = 'The Moodle Clone directory {$a->dir} belongs to OS user "{$a->owner}", but this process runs as "{$a->user}". Backups must run as the web server user, and this directory must belong to that user.';
$string['error:workspacesymlink'] = 'The Moodle Clone directory {$a} is a symbolic link; refusing to use it.';
$string['error:writefailed'] = 'Writing {$a} failed. The disk may be full.';
$string['event:backupcompleted'] = 'Moodle Clone backup completed';
$string['event:backupfailed'] = 'Moodle Clone backup failed';
$string['event:backupstarted'] = 'Moodle Clone backup started';
$string['event:packagedeleted'] = 'Moodle Clone package deleted';
$string['event:packagedownloaded'] = 'Moodle Clone package downloaded';
$string['exclude:config'] = 'config.php contains the database password; the installer writes a new one.';
$string['exclude:configureddir'] = 'Directory that config.php assigns to temporary, cache or trash data.';
$string['exclude:customfiledir'] = 'Unused because $CFG->filedir points elsewhere; the real file pool is collected instead.';
$string['exclude:devtools'] = 'Development dependencies.';
$string['exclude:muc'] = 'Cache store configuration: may contain cache server credentials and is server specific.';
$string['exclude:nesteddata'] = 'Data directory nested inside the code directory; collected (or excluded) as moodledata instead.';
$string['exclude:ownoutput'] = 'Moodle Clone packages and working files.';
$string['exclude:quarantine'] = 'Files quarantined by the antivirus scanner.';
$string['exclude:regenerated'] = 'Cache data that Moodle regenerates automatically.';
$string['exclude:runtime'] = 'Runtime state of this server (locks, maintenance flag).';
$string['exclude:sessions'] = 'Session files: security sensitive and invalid on another site.';
$string['exclude:temporary'] = 'Temporary files.';
$string['exclude:trash'] = 'Deleted files waiting to be purged (content still referenced by the database snapshot is recovered into filedir).';
$string['exclude:unsafe'] = 'Unsafe path.';
$string['exclude:vcs'] = 'Version control metadata.';
$string['identity:mismatch'] = 'This process runs as OS user "{$a->current}", but backups must run as "{$a->expected}" ({$a->source}); otherwise the web server cannot read the package for download and cron cannot clean up. Run: {$a->command}';
$string['identity:ok'] = 'Running as OS user "{$a}", the same user as the web server.';
$string['identity:root'] = 'Backups must not run as root: the package would not be readable by the web server, and root must not create site files. Run as the web server user instead: {$a}';
$string['identity:source_owner'] = 'the owner of the Moodle Clone directory in moodledata';
$string['identity:source_web'] = 'the user the web server runs as';
$string['identity:unknown'] = 'The operating system user cannot be determined here (no posix extension, or an unsupported platform). Make sure cron and this script run as the web server user.';
$string['identity:unverified'] = 'Running as OS user "{$a}". The web server\'s user is not known yet (it is recorded when the Moodle Clone admin page is opened), so this cannot be verified.';
$string['info:databasename'] = 'Database name';
$string['info:databasetype'] = 'Database type';
$string['info:databaseversion'] = 'Database version';
$string['info:dataroot'] = 'Moodledata';
$string['info:dirroot'] = 'Moodle root';
$string['info:extensions'] = 'PHP extensions';
$string['info:freediskspace'] = 'Available disk space (moodledata)';
$string['info:moodleversion'] = 'Moodle version';
$string['info:operatingsystem'] = 'Operating system';
$string['info:phpversion'] = 'PHP version';
$string['info:tableprefix'] = 'Table prefix';
$string['info:wwwroot'] = 'Site URL (wwwroot)';
$string['installerdownload'] = 'Download installer.php';
$string['installerdownloadinfo'] = 'To restore a package on another server, copy installer.php and the package (with its .sha256 file) into an empty directory on the destination, then open installer.php in a browser there. It does not need Moodle installed first.';
$string['installerpassword'] = 'Installer password';
$string['installerpassword2'] = 'Repeat the installer password';
$string['installerpasswordinfo'] = 'At least {$a} characters. Only a salted, slow hash of it is stored in the package; the password itself is never saved, logged or shown again, so keep it somewhere safe. If you lose it, create the package again.';
$string['installerprotection'] = 'Installer protection';
$string['installerprotection_keyfile'] = 'Key file on the destination server (no password)';
$string['installerprotection_keyfile_help'] = 'The installer writes a key into its folder on the destination server and asks for it, so whoever installs must be able to read files on that server (SSH, hosting file manager or FTP). Choose this only if you do not want a password in the package.';
$string['installerprotection_password'] = 'Installer password (recommended)';
$string['installerprotection_password_help'] = 'The installer asks for this password in the browser before it does anything. Nothing has to be read on the destination server.';
$string['intro'] = 'Create a portable clone of this Moodle installation: code, persistent moodledata and the database in one verified package.';
$string['jobcancelled'] = 'Backup job {$a} cancelled.';
$string['jobdeleted'] = 'Backup job {$a} and its package were deleted.';
$string['jobqueued'] = 'Backup job {$a} queued. It will start when the scheduled task next runs.';
$string['jobqueuednoworker'] = 'Backup job {$a} queued, but no background worker is running, so it will not start yet. See the backup status below.';
$string['lastupdate'] = 'Last progress update';
$string['loaded'] = 'loaded';
$string['moodleclone:manage'] = 'Create and manage full-site clone packages';
$string['noactive'] = 'No backup is queued or running.';
$string['nojobs'] = 'No backups have been made yet.';
$string['notavailable'] = 'Not available';
$string['notloaded'] = 'not loaded';
$string['origin:cli'] = 'CLI';
$string['origin:web'] = 'Web';
$string['package'] = 'Package';
$string['packages'] = 'Backups and packages';
$string['pendingblocked'] = 'This backup will not start until the problem below is fixed. (It can also be run now from the command line, as the web server user, with cli/backup.php --execute.)';
$string['pendinginfo'] = 'Queued. The backup starts when the "Process Moodle Clone backups" scheduled task next runs (cron). Last cron start: {$a}.';
$string['pluginname'] = 'Moodle Clone';
$string['preflight'] = 'Pre-flight checks';
$string['privacy:metadata:jobs'] = 'Moodle Clone backup jobs. Note that clone packages contain a complete copy of the site, including all users\' data.';
$string['privacy:metadata:jobs:status'] = 'The status of the backup job.';
$string['privacy:metadata:jobs:timecreated'] = 'When the backup was requested.';
$string['privacy:metadata:jobs:userid'] = 'The user who requested the backup.';
$string['privacy:metadata:jobs:usermodified'] = 'The user who last changed the job record.';
$string['progress'] = 'Progress';
$string['protection'] = 'Installer protection';
$string['protection:keyfile'] = 'Key file';
$string['protection:password'] = 'Password';
$string['recovered'] = 'Backup job {$a} was interrupted (its worker stopped); it has been marked as failed and its temporary files removed.';
$string['requestedby'] = 'Requested by';
$string['sourceinformation'] = 'Source information';
$string['stage:archive'] = 'Creating archive';
$string['stage:checksums'] = 'Generating checksums';
$string['stage:code'] = 'Collecting Moodle files';
$string['stage:database'] = 'Dumping database';
$string['stage:dataroot'] = 'Collecting Moodledata';
$string['stage:finalising'] = 'Finalising package';
$string['stage:manifest'] = 'Generating manifest';
$string['stage:preflight'] = 'Running pre-flight checks';
$string['stage:starting'] = 'Starting backup';
$string['stagefailed'] = 'Stage "{$a->stage}" failed: {$a->message}';
$string['stalled'] = 'No progress has been reported for more than 10 minutes. If the worker process has stopped, the job will be marked as failed and its temporary files removed the next time the scheduled task or the CLI runs.';
$string['started'] = 'Started';
$string['status'] = 'Status';
$string['status:cancelled'] = 'Cancelled';
$string['status:completed'] = 'Completed';
$string['status:error'] = 'Error';
$string['status:failed'] = 'Failed';
$string['status:ok'] = 'OK';
$string['status:pending'] = 'Pending';
$string['status:running'] = 'Running';
$string['status:skipped'] = 'Skipped';
$string['status:warning'] = 'Warning';
$string['task:finished'] = 'Moodle Clone backup job {$a->id} finished with status {$a->status}.';
$string['task:locked'] = 'Another Moodle Clone backup is running.';
$string['task:processbackups'] = 'Process Moodle Clone backups';
$string['waitingforworker'] = 'Waiting for the background worker (Moodle cron).';
$string['warning:special'] = 'Skipped {$a}: sockets, pipes and device files cannot be archived.';
$string['warning:vanished'] = 'Skipped {$a}: it was deleted while the backup was running.';
$string['warning:view'] = 'Skipped database view {$a}: views are not table data and are not included in the dump.';
$string['worker:crondisabled'] = 'Cron is disabled in Site administration > Server > Tasks > Task processing, so queued backups will not start.';
$string['worker:cronfix'] = 'Open that user\'s crontab with "{$a->crontab}" and add: {$a->cronline}';
$string['worker:cronnotrunning'] = 'Moodle\'s cron is not running (last run: {$a->lastcron}), so queued backups will not start. Cron must run every minute as the web server user ({$a->user}).';
$string['worker:ok'] = 'Background worker: Moodle cron last ran {$a}; a queued backup starts at the next run.';
$string['worker:taskdisabled'] = 'The scheduled task "Process Moodle Clone backups" is disabled, so queued backups will not start. Enable it in Site administration > Server > Tasks > Scheduled tasks.';
