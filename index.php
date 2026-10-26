<?php
/*
  MICRO REMOTE V7 - swapped micro prev/next commands and synced mini volume chip.

  Denon CEOL / RCD-N9 TCP/IP Remote
  Single-file PHP 7.2 webapp, no Bootstrap, no database.

  Deploy this file on a PHP server inside the same LAN as the Denon.
  The browser talks to PHP; PHP talks to the Denon over TCP port 23.

  Important Denon setting:
  Enable Network Control / IP Control on the Denon, otherwise standby control may fail.
*/

declare(strict_types=1);

// The Denon's address is kept on the server, shared by every browser and device, so the page never
// asks for it: the default below until Change IP saves another one in DENON_IP_FILE.
const DEFAULT_DENON_IP = '192.168.23.180';
const DENON_IP_FILE = __DIR__ . '/denon-ip.txt';

// The Denon accepts one control connection at a time, so only one page may control it (see
// claim_session()). This file holds "<page id> <last request time>"; it is runtime state, so it
// lives in the system temp folder. The active page asks every 3 s while visible.
const SESSION_FILE = '/tmp/denon-ceol-session.txt';
const SESSION_TIMEOUT_SECONDS = 10;
const SESSION_BUSY_MESSAGE = 'A session is already active on another device.';

const DENON_TCP_PORT = 23;
const DENON_CONNECT_TIMEOUT_SECONDS = 1.2;
const DENON_DEFAULT_READ_MS = 900;
const DENON_BUSY_RETRY_SECONDS = 2.5; // Longer than one status poll of another client holds the Denon (about 2 s).
const ALLOW_PUBLIC_DENON_IP = false; // Keep false unless this app is firewalled and you know what you are doing.
const APP_TITLE = 'CEOL N9 Micro Command Deck';
const APP_VERSION = 'micro-remote-v7-2026-06-19';

function json_out(array $payload): void {
  header('Content-Type: application/json; charset=utf-8');
  // The Denon sends some texts in bytes that are not valid UTF-8 (e.g. "BDFILE NAME" while the
  // source is CD). Without JSON_INVALID_UTF8_SUBSTITUTE json_encode() returns false, the reply is
  // empty and the page shows "Offline" although the Denon answered: substitute those bytes instead.
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) {
    // Never answer with an empty body: say why the reply could not be built.
    $json = json_encode(array('ok' => false, 'error' => 'Cannot encode the reply as JSON: ' . json_last_error_msg()));
  }
  echo $json;
  exit;
}

function h($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function is_private_ipv4(string $ip): bool {
  if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    return false;
  }

  $long = ip2long($ip);
  if ($long === false) {
    return false;
  }

  $ranges = array(
    array('10.0.0.0', '10.255.255.255'),
    array('172.16.0.0', '172.31.255.255'),
    array('192.168.0.0', '192.168.255.255'),
    array('169.254.0.0', '169.254.255.255'),
    array('127.0.0.1', '127.255.255.255')
  );

  foreach ($ranges as $range) {
    $start = ip2long($range[0]);
    $end = ip2long($range[1]);
    if ($start !== false && $end !== false && $long >= $start && $long <= $end) {
      return true;
    }
  }

  return false;
}

function validate_denon_ip(string $ip): array {
  $ip = trim($ip);

  if ($ip === '') {
    return array(false, '', 'Enter the Denon IP address.');
  }

  if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    return array(false, '', 'Use an IPv4 address, for example 192.168.1.45.');
  }

  if (!ALLOW_PUBLIC_DENON_IP && !is_private_ipv4($ip)) {
    return array(false, '', 'For safety this app accepts only private LAN IPs. Edit ALLOW_PUBLIC_DENON_IP only if you really need it.');
  }

  return array(true, $ip, '');
}

// Opens SESSION_FILE locked, lets $decide(holder, last seen) say what to write back (null = leave it).
// Returns '' or the reason the file could not be used.
function with_session_file(callable $decide): string {
  $fp = @fopen(SESSION_FILE, 'c+');
  if ($fp === false) {
    $why = error_get_last();
    return 'Cannot open ' . SESSION_FILE . ': ' . ($why ? $why['message'] : 'unknown error');
  }
  flock($fp, LOCK_EX);
  $parts = explode(' ', trim((string)stream_get_contents($fp)));
  $write = $decide($parts[0], isset($parts[1]) ? (int)$parts[1] : 0);
  if ($write !== null) {
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $write);
    fflush($fp);
  }
  flock($fp, LOCK_UN);
  fclose($fp);
  return '';
}

// The first page to ask gets the session and keeps it while it keeps asking; any other page is
// refused until the session is released (page hidden or closed) or SESSION_TIMEOUT_SECONDS pass
// without news from it. Returns '' when $page may go on, otherwise why it may not.
function claim_session(string $page): string {
  $free = false;
  $error = with_session_file(function (string $holder, int $seen) use ($page, &$free) {
    $free = $holder === '' || $holder === $page || time() - $seen > SESSION_TIMEOUT_SECONDS;
    return $free ? $page . ' ' . time() : null;
  });
  if ($error !== '') {
    return $error;
  }
  return $free ? '' : SESSION_BUSY_MESSAGE;
}

function release_session(string $page): string {
  return with_session_file(function (string $holder, int $seen) use ($page) {
    return $holder === $page ? '' : null;
  });
}

function current_denon_ip(): string {
  if (is_file(DENON_IP_FILE)) {
    $saved = @file_get_contents(DENON_IP_FILE);
    list($ok, $ip) = validate_denon_ip($saved === false ? '' : $saved);
    if ($ok) {
      return $ip;
    }
    // Unreadable or not a LAN IPv4: report it in the PHP error log and fall back to the default.
    error_log('denon: ignoring ' . DENON_IP_FILE . ': ' . ($saved === false ? 'cannot read it' : 'not a valid LAN IP: "' . trim($saved) . '"'));
  }
  return DEFAULT_DENON_IP;
}

function source_labels(): array {
  return array(
    'SICD' => 'CD',
    'SITUNER' => 'Tuner',
    'SIFM' => 'FM',
    'SIAM' => 'AM',
    'SIIRADIO' => 'Internet Radio',
    'SISERVER' => 'Music Server',
    'SIUSB' => 'USB',
    'SIBLUETOOTH' => 'Bluetooth',
    'SIBT' => 'Bluetooth',
    'SIDIGITALIN1' => 'Digital In 1',
    'SIDIGITALIN2' => 'Digital In 2',
    'SIANALOGIN' => 'Analog In'
  );
}

function allowed_fixed_commands(): array {
  return array(
    // Power / volume / mute / source queries
    'PWON', 'PWSTANDBY', 'PW?',
    'MVUP', 'MVDOWN', 'MV?',
    'MUON', 'MUOFF', 'MU?',
    'SI?',

    // Sources most relevant to CEOL / RCD-N9
    'SICD', 'SITUNER', 'SIFM', 'SIAM', 'SIIRADIO', 'SISERVER', 'SIUSB',
    'SIBLUETOOTH', 'SIBT', 'SIDIGITALIN1', 'SIDIGITALIN2', 'SIANALOGIN',

    // Tuner
    'TFANUP', 'TFANDOWN', 'TFAN?', 'TFANNAME?',
    'TMANFM', 'TMANAM', 'TMANAUTO', 'TMANMANUAL', 'TM?',

    // Display / network info
    'NSA', 'NSE', 'NSINF?', 'SSFMT?',

    // Network browsing / transport commands used by Denon network sources
    'NS90', 'NS91', 'NS92', 'NS93', 'NS94',
    'NS9A', 'NS9B', 'NS9C', 'NS9D', 'NS9E', 'NS9X', 'NS9Y'
  );
}

function is_allowed_denon_command(string $command): bool {
  $command = trim(strtoupper($command));

  if (in_array($command, allowed_fixed_commands(), true)) {
    return true;
  }

  // CEOL-family absolute volume is normally MV00..MV60.
  if (preg_match('/^MV([0-5][0-9]|60)$/', $command)) {
    return true;
  }

  // Favorite direct recall FV01..FV50.
  if (preg_match('/^FV(0[1-9]|[1-4][0-9]|50)$/', $command)) {
    return true;
  }

  // Optional direct tuner frequency, exactly 6 digits, e.g. TFAN105000.
  if (preg_match('/^TFAN[0-9]{6}$/', $command)) {
    return true;
  }

  return false;
}

function normalize_command(string $command): string {
  return trim(strtoupper($command));
}

function clean_denon_line(string $line): string {
  // NSA/NSE lines 1-7 carry an attribute byte right after the line number; bit 3 (0x08) marks the
  // line under the menu cursor. It is a control character (often "\n"), so turn it into a visible
  // "> " for the selected line and drop it otherwise, before control characters are stripped.
  if (preg_match('/^(NS[AE][1-7])([\x00-\x1F])(.*)$/s', $line, $m)) {
    $line = $m[1] . ((ord($m[2]) & 0x08) ? '> ' : '') . $m[3];
  }

  $line = trim($line);
  // Remove control characters except normal UTF-8 text. Denon display lines can contain UTF-8.
  $line = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $line);
  return $line === null ? '' : $line;
}

function denon_tcp_send(string $ip, array $commands, int $readMs = DENON_DEFAULT_READ_MS): array {
  $errors = array();
  $lines = array();
  $raw = '';

  $errno = 0;
  $errstr = '';

  // The Denon serves one control connection at a time and refuses others while it is held (for
  // example by this page open on another device): keep trying for a while before giving up.
  $giveUpAt = microtime(true) + DENON_BUSY_RETRY_SECONDS;
  do {
    $fp = @fsockopen($ip, DENON_TCP_PORT, $errno, $errstr, DENON_CONNECT_TIMEOUT_SECONDS);
    if ($fp) {
      break;
    }
    usleep(100000);
  } while (microtime(true) < $giveUpAt);

  if (!$fp) {
    return array(
      'ok' => false,
      'error' => 'TCP connection to ' . $ip . ':' . DENON_TCP_PORT . ' failed (retried for ' . DENON_BUSY_RETRY_SECONDS . ' s): ' . ($errstr !== '' ? $errstr : ('error ' . $errno)),
      'lines' => array(),
      'raw' => ''
    );
  }

  stream_set_blocking($fp, false);
  stream_set_timeout($fp, 0, 250000);

  foreach ($commands as $command) {
    $command = normalize_command((string)$command);

    if (!is_allowed_denon_command($command)) {
      $errors[] = 'Blocked command: ' . $command;
      continue;
    }

    fwrite($fp, $command . "\r");

    // Denon docs commonly warn that the first command after power-on may need a short delay.
    if ($command === 'PWON') {
      usleep(1000000);
    } else {
      usleep(70000);
    }
  }

  $deadline = microtime(true) + max(100, $readMs) / 1000;

  while (microtime(true) < $deadline) {
    $chunk = fread($fp, 4096);
    if ($chunk !== false && $chunk !== '') {
      $raw .= $chunk;
      $deadline = max($deadline, microtime(true) + 0.12);
    }
    usleep(30000);
  }

  fclose($fp);

  if ($raw !== '') {
    // Denon replies end with CR. A bare "\n" can be the attribute byte of a display line, not a line end.
    $parts = preg_split('/\r\n?/', $raw);
    if (is_array($parts)) {
      foreach ($parts as $part) {
        $line = clean_denon_line((string)$part);
        if ($line !== '') {
          $lines[] = $line;
        }
      }
    }
  }

  return array(
    'ok' => count($errors) === 0,
    'error' => count($errors) ? implode('; ', $errors) : '',
    'lines' => $lines,
    'raw' => $raw
  );
}

function denon_http_get_quiet(string $url, int $timeoutSeconds = 2): array {
  $context = stream_context_create(array(
    'http' => array(
      'method' => 'GET',
      'timeout' => $timeoutSeconds,
      'ignore_errors' => true,
      'header' => "User-Agent: CEOL-PHP-Remote/1.0\r\n"
    ),
    'ssl' => array(
      'verify_peer' => false,
      'verify_peer_name' => false
    )
  ));

  $body = @file_get_contents($url, false, $context);
  if ($body === false) {
    return array('ok' => false, 'body' => '', 'error' => 'HTTP request failed.');
  }

  return array('ok' => true, 'body' => $body, 'error' => '');
}

function denon_http_fallback_command(string $ip, string $command): array {
  $command = normalize_command($command);
  $path = '';

  if ($command === 'PWON') {
    $path = '/goform/formiPhoneAppPower.xml?1+PowerOn';
  } elseif ($command === 'PWSTANDBY') {
    $path = '/goform/formiPhoneAppPower.xml?1+PowerStandby';
  } elseif ($command === 'MUON') {
    $path = '/goform/formiPhoneAppMute.xml?1+MuteOn';
  } elseif ($command === 'MUOFF') {
    $path = '/goform/formiPhoneAppMute.xml?1+MuteOff';
  } elseif (is_allowed_denon_command($command)) {
    $path = '/goform/formiPhoneAppDirect.xml?' . rawurlencode($command);
  } else {
    return array('ok' => false, 'error' => 'Command not allowed for HTTP fallback.', 'lines' => array());
  }

  // RCD-N9 field reports often use HTTPS; many Denon devices also accept HTTP.
  $tries = array('http://' . $ip . $path, 'https://' . $ip . $path, 'http://' . $ip . ':8080' . $path);

  foreach ($tries as $url) {
    $res = denon_http_get_quiet($url, 2);
    if ($res['ok']) {
      return array('ok' => true, 'error' => '', 'lines' => array('HTTP fallback sent: ' . $command), 'body' => $res['body']);
    }
  }

  return array('ok' => false, 'error' => 'HTTP fallback failed on ports 80/443/8080.', 'lines' => array());
}

function parse_denon_state(array $lines): array {
  $labels = source_labels();

  $state = array(
    'power' => 'unknown',
    'powerLabel' => 'Unknown',
    'mute' => null,
    'muteLabel' => 'Unknown',
    'volumeRaw' => '',
    'volumeNumber' => null,
    'volumePercent' => 0,
    'sourceRaw' => '',
    'sourceLabel' => 'Unknown',
    'display' => array_fill(0, 9, ''),
    'tunerFrequencyRaw' => '',
    'tunerFrequencyLabel' => '',
    'tunerStationName' => '',
    'tunerMode' => '',
    'tunerBand' => '',
    'networkInfo' => array(),
    'lastLine' => count($lines) ? $lines[count($lines) - 1] : ''
  );

  foreach ($lines as $line) {
    $line = clean_denon_line((string)$line);

    if ($line === 'PWON') {
      $state['power'] = 'on';
      $state['powerLabel'] = 'On';
      continue;
    }

    if ($line === 'PWSTANDBY') {
      $state['power'] = 'standby';
      $state['powerLabel'] = 'Standby';
      continue;
    }

    if ($line === 'MUON') {
      $state['mute'] = true;
      $state['muteLabel'] = 'Muted';
      continue;
    }

    if ($line === 'MUOFF') {
      $state['mute'] = false;
      $state['muteLabel'] = 'Live';
      continue;
    }

    if (preg_match('/^MV([0-9]{2,3})/', $line, $m)) {
      $state['volumeRaw'] = $m[1];
      $num = (int)$m[1];
      $state['volumeNumber'] = $num;
      $state['volumePercent'] = max(0, min(100, (int)round(($num / 60) * 100)));
      continue;
    }

    if (strpos($line, 'SI') === 0 && strlen($line) > 2) {
      $state['sourceRaw'] = $line;
      $state['sourceLabel'] = isset($labels[$line]) ? $labels[$line] : substr($line, 2);
      continue;
    }

    if (preg_match('/^NS[AE]([0-8])(.*)$/u', $line, $m)) {
      $idx = (int)$m[1];
      $text = trim($m[2]);
      $state['display'][$idx] = $text;
      continue;
    }

    if (preg_match('/^TFAN([0-9]{6})$/', $line, $m)) {
      $state['tunerFrequencyRaw'] = $m[1];
      $state['tunerFrequencyLabel'] = format_tuner_frequency($m[1]);
      continue;
    }

    if (strpos($line, 'TFANNAME') === 0) {
      $state['tunerStationName'] = trim(substr($line, 8));
      continue;
    }

    if ($line === 'TMANFM' || $line === 'TMANAM') {
      $state['tunerBand'] = substr($line, 4);
      continue;
    }

    if (strpos($line, 'TMAN') === 0) {
      $state['tunerMode'] = substr($line, 4);
      continue;
    }

    if (strpos($line, 'NSINFFRN') === 0) {
      $state['networkInfo']['friendlyName'] = trim(substr($line, 8));
      continue;
    }
    if (strpos($line, 'NSINFAFF') === 0) {
      $state['networkInfo']['linkType'] = trim(substr($line, 8));
      continue;
    }
    if (strpos($line, 'NSINFSID') === 0) {
      $state['networkInfo']['ssid'] = trim(substr($line, 8));
      continue;
    }
    if (strpos($line, 'NSINFDHC') === 0) {
      $state['networkInfo']['dhcp'] = trim(substr($line, 8));
      continue;
    }
    if (strpos($line, 'NSINFIPA') === 0) {
      $state['networkInfo']['ip'] = trim(substr($line, 8));
      continue;
    }
    if (strpos($line, 'NSINFMAC') === 0) {
      $state['networkInfo']['mac'] = trim(substr($line, 8));
      continue;
    }
  }

  // The Now Playing screen is not a menu, yet the Denon flags its artist line like the menu
  // cursor: drop the "> " marker there (see clean_denon_line()).
  if ($state['display'][0] === 'Now Playing') {
    foreach ($state['display'] as $i => $text) {
      if (strpos($text, '> ') === 0) {
        $state['display'][$i] = substr($text, 2);
      }
    }
  }

  // The display mirror shows the screen of the Denon's network module (NSE). In standby or on a
  // source that module does not handle, that screen holds leftovers such as "No Connection",
  // which say nothing about the Denon or about the page's link to it: show the mirror empty.
  // Network sources (Internet Radio, Server, USB, Bluetooth...) keep it: there it is meaningful.
  $nonNetworkSources = array('SICD', 'SITUNER', 'SIFM', 'SIAM', 'SIDIGITALIN1', 'SIDIGITALIN2', 'SIANALOGIN');
  if ($state['power'] === 'standby' || in_array($state['sourceRaw'], $nonNetworkSources, true)) {
    $state['display'] = array_fill(0, 9, '');
  }

  return $state;
}

function format_tuner_frequency(string $raw): string {
  if (!preg_match('/^[0-9]{6}$/', $raw)) {
    return $raw;
  }

  $n = (int)$raw;

  // Denon sends the frequency with two implied decimals: FM in MHz (008750 = 87.50 MHz),
  // AM in kHz (105000 = 1050.00 kHz). Anything else is shown raw.
  if ($n >= 7600 && $n <= 10800) {
    return number_format($n / 100, 2, '.', '') . ' MHz';
  }

  if ($n >= 52000 && $n <= 171000) {
    return number_format($n / 100, 0, '.', '') . ' kHz';
  }

  return $raw;
}

function status_command_bundle(): array {
  return array('PW?', 'MV?', 'MU?', 'SI?', 'TFAN?', 'TFANNAME?', 'TM?', 'NSE', 'NSINF?');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
  $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

  if ($action === 'save_ip') {
    list($ok, $ip, $error) = validate_denon_ip(isset($_POST['ip']) ? (string)$_POST['ip'] : '');
    if (!$ok) {
      json_out(array('ok' => false, 'error' => $error));
    }

    if (@file_put_contents(DENON_IP_FILE, $ip . "\n") === false) {
      $why = error_get_last();
      json_out(array('ok' => false, 'error' => 'Cannot save the IP in ' . DENON_IP_FILE . ': ' . ($why ? $why['message'] : 'unknown error') . '. The web server needs write permission on that folder.'));
    }
    json_out(array('ok' => true, 'ip' => $ip));
  }

  if ($action === 'reset_ip') {
    // Back to DEFAULT_DENON_IP.
    if (is_file(DENON_IP_FILE) && !@unlink(DENON_IP_FILE)) {
      $why = error_get_last();
      json_out(array('ok' => false, 'error' => 'Cannot delete ' . DENON_IP_FILE . ': ' . ($why ? $why['message'] : 'unknown error')));
    }
    json_out(array('ok' => true, 'ip' => DEFAULT_DENON_IP));
  }

  // Everything below talks to the Denon: only the page holding the session may do it.
  $page = isset($_POST['page']) ? (string)$_POST['page'] : '';
  if (!preg_match('/^[0-9a-f]{32}$/', $page)) {
    json_out(array('ok' => false, 'error' => 'Missing or invalid page id: reload the page.'));
  }

  if ($action === 'release') {
    $error = release_session($page);
    json_out(array('ok' => $error === '', 'error' => $error));
  }

  $refusal = claim_session($page);
  if ($refusal !== '') {
    json_out(array('ok' => false, 'busy' => $refusal === SESSION_BUSY_MESSAGE, 'error' => $refusal));
  }

  $ip = current_denon_ip();
  if ($ip === '') {
    json_out(array('ok' => false, 'error' => 'No Denon IP configured.'));
  }

  if ($action === 'test') {
    $res = denon_tcp_send($ip, array('PW?'), 600);
    $state = parse_denon_state($res['lines']);
    json_out(array('ok' => $res['ok'], 'error' => $res['error'], 'lines' => $res['lines'], 'state' => $state));
  }

  if ($action === 'status') {
    $res = denon_tcp_send($ip, status_command_bundle(), 1150);
    $state = parse_denon_state($res['lines']);

    // Some firmware prefers ASCII NSA if UTF-8 NSE returns nothing.
    if ($res['ok'] && count($res['lines']) === 0) {
      $res2 = denon_tcp_send($ip, array('NSA'), 700);
      if (count($res2['lines']) > 0) {
        $res = $res2;
        $state = parse_denon_state($res['lines']);
      }
    }

    json_out(array('ok' => $res['ok'], 'error' => $res['error'], 'lines' => $res['lines'], 'state' => $state));
  }

  if ($action === 'command') {
    $command = normalize_command(isset($_POST['command']) ? (string)$_POST['command'] : '');
    $httpFallback = isset($_POST['httpFallback']) && (string)$_POST['httpFallback'] === '1';

    if (!is_allowed_denon_command($command)) {
      json_out(array('ok' => false, 'error' => 'Command not allowed: ' . $command));
    }

    if ($httpFallback) {
      $res = denon_http_fallback_command($ip, $command);
      json_out(array('ok' => $res['ok'], 'error' => $res['error'], 'lines' => $res['lines'], 'state' => parse_denon_state($res['lines'])));
    }

    $commands = array($command);

    // After changing visible state, ask for the updated state quickly.
    if ($command !== 'NSE' && $command !== 'NSA') {
      $commands[] = 'PW?';
      $commands[] = 'MV?';
      $commands[] = 'MU?';
      $commands[] = 'SI?';
      $commands[] = 'NSE';
    }

    $res = denon_tcp_send($ip, $commands, 950);
    $state = parse_denon_state($res['lines']);
    json_out(array('ok' => $res['ok'], 'error' => $res['error'], 'lines' => $res['lines'], 'state' => $state));
  }

  if ($action === 'volume_set') {
    $value = isset($_POST['value']) ? (int)$_POST['value'] : -1;
    $value = max(0, min(60, $value));
    $command = 'MV' . str_pad((string)$value, 2, '0', STR_PAD_LEFT);
    // PW? and SI? too: without them the display mirror cannot tell a network source from CD/tuner.
    $res = denon_tcp_send($ip, array($command, 'PW?', 'SI?', 'MV?', 'NSE'), 850);
    json_out(array('ok' => $res['ok'], 'error' => $res['error'], 'lines' => $res['lines'], 'state' => parse_denon_state($res['lines'])));
  }

  if ($action === 'favorite') {
    $value = isset($_POST['value']) ? (int)$_POST['value'] : 1;
    $value = max(1, min(50, $value));
    $command = 'FV' . str_pad((string)$value, 2, '0', STR_PAD_LEFT);
    $res = denon_tcp_send($ip, array($command, 'PW?', 'SI?', 'NSE'), 900);
    json_out(array('ok' => $res['ok'], 'error' => $res['error'], 'lines' => $res['lines'], 'state' => parse_denon_state($res['lines'])));
  }

  json_out(array('ok' => false, 'error' => 'Unknown action.'));
}

$configuredIp = current_denon_ip();
$sources = array(
  array('SICD', 'CD', 'optical disc'),
  array('SITUNER', 'Tuner', 'radio deck'),
  // On the CEOL, SIFM and SIAM act like SITUNER and only toggle the band; TMANFM/TMANAM switch to the
  // radio and set that band from any source (checked on the device).
  array('TMANFM', 'FM', 'radio, FM band'),
  array('TMANAM', 'AM', 'radio, AM band'),
  array('SIIRADIO', 'Internet Radio', 'stream matrix'),
  array('SISERVER', 'Server', 'media library'),
  array('SIUSB', 'USB', 'local port'),
  array('SIBLUETOOTH', 'Bluetooth', 'wireless link'),
  array('SIDIGITALIN1', 'Digital 1', 'optical/coax'),
  array('SIDIGITALIN2', 'Digital 2', 'optical/coax'),
  array('SIANALOGIN', 'Analog', 'line input')
);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= h(APP_TITLE) ?></title>
  <style>
    /* Light theme only (site rule): light ground, dark text, nothing dark anywhere. */
    :root {
      color-scheme: light;
      --panel: #ffffff;
      --panel-2: #f5f9fa;
      --line: rgba(13, 148, 136, 0.26);
      --cyan: #0d9488;
      --amber: #b45309;
      --red: #e11d48;
      --green: #16a34a;
      --text: #10212d;
      --muted: #5b6b78;
      --screen: #e8f6f3;
      --screen-text: #0f5f58;
      --button: linear-gradient(180deg, #ffffff, #edf3f6);
      --shadow: 0 1px 2px rgba(16, 33, 45, 0.06), 0 10px 28px rgba(16, 33, 45, 0.08);
      --radius: 26px;
      --vol: 0%;
    }

    * { box-sizing: border-box; }

    html, body { min-height: 100%; }

    body {
      margin: 0;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at 20% 20%, rgba(13, 148, 136, 0.07), transparent 30%),
        radial-gradient(circle at 82% 10%, rgba(37, 99, 235, 0.06), transparent 34%),
        linear-gradient(135deg, #f7fafc 0%, #eef3f7 100%);
      overflow-x: hidden;
    }

    body::before {
      content: "";
      position: fixed;
      inset: 0;
      pointer-events: none;
      background:
        linear-gradient(rgba(16, 33, 45, 0.035) 1px, transparent 1px),
        linear-gradient(90deg, rgba(16, 33, 45, 0.035) 1px, transparent 1px);
      background-size: 48px 48px;
      mask-image: radial-gradient(circle at center, rgba(0,0,0,0.9), transparent 76%);
    }

    button, input, select { font: inherit; }

    .wrap {
      width: 1440px; /* fixed design size: fitToWindow() zooms it to the window */
      margin: 0 auto;
      padding: 24px 0 36px;
      position: relative;
      z-index: 1;
    }

    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      margin-bottom: 20px;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .logo {
      width: 58px;
      height: 58px;
      border-radius: 18px;
      position: relative;
      background:
        radial-gradient(circle at 50% 50%, rgba(13, 148, 136, 0.85), rgba(8, 145, 178, 0.18) 38%, transparent 40%),
        conic-gradient(from 210deg, rgba(13, 148, 136, 0.85), rgba(37, 99, 235, 0.25), rgba(245, 158, 11, 0.55), rgba(13, 148, 136, 0.85));
      box-shadow: 0 4px 14px rgba(13, 148, 136, 0.22);
    }

    .logo::before, .logo::after {
      content: "";
      position: absolute;
      border: 1px solid rgba(255, 255, 255, 0.75);
      border-radius: 50%;
      inset: 11px;
    }

    .logo::after { inset: 21px; border-color: rgba(180, 83, 9, 0.5); }

    h1 {
      margin: 0;
      font-size: 42px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      line-height: 1;
    }

    .subtitle {
      margin-top: 7px;
      color: var(--muted);
      letter-spacing: 0.2em;
      text-transform: uppercase;
      font-size: 11px;
    }

    .top-actions {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
      flex-wrap: wrap;
    }

    .ip-pill, .status-pill {
      border: 1px solid var(--line);
      background: #ffffff;
      border-radius: 999px;
      padding: 10px 14px;
      color: var(--muted);
      font-size: 13px;
      letter-spacing: 0.04em;
    }

    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 9px;
    }

    .dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: var(--red);
      box-shadow: 0 0 6px rgba(225, 29, 72, 0.5);
    }

    .dot.online { background: var(--green); box-shadow: 0 0 6px rgba(22, 163, 74, 0.5); }


    .mini-remote {
      position: relative;
      display: grid;
      justify-items: center;
      gap: 18px;
      align-content: start;
      isolation: isolate;
      padding: 22px 18px 24px;
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background:
        radial-gradient(circle at 50% 0%, rgba(13, 148, 136, 0.08), transparent 42%),
        linear-gradient(180deg, #ffffff, #f1f6f8);
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    .mini-volume-chip {
      min-width: 48px;
      height: 34px;
      padding: 0 11px;
      border: 1px solid rgba(13, 148, 136, 0.34);
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 16px;
      font-weight: 800;
      letter-spacing: 0.08em;
      color: var(--text);
      background: #ffffff;
      pointer-events: none;
    }

    .mini-volume-chip::before {
      content: "VOL";
      margin-right: 7px;
      font-size: 9px;
      font-weight: 700;
      letter-spacing: 0.14em;
      color: var(--muted);
    }

    .mini-row {
      position: relative;
      z-index: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 18px;
      width: 100%;
    }

    .mini-display {
      position: relative;
      z-index: 1;
      width: min(390px, 92%);
      padding: 13px 15px;
      border: 1px solid var(--line);
      border-radius: 20px;
      background: var(--screen);
      box-shadow: inset 0 1px 3px rgba(16, 33, 45, 0.08);
      overflow: hidden;
    }

    .mini-display::before {
      content: "";
      position: absolute;
      inset: 0;
      pointer-events: none;
      background: repeating-linear-gradient(180deg, rgba(13, 148, 136, 0.10) 0, rgba(13, 148, 136, 0.10) 1px, transparent 1px, transparent 7px);
      opacity: 0.4;
    }

    .mini-display-lines {
      position: relative;
      z-index: 1;
      display: grid;
      gap: 5px;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 15px;
      line-height: 1.12;
      letter-spacing: 0.08em;
      color: var(--screen-text);
    }

    .mini-display-line {
      min-height: 1.12em;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      opacity: 0.98;
    }

    .mini-display-line.empty { opacity: 0.38; }

    .mini-btn {
      width: 74px;
      height: 74px;
      border: 1px solid rgba(16, 33, 45, 0.12);
      border-radius: 50%;
      color: var(--text);
      background: var(--button);
      box-shadow: inset 0 1px 0 #ffffff, 0 4px 12px rgba(16, 33, 45, 0.10);
      cursor: pointer;
      display: grid;
      place-items: center;
      transition: transform 0.12s ease, border-color 0.12s ease, box-shadow 0.12s ease, color 0.12s ease;
      -webkit-tap-highlight-color: transparent;
      user-select: none;
    }

    .mini-btn:hover {
      transform: translateY(-1px);
      border-color: rgba(13, 148, 136, 0.55);
      color: var(--cyan);
      box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.12), 0 4px 12px rgba(16, 33, 45, 0.10);
    }

    .mini-btn:active {
      transform: translateY(1px) scale(0.98);
      box-shadow: inset 0 2px 6px rgba(16, 33, 45, 0.12);
    }

    .mini-btn.active {
      border-color: rgba(180, 83, 9, 0.6);
      color: var(--amber);
      background: #fff7e8;
      box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
    }

    .mini-btn.power-active {
      border-color: rgba(22, 163, 74, 0.6);
      color: var(--green);
      background: #effaf2;
      box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.14);
    }

    .mini-btn.muted-active {
      border-color: rgba(225, 29, 72, 0.55);
      color: var(--red);
      background: #fff1f3;
      box-shadow: 0 0 0 3px rgba(225, 29, 72, 0.12);
    }

    .mini-btn svg {
      width: 36px;
      height: 36px;
      display: block;
      stroke: currentColor;
      fill: none;
      stroke-width: 1.85;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .mini-btn.mini-source svg { width: 34px; height: 34px; }

    .mini-pad {
      position: relative;
      z-index: 1;
      width: 286px;
      height: 286px;
      border-radius: 50%;
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      grid-template-rows: 1fr 1fr 1fr;
      gap: 10px;
      padding: 14px;
      background:
        radial-gradient(circle at 50% 50%, rgba(13, 148, 136, 0.06), transparent 52%),
        linear-gradient(180deg, #f7fafc, #e9f0f4);
      border: 1px solid rgba(13, 148, 136, 0.18);
      box-shadow: inset 0 2px 10px rgba(16, 33, 45, 0.07);
    }

    .mini-pad .mini-btn {
      width: 100%;
      height: 100%;
      border-radius: 34px;
    }

    .mini-pad .mini-up { grid-column: 2; grid-row: 1; }
    .mini-pad .mini-left { grid-column: 1; grid-row: 2; }
    .mini-pad .mini-enter { grid-column: 2; grid-row: 2; border-radius: 50%; }
    .mini-pad .mini-right { grid-column: 3; grid-row: 2; }
    .mini-pad .mini-down { grid-column: 2; grid-row: 3; }

    .mini-enter svg {
      width: 42px;
      height: 42px;
      stroke-width: 1.65;
    }

    .deck {
      display: grid;
      grid-template-columns: 1.05fr 1.5fr 1.05fr;
      gap: 18px;
      align-items: stretch;
    }

    /* Two rows: the left and middle panels share the first one, so their bottoms line up, and the
       micro-remote boxes below them share the second one, so their tops line up and they reach the
       bottom of the right panel, which spans both rows. The column wrappers are dissolved
       (display: contents) so that their panel and box join the deck's grid directly. */
    .deck {
      grid-template-rows: auto 1fr;
      grid-auto-flow: column;
    }

    .deck-column { display: contents; }

    .deck > .panel { grid-row: span 2; }

    .panel {
      position: relative;
      border: 1px solid var(--line);
      background:
        linear-gradient(145deg, rgba(13, 148, 136, 0.04), transparent 30%),
        var(--panel);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    .panel::before {
      content: "";
      position: absolute;
      inset: 0;
      pointer-events: none;
      background: linear-gradient(90deg, transparent, rgba(13, 148, 136, 0.06), transparent);
      transform: translateX(-120%);
      animation: sweep 7s linear infinite;
      opacity: 0.35;
    }

    @keyframes sweep {
      0% { transform: translateX(-120%); }
      55%, 100% { transform: translateX(120%); }
    }

    .panel-inner { position: relative; z-index: 1; padding: 20px; }

    .section-title {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin-bottom: 14px;
      color: var(--text);
      text-transform: uppercase;
      letter-spacing: 0.16em;
      font-size: 12px;
      font-weight: 800;
    }

    .panel-code {
      color: var(--cyan);
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 11px;
      opacity: 0.9;
    }

    .source-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .source-btn, .cmd-btn, .ghost-btn, .danger-btn, .power-btn, .round-btn {
      border: 1px solid var(--line);
      color: var(--text);
      background: var(--button);
      box-shadow: inset 0 1px 0 #ffffff, 0 2px 6px rgba(16, 33, 45, 0.07);
      cursor: pointer;
      transition: transform 0.12s ease, border-color 0.12s ease, box-shadow 0.12s ease, color 0.12s ease;
      user-select: none;
    }

    .source-btn:hover, .cmd-btn:hover, .ghost-btn:hover, .danger-btn:hover, .power-btn:hover, .round-btn:hover {
      transform: translateY(-1px);
      border-color: rgba(13, 148, 136, 0.6);
      box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.12), 0 2px 6px rgba(16, 33, 45, 0.07);
    }

    .source-btn:active, .cmd-btn:active, .ghost-btn:active, .danger-btn:active, .power-btn:active, .round-btn:active {
      transform: translateY(1px) scale(0.99);
    }

    .source-btn {
      padding: 14px 13px;
      border-radius: 18px;
      min-height: 72px;
      text-align: left;
    }

    .source-btn strong {
      display: block;
      font-size: 15px;
      letter-spacing: 0.04em;
    }

    .source-btn span {
      display: block;
      margin-top: 4px;
      color: var(--muted);
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.12em;
    }

    .source-btn.active {
      border-color: rgba(180, 83, 9, 0.6);
      color: #92400e;
      background: #fff7e8;
      box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
    }

    .display-panel { min-height: 460px; }

    .oled {
      position: relative;
      min-height: 280px;
      border-radius: 24px;
      padding: 24px 22px;
      background:
        radial-gradient(circle at 50% 0%, rgba(13, 148, 136, 0.08), transparent 48%),
        var(--screen);
      border: 1px solid rgba(13, 148, 136, 0.35);
      box-shadow: inset 0 1px 4px rgba(16, 33, 45, 0.08);
      overflow: hidden;
    }

    .oled::before {
      content: "";
      position: absolute;
      inset: 0;
      background: repeating-linear-gradient(0deg, rgba(13, 148, 136, 0.05), rgba(13, 148, 136, 0.05) 1px, transparent 1px, transparent 7px);
      pointer-events: none;
    }

    .display-lines {
      position: relative;
      z-index: 1;
      display: grid;
      gap: 10px;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    }

    .display-line {
      min-height: 22px;
      color: var(--screen-text);
      font-size: 19px;
      letter-spacing: 0.05em;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .display-line.empty { opacity: 0.35; }

    .state-row {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
      margin-top: 14px;
    }

    .state-card {
      border: 1px solid var(--line);
      background: var(--panel-2);
      border-radius: 18px;
      padding: 14px 12px;
      min-height: 70px;
    }

    .state-card small {
      display: block;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.14em;
      font-size: 10px;
      margin-bottom: 7px;
    }

    .state-card b {
      display: block;
      font-size: 17px;
      font-weight: 800;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .volume-stage {
      display: grid;
      place-items: center;
      padding: 4px 0 10px;
    }

    .volume-ring {
      width: 290px;
      aspect-ratio: 1;
      border-radius: 50%;
      position: relative;
      display: grid;
      place-items: center;
      background:
        radial-gradient(circle, #ffffff 0 49%, transparent 50%),
        conic-gradient(var(--cyan) 0 var(--vol), #dde8ec var(--vol) 100%);
      box-shadow: var(--shadow);
      border: 1px solid var(--line);
    }

    .volume-ring::before {
      content: "";
      position: absolute;
      inset: 18px;
      border-radius: 50%;
      border: 1px solid rgba(16, 33, 45, 0.06);
      background:
        radial-gradient(circle at 50% 30%, rgba(13, 148, 136, 0.05), transparent 25%),
        linear-gradient(180deg, #ffffff, #f2f7f9);
      box-shadow: inset 0 1px 6px rgba(16, 33, 45, 0.06);
    }

    .volume-readout {
      position: relative;
      z-index: 1;
      text-align: center;
    }

    .volume-readout .num {
      display: block;
      font-size: 86px;
      line-height: 0.9;
      font-weight: 900;
      letter-spacing: -0.08em;
      color: var(--text);
    }

    .volume-readout .unit {
      display: block;
      margin-top: 6px;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.22em;
      font-size: 11px;
    }

    .volume-controls {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-top: 16px;
    }

    .round-btn {
      border-radius: 24px;
      min-height: 76px;
      font-size: 34px;
      font-weight: 900;
    }

    .slider-wrap {
      margin-top: 16px;
      border: 1px solid var(--line);
      background: var(--panel-2);
      border-radius: 20px;
      padding: 15px;
    }

    input[type="range"] {
      width: 100%;
      accent-color: var(--cyan);
    }

    .power-grid, .transport-grid, .tuner-grid, .utility-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .transport-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .utility-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top: 12px; }

    .cmd-btn, .ghost-btn, .danger-btn, .power-btn {
      min-height: 50px;
      border-radius: 17px;
      padding: 12px 12px;
      font-weight: 800;
      letter-spacing: 0.05em;
    }

    .power-btn.on { border-color: rgba(22, 163, 74, 0.55); }
    .power-btn.off, .danger-btn { border-color: rgba(225, 29, 72, 0.45); }
    .ghost-btn { color: var(--muted); }

    .field-row {
      display: flex;
      gap: 10px;
      align-items: center;
      margin-top: 12px;
    }

    .field-row input {
      flex: 1;
      min-width: 0;
      border: 1px solid var(--line);
      border-radius: 16px;
      background: #ffffff;
      color: var(--text);
      padding: 13px 14px;
      outline: none;
    }

    .field-row input:focus { border-color: rgba(13, 148, 136, 0.6); box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.14); }

    .log {
      max-height: 190px;
      overflow: auto;
      border-radius: 18px;
      border: 1px solid var(--line);
      background: var(--panel-2);
      padding: 12px;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 12px;
      color: #134e4a;
      white-space: pre-wrap;
    }

    .note {
      color: var(--muted);
      line-height: 1.45;
      font-size: 13px;
    }

    .modal {
      position: fixed;
      inset: 0;
      z-index: 50;
      display: <?= $configuredIp === '' ? 'grid' : 'none' ?>;
      place-items: center;
      padding: 20px;
      background: rgba(243, 246, 249, 0.82);
      backdrop-filter: blur(8px);
    }

    .modal-card {
      width: min(620px, 100%);
      border-radius: 30px;
      border: 1px solid var(--line);
      background:
        radial-gradient(circle at 50% 0%, rgba(13, 148, 136, 0.08), transparent 38%),
        #ffffff;
      box-shadow: 0 24px 70px rgba(16, 33, 45, 0.18);
      padding: 28px;
      position: relative;
      overflow: hidden;
    }

    .modal-card h2 {
      margin: 0 0 10px;
      font-size: clamp(26px, 4vw, 42px);
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }

    .modal-card p {
      color: var(--muted);
      line-height: 1.55;
      margin: 0 0 18px;
    }

    .connect-form {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 10px;
    }

    .connect-form input {
      width: 100%;
      border: 1px solid var(--line);
      background: #ffffff;
      color: var(--text);
      border-radius: 18px;
      padding: 16px 15px;
      outline: none;
      font-size: 18px;
      letter-spacing: 0.03em;
    }

    .connect-form button {
      border: 1px solid rgba(13, 148, 136, 0.55);
      background: #d5f3ee;
      color: var(--screen-text);
      border-radius: 18px;
      padding: 14px 18px;
      cursor: pointer;
      font-weight: 900;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .error-box {
      display: none;
      margin-top: 14px;
      border: 1px solid rgba(225, 29, 72, 0.35);
      background: #fff1f3;
      color: #9f1239;
      padding: 12px 14px;
      border-radius: 16px;
    }

    .switch-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-top: 12px;
      color: var(--muted);
      font-size: 13px;
    }

    .switch-row input { accent-color: var(--cyan); }

    .mt { margin-top: 18px; }
    .mb { margin-bottom: 18px; }

    .tabbar { display: none; }

    /* Phone layout (designed for the iPhone 14: 390 x 844, about 390 x 660 inside Safari): the same
       controls, one section per tab, with a tab bar at the bottom. Elements carry data-tab with the
       tab(s) they belong to; <body data-tab> is the tab on screen. */
    @media (max-width: 600px), (max-height: 500px) { /* same query as PHONE_LAYOUT in the script */
      .wrap {
        width: auto;
        padding: calc(8px + env(safe-area-inset-top)) 12px calc(76px + env(safe-area-inset-bottom));
      }

      body[data-tab="remote"] [data-tab]:not([data-tab~="remote"]),
      body[data-tab="sources"] [data-tab]:not([data-tab~="sources"]),
      body[data-tab="browse"] [data-tab]:not([data-tab~="browse"]),
      body[data-tab="volume"] [data-tab]:not([data-tab~="volume"]),
      body[data-tab="tools"] [data-tab]:not([data-tab~="tools"]) { display: none; }

      button { touch-action: manipulation; } /* no double-tap zoom on fast repeated taps */

      /* Top bar: one compact line (IP and Change IP appear in the Tools tab only). */
      .topbar { flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
      .brand { gap: 9px; }
      .logo { width: 30px; height: 30px; border-radius: 9px; }
      .logo::before { inset: 6px; }
      .logo::after { inset: 11px; }
      h1 { font-size: 12px; letter-spacing: 0.04em; }
      .subtitle { display: none; }
      .top-actions { gap: 6px; margin-left: auto; }
      .ip-pill, .status-pill { padding: 6px 10px; font-size: 12px; }
      .top-actions .ghost-btn { min-height: 0; padding: 6px 10px; font-size: 12px; border-radius: 999px; }

      .deck { display: flex; flex-direction: column; gap: 12px; }
      .panel-inner { padding: 14px; }
      .section-title { margin-bottom: 10px; font-size: 11px; }
      .mt { margin-top: 14px; }
      .cmd-btn, .ghost-btn, .power-btn { min-height: 44px; padding: 10px 8px; border-radius: 14px; }
      .power-grid, .transport-grid, .tuner-grid, .utility-grid { gap: 8px; }

      /* Remote */
      .mini-remote { padding: 14px 12px; gap: 12px; }
      .mini-row { gap: 12px; }
      .mini-btn { width: 54px; height: 54px; }
      .mini-btn svg, .mini-btn.mini-source svg { width: 28px; height: 28px; }
      .mini-display { width: 100%; padding: 11px 13px; border-radius: 16px; }
      .mini-pad { width: 220px; height: 220px; gap: 8px; padding: 11px; }
      .mini-enter svg { width: 32px; height: 32px; }

      /* Sources: three per row */
      .source-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
      .source-btn { min-height: 58px; padding: 9px; border-radius: 14px; }
      .source-btn strong { font-size: 13px; letter-spacing: 0.02em; }
      .source-btn span { font-size: 9px; letter-spacing: 0.08em; }

      /* Browse */
      .display-panel { min-height: 0; }
      .oled { min-height: 0; padding: 12px 14px; border-radius: 16px; }
      .display-lines { gap: 4px; }
      .display-line { min-height: 16px; font-size: 13px; }
      .state-row { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; margin-top: 10px; }
      .state-card { min-height: 0; padding: 8px; border-radius: 12px; }
      .state-card small { margin-bottom: 4px; font-size: 8px; }
      .state-card b { font-size: 12px; }

      /* Volume */
      .volume-stage { padding: 0 0 4px; }
      .volume-ring { width: 180px; }
      .volume-ring::before { inset: 14px; }
      .volume-readout .num { font-size: 58px; }
      .volume-controls { gap: 10px; margin-top: 10px; }
      .round-btn { min-height: 52px; border-radius: 16px; font-size: 28px; }
      .slider-wrap { margin-top: 10px; padding: 10px 14px; border-radius: 16px; }
      .field-row { margin-top: 8px; }
      .field-row input { padding: 10px 12px; border-radius: 14px; }

      /* Tools: Diagnostics is the first block of the tab, so no top margin */
      .panel-inner > .mt[data-tab="tools"] { margin-top: 0; }
      .log { max-height: 280px; }

      .tabbar {
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 20;
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        padding: 6px 6px calc(6px + env(safe-area-inset-bottom));
        border-top: 1px solid var(--line);
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
      }

      .tabbar button {
        display: grid;
        justify-items: center;
        gap: 3px;
        padding: 6px 0;
        border: 0;
        background: none;
        color: var(--muted);
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.04em;
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;
      }

      .tabbar button.active { color: var(--cyan); }

      .tabbar svg {
        width: 24px;
        height: 24px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
      }
    }

    /* Phone held sideways (iPhone 14: 844 x 390, about 844 x 340 inside Safari): the tab bar becomes
       a rail on the left and each tab's content is split in two columns to fit the low screen. */
    @media (max-height: 500px) {
      .wrap {
        padding: 6px calc(12px + env(safe-area-inset-right)) 8px calc(80px + env(safe-area-inset-left));
      }

      .topbar { margin-bottom: 8px; }
      .logo { width: 26px; height: 26px; }

      .tabbar {
        top: 0;
        right: auto;
        width: calc(68px + env(safe-area-inset-left));
        grid-template-columns: 1fr;
        align-content: center;
        gap: 4px;
        padding: 8px 4px 8px calc(4px + env(safe-area-inset-left));
        border-top: 0;
        border-right: 1px solid var(--line);
      }

      /* The visible blocks of a tab stand side by side, sharing the width. */
      .deck { flex-direction: row; align-items: stretch; }
      .deck > *, .deck-column > * { flex: 1; min-width: 0; }

      /* Remote: buttons and mini display | pad with the mute button beside it */
      .mini-remote { align-content: center; }
      .mini-remote:has(.mini-pad) { grid-auto-flow: column; justify-content: center; align-items: center; gap: 18px; }
      .mini-pad { width: 200px; height: 200px; }

      /* Sources: six per row, power controls on one row */
      .source-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
      .source-btn { min-height: 52px; }
      .power-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
      .mt { margin-top: 10px; }

      /* Browse: display and status | navigation */
      .display-panel .panel-inner {
        display: grid;
        grid-template-columns: 1.15fr 1fr;
        column-gap: 16px;
        align-content: start;
      }
      .display-panel .panel-inner > * { grid-column: 1; }
      .display-panel .panel-inner > .mt { grid-column: 2; grid-row: 1 / span 3; margin-top: 0; }
      .oled { padding: 10px 12px; }
      .display-lines { gap: 3px; }
      .display-line { min-height: 14px; font-size: 12px; }
      .transport-grid .cmd-btn { min-height: 40px; }

      /* Volume: ring and -/+ | slider and tuner */
      .panel[data-tab~="volume"] .panel-inner {
        display: grid;
        grid-template-columns: 1fr 1.2fr;
        grid-template-areas: "title slider" "stage tuner" "controls tuner";
        column-gap: 16px;
        align-items: start;
      }
      .panel-inner > .section-title[data-tab="volume"] { grid-area: title; }
      .volume-stage { grid-area: stage; }
      .volume-controls { grid-area: controls; }
      .slider-wrap { grid-area: slider; margin-top: 0; }
      .panel-inner > .mt[data-tab="volume"] { grid-area: tuner; }
      .volume-ring { width: 140px; }
      .volume-readout .num { font-size: 46px; }

      /* Tools: buttons and manual command | log */
      .panel-inner > .mt[data-tab="tools"] {
        grid-column: 1 / -1;
        display: grid;
        grid-template-columns: 1fr 1.2fr;
        grid-template-areas: "title log" "util log" "field log";
        column-gap: 16px;
        align-content: start;
      }
      .panel-inner > .mt[data-tab="tools"] > .section-title { grid-area: title; }
      .utility-grid { grid-area: util; margin-top: 0; }
      .panel-inner > .mt[data-tab="tools"] > .field-row { grid-area: field; }
      .log { grid-area: log; height: 250px; max-height: 250px; margin-top: 0; }
    }
  </style>
</head>
<body data-tab="remote">
  <div class="modal" id="ipModal">
    <div class="modal-card">
      <div class="logo mb"></div>
      <h2>Link the CEOL</h2>
      <p>Enter the Denon IP address. This PHP file must run on a computer/server inside the same LAN as the Denon, because PHP opens the TCP/IP socket to the device.</p>
      <div class="connect-form">
        <input type="text" id="denonIpInput" placeholder="192.168.1.45" value="<?= h($configuredIp) ?>" inputmode="numeric" autocomplete="off">
        <button type="button" onclick="saveIp()">Connect</button>
      </div>
      <div class="error-box" id="ipError"></div>
      <p class="note mt">On the Denon, enable <b>Network Control</b>. Without it, standby wake/control can fail.</p>
    </div>
  </div>

  <!-- Shown while another page holds the session (the Denon accepts one connection at a time). -->
  <div class="modal" id="busyModal" style="display: none">
    <div class="modal-card">
      <div class="logo mb"></div>
      <h2>CEOL busy</h2>
      <p>A session is already active on another device.</p>
      <p class="note">This page connects by itself as soon as that page is closed or hidden.</p>
    </div>
  </div>

  <main class="wrap">
    <div class="topbar">
      <div class="brand">
        <div class="logo"></div>
        <div>
          <h1><?= h(APP_TITLE) ?></h1>
          <div class="subtitle">Denon CEOL / RCD-N9 TCP/IP Remote</div>
        </div>
      </div>
      <div class="top-actions">
        <div class="status-pill"><span class="dot" id="connDot"></span><span id="connText">Offline</span></div>
        <div class="ip-pill" data-tab="tools">IP <b id="ipText"><?= h($configuredIp !== '' ? $configuredIp : 'not set') ?></b></div>
        <button class="ghost-btn" type="button" data-tab="tools" onclick="showIpModal()">Change IP</button>
      </div>
    </div>

    <div class="deck">
      <div class="deck-column">
      <section class="panel" data-tab="sources">
        <div class="panel-inner">
          <div class="section-title"><span>Input Matrix</span><span class="panel-code">SI BUS</span></div>
          <div class="source-grid" id="sourceGrid">
            <?php foreach ($sources as $src): ?>
              <button type="button" class="source-btn" data-command="<?= h($src[0]) ?>" onclick="cmd('<?= h($src[0]) ?>')">
                <strong><?= h($src[1]) ?></strong>
                <span><?= h($src[2]) ?></span>
              </button>
            <?php endforeach; ?>
          </div>

          <div class="mt">
            <div class="section-title"><span>Power Core</span><span class="panel-code">PW / MU</span></div>
            <div class="power-grid">
              <button class="power-btn on" type="button" onclick="cmd('PWON')">Power On</button>
              <button class="power-btn off" type="button" onclick="cmd('PWSTANDBY')">Standby</button>
              <button class="cmd-btn" type="button" onclick="setMuteFromMain(true)">Mute</button>
              <button class="cmd-btn" type="button" onclick="setMuteFromMain(false)">Unmute</button>
            </div>
          </div>

          <div class="switch-row">
            <label><input type="checkbox" id="httpFallback"> Use HTTP goform fallback for commands</label>
          </div>
        </div>
      </section>
      <section class="mini-remote" data-tab="remote" aria-label="Simplified Denon remote: power, sources and mini display">
      <div class="mini-row">
        <button class="mini-btn" id="miniPower" type="button" aria-label="Toggle power" title="Toggle power" onclick="togglePower()">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v8"></path><path d="M7.05 6.6a8 8 0 1 0 9.9 0"></path></svg>
        </button>
        <button class="mini-btn mini-source" id="miniServer" type="button" aria-label="Server source" title="Server" onclick="cmd('SISERVER')">
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="5" rx="1.6"></rect><rect x="5" y="10.5" width="14" height="5" rx="1.6"></rect><rect x="5" y="17" width="14" height="3" rx="1.4"></rect><path d="M8 6.5h.01M8 13h.01"></path><path d="M12 20v1.2"></path></svg>
        </button>
        <button class="mini-btn mini-source" id="miniBt" type="button" aria-label="Bluetooth source" title="Bluetooth" onclick="cmd('SIBLUETOOTH')">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6l8 6-8 6V6z"></path><path d="M8 6l8-3v18l-8-3"></path><path d="M4 8l4 4-4 4"></path></svg>
        </button>
        <div class="mini-volume-chip" id="miniVolumeNum" aria-label="Volume">--</div>
      </div>

      <div class="mini-display" aria-label="Mini display rows 1, 2, 5 and 6">
        <div class="mini-display-lines" id="miniDisplayLines">
          <div class="mini-display-line empty">····················</div>
          <div class="mini-display-line empty">····················</div>
          <div class="mini-display-line empty">····················</div>
          <div class="mini-display-line empty">····················</div>
        </div>
      </div>
      </section>
      </div>

      <div class="deck-column">
      <section class="panel display-panel" data-tab="browse">
        <div class="panel-inner">
          <div class="section-title"><span>Front Display Mirror</span><span class="panel-code">NSE / NSA</span></div>
          <div class="oled">
            <div class="display-lines" id="displayLines">
              <?php for ($i = 0; $i < 9; $i++): ?>
                <div class="display-line empty">····················</div>
              <?php endfor; ?>
            </div>
          </div>

          <div class="state-row">
            <div class="state-card"><small>Power</small><b id="powerText">Unknown</b></div>
            <div class="state-card"><small>Source</small><b id="sourceText">Unknown</b></div>
            <div class="state-card"><small>Mute</small><b id="muteText">Unknown</b></div>
            <div class="state-card"><small>Tuner</small><b id="tunerText">—</b></div>
          </div>

          <div class="mt">
            <div class="section-title"><span>Navigation / Transport</span><span class="panel-code">NS BUS</span></div>
            <div class="transport-grid">
              <button class="cmd-btn" type="button" onclick="cmd('NS90')">▲ Up</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS94')">Enter</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS91')">▼ Down</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS92')">◀ Left</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS9A')">Play</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS93')">Right ▶</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS9E')">Prev</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS9B')">Pause</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS9D')">Next</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS9X')">Page +</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS9C')">Stop</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS9Y')">Page -</button>
            </div>
          </div>
        </div>
      </section>
      <section class="mini-remote" data-tab="remote" aria-label="Simplified Denon remote: volume, navigation and mute">
      <div class="mini-pad" aria-label="Volume and navigation pad">
        <button class="mini-btn mini-up" id="miniVolUp" type="button" aria-label="Volume up" title="Volume up">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
        </button>
        <button class="mini-btn mini-left" id="miniPrev" type="button" aria-label="Previous" title="Previous">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 6l-6 6 6 6"></path><path d="M19 6l-6 6 6 6"></path></svg>
        </button>
        <button class="mini-btn mini-enter" type="button" aria-label="Enter" title="Enter" onclick="cmd('NS94')">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="6.5"></circle><circle cx="12" cy="12" r="1"></circle></svg>
        </button>
        <button class="mini-btn mini-right" id="miniNext" type="button" aria-label="Next" title="Next">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6l6 6-6 6"></path><path d="M13 6l6 6-6 6"></path></svg>
        </button>
        <button class="mini-btn mini-down" id="miniVolDown" type="button" aria-label="Volume down" title="Volume down">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"></path></svg>
        </button>
      </div>

      <div class="mini-row">
        <button class="mini-btn" id="miniMute" type="button" aria-label="Mute" title="Mute" onclick="toggleMute()" data-muted="0">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10v4h4l5 4V6l-5 4H4z"></path><path d="M16.5 9.5a4 4 0 0 1 0 5"></path><path d="M19 7a7 7 0 0 1 0 10"></path></svg>
        </button>
      </div>
      </section>
      </div>

      <section class="panel" data-tab="volume tools">
        <div class="panel-inner">
          <div class="section-title" data-tab="volume"><span>Amplitude Drive</span><span class="panel-code">MV 00-60</span></div>
          <div class="volume-stage" data-tab="volume">
            <div class="volume-ring" id="volumeRing">
              <div class="volume-readout">
                <span class="num" id="volumeNum">--</span>
                <span class="unit">Volume</span>
              </div>
            </div>
          </div>

          <div class="volume-controls" data-tab="volume">
            <button class="round-btn" type="button" id="volDown">−</button>
            <button class="round-btn" type="button" id="volUp">+</button>
          </div>

          <div class="slider-wrap" data-tab="volume">
            <input type="range" min="0" max="60" value="0" id="volumeSlider">
          </div>

          <div class="mt" data-tab="volume">
            <div class="section-title"><span>Tuner / Channels</span><span class="panel-code">TF / FV</span></div>
            <div class="tuner-grid">
              <button class="cmd-btn" type="button" onclick="cmd('TFANDOWN')">Tune / Ch −</button>
              <button class="cmd-btn" type="button" onclick="cmd('TFANUP')">Tune / Ch +</button>
              <button class="cmd-btn" type="button" onclick="cmd('TMANFM')">FM Band</button>
              <button class="cmd-btn" type="button" onclick="cmd('TMANAM')">AM Band</button>
            </div>
            <div class="field-row">
              <input type="number" min="1" max="50" value="1" id="favoriteNo" placeholder="Favorite 1-50">
              <button class="cmd-btn" type="button" onclick="favoriteGo()">FV</button>
            </div>
          </div>

          <div class="mt" data-tab="tools">
            <div class="section-title"><span>Diagnostics</span><span class="panel-code">RAW</span></div>
            <div class="utility-grid">
              <button class="ghost-btn" type="button" onclick="refreshStatus(true)">Refresh</button>
              <button class="ghost-btn" type="button" onclick="cmd('NSE')">Display</button>
              <button class="ghost-btn" type="button" onclick="cmd('NSINF?')">Network</button>
              <button class="ghost-btn" type="button" onclick="testConnection()">Test</button>
            </div>
            <div class="field-row">
              <input type="text" id="manualCommand" placeholder="Allowed command, e.g. MV20 or SIIRADIO">
              <button class="cmd-btn" type="button" onclick="sendManual()">Send</button>
            </div>
            <div class="log mt" id="logBox">Boot sequence ready.</div>
          </div>
        </div>
      </section>
    </div>
  </main>

  <!-- Phone layout only (max-width: 600px): one section per tab. -->
  <nav class="tabbar" aria-label="Sections">
    <button type="button" class="active" data-goto="remote"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"></circle><circle cx="12" cy="12" r="2.5"></circle><path d="M12 5.5v2M12 16.5v2M5.5 12h2M16.5 12h2"></path></svg><span>Remote</span></button>
    <button type="button" data-goto="sources"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="7" height="7" rx="1.5"></rect><rect x="13" y="4" width="7" height="7" rx="1.5"></rect><rect x="4" y="13" width="7" height="7" rx="1.5"></rect><rect x="13" y="13" width="7" height="7" rx="1.5"></rect></svg><span>Sources</span></button>
    <button type="button" data-goto="browse"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="13" rx="2"></rect><path d="M7 8.5h10M7 12.5h6M9 21h6M12 17v4"></path></svg><span>Browse</span></button>
    <button type="button" data-goto="volume"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10v4h4l5 4V6l-5 4H4z"></path><path d="M16.5 9.5a4 4 0 0 1 0 5"></path><path d="M19 7a7 7 0 0 1 0 10"></path></svg><span>Volume</span></button>
    <button type="button" data-goto="tools"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M7 9l3 3-3 3M12 15h5"></path></svg><span>Tools</span></button>
  </nav>

  <script>
    let polling = null;
    let busy = false;
    let statusInFlight = false;

    // Identifies this page (each tab, each device) for the one-session-at-a-time rule on the server.
    const PAGE_ID = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
    let lastKnownVolume = null;
    let lastKnownPower = 'unknown';
    let lastKnownMute = null;

    function qs(id) {
      return document.getElementById(id);
    }

    function setLog(text, append = true) {
      const box = qs('logBox');
      const stamp = new Date().toLocaleTimeString();
      const line = '[' + stamp + '] ' + text;
      box.textContent = append ? (line + '\n' + box.textContent).slice(0, 6000) : line;
    }

    async function api(action, data = {}) {
      const form = new FormData();
      form.append('ajax', '1');
      form.append('action', action);
      form.append('page', PAGE_ID);
      Object.keys(data).forEach(key => form.append(key, data[key]));

      const res = await fetch(location.href, {
        method: 'POST',
        body: form,
        credentials: 'same-origin'
      });

      // Parse by hand so that a reply that is not JSON is reported with what it actually contained.
      const text = await res.text();
      try {
        return JSON.parse(text);
      } catch (err) {
        throw new Error(action + ': HTTP ' + res.status + ', the reply is not JSON (' + (text === '' ? 'empty' : text.slice(0, 200)) + ')');
      }
    }

    function showIpModal() {
      qs('ipModal').style.display = 'grid';
      qs('denonIpInput').focus();
    }

    function hideIpModal() {
      qs('ipModal').style.display = 'none';
    }

    async function saveIp() {
      const ip = qs('denonIpInput').value.trim();
      qs('ipError').style.display = 'none';

      let json;
      try {
        json = await api('save_ip', { ip });
      } catch (err) {
        json = { ok: false, error: 'Cannot save the IP: ' + err.message };
      }
      if (!json.ok) {
        qs('ipError').textContent = json.error || 'Cannot save IP.';
        qs('ipError').style.display = 'block';
        return;
      }

      qs('ipText').textContent = json.ip;
      hideIpModal();
      setLog('IP configured: ' + json.ip);
      await testConnection();
      startPolling();
    }

    async function testConnection() {
      setConnection(false, 'Testing');
      const json = await api('test');
      handleResponse(json, 'PW?');
      return json.ok;
    }

    function setConnection(online, text) {
      qs('connDot').classList.toggle('online', !!online);
      qs('connText').textContent = text || (online ? 'Online' : 'Offline');
    }



    const miniMuteLiveIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10v4h4l5 4V6l-5 4H4z"></path><path d="M16.5 9.5a4 4 0 0 1 0 5"></path><path d="M19 7a7 7 0 0 1 0 10"></path></svg>';
    const miniMuteOffIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10v4h4l5 4V6l-5 4H4z"></path><path d="M17 9l4 6"></path><path d="M21 9l-4 6"></path></svg>';

    function setMiniMuteVisual(isMuted) {
      const btn = qs('miniMute');
      btn.classList.toggle('muted-active', isMuted === true);
      btn.dataset.muted = isMuted === true ? '1' : '0';
      btn.innerHTML = isMuted === true ? miniMuteOffIcon : miniMuteLiveIcon;
      btn.setAttribute('aria-label', isMuted === true ? 'Unmute' : 'Mute');
      btn.title = isMuted === true ? 'Unmute' : 'Mute';
    }

    function togglePower() {
      cmd(lastKnownPower === 'on' ? 'PWSTANDBY' : 'PWON');
    }

    function toggleMute() {
      const targetMuted = lastKnownMute === true ? false : true;
      setMuteVisualEverywhere(targetMuted);
      cmd(targetMuted ? 'MUON' : 'MUOFF');
    }

    function setMuteVisualEverywhere(isMuted) {
      lastKnownMute = isMuted;
      setMiniMuteVisual(isMuted);
      qs('muteText').textContent = isMuted === true ? 'Muted' : 'Live';
    }

    function setMuteFromMain(isMuted) {
      setMuteVisualEverywhere(isMuted);
      cmd(isMuted ? 'MUON' : 'MUOFF');
    }

    function setVolumeVisualEverywhere(vol, percent = null) {
      if (vol === null || vol === undefined || Number.isNaN(Number(vol))) return;

      const n = Math.max(0, Math.min(60, parseInt(vol, 10)));
      lastKnownVolume = n;
      const padded = String(n).padStart(2, '0');

      qs('volumeNum').textContent = padded;
      qs('miniVolumeNum').textContent = padded;
      qs('volumeSlider').value = n;

      const ringPercent = percent !== null && percent !== undefined
        ? percent
        : Math.max(0, Math.min(100, Math.round((n / 60) * 100)));
      qs('volumeRing').style.setProperty('--vol', ringPercent + '%');
    }

    function nudgeVolumeVisual(delta) {
      if (lastKnownVolume === null || lastKnownVolume === undefined || Number.isNaN(Number(lastKnownVolume))) return;
      setVolumeVisualEverywhere(Number(lastKnownVolume) + delta);
    }

    async function cmd(command) {
      if (busy) return;
      busy = true;
      setLog('TX ' + command);
      if (command === 'MVUP') nudgeVolumeVisual(1);
      if (command === 'MVDOWN') nudgeVolumeVisual(-1);

      try {
        const json = await api('command', {
          command,
          httpFallback: qs('httpFallback').checked ? '1' : '0'
        });
        handleResponse(json, command);
      } catch (err) {
        setConnection(false, 'PHP error');
        setLog('ERROR ' + err.message);
      } finally {
        busy = false;
      }
    }

    function handleResponse(json, label) {
      // Another page holds the session: say so; the polling keeps asking until it is free.
      qs('busyModal').style.display = json.busy === true ? 'grid' : 'none';
      if (json.busy === true) {
        setConnection(false, 'Busy');
        return;
      }

      if (!json.ok) {
        setConnection(false, 'Error');
        setLog('FAIL ' + (label || '') + ': ' + (json.error || 'unknown error'));
        return;
      }

      setConnection(true, 'Online');

      if (json.state) {
        renderState(json.state);
      }

      if (json.lines && json.lines.length) {
        setLog('RX ' + json.lines.join(' | '));
      } else {
        setLog('RX ok, no returned lines');
      }
    }

    async function refreshStatus(manual = false) {
      if (busy && !manual) return;
      // Never stack polls: while the Denon is shared with another client a reply can take longer
      // than the 3-second interval, and queued polls would only make it worse.
      if (statusInFlight) return;
      statusInFlight = true;
      try {
        const json = await api('status');
        handleResponse(json, 'STATUS');
      } catch (err) {
        // Log the reason when the link drops, not again on every 3-second poll while it stays down.
        if (manual || qs('connText').textContent !== 'Offline') setLog('STATUS ERROR ' + err.message);
        setConnection(false, 'Offline');
      } finally {
        statusInFlight = false;
      }
    }

    function renderState(state) {
      qs('powerText').textContent = state.powerLabel || 'Unknown';
      qs('sourceText').textContent = state.sourceLabel || 'Unknown';
      qs('muteText').textContent = state.muteLabel || 'Unknown';

      lastKnownPower = state.power || 'unknown';
      qs('miniPower').classList.toggle('power-active', lastKnownPower === 'on');
      if (state.mute === true || state.mute === false) {
        setMuteVisualEverywhere(state.mute);
      }
      qs('miniServer').classList.toggle('active', state.sourceRaw === 'SISERVER');
      qs('miniBt').classList.toggle('active', state.sourceRaw === 'SIBLUETOOTH' || state.sourceRaw === 'SIBT');

      const tunerParts = [];
      if (state.tunerFrequencyLabel) tunerParts.push(state.tunerFrequencyLabel);
      if (state.tunerStationName) tunerParts.push(state.tunerStationName);
      if (state.tunerMode) tunerParts.push(state.tunerMode);
      qs('tunerText').textContent = tunerParts.length ? tunerParts.join(' · ') : '—';

      const vol = state.volumeNumber;
      if (vol !== null && vol !== undefined && !Number.isNaN(Number(vol))) {
        setVolumeVisualEverywhere(vol, state.volumePercent || 0);
      }

      const lines = Array.isArray(state.display) ? state.display : [];
      const holder = qs('displayLines');
      holder.innerHTML = '';
      for (let i = 0; i < 9; i++) {
        const div = document.createElement('div');
        const text = (lines[i] || '').trim();
        div.className = 'display-line' + (text === '' ? ' empty' : '');
        div.textContent = text !== '' ? text : '····················';
        holder.appendChild(div);
      }

      renderMiniDisplay(lines);

      document.querySelectorAll('.source-btn').forEach(btn => {
        const command = btn.getAttribute('data-command');
        // FM and AM are the bands of the radio (source SITUNER): lit while the radio plays that band.
        const band = command === 'TMANFM' ? 'FM' : (command === 'TMANAM' ? 'AM' : '');
        btn.classList.toggle('active', band !== ''
          ? state.sourceRaw === 'SITUNER' && state.tunerBand === band
          : command === state.sourceRaw);
      });
    }

    function renderMiniDisplay(lines) {
      const holder = qs('miniDisplayLines');
      // Four lines fit. While playing: title, track, album, time (display rows 1, 2, 5, 6). In a
      // menu (items on rows 2-8): the title and the three items around the selected one ("> ").
      let indexes = [0, 1, 4, 5];
      if (lines && lines[0] !== 'Now Playing') {
        const selected = lines.findIndex((text, i) => i >= 1 && i <= 7 && (text || '').startsWith('> '));
        const first = selected < 0 ? 1 : Math.min(Math.max(selected - 1, 1), 5);
        indexes = [0, first, first + 1, first + 2];
      }
      holder.innerHTML = '';

      indexes.forEach(idx => {
        const div = document.createElement('div');
        const text = ((lines && lines[idx]) ? lines[idx] : '').trim();
        div.className = 'mini-display-line' + (text === '' ? ' empty' : '');
        div.textContent = text !== '' ? text : '····················';
        holder.appendChild(div);
      });
    }

    function startPolling() {
      if (polling) clearInterval(polling);
      refreshStatus(true);
      polling = setInterval(() => refreshStatus(false), 3000);
    }

    function stopPolling() {
      if (polling) clearInterval(polling);
      polling = null;
    }

    // Give the session back at once; sendBeacon is still delivered while the page goes away.
    function releaseSession() {
      const form = new FormData();
      form.append('ajax', '1');
      form.append('action', 'release');
      form.append('page', PAGE_ID);
      navigator.sendBeacon(location.href, form);
    }

    // A page that is not visible (other tab, minimized window, phone screen off) releases the
    // Denon for other devices and asks for it again when it is visible.
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        stopPolling();
        releaseSession();
      } else {
        startPolling();
      }
    });
    window.addEventListener('pagehide', releaseSession);

    function bindHoldButton(id, command) {
      const btn = qs(id);
      let timer = null;

      const start = (ev) => {
        ev.preventDefault();
        cmd(command);
        timer = setInterval(() => cmd(command), 320);
      };

      const stop = () => {
        if (timer) clearInterval(timer);
        timer = null;
      };

      btn.addEventListener('pointerdown', start);
      window.addEventListener('pointerup', stop);
      window.addEventListener('pointercancel', stop);
      btn.addEventListener('mouseleave', stop);
    }

    async function favoriteGo() {
      const val = parseInt(qs('favoriteNo').value, 10);
      const value = Math.max(1, Math.min(50, Number.isFinite(val) ? val : 1));
      qs('favoriteNo').value = value;
      const json = await api('favorite', { value });
      handleResponse(json, 'FV' + String(value).padStart(2, '0'));
    }

    async function setVolume(value) {
      const v = Math.max(0, Math.min(60, parseInt(value, 10) || 0));
      setVolumeVisualEverywhere(v);
      setLog('TX MV' + String(v).padStart(2, '0'));
      const json = await api('volume_set', { value: v });
      handleResponse(json, 'MV' + String(v).padStart(2, '0'));
    }

    function sendManual() {
      const command = qs('manualCommand').value.trim().toUpperCase();
      if (!command) return;
      cmd(command);
    }

    qs('volumeSlider').addEventListener('change', function () {
      setVolume(this.value);
    });

    qs('manualCommand').addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') sendManual();
    });

    qs('denonIpInput').addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') saveIp();
    });

    document.addEventListener('keydown', function (ev) {
      if (ev.target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(ev.target.tagName)) return;
      if (ev.key === '+') cmd('MVUP');
      if (ev.key === '-') cmd('MVDOWN');
      if (ev.key.toLowerCase() === 'm') toggleMute();
      if (ev.key.toLowerCase() === 'r') refreshStatus(true);
    });

    // Zoom the whole layout so that it fills the window without scrolling, whatever the window
    // size: measure it unzoomed, then scale.
    // Phones, upright or sideways: same query as the phone media query in the stylesheet.
    const PHONE_LAYOUT = '(max-width: 600px), (max-height: 500px)';

    function fitToWindow() {
      const wrap = document.querySelector('.wrap');
      wrap.style.zoom = '';
      if (window.matchMedia(PHONE_LAYOUT).matches) return; // phone layout: tabs, no zoom
      const size = wrap.getBoundingClientRect();
      wrap.style.zoom = Math.min((window.innerWidth - 28) / size.width, (window.innerHeight - 2) / size.height);
    }

    window.addEventListener('resize', fitToWindow);
    fitToWindow();

    // Phone layout: the tab bar shows one section at a time.
    function showTab(name) {
      document.body.dataset.tab = name;
      document.querySelectorAll('.tabbar button').forEach(b => b.classList.toggle('active', b.dataset.goto === name));
      window.scrollTo(0, 0);
    }

    document.querySelectorAll('.tabbar button').forEach(btn => {
      btn.addEventListener('click', () => showTab(btn.dataset.goto));
    });

    bindHoldButton('volUp', 'MVUP');
    bindHoldButton('volDown', 'MVDOWN');
    bindHoldButton('miniVolUp', 'MVUP');
    bindHoldButton('miniVolDown', 'MVDOWN');
    // Micro remote LEFT/RIGHT transport commands swapped per user request.
    // Left physical button  => NS9D (Denon Next)
    // Right physical button => NS9E (Denon Previous)
    bindHoldButton('miniPrev', 'NS9D');
    bindHoldButton('miniNext', 'NS9E');

    (function boot() {
      // The server always knows the IP (default or saved by Change IP): connect straight away.
      setConnection(false, 'Starting');
      if (!document.hidden) startPolling(); // opened in a background tab: wait until it is shown
    })();
  </script>
</body>
</html>
