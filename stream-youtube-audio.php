<?php
/**
 * Simple YouTube audio downloader (yt-dlp wrapper) with realtime logs.
 * Saves the best available audio stream to a directory — no re-encoding.
 * Requires PHP 8+ (Debian 13 ships PHP 8.4).
 *
 * DANGER! - for personal use only on internal home network - there is
 * no auth or security, no concurency - runs with built-in php webserver
 * on Rasbery Pi
 *
 * /etc/fstab:
 * tmpfs /var/www/public/some-hard-to-guess-random-name tmpfs defaults,noatime,size=100M,mode=1777 0 0
 * 
 * Install requirements:
 * apt install -y php-cli pipx
 * sudo -u www-data bash
 * pipx install yt-dlp
 * pipx ensurepath
 * mkdir -p /var/www/public/some-hard-to-guess-random-name
 * chown www-data: /var/www/public/some-hard-to-guess-random-name
 * 
 * Run:
 * cd /var/www/public;screen -dmS php sudo -u www-data php -S 0.0.0.0:8080
 *
 * Everything lives in this one file, which now answers in three ways:
 *   - normal GET         → show the HTML page
 *   - POST  ?stream=1    → run yt-dlp and stream its output live (text/plain)
 *   - GET   ?download=1  → serve the most recently downloaded audio file
 */

// ---- Configuration ----------------------------------------------------------

// 

// Absolute path to the yt-dlp binary.
// IMPORTANT: a pipx install lives in the INSTALLING user's home
// (e.g. /home/pi/.local/bin/yt-dlp). A web server runs as "www-data", which
// has a different home and a minimal PATH, so the bare command "yt-dlp" will
// NOT be found. Point this at the real absolute path (`which yt-dlp`).
$ytDlp = '/var/www/.local/bin/yt-dlp';

// Where downloaded audio files should be saved.
$downloadDir = '/var/www/public/some-hard-to-guess-random-name';

// Fixed base name for the saved file. The same name is reused every time, so
// each new download overwrites the previous one. With -f bestaudio the
// EXTENSION follows whatever YouTube serves (usually .webm or .m4a), so the
// full filename ends up like "audio.webm".
$fileName = 'audio';

// ---- Helpers ----------------------------------------------------------------

function isYoutubeUrl(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    // Only web URLs make sense here; FILTER_VALIDATE_URL alone also accepts
    // schemes like ftp://, which yt-dlp would choke on anyway.
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    // Accept youtube.com (and subdomains like www./m./music.) and youtu.be.
    foreach (['youtube.com', 'youtu.be'] as $domain) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return true;
        }
    }
    return false;
}

/** Ensure the target directory exists and is writable. Returns an error string or null. */
function prepareDownloadDir(string $dir): ?string
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        return 'Could not create the download directory.';
    }
    if (!is_writable($dir)) {
        return 'The download directory is not writable by the web server user.';
    }
    return null;
}

/**
 * Remove any previously downloaded file(s) so only the newest remains. This
 * matches only this script's fixed base name (e.g. "audio.webm", "audio.m4a"),
 * so nothing else in the folder is touched. glob() is given a trusted constant
 * here, never user input.
 */
function clearOldFiles(string $dir, string $base): void
{
    foreach (glob($dir . '/' . $base . '.*') ?: [] as $old) {
        if (is_file($old)) {
            unlink($old);
        }
    }
}

/** Basename of the current downloaded file (trusted constant base name), or null. */
function downloadedFile(string $dir, string $base): ?string
{
    $found = glob($dir . '/' . $base . '.*') ?: [];
    return $found ? basename($found[0]) : null;
}

/**
 * Build the yt-dlp command. EVERY value that may contain user input is passed
 * through escapeshellarg(), so a crafted URL such as  "; rm -rf ~ #  cannot
 * break out and run extra shell commands. 2>&1 captures yt-dlp's progress and
 * errors so we can display them.
 *
 * "exec env" (trusted constants, not escaped) does two jobs:
 *   exec  makes /bin/sh REPLACE itself with the command instead of staying
 *         around as a parent process. Without it, the SIGKILL sent by
 *         proc_terminate() when the user closes the tab would only kill the
 *         wrapper shell and leave yt-dlp running as an orphan.
 *   env   sets PYTHONUNBUFFERED=1, which forces Python — and therefore
 *         yt-dlp — to flush its output line-by-line instead of in one big
 *         block when stdout is a pipe. Without it, "realtime" logs would not
 *         appear until the download finished.
 *
 *   -f bestaudio          grab the best audio-only stream (no re-encode)
 *   --no-playlist         a watch URL with a &list= param gets just that video
 *   --newline             print progress on new lines (readable when captured)
 *   --restrict-filenames  keep filenames filesystem-safe (no spaces/unicode)
 *   --force-overwrites    replace the existing file so the same name is reused
 */
function buildCmd(string $ytDlp, string $dir, string $base, string $url): string
{
    return sprintf(
        'exec env PYTHONUNBUFFERED=1 %s -f bestaudio --no-playlist --newline --restrict-filenames --force-overwrites -P %s -o %s %s 2>&1',
        escapeshellarg($ytDlp),
        escapeshellarg($dir),
        escapeshellarg($base . '.%(ext)s'),
        escapeshellarg($url)
    );
}

// ---- Mode: serve the downloaded file (?download=1) --------------------------
// The download directory is usually NOT under the web root, so we stream the
// file through PHP instead of linking to a path the browser can't reach.

if (isset($_GET['download'])) {
    $file = downloadedFile($downloadDir, $fileName);
    if ($file === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No downloaded file found.';
        exit;
    }
    $path = $downloadDir . '/' . $file;
    header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    // "inline" lets the browser show/play it in the new tab; the user can still save it.
    header('Content-Disposition: inline; filename="' . $file . '"');
    header('X-Content-Type-Options: nosniff'); // never let the browser second-guess the type
    header('Cache-Control: no-store'); // the file changes on every download
    readfile($path);
    exit;
}

// ---- Mode: run + stream output live (POST ?stream=1) ------------------------

if (isset($_GET['stream']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // A single ASCII Record-Separator char can never appear in yt-dlp output,
    // so the browser can reliably split the live log from the final JSON
    // status (exit code + filename) we append at the very end.
    $RESULT = "\x1e";

    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no'); // ask nginx not to buffer the response

    // Turn off PHP output buffering so each line is sent immediately.
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);

    $sendResult = static function (int $code, ?string $file) use ($RESULT): void {
        echo $RESULT . json_encode(['code' => $code, 'file' => $file]) . "\n";
        flush();
    };

    $url = trim($_POST['url'] ?? '');

    if (!isYoutubeUrl($url)) {
        echo "Please enter a valid YouTube URL.\n";
        $sendResult(1, null);
        exit;
    }
    if (($err = prepareDownloadDir($downloadDir)) !== null) {
        echo $err . "\n";
        $sendResult(1, null);
        exit;
    }

    // Don't let PHP abort a download that takes a while. ignore_user_abort()
    // keeps the script alive after the browser disconnects mid-stream; without
    // it, PHP would die on the next echo/flush and the connection_aborted()
    // check below would never run — leaving yt-dlp orphaned instead of killed.
    ignore_user_abort(true);
    set_time_limit(0);
    clearOldFiles($downloadDir, $fileName);

    $cmd = buildCmd($ytDlp, $downloadDir, $fileName, $url);

    // proc_open (rather than popen) lets us kill yt-dlp if the user navigates
    // away, instead of leaving it running. stderr is merged into stdout by the
    // 2>&1 in $cmd, so we only need to read pipe 1. Thanks to the "exec" in
    // buildCmd(), the process we hold here IS yt-dlp, not a wrapper shell.
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        echo "Failed to start yt-dlp.\n";
        $sendResult(1, null);
        exit;
    }
    fclose($pipes[0]); // no stdin

    // Stream stdout line-by-line as yt-dlp produces it.
    while (($line = fgets($pipes[1])) !== false) {
        echo str_replace("\r", '', $line); // drop stray carriage returns
        flush();
        if (connection_aborted()) {        // user closed the tab → stop yt-dlp
            proc_terminate($proc, 9);
            break;
        }
    }
    fclose($pipes[1]);

    $exitCode = proc_close($proc);
    $sendResult($exitCode, $exitCode === 0 ? downloadedFile($downloadDir, $fileName) : null);
    exit;
}

// ---- Mode: plain POST (fallback when JavaScript is disabled) ----------------

$message        = '';
$messageClass   = ''; // 'ok' | 'error' — colors the server-rendered status too
$output         = '';
$downloadedName = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['url'])) {
    $url = trim($_POST['url']);

    if (!isYoutubeUrl($url)) {
        $message      = 'Please enter a valid YouTube URL.';
        $messageClass = 'error';
    } elseif (($err = prepareDownloadDir($downloadDir)) !== null) {
        $message      = $err;
        $messageClass = 'error';
    } else {
        set_time_limit(0);
        clearOldFiles($downloadDir, $fileName);
        exec(buildCmd($ytDlp, $downloadDir, $fileName, $url), $lines, $exitCode);
        $output = implode("\n", $lines);
        if ($exitCode === 0) {
            $message        = 'Done — saved to ' . $downloadDir;
            $messageClass   = 'ok';
            $downloadedName = downloadedFile($downloadDir, $fileName);
        } else {
            $message      = 'yt-dlp exited with an error (code ' . $exitCode . ').';
            $messageClass = 'error';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Tell the browser this page is dark: native widgets, scrollbars and the
       Android address bar all follow, so there are no white flashes at night. -->
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#101318">
  <title>Stream YouTube Audio</title>
  <style>
    /* Dark, low-glare palette. Deliberately NOT pure black / pure white:
       #fff on #000 (21:1 contrast) causes halation on OLED screens at night;
       these values sit around 11–13:1 — crisp but easy on the eyes. */
    :root {
      color-scheme: dark;
      --bg:           #101318;
      --surface:      #181d24;
      --surface-hi:   #212832;
      --border:       #2a323d;
      --text:         #dbe1e8;
      --muted:        #8f9aa7;
      --accent:       #8fb8f2; /* soft periwinkle — the one bright spot */
      --accent-press: #a8c6f5;
      --ok:           #86c99b;
      --error:        #ef9a91;
    }

    * { box-sizing: border-box; }
    html { -webkit-text-size-adjust: 100%; }

    /* Mobile-first: one column, comfortable edge padding, safe-area aware.
       max-width only kicks in visually on wider screens. */
    body {
      margin: 0 auto;
      max-width: 640px;
      min-height: 100dvh;
      padding: 1.25rem 1rem calc(2.5rem + env(safe-area-inset-bottom));
      background: var(--bg);
      color: var(--text);
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
      line-height: 1.5;
      -webkit-tap-highlight-color: transparent;
    }

    h1 {
      margin: 0;
      white-space: nowrap; /* clamp() scales the one-line title to fit narrow phones */
      font-size: clamp(1.15rem, 5.5vw, 1.9rem);
      letter-spacing: -0.015em;
    }
    .sub { margin: .35rem 0 1.4rem; color: var(--muted); font-size: .9rem; }

    input[type=url] {
      width: 100%;
      min-height: 48px;   /* Android touch-target size */
      padding: .7rem .9rem;
      font: inherit;
      font-size: 1rem;    /* ≥16px so mobile browsers don't zoom into the field */
      color: var(--text);
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 12px;
      caret-color: var(--accent);
    }
    input[type=url]::placeholder { color: var(--muted); opacity: .8; }

    /* Buttons stack full-width on phones (easy thumb targets)… */
    .row { display: flex; flex-direction: column; gap: .6rem; margin-top: .75rem; }

    .btn {
      display: flex; align-items: center; justify-content: center; gap: .55rem;
      width: 100%;
      min-height: 48px;
      padding: .7rem 1.2rem;
      font: inherit; font-weight: 600;
      color: var(--text);
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 12px;
      cursor: pointer;
      text-decoration: none;
      touch-action: manipulation;
    }
    .btn:active { background: var(--surface-hi); }
    .btn-primary { background: var(--accent); border-color: transparent; color: #10151d; }
    .btn-primary:active { background: var(--accent-press); }
    .btn[disabled] { opacity: .55; cursor: default; }
    :is(input, .btn):focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
    /* Hover only where a real pointer exists — avoids sticky hover on touch. */
    @media (hover: hover) {
      .btn:hover { background: var(--surface-hi); }
      .btn-primary:hover { background: var(--accent-press); }
    }

    /* Small spinner inside the primary button while a download runs. */
    .btn-primary.loading::before {
      content: '';
      width: 1em; height: 1em; flex: none;
      border: 2px solid currentColor;
      border-right-color: transparent;
      border-radius: 50%;
      animation: spin .8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(1turn); } }
    @media (prefers-reduced-motion: reduce) {
      .btn-primary.loading::before { animation: none; }
    }

    .msg { margin: 1rem 0 0; font-weight: 600; overflow-wrap: anywhere; }
    .msg:empty { display: none; } /* an empty status leaves no gap behind */
    .msg.ok { color: var(--ok); }
    .msg.error { color: var(--error); }

    /* The live log: a quiet terminal panel. Dimmer than body text on purpose,
       wraps long URLs, and scrolls inside itself without dragging the page. */
    pre {
      margin: 1rem 0 0;
      padding: .85rem .95rem;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 12px;
      color: var(--muted);
      font-family: ui-monospace, "Cascadia Mono", Menlo, Consolas, monospace;
      font-size: .8125rem;
      line-height: 1.45;
      max-height: 45vh;
      overflow: auto;
      overscroll-behavior: contain;
      white-space: pre-wrap;
      overflow-wrap: anywhere;
    }

    [hidden] { display: none !important; }

    /* …and sit side by side once there's room. */
    @media (min-width: 520px) {
      body { padding-top: 3rem; }
      .row { flex-direction: row; flex-wrap: wrap; }
      .btn { width: auto; }
    }
  </style>
</head>
<body>
  <h1>Stream YouTube Audio</h1>
  <p class="sub">Saves the best audio stream on the Pi — each new download replaces the previous one.</p>

  <form id="dl-form" method="post">
    <input type="url" id="url" name="url" placeholder="https://www.youtube.com/watch?v=..."
           inputmode="url" enterkeyhint="go" autocomplete="off" autocapitalize="none"
           autocorrect="off" spellcheck="false" required>
    <div class="row">
      <button type="submit" id="submit" class="btn btn-primary">Download</button>
      <?php /* href is pre-filled server-side after a plain (no-JS) POST, so the
               link works even when JavaScript is disabled. */ ?>
      <a id="open-btn" class="btn" target="_blank" rel="noopener"
         <?= $downloadedName !== null ? 'href="?download=1&t=' . time() . '"' : 'hidden' ?>>Open file in new tab ↗</a>
      <button type="button" id="clear-btn" class="btn" hidden>Clear input</button>
    </div>
  </form>

  <?php // Keep the <p> free of whitespace: the .msg:empty rule needs a truly empty node. ?>
  <p id="status" class="msg<?= $messageClass !== '' ? ' ' . $messageClass : '' ?>" aria-live="polite"><?= htmlspecialchars($message) ?></p>
  <pre id="log" aria-live="polite" <?= $output === '' ? 'hidden' : '' ?>><?= htmlspecialchars($output) ?></pre>

  <script>
    const form      = document.getElementById('dl-form');
    const urlInput  = document.getElementById('url');
    const submitBtn = document.getElementById('submit');
    const openBtn   = document.getElementById('open-btn');
    const clearBtn  = document.getElementById('clear-btn');
    const statusEl  = document.getElementById('status');
    const logEl     = document.getElementById('log');
    const RESULT    = '\x1e'; // matches the marker the server appends

    const setBusy = (busy) => {
      submitBtn.disabled = busy;
      submitBtn.classList.toggle('loading', busy);
      submitBtn.textContent = busy ? 'Downloading…' : 'Download';
    };

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const url = urlInput.value.trim();
      if (!url) return;

      // Reset the UI for a fresh run.
      openBtn.hidden = true;
      openBtn.removeAttribute('href');
      clearBtn.hidden = true;
      statusEl.textContent = '';
      statusEl.className = 'msg';
      logEl.hidden = false;
      logEl.textContent = '';
      setBusy(true);

      let result = null;
      let buffer = '';
      const decoder = new TextDecoder();

      // Split the live log from the trailing status marker, if present.
      const render = () => {
        let display = buffer;
        const i = buffer.indexOf(RESULT);
        if (i !== -1) {
          display = buffer.slice(0, i);
          try { result = JSON.parse(buffer.slice(i + 1).trim()); } catch (_) {}
        }
        logEl.textContent = display;
        logEl.scrollTop = logEl.scrollHeight; // keep the newest line in view
      };

      try {
        const resp = await fetch('?stream=1', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ url })
        });
        if (!resp.ok || !resp.body) throw new Error('server returned ' + resp.status);

        const reader = resp.body.getReader();
        for (;;) {
          const { done, value } = await reader.read();
          if (done) break;
          buffer += decoder.decode(value, { stream: true });
          render();
        }
        buffer += decoder.decode(); // flush any partial multi-byte char the decoder held back
        render();
      } catch (err) {
        statusEl.textContent = 'Error: ' + err.message;
        statusEl.classList.add('error');
        return;
      } finally {
        setBusy(false); // runs on success AND on the early return above
      }

      if (result && result.code === 0 && result.file) {
        statusEl.textContent = 'Done — ' + result.file;
        statusEl.classList.add('ok');
        // Cache-bust because the filename is reused on every download.
        openBtn.href = '?download=1&t=' + Date.now();
        openBtn.hidden = false;
        // Auto-open the file in a new tab by "pressing" the button for the
        // user. NOTE: this click runs after an async download, not directly
        // from the user's own click, so the browser may treat the new tab as
        // an unrequested pop-up and block it. If that happens the button stays
        // visible as a one-click fallback — allow pop-ups for this site to
        // make the auto-open stick.
        openBtn.click();
      } else if (result && result.code === 0) {
        statusEl.textContent = 'Done, but the saved file could not be found.';
        statusEl.classList.add('error');
      } else if (result) {
        statusEl.textContent = 'yt-dlp exited with an error (code ' + result.code + ').';
        statusEl.classList.add('error');
      } else {
        statusEl.textContent = 'Finished, but no status was received.';
        statusEl.classList.add('error');
      }
    });

    // Once the file has been opened, offer to clear the field for the next URL.
    openBtn.addEventListener('click', () => {
      clearBtn.hidden = false;
    });

    clearBtn.addEventListener('click', () => {
      urlInput.value = '';
      // <p> and <pre> have no .value — the old code set it and silently did
      // nothing. textContent is the right property, and emptying the status
      // (instead of hiding it) means the next run's "Done" message can never
      // end up invisible.
      statusEl.textContent = '';
      statusEl.className = 'msg';
      logEl.textContent = '';
      logEl.hidden = true;
      clearBtn.hidden = true;
      openBtn.hidden = true;
      openBtn.removeAttribute('href');
      urlInput.focus();
    });
  </script>
</body>
</html>
