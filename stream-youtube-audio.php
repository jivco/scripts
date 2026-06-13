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
 * tmpfs /var/www/youtube tmpfs defaults,noatime,size=100M,mode=1777 0 0
 *
 * Install requirements:
 * apt install -y php-cli pipx
 * sudo -u www-data bash
 * pipx install yt-dlp
 * pipx ensurepath
 * mkdir /var/www/youtube
 * chown www-data: /var/www/youtube
 *
 * Run:
 * cd /var/www;screen -dmS php sudo -u www-data php -S 0.0.0.0:8080
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
$downloadDir = '/var/www/youtube';

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
 * PYTHONUNBUFFERED=1 (a trusted constant, not escaped) forces Python — and
 * therefore yt-dlp — to flush its output line-by-line instead of in one big
 * block when stdout is a pipe. Without it, "realtime" logs would not appear
 * until the download finished.
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
        'PYTHONUNBUFFERED=1 %s -f bestaudio --no-playlist --newline --restrict-filenames --force-overwrites -P %s -o %s %s 2>&1',
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
    // 2>&1 in $cmd, so we only need to read pipe 1.
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
$output         = '';
$downloadedName = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['url'])) {
    $url = trim($_POST['url']);

    if (!isYoutubeUrl($url)) {
        $message = 'Please enter a valid YouTube URL.';
    } elseif (($err = prepareDownloadDir($downloadDir)) !== null) {
        $message = $err;
    } else {
        set_time_limit(0);
        clearOldFiles($downloadDir, $fileName);
        exec(buildCmd($ytDlp, $downloadDir, $fileName, $url), $lines, $exitCode);
        $output = implode("\n", $lines);
        if ($exitCode === 0) {
            $message        = 'Done — saved to ' . $downloadDir;
            $downloadedName = downloadedFile($downloadDir, $fileName);
        } else {
            $message = 'yt-dlp exited with an error (code ' . $exitCode . ').';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>YouTube Audio Downloader</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 640px; margin: 3rem auto; padding: 0 1rem; }
    input[type=url] { width: 100%; padding: .6rem; box-sizing: border-box; }
    .row { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; margin-top: .6rem; }
    .btn { display: inline-block; padding: .6rem 1.2rem; font: inherit; cursor: pointer;
           border: 1px solid #888; border-radius: 6px; background: #fafafa;
           color: inherit; text-decoration: none; }
    .btn:hover { background: #f0f0f0; }
    .btn[disabled] { opacity: .6; cursor: default; }
    pre { background: #f4f4f4; padding: 1rem; overflow: auto; white-space: pre-wrap; max-height: 320px; }
    .msg { font-weight: bold; }
    .msg.ok { color: #137333; }
    .msg.error { color: #c5221f; }
    [hidden] { display: none !important; }
  </style>
</head>
<body>
  <h1>Download YouTube audio</h1>

  <form id="dl-form" method="post">
    <input type="url" id="url" name="url" placeholder="https://www.youtube.com/watch?v=..." required>
    <div class="row">
      <button type="submit" id="submit" class="btn">Download</button>
      <?php /* href is pre-filled server-side after a plain (no-JS) POST, so the
               link works even when JavaScript is disabled. */ ?>
      <a id="open-btn" class="btn" target="_blank" rel="noopener"
         <?= $downloadedName !== null ? 'href="?download=1&t=' . time() . '"' : 'hidden' ?>>Open file in new tab ↗</a>
      <button type="button" id="clear-btn" class="btn" hidden>Clear input</button>
    </div>
  </form>

  <p id="status" class="msg"><?= htmlspecialchars($message) ?></p>
  <pre id="log" <?= $output === '' ? 'hidden' : '' ?>><?= htmlspecialchars($output) ?></pre>

  <script>
    const form      = document.getElementById('dl-form');
    const urlInput  = document.getElementById('url');
    const submitBtn = document.getElementById('submit');
    const openBtn   = document.getElementById('open-btn');
    const clearBtn  = document.getElementById('clear-btn');
    const statusEl  = document.getElementById('status');
    const logEl     = document.getElementById('log');
    const RESULT    = '\x1e'; // matches the marker the server appends

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
      submitBtn.disabled = true;
      submitBtn.textContent = 'Downloading…';

      let result = null;
      try {
        const resp = await fetch('?stream=1', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ url })
        });
        if (!resp.ok || !resp.body) throw new Error('server returned ' + resp.status);

        const reader = resp.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        for (;;) {
          const { done, value } = await reader.read();
          if (done) break;
          buffer += decoder.decode(value, { stream: true });

          // Split the live log from the trailing status marker, if present.
          let display = buffer;
          const i = buffer.indexOf(RESULT);
          if (i !== -1) {
            display = buffer.slice(0, i);
            try { result = JSON.parse(buffer.slice(i + 1).trim()); } catch (_) {}
          }
          logEl.textContent = display;
          logEl.scrollTop = logEl.scrollHeight; // keep the newest line in view
        }
      } catch (err) {
        statusEl.textContent = 'Error: ' + err.message;
        statusEl.classList.add('error');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Download';
        return;
      }

      submitBtn.disabled = false;
      submitBtn.textContent = 'Download';

      if (result && result.code === 0 && result.file) {
        statusEl.textContent = 'Done — ' + result.file;
        statusEl.classList.add('ok');
        // Cache-bust because the filename is reused on every download.
        openBtn.href = '?download=1&t=' + Date.now();
        openBtn.hidden = false;
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
      statusEl.value = '';
      logEl.value = '';
      statusEl.hidden = true;
      logEl.hidden = true;
      clearBtn.hidden = true;
      openBtn.hidden = true;
      openBtn.removeAttribute('href');
      urlInput.focus();
    });
  </script>
</body>
</html>
