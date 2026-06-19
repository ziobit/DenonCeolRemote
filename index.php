<?php
/*
  MICRO REMOTE V7

  Denon CEOL / RCD-N9 TCP/IP Remote
  Single-file PHP 7.2 webapp, no Bootstrap, no database.

  Deploy this file on a PHP server inside the same LAN as the Denon.
  The browser talks to PHP; PHP talks to the Denon over TCP port 23.

  Important Denon setting:
  Enable Network Control / IP Control on the Denon, otherwise standby control may fail.
*/

declare(strict_types=1);

session_start();

const DENON_TCP_PORT = 23;
const DENON_CONNECT_TIMEOUT_SECONDS = 1.2;
const DENON_DEFAULT_READ_MS = 900;
const ALLOW_PUBLIC_DENON_IP = false; // Keep false unless this app is firewalled and you know what you are doing.
const APP_TITLE = 'CEOL N9 Micro Command Deck';
const APP_VERSION = 'micro-remote-v7-2026-06-19';

function json_out(array $payload): void {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

function current_denon_ip(): string {
  return isset($_SESSION['denon_ip']) ? (string)$_SESSION['denon_ip'] : '';
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
  $fp = @fsockopen($ip, DENON_TCP_PORT, $errno, $errstr, DENON_CONNECT_TIMEOUT_SECONDS);

  if (!$fp) {
    return array(
      'ok' => false,
      'error' => 'TCP connection failed: ' . ($errstr !== '' ? $errstr : ('error ' . $errno)),
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
    $parts = preg_split('/\r\n|\r|\n/', $raw);
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

  return $state;
}

function format_tuner_frequency(string $raw): string {
  if (!preg_match('/^[0-9]{6}$/', $raw)) {
    return $raw;
  }

  $n = (int)$raw;

  // Denon models vary. This keeps the raw value visible and provides a reasonable FM helper label.
  if ($n >= 76000 && $n <= 108000) {
    return number_format($n / 1000, 3, '.', '') . ' MHz';
  }

  if ($n >= 520 && $n <= 1710) {
    return $n . ' kHz';
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

    $_SESSION['denon_ip'] = $ip;
    json_out(array('ok' => true, 'ip' => $ip));
  }

  if ($action === 'reset_ip') {
    unset($_SESSION['denon_ip']);
    json_out(array('ok' => true));
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
    $res = denon_tcp_send($ip, array($command, 'MV?', 'NSE'), 850);
    json_out(array('ok' => $res['ok'], 'error' => $res['error'], 'lines' => $res['lines'], 'state' => parse_denon_state($res['lines'])));
  }

  if ($action === 'favorite') {
    $value = isset($_POST['value']) ? (int)$_POST['value'] : 1;
    $value = max(1, min(50, $value));
    $command = 'FV' . str_pad((string)$value, 2, '0', STR_PAD_LEFT);
    $res = denon_tcp_send($ip, array($command, 'NSE'), 900);
    json_out(array('ok' => $res['ok'], 'error' => $res['error'], 'lines' => $res['lines'], 'state' => parse_denon_state($res['lines'])));
  }

  json_out(array('ok' => false, 'error' => 'Unknown action.'));
}

$configuredIp = current_denon_ip();
$sources = array(
  array('SICD', 'CD', 'optical disc'),
  array('SITUNER', 'Tuner', 'radio deck'),
  array('SIFM', 'FM', 'frequency mode'),
  array('SIAM', 'AM', 'frequency mode'),
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
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h(APP_TITLE) ?></title>
  <style>
    :root {
      --bg: #05070b;
      --panel: rgba(12, 19, 31, 0.78);
      --panel-2: rgba(18, 29, 47, 0.76);
      --line: rgba(94, 234, 212, 0.26);
      --cyan: #5eead4;
      --cyan-2: #22d3ee;
      --blue: #60a5fa;
      --amber: #fbbf24;
      --red: #fb7185;
      --green: #86efac;
      --text: #e8fbff;
      --muted: #8aa4b7;
      --shadow: 0 0 38px rgba(34, 211, 238, 0.13), 0 18px 60px rgba(0, 0, 0, 0.45);
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
        radial-gradient(circle at 20% 20%, rgba(34, 211, 238, 0.16), transparent 30%),
        radial-gradient(circle at 82% 10%, rgba(96, 165, 250, 0.18), transparent 34%),
        radial-gradient(circle at 50% 90%, rgba(94, 234, 212, 0.12), transparent 42%),
        linear-gradient(135deg, #02040a 0%, #07101d 48%, #03070e 100%);
      overflow-x: hidden;
    }

    body::before {
      content: "";
      position: fixed;
      inset: 0;
      pointer-events: none;
      background:
        linear-gradient(rgba(255,255,255,0.032) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.032) 1px, transparent 1px);
      background-size: 48px 48px;
      mask-image: radial-gradient(circle at center, rgba(0,0,0,0.9), transparent 76%);
    }

    body::after {
      content: "";
      position: fixed;
      inset: 0;
      pointer-events: none;
      background: repeating-linear-gradient(0deg, rgba(255,255,255,0.028), rgba(255,255,255,0.028) 1px, transparent 1px, transparent 5px);
      mix-blend-mode: overlay;
      opacity: 0.28;
    }

    button, input, select { font: inherit; }

    .wrap {
      width: min(1440px, calc(100vw - 28px));
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
        radial-gradient(circle at 50% 50%, rgba(94, 234, 212, 0.9), rgba(34, 211, 238, 0.18) 38%, transparent 40%),
        conic-gradient(from 210deg, rgba(94,234,212,0.95), rgba(96,165,250,0.2), rgba(251,191,36,0.55), rgba(94,234,212,0.95));
      box-shadow: 0 0 35px rgba(94, 234, 212, 0.28), inset 0 0 24px rgba(0,0,0,0.5);
    }

    .logo::before, .logo::after {
      content: "";
      position: absolute;
      border: 1px solid rgba(232,251,255,0.4);
      border-radius: 50%;
      inset: 11px;
    }

    .logo::after { inset: 21px; border-color: rgba(251,191,36,0.58); }

    h1 {
      margin: 0;
      font-size: clamp(24px, 3vw, 42px);
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

    .ip-pill, .status-pill, .tiny-pill {
      border: 1px solid rgba(94, 234, 212, 0.22);
      background: rgba(7, 12, 22, 0.62);
      border-radius: 999px;
      padding: 10px 14px;
      box-shadow: inset 0 0 18px rgba(34, 211, 238, 0.05);
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
      box-shadow: 0 0 14px var(--red);
    }

    .dot.online { background: var(--green); box-shadow: 0 0 15px var(--green); }


    .mini-remote {
      position: relative;
      display: grid;
      justify-items: center;
      gap: 18px;
      width: min(560px, 100%);
      margin: 0 auto 22px;
      isolation: isolate;
      padding: 22px 18px 24px;
      border: 1px solid rgba(94, 234, 212, 0.22);
      border-radius: 34px;
      background:
        radial-gradient(circle at 50% 0%, rgba(94, 234, 212, 0.13), transparent 42%),
        linear-gradient(180deg, rgba(18, 29, 47, 0.82), rgba(4, 9, 17, 0.92));
      box-shadow: 0 22px 70px rgba(0, 0, 0, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.07);
      overflow: hidden;
    }

    .mini-remote::before {
      content: "";
      position: absolute;
      inset: 0;
      pointer-events: none;
      background:
        radial-gradient(circle at 50% 50%, transparent 0 56%, rgba(94, 234, 212, 0.08) 57%, transparent 58%),
        linear-gradient(115deg, transparent 0 42%, rgba(255, 255, 255, 0.06) 48%, transparent 56%);
      opacity: 0.65;
    }

    .mini-volume-chip {
      position: absolute;
      top: 16px;
      right: 16px;
      z-index: 2;
      min-width: 48px;
      height: 34px;
      padding: 0 11px;
      border: 1px solid rgba(94, 234, 212, 0.34);
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 16px;
      font-weight: 800;
      letter-spacing: 0.08em;
      color: #e8fbff;
      background: rgba(0, 7, 14, 0.72);
      box-shadow: inset 0 0 18px rgba(94, 234, 212, 0.10), 0 0 22px rgba(94, 234, 212, 0.08);
      text-shadow: 0 0 12px rgba(94, 234, 212, 0.60);
      pointer-events: none;
    }

    .mini-volume-chip::before {
      content: "VOL";
      margin-right: 7px;
      font-size: 9px;
      font-weight: 700;
      letter-spacing: 0.14em;
      color: rgba(191, 252, 255, 0.55);
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
      border: 1px solid rgba(94, 234, 212, 0.20);
      border-radius: 20px;
      background:
        linear-gradient(180deg, rgba(3, 10, 16, 0.92), rgba(0, 3, 8, 0.96)),
        radial-gradient(circle at 50% 0%, rgba(94, 234, 212, 0.10), transparent 54%);
      box-shadow: inset 0 0 28px rgba(94, 234, 212, 0.08), 0 12px 34px rgba(0, 0, 0, 0.32);
      overflow: hidden;
    }

    .mini-display::before {
      content: "";
      position: absolute;
      inset: 0;
      pointer-events: none;
      background: repeating-linear-gradient(180deg, rgba(255, 255, 255, 0.04) 0, rgba(255, 255, 255, 0.04) 1px, transparent 1px, transparent 7px);
      opacity: 0.22;
    }

    .mini-display-lines {
      position: relative;
      z-index: 1;
      display: grid;
      gap: 5px;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: clamp(12px, 2.8vw, 15px);
      line-height: 1.12;
      letter-spacing: 0.08em;
      color: #bffcff;
      text-shadow: 0 0 12px rgba(94, 234, 212, 0.48);
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
      border: 1px solid rgba(232, 251, 255, 0.13);
      border-radius: 50%;
      color: rgba(232, 251, 255, 0.9);
      background:
        radial-gradient(circle at 50% 25%, rgba(255, 255, 255, 0.08), transparent 42%),
        linear-gradient(180deg, rgba(19, 33, 52, 0.92), rgba(3, 8, 15, 0.95));
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08), 0 16px 32px rgba(0, 0, 0, 0.34);
      cursor: pointer;
      display: grid;
      place-items: center;
      transition: transform 0.12s ease, border-color 0.12s ease, box-shadow 0.12s ease, color 0.12s ease;
      -webkit-tap-highlight-color: transparent;
      user-select: none;
    }

    .mini-btn:hover {
      transform: translateY(-1px);
      border-color: rgba(94, 234, 212, 0.55);
      color: #ffffff;
      box-shadow: 0 0 28px rgba(94, 234, 212, 0.16), inset 0 1px 0 rgba(255, 255, 255, 0.12);
    }

    .mini-btn:active {
      transform: translateY(1px) scale(0.98);
      box-shadow: inset 0 0 26px rgba(94, 234, 212, 0.12), 0 8px 18px rgba(0, 0, 0, 0.35);
    }

    .mini-btn.active {
      border-color: rgba(251, 191, 36, 0.72);
      color: #fff7d6;
      box-shadow: 0 0 30px rgba(251, 191, 36, 0.18), inset 0 0 24px rgba(251, 191, 36, 0.08);
    }

    .mini-btn.power-active {
      border-color: rgba(134, 239, 172, 0.64);
      color: #dcfce7;
      box-shadow: 0 0 32px rgba(134, 239, 172, 0.18), inset 0 0 24px rgba(134, 239, 172, 0.08);
    }

    .mini-btn.muted-active {
      border-color: rgba(251, 113, 133, 0.66);
      color: #ffe4e6;
      box-shadow: 0 0 32px rgba(251, 113, 133, 0.18), inset 0 0 24px rgba(251, 113, 133, 0.08);
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
      width: min(286px, 82vw);
      height: min(286px, 82vw);
      border-radius: 50%;
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      grid-template-rows: 1fr 1fr 1fr;
      gap: 10px;
      padding: 14px;
      background:
        radial-gradient(circle at 50% 50%, rgba(94, 234, 212, 0.09), transparent 52%),
        linear-gradient(180deg, rgba(9, 18, 31, 0.9), rgba(2, 6, 12, 0.96));
      border: 1px solid rgba(94, 234, 212, 0.17);
      box-shadow: inset 0 0 48px rgba(0, 0, 0, 0.45), 0 18px 44px rgba(0, 0, 0, 0.38);
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

    @media (max-width: 760px) {
      .mini-remote {
        position: sticky;
        top: 8px;
        z-index: 8;
        margin-top: 4px;
        border-radius: 30px;
        padding: 20px 12px 22px;
      }

      .mini-row { gap: 14px; }

      .mini-btn {
        width: 68px;
        height: 68px;
      }
    }

    @media (max-width: 430px) {
      .mini-volume-chip {
        top: 12px;
        right: 12px;
        min-width: 42px;
        height: 30px;
        font-size: 14px;
        padding: 0 9px;
      }

      .mini-row { gap: 10px; }
      .mini-btn { width: 60px; height: 60px; }
      .mini-btn svg { width: 31px; height: 31px; }
      .mini-pad { width: min(260px, 88vw); height: min(260px, 88vw); gap: 8px; padding: 12px; }
      .mini-display { width: 94%; padding: 11px 12px; border-radius: 17px; }
    }

    .deck {
      display: grid;
      grid-template-columns: 1.05fr 1.5fr 1.05fr;
      gap: 18px;
      align-items: stretch;
    }

    .panel {
      position: relative;
      border: 1px solid rgba(94, 234, 212, 0.18);
      background:
        linear-gradient(145deg, rgba(255,255,255,0.055), transparent 30%),
        linear-gradient(180deg, var(--panel), rgba(6, 12, 22, 0.82));
      border-radius: var(--radius);
      box-shadow: var(--shadow), inset 0 1px 0 rgba(255,255,255,0.06);
      overflow: hidden;
    }

    .panel::before {
      content: "";
      position: absolute;
      inset: 0;
      pointer-events: none;
      background: linear-gradient(90deg, transparent, rgba(94,234,212,0.12), transparent);
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
      color: #dffaff;
      text-transform: uppercase;
      letter-spacing: 0.16em;
      font-size: 12px;
      font-weight: 800;
    }

    .panel-code {
      color: var(--cyan);
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 11px;
      opacity: 0.8;
    }

    .source-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .source-btn, .cmd-btn, .ghost-btn, .danger-btn, .power-btn, .round-btn {
      border: 1px solid rgba(94, 234, 212, 0.22);
      color: var(--text);
      background:
        linear-gradient(180deg, rgba(20, 43, 64, 0.88), rgba(8, 15, 26, 0.82));
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.07), 0 10px 28px rgba(0,0,0,0.24);
      cursor: pointer;
      transition: transform 0.12s ease, border-color 0.12s ease, box-shadow 0.12s ease, color 0.12s ease;
      user-select: none;
    }

    .source-btn:hover, .cmd-btn:hover, .ghost-btn:hover, .danger-btn:hover, .power-btn:hover, .round-btn:hover {
      transform: translateY(-1px);
      border-color: rgba(94,234,212,0.55);
      box-shadow: 0 0 26px rgba(34, 211, 238, 0.16), inset 0 1px 0 rgba(255,255,255,0.1);
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
      border-color: rgba(251,191,36,0.72);
      color: #fff7d6;
      box-shadow: 0 0 28px rgba(251,191,36,0.18), inset 0 0 28px rgba(251,191,36,0.06);
    }

    .display-panel { min-height: 460px; }

    .oled {
      position: relative;
      min-height: 280px;
      border-radius: 24px;
      padding: 24px 22px;
      background:
        radial-gradient(circle at 50% 0%, rgba(94,234,212,0.15), transparent 48%),
        linear-gradient(180deg, rgba(0, 16, 20, 0.94), rgba(0, 5, 10, 0.98));
      border: 1px solid rgba(94, 234, 212, 0.35);
      box-shadow: inset 0 0 40px rgba(94,234,212,0.1), 0 0 40px rgba(34,211,238,0.1);
      overflow: hidden;
    }

    .oled::before {
      content: "";
      position: absolute;
      inset: 0;
      background: repeating-linear-gradient(0deg, rgba(94,234,212,0.045), rgba(94,234,212,0.045) 1px, transparent 1px, transparent 7px);
      pointer-events: none;
    }

    .oled::after {
      content: "";
      position: absolute;
      left: -20%;
      top: -80%;
      width: 140%;
      height: 180%;
      transform: rotate(8deg);
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.08), transparent);
      pointer-events: none;
    }

    .display-lines {
      position: relative;
      z-index: 1;
      display: grid;
      gap: 10px;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      text-shadow: 0 0 12px rgba(94,234,212,0.65);
    }

    .display-line {
      min-height: 22px;
      color: var(--cyan);
      font-size: clamp(14px, 1.4vw, 19px);
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
      border: 1px solid rgba(94, 234, 212, 0.14);
      background: rgba(2, 9, 17, 0.55);
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
      width: min(290px, 72vw);
      aspect-ratio: 1;
      border-radius: 50%;
      position: relative;
      display: grid;
      place-items: center;
      background:
        radial-gradient(circle, #07101c 0 49%, transparent 50%),
        conic-gradient(var(--cyan) 0 var(--vol), rgba(94,234,212,0.08) var(--vol) 100%);
      box-shadow: 0 0 46px rgba(94,234,212,0.16), inset 0 0 36px rgba(0,0,0,0.5);
      border: 1px solid rgba(94,234,212,0.25);
    }

    .volume-ring::before {
      content: "";
      position: absolute;
      inset: 18px;
      border-radius: 50%;
      border: 1px solid rgba(255,255,255,0.08);
      background:
        radial-gradient(circle at 50% 30%, rgba(255,255,255,0.08), transparent 25%),
        linear-gradient(180deg, rgba(12,22,35,0.95), rgba(3,8,14,0.98));
      box-shadow: inset 0 0 40px rgba(94,234,212,0.07);
    }

    .volume-readout {
      position: relative;
      z-index: 1;
      text-align: center;
    }

    .volume-readout .num {
      display: block;
      font-size: clamp(54px, 8vw, 86px);
      line-height: 0.9;
      font-weight: 900;
      letter-spacing: -0.08em;
      color: #f3feff;
      text-shadow: 0 0 24px rgba(94,234,212,0.35);
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
      border: 1px solid rgba(94,234,212,0.15);
      background: rgba(2,9,17,0.45);
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

    .power-btn.on { border-color: rgba(134,239,172,0.55); }
    .power-btn.off, .danger-btn { border-color: rgba(251,113,133,0.45); }
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
      border: 1px solid rgba(94,234,212,0.22);
      border-radius: 16px;
      background: rgba(2, 8, 15, 0.82);
      color: var(--text);
      padding: 13px 14px;
      outline: none;
    }

    .field-row input:focus { border-color: rgba(94,234,212,0.55); box-shadow: 0 0 20px rgba(34,211,238,0.12); }

    .log {
      max-height: 190px;
      overflow: auto;
      border-radius: 18px;
      border: 1px solid rgba(94,234,212,0.13);
      background: rgba(1, 6, 11, 0.72);
      padding: 12px;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 12px;
      color: #b7f7ff;
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
      background: rgba(0, 3, 8, 0.78);
      backdrop-filter: blur(16px);
    }

    .modal-card {
      width: min(620px, 100%);
      border-radius: 30px;
      border: 1px solid rgba(94,234,212,0.28);
      background:
        radial-gradient(circle at 50% 0%, rgba(94,234,212,0.15), transparent 38%),
        linear-gradient(180deg, rgba(13,24,39,0.96), rgba(4,9,17,0.98));
      box-shadow: 0 0 60px rgba(94,234,212,0.18), 0 28px 80px rgba(0,0,0,0.62);
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
      border: 1px solid rgba(94,234,212,0.28);
      background: rgba(1,6,11,0.85);
      color: var(--text);
      border-radius: 18px;
      padding: 16px 15px;
      outline: none;
      font-size: 18px;
      letter-spacing: 0.03em;
    }

    .connect-form button, .primary-btn {
      border: 1px solid rgba(94,234,212,0.55);
      background: linear-gradient(180deg, rgba(94,234,212,0.28), rgba(34,211,238,0.12));
      color: var(--text);
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
      border: 1px solid rgba(251,113,133,0.35);
      background: rgba(251,113,133,0.09);
      color: #fecdd3;
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

    @media (max-width: 1160px) {
      .deck { grid-template-columns: 1fr; }
      .display-panel { min-height: auto; }
    }

    @media (max-width: 760px) {
      .topbar { align-items: flex-start; flex-direction: column; }
      .top-actions { justify-content: flex-start; width: 100%; }
      .source-grid, .power-grid, .transport-grid, .tuner-grid, .utility-grid { grid-template-columns: 1fr 1fr; }
      .state-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .connect-form { grid-template-columns: 1fr; }
      .panel-inner { padding: 16px; }
    }

    @media (max-width: 460px) {
      .source-grid, .power-grid, .transport-grid, .tuner-grid, .utility-grid, .volume-controls { grid-template-columns: 1fr; }
      .state-row { grid-template-columns: 1fr; }
      .wrap { width: min(100vw - 18px, 1440px); }
    }
  </style>
</head>
<body>
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

  <main class="wrap">
    <!-- MICRO_REMOTE_V7_START: icon-only mobile command center with mini display and synced volume chip -->
    <section class="mini-remote" data-version="micro-v7" aria-label="Simplified Denon remote">
      <div class="mini-volume-chip" id="miniVolumeNum" aria-label="Volume">--</div>
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
      </div>

      <div class="mini-display" aria-label="Mini display rows 1, 2, 5 and 6">
        <div class="mini-display-lines" id="miniDisplayLines">
          <div class="mini-display-line empty">····················</div>
          <div class="mini-display-line empty">····················</div>
          <div class="mini-display-line empty">····················</div>
          <div class="mini-display-line empty">····················</div>
        </div>
      </div>

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

    <!-- MICRO_REMOTE_V7_END -->

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
        <div class="ip-pill">IP <b id="ipText"><?= h($configuredIp !== '' ? $configuredIp : 'not set') ?></b></div>
        <button class="ghost-btn" type="button" onclick="showIpModal()">Change IP</button>
      </div>
    </div>

    <div class="deck">
      <section class="panel">
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

      <section class="panel display-panel">
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

      <section class="panel">
        <div class="panel-inner">
          <div class="section-title"><span>Amplitude Drive</span><span class="panel-code">MV 00-60</span></div>
          <div class="volume-stage">
            <div class="volume-ring" id="volumeRing">
              <div class="volume-readout">
                <span class="num" id="volumeNum">--</span>
                <span class="unit">Volume</span>
              </div>
            </div>
          </div>

          <div class="volume-controls">
            <button class="round-btn" type="button" id="volDown">−</button>
            <button class="round-btn" type="button" id="volUp">+</button>
          </div>

          <div class="slider-wrap">
            <input type="range" min="0" max="60" value="0" id="volumeSlider">
          </div>

          <div class="mt">
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

          <div class="mt">
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

  <script>
    const initialIp = <?= json_encode($configuredIp, JSON_UNESCAPED_SLASHES) ?>;
    let polling = null;
    let busy = false;
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
      Object.keys(data).forEach(key => form.append(key, data[key]));

      const res = await fetch(location.href, {
        method: 'POST',
        body: form,
        credentials: 'same-origin'
      });

      const json = await res.json();
      return json;
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

      const json = await api('save_ip', { ip });
      if (!json.ok) {
        qs('ipError').textContent = json.error || 'Cannot save IP.';
        qs('ipError').style.display = 'block';
        return;
      }

      localStorage.setItem('denon_ceol_ip', json.ip);
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
      try {
        const json = await api('status');
        handleResponse(json, 'STATUS');
      } catch (err) {
        setConnection(false, 'Offline');
        if (manual) setLog('STATUS ERROR ' + err.message);
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
        btn.classList.toggle('active', command === state.sourceRaw);
      });
    }

    function renderMiniDisplay(lines) {
      const holder = qs('miniDisplayLines');
      const indexes = [0, 1, 4, 5]; // User-facing display rows 1, 2, 5 and 6.
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
      const saved = localStorage.getItem('denon_ceol_ip');
      if (!initialIp && saved) {
        qs('denonIpInput').value = saved;
      }

      if (initialIp) {
        setConnection(false, 'Starting');
        startPolling();
      } else {
        showIpModal();
      }
    })();
  </script>
</body>
</html>
