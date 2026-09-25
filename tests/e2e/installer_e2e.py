#!/usr/bin/env python3
"""
Browser-level end-to-end test of the Moodle Clone installer authorization (Phase 3.1).

It drives a real Chrome (Playwright) through the whole browser-to-browser migration, with no shell steps in the middle
except the two a browser cannot do: running the site's cron (what the crontab does) and the file transfer between the
servers (what an administrator does with FTP or a file manager). Everything else, including creating the package,
downloading it and the installer, authorizing the installer and restoring the site, happens in the browser.

It needs two web sites on this machine that are safe to break: a SOURCE Moodle with tool_moodleclone installed and an
empty DESTINATION directory with its own vhost, empty database and (absent) moodledata directory. It is a
development-environment test: it reads and removes files with sudo (password in the environment variable MCI_SUDO).

Usage:  MCI_SUDO=... python3 installer_e2e.py CONFIG.json [scenario ...]
Scenarios: password (default), keyfile, both, legacy (run keyfile and both in the same invocation as password). Configuration keys are documented in CONFIG.example below.
"""
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import time
import zipfile
import gzip
import secrets

from playwright.sync_api import sync_playwright

CONFIG_EXAMPLE = {
    "chrome": "/usr/bin/google-chrome",
    "downloads": "/tmp/mci-e2e",
    "source": {"url": "http://source.example", "admin_user": "admin", "admin_password_file": "/path", "dir": "/var/www/source",
               "dataroot": "/var/www/source-data", "web_user": "www-data",
               "db": {"host": "localhost", "name": "x", "user": "x", "pass": "x"}},
    "dest": {"url": "http://dest.example", "dir": "/var/www/dest", "dataroot": "/var/www/dest-data",
             "db": {"host": "localhost", "name": "y", "user": "y", "pass": "y"}},
    "legacy_package": "/path/to/a/format-2/moodle-clone-YYYY-MM-DD-HHMMSS.zip",
}

results = []


def check(condition, label):
    results.append((bool(condition), label))
    print(('  PASS  ' if condition else '  FAIL  ') + label, flush=True)
    return bool(condition)


def sudo(command, user=None):
    """Run a shell command with sudo (password from MCI_SUDO); returns stdout."""
    args = ['sudo', '-S', '-p', '']
    if user:
        args += ['-u', user]
    args += ['bash', '-c', command]
    done = subprocess.run(args, input=os.environ['MCI_SUDO'] + '\n', capture_output=True, text=True, timeout=1800)
    return done.stdout


def mysql(db, query):
    done = subprocess.run(['mysql', '-N', '-B', '-h127.0.0.1', '-u' + db['user'], '-p' + db['pass'], db['name'], '-e', query],
                          capture_output=True, text=True, timeout=120)
    return done.stdout.strip()


def h1(page):
    return page.locator('h1').first.inner_text()


def body_text(page):
    return page.inner_text('body')


def wait_h1(page, texts, timeout_ms=1500000):
    """Wait until the page's heading is one of the texts (or an installer error page); returns the heading."""
    accepted = list(texts) + ['Cannot continue', 'The installation stopped']
    page.wait_for_function('(texts) => { const h = document.querySelector("h1"); return h && texts.includes(h.innerText.trim()); }',
                           arg=accepted, timeout=timeout_ms, polling=500)
    heading = h1(page)
    if heading not in texts:
        print('    unexpected page "%s": %s' % (heading, re.sub(r'\s+', ' ', body_text(page))[:400]), flush=True)
    return heading


class Run:
    def __init__(self, cfg, playwright):
        self.cfg = cfg
        self.src = cfg['source']
        self.dst = cfg['dest']
        self.dl = cfg['downloads']
        os.makedirs(self.dl, exist_ok=True)
        self.browser = playwright.chromium.launch(executable_path=cfg['chrome'], headless=True, args=['--no-sandbox'])
        # Random per run and never written to a file: the source site's code tree (which goes into the package) contains
        # this very script, so a constant here would legitimately show up in the package.
        self.password = 'Blue-Heron %s ñandú!' % secrets.token_hex(6)
        self.wrongs = ['not the password %d' % i for i in range(1, 7)]
        self.artifacts = {}

    def context(self, **kw):
        return self.browser.new_context(accept_downloads=True, viewport={'width': 1280, 'height': 900}, **kw)

    def shot(self, page, name):
        page.screenshot(path=os.path.join(self.dl, name + '.png'), full_page=True)

    # ---------------------------------------------------------------- source

    def source_login(self, page):
        page.goto(self.src['url'] + '/login/index.php')
        page.fill('#username', self.src['admin_user'])
        page.fill('#password', open(self.src['admin_password_file']).read().strip())
        page.click('#loginbtn')
        page.wait_for_load_state('networkidle')
        return check('login' not in page.url and 'Log in' not in page.title(), 'source: administrator logged in through the login form')

    def create_form(self, page, protection='password', pw1='', pw2=None):
        page.goto(self.src['url'] + '/admin/tool/moodleclone/index.php')
        if protection == 'password':
            page.check('#mc-protect-password')
            page.fill('#mc-installer-password', pw1)
            page.fill('#mc-installer-password2', pw1 if pw2 is None else pw2)
        else:
            page.check('#mc-protect-keyfile')
        page.click('form:has(input[name=action][value=create]) button[type=submit]')
        page.wait_for_load_state('networkidle')
        return page.inner_text('#user-notifications') if page.locator('#user-notifications').count() else body_text(page)

    def run_worker(self):
        """What the crontab does: run the plugin's scheduled task as the web server user."""
        cmd = 'cd %s && php -d max_input_vars=5000 admin/cli/scheduled_task.php --execute=\'\\tool_moodleclone\\task\\process_backups\' 2>&1' % self.src['dir']
        out = sudo(cmd, self.src['web_user'])
        return out

    def create_package(self, page, protection, password=''):
        """Create a package through the UI, run the worker, wait for it. Returns the completed row's locator."""
        before = self.completed_rows(page)
        note = self.create_form(page, protection, password)
        check('queued' in note.lower() or 'waiting' in note.lower(), 'source: the browser reports the backup as queued (%s)' % re.sub(r'\s+', ' ', note)[:90])
        job = mysql(self.src['db'], "SELECT id, status FROM mdl_tool_moodleclone_jobs ORDER BY id DESC LIMIT 1")
        check('pending' in job, 'source: the job is pending until cron runs it (%s)' % job.replace('\t', ' '))
        return job

    def completed_rows(self, page):
        page.goto(self.src['url'] + '/admin/tool/moodleclone/index.php')
        return page.locator('tr:has(.badge:has-text("Completed"))').count()

    # ------------------------------------------------------------ scenarios

    def scenario_password(self):
        print('\n== Scenario: password-protected package, browser to browser ==', flush=True)
        src, dst, pw = self.src, self.dst, self.password
        admin_pw = open(src['admin_password_file']).read().strip()
        ctx = self.context()
        page = ctx.new_page()
        if not self.source_login(page):
            return

        # --- The create form and its rules.
        page.goto(src['url'] + '/admin/tool/moodleclone/index.php')
        check(page.is_checked('#mc-protect-password'), 'source: the secure default (an installer password) is preselected')
        check(page.locator('#mc-installer-password').get_attribute('type') == 'password', 'source: the password field is masked')
        check(page.locator('#mc-installer-password').get_attribute('autocomplete') == 'new-password', 'source: browsers are told not to autofill it')
        self.shot(page, '01-source-create-form')
        cases = [('mismatched entries', pw, pw + 'x', 'do not match'), ('too short', 'short', 'short', 'at least 12'),
                 ('too repetitive', 'aaaaaaaaaaaaaaaa', 'aaaaaaaaaaaaaaaa', 'repetitive'),
                 ('the Moodle password reused', admin_pw, admin_pw, 'different from your Moodle password'),
                 ('nothing typed', '', '', 'at least 12')]
        for label, a, b, expect in cases:
            note = self.create_form(page, 'password', a, b)
            check(expect in note, 'source: refused (%s)' % label)
            check(mysql(src['db'], "SELECT COUNT(*) FROM mdl_tool_moodleclone_jobs WHERE status IN ('pending','running')") == '0',
                  'source: no job was queued for (%s)' % label)

        # --- A real one.
        self.create_package(page, 'password', pw)
        verifier = mysql(src['db'], "SELECT options FROM mdl_tool_moodleclone_jobs ORDER BY id DESC LIMIT 1")
        check('"mode":"password"' in verifier.replace(' ', '') and 'verifier' in verifier, 'source: the waiting job holds a verifier')
        check(pw not in verifier and 'Heron' not in verifier, 'source: ... and never the password')
        started = time.time()
        out = self.run_worker()
        print('    worker finished in %.0fs' % (time.time() - started), flush=True)
        page.goto(src['url'] + '/admin/tool/moodleclone/index.php')
        row = page.locator('tr:has(.badge:has-text("Completed"))').first
        check(row.count() == 1, 'source: the package completed (worker output tail: %s)' % re.sub(r'\s+', ' ', out.strip())[-120:])
        check('Password' in row.inner_text() and 'Installer protection' in row.inner_text(), 'source: the package list says it is password protected')
        after = mysql(src['db'], "SELECT options FROM mdl_tool_moodleclone_jobs ORDER BY id DESC LIMIT 1")
        check('verifier' not in after and 'salt' not in after and '"mode":"password"' in after.replace(' ', ''),
              'source: the verifier was removed from the job record when the worker started (only the mode remains)')
        self.shot(page, '02-source-package-list')

        # --- Downloads through the browser.
        shown = re.search(r'SHA-256:\s*([0-9a-f]{64})', row.inner_text()).group(1)
        with page.expect_download(timeout=600000) as d:
            row.locator('a:has-text("Download")').first.click()
        package_path = os.path.join(self.dl, d.value.suggested_filename)
        d.value.save_as(package_path)
        with page.expect_download() as d2:
            row.locator('a:has-text("SHA-256 file")').click()
        sidecar_path = os.path.join(self.dl, d2.value.suggested_filename)
        d2.value.save_as(sidecar_path)
        with page.expect_download() as d3:
            page.locator('a:has-text("Download installer.php")').click()
        installer_path = os.path.join(self.dl, 'installer.php')
        d3.value.save_as(installer_path)
        name = os.path.basename(package_path)
        actual = hashlib.sha256(open(package_path, 'rb').read()).hexdigest()
        check(actual == shown, 'browser: the downloaded package matches the SHA-256 the page shows')
        check(open(sidecar_path).read().split()[0] == shown and name + '.sha256' == os.path.basename(sidecar_path),
              'browser: the downloaded .sha256 file matches too')
        check(os.path.getsize(installer_path) > 50000, 'browser: installer.php downloaded (%d bytes)' % os.path.getsize(installer_path))

        # --- The package holds a verifier and not the password.
        found = self.scan_package_for(package_path, pw)
        check(found == [], 'package: the password appears nowhere in the package (every entry, decompressed): %s' % found)
        manifest = json.loads(zipfile.ZipFile(package_path).read('manifest.json'))
        auth = manifest.get('installer_auth', {})
        check(manifest['format'] == 3 and auth.get('mode') == 'password' and auth.get('kdf') == 'pbkdf2-sha256' and auth.get('iterations', 0) >= 600000,
              'package: manifest format 3 with a salted PBKDF2 verifier (%s, %s iterations)' % (auth.get('kdf'), auth.get('iterations')))
        ctx.close()

        self.artifacts['password'] = (package_path, sidecar_path)
        self.artifacts['installer'] = installer_path

        # --- The destination.
        self.clean_dest()
        for path in (package_path, sidecar_path, installer_path):
            shutil.copy(path, dst['dir'])
        self.destination(name, pw, keep_package=True)

    def scan_package_for(self, package_path, needle):
        raw = needle.encode()
        found = []
        with zipfile.ZipFile(package_path) as z:
            for info in z.infolist():
                if info.is_dir():
                    continue
                data = z.read(info.filename)
                if info.filename == 'database.sql.gz':
                    data = gzip.decompress(data)
                if raw in data or raw.hex().encode() in data:
                    found.append(info.filename)
        return found

    def clean_dest(self):
        dst = self.dst
        # This test deletes things with sudo: refuse anything that is not obviously a disposable clone destination.
        assert 'clone' in os.path.basename(dst['dir']) and 'clone' in os.path.basename(dst['dataroot']), 'refusing to clean %s' % dst['dir']
        sudo('rm -rf %s/* %s/.[!.]* %s' % (dst['dir'], dst['dir'], dst['dataroot']))
        mysql(dst['db'], "SET foreign_key_checks=0; " + ' '.join('DROP TABLE `%s`;' % t for t in
              mysql(dst['db'], 'SHOW TABLES').split()) if mysql(dst['db'], 'SHOW TABLES') else 'SELECT 1')
        check(mysql(dst['db'], 'SHOW TABLES') == '' and not os.path.exists(dst['dataroot']), 'destination: empty folder, empty database, no moodledata')

    def destination(self, package_name, password, keep_package):
        """Steps 1..n at the destination: authorize, verify, settings, restore, verify the result, revocation."""
        dst = self.dst
        url = dst['url'] + '/installer.php'
        listing = lambda: sorted(sudo('ls -A %s' % dst['dir']).split())

        # ---- Pre-authorization: what a stranger sees and can do.
        stranger = self.context()
        page = stranger.new_page()
        resp = page.goto(url)
        check(resp.status == 200 and page.locator('input[name=password]').count() == 1, 'installer: the first page asks for the installer password')
        check(page.locator('input[name=key]').count() == 0, 'installer: ... and not for a key file (nothing to read on the server)')
        text = body_text(page)
        check(package_name not in text and 'school' not in text.lower() and '/var/www' not in text and 'Moodle 4.1' not in text,
              'installer: before authorization it shows nothing about the package, the site or the server paths')
        self.shot(page, '03-installer-login')
        files = listing()
        check('moodleclone-installer-key.php' not in files, 'destination: no key file is created in password mode')
        check('.htaccess' in files, 'destination: the protective .htaccess is in place from the first request')
        got = self.raw_get(dst['url'] + '/' + package_name)
        check(got in (403, 404), 'destination: the package cannot be downloaded from the web while the installer is here (HTTP %s)' % got)
        got = self.raw_get(dst['url'] + '/moodleclone-installer-auth.php')
        check(got in (403, 404), 'destination: the installer state file is not served (HTTP %s)' % got)

        # ---- Wrong passwords, then the lock-out.
        statuses = []
        for wrong in self.wrongs[:5]:
            page.fill('input[name=password]', wrong)
            with page.expect_navigation() as nav:
                page.click('button:has-text("Continue")')
            statuses.append(nav.value.status)
            check('not correct' in body_text(page), 'installer: a wrong password is refused (%r)' % wrong) if wrong == self.wrongs[0] else None
        check(statuses == [403] * 5, 'installer: five wrong passwords: HTTP %s' % statuses)
        page.fill('input[name=password]', self.wrongs[5])
        with page.expect_navigation() as nav:
            page.click('button:has-text("Continue")')
        check(nav.value.status == 429 and 'Too many failed attempts' in body_text(page), 'installer: the sixth attempt is rate-limited (HTTP %s)' % nav.value.status)
        self.shot(page, '04-installer-locked-out')
        page.fill('input[name=password]', password)
        with page.expect_navigation() as nav:
            page.click('button:has-text("Continue")')
        check(nav.value.status == 429 and 'Too many failed attempts' in body_text(page),
              'installer: even the CORRECT password is refused during the lock-out (it is not an oracle)')
        same_wording = re.sub(r'\d+', 'N', body_text(page))
        # A different browser (another address would be the same here): the lock is per client and global; both are on the server.
        state = sudo('cat %s/moodleclone-installer-auth.php' % dst['dir'])
        check(state.startswith('<?php exit; ?>') and password not in state, 'destination: the attempt counters live in a server-side file behind an exit guard, without the password')
        print('    waiting for the lock-out to end (about 31 seconds)...', flush=True)
        time.sleep(31)

        # ---- The correct password.
        page.fill('input[name=password]', password)
        page.click('button:has-text("Continue")')
        heading = wait_h1(page, ['Package verified', 'Package checksum'], 120000)
        check(heading == 'Package verified', 'installer: the correct password authorizes the session (page: %s)' % heading)
        check('SHA-256 verified' in body_text(page), 'installer: the package is verified against its .sha256 file')
        check(mysql(dst['db'], 'SHOW TABLES') == '', 'destination: nothing was touched before authorization and verification')
        self.shot(page, '05-installer-package-verified')

        # ---- Forgeries.
        cookies = {c['name']: c for c in stranger.cookies()}
        real = cookies['MOODLECLONEINSTALLER']['value']
        forger = self.context()
        forger.add_cookies([{'name': 'MOODLECLONEINSTALLER', 'value': 'a' * 26, 'url': dst['url']}])
        fpage = forger.new_page()
        fpage.goto(url)
        check(fpage.locator('input[name=password]').count() == 1, 'forgery: a made-up session id gets the login page')
        resp = fpage.request.post(url, form={'csrf': 'x' * 64, 'action': 'environment'})
        check('Package verified' not in resp.text() and 'Destination settings' not in resp.text(), 'forgery: a forged POST to a later step does nothing')
        resp = fpage.request.post(url, form={'action': 'settings', 'auth': '1', 'authtoken': 'f' * 64, 'wwwroot': 'http://x'})
        check('input name="password"' in resp.text() or 'name="password"' in resp.text(), 'forgery: posting an auth flag or a token is not authorization')
        forger.close()
        other_ua = self.context(user_agent='Mozilla/5.0 (Evil) Chrome/1')
        other_ua.add_cookies([{'name': 'MOODLECLONEINSTALLER', 'value': real, 'url': dst['url']}])
        opage = other_ua.new_page()
        opage.goto(url)
        check(opage.locator('input[name=password]').count() == 1, 'forgery: a stolen session cookie used from a different browser is refused')
        other_ua.close()
        page.reload()
        check(h1(page) == 'Package verified', 'forgery: none of that disturbed the real session (it is still on: %s)' % h1(page))

        # ---- A second authorized session (used later to prove revocation).
        second = self.context()
        spage = second.new_page()
        spage.goto(url)
        spage.fill('input[name=password]', password)
        spage.click('button:has-text("Continue")')
        check(wait_h1(spage, ['Package verified'], 120000) == 'Package verified', 'installer: a second browser can authorize itself with the password')

        # ---- Restore through the browser.
        page.click('button:has-text("Continue")')
        check(wait_h1(page, ['Destination settings'], 60000) == 'Destination settings', 'installer: destination settings page')
        page.fill('input[name=wwwroot]', dst['url'])
        page.fill('input[name=dataroot]', dst['dataroot'])
        page.fill('input[name=dbhost]', dst['db']['host'])
        page.fill('input[name=dbname]', dst['db']['name'])
        page.fill('input[name=dbuser]', dst['db']['user'])
        page.fill('input[name=dbpass]', dst['db']['pass'])
        if not keep_package:
            page.check('input[name=deletepackage]')
        else:
            page.uncheck('input[name=deletepackage]')
        page.click('button:has-text("Continue")')
        heading = wait_h1(page, ['Ready to install', 'Destination settings'], 120000)
        check(heading == 'Ready to install', 'installer: settings accepted (live database check passed)')
        page.click('button:has-text("Install")')
        started = time.time()
        heading = wait_h1(page, ['Installation complete', 'The installation stopped', 'Cannot continue'])
        print('    installation took %.0fs' % (time.time() - started), flush=True)
        self.shot(page, '06-installer-complete')
        done = body_text(page)
        check(heading == 'Installation complete', 'installer: the installation completed through the browser (%s)' % (heading if heading != 'Installation complete' else 'ok'))
        if heading != 'Installation complete':
            print(done[:1500])
        for expected in ['Moodle sees all', 'site administrator account', 'no upgrade needed', 'login page of the new site answers']:
            check(expected in done, 'installer verification: %s' % expected)

        # ---- After success.
        files = listing()
        check('installer.php' not in files, 'destination: installer.php removed itself')
        check(not any(f.startswith('moodleclone-installer') for f in files), 'destination: the installer state, key and lock files are gone: %s' % [f for f in files if f.startswith('moodleclone')])
        ht = sudo('cat %s/.htaccess 2>/dev/null' % dst['dir'])
        check('moodleclone-installer protection' not in ht, 'destination: the temporary protection was removed (the package\'s own .htaccess restored)')
        check(self.raw_get(dst['url'] + '/installer.php') == 404, 'destination: installer.php answers 404')
        site = self.context()
        sp = site.new_page()
        sp.goto(dst['url'] + '/login/index.php')
        check('Log in' in sp.title(), 'destination: the restored site serves its login page (%s)' % sp.title())
        site.close()
        cfg = sudo('cat %s/config.php' % dst['dir'])
        check(dst['db']['pass'] in cfg and self.src['db']['pass'] not in cfg, 'destination: config.php was generated for the destination (not copied)')

        # ---- The other authorized session is dead: put the installer back (the worst case) and try it.
        shutil.copy(os.path.join(self.dl, 'installer.php'), dst['dir'])
        spage.goto(url)
        check(spage.locator('input[name=password]').count() == 0 and 'already contains a Moodle' in body_text(spage),
              'revocation: with the installer put back, the second browser\'s session authorizes nothing (page says the folder is already installed)')
        check(not os.path.exists(dst['dir'] + '/.htaccess') or 'moodleclone-installer protection' not in sudo('cat %s/.htaccess' % dst['dir']),
              'revocation: a leftover installer does not lock the finished site down with a deny-all .htaccess')
        check(self.raw_get(dst['url'] + '/login/index.php') == 200, 'revocation: the finished site still answers while a stale installer sits in its folder')
        resp = spage.request.post(url, form={'csrf': 'x', 'action': 'reset'})
        check('Destination reset' not in resp.text() and 'The installation stopped' not in resp.text(), 'revocation: it cannot reset or restore the finished site')
        check(mysql(dst['db'], 'SHOW TABLES') != '', 'revocation: the restored database is intact')
        stale = self.context()
        stale.add_cookies([{'name': 'MOODLECLONEINSTALLER', 'value': real, 'url': dst['url']}])
        tp = stale.new_page()
        tp.goto(url)
        check(tp.locator('input[name=password]').count() == 0 and 'already contains a Moodle' in body_text(tp),
              'revocation: the first browser\'s old session cookie gets nothing either')
        second.close()
        stale.close()
        stranger.close()
        sudo('rm -f %s/installer.php' % dst['dir'])

        # ---- Nowhere on the servers is the plaintext.
        hits = sudo('grep -rlaF -- %s /var/lib/php/sessions /var/log/apache2 %s 2>/dev/null' % (shell_quote(password), dst['dir']))
        check(hits.strip() == '', 'servers: the installer password is in no PHP session file, Apache log or destination file: %r' % hits.strip())
        logrows = mysql(self.src['db'], "SELECT COUNT(*) FROM mdl_logstore_standard_log WHERE other LIKE %s" % shell_sql('%' + password[:18] + '%'))
        check(logrows == '0', 'source: the password is in no Moodle log record')

    def raw_get(self, url):
        ctx = self.context()
        try:
            return ctx.request.get(url, max_redirects=0).status
        finally:
            ctx.close()

    def download_latest(self, page):
        """Download the newest completed package and its .sha256 through the admin page; returns (package, sidecar) paths."""
        page.goto(self.src['url'] + '/admin/tool/moodleclone/index.php')
        row = page.locator('tr:has(.badge:has-text("Completed"))').first
        with page.expect_download(timeout=600000) as d:
            row.locator('a:has-text("Download")').first.click()
        package_path = os.path.join(self.dl, d.value.suggested_filename)
        d.value.save_as(package_path)
        with page.expect_download() as d2:
            row.locator('a:has-text("SHA-256 file")').click()
        sidecar_path = os.path.join(self.dl, d2.value.suggested_filename)
        d2.value.save_as(sidecar_path)
        return package_path, sidecar_path

    def scenario_keyfile(self):
        print('\n== Scenario: a format 3 package created with the key-file alternative ==', flush=True)
        src, dst = self.src, self.dst
        ctx = self.context()
        page = ctx.new_page()
        if not self.source_login(page):
            return
        self.create_package(page, 'keyfile')
        opts = mysql(src['db'], "SELECT options FROM mdl_tool_moodleclone_jobs ORDER BY id DESC LIMIT 1")
        check('keyfile' in opts and 'verifier' not in opts, 'source: a key-file job holds no verifier at all')
        self.run_worker()
        page.goto(src['url'] + '/admin/tool/moodleclone/index.php')
        row = page.locator('tr:has(.badge:has-text("Completed"))').first
        check('Key file' in row.inner_text(), 'source: the package list says it uses the key file')
        package_path, sidecar_path = self.download_latest(page)
        ctx.close()
        self.artifacts['keyfile'] = (package_path, sidecar_path)
        manifest = json.loads(zipfile.ZipFile(package_path).read('manifest.json'))
        check(manifest['format'] == 3 and manifest['installer_auth'] == {'mode': 'keyfile'}, 'package: manifest format 3 with installer_auth keyfile')

        self.clean_dest()
        installer = self.artifacts.get('installer') or os.path.join(self.dl, 'installer.php')
        for path in (package_path, sidecar_path, installer):
            shutil.copy(path, dst['dir'])
        c = self.context()
        p = c.new_page()
        p.goto(dst['url'] + '/installer.php')
        check(p.locator('input[name=key]').count() == 1 and p.locator('input[name=password]').count() == 0, 'installer: asks for the key, not a password')
        keyfile = dst['dir'] + '/moodleclone-installer-key.php'
        check(sudo('stat -c %%a %s' % keyfile).strip() == '600', 'installer: the key file is mode 0600')
        p.fill('input[name=key]', 'f' * 32)
        with p.expect_navigation() as nav:
            p.click('button:has-text("Continue")')
        check(nav.value.status == 403, 'installer: a wrong key is refused')
        key = sudo('cat %s' % keyfile).split('?>')[1].strip()
        p.fill('input[name=key]', key)
        p.click('button:has-text("Continue")')
        check(wait_h1(p, ['Package verified', 'Package checksum'], 120000) == 'Package verified', 'installer: the key authorizes the session')
        c.close()

    def scenario_both(self):
        print('\n== Scenario: one folder, one password package and one key-file package ==', flush=True)
        dst = self.dst
        if 'password' not in self.artifacts or 'keyfile' not in self.artifacts:
            print('  (needs the password and keyfile scenarios in the same run)')
            return
        self.clean_dest()
        pw_pkg, pw_sum = self.artifacts['password']
        kf_pkg, kf_sum = self.artifacts['keyfile']
        for path in (pw_pkg, pw_sum, kf_pkg, kf_sum, self.artifacts['installer']):
            shutil.copy(path, dst['dir'])
        url = dst['url'] + '/installer.php'
        pw_name, kf_name = os.path.basename(pw_pkg), os.path.basename(kf_pkg)

        c = self.context()
        p = c.new_page()
        p.goto(url)
        check(p.locator('input[name=password]').count() == 1 and p.locator('input[name=key]').count() == 1,
              'both: the login page offers the password and the key')
        check(pw_name not in body_text(p) and kf_name not in body_text(p), 'both: ... without naming either package')
        p.fill('input[name=password]', self.password)
        p.click('button:has-text("Continue")')
        check(wait_h1(p, ['Package verified', 'Package checksum', 'Choose the package'], 120000) == 'Package verified', 'both: the password authorizes')
        check(pw_name in body_text(p) and kf_name not in body_text(p), 'both: ... only the password package (%s), the other is not offered' % pw_name)
        c.close()

        c = self.context()
        p = c.new_page()
        p.goto(url)
        key = sudo('cat %s/moodleclone-installer-key.php' % dst['dir']).split('?>')[1].strip()
        p.fill('input[name=key]', key)
        p.click('button:has-text("Continue")')
        check(wait_h1(p, ['Package verified', 'Package checksum', 'Choose the package'], 120000) == 'Package verified', 'both: the key authorizes')
        check(kf_name in body_text(p) and pw_name not in body_text(p), 'both: ... only the key-file package (%s)' % kf_name)
        # A wrong password never reveals which package it was tried against.
        c.close()
        c = self.context()
        p = c.new_page()
        p.goto(url)
        p.fill('input[name=password]', 'x' * 20)
        with p.expect_navigation() as nav:
            p.click('button:has-text("Continue")')
        check(nav.value.status == 403 and pw_name not in body_text(p) and kf_name not in body_text(p), 'both: a wrong password names nothing')
        c.close()

    def scenario_legacy(self):
        print('\n== Scenario: a package made before installer passwords (format 2) uses the key file ==', flush=True)
        dst = self.dst
        legacy = self.cfg['legacy_package']
        name = os.path.basename(legacy)
        self.clean_dest()
        shutil.copy(legacy, dst['dir'])
        shutil.copy(legacy + '.sha256', dst['dir'])
        # What an administrator's file transfer does: files the web server can read.
        for f in (name, name + '.sha256'):
            os.chmod(os.path.join(dst['dir'], f), 0o644)
        shutil.copy(os.path.join(self.dl, 'installer.php'), dst['dir'])
        url = dst['url'] + '/installer.php'
        ctx = self.context()
        page = ctx.new_page()
        page.goto(url)
        check(page.locator('input[name=key]').count() == 1 and page.locator('input[name=password]').count() == 0,
              'legacy: a format 2 package asks for the key file, not a password')
        text = body_text(page)
        check('moodleclone-installer-key.php' in text and '/var/www' not in text, 'legacy: the page names the key file but not the server path')
        keyfile = dst['dir'] + '/moodleclone-installer-key.php'
        mode = sudo('stat -c %%a %s' % keyfile).strip()
        check(mode == '600', 'legacy: the key file is mode 0600 (%s)' % mode)
        page.fill('input[name=key]', '0' * 32)
        with page.expect_navigation() as nav:
            page.click('button:has-text("Continue")')
        check(nav.value.status == 403 and 'not correct' in body_text(page), 'legacy: a wrong key is refused')
        key = sudo('cat %s' % keyfile).split('?>')[1].strip()
        page.fill('input[name=key]', key)
        page.click('button:has-text("Continue")')
        check(wait_h1(page, ['Package verified', 'Package checksum'], 120000) == 'Package verified', 'legacy: the key authorizes the session')
        check('school' in body_text(page) or 'Source site' in body_text(page), 'legacy: package details appear only after authorization')
        page.click('button:has-text("Continue")')
        wait_h1(page, ['Destination settings'], 60000)
        page.fill('input[name=wwwroot]', dst['url'])
        page.fill('input[name=dataroot]', dst['dataroot'])
        page.fill('input[name=dbhost]', dst['db']['host'])
        page.fill('input[name=dbname]', dst['db']['name'])
        page.fill('input[name=dbuser]', dst['db']['user'])
        page.fill('input[name=dbpass]', dst['db']['pass'])
        page.uncheck('input[name=deletepackage]')
        page.click('button:has-text("Continue")')
        wait_h1(page, ['Ready to install'], 120000)
        page.click('button:has-text("Install")')
        started = time.time()
        heading = wait_h1(page, ['Installation complete', 'The installation stopped', 'Cannot continue'])
        print('    installation took %.0fs' % (time.time() - started), flush=True)
        done = body_text(page)
        check(heading == 'Installation complete', 'legacy: the real 96 MB format 2 package restored through the browser (%s)' % heading)
        check('Moodle sees all 510 database tables' in done, 'legacy: 510 tables restored')
        files = sorted(sudo('ls -A %s' % dst['dir']).split())
        check('installer.php' not in files and 'moodleclone-installer-key.php' not in files, 'legacy: installer and key file removed after success')
        check(self.raw_get(dst['url'] + '/login/index.php') == 200, 'legacy: the restored site answers')
        ctx.close()

    def close(self):
        self.browser.close()


def shell_quote(value):
    return "'" + value.replace("'", "'\\''") + "'"


def shell_sql(value):
    return "'" + value.replace("'", "''") + "'"


def main():
    if len(sys.argv) < 2 or 'MCI_SUDO' not in os.environ:
        print(__doc__)
        return 2
    cfg = json.load(open(sys.argv[1]))
    scenarios = sys.argv[2:] or ['password']
    with sync_playwright() as p:
        run = Run(cfg, p)
        try:
            for name in scenarios:
                getattr(run, 'scenario_' + name)()
        finally:
            run.close()
    failed = [label for ok, label in results if not ok]
    print('\n%d checks, %d passed, %d failed' % (len(results), len(results) - len(failed), len(failed)))
    for label in failed:
        print('FAILED: ' + label)
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
