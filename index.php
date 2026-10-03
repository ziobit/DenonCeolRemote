<?php
/*
  CEOL SKIN STUDIO V8

  Denon CEOL / RCD-N9 Network Remote
  Single-file PHP 7.2 webapp, no Bootstrap, no database.

  Connection settings offer two explicit modes; failures never switch modes.
  Server relay (default): PHP reaches the Denon over TCP port 23 or the selected
  HTTP command fallback. The PHP host needs access to the Denon LAN or a VPN.
  Browser direct: JavaScript reaches the Denon HTTP goform API from the user's
  LAN; PHP only serves the UI, preferences, theme studio, and updater. Browser
  mixed-content, CORS, and local/private-network policies still apply. Opaque
  replies cannot confirm delivery or read status; displayed changes are estimates.

  Theme studio: open "Theme editor" and create your admin password on first use.
  Right-click an item for properties; drag it or resize it with the selection handles.
  Deploy only this index.php. PHP needs write access to this folder to create
  denon-ceol-preferences.json and the guarded .denon-ceol-admin.php credential data.
  To reset a forgotten password, remove .denon-ceol-admin.php from the server,
  then create a new password in the editor. Built-in themes are never overwritten.
  Updates: the app checks this repository's main/index.php over verified HTTPS.
  Bump APP_VERSION for each release. Installation requires confirmation and the
  admin password. Settings stay in their local files. To recover manually, remove
  the first line of .denon-ceol-backup.php and restore it as index.php.

  Important Denon setting:
  Enable Network Control / IP Control on the Denon, otherwise standby control may fail.
*/

declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure', '1');
session_start();
if (!isset($_SESSION['ceol_csrf'])) $_SESSION['ceol_csrf'] = bin2hex(random_bytes(24));

const DENON_TCP_PORT = 23;
const DENON_CONNECT_TIMEOUT_SECONDS = 1.2;
const DENON_DEFAULT_READ_MS = 900;
const ALLOW_PUBLIC_DENON_IP = false; // Keep false unless this app is firewalled and you know what you are doing.
const APP_TITLE = 'CEOL N9 Micro Command Deck';
const APP_VERSION = '8.2.0';
const APP_ID = 'ziobit/DenonCeolRemote';
const CEOL_UPDATE_URL = 'https://raw.githubusercontent.com/ziobit/DenonCeolRemote/main/index.php';
const CEOL_REPOSITORY_URL = 'https://github.com/ziobit/DenonCeolRemote';
const CEOL_UPDATE_CACHE_SECONDS = 3600;
const CEOL_UPDATE_MAX_BYTES = 2097152;

function json_out(array $payload): void {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, private');
  header('X-Content-Type-Options: nosniff');
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
  if (isset($_SESSION['denon_ip'])) return (string)$_SESSION['denon_ip'];
  try {
    $prefs = ceol_preferences();
    $ip = isset($prefs['denonIp']) && is_string($prefs['denonIp']) ? $prefs['denonIp'] : '';
    return validate_denon_ip($ip)[0] ? $ip : '';
  } catch (Throwable $error) {
    return '';
  }
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
      'error' => 'TCP ' . $ip . ':' . DENON_TCP_PORT . ' connection failed: '
        . ($errstr !== '' ? $errstr : ('error ' . $errno))
        . '. Check port ' . DENON_TCP_PORT . ' from the PHP host; it needs access to the Denon LAN, locally or through a VPN.',
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
  if (function_exists('curl_init')) {
    $request = curl_init($url);
    if ($request === false) return array('ok' => false, 'body' => '', 'error' => 'Cannot start PHP cURL.');
    $body = '';
    curl_setopt_array($request, array(CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_CONNECTTIMEOUT => $timeoutSeconds, CURLOPT_TIMEOUT => $timeoutSeconds,
      CURLOPT_USERAGENT => 'CEOL-PHP-Remote/' . APP_VERSION,
      // Older Denon firmware uses a self-signed LAN HTTPS certificate. This
      // compatibility setting applies only to the device, never to updates.
      CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
      CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body) {
        if (strlen($body) + strlen($chunk) > 1048576) return 0;
        $body .= $chunk;
        return strlen($chunk);
      }));
    $ok = curl_exec($request);
    $status = (int)curl_getinfo($request, CURLINFO_HTTP_CODE);
    $error = curl_error($request);
    curl_close($request);
    if ($ok === false) return array('ok' => false, 'body' => '', 'error' => $error !== '' ? $error : 'HTTP connection failed.');
    if ($status < 200 || $status >= 300) return array('ok' => false, 'body' => '', 'error' => 'The Denon returned HTTP ' . $status . ' for this command endpoint.');
    return array('ok' => true, 'body' => $body, 'error' => '');
  }
  if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
    return array('ok' => false, 'body' => '', 'error' => 'PHP HTTP access is disabled: enable cURL or allow_url_fopen on the PHP host.');
  }
  $context = stream_context_create(array(
    'http' => array(
      'method' => 'GET',
      'timeout' => $timeoutSeconds,
      'ignore_errors' => true,
      'follow_location' => 0,
      'header' => "User-Agent: CEOL-PHP-Remote/" . APP_VERSION . "\r\n"
    ),
    'ssl' => array(
      'verify_peer' => false,
      'verify_peer_name' => false
    )
  ));

  error_clear_last();
  $body = @file_get_contents($url, false, $context, 0, 1048577);
  if ($body === false) {
    $warning = error_get_last();
    $detail = isset($warning['message']) ? $warning['message'] : 'HTTP connection failed.';
    return array('ok' => false, 'body' => '', 'error' => $detail);
  }
  if (strlen($body) > 1048576) return array('ok' => false, 'body' => '', 'error' => 'The Denon HTTP response exceeds 1MB.');
  $headers = isset($http_response_header) ? $http_response_header : array();
  $status = !empty($headers) && preg_match('/^HTTP\/\S+ (\d{3})/', $headers[0], $match) ? (int)$match[1] : 0;
  if ($status < 200 || $status >= 300) return array('ok' => false, 'body' => '', 'error' => 'The Denon returned HTTP ' . $status . ' for this command endpoint.');
  return array('ok' => true, 'body' => $body, 'error' => '');
}

function denon_http_command_path(string $command): string {
  $command = normalize_command($command);
  if (!is_allowed_denon_command($command)) throw new RuntimeException('Command not allowed: ' . $command);
  $paths = array('PWON' => '/goform/formiPhoneAppPower.xml?1+PowerOn',
    'PWSTANDBY' => '/goform/formiPhoneAppPower.xml?1+PowerStandby',
    'MUON' => '/goform/formiPhoneAppMute.xml?1+MuteOn', 'MUOFF' => '/goform/formiPhoneAppMute.xml?1+MuteOff');
  return isset($paths[$command]) ? $paths[$command] : '/goform/formiPhoneAppDirect.xml?' . rawurlencode($command);
}

function denon_http_fallback_command(string $ip, string $command): array {
  $command = normalize_command($command);
  if (!is_allowed_denon_command($command)) return array('ok' => false, 'error' => 'Command not allowed for HTTP fallback.', 'lines' => array());
  $path = denon_http_command_path($command);

  // RCD-N9 field reports often use HTTPS; many Denon devices also accept HTTP.
  $tries = array('http://' . $ip . $path, 'https://' . $ip . $path, 'http://' . $ip . ':8080' . $path);
  $errors = array();
  foreach ($tries as $url) {
    $res = denon_http_get_quiet($url, 2);
    if ($res['ok']) {
      return array('ok' => true, 'error' => '', 'lines' => array('HTTP fallback sent: ' . $command), 'body' => $res['body']);
    }
    $port = parse_url($url, PHP_URL_PORT);
    if ($port === null) $port = parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80;
    $errors[] = 'Port ' . $port . ': ' . $res['error'];
  }

  return array('ok' => false, 'error' => 'HTTP fallback failed from the PHP host. ' . implode(' | ', $errors)
    . ' Run PHP on the Denon LAN or connect the PHP host through a VPN.', 'lines' => array());
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

// Runtime data is created beside this file. Deploy only index.php.
// Preferences contain no credentials. The admin data has a PHP exit guard.
const CEOL_PREFS_FILE = __DIR__ . '/denon-ceol-preferences.json';
const CEOL_ADMIN_FILE = __DIR__ . '/.denon-ceol-admin.php';
const CEOL_ADMIN_GUARD = "<?php http_response_code(404); exit; ?>\n";
const CEOL_EDITOR_SESSION_SECONDS = 1800;

function ceol_preset_ids(): array {
  return array('porcelain', 'sandstone', 'seafoam', 'ice', 'rose', 'graphite', 'midnight', 'forest', 'aubergine', 'espresso');
}

function ceol_default_preferences(): array {
  return array('schema' => 1, 'revision' => 0, 'activeTheme' => 'porcelain', 'activeView' => 'mini',
    'denonIp' => '', 'connectionMode' => 'relay', 'directScheme' => 'http', 'directPort' => 80,
    'directReadback' => false, 'useHttpFallback' => false, 'customThemes' => array());
}

function ceol_connection_settings(array $input, array $current): array {
  $next = $current;
  if (isset($input['connectionMode'])) $next['connectionMode'] = ceol_choice($input['connectionMode'], array('relay', 'direct'));
  if (isset($input['directScheme'])) $next['directScheme'] = ceol_choice($input['directScheme'], array('http', 'https'));
  if (isset($input['directPort'])) {
    $port = filter_var($input['directPort'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 65535)));
    if ($port === false) throw new RuntimeException('Use a Denon HTTP port from 1 to 65535.');
    $next['directPort'] = $port;
  }
  if (isset($input['directReadback'])) $next['directReadback'] = ceol_choice($input['directReadback'], array('0', '1')) === '1';
  return $next;
}

function ceol_read_json(string $file, string $guard, array $fallback): array {
  if (!is_file($file)) return $fallback;
  if (filesize($file) > 2097152) throw new RuntimeException('The saved data is too large.');
  $raw = @file_get_contents($file);
  if ($raw === false) throw new RuntimeException('Cannot read the saved data. Check the file permissions.');
  if ($guard !== '') {
    if (substr($raw, 0, strlen($guard)) !== $guard) throw new RuntimeException('The admin data is invalid. Restore it from a backup.');
    $raw = substr($raw, strlen($guard));
  }
  $data = json_decode($raw, true);
  if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
    throw new RuntimeException('The saved JSON is invalid. Restore it from a backup before saving.');
  }
  return $data;
}

function ceol_preferences(): array {
  return array_replace(ceol_default_preferences(), ceol_read_json(CEOL_PREFS_FILE, '', ceol_default_preferences()));
}

function ceol_admin_data(): array {
  return ceol_read_json(CEOL_ADMIN_FILE, CEOL_ADMIN_GUARD, array());
}

// A lock serializes read/modify/write; a same-directory rename keeps readers from seeing partial JSON.
function ceol_mutate_json(string $file, string $guard, array $fallback, callable $change): array {
  $lock = @fopen($file . '.lock', 'c');
  if (!$lock) throw new RuntimeException('Cannot save preferences. Give PHP write permission to this folder.');
  @chmod($file . '.lock', 0600);
  $temp = false;
  try {
    if (!flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock the preferences file.');
    $data = $change(ceol_read_json($file, $guard, $fallback));
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('Cannot encode preferences.');
    if (strlen($guard . $json . "\n") > 2097152) throw new RuntimeException('Saved data exceeds 2MB. Delete unused custom themes before saving.');
    $temp = @tempnam(dirname($file), '._ceol_');
    if ($temp === false || @file_put_contents($temp, $guard . $json . "\n") === false) {
      throw new RuntimeException('Cannot write preferences. Check folder permissions and free space.');
    }
    @chmod($temp, 0600);
    if (!@rename($temp, $file)) throw new RuntimeException('Cannot replace the saved preferences.');
    $temp = false;
    return $data;
  } finally {
    if ($temp !== false) @unlink($temp);
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

function ceol_update_preferences(callable $change): array {
  return ceol_mutate_json(CEOL_PREFS_FILE, '', ceol_default_preferences(), function ($data) use ($change) {
    $data = array_replace(ceol_default_preferences(), $data);
    $next = $change($data);
    $next['revision'] = (int)$data['revision'] + 1;
    return $next;
  });
}

function ceol_editor_authenticated(array $security): bool {
  $valid = isset($security['authVersion'], $_SESSION['ceol_admin_version'], $_SESSION['ceol_admin_seen'])
    && hash_equals((string)$security['authVersion'], (string)$_SESSION['ceol_admin_version'])
    && time() - (int)$_SESSION['ceol_admin_seen'] < CEOL_EDITOR_SESSION_SECONDS;
  if ($valid) $_SESSION['ceol_admin_seen'] = time();
  return $valid;
}

function ceol_require_editor(): void {
  if (!ceol_editor_authenticated(ceol_admin_data())) {
    http_response_code(401);
    json_out(array('ok' => false, 'error' => 'Unlock the editor with the admin password.', 'authRequired' => true));
  }
}

function ceol_require_csrf(): void {
  $token = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
  if (!isset($_SESSION['ceol_csrf']) || !hash_equals($_SESSION['ceol_csrf'], $token)) {
    http_response_code(403);
    json_out(array('ok' => false, 'error' => 'Your page session has expired. Reload the page.'));
  }
}

// Only the fixed upstream file is fetched. Never use the Denon's HTTP helper here:
// GitHub requests must verify both the TLS certificate and hostname.
function ceol_download_update(): string {
  $source = '';
  if (function_exists('curl_init')) {
    $request = curl_init(CEOL_UPDATE_URL);
    if ($request === false) throw new RuntimeException('Cannot start the online update check.');
    curl_setopt_array($request, array(CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => 12, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_USERAGENT => 'DenonCeolRemote/' . APP_VERSION, CURLOPT_HTTPHEADER => array('Accept: text/plain'),
      CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$source) {
        if (strlen($source) + strlen($chunk) > CEOL_UPDATE_MAX_BYTES) return 0;
        $source .= $chunk;
        return strlen($chunk);
      }));
    $ok = curl_exec($request);
    $status = (int)curl_getinfo($request, CURLINFO_HTTP_CODE);
    curl_close($request);
    if ($ok === false || $status !== 200) throw new RuntimeException('Cannot reach GitHub securely. Check internet access and the server\'s CA certificates, then try again.');
  } else {
    $context = stream_context_create(array('http' => array('method' => 'GET', 'timeout' => 12,
      'follow_location' => 0, 'ignore_errors' => true,
      'header' => "Accept: text/plain\r\nUser-Agent: DenonCeolRemote/" . APP_VERSION . "\r\n"),
      'ssl' => array('verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false)));
    $stream = @fopen(CEOL_UPDATE_URL, 'rb', false, $context);
    if (!$stream) throw new RuntimeException('Cannot reach GitHub securely. Enable PHP cURL or allow_url_fopen and check the server\'s CA certificates.');
    try {
      $metadata = stream_get_meta_data($stream);
      $headers = isset($metadata['wrapper_data']) ? $metadata['wrapper_data'] : array();
      if (empty($headers) || !preg_match('/^HTTP\/\S+ 200(?:\s|$)/', $headers[0])) {
        throw new RuntimeException('GitHub did not return the update file. Try again later.');
      }
      $deadline = microtime(true) + 12;
      while (!feof($stream)) {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) throw new RuntimeException('The GitHub update check timed out. Try again.');
        stream_set_timeout($stream, (int)$remaining, (int)(($remaining - (int)$remaining) * 1000000));
        $chunk = fread($stream, 65536);
        $metadata = stream_get_meta_data($stream);
        if ($chunk === false || !empty($metadata['timed_out'])) throw new RuntimeException('The GitHub download was interrupted. Try again.');
        $source .= $chunk;
        if (strlen($source) > CEOL_UPDATE_MAX_BYTES) throw new RuntimeException('The update file exceeds the 2MB limit.');
      }
    } finally {
      fclose($stream);
    }
  }
  if (strlen($source) < 20000 || substr($source, 0, 5) !== '<?php') {
    throw new RuntimeException('GitHub returned an incomplete or invalid application file. Nothing was installed.');
  }
  return $source;
}

function ceol_source_version(string $source): string {
  if (!preg_match("/^const APP_VERSION = '([^']+)';[\r\n]/m", $source, $match)) {
    throw new RuntimeException('The online file has no valid application version.');
  }
  $version = $match[1];
  // Recognize the previous version label when this updater is first deployed.
  if (preg_match('/^skin-studio-v8-\d{4}-\d{2}-\d{2}$/', $version)
      && strpos($source, 'CEOL SKIN STUDIO V8') !== false) return '8.0.0';
  if (!preg_match('/^(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})$/', $version)
      || strpos($source, "const APP_ID = '" . APP_ID . "';") === false) {
    throw new RuntimeException('The online file is not a versioned Denon CEOL Remote release.');
  }
  return $version;
}

function ceol_stage_update_file(string $file, string $source, int $mode): string {
  $temp = @tempnam(dirname($file), '._ceol_update_');
  if ($temp === false) throw new RuntimeException('Give PHP write permission to the application folder before updating.');
  if (dirname($temp) !== dirname($file) || @file_put_contents($temp, $source) !== strlen($source)
      || !@chmod($temp, $mode)) {
    @unlink($temp);
    throw new RuntimeException('Cannot prepare the update. Check folder permissions and free disk space.');
  }
  return $temp;
}

function ceol_discard_update_cache(string $sourceHash): void {
  try {
    ceol_mutate_json(CEOL_PREFS_FILE, '', ceol_default_preferences(), function ($data) use ($sourceHash) {
      if (isset($data['updateCache']['sourceHash']) && $data['updateCache']['sourceHash'] === $sourceHash) unset($data['updateCache']);
      return $data;
    });
  } catch (RuntimeException $error) { /* An unavailable cache must not mask the update error. */ }
}

function ceol_install_update(array $offer): void {
  $file = realpath(__FILE__);
  if ($file === false) throw new RuntimeException('Cannot locate the running application file.');
  $lock = @fopen($file . '.update.lock', 'c');
  if (!$lock) throw new RuntimeException('Give PHP write permission to the application folder before updating.');
  @chmod($file . '.update.lock', 0600);
  $temp = false;
  $backupTemp = false;
  try {
    if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another update is in progress. Wait, then reload the page.');
    $current = @file_get_contents($file);
    if ($current === false || !hash_equals($offer['localHash'], hash('sha256', $current))) {
      throw new RuntimeException('The local application changed after the check. Reload and check for updates again.');
    }
    $source = ceol_download_update();
    if (!hash_equals($offer['sourceHash'], hash('sha256', $source)) || ceol_source_version($source) !== $offer['version']) {
      ceol_discard_update_cache($offer['sourceHash']);
      throw new RuntimeException('The GitHub release changed after your confirmation. Check again and confirm the new release.');
    }
    if (!version_compare($offer['version'], APP_VERSION, '>')) throw new RuntimeException('Only a newer version can be installed.');
    if (!function_exists('token_get_all') || !defined('TOKEN_PARSE')) {
      throw new RuntimeException('Enable the PHP tokenizer extension so the update can be checked before installation.');
    }
    try { token_get_all($source, TOKEN_PARSE); }
    catch (Throwable $error) { throw new RuntimeException('The update has PHP syntax incompatible with this server. Nothing was installed.'); }
    $permissions = @fileperms($file);
    if ($permissions === false) throw new RuntimeException('Cannot read application file permissions.');
    $temp = ceol_stage_update_file($file, $source, $permissions & 0777);
    // __halt_compiler prevents the original PHP code (including strict_types) from
    // being parsed or executed. Remove the first line to restore this backup.
    $backup = __DIR__ . '/.denon-ceol-backup.php';
    $guard = "<?php http_response_code(404); exit; __halt_compiler(); ?>\n";
    $backupTemp = ceol_stage_update_file($backup, $guard . $current, 0600);
    if (!@rename($backupTemp, $backup)) throw new RuntimeException('Cannot save the recovery backup. Nothing was installed.');
    $backupTemp = false;
    if (!@rename($temp, $file)) throw new RuntimeException('Cannot replace the application file. The current version is still running.');
    $temp = false;
    clearstatcache(true, $file);
    if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
  } finally {
    if ($temp !== false) @unlink($temp);
    if ($backupTemp !== false) @unlink($backupTemp);
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

function ceol_update_route(string $action): void {
  if ($action === 'update_check') {
    $prefs = ceol_preferences();
    $cache = isset($prefs['updateCache']) && is_array($prefs['updateCache']) ? $prefs['updateCache'] : array();
    $validCache = isset($cache['checkedAt'], $cache['version'], $cache['sourceHash'])
      && is_int($cache['checkedAt']) && $cache['checkedAt'] <= time()
      && is_string($cache['version']) && preg_match('/^\d+\.\d+\.\d+$/', $cache['version'])
      && is_string($cache['sourceHash']) && preg_match('/^[a-f0-9]{64}$/', $cache['sourceHash']);
    $age = $validCache ? time() - $cache['checkedAt'] : PHP_INT_MAX;
    $force = isset($_POST['force']) && $_POST['force'] === '1';
    // A short shared cooldown prevents repeated manual checks from flooding GitHub.
    if ($age >= CEOL_UPDATE_CACHE_SECONDS || ($force && $age >= 30)) {
      $source = ceol_download_update();
      $cache = array('checkedAt' => time(), 'version' => ceol_source_version($source), 'sourceHash' => hash('sha256', $source));
      // Cache writes do not change the layout revision or invalidate editor drafts.
      try {
        ceol_mutate_json(CEOL_PREFS_FILE, '', ceol_default_preferences(), function ($data) use ($cache) {
          $data['updateCache'] = $cache;
          return $data;
        });
      } catch (RuntimeException $error) { /* Checking also works on a read-only deployment. */ }
    }
    $available = version_compare($cache['version'], APP_VERSION, '>');
    $ticket = '';
    if ($available) {
      $localHash = @hash_file('sha256', __FILE__);
      if ($localHash === false) throw new RuntimeException('Cannot read the local application file.');
      $ticket = bin2hex(random_bytes(24));
      $_SESSION['ceol_update_offer'] = array('ticket' => $ticket, 'version' => $cache['version'],
        'sourceHash' => $cache['sourceHash'], 'localHash' => $localHash, 'expires' => time() + 3600);
    } else unset($_SESSION['ceol_update_offer']);
    json_out(array('ok' => true, 'currentVersion' => APP_VERSION, 'latestVersion' => $cache['version'],
      'available' => $available, 'ticket' => $ticket, 'checkedAt' => $cache['checkedAt']));
  }
  if ($action === 'update_install') {
    ceol_require_editor();
    $ticket = isset($_POST['ticket']) && is_string($_POST['ticket']) ? $_POST['ticket'] : '';
    $version = isset($_POST['version']) && is_string($_POST['version']) ? $_POST['version'] : '';
    $offer = isset($_SESSION['ceol_update_offer']) ? $_SESSION['ceol_update_offer'] : array();
    if (!isset($_POST['confirmed']) || $_POST['confirmed'] !== '1' || empty($offer['ticket'])
        || !hash_equals($offer['ticket'], $ticket) || $version !== $offer['version'] || $offer['expires'] < time()) {
      throw new RuntimeException('Check for updates and confirm the version to install first.');
    }
    ceol_install_update($offer);
    unset($_SESSION['ceol_update_offer']);
    json_out(array('ok' => true, 'version' => $offer['version']));
  }
}

function ceol_text($value, int $limit): string {
  if (!is_string($value) || strlen($value) > $limit * 4 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
    throw new RuntimeException('A text property is invalid or too long.');
  }
  return $value;
}

function ceol_number($value, float $min, float $max): float {
  if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < $min || (float)$value > $max) {
    throw new RuntimeException('A size or effect property is outside its allowed range.');
  }
  return (float)$value;
}

function ceol_color($value, bool $inherit = false): string {
  if ($inherit && $value === '') return '';
  if (!is_string($value) || !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) throw new RuntimeException('Use six-digit hex colors.');
  return strtolower($value);
}

function ceol_choice($value, array $choices): string {
  if (!is_string($value) || !in_array($value, $choices, true)) throw new RuntimeException('An editor property is invalid.');
  return $value;
}

function ceol_validate_theme(array $input): array {
  $theme = array();
  $theme['id'] = ceol_text(isset($input['id']) ? $input['id'] : '', 60);
  if (!preg_match('/^custom-[a-z0-9-]{1,48}$/', $theme['id'])) throw new RuntimeException('Invalid custom theme identifier.');
  $theme['name'] = ceol_text(isset($input['name']) ? $input['name'] : '', 60);
  if (trim($theme['name']) === '') throw new RuntimeException('Give this theme a name.');
  $theme['mode'] = ceol_choice(isset($input['mode']) ? $input['mode'] : '', array('light', 'dark'));
  $theme['font'] = ceol_choice(isset($input['font']) ? $input['font'] : 'system', array('system', 'rounded', 'mono', 'serif'));
  $theme['radius'] = ceol_number(isset($input['radius']) ? $input['radius'] : 20, 0, 100);
  $theme['buttonDepth'] = ceol_number(isset($input['buttonDepth']) ? $input['buttonDepth'] : 0, 0, 20);
  $theme['buttonShadow'] = ceol_number(isset($input['buttonShadow']) ? $input['buttonShadow'] : 8, 0, 40);
  $theme['hoverEffect'] = ceol_choice(isset($input['hoverEffect']) ? $input['hoverEffect'] : 'lift', array('none', 'lift', 'grow', 'glow'));
  $colors = isset($input['colors']) && is_array($input['colors']) ? $input['colors'] : array();
  foreach (array('background', 'surface', 'button', 'text', 'muted', 'accent', 'accentText', 'border', 'hover', 'hoverText', 'display', 'displayText') as $key) {
    $theme['colors'][$key] = ceol_color(isset($colors[$key]) ? $colors[$key] : null);
  }
  $kinds = array('button', 'display', 'volume', 'state', 'slider', 'favorite', 'manual', 'fallback', 'log', 'label', 'panel');
  $icons = array('none', 'power', 'server', 'bluetooth', 'plus', 'minus', 'up', 'down', 'left', 'right', 'check', 'play', 'pause', 'stop', 'next', 'previous', 'volume', 'mute', 'disc', 'radio', 'usb', 'cable', 'refresh', 'network', 'heart', 'send', 'info');
  foreach (array('mini', 'full') as $view) {
    $layout = isset($input['layouts'][$view]) && is_array($input['layouts'][$view]) ? $input['layouts'][$view] : array();
    $width = ceol_number(isset($layout['width']) ? $layout['width'] : 0, 280, 1800);
    $height = ceol_number(isset($layout['height']) ? $layout['height'] : 0, 200, 3000);
    if (!isset($layout['nodes']) || !is_array($layout['nodes']) || count($layout['nodes']) > 200) {
      throw new RuntimeException('Each layout can have up to 200 items.');
    }
    $nodes = array();
    $ids = array();
    foreach ($layout['nodes'] as $raw) {
      if (!is_array($raw)) throw new RuntimeException('An item is invalid.');
      $id = ceol_text(isset($raw['id']) ? $raw['id'] : '', 64);
      if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) || isset($ids[$id])) throw new RuntimeException('Item identifiers must be unique.');
      $ids[$id] = true;
      $node = array('id' => $id, 'kind' => ceol_choice(isset($raw['kind']) ? $raw['kind'] : '', $kinds));
      foreach (array('x', 'y', 'w', 'h') as $key) {
        $node[$key] = ceol_number(isset($raw[$key]) ? $raw[$key] : -1, ($key === 'w' || $key === 'h') ? 24 : 0, ($key === 'x' || $key === 'w') ? $width : $height);
      }
      if ($node['x'] + $node['w'] > $width + 0.1 || $node['y'] + $node['h'] > $height + 0.1) {
        throw new RuntimeException('An item extends beyond its canvas. Enlarge the canvas or move the item.');
      }
      $node['label'] = ceol_text(isset($raw['label']) ? $raw['label'] : '', 120);
      $node['icon'] = ceol_choice(isset($raw['icon']) ? $raw['icon'] : 'none', $icons);
      $node['action'] = isset($raw['action']) ? ceol_text($raw['action'], 60) : '';
      if ($node['kind'] === 'button' && !in_array($node['action'], array('toggle_power', 'toggle_mute', 'refresh', 'test'), true)
          && !is_allowed_denon_command($node['action'])) {
        throw new RuntimeException('Choose an allowed Denon command for each button.');
      }
      if ($node['kind'] !== 'button') $node['action'] = '';
      $node['key'] = ceol_choice(isset($raw['key']) ? $raw['key'] : 'power', array('power', 'source', 'mute', 'tuner'));
      $node['repeat'] = !empty($raw['repeat']) && $node['kind'] === 'button';
      $node['hidden'] = !empty($raw['hidden']);
      $style = isset($raw['style']) && is_array($raw['style']) ? $raw['style'] : array();
      $node['style'] = array();
      foreach (array('bg', 'fg', 'hoverBg', 'hoverFg', 'borderColor', 'gradient') as $key) {
        $node['style'][$key] = ceol_color(isset($style[$key]) ? $style[$key] : '', true);
      }
      $ranges = array('radius' => array(-1, 100, -1), 'fontSize' => array(10, 80, 14), 'iconSize' => array(12, 100, 24),
        'borderWidth' => array(0, 8, 1), 'depth' => array(-1, 20, -1), 'shadow' => array(-1, 40, -1), 'opacity' => array(0.2, 1, 1));
      foreach ($ranges as $key => $range) $node['style'][$key] = ceol_number(isset($style[$key]) ? $style[$key] : $range[2], $range[0], $range[1]);
      $node['style']['hoverEffect'] = ceol_choice(isset($style['hoverEffect']) ? $style['hoverEffect'] : 'theme', array('theme', 'none', 'lift', 'grow', 'glow'));
      $node['style']['pressEffect'] = ceol_choice(isset($style['pressEffect']) ? $style['pressEffect'] : 'sink', array('none', 'sink', 'shrink'));
      $node['style']['alignment'] = ceol_choice(isset($style['alignment']) ? $style['alignment'] : 'center', array('left', 'center', 'right'));
      $node['style']['weight'] = ceol_choice(isset($style['weight']) ? $style['weight'] : '600', array('400', '500', '600', '700', '800'));
      $node['style']['iconOnly'] = !empty($style['iconOnly']);
      $node['style']['tone'] = ceol_choice(isset($style['tone']) ? $style['tone'] : 'default', array('default', 'accent'));
      $nodes[] = $node;
    }
    $theme['layouts'][$view] = array('width' => $width, 'height' => $height, 'nodes' => $nodes);
  }
  return $theme;
}

function ceol_check_revision(array $data): void {
  if (!isset($_POST['revision']) || (string)(int)$_POST['revision'] !== (string)$_POST['revision']
      || (int)$_POST['revision'] !== (int)$data['revision']) {
    http_response_code(409);
    throw new RuntimeException('Preferences changed in another tab. Reload saved preferences before saving your draft.');
  }
}

function ceol_skin_route(string $action): void {
  if ($action === 'editor_state') {
    $security = ceol_admin_data();
    json_out(array('ok' => true, 'setupRequired' => empty($security['passwordHash']),
      'authenticated' => ceol_editor_authenticated($security), 'preferences' => ceol_preferences()));
  }
  if ($action === 'editor_setup' || $action === 'editor_login') {
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    if (strlen($password) < 8 || strlen($password) > 72) throw new RuntimeException('Use a password between 8 and 72 bytes.');
    $client = hash('sha256', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'local');
    $error = '';
    $security = ceol_mutate_json(CEOL_ADMIN_FILE, CEOL_ADMIN_GUARD, array(), function ($data) use ($action, $password, $client, &$error) {
      if ($action === 'editor_setup') {
        if (!empty($data['passwordHash'])) throw new RuntimeException('An admin password already exists. Sign in instead.');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) throw new RuntimeException('Cannot create the admin password.');
        return array('passwordHash' => $hash, 'authVersion' => bin2hex(random_bytes(16)), 'attempts' => array());
      }
      if (empty($data['passwordHash'])) throw new RuntimeException('Create the admin password first.');
      $attempts = isset($data['attempts']) && is_array($data['attempts']) ? $data['attempts'] : array();
      foreach ($attempts as $key => $attempt) {
        if (!isset($attempt['until']) || $attempt['until'] < time()) unset($attempts[$key]);
      }
      $attempt = isset($attempts[$client]) ? $attempts[$client] : array('count' => 0, 'until' => time() + 900);
      if ($attempt['count'] >= 5) {
        http_response_code(429);
        $error = 'Too many failed logins. Try again in 15 minutes.';
      } elseif (!password_verify($password, $data['passwordHash'])) {
        $attempt['count']++;
        $attempts[$client] = $attempt;
        $error = 'Incorrect admin password.';
      } else {
        unset($attempts[$client]);
      }
      $data['attempts'] = array_slice($attempts, -200, null, true);
      return $data;
    });
    if ($error !== '') json_out(array('ok' => false, 'error' => $error));
    session_regenerate_id(true);
    $_SESSION['ceol_admin_version'] = $security['authVersion'];
    $_SESSION['ceol_admin_seen'] = time();
    $_SESSION['ceol_csrf'] = bin2hex(random_bytes(24));
    json_out(array('ok' => true, 'csrf' => $_SESSION['ceol_csrf'], 'authenticated' => true, 'preferences' => ceol_preferences()));
  }
  if ($action === 'editor_logout') {
    unset($_SESSION['ceol_admin_version'], $_SESSION['ceol_admin_seen']);
    session_regenerate_id(true);
    $_SESSION['ceol_csrf'] = bin2hex(random_bytes(24));
    json_out(array('ok' => true, 'csrf' => $_SESSION['ceol_csrf']));
  }
  if ($action === 'editor_password') {
    ceol_require_editor();
    $old = isset($_POST['currentPassword']) && is_string($_POST['currentPassword']) ? $_POST['currentPassword'] : '';
    $new = isset($_POST['newPassword']) && is_string($_POST['newPassword']) ? $_POST['newPassword'] : '';
    if (strlen($new) < 8 || strlen($new) > 72) throw new RuntimeException('Use a password between 8 and 72 bytes.');
    $security = ceol_mutate_json(CEOL_ADMIN_FILE, CEOL_ADMIN_GUARD, array(), function ($data) use ($old, $new) {
      if (empty($data['passwordHash']) || !password_verify($old, $data['passwordHash'])) throw new RuntimeException('Incorrect current password.');
      $hash = password_hash($new, PASSWORD_DEFAULT);
      if ($hash === false) throw new RuntimeException('Cannot save the admin password.');
      $data['passwordHash'] = $hash;
      $data['authVersion'] = bin2hex(random_bytes(16));
      return $data;
    });
    $_SESSION['ceol_admin_version'] = $security['authVersion'];
    json_out(array('ok' => true));
  }
  if ($action === 'skin_validate' || $action === 'skin_save') {
    ceol_require_editor();
    $raw = isset($_POST['theme']) && is_string($_POST['theme']) ? $_POST['theme'] : '';
    if (strlen($raw) > 1572864) throw new RuntimeException('The theme is too large.');
    $theme = json_decode($raw, true);
    if (!is_array($theme)) throw new RuntimeException('The theme JSON is invalid.');
    $theme = ceol_validate_theme($theme);
    if ($action === 'skin_validate') json_out(array('ok' => true, 'theme' => $theme));
    $data = ceol_update_preferences(function ($data) use ($theme) {
      ceol_check_revision($data);
      $found = false;
      foreach ($data['customThemes'] as $i => $saved) {
        if ($saved['id'] === $theme['id']) { $data['customThemes'][$i] = $theme; $found = true; break; }
      }
      if (!$found) {
        if (count($data['customThemes']) >= 20) throw new RuntimeException('You can save up to 20 custom themes. Delete one first.');
        $data['customThemes'][] = $theme;
      }
      $data['activeTheme'] = $theme['id'];
      return $data;
    });
    json_out(array('ok' => true, 'preferences' => $data));
  }
  if ($action === 'skin_delete') {
    ceol_require_editor();
    $id = isset($_POST['themeId']) && is_string($_POST['themeId']) ? $_POST['themeId'] : '';
    $data = ceol_update_preferences(function ($data) use ($id) {
      ceol_check_revision($data);
      $data['customThemes'] = array_values(array_filter($data['customThemes'], function ($theme) use ($id) { return $theme['id'] !== $id; }));
      if ($data['activeTheme'] === $id) $data['activeTheme'] = 'porcelain';
      return $data;
    });
    json_out(array('ok' => true, 'preferences' => $data));
  }
  if ($action === 'skin_select') {
    $data = ceol_update_preferences(function ($data) {
      $ids = array_merge(ceol_preset_ids(), array_column($data['customThemes'], 'id'));
      if (isset($_POST['themeId'])) $data['activeTheme'] = ceol_choice($_POST['themeId'], $ids);
      if (isset($_POST['view'])) $data['activeView'] = ceol_choice($_POST['view'], array('mini', 'full'));
      if (isset($_POST['fallback'])) $data['useHttpFallback'] = $_POST['fallback'] === '1';
      return $data;
    });
    json_out(array('ok' => true, 'preferences' => $data));
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
  $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
  try {
    ceol_require_csrf();
    ceol_update_route($action);
    ceol_skin_route($action);
  } catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(400);
    json_out(array('ok' => false, 'error' => $error instanceof RuntimeException ? $error->getMessage() : 'The request could not be processed.'));
  }

  if ($action === 'save_ip') {
    list($ok, $ip, $error) = validate_denon_ip(isset($_POST['ip']) ? (string)$_POST['ip'] : '');
    if (!$ok) {
      json_out(array('ok' => false, 'error' => $error));
    }

    try {
      $prefs = ceol_update_preferences(function ($prefs) use ($ip) {
        $prefs = ceol_connection_settings($_POST, $prefs);
        $prefs['denonIp'] = $ip;
        return $prefs;
      });
    } catch (Throwable $error) {
      json_out(array('ok' => false, 'error' => $error->getMessage()));
    }
    $_SESSION['denon_ip'] = $ip;
    json_out(array('ok' => true, 'ip' => $ip, 'preferences' => $prefs));
  }

  if ($action === 'reset_ip') {
    try {
      $prefs = ceol_update_preferences(function ($prefs) { $prefs['denonIp'] = ''; return $prefs; });
    } catch (Throwable $error) {
      json_out(array('ok' => false, 'error' => $error->getMessage()));
    }
    unset($_SESSION['denon_ip']);
    json_out(array('ok' => true, 'preferences' => $prefs));
  }

  // A stale tab/client must not accidentally relay while direct mode is selected.
  if (in_array($action, array('command', 'status', 'test', 'volume_set', 'favorite'), true)) {
    try {
      if (ceol_preferences()['connectionMode'] === 'direct') {
        http_response_code(409);
        json_out(array('ok' => false, 'error' => 'Browser direct is selected. No PHP-to-Denon request was made. Reload this page or explicitly select Server relay in Connection settings.'));
      }
    } catch (Throwable $error) {
      json_out(array('ok' => false, 'error' => $error->getMessage()));
    }
  }

  $ip = current_denon_ip();
  if ($ip === '') {
    json_out(array('ok' => false, 'error' => 'No Denon IP configured.'));
  }

  // Release the browser session lock before waiting for the amplifier.
  session_write_close();

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
$ceolStorageError = '';
try {
  $ceolPrefs = ceol_preferences();
  $ceolSecurity = ceol_admin_data();
} catch (Throwable $error) {
  $ceolPrefs = ceol_default_preferences();
  $ceolSecurity = array();
  $ceolStorageError = $error->getMessage();
}
if ($ceolPrefs['denonIp'] === '') $ceolPrefs['denonIp'] = $configuredIp;
$ceolHttpPaths = array();
foreach (allowed_fixed_commands() as $command) $ceolHttpPaths[$command] = denon_http_command_path($command);
$ceolBoot = array('preferences' => $ceolPrefs, 'csrf' => $_SESSION['ceol_csrf'],
  'setupRequired' => empty($ceolSecurity['passwordHash']), 'authenticated' => ceol_editor_authenticated($ceolSecurity),
  'allowedCommands' => allowed_fixed_commands(), 'httpCommandPaths' => $ceolHttpPaths,
  'allowPublicDenonIp' => ALLOW_PUBLIC_DENON_IP, 'sourceLabels' => source_labels(), 'storageError' => $ceolStorageError,
  'version' => APP_VERSION);
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f3f5f7" id="browserThemeColor">
  <title><?= h(APP_TITLE) ?></title>
  <style>
    :root { --background:#f3f5f7; --surface:#ffffff; --button:#edf1f5; --text:#18232f; --muted:#657282; --accent:#2563eb; --accent-text:#ffffff; --border:#dbe2ea; --hover:#dce7fb; --hover-text:#18232f; --display:#162331; --display-text:#a7edc6; --radius:20px; --font:system-ui,-apple-system,"Segoe UI",sans-serif; color-scheme:light; }
    * { box-sizing:border-box; }
    body { margin:0; background:var(--background); color:var(--text); font-family:var(--font); }
    button,input,select,textarea { font:inherit; }
    button { cursor:pointer; }
    button:disabled { opacity:.45; cursor:not-allowed; }
    button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible { outline:3px solid var(--accent); outline-offset:3px; }
    [hidden] { display:none!important; }
    svg { width:20px; height:20px; fill:none; stroke:currentColor; stroke-width:1.7; stroke-linecap:round; stroke-linejoin:round; flex:none; }
    .app { max-width:1440px; margin:0 auto; padding:28px 32px 40px; }
    .app-header { display:flex; align-items:center; justify-content:space-between; gap:24px; border-bottom:1px solid var(--border); padding-bottom:24px; }
    .brand { display:flex; align-items:center; gap:14px; }
    .brand-mark { width:44px; height:44px; border:2px solid var(--text); border-radius:50%; display:grid; place-items:center; }
    .brand-mark svg { width:23px; height:23px; }
    .eyebrow { font-size:10px; font-weight:700; letter-spacing:.17em; text-transform:uppercase; color:var(--muted); }
    h1 { margin:3px 0 0; font-size:24px; letter-spacing:-.04em; font-weight:650; }
    .header-actions,.inline,.toolbar,.segmented { display:flex; align-items:center; gap:9px; flex-wrap:wrap; }
    .ui-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:40px; padding:9px 14px; border:1px solid var(--border); border-radius:10px; background:var(--surface); color:var(--text); font-size:13px; font-weight:600; }
    .ui-btn:hover { background:var(--hover); color:var(--hover-text); }
    .ui-btn.primary { background:var(--accent); color:var(--accent-text); border-color:var(--accent); }
    .ui-btn.danger { color:#d14343; }
    .ui-btn.icon { padding:8px; min-width:38px; }
    .status { display:flex; align-items:center; gap:7px; color:var(--muted); font-size:12px; }
    .dot { width:7px; height:7px; border-radius:50%; background:#dba052; flex:none; }
    .dot.online { background:#29a96b; }
    .workspace-heading { display:flex; align-items:flex-end; justify-content:space-between; gap:20px; padding:28px 0 22px; }
    .workspace-heading h2 { font-size:30px; letter-spacing:-.04em; font-weight:600; margin:7px 0 4px; }
    .workspace-heading p { color:var(--muted); font-size:13px; margin:0; }
    .segmented { padding:4px; gap:3px; background:var(--button); border:1px solid var(--border); border-radius:13px; }
    .segmented button { border:0; border-radius:9px; background:transparent; color:var(--muted); padding:9px 15px; font-size:13px; font-weight:600; }
    .segmented button.active { background:var(--surface); color:var(--text); box-shadow:0 2px 6px #0000000b; }
    .runtime-stage { border:1px solid var(--border); border-radius:var(--radius); background:var(--surface); padding:22px; box-shadow:0 16px 50px #00000006; overflow:auto; }
    .runtime-stage.mini { width:min(100%,440px); margin:0 auto; }
    .canvas-viewport { position:relative; margin:0 auto; }
    .skin-canvas { position:relative; transform-origin:top left; background:var(--surface); color:var(--text); isolation:isolate; }
    .skin-node { position:absolute; min-width:0; overflow:visible; }
    .node-face { width:100%; height:100%; border:var(--node-border-width,1px) solid var(--node-border,var(--border)); border-radius:var(--node-radius,16px); background:var(--node-background,var(--button)); color:var(--node-color,var(--text)); font:var(--node-weight,600) var(--node-font-size,14px)/1.2 var(--font); display:flex; align-items:center; justify-content:var(--node-align,center); gap:8px; padding:8px 12px; text-align:var(--node-text-align,center); overflow:hidden; opacity:var(--node-opacity,1); transition:background-color .18s,color .18s,transform .18s,box-shadow .18s; box-shadow:0 var(--node-depth,0px) 0 var(--node-border,var(--border)),0 calc(var(--node-shadow,8px)/2) var(--node-shadow,8px) #00000012; }
    .node-face svg { width:var(--node-icon-size,24px); height:var(--node-icon-size,24px); }
    .button-node .node-face:hover { background:var(--node-hover,var(--hover)); color:var(--node-hover-color,var(--hover-text)); }
    .button-node[data-hover="lift"] .node-face:hover { transform:translateY(-3px); }
    .button-node[data-hover="grow"] .node-face:hover { transform:scale(1.035); }
    .button-node[data-hover="glow"] .node-face:hover { box-shadow:0 0 0 2px var(--accent),0 0 24px var(--accent); }
    .button-node[data-press="sink"] .node-face:active { transform:translateY(max(2px,var(--node-depth,0px))); box-shadow:0 0 0 transparent; }
    .button-node[data-press="shrink"] .node-face:active { transform:scale(.95); }
    .node-face.is-active { outline:2px solid var(--accent); outline-offset:2px; }
    .node-face.icon-only { padding:5px; }
    .node-face.icon-only .node-label { position:absolute; width:1px; height:1px; overflow:hidden; clip-path:inset(50%); }
    .display-node .node-face { background:var(--node-background,var(--display)); color:var(--node-color,var(--display-text)); display:block; padding:12px 14px; font-family:ui-monospace,Consolas,monospace; }
    .display-content { height:100%; display:flex; flex-direction:column; justify-content:center; gap:3px; overflow:hidden; }
    .display-line { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; line-height:1.35; font-weight:400; }
    .display-line.empty { opacity:.3; }
    .volume-node .node-face,.state-node .node-face { flex-direction:column; gap:4px; }
    .state-node .node-face { padding:7px 8px; }
    .live-caption { font-size:.72em; font-weight:500; color:var(--muted); text-transform:uppercase; letter-spacing:.12em; }
    .volume-number { font-size:2em; letter-spacing:-.05em; font-weight:650; }
    .state-value { font-size:1em; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .slider-node .node-face,.favorite-node .node-face,.manual-node .node-face { gap:10px; }
    .slider-node input { min-width:40px; width:100%; accent-color:var(--accent); }
    .node-face input:not([type="range"]):not([type="checkbox"]) { min-width:0; width:100%; background:var(--surface); color:var(--text); border:1px solid var(--border); border-radius:7px; padding:8px; font-weight:400; }
    .node-face .inline-action { flex:none; padding:8px; display:grid; place-items:center; border:0; border-radius:8px; background:var(--accent); color:var(--accent-text); }
    .fallback-node .node-face { font-size:12px; font-weight:400; justify-content:flex-start; }
    .fallback-node input { accent-color:var(--accent); }
    .log-node .node-face { display:block; text-align:left; padding:12px; }
    .log-content { margin:0; font:11px/1.6 ui-monospace,Consolas,monospace; white-space:pre-wrap; overflow:auto; height:100%; font-weight:400; }
    .label-node .node-face { border:0; background:transparent; box-shadow:none; padding:0; }
    .panel-node .node-face { background:var(--node-background,var(--button)); pointer-events:none; box-shadow:none; }
    .app-footer { display:flex; justify-content:space-between; gap:15px; flex-wrap:wrap; font-size:11px; color:var(--muted); margin-top:20px; }
    .update-link { background:none; border:0; padding:0; color:var(--muted); font-size:11px; text-decoration:underline; text-underline-offset:3px; }
    .update-link.available { color:var(--accent); font-weight:700; }
    .update-versions { display:flex; align-items:center; justify-content:center; gap:20px; padding:20px; margin:16px 0; border:1px solid var(--border); border-radius:12px; }
    .update-versions strong { display:block; margin-top:4px; font-size:20px; color:var(--text); }
    .update-source { color:var(--accent); overflow-wrap:anywhere; }
    .overlay { position:fixed; inset:0; z-index:100; display:grid; place-items:center; background:#0b142c80; backdrop-filter:blur(7px); padding:20px; }
    .dialog { background:var(--surface); color:var(--text); border:1px solid var(--border); border-radius:20px; box-shadow:0 30px 100px #00000030; width:min(100%,460px); max-height:90vh; overflow:auto; padding:24px; }
    .dialog.wide { width:min(100%,940px); }
    .dialog-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
    .dialog h2 { margin:0 0 7px; font-size:23px; letter-spacing:-.03em; }
    .dialog p { color:var(--muted); font-size:13px; line-height:1.6; }
    .field { display:flex; flex-direction:column; gap:5px; font-size:12px; margin:12px 0; min-width:0; }
    .field>span { color:var(--muted); }
    .field input,.field select,.field textarea,.toolbar select,.toolbar input { width:100%; min-width:0; border:1px solid var(--border); background:var(--surface); color:var(--text); border-radius:8px; padding:9px 10px; }
    .field input[type="color"] { height:35px; padding:3px; cursor:pointer; }
    .form-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .form-row .field { margin:5px 0; }
    .check-field { display:flex; align-items:center; gap:8px; font-size:12px; margin:10px 0; }
    .check-field input { accent-color:var(--accent); }
    .message { font-size:12px; color:var(--muted); min-height:20px; line-height:1.5; }
    .message.error { color:#ce4444; }
    .connection-notice { margin:14px 0; padding:12px 14px; border:1px solid var(--border); border-radius:12px; background:var(--surface); font-size:12px; line-height:1.6; overflow-wrap:anywhere; }
    .connection-notice.error { border-color:#ce4444; }
    .theme-group { margin:24px 0 8px; font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.1em; }
    .theme-grid { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:12px; }
    .theme-card { text-align:left; background:var(--surface); color:var(--text); border:1px solid var(--border); border-radius:13px; padding:8px; }
    .theme-card.selected { outline:2px solid var(--accent); outline-offset:2px; }
    .theme-sample { height:105px; display:grid; place-items:center; border-radius:9px; border:1px solid #88888822; position:relative; }
    .sample-remote { display:grid; grid-template-columns:repeat(3,13px); gap:5px; padding:9px; border-radius:11px; }
    .sample-remote i { display:block; width:13px; height:13px; border-radius:5px; }
    .sample-remote i:first-child { grid-column:span 3; width:100%; height:17px; margin-bottom:3px; }
    .theme-card b { display:block; font-size:12px; margin:9px 4px 3px; }
    .theme-card small { color:var(--muted); margin:0 4px 4px; font-size:10px; display:block; }
    .toast { position:fixed; bottom:25px; left:50%; transform:translateX(-50%); z-index:500; max-width:calc(100% - 30px); background:var(--text); color:var(--surface); border-radius:12px; padding:12px 18px; font-size:13px; box-shadow:0 5px 30px #00000025; pointer-events:none; }
    .editor-shell { position:fixed; inset:0; z-index:200; background:var(--background); color:var(--text); display:flex; flex-direction:column; }
    .editor-header { background:var(--surface); border-bottom:1px solid var(--border); padding:12px 18px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
    .editor-title { font-size:14px; font-weight:700; display:flex; align-items:center; gap:10px; }
    .editor-title small { font-size:11px; color:var(--muted); font-weight:400; }
    .editor-header .ui-btn { font-size:12px; min-height:34px; padding:7px 10px; }
    .editor-workspace { min-height:0; flex:1; display:grid; grid-template-columns:225px minmax(200px,1fr) 285px; }
    .editor-sidebar { overflow:auto; background:var(--surface); padding:16px; border-right:1px solid var(--border); }
    .editor-properties { border-right:0; border-left:1px solid var(--border); }
    .editor-properties>.palette-tabs { position:sticky; top:-1px; z-index:2; background:var(--surface); padding:4px 0; }
    .sidebar-heading { margin:0 0 12px; font-size:12px; font-weight:700; }
    .sidebar-note { font-size:11px; color:var(--muted); line-height:1.6; }
    .palette-tabs { display:flex; gap:5px; margin-bottom:12px; }
    .palette-tabs button { flex:1; border:1px solid var(--border); background:var(--button); color:var(--text); font-size:11px; border-radius:7px; padding:7px; }
    .palette-tabs button.active { background:var(--accent); color:var(--accent-text); border-color:var(--accent); }
    .palette-search { width:100%; border:1px solid var(--border); border-radius:8px; background:var(--background); color:var(--text); padding:9px; font-size:12px; margin-bottom:12px; }
    .palette-heading { font-size:10px; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); margin:18px 0 8px; }
    .palette-item { width:100%; display:flex; align-items:center; gap:9px; text-align:left; border:1px solid var(--border); background:var(--surface); color:var(--text); border-radius:8px; padding:9px; margin-bottom:6px; font-size:11px; }
    .palette-item:hover { background:var(--hover); color:var(--hover-text); }
    .palette-item svg { width:17px; height:17px; }
    .palette-item span { min-width:0; }
    .palette-item small { display:block; color:var(--muted); font:9px ui-monospace,monospace; margin-top:3px; }
    .editor-center { min-width:0; min-height:0; display:flex; flex-direction:column; }
    .canvas-toolbar { padding:12px 16px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
    .canvas-toolbar .segmented button { font-size:11px; padding:7px 10px; }
    .canvas-toolbar label { font-size:11px; color:var(--muted); }
    .canvas-toolbar select { border:1px solid var(--border); background:var(--surface); color:var(--text); border-radius:7px; padding:7px; font-size:11px; }
    .canvas-scroll { flex:1; min-height:0; overflow:auto; padding:35px; background-image:radial-gradient(var(--border) 1px,transparent 1px); background-size:16px 16px; }
    .editor-canvas-wrap { position:relative; margin:0 auto; }
    .editor-canvas { box-shadow:0 14px 60px #00000015; outline:1px solid var(--border); }
    .editor-canvas.grid-on { background-image:linear-gradient(to right,#88888812 1px,transparent 1px),linear-gradient(to bottom,#88888812 1px,transparent 1px); background-size:8px 8px; }
    .editor-canvas .skin-node { cursor:move; touch-action:none; }
    .editor-canvas .node-face { pointer-events:none; transition:none; }
    .editor-canvas .skin-node.selected { outline:2px solid var(--accent); outline-offset:2px; z-index:250!important; }
    .editor-canvas .skin-node.hidden-node { opacity:.3; outline:1px dashed var(--muted); }
    .editor-canvas .resize-handle { position:absolute; width:10px; height:10px; background:var(--surface); border:2px solid var(--accent); border-radius:3px; z-index:3; touch-action:none; }
    .resize-handle[data-handle="nw"] { top:-6px; left:-6px; cursor:nwse-resize; }
    .resize-handle[data-handle="ne"] { top:-6px; right:-6px; cursor:nesw-resize; }
    .resize-handle[data-handle="sw"] { bottom:-6px; left:-6px; cursor:nesw-resize; }
    .resize-handle[data-handle="se"] { bottom:-6px; right:-6px; cursor:nwse-resize; }
    .resize-handle[data-handle="n"] { top:-6px; left:calc(50% - 5px); cursor:ns-resize; }
    .resize-handle[data-handle="s"] { bottom:-6px; left:calc(50% - 5px); cursor:ns-resize; }
    .resize-handle[data-handle="w"] { left:-6px; top:calc(50% - 5px); cursor:ew-resize; }
    .resize-handle[data-handle="e"] { right:-6px; top:calc(50% - 5px); cursor:ew-resize; }
    .editor-footer { background:var(--surface); border-top:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; gap:10px; padding:9px 18px; font-size:11px; color:var(--muted); }
    .properties-section { border-top:1px solid var(--border); padding-top:12px; margin-top:16px; }
    .properties-section h4 { margin:0 0 10px; font-size:11px; }
    .color-field { display:grid; grid-template-columns:28px 1fr auto; align-items:center; gap:7px; margin:8px 0; font-size:11px; }
    .color-field input { width:28px; height:27px; padding:1px; border:1px solid var(--border); background:transparent; border-radius:5px; }
    .color-field button { background:var(--button); color:var(--muted); border:0; padding:4px; border-radius:4px; font-size:9px; }
    .properties-empty { color:var(--muted); font-size:12px; line-height:1.7; padding:20px 0; }
    .layer-item { display:flex; gap:5px; margin-bottom:5px; }
    .layer-item .palette-item { margin:0; }
    .layer-item .palette-item.active { border-color:var(--accent); background:var(--hover); }
    .context-menu { position:fixed; z-index:400; width:190px; padding:5px; background:var(--surface); color:var(--text); border:1px solid var(--border); border-radius:10px; box-shadow:0 15px 50px #00000025; }
    .context-menu button { width:100%; text-align:left; border:0; border-radius:6px; padding:9px; background:transparent; color:var(--text); font-size:12px; display:flex; align-items:center; gap:8px; }
    .context-menu button:hover { background:var(--hover); color:var(--hover-text); }
    .context-menu hr { border:0; border-top:1px solid var(--border); margin:4px; }
    .mobile-editor-tabs { display:none; }
    .editor-shell[data-preview="1"] .editor-canvas .skin-node { cursor:default; }
    .editor-shell[data-preview="1"] .editor-canvas .node-face { pointer-events:auto; }
    .editor-shell[data-preview="1"] .resize-handle { display:none; }
    .editor-shell[data-preview="1"] .skin-node.selected { outline:0; }
    @media (max-width:1050px) { .editor-workspace { grid-template-columns:190px minmax(180px,1fr) 255px; } .editor-sidebar { padding:12px; } .canvas-scroll { padding:25px; } }
    @media (max-width:780px) {
      .app { padding:20px 16px 25px; } .app-header { align-items:flex-start; flex-wrap:wrap; gap:16px; }
      .header-actions { gap:6px; } .header-actions .ui-btn { font-size:12px; min-height:36px; padding:7px 10px; }
      .workspace-heading { align-items:flex-start; flex-wrap:wrap; padding-top:22px; } .workspace-heading h2 { font-size:26px; }
      .runtime-stage { padding:14px; } .theme-grid { grid-template-columns:repeat(3,minmax(0,1fr)); }
      .editor-workspace { display:block; position:relative; overflow:hidden; } .editor-center { height:100%; }
      .editor-sidebar { display:none; position:absolute; inset:0; z-index:10; width:100%; border:0; }
      .editor-workspace[data-mobile-panel="palette"] #paletteSidebar,.editor-workspace[data-mobile-panel="properties"] #propertiesSidebar { display:block; }
      .mobile-editor-tabs { display:flex; gap:3px; padding:6px 12px; border-bottom:1px solid var(--border); background:var(--surface); }
      .mobile-editor-tabs button { flex:1; font-size:11px; padding:7px; }
      .editor-header { padding:9px 12px; } .editor-header .inline { gap:5px; } .editor-title small { display:none; }
      .editor-header .ui-btn { font-size:11px; padding:6px 8px; } .editor-footer span:last-child { display:none; }
      .canvas-scroll { padding:18px; } .canvas-toolbar { padding:8px 12px; }
      .resize-handle { min-width:16px; min-height:16px; }
      .runtime-stage.full.mobile-flow { overflow:visible; }
      .mobile-flow .canvas-viewport { width:100%!important; height:auto!important; }
      .mobile-flow .skin-canvas { width:100%!important; height:auto!important; transform:none!important; display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
      .mobile-flow .skin-node { position:relative; left:auto!important; top:auto!important; width:auto!important; height:auto!important; min-height:52px; }
      .mobile-flow .label-node,.mobile-flow .display-node,.mobile-flow .slider-node,.mobile-flow .manual-node,.mobile-flow .favorite-node,.mobile-flow .log-node,.mobile-flow .fallback-node { grid-column:1/-1; }
      .mobile-flow .label-node { min-height:30px; margin-top:6px; } .mobile-flow .display-node { height:200px!important; }
      .mobile-flow .volume-node { min-height:100px; } .mobile-flow .log-node { height:160px!important; } .mobile-flow .panel-node { display:none; }
    }
    @media (max-width:420px) { .theme-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .app-header .status { width:100%; } .brand-mark { width:38px; height:38px; } h1 { font-size:21px; } }
    @media (prefers-reduced-motion:reduce) { *,*::before,*::after { transition:none!important; animation:none!important; } .button-node .node-face:hover { transform:none!important; } }
  </style>
</head>
<body>
  <main class="app" id="appRoot">
    <header class="app-header">
      <div class="brand">
        <div class="brand-mark" data-icon="volume"></div>
        <div><div class="eyebrow">Denon / Network control</div><h1>CEOL Remote</h1></div>
      </div>
      <div class="header-actions">
        <div class="status"><span class="dot" id="connDot"></span><span id="connText">Offline</span></div>
        <button class="ui-btn" id="connectionButton" type="button" title="Connection settings"><span data-icon="network"></span><span id="ipText"><?= h($configuredIp !== '' ? $configuredIp : 'Connect') ?></span></button>
        <button class="ui-btn" id="themesButton" type="button"><span data-icon="disc"></span>Themes</button>
        <button class="ui-btn" id="editorButton" type="button"><span data-icon="sliders"></span>Theme editor</button>
      </div>
    </header>
    <div class="workspace-heading">
      <div><div class="eyebrow" id="themeCaption">Porcelain / Light</div><h2 id="viewTitle">Your everyday remote.</h2><p id="viewSubtitle">Power, music, and volume. Everything within reach.</p></div>
      <div class="segmented" aria-label="Remote layout">
        <button id="miniViewButton" class="active" type="button">Mini remote</button>
        <button id="fullViewButton" type="button">All commands</button>
      </div>
    </div>
    <div class="connection-notice" id="connectionNotice" role="status" aria-live="polite" hidden></div>
    <section class="runtime-stage mini" id="runtimeStage" aria-label="Denon remote">
      <div class="canvas-viewport" id="runtimeViewport"><div class="skin-canvas" id="runtimeCanvas"></div></div>
    </section>
    <footer class="app-footer"><span id="transportText">CEOL / RCD-N9 · Server relay</span><span id="footerState">Ready when you are.</span><span class="inline"><span>v<?= h(APP_VERSION) ?></span><button class="update-link" id="checkUpdatesButton" type="button">Check for updates</button></span></footer>
  </main>

  <div class="overlay" id="ipModal" hidden>
    <form class="dialog" id="ipForm">
      <div class="dialog-head"><h2>Connect your CEOL</h2><button class="ui-btn icon" type="button" data-close="ipModal" aria-label="Close">×</button></div>
      <p>Enable Network Control on the Denon, then choose which computer reaches it. A failed request stays in the selected mode.</p>
      <label class="field"><span>Denon IPv4 address</span><input id="denonIpInput" placeholder="192.168.1.45" value="<?= h($configuredIp) ?>" inputmode="decimal" autocomplete="off" required></label>
      <label class="field"><span>Connection mode</span><select id="connectionModeInput"><option value="relay">Server relay · PHP → Denon</option><option value="direct">Browser direct · this browser → Denon</option></select></label>
      <p id="connectionHelp">Server relay needs the PHP host to reach the Denon LAN, locally or through a VPN.</p>
      <div id="directSettings" hidden>
        <div class="form-row">
          <label class="field"><span>Denon protocol</span><select id="directSchemeInput"><option value="http">HTTP</option><option value="https">HTTPS</option></select></label>
          <label class="field"><span>Denon HTTP port</span><input id="directPortInput" type="number" min="1" max="65535" value="80" required></label>
        </div>
        <label class="field"><span>HTTP replies</span><select id="directReadbackInput"><option value="0">Dispatch only · replies unreadable (no-cors)</option><option value="1">Check HTTP replies · requires Denon CORS</option></select></label>
        <p>Dispatch-only replies cannot confirm delivery, HTTP errors, or live status. Direct-mode displays are estimates; Refresh and Test send only a PW? probe. Check HTTP replies detects readable HTTP errors but still does not provide full live status.</p>
        <p id="directPolicyHelp" class="message"></p>
      </div>
      <div class="message error" id="ipError"></div>
      <button class="ui-btn primary" type="submit">Save & connect</button>
    </form>
  </div>
  <div class="overlay" id="themesModal" hidden>
    <div class="dialog wide" role="dialog" aria-modal="true" aria-labelledby="themesTitle">
      <div class="dialog-head"><div><h2 id="themesTitle">Find your finish.</h2><p>Ten built-in themes. Your custom layouts live here too.</p></div><button class="ui-btn icon" type="button" data-close="themesModal" aria-label="Close">×</button></div>
      <div id="themeGallery"></div>
    </div>
  </div>
  <div class="overlay" id="updateModal" style="z-index:280" hidden>
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="updateTitle" aria-describedby="updateDescription">
      <div class="dialog-head"><h2 id="updateTitle">Software updates</h2><button class="ui-btn icon" id="closeUpdateButton" type="button" data-close="updateModal" aria-label="Close">×</button></div>
      <p id="updateDescription" aria-live="polite">Checking GitHub for a newer version…</p>
      <div class="update-versions" id="updateVersions" hidden><div><span class="eyebrow">Installed</span><strong id="installedVersion"></strong></div><span aria-hidden="true">→</span><div><span class="eyebrow">Available</span><strong id="availableVersion"></strong></div></div>
      <p id="updatePreservation" hidden>Your saved themes, settings, and admin password will be kept. A recovery backup is created before replacing the application.</p>
      <p>Update source: <a class="update-source" href="<?= h(CEOL_REPOSITORY_URL) ?>" target="_blank" rel="noopener noreferrer">ziobit/DenonCeolRemote · main</a></p>
      <div class="message error" id="updateError" role="alert"></div>
      <div class="inline"><button class="ui-btn" id="laterUpdateButton" type="button" data-close="updateModal">Close</button><button class="ui-btn" id="retryUpdateButton" type="button" hidden>Check again</button><button class="ui-btn primary" id="installUpdateButton" type="button" hidden>Update now</button></div>
    </div>
  </div>
  <div class="overlay" id="authModal" style="z-index:300" hidden>
    <form class="dialog" id="authForm">
      <div class="dialog-head"><h2 id="authTitle">Unlock the editor</h2><button class="ui-btn icon" type="button" data-close="authModal" aria-label="Close">×</button></div>
      <p id="authDescription">Enter the admin password to edit themes and layouts.</p>
      <label class="field"><span>Admin password</span><input id="adminPassword" type="password" minlength="8" maxlength="72" autocomplete="current-password" required></label>
      <label class="field" id="confirmPasswordField" hidden><span>Confirm password</span><input id="confirmAdminPassword" type="password" minlength="8" maxlength="72" autocomplete="new-password"></label>
      <div class="message error" id="authError"></div>
      <button class="ui-btn primary" id="authSubmit" type="submit">Unlock editor</button>
    </form>
  </div>
  <section class="editor-shell" id="editorShell" aria-label="Theme editor" hidden>
    <header class="editor-header">
      <div class="editor-title"><span data-icon="sliders"></span>Theme studio <small id="draftState">Saved</small></div>
      <div class="inline">
        <button class="ui-btn" id="undoButton" type="button" title="Undo (Ctrl+Z)">↶ Undo</button>
        <button class="ui-btn" id="redoButton" type="button" title="Redo (Ctrl+Shift+Z)">↷ Redo</button>
        <button class="ui-btn" id="previewButton" type="button">Preview</button>
        <button class="ui-btn" id="editorMoreButton" type="button">More ▾</button>
        <button class="ui-btn primary" id="saveThemeButton" type="button"><span data-icon="check"></span>Save theme</button>
        <button class="ui-btn" id="closeEditorButton" type="button">Close</button>
      </div>
    </header>
    <div class="mobile-editor-tabs">
      <button class="ui-btn" type="button" data-mobile-panel="palette">Commands</button>
      <button class="ui-btn" type="button" data-mobile-panel="canvas">Canvas</button>
      <button class="ui-btn" type="button" data-mobile-panel="properties">Properties</button>
    </div>
    <div class="editor-workspace" id="editorWorkspace" data-mobile-panel="canvas">
      <aside class="editor-sidebar" id="paletteSidebar">
        <h3 class="sidebar-heading">Build your remote</h3>
        <div class="palette-tabs"><button id="paletteTab" class="active" type="button">Commands</button><button id="layersTab" type="button">Layers</button></div>
        <div id="palettePanel"><input class="palette-search" id="paletteSearch" placeholder="Search commands…" aria-label="Search commands"><div id="commandPalette"></div><p class="sidebar-note">Drag an item onto the canvas, or tap it to add. Right-click an item for its properties.</p></div>
        <div id="layersPanel" hidden><div id="layerList"></div><p class="sidebar-note">Top layers appear in front. Select a layer, then use its properties to reorder or hide it.</p></div>
      </aside>
      <div class="editor-center">
        <div class="canvas-toolbar">
          <div class="segmented"><button id="editMiniButton" class="active" type="button">Mini remote</button><button id="editFullButton" type="button">All commands</button></div>
          <div class="inline"><label for="editorZoom">Zoom</label><select id="editorZoom"><option value="fit">Fit</option><option value="0.5">50%</option><option value="0.75">75%</option><option value="1">100%</option><option value="1.5">150%</option></select><label class="check-field"><input id="snapGrid" type="checkbox" checked> Snap</label></div>
        </div>
        <div class="canvas-scroll" id="canvasScroll"><div class="editor-canvas-wrap" id="editorViewport"><div class="skin-canvas editor-canvas grid-on" id="editorCanvas"></div></div></div>
      </div>
      <aside class="editor-sidebar editor-properties" id="propertiesSidebar">
        <div class="palette-tabs"><button id="themePropertiesTab" class="active" type="button">Theme</button><button id="itemPropertiesTab" type="button">Button / item</button></div>
        <div id="themeProperties"></div><div id="itemProperties" hidden></div>
      </aside>
    </div>
    <footer class="editor-footer"><span id="editorMessage">Drag to move · Handles to resize · Right-click for properties</span><span>Arrow keys: move · Shift: 10px · Delete: remove · Ctrl+D: duplicate</span></footer>
  </section>
  <div class="context-menu" id="itemContextMenu" hidden>
    <button type="button" data-context="properties"><span data-icon="sliders"></span>Properties</button>
    <button type="button" data-context="duplicate"><span data-icon="copy"></span>Duplicate</button>
    <button type="button" data-context="front">Bring to front</button>
    <button type="button" data-context="back">Send to back</button>
    <button type="button" data-context="hide">Show / hide</button>
    <hr><button type="button" data-context="delete">Delete item</button>
  </div>
  <div class="context-menu" id="editorMoreMenu" hidden>
    <button type="button" id="newThemeButton">New from this theme</button>
    <button type="button" id="reloadPrefsButton">Reload saved preferences</button>
    <button type="button" id="exportThemeButton">Export theme JSON</button>
    <button type="button" id="importThemeButton">Import theme JSON</button>
    <button type="button" id="deleteThemeButton">Delete custom theme</button>
    <hr><button type="button" id="changePasswordButton">Change admin password</button>
    <button type="button" id="lockEditorButton">Lock editor</button>
  </div>
  <input type="file" id="importThemeInput" accept=".json,application/json" hidden>
  <div class="overlay" id="passwordModal" style="z-index:300" hidden>
    <form class="dialog" id="passwordForm">
      <div class="dialog-head"><h2>Change admin password</h2><button class="ui-btn icon" type="button" data-close="passwordModal" aria-label="Close">×</button></div>
      <label class="field"><span>Current password</span><input id="currentPassword" type="password" autocomplete="current-password" required></label>
      <label class="field"><span>New password</span><input id="newPassword" type="password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
      <label class="field"><span>Confirm new password</span><input id="confirmNewPassword" type="password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
      <div class="message error" id="passwordError"></div><button class="ui-btn primary" type="submit">Change password</button>
    </form>
  </div>
  <div class="toast" id="toast" role="status" hidden></div>
  <script>
    'use strict';
    const bootData = <?= json_encode($ceolBoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const $ = id => document.getElementById(id);
    const clone = value => JSON.parse(JSON.stringify(value));
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
    const iconPaths = {
      none:'', power:'<path d="M12 3v9M6.3 6.3a8 8 0 1 0 11.4 0"/>',
      server:'<rect x="4" y="3" width="16" height="7" rx="2"/><rect x="4" y="14" width="16" height="7" rx="2"/><path d="M8 6.5h.01M8 17.5h.01M12 7h5M12 18h5"/>',
      bluetooth:'<path d="m7 7 10 10-5 4V3l5 4L7 17"/>', plus:'<path d="M12 5v14M5 12h14"/>', minus:'<path d="M5 12h14"/>',
      up:'<path d="m6 15 6-6 6 6"/>', down:'<path d="m6 9 6 6 6-6"/>', left:'<path d="m15 6-6 6 6 6"/>', right:'<path d="m9 6 6 6-6 6"/>',
      check:'<path d="m5 12 4 4L19 6"/>', play:'<path d="m8 4 12 8-12 8z"/>', pause:'<path d="M8 4v16M16 4v16"/>', stop:'<rect x="5" y="5" width="14" height="14" rx="2"/>',
      next:'<path d="m4 5 11 7-11 7zM19 5v14"/>', previous:'<path d="m20 5-11 7 11 7zM5 5v14"/>',
      volume:'<path d="M3 9v6h4l5 4V5L7 9H3M16 8a6 6 0 0 1 0 8M19 5a10 10 0 0 1 0 14"/>', mute:'<path d="M3 9v6h4l5 4V5L7 9H3m13 0 6 6m0-6-6 6"/>',
      disc:'<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2"/><path d="M5.5 10A7 7 0 0 1 10 5.5M14 18.5a7 7 0 0 0 4.5-4.5"/>',
      radio:'<rect x="3" y="8" width="18" height="13" rx="2"/><path d="m4 8 13-6M7 12h.01M10 12h8"/><circle cx="8" cy="16" r="2"/><path d="M14 16h4M14 18h4"/>',
      usb:'<path d="M12 21V3m-3 3 3-3 3 3M12 16l-5-3V9m5 3 5-3V6"/><circle cx="7" cy="8" r="1"/><rect x="16" y="3" width="2" height="3"/>',
      cable:'<path d="M7 3v4M12 3v4M5 7h9v3a4.5 4.5 0 0 1-9 0V7m4.5 7.5V18a3 3 0 0 0 6 0v-1"/>',
      refresh:'<path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-1l2 3M4 15l2 3a7 7 0 0 0 12-1"/>',
      network:'<rect x="8" y="3" width="8" height="5" rx="1"/><rect x="2" y="16" width="6" height="5" rx="1"/><rect x="16" y="16" width="6" height="5" rx="1"/><path d="M12 8v4M5 16v-4h14v4"/>',
      heart:'<path d="M20.8 4.6a5.4 5.4 0 0 0-7.6 0L12 5.8l-1.2-1.2a5.4 5.4 0 0 0-7.6 7.6L12 21l8.8-8.8a5.4 5.4 0 0 0 0-7.6z"/>',
      send:'<path d="m21 3-7 18-4-7-7-4L21 3 10 14"/>', info:'<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
      sliders:'<path d="M4 7h5m4 0h7M4 17h10m4 0h2"/><circle cx="11" cy="7" r="2"/><circle cx="16" cy="17" r="2"/>',
      copy:'<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V4H4v12h4"/>'
    };
    const itemIcons = Object.keys(iconPaths).filter(key => !['sliders', 'copy'].includes(key));
    function icon(name) { return iconPaths[name] ? '<svg viewBox="0 0 24 24" aria-hidden="true">' + iconPaths[name] + '</svg>' : ''; }
    document.querySelectorAll('[data-icon]').forEach(el => { el.innerHTML = icon(el.dataset.icon); });

    function node(kind, id, label, x, y, w, h, options = {}) {
      return Object.assign({kind, id, label, x, y, w, h, action:'', icon:'none', key:'power', repeat:false, hidden:false,
        style:{bg:'', fg:'', hoverBg:'', hoverFg:'', borderColor:'', gradient:'', radius:-1, fontSize:kind==='display'?12:14, iconSize:24,
          borderWidth:1, depth:-1, shadow:-1, opacity:1, hoverEffect:'theme', pressEffect:'sink', alignment:'center', weight:'600', iconOnly:false, tone:'default'}}, options);
    }
    function button(id, label, action, glyph, x, y, w, h, options = {}) {
      const result = node('button', id, label, x, y, w, h, {action, icon:glyph, repeat:!!options.repeat});
      Object.assign(result.style, options);
      return result;
    }
    function heading(id, text, x, y, w) {
      const result = node('label', id, text, x, y, w, 24);
      Object.assign(result.style, {fontSize:12, alignment:'left', weight:'700', radius:0});
      return result;
    }
    function miniLayout(variant) {
      const n = [heading('mini-title', 'CEOL / N9', 20, 16, 220), node('state','mini-status','Power',268,10,72,42,{key:'power'}),
        node('display','mini-display','Display',20,62,320,106),
        button('mini-power','Power','toggle_power','power',20,192,92,66,{iconOnly:true,tone:'accent'}),
        button('mini-server','Server','SISERVER','server',134,192,92,66,{iconOnly:true}),
        button('mini-bt','Bluetooth','SIBLUETOOTH','bluetooth',248,192,92,66,{iconOnly:true}),
        node('volume','mini-volume','Volume',116,280,128,88),
        button('mini-mute','Mute','toggle_mute','volume',20,280,76,66,{iconOnly:true}),
        button('mini-play','Play','NS9A','play',264,280,76,66,{iconOnly:true}),
        button('mini-up','Volume up','MVUP','plus',142,386,76,66,{iconOnly:true,repeat:true}),
        // Keep the original remote's intentionally swapped left/right transport routing.
        button('mini-left','Next','NS9D','left',40,462,76,66,{iconOnly:true,repeat:true}),
        button('mini-enter','Enter','NS94','check',142,462,76,66,{iconOnly:true,radius:50}),
        button('mini-right','Previous','NS9E','right',244,462,76,66,{iconOnly:true,repeat:true}),
        button('mini-down','Volume down','MVDOWN','minus',142,538,76,66,{iconOnly:true,repeat:true}),
        heading('mini-footer','MUSIC, WITHIN REACH',20,618,320)];
      const byId = id => n.find(item => item.id === id);
      const place = (id,x,y,w,h) => Object.assign(byId(id),{x,y,w,h});
      let width=360, height=656;
      if (variant === 1) {
        ['mini-power','mini-server','mini-bt'].forEach(id=>{byId(id).y=178;byId(id).h=60;byId(id).style.radius=30;});
        place('mini-volume',116,254,128,64); place('mini-mute',20,254,76,64); place('mini-play',264,254,76,64);
        place('mini-up',142,334,76,54); place('mini-left',40,398,76,54); place('mini-enter',142,398,76,54); place('mini-right',244,398,76,54); place('mini-down',142,462,76,54);
        byId('mini-footer').y=534; height=572;
      } else if (variant === 2) {
        width=400;height=550; place('mini-display',20,62,360,106);place('mini-status',308,10,72,42);
        place('mini-power',20,184,112,64);place('mini-server',144,184,112,64);place('mini-bt',268,184,112,64);
        place('mini-volume',140,264,120,70);place('mini-mute',20,264,108,70);place('mini-play',272,264,108,70);
        place('mini-up',20,350,174,64);place('mini-down',206,350,174,64);
        place('mini-left',20,430,108,64);place('mini-enter',146,430,108,64);place('mini-right',272,430,108,64);byId('mini-footer').y=514;
      } else if (variant === 3) {
        height=622; place('mini-power',20,184,320,48);place('mini-server',20,244,154,50);place('mini-bt',186,244,154,50);
        place('mini-volume',118,310,124,64);place('mini-mute',20,310,84,64);place('mini-play',256,310,84,64);
        place('mini-up',142,394,76,54);place('mini-left',40,460,76,54);place('mini-enter',142,460,76,54);place('mini-right',244,460,76,54);place('mini-down',142,526,76,54);byId('mini-footer').y=594;
      } else if (variant === 4) {
        width=380;height=540;place('mini-display',20,62,340,100);place('mini-status',288,10,72,42);
        place('mini-power',20,178,104,56);place('mini-server',138,178,104,56);place('mini-bt',256,178,104,56);
        place('mini-volume',136,250,108,72);place('mini-mute',20,258,104,56);place('mini-play',256,258,104,56);
        place('mini-up',20,346,164,60);place('mini-down',196,346,164,60);place('mini-left',20,424,104,60);place('mini-enter',138,424,104,60);place('mini-right',256,424,104,60);byId('mini-footer').y=506;
      }
      byId('mini-status').style.fontSize=11; byId('mini-status').style.borderWidth=0;byId('mini-status').style.shadow=0;
      byId('mini-footer').style.fontSize=10;byId('mini-footer').style.opacity=.55;
      return {width,height,nodes:n};
    }
    const sourceCommands = [['SICD','CD','disc'],['SITUNER','Tuner','radio'],['SIFM','FM','radio'],['SIAM','AM','radio'],
      ['SIIRADIO','Internet radio','network'],['SISERVER','Music server','server'],['SIUSB','USB','usb'],['SIBLUETOOTH','Bluetooth','bluetooth'],
      ['SIDIGITALIN1','Digital 1','cable'],['SIDIGITALIN2','Digital 2','cable'],['SIANALOGIN','Analog','cable']];
    const transportCommands = [['NS90','Up','up'],['NS94','Enter','check'],['NS91','Down','down'],['NS92','Left','left'],['NS9A','Play','play'],['NS93','Right','right'],
      ['NS9E','Previous','previous'],['NS9B','Pause','pause'],['NS9D','Next','next'],['NS9X','Page +','plus'],['NS9C','Stop','stop'],['NS9Y','Page −','minus']];
    function fullLayout() {
      const n=[heading('sources-title','INPUT SOURCES',20,18,280)];
      sourceCommands.forEach((c,i)=>n.push(button('source-'+i,c[1],c[0],c[2],20+(i%2)*142,58+Math.floor(i/2)*62,132,50,{fontSize:12,iconSize:18})));
      n.push(heading('power-title','POWER & MUTE',20,446,280));
      [['PWON','Power on','power'],['PWSTANDBY','Standby','power'],['MUON','Mute','mute'],['MUOFF','Unmute','volume']].forEach((c,i)=>n.push(button('power-'+i,c[1],c[0],c[2],20+(i%2)*142,480+Math.floor(i/2)*62,132,50,{fontSize:12,iconSize:18,tone:i===0?'accent':'default'})));
      n.push(node('fallback','fallback','HTTP command fallback',20,616,274,56));
      n.push(heading('display-title','FRONT DISPLAY',330,18,430),node('display','full-display','Display',330,58,430,230));
      ['power','source','mute','tuner'].forEach((key,i)=>n.push(node('state','state-'+key,key.charAt(0).toUpperCase()+key.slice(1),330+i*110,304,100,62,{key})));
      n.push(heading('navigation-title','NAVIGATION & TRANSPORT',330,392,430));
      transportCommands.forEach((c,i)=>n.push(button('transport-'+i,c[1],c[0],c[2],330+(i%3)*147,432+Math.floor(i/3)*62,136,50,{fontSize:12,iconSize:18})));
      n.push(heading('volume-title','VOLUME',790,18,310),node('volume','full-volume','Volume',790,58,310,108),node('slider','volume-slider','Volume',790,182,310,52));
      n.push(button('vol-down','Volume −','MVDOWN','minus',790,250,148,54,{repeat:true}),button('vol-up','Volume +','MVUP','plus',952,250,148,54,{repeat:true}));
      n.push(heading('tuner-title','TUNER & FAVORITES',790,338,310));
      [['TFANDOWN','Tune / Ch −','minus'],['TFANUP','Tune / Ch +','plus'],['TMANFM','FM band','radio'],['TMANAM','AM band','radio']].forEach((c,i)=>n.push(button('tuner-'+i,c[1],c[0],c[2],790+(i%2)*162,376+Math.floor(i/2)*62,148,50,{fontSize:12,iconSize:18})));
      n.push(node('favorite','favorite','Favorite 1–50',790,504,310,56),heading('diagnostics-title','DIAGNOSTICS',790,592,310));
      [['refresh','Refresh','refresh'],['NSE','Display','info'],['NSINF?','Network','network'],['test','Test','check']].forEach((c,i)=>n.push(button('diagnostic-'+i,c[1],c[0],c[2],790+(i%2)*162,630+Math.floor(i/2)*62,148,50,{fontSize:12,iconSize:18})));
      n.push(node('manual','manual','Manual command',790,762,310,60),heading('queries-title','QUERIES & EXTRA COMMANDS',20,702,740));
      [['PW?','Power?'],['MV?','Volume?'],['MU?','Mute?'],['SI?','Source?'],['TFAN?','Frequency?'],['TFANNAME?','Station?'],
        ['TM?','Tuner mode?'],['NSA','ASCII display'],['SSFMT?','Format?'],['TMANAUTO','Auto tuning'],['TMANMANUAL','Manual tuning'],['SIBT','BT alternate']]
        .forEach((c,i)=>n.push(button('extra-'+i,c[1],c[0],'none',20+(i%6)*125,740+Math.floor(i/6)*62,115,50,{fontSize:11})));
      n.push(heading('log-title','COMMAND LOG',20,862,1080),node('log','command-log','Log',20,900,1080,164));
      return {width:1120,height:1084,nodes:n};
    }
    function preset(id,name,mode,colors,variant,options={}) {
      const keys=['background','surface','button','text','muted','accent','accentText','border','hover','hoverText','display','displayText'];
      return Object.assign({id,name,mode,font:'system',radius:18,buttonDepth:0,buttonShadow:8,hoverEffect:'lift',colors:Object.fromEntries(keys.map((key,i)=>[key,colors[i]])),layouts:{mini:miniLayout(variant),full:fullLayout()}},options);
    }
    const presets = [
      preset('porcelain','Porcelain','light',['#f3f5f7','#ffffff','#edf1f5','#18232f','#657282','#2563eb','#ffffff','#dbe2ea','#dce7fb','#18232f','#162331','#a7edc6'],0,{radius:18}),
      preset('sandstone','Sandstone','light',['#f4f0e9','#fcfaf6','#eee7dc','#302b24','#84796b','#9a6236','#ffffff','#ded3c2','#e8dbc8','#302b24','#302b24','#edddbb'],1,{radius:28,buttonDepth:3,buttonShadow:10,font:'rounded'}),
      preset('seafoam','Seafoam','light',['#edf5f1','#fbfffc','#e2f0e8','#183b2e','#678675','#207a59','#ffffff','#caddd2','#cce9d8','#183b2e','#173c30','#bde5d0'],2,{radius:15,buttonShadow:5}),
      preset('ice','Ice','light',['#edf3fa','#ffffff','#e5eef9','#19364f','#69849c','#0865a8','#ffffff','#ccdeee','#d1e5fb','#19364f','#153149','#b6dcf4'],3,{radius:12,buttonDepth:2,hoverEffect:'glow'}),
      preset('rose','Rose quartz','light',['#faf1f2','#fffafb','#f4e7eb','#4c2d39','#957785','#af426e','#ffffff','#e6d4dc','#efd4e0','#4c2d39','#462734','#f2c4d6'],4,{radius:24,font:'rounded',hoverEffect:'grow'}),
      preset('graphite','Graphite','dark',['#101214','#1b1e22','#292d33','#eef0f3','#8c959f','#d7e4f2','#18232f','#3c424b','#3e4651','#ffffff','#0e1115','#b9d7ba'],2,{radius:12,buttonDepth:3,buttonShadow:12}),
      preset('midnight','Midnight','dark',['#0b1120','#131d31','#202e47','#e8eef9','#8fa3c3','#68a7ff','#0c1f38','#33425c','#2e4366','#ffffff','#080f1c','#86c9f8'],0,{radius:20,hoverEffect:'glow',buttonShadow:14}),
      preset('forest','Deep forest','dark',['#0e1916','#162720','#21382e','#e1eee6','#8ba397','#9ad4af','#122c1c','#355243','#304d3f','#ffffff','#0c1712','#bcebbb'],4,{radius:16,buttonDepth:2}),
      preset('aubergine','Aubergine','dark',['#1a1220','#281d32','#3a2a48','#f1e8f8','#b29ac4','#c6a0e8','#271238','#513e61','#533964','#ffffff','#160d1d','#dfbaf4'],1,{radius:28,font:'rounded',hoverEffect:'grow'}),
      preset('espresso','Espresso','dark',['#1b1714','#29221d','#3b3028','#f4ece1','#b5a392','#e3b47c','#332314','#554435','#544233','#ffffff','#17110d','#f0c9a0'],3,{radius:10,font:'mono',buttonDepth:4,buttonShadow:10})
    ];

    let preferences = bootData.preferences;
    let csrf = bootData.csrf;
    let activeTheme = findTheme(preferences.activeTheme);
    let activeView = preferences.activeView;
    let editorOpen = false, authenticated = bootData.authenticated, setupRequired = bootData.setupRequired;
    let draft = null, draftBase = '', editView = 'mini', selectedId = null, editorScale = 1, previewMode = false;
    let undoStack = [], redoStack = [], dragState = null, paletteDragging = null, themeSaving = false;
    let pollTimer = null, busy = false, liveState = {power:'unknown',mute:null,volumeNumber:null,display:[],sourceRaw:''}, logLines = [];
    let toastTimer = null, heldStop = null, selectionBusy = false, resumeEditorDraft = false;
    let authPurpose = 'editor', updateInfo = null, updateChecking = false, updateInstalling = false;
    let dismissedUpdateVersion = '', pendingUpdateOffer = false;
    let remoteQueue = [];
    let connectionEpoch = 0, connectionSaving = false;
    const DIRECT_TIMEOUT_MS = 6000;
    function themes() { return presets.concat(preferences.customThemes || []); }
    function findTheme(id) { return themes().find(theme => theme.id === id) || presets[0]; }
    function currentLayout() { return draft.layouts[editView]; }
    function selectedNode() { return draft && currentLayout().nodes.find(item => item.id === selectedId); }
    function dirty() { return draft && JSON.stringify(draft) !== draftBase; }
    function notice(text) { $('toast').textContent=text;$('toast').hidden=false;clearTimeout(toastTimer);toastTimer=setTimeout(()=>$('toast').hidden=true,3600); }
    function setEditorMessage(text) { $('editorMessage').textContent=text; }
    async function api(action,data={}) {
      const form = new FormData();form.set('ajax','1');form.set('action',action);form.set('csrf',csrf);
      Object.entries(data).forEach(([key,value])=>form.set(key,String(value)));
      const response=await fetch(location.href,{method:'POST',body:form,credentials:'same-origin'});
      let result;
      try { result=await response.json(); } catch(e) { throw new Error('The PHP server returned an invalid response.'); }
      if (result.csrf) csrf=result.csrf;
      if (!result.ok) {
        if (result.authRequired) authenticated=false;
        const error=new Error(result.error || 'The request failed.');error.authRequired=!!result.authRequired;throw error;
      }
      return result;
    }
    function setThemeVariables(element,theme) {
      Object.entries(theme.colors).forEach(([key,value])=>element.style.setProperty('--'+key.replace(/[A-Z]/g,m=>'-'+m.toLowerCase()),value));
      element.style.setProperty('--radius',theme.radius+'px');
      const fonts={system:'system-ui,-apple-system,"Segoe UI",sans-serif',rounded:'"Trebuchet MS",system-ui,sans-serif',mono:'ui-monospace,Consolas,monospace',serif:'Georgia,"Times New Roman",serif'};
      element.style.setProperty('--font',fonts[theme.font] || fonts.system);element.style.colorScheme=theme.mode;
    }
    function applyTheme(theme) {
      activeTheme=theme;setThemeVariables(document.documentElement,theme);
      $('browserThemeColor').content=theme.colors.background;
      $('themeCaption').textContent=theme.name+' / '+(theme.mode==='light'?'Light':'Dark');
      renderRuntime();
    }
    function setNodeStyle(element,item,theme) {
      const s=item.style, accent=s.tone==='accent';
      const bg=s.bg || (item.kind==='display'?theme.colors.display:accent?theme.colors.accent:theme.colors.button);
      element.style.setProperty('--node-background',s.gradient?'linear-gradient(135deg,'+bg+','+s.gradient+')':bg);
      element.style.setProperty('--node-color',s.fg || (item.kind==='display'?theme.colors.displayText:accent?theme.colors.accentText:theme.colors.text));
      element.style.setProperty('--node-hover',s.hoverBg || theme.colors.hover);
      element.style.setProperty('--node-hover-color',s.hoverFg || theme.colors.hoverText);
      element.style.setProperty('--node-border',s.borderColor || theme.colors.border);
      element.style.setProperty('--node-border-width',s.borderWidth+'px');
      element.style.setProperty('--node-radius',(s.radius<0?theme.radius:s.radius)+'px');
      element.style.setProperty('--node-font-size',s.fontSize+'px');element.style.setProperty('--node-icon-size',s.iconSize+'px');
      element.style.setProperty('--node-depth',(s.depth<0?theme.buttonDepth:s.depth)+'px');
      element.style.setProperty('--node-shadow',(s.shadow<0?theme.buttonShadow:s.shadow)+'px');
      element.style.setProperty('--node-opacity',s.opacity);element.style.setProperty('--node-weight',s.weight);
      element.style.setProperty('--node-align',{left:'flex-start',center:'center',right:'flex-end'}[s.alignment]);
      element.style.setProperty('--node-text-align',s.alignment);
      element.dataset.hover=s.hoverEffect==='theme'?theme.hoverEffect:s.hoverEffect;element.dataset.press=s.pressEffect;
      positionNode(element,item);
    }
    function positionNode(el,item) { Object.assign(el.style,{left:item.x+'px',top:item.y+'px',width:item.w+'px',height:item.h+'px'}); }
    function makeText(tag,css,text) { const el=document.createElement(tag);el.className=css;el.textContent=text;return el; }
    function makeNode(item,theme,view,editing=false) {
      const outer=document.createElement('div');outer.className='skin-node '+item.kind+'-node';outer.dataset.nodeId=item.id;outer.dataset.kind=item.kind;
      outer.dataset.action=item.action;outer.dataset.view=view;
      if (item.hidden) { if (!editing) outer.hidden=true; else outer.classList.add('hidden-node'); }
      setNodeStyle(outer,item,theme);
      const face=document.createElement(item.kind==='button'?'button':'div');face.className='node-face';
      if (item.kind==='button') {
        face.type='button';face.setAttribute('aria-label',item.label || item.action);face.title=item.label+(item.action?' · '+item.action:'');
        face.innerHTML=icon(item.icon);face.appendChild(makeText('span','node-label',item.label));
        if (item.style.iconOnly && item.icon!=='none') face.classList.add('icon-only');
        if (!editing) bindRemoteButton(face,item);
      } else if (item.kind==='display') {
        const content=makeText('div','display-content','');content.dataset.live='display';content.dataset.view=view;face.appendChild(content);
      } else if (item.kind==='volume') {
        face.appendChild(makeText('span','live-caption',item.label));const value=makeText('b','volume-number','--');value.dataset.live='volume';face.appendChild(value);
      } else if (item.kind==='state') {
        face.appendChild(makeText('span','live-caption',item.label));const value=makeText('b','state-value','Unknown');value.dataset.live='state';value.dataset.key=item.key;face.appendChild(value);
      } else if (item.kind==='slider') {
        const input=document.createElement('input');input.type='range';input.min=0;input.max=60;input.value=liveState.volumeNumber || 0;input.dataset.live='slider';input.setAttribute('aria-label',item.label || 'Volume');
        input.addEventListener('input',()=>{if (!editorOpen) updateVolume(Number(input.value));});
        input.addEventListener('change',()=>{if (!editorOpen) setVolume(input.value);});face.appendChild(input);
      } else if (item.kind==='favorite' || item.kind==='manual') {
        const input=document.createElement('input');input.setAttribute('aria-label',item.label);
        if (item.kind==='favorite') { input.type='number';input.min=1;input.max=50;input.value=1; } else { input.type='text';input.placeholder='MV20, SIIRADIO…';input.maxLength=32; }
        const send=document.createElement('button');send.type='button';send.className='inline-action';send.innerHTML=icon(item.kind==='favorite'?'heart':'send');send.setAttribute('aria-label',item.kind==='favorite'?'Recall favorite':'Send command');
        const run=()=>{ if (editorOpen) return; if (item.kind==='favorite') favoriteGo(input);else { const cmd=input.value.trim().toUpperCase();if(cmd) sendCommand(cmd); } };
        send.addEventListener('click',run);input.addEventListener('keydown',ev=>{if(ev.key==='Enter'){ev.preventDefault();run();}});face.append(input,send);
      } else if (item.kind==='fallback') {
        const label=makeText('label','inline','');const input=document.createElement('input');input.type='checkbox';input.checked=preferences.useHttpFallback;input.dataset.live='fallback';
        input.addEventListener('change',()=>{if (!editorOpen) saveSelection({fallback:input.checked?'1':'0'});});label.append(input,makeText('span','',item.label));face.append(label);
      } else if (item.kind==='log') {
        const log=makeText('pre','log-content','');log.dataset.live='log';face.append(log);
      } else if (item.kind==='label') face.textContent=item.label;
      outer.append(face);
      if (editing) { outer.addEventListener('pointerdown',startCanvasPointer);outer.addEventListener('contextmenu',openItemContext); }
      return outer;
    }
    function renderCanvas(canvas,layout,theme,view,editing=false) {
      stopHolding();canvas.replaceChildren();canvas.style.width=layout.width+'px';canvas.style.height=layout.height+'px';
      layout.nodes.forEach((item,i)=>{const el=makeNode(item,theme,view,editing);el.style.zIndex=i;canvas.append(el);});
      updateLiveDisplays();
    }
    function renderRuntime() {
      $('miniViewButton').classList.toggle('active',activeView==='mini');$('fullViewButton').classList.toggle('active',activeView==='full');
      $('viewTitle').textContent=activeView==='mini'?'Your everyday remote.':'Every command, in one place.';
      $('viewSubtitle').textContent=activeView==='mini'?'Power, music, and volume. Everything within reach.':'Sources, playback, tuning, and diagnostics.';
      $('runtimeStage').className='runtime-stage '+activeView;
      renderCanvas($('runtimeCanvas'),activeTheme.layouts[activeView],activeTheme,activeView);sizeRuntime();
    }
    function sizeRuntime() {
      const layout=activeTheme.layouts[activeView],stage=$('runtimeStage');
      stage.classList.toggle('mobile-flow',activeView==='full' && window.innerWidth<=780);
      const available=stage.clientWidth-parseFloat(getComputedStyle(stage).paddingLeft)-parseFloat(getComputedStyle(stage).paddingRight);
      const scale=Math.min(1,available/layout.width);
      $('runtimeCanvas').style.transform='scale('+scale+')';$('runtimeViewport').style.width=layout.width*scale+'px';$('runtimeViewport').style.height=layout.height*scale+'px';
    }
    async function saveSelection(data) {
      if (selectionBusy) return;selectionBusy=true;stopHolding();
      try { const result=await api('skin_select',data);preferences=result.preferences;activeView=preferences.activeView;applyTheme(findTheme(preferences.activeTheme)); }
      catch(error) { notice(error.message);updateLiveDisplays(); }
      finally { selectionBusy=false; }
    }
    function renderThemeGallery() {
      const gallery=$('themeGallery');gallery.replaceChildren();
      for (const [label,list] of [['Light',presets.filter(t=>t.mode==='light')],['Dark',presets.filter(t=>t.mode==='dark')],['Your themes',preferences.customThemes]]) {
        if (!list || !list.length) continue;gallery.append(makeText('h3','theme-group',label));const grid=makeText('div','theme-grid','');
        list.forEach(theme=>{
          const card=document.createElement('button');card.type='button';card.className='theme-card'+(theme.id===activeTheme.id?' selected':'');
          const sample=makeText('div','theme-sample','');sample.style.background=theme.colors.background;
          const remote=makeText('div','sample-remote','');remote.style.background=theme.colors.surface;
          for(let i=0;i<10;i++){const tile=document.createElement('i');tile.style.background=i===0?theme.colors.display:i===1?theme.colors.accent:theme.colors.button;tile.style.borderRadius=Math.min(theme.radius/3,7)+'px';remote.append(tile);}
          sample.append(remote);card.append(sample,makeText('b','',theme.name),makeText('small','',theme.id.startsWith('custom-')?'Custom layout':theme.mode+' · '+['Soft geometry','Compact curves','Wide controls','Studio layout','Linear controls'][presets.indexOf(theme)%5]));
          card.addEventListener('click',async()=>{await saveSelection({themeId:theme.id});renderThemeGallery();});grid.append(card);
        });gallery.append(grid);
      }
    }

    const palette = [];
    function paletteButton(group,label,action,glyph='none',repeat=false) { palette.push({group,label,kind:'button',action,icon:glyph,repeat}); }
    sourceCommands.forEach(c=>paletteButton('Sources',c[1],c[0],c[2]));
    [['toggle_power','Power toggle','power'],['PWON','Power on','power'],['PWSTANDBY','Standby','power'],['toggle_mute','Mute toggle','volume'],['MUON','Mute','mute'],['MUOFF','Unmute','volume'],['MVUP','Volume up','plus',true],['MVDOWN','Volume down','minus',true]]
      .forEach(c=>paletteButton('Power & volume',c[1],c[0],c[2],c[3]));
    transportCommands.forEach(c=>paletteButton('Navigation & playback',c[1],c[0],c[2]));
    [['TFANUP','Tune / channel +','plus'],['TFANDOWN','Tune / channel −','minus'],['TMANFM','FM band','radio'],['TMANAM','AM band','radio'],['TMANAUTO','Auto tuning','radio'],['TMANMANUAL','Manual tuning','radio']]
      .forEach(c=>paletteButton('Tuner',c[1],c[0],c[2]));
    bootData.allowedCommands.filter(command=>!palette.some(item=>item.action===command)).forEach(command=>paletteButton('Queries & diagnostics',command,command,'info'));
    paletteButton('Queries & diagnostics','Refresh status','refresh','refresh');paletteButton('Queries & diagnostics','Test connection','test','check');
    paletteButton('Custom command','Custom command button','MV20','send');
    [['display','Live display'],['volume','Volume readout'],['slider','Volume slider'],['state','Power status'],['favorite','Favorite selector'],['manual','Manual command field'],['fallback','HTTP fallback switch'],['log','Command log'],['label','Text label'],['panel','Background panel']]
      .forEach(c=>palette.push({group:'Widgets & decoration',label:c[1],kind:c[0],action:'',icon:'none'}));
    function validCommand(command) { return bootData.allowedCommands.includes(command) || /^MV([0-5][0-9]|60)$/.test(command) || /^FV(0[1-9]|[1-4][0-9]|50)$/.test(command) || /^TFAN[0-9]{6}$/.test(command); }
    function validAction(action) { return ['toggle_power','toggle_mute','refresh','test'].includes(action) || validCommand(action); }
    function newId(prefix) { const bytes=new Uint8Array(8);crypto.getRandomValues(bytes);return prefix+'-'+Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join(''); }
    function renderPalette() {
      const query=$('paletteSearch').value.toLowerCase(),target=$('commandPalette');target.replaceChildren();let group='';
      palette.forEach((item,index)=>{
        if(!(item.label+' '+item.action+' '+item.group).toLowerCase().includes(query)) return;
        if(group!==item.group){target.append(makeText('h4','palette-heading',item.group));group=item.group;}
        const entry=document.createElement('button');entry.type='button';entry.className='palette-item';entry.draggable=true;entry.dataset.paletteIndex=index;
        entry.innerHTML=icon(item.icon==='none'?'plus':item.icon);const text=makeText('span','',item.label);if(item.action)text.append(makeText('small','',item.action));entry.append(text);
        entry.addEventListener('click',()=>addPaletteItem(index));
        entry.addEventListener('dragstart',ev=>{if(previewMode){ev.preventDefault();return;}paletteDragging=index;ev.dataTransfer.effectAllowed='copy';ev.dataTransfer.setData('application/x-ceol-item',String(index));ev.dataTransfer.setData('text/plain',item.label);});
        entry.addEventListener('dragend',()=>{paletteDragging=null;});target.append(entry);
      });
    }
    function addPaletteItem(index,position=null) {
      if(!editorOpen || previewMode) return;
      const template=palette[index];if(!template) return;
      if(currentLayout().nodes.length>=200){notice('This canvas already has 200 items.');return;}
      const dimensions={button:[132,56],display:[300,110],volume:[120,80],state:[120,62],slider:[280,56],favorite:[280,56],manual:[280,60],fallback:[280,56],log:[300,140],label:[220,32],panel:[300,200]};
      let [w,h]=dimensions[template.kind];const layout=currentLayout();w=Math.min(w,layout.width);h=Math.min(h,layout.height);
      const pos=position || {x:24+(layout.nodes.length%5)*8,y:Math.max(24,Math.min(layout.height-h-24,layout.nodes.length%8*32+24))};
      const x=clamp(snap(pos.x),0,layout.width-w),y=clamp(snap(pos.y),0,layout.height-h);
      const item=node(template.kind,newId('item'),template.label,x,y,w,h,{action:template.action,icon:template.icon,repeat:!!template.repeat});
      if(item.kind==='panel'){item.style.shadow=0;item.style.opacity=.6;}
      if(item.kind==='label'){item.style.alignment='left';item.style.fontSize=18;}
      selectedId=item.id;mutate(()=>{if(item.kind==='panel')layout.nodes.unshift(item);else layout.nodes.push(item);},true);
      showPropertyTab('item');if(window.innerWidth<=780)$('editorWorkspace').dataset.mobilePanel='canvas';
    }
    function snap(value) { return $('snapGrid').checked?Math.round(value/8)*8:Math.round(value); }
    function recordUndo() { undoStack.push(clone(draft));if(undoStack.length>60)undoStack.shift();redoStack=[]; }
    function mutate(change,refreshProperties=false) {
      if(previewMode || themeSaving) return;recordUndo();change();renderEditorCanvas();renderLayers();updateDraftStatus();
      if(refreshProperties){renderThemeProperties();renderItemProperties();}
    }
    function updateDraftStatus() {
      $('draftState').textContent=dirty()?'Unsaved changes':'Saved';$('undoButton').disabled=!undoStack.length || previewMode || themeSaving;
      $('redoButton').disabled=!redoStack.length || previewMode || themeSaving;$('saveThemeButton').disabled=themeSaving;
      $('saveThemeButton').textContent=themeSaving?'Saving…':'Save theme';
    }
    function renderEditorCanvas() {
      if(!draft)return;setThemeVariables($('editorShell'),draft);setThemeVariables($('itemContextMenu'),draft);setThemeVariables($('editorMoreMenu'),draft);
      $('editMiniButton').classList.toggle('active',editView==='mini');$('editFullButton').classList.toggle('active',editView==='full');
      renderCanvas($('editorCanvas'),currentLayout(),draft,editView,true);
      $('editorCanvas').classList.toggle('grid-on',$('snapGrid').checked && !previewMode);selectNode(selectedId,false);sizeEditorCanvas();
    }
    function sizeEditorCanvas() {
      if(!editorOpen || !draft)return;
      const layout=currentLayout(),scroll=$('canvasScroll'),padding=parseFloat(getComputedStyle(scroll).paddingLeft)*2;
      editorScale=$('editorZoom').value==='fit'?Math.min(1,(scroll.clientWidth-padding)/layout.width):Number($('editorZoom').value);
      editorScale=Math.max(.15,editorScale);$('editorCanvas').style.transform='scale('+editorScale+')';
      $('editorViewport').style.width=layout.width*editorScale+'px';$('editorViewport').style.height=layout.height*editorScale+'px';
    }
    function selectNode(id,showProperties=true) {
      selectedId=id;const chosen=selectedNode();
      $('editorCanvas').querySelectorAll('.skin-node').forEach(el=>{
        el.classList.toggle('selected',!!chosen && el.dataset.nodeId===id && !previewMode);el.querySelectorAll('.resize-handle').forEach(handle=>handle.remove());
        if(chosen && el.dataset.nodeId===id && !previewMode){
          ['nw','n','ne','e','se','s','sw','w'].forEach(direction=>{const handle=makeText('span','resize-handle','');handle.dataset.handle=direction;el.append(handle);});
        }
      });
      if(showProperties){renderItemProperties();if(chosen)showPropertyTab('item');renderLayers();}
    }
    function startCanvasPointer(ev) {
      if(previewMode || themeSaving || ev.button!==0)return;ev.preventDefault();ev.stopPropagation();
      const wrapper=ev.currentTarget;selectNode(wrapper.dataset.nodeId);const item=selectedNode();if(!item)return;
      dragState={id:item.id,handle:ev.target.dataset.handle || '',startX:ev.clientX,startY:ev.clientY,original:{x:item.x,y:item.y,w:item.w,h:item.h},snapshot:clone(draft),changed:false,pointerId:ev.pointerId,element:wrapper};
      wrapper.setPointerCapture(ev.pointerId);
    }
    function moveCanvasPointer(ev) {
      if(!dragState || ev.pointerId!==dragState.pointerId)return;
      const item=selectedNode();if(!item || item.id!==dragState.id)return;
      const dx=(ev.clientX-dragState.startX)/editorScale,dy=(ev.clientY-dragState.startY)/editorScale;
      const before=dragState.original,layout=currentLayout(),handle=dragState.handle;
      if(!handle){item.x=clamp(snap(before.x+dx),0,layout.width-item.w);item.y=clamp(snap(before.y+dy),0,layout.height-item.h);}
      else {
        let left=before.x,top=before.y,right=before.x+before.w,bottom=before.y+before.h;
        if(handle.includes('w'))left=clamp(snap(before.x+dx),0,right-24);
        if(handle.includes('e'))right=clamp(snap(before.x+before.w+dx),left+24,layout.width);
        if(handle.includes('n'))top=clamp(snap(before.y+dy),0,bottom-24);
        if(handle.includes('s'))bottom=clamp(snap(before.y+before.h+dy),top+24,layout.height);
        Object.assign(item,{x:left,y:top,w:right-left,h:bottom-top});
      }
      dragState.changed=['x','y','w','h'].some(key=>item[key]!==before[key]);positionNode(dragState.element,item);
      ['x','y','w','h'].forEach(key=>{const input=$('item-'+key);if(input)input.value=item[key];});
      setEditorMessage(Math.round(item.x)+', '+Math.round(item.y)+' · '+Math.round(item.w)+' × '+Math.round(item.h)+' px');updateDraftStatus();
    }
    function endCanvasPointer(ev) {
      if(!dragState || (ev && ev.pointerId!==undefined && ev.pointerId!==dragState.pointerId))return;
      if(dragState.changed){undoStack.push(dragState.snapshot);if(undoStack.length>60)undoStack.shift();redoStack=[];}
      if(dragState.element.hasPointerCapture(dragState.pointerId))dragState.element.releasePointerCapture(dragState.pointerId);
      dragState=null;renderLayers();updateDraftStatus();
    }
    function openItemContext(ev) {
      if(previewMode)return;ev.preventDefault();ev.stopPropagation();selectNode(ev.currentTarget.dataset.nodeId);
      $('editorWorkspace').dataset.mobilePanel='properties';placeMenu($('itemContextMenu'),ev.clientX,ev.clientY);
    }
    function placeMenu(menu,x,y) { closeMenus();menu.hidden=false;menu.style.left=clamp(x,8,window.innerWidth-menu.offsetWidth-8)+'px';menu.style.top=clamp(y,8,window.innerHeight-menu.offsetHeight-8)+'px'; }
    function closeMenus() { $('itemContextMenu').hidden=true;$('editorMoreMenu').hidden=true; }
    function renderLayers() {
      const target=$('layerList');target.replaceChildren();if(!draft)return;
      [...currentLayout().nodes].reverse().forEach(item=>{
        const row=makeText('div','layer-item',''),entry=document.createElement('button');entry.type='button';entry.className='palette-item'+(item.id===selectedId?' active':'');
        entry.innerHTML=icon(item.icon==='none'?'info':item.icon);const text=makeText('span','',(item.hidden?'◌ ':'')+(item.label || item.kind));text.append(makeText('small','',item.action || item.kind));entry.append(text);
        entry.addEventListener('click',()=>{selectNode(item.id);if(window.innerWidth<=780)$('editorWorkspace').dataset.mobilePanel='properties';});row.append(entry);target.append(row);
      });
    }
    function showPropertyTab(tab) {
      $('themeProperties').hidden=tab!=='theme';$('itemProperties').hidden=tab!=='item';$('themePropertiesTab').classList.toggle('active',tab==='theme');$('itemPropertiesTab').classList.toggle('active',tab==='item');
    }
    function field(parent,label,value,type,onChange,options={}) {
      const wrapper=makeText('label','field','');wrapper.append(makeText('span','',label));const input=document.createElement(type==='select'?'select':'input');
      if(type==='select'){
        (options.choices || []).forEach(choice=>{const option=document.createElement('option');option.value=Array.isArray(choice)?choice[0]:choice;option.textContent=Array.isArray(choice)?choice[1]:choice;input.append(option);});
      }else input.type=type;
      input.value=value;if(options.id)input.id=options.id;
      for(const key of ['min','max','step','placeholder','maxLength'])if(options[key]!==undefined)input[key]=options[key];
      input.addEventListener('change',()=>{
        if(previewMode || themeSaving){input.value=value;return;}
        let next=input.value;
        if(type==='number'){next=Number(next);if(!Number.isFinite(next)){input.value=value;return;}next=clamp(next,options.min===undefined?-Infinity:options.min,options.max===undefined?Infinity:options.max);input.value=next;}
        onChange(next,input);
      });wrapper.append(input);parent.append(wrapper);return input;
    }
    function section(parent,label) { const el=makeText('div','properties-section','');el.append(makeText('h4','',label));parent.append(el);return el; }
    function checkField(parent,label,value,onChange) {
      const el=makeText('label','check-field','');const input=document.createElement('input');input.type='checkbox';input.checked=value;
      input.addEventListener('change',()=>{if(!previewMode && !themeSaving)onChange(input.checked);else input.checked=value;});el.append(input,makeText('span','',label));parent.append(el);
    }
    function colorField(parent,label,value,fallback,onChange,inherit=false) {
      const row=makeText('div','color-field','');const input=document.createElement('input');input.type='color';input.value=value || fallback;input.setAttribute('aria-label',label);
      input.addEventListener('change',()=>{if(!previewMode && !themeSaving)onChange(input.value);});row.append(input,makeText('span','',label));
      if(inherit){const reset=document.createElement('button');reset.type='button';reset.textContent=value?'Reset':'Theme';reset.title='Use theme default';reset.addEventListener('click',()=>{if(!previewMode){onChange('');input.value=fallback;reset.textContent='Theme';}});row.append(reset);}
      else row.append(makeText('small','',value));parent.append(row);
    }
    function row(parent) { const target=makeText('div','form-row','');parent.append(target);return target; }
    function renderThemeProperties() {
      const target=$('themeProperties');target.replaceChildren();if(!draft)return;
      const currentId=draft.id;
      field(target,'Start from a theme',currentId,'select',value=>{
        if(dirty() && !confirm('Discard this unsaved draft and open another theme?')){renderThemeProperties();return;}
        openDraft(findTheme(value));
      },{choices:themes().map(theme=>[theme.id,theme.name])});
      if(!themes().some(t=>t.id===currentId))target.querySelector('select').value='';
      field(target,'Theme name',draft.name,'text',value=>{mutate(()=>draft.name=value);},{maxLength:60});
      const basics=section(target,'Theme appearance');
      field(basics,'Mode',draft.mode,'select',value=>mutate(()=>draft.mode=value),{choices:[['light','Light'],['dark','Dark']]});
      field(basics,'Font',draft.font,'select',value=>mutate(()=>draft.font=value),{choices:[['system','System sans'],['rounded','Rounded sans'],['mono','Monospace'],['serif','Serif']]});
      const sizes=row(basics);field(sizes,'Corners',draft.radius,'number',value=>mutate(()=>draft.radius=value),{min:0,max:100});field(sizes,'3D depth',draft.buttonDepth,'number',value=>mutate(()=>draft.buttonDepth=value),{min:0,max:20});
      field(basics,'Button shadow',draft.buttonShadow,'number',value=>mutate(()=>draft.buttonShadow=value),{min:0,max:40});
      field(basics,'Default hover',draft.hoverEffect,'select',value=>mutate(()=>draft.hoverEffect=value),{choices:[['none','None'],['lift','Lift'],['grow','Grow'],['glow','Glow']]});
      const colors=section(target,'Theme colors');
      const labels={background:'Page background',surface:'Canvas / panels',button:'Button background',text:'Text',muted:'Secondary text',accent:'Accent',accentText:'Accent text',border:'Borders / 3D edge',hover:'Hover background',hoverText:'Hover text',display:'Display background',displayText:'Display text'};
      Object.entries(labels).forEach(([key,label])=>colorField(colors,label,draft.colors[key],draft.colors[key],value=>{mutate(()=>draft.colors[key]=value);renderThemeProperties();}));
      const canvas=section(target,(editView==='mini'?'Mini remote':'All commands')+' canvas');const layout=currentLayout();
      const bounds={width:Math.max(280,...layout.nodes.map(n=>n.x+n.w)),height:Math.max(200,...layout.nodes.map(n=>n.y+n.h))};
      const dimensions=row(canvas);
      field(dimensions,'Width (px)',layout.width,'number',value=>mutate(()=>layout.width=value,true),{min:bounds.width,max:1800});
      field(dimensions,'Height (px)',layout.height,'number',value=>mutate(()=>layout.height=value,true),{min:bounds.height,max:3000});
      const reset=document.createElement('button');reset.type='button';reset.className='ui-btn';reset.textContent='Reset this layout';reset.addEventListener('click',()=>{
        if(confirm('Reset this canvas to the built-in layout?'))mutate(()=>{draft.layouts[editView]=clone((presets.find(t=>t.id===draft.id) || presets[0]).layouts[editView]);selectedId=null;},true);
      });canvas.append(reset,makeText('p','sidebar-note','Both canvases are saved with this theme. On small screens, All commands uses a readable two-column arrangement.'));
    }
    function renderItemProperties() {
      const target=$('itemProperties');target.replaceChildren();const item=selectedNode();
      if(!item){target.append(makeText('p','properties-empty','Select any item on the canvas. Drag to move it, use its handles to resize, or right-click to open these properties.'));return;}
      target.append(makeText('h3','sidebar-heading',item.kind==='button'?'Button properties':'Item properties'));
      const change=(key,value)=>mutate(()=>item[key]=value);
      const styleChange=(key,value)=>mutate(()=>item.style[key]=value);
      field(target,'Label / tooltip',item.label,'text',value=>change('label',value),{maxLength:120});
      if(item.kind==='button'){
        field(target,'Command / action',item.action,'text',(value,input)=>{value=value.trim();if(!['toggle_power','toggle_mute','refresh','test'].includes(value))value=value.toUpperCase();
          if(!validAction(value)){input.value=item.action;notice('Choose an allowed command, MV00–60, FV01–50, or TFAN + six digits.');return;}change('action',value);input.value=value;});
        target.append(makeText('p','sidebar-note','Special actions: toggle_power, toggle_mute, refresh, test. All fixed commands are in the palette.'));
        field(target,'Icon',item.icon,'select',value=>change('icon',value),{choices:itemIcons.map(key=>[key,key.charAt(0).toUpperCase()+key.slice(1)])});
        checkField(target,'Icon only (label stays accessible)',item.style.iconOnly,value=>styleChange('iconOnly',value));
        checkField(target,'Repeat command while held',item.repeat,value=>change('repeat',value));
      }
      if(item.kind==='state')field(target,'Live value',item.key,'select',value=>change('key',value),{choices:['power','source','mute','tuner']});
      checkField(target,'Hidden on the remote',item.hidden,value=>change('hidden',value));
      const geometry=section(target,'Position & size');
      let group=row(geometry);
      field(group,'X',item.x,'number',value=>mutate(()=>item.x=clamp(value,0,currentLayout().width-item.w),true),{id:'item-x',min:0,max:currentLayout().width-item.w});
      field(group,'Y',item.y,'number',value=>mutate(()=>item.y=clamp(value,0,currentLayout().height-item.h),true),{id:'item-y',min:0,max:currentLayout().height-item.h});
      group=row(geometry);
      field(group,'Width',item.w,'number',value=>mutate(()=>item.w=clamp(value,24,currentLayout().width-item.x),true),{id:'item-w',min:24,max:currentLayout().width-item.x});
      field(group,'Height',item.h,'number',value=>mutate(()=>item.h=clamp(value,24,currentLayout().height-item.y),true),{id:'item-h',min:24,max:currentLayout().height-item.y});
      const appearance=section(target,'Appearance');
      if(item.kind==='button')field(appearance,'Color role',item.style.tone,'select',value=>{styleChange('tone',value);renderItemProperties();},{choices:[['default','Standard'],['accent','Accent']]});
      const roleBg=item.kind==='display'?draft.colors.display:item.style.tone==='accent'?draft.colors.accent:draft.colors.button;
      const roleFg=item.kind==='display'?draft.colors.displayText:item.style.tone==='accent'?draft.colors.accentText:draft.colors.text;
      [['bg','Background',roleBg],['fg','Text / icon',roleFg],['hoverBg','Hover background',draft.colors.hover],['hoverFg','Hover text / icon',draft.colors.hoverText],['borderColor','Border / 3D edge',draft.colors.border],['gradient','Gradient end (optional)',roleBg]]
        .forEach(c=>colorField(appearance,c[1],item.style[c[0]],c[2],value=>{styleChange(c[0],value);renderItemProperties();},true));
      group=row(appearance);
      field(group,'Corners (−1 = theme)',item.style.radius,'number',value=>styleChange('radius',value),{min:-1,max:100});
      field(group,'Border width',item.style.borderWidth,'number',value=>styleChange('borderWidth',value),{min:0,max:8});
      group=row(appearance);field(group,'Text size',item.style.fontSize,'number',value=>styleChange('fontSize',value),{min:10,max:80});field(group,'Icon size',item.style.iconSize,'number',value=>styleChange('iconSize',value),{min:12,max:100});
      field(appearance,'Text weight',item.style.weight,'select',value=>styleChange('weight',value),{choices:[['400','Regular'],['500','Medium'],['600','Semibold'],['700','Bold'],['800','Heavy']]});
      field(appearance,'Alignment',item.style.alignment,'select',value=>styleChange('alignment',value),{choices:['left','center','right']});
      field(appearance,'Opacity',item.style.opacity,'number',value=>styleChange('opacity',value),{min:.2,max:1,step:.05});
      const effects=section(target,'3D, hover & pressed state');group=row(effects);
      field(group,'3D depth (−1 = theme)',item.style.depth,'number',value=>styleChange('depth',value),{min:-1,max:20});
      field(group,'Shadow (−1 = theme)',item.style.shadow,'number',value=>styleChange('shadow',value),{min:-1,max:40});
      field(effects,'On hover',item.style.hoverEffect,'select',value=>styleChange('hoverEffect',value),{choices:[['theme','Use theme'],['none','None'],['lift','Lift'],['grow','Grow'],['glow','Glow']]});
      field(effects,'When pressed',item.style.pressEffect,'select',value=>styleChange('pressEffect',value),{choices:[['sink','Sink'],['shrink','Shrink'],['none','None']]});
      const actions=section(target,'Layer actions'),buttons=makeText('div','inline','');
      [['duplicate','Duplicate'],['front','Front'],['back','Back'],['delete','Delete']].forEach(([action,label])=>{const btn=document.createElement('button');btn.type='button';btn.className='ui-btn'+(action==='delete'?' danger':'');btn.textContent=label;btn.addEventListener('click',()=>itemAction(action));buttons.append(btn);});actions.append(buttons);
    }
    function itemAction(action) {
      const item=selectedNode();closeMenus();if(!item || previewMode)return;
      if(action==='properties'){renderItemProperties();showPropertyTab('item');$('editorWorkspace').dataset.mobilePanel='properties';return;}
      mutate(()=>{
        const layout=currentLayout(),index=layout.nodes.indexOf(item);
        if(action==='duplicate'){
          if(layout.nodes.length>=200){notice('This canvas already has 200 items.');return;}
          const copy=clone(item);copy.id=newId('item');copy.x=clamp(item.x+16,0,layout.width-item.w);copy.y=clamp(item.y+16,0,layout.height-item.h);layout.nodes.splice(index+1,0,copy);selectedId=copy.id;
        }else if(action==='front'){layout.nodes.splice(index,1);layout.nodes.push(item);}
        else if(action==='back'){layout.nodes.splice(index,1);layout.nodes.unshift(item);}
        else if(action==='hide'){item.hidden=!item.hidden;}
        else if(action==='delete'){layout.nodes.splice(index,1);selectedId=null;}
      },true);
    }
    function historyAction(redo=false) {
      if(previewMode || themeSaving)return;endCanvasPointer();const source=redo?redoStack:undoStack,destination=redo?undoStack:redoStack;if(!source.length)return;
      destination.push(clone(draft));draft=source.pop();renderEditorCanvas();renderThemeProperties();renderItemProperties();renderLayers();updateDraftStatus();
    }
    function openDraft(theme) {
      draft=clone(theme);draftBase=JSON.stringify(draft);undoStack=[];redoStack=[];selectedId=null;previewMode=false;$('editorShell').dataset.preview='0';$('previewButton').textContent='Preview';
      renderEditorCanvas();renderThemeProperties();renderItemProperties();renderLayers();updateDraftStatus();showPropertyTab('theme');
    }
    function openEditor() {
      stopHolding();remoteQueue.splice(0).forEach(task=>task.resolve());editorOpen=true;$('editorShell').hidden=false;$('appRoot').inert=true;document.body.style.overflow='hidden';editView=activeView;$('editorWorkspace').dataset.mobilePanel='canvas';
      openDraft(activeTheme);setEditorMessage('Drag to move · Handles to resize · Right-click for properties');
    }
    async function requestEditor() {
      try {
        authPurpose='editor';
        const state=await api('editor_state');resumeEditorDraft=editorOpen;
        if(!resumeEditorDraft)preferences=state.preferences;
        setupRequired=state.setupRequired;authenticated=state.authenticated;
        if(!resumeEditorDraft){activeView=preferences.activeView;applyTheme(findTheme(preferences.activeTheme));}
        if(authenticated){if(!resumeEditorDraft)openEditor();return;}
        $('authTitle').textContent=setupRequired?'Create your admin password':'Unlock the editor';
        $('authDescription').textContent=setupRequired?'Choose an admin password of at least 8 characters. It protects editing and saving both remote layouts.':'Enter the admin password to edit themes and layouts.';
        $('authSubmit').textContent=setupRequired?'Create password & open editor':'Unlock editor';$('confirmPasswordField').hidden=!setupRequired;$('confirmAdminPassword').required=setupRequired;
        $('adminPassword').autocomplete=setupRequired?'new-password':'current-password';$('authError').textContent='';$('authForm').reset();showModal('authModal','adminPassword');
      }catch(error){notice(error.message);}
    }
    function closeEditor(force=false) {
      if(themeSaving)return false;
      if(!force && dirty() && !confirm('Close the editor and discard unsaved changes?'))return false;
      endCanvasPointer();stopHolding();editorOpen=false;$('editorShell').hidden=true;$('appRoot').inert=false;document.body.style.overflow='';closeMenus();applyTheme(findTheme(preferences.activeTheme));$('editorButton').focus();return true;
    }
    async function saveTheme() {
      if(themeSaving)return;endCanvasPointer();themeSaving=true;updateDraftStatus();
      let theme=clone(draft);
      if(!theme.id.startsWith('custom-')){const old=findTheme(theme.id);theme.id=newId('custom');if(theme.name===old.name)theme.name+=' Custom';}
      try {
        const result=await api('skin_save',{theme:JSON.stringify(theme),revision:preferences.revision});preferences=result.preferences;
        activeTheme=findTheme(preferences.activeTheme);draft=clone(activeTheme);draftBase=JSON.stringify(draft);undoStack=[];redoStack=[];
        applyTheme(activeTheme);renderEditorCanvas();renderThemeProperties();renderItemProperties();renderLayers();setEditorMessage('Saved to denon-ceol-preferences.json');notice('Theme and both layouts saved.');
      }catch(error){setEditorMessage(error.message);notice(error.message);if(!authenticated)requestEditor();}
      finally{themeSaving=false;updateDraftStatus();}
    }

    function isDirectMode() { return preferences.connectionMode === 'direct'; }
    function directTarget() {
      const ip=String(preferences.denonIp || '');
      const parts=ip.split('.').map(Number);
      const valid=/^(0|[1-9][0-9]{0,2})(\.(0|[1-9][0-9]{0,2})){3}$/.test(ip) && parts.every(n=>n<=255);
      const local=valid && (parts[0]===10 || parts[0]===127 || (parts[0]===172 && parts[1]>=16 && parts[1]<=31) ||
        (parts[0]===192 && parts[1]===168) || (parts[0]===169 && parts[1]===254));
      if(!valid || (!local && !bootData.allowPublicDenonIp))throw new Error('Browser direct needs a valid private Denon IPv4 address.');
      const scheme=preferences.directScheme,port=Number(preferences.directPort);
      if(!['http','https'].includes(scheme) || !Number.isInteger(port) || port<1 || port>65535)throw new Error('Check the Denon HTTP protocol and port in Connection settings.');
      return {base:scheme+'://'+ip+':'+port,local,loopback:parts[0]===127};
    }
    function directCommandPath(command) {
      if(!validCommand(command))throw new Error('Command not allowed: '+command);
      return bootData.httpCommandPaths[command] || '/goform/formiPhoneAppDirect.xml?'+encodeURIComponent(command);
    }
    function setConnectionNotice(text,error=false) {
      $('connectionNotice').textContent=text;$('connectionNotice').hidden=!text;$('connectionNotice').classList.toggle('error',error);
    }
    function directModeNotice() {
      const policy=location.protocol==='https:' && preferences.directScheme==='http'
        ? ' HTTPS → HTTP can be blocked as mixed content. Supporting browsers may allow it after Local Network Access permission.' : '';
      return 'Browser direct · '+(preferences.directReadback?'HTTP replies require Denon CORS.':'Dispatch only: replies are opaque; delivery and HTTP status are unconfirmed.')+
        ' Power, source, mute, and volume are estimates. Live status polling is off; Refresh/Test only probe PW?.'+policy;
    }
    function updateConnectionInfo() {
      $('ipText').textContent=preferences.denonIp || 'Connect';
      $('transportText').textContent='CEOL / RCD-N9 · '+(isDirectMode()?'Browser direct':'Server relay');
      setConnectionNotice(isDirectMode()?directModeNotice():'');
      updateLiveDisplays();
    }
    async function checkDirectPermission(target) {
      if(!target.local || !navigator.permissions || !navigator.permissions.query)return;
      // Unsupported permission names are normal in other/older browsers.
      for(const name of [target.loopback?'loopback-network':'local-network','local-network-access']) {
        let permission;
        try{permission=await navigator.permissions.query({name});}catch(error){continue;}
        if(permission.state==='denied')throw new Error('Local/private-network access is denied by this browser or site policy. Allow Local Network Access in site settings, or explicitly select Server relay.');
        break;
      }
    }
    function staleConnectionError() { const error=new Error('Connection settings changed; the old request was discarded.');error.staleConnection=true;return error; }
    async function directSend(command,epoch) {
      const target=directTarget(),url=target.base+directCommandPath(command),readback=!!preferences.directReadback;
      await checkDirectPermission(target);
      if(epoch!==connectionEpoch)throw staleConnectionError();
      const controller=new AbortController();
      const timer=setTimeout(()=>controller.abort(),DIRECT_TIMEOUT_MS);
      try {
        const options={method:'GET',mode:readback?'cors':'no-cors',cache:'no-store',credentials:'omit',referrerPolicy:'no-referrer',redirect:'error',signal:controller.signal};
        if(target.local && typeof Request!=='undefined' && 'targetAddressSpace' in Request.prototype)options.targetAddressSpace=target.loopback?'loopback':'local';
        const response=await fetch(url,options);
        if(response.type!=='opaque' && !response.ok)throw new Error('Denon returned HTTP '+response.status+' at '+target.base+'. Check the port and goform endpoint.');
        if(readback && response.type==='opaque')throw new Error('The Denon reply is opaque; CORS readback was requested but is unavailable.');
        clearTimeout(timer);
        // Retain the relay's pacing, including the power-on settling delay.
        await new Promise(resolve=>setTimeout(resolve,command==='PWON'?1000:80));
        // Do not promote an opaque response into an acknowledgement or real state.
        return {ok:true,direct:true,replyVerified:response.type!=='opaque',httpStatus:response.status,command,lines:[]};
      } catch(error) {
        if(controller.signal.aborted)throw new Error('Browser direct timed out after '+(DIRECT_TIMEOUT_MS/1000)+' seconds at '+target.base+'. The command may have reached the Denon; it was not retried. Check device power, Network Control, IP/port, and browser Local Network Access.');
        if(error instanceof TypeError) {
          const mixed=location.protocol==='https:' && target.base.startsWith('http:');
          throw new Error('Browser direct failed at '+target.base+'. '+(mixed?'HTTPS → HTTP may be blocked as mixed content. ':'')+
            (readback?'CORS readback may be blocked. ':'')+'Local/private-network policy, TLS trust, or an unreachable device can also cause this error. JavaScript cannot distinguish these causes; inspect the browser console/Network panel and open the Denon web interface from this browser. The command may have reached the device; it was not retried through PHP.');
        }
        throw error;
      } finally { clearTimeout(timer); }
    }
    async function remoteRequest(action,data={}) {
      const epoch=connectionEpoch,mode=preferences.connectionMode || 'relay';
      if(!['relay','direct'].includes(mode))throw new Error('Select a valid connection mode.');
      let command;
      if(action==='command')command=String(data.command || '').trim().toUpperCase();
      else if(action==='volume_set')command='MV'+String(clamp(Math.trunc(Number(data.value) || 0),0,60)).padStart(2,'0');
      else if(action==='favorite')command='FV'+String(clamp(Math.trunc(Number(data.value) || 1),1,50)).padStart(2,'0');
      else if(['status','test'].includes(action))command='PW?';
      else throw new Error('Unknown remote action.');
      if(!validCommand(command))throw new Error('Command not allowed: '+command);
      try { return mode==='direct'?await directSend(command,epoch):await api(action,action==='command'?Object.assign({},data,{command}):data); }
      finally { if(epoch!==connectionEpoch)throw staleConnectionError(); }
    }
    function estimateDirectCommand(command) {
      if(['PWON','PWSTANDBY'].includes(command)){liveState.power=command==='PWON'?'on':'standby';liveState.powerLabel=command==='PWON'?'On':'Standby';}
      if(['MUON','MUOFF'].includes(command))liveState.mute=command==='MUON';
      if(/^MV[0-9]{2}$/.test(command))liveState.volumeNumber=Number(command.slice(2));
      if(bootData.sourceLabels[command]){liveState.sourceRaw=command;liveState.sourceLabel=bootData.sourceLabels[command];}
      updateLiveDisplays();
    }
    function remoteFailure(error) {
      if(error.staleConnection)return;
      setConnection(false,isDirectMode()?'Direct · Failed':'Relay · Failed');setLog('FAIL '+error.message);
      if(isDirectMode()) {
        stopHolding();remoteQueue.splice(0).forEach(task=>task.resolve());
        setConnectionNotice(error.message+' Pending commands stopped. Browser direct remains selected; no relay fallback was attempted.',true);
      }
    }
    function setConnection(online,label) { $('connDot').classList.toggle('online',online);$('connText').textContent=label || (online?'Relay · Online':'Relay · Offline'); }
    function setLog(text) {
      logLines.unshift('['+new Date().toLocaleTimeString()+'] '+text);logLines=logLines.slice(0,70);
      document.querySelectorAll('[data-live="log"]').forEach(el=>el.textContent=logLines.join('\n'));
      $('footerState').textContent=text.length>100?text.slice(0,100)+'…':text;
    }
    function updateVolume(value) { const n=Number(value);if(Number.isFinite(n)){liveState.volumeNumber=clamp(Math.round(n),0,60);updateLiveDisplays();} }
    function mergeState(state,lines=[]) {
      if(!state)return;
      if(state.power && state.power!=='unknown'){liveState.power=state.power;liveState.powerLabel=state.powerLabel;}
      if(typeof state.mute==='boolean'){liveState.mute=state.mute;liveState.muteLabel=state.muteLabel;}
      if(state.volumeNumber!==null && state.volumeNumber!==undefined)liveState.volumeNumber=clamp(Number(state.volumeNumber),0,60);
      if(state.sourceRaw){liveState.sourceRaw=state.sourceRaw;liveState.sourceLabel=state.sourceLabel;}
      ['tunerFrequencyLabel','tunerStationName','tunerMode'].forEach(key=>{if(state[key])liveState[key]=state[key];});
      let displayReceived=false;
      lines.forEach(line=>{const match=line.match(/^NS[AE]([0-8])(.*)$/u);if(match){liveState.display[Number(match[1])]=match[2].trim();displayReceived=true;}});
      if(!displayReceived && Array.isArray(state.display) && state.display.some(line=>line!==''))liveState.display=state.display;
      updateLiveDisplays();
    }
    function updateLiveDisplays() {
      document.querySelectorAll('[data-live="volume"]').forEach(el=>{el.textContent=liveState.volumeNumber===null?'--':String(liveState.volumeNumber).padStart(2,'0');el.title=isDirectMode()?'Estimated volume; direct mode has no live status readback.':'Volume';});
      document.querySelectorAll('[data-live="slider"]').forEach(el=>{if(document.activeElement!==el)el.value=liveState.volumeNumber || 0;});
      document.querySelectorAll('[data-live="fallback"]').forEach(el=>{el.checked=preferences.useHttpFallback;el.disabled=isDirectMode() || editorOpen;el.title=isDirectMode()?'HTTP fallback applies only to Server relay.':'Use HTTP instead of TCP for relay commands.';});
      const tuner=[liveState.tunerFrequencyLabel,liveState.tunerStationName,liveState.tunerMode].filter(Boolean).join(' · ');
      const values={power:liveState.powerLabel || 'Unknown',source:liveState.sourceLabel || 'Unknown',mute:typeof liveState.mute==='boolean'?(liveState.mute?'Muted':'Live'):'Unknown',tuner:tuner || '—'};
      document.querySelectorAll('[data-live="state"]').forEach(el=>{const value=values[el.dataset.key];el.textContent=value+(isDirectMode() && !['Unknown','—'].includes(value)?' (est.)':'');el.title=value;});
      document.querySelectorAll('[data-live="display"]').forEach(el=>{
        const indexes=el.dataset.view==='mini'?[0,1,4,5]:[0,1,2,3,4,5,6,7,8];el.replaceChildren();
        indexes.forEach(index=>{const text=(liveState.display[index] || '').trim();el.append(makeText('div','display-line'+(text?'':' empty'),text || '····················'));});
      });
      document.querySelectorAll('[data-live="log"]').forEach(el=>el.textContent=logLines.join('\n'));
      document.querySelectorAll('.button-node').forEach(wrapper=>{
        const action=wrapper.dataset.action,face=wrapper.querySelector('.node-face');
        const on=action==='toggle_power'?liveState.power==='on':action==='toggle_mute'?liveState.mute===true:
          action.startsWith('SI')?(action===liveState.sourceRaw || (['SIBT','SIBLUETOOTH'].includes(action) && ['SIBT','SIBLUETOOTH'].includes(liveState.sourceRaw))):false;
        face.classList.toggle('is-active',on);
        if(action==='toggle_mute'){
          const layout=wrapper.closest('#editorCanvas') && draft?draft.layouts[wrapper.dataset.view]:activeTheme.layouts[wrapper.dataset.view];
          const item=layout.nodes.find(n=>n.id===wrapper.dataset.nodeId);
          if(item && ['volume','mute'].includes(item.icon)){
            const svg=face.querySelector('svg');if(svg){const holder=document.createElement('span');holder.innerHTML=icon(liveState.mute?'mute':'volume');svg.replaceWith(holder.firstChild);}
          }
          face.setAttribute('aria-label',liveState.mute?'Unmute':'Mute');
        }
      });
    }
    function handleRemoteResult(result,label) {
      if(result.direct) {
        estimateDirectCommand(result.command);
        setConnection(result.replyVerified,result.replyVerified?'Direct · HTTP replied':'Direct · Unconfirmed');
        setConnectionNotice(directModeNotice());
        setLog('DIRECT '+result.command+(result.replyVerified?' · HTTP '+result.httpStatus+' replied':' · dispatched; delivery unconfirmed')+
          (result.command==='PW?'?' · live status unavailable':' · displayed changes are estimates'));
      } else { setConnection(true);mergeState(result.state,result.lines || []);setLog(result.lines && result.lines.length?'RX '+result.lines.join(' | '):label+' · accepted'); }
    }
    // Both transports use this queue; never retry a failed command in another mode.
    function enqueueRemote(operation,key='') {
      if(editorOpen || connectionSaving)return Promise.resolve();
      return new Promise(resolve=>{
        if(key){const pending=remoteQueue.find(task=>task.key===key);if(pending){pending.resolve();pending.operation=operation;pending.resolve=resolve;return;}}
        if(remoteQueue.length>=20){notice('The remote is catching up. Try again in a moment.');resolve();return;}
        remoteQueue.push({operation,key,resolve,epoch:connectionEpoch});drainRemote();
      });
    }
    async function drainRemote() {
      if(busy || editorOpen || !remoteQueue.length)return;
      const task=remoteQueue.shift();busy=true;
      try{if(task.epoch===connectionEpoch)await task.operation();}finally{busy=false;task.resolve();drainRemote();}
    }
    function sendCommand(command) {
      return enqueueRemote(async()=>{
        const actual=String(typeof command==='function'?command():command).trim().toUpperCase();
        const oldState=clone(liveState);setLog('TX '+actual);
        if(actual==='MVUP' && liveState.volumeNumber!==null)updateVolume(liveState.volumeNumber+1);
        if(actual==='MVDOWN' && liveState.volumeNumber!==null)updateVolume(liveState.volumeNumber-1);
        if(['MUON','MUOFF'].includes(actual)){liveState.mute=actual==='MUON';updateLiveDisplays();}
        try { const result=await remoteRequest('command',{command:actual,httpFallback:preferences.useHttpFallback?'1':'0'});handleRemoteResult(result,actual); }
        catch(error){if(error.staleConnection)return;liveState=oldState;updateLiveDisplays();remoteFailure(error);}
      });
    }
    function runAction(action) {
      if(editorOpen)return;
      if(action==='toggle_power')sendCommand(()=>liveState.power==='on'?'PWSTANDBY':'PWON');
      else if(action==='toggle_mute')sendCommand(()=>liveState.mute===true?'MUOFF':'MUON');
      else if(action==='refresh')refreshStatus(true);
      else if(action==='test')testConnection();
      else sendCommand(action);
    }
    function stopHolding() { if(heldStop){const stop=heldStop;heldStop=null;stop();} }
    function bindRemoteButton(face,item) {
      if(!item.repeat){face.addEventListener('click',()=>runAction(item.action));return;}
      face.addEventListener('pointerdown',ev=>{
        if(editorOpen || ev.button!==0)return;ev.preventDefault();stopHolding();face.setPointerCapture(ev.pointerId);runAction(item.action);
        const timer=setInterval(()=>{if(!busy && !remoteQueue.length)runAction(item.action);},320);heldStop=()=>clearInterval(timer);
      });
      face.addEventListener('click',ev=>{if(ev.detail===0)runAction(item.action);});
      face.addEventListener('pointerleave',stopHolding);face.addEventListener('lostpointercapture',stopHolding);
    }
    async function refreshStatus(manual=false) {
      if(!preferences.denonIp || connectionSaving || (isDirectMode() && !manual))return;
      if(manual && !editorOpen)return enqueueRemote(async()=>{
        try{handleRemoteResult(await remoteRequest('status'),'Status');}catch(error){remoteFailure(error);}
      },'status');
      if(busy)return;busy=true;
      try{handleRemoteResult(await remoteRequest('status'),'Status');}
      catch(error){if(!error.staleConnection){setConnection(false,'Relay · Offline');if(manual)remoteFailure(error);}}
      finally{busy=false;drainRemote();}
    }
    function testConnection() {
      return enqueueRemote(async()=>{
        setConnection(false,'Testing');try{handleRemoteResult(await remoteRequest('test'),'Connection test');}catch(error){remoteFailure(error);}
      });
    }
    function setVolume(value) {
      const volume=clamp(Math.trunc(Number(value) || 0),0,60);
      return enqueueRemote(async()=>{
        const old=liveState.volumeNumber;updateVolume(volume);setLog('TX MV'+String(volume).padStart(2,'0'));
        try{handleRemoteResult(await remoteRequest('volume_set',{value:volume}),'Volume');}catch(error){if(error.staleConnection)return;liveState.volumeNumber=old;updateLiveDisplays();remoteFailure(error);}
      },'volume');
    }
    function favoriteGo(input) {
      const value=clamp(parseInt(input.value,10) || 1,1,50);input.value=value;
      return enqueueRemote(async()=>{
        setLog('TX FV'+String(value).padStart(2,'0'));
        try{handleRemoteResult(await remoteRequest('favorite',{value}),'Favorite');}catch(error){remoteFailure(error);}
      });
    }
    function startPolling(probeDirect=false) {
      clearInterval(pollTimer);pollTimer=null;updateConnectionInfo();
      if(isDirectMode()){setConnection(false,'Direct · Ready');if(probeDirect)refreshStatus(true);return;}
      refreshStatus(true);pollTimer=setInterval(()=>{if(!document.hidden)refreshStatus();},3000);
    }

    function updateBusy(busy) {
      ['closeUpdateButton','laterUpdateButton','retryUpdateButton','installUpdateButton'].forEach(id=>$(id).disabled=busy);
      $('updateModal').setAttribute('aria-busy',String(busy));
    }
    function renderUpdateOffer() {
      if(!updateInfo || !updateInfo.available)return;
      pendingUpdateOffer=false;$('updateTitle').textContent='A new version is available';
      $('updateDescription').textContent='Install this version from GitHub? Your confirmation and admin password are required.';
      $('installedVersion').textContent='v'+updateInfo.currentVersion;$('availableVersion').textContent='v'+updateInfo.latestVersion;
      $('updateVersions').hidden=false;$('updatePreservation').hidden=false;$('installUpdateButton').hidden=false;
      $('retryUpdateButton').hidden=false;$('laterUpdateButton').textContent='Later';$('updateError').textContent='';updateBusy(false);
      showModal('updateModal','laterUpdateButton');
    }
    function offerPendingUpdate() {
      if(pendingUpdateOffer && !document.hidden && !editorOpen && !updateInstalling
          && Array.from(document.querySelectorAll('.overlay')).every(el=>el.hidden))renderUpdateOffer();
    }
    async function checkForUpdates(manual=false) {
      if(updateChecking || updateInstalling)return;
      if(!manual && !$('updateModal').hidden)return;
      if(manual && editorOpen){notice('Close the editor before checking for updates.');return;}
      updateChecking=true;$('checkUpdatesButton').disabled=true;
      if(manual){
        pendingUpdateOffer=false;
        $('updateTitle').textContent='Software updates';$('updateDescription').textContent='Checking GitHub for a newer version…';
        $('updateVersions').hidden=true;$('updatePreservation').hidden=true;$('installUpdateButton').hidden=true;
        $('retryUpdateButton').hidden=true;$('laterUpdateButton').textContent='Close';$('updateError').textContent='';
        updateBusy(false);showModal('updateModal');
      }
      try {
        updateInfo=await api('update_check',{force:manual?'1':'0'});
        $('checkUpdatesButton').classList.toggle('available',updateInfo.available);
        $('checkUpdatesButton').textContent=updateInfo.available?'v'+updateInfo.latestVersion+' available':'Check for updates';
        $('checkUpdatesButton').title='Last checked '+new Date(updateInfo.checkedAt*1000).toLocaleString();
        if(updateInfo.available){
          if(manual && !$('updateModal').hidden)renderUpdateOffer();
          else if(!manual && updateInfo.latestVersion!==dismissedUpdateVersion){pendingUpdateOffer=true;offerPendingUpdate();}
        }else {
          pendingUpdateOffer=false;
          if(manual && !$('updateModal').hidden){$('updateTitle').textContent='You’re up to date';$('updateDescription').textContent='Version '+bootData.version+' is installed. There is no newer version on GitHub.';$('retryUpdateButton').hidden=false;}
        }
      }catch(error){
        if(manual && !$('updateModal').hidden){$('updateDescription').textContent='The online check could not be completed.';$('updateError').textContent=error.message;$('retryUpdateButton').hidden=false;}
        else $('checkUpdatesButton').title=error.message;
      }finally{updateChecking=false;$('checkUpdatesButton').disabled=false;}
    }
    async function requestUpdateAuth() {
      const state=await api('editor_state');setupRequired=state.setupRequired;authenticated=state.authenticated;
      authPurpose='update';resumeEditorDraft=false;
      $('authTitle').textContent=setupRequired?'Create your admin password':'Authorize the update';
      $('authDescription').textContent=setupRequired?'Choose an admin password of at least 8 characters to protect updates and the theme editor.':'Enter the admin password to install version '+updateInfo.latestVersion+'.';
      $('authSubmit').textContent=setupRequired?'Create password & update':'Unlock & update';
      $('confirmPasswordField').hidden=!setupRequired;$('confirmAdminPassword').required=setupRequired;
      $('adminPassword').autocomplete=setupRequired?'new-password':'current-password';$('authError').textContent='';$('authForm').reset();showModal('authModal','adminPassword');
    }
    async function installUpdate() {
      if(updateInstalling || updateChecking || !updateInfo || !updateInfo.available)return;
      if(editorOpen && (dirty() || themeSaving)){$('updateError').textContent='Save your theme and close the editor before updating.';return;}
      if(editorOpen && !closeEditor())return;
      updateInstalling=true;updateBusy(true);stopHolding();clearInterval(pollTimer);
      remoteQueue.splice(0).forEach(task=>task.resolve());$('appRoot').inert=true;
      $('updateDescription').textContent='Downloading and checking the confirmed version… Keep this page open.';$('updateError').textContent='';
      let installed=false;
      try {
        const result=await api('update_install',{ticket:updateInfo.ticket,version:updateInfo.latestVersion,confirmed:'1'});
        installed=true;$('updateTitle').textContent='Update installed';$('updateDescription').textContent='Version '+result.version+' is installed. Reloading…';
        setTimeout(()=>location.reload(),800);
      }catch(error){
        $('updateDescription').textContent='The update has not been installed.';
        if(error.authRequired){
          try{await requestUpdateAuth();}catch(authError){$('updateError').textContent=authError.message;}
        }else {$('updateError').textContent=error.message;$('retryUpdateButton').hidden=false;}
      }finally {
        if(!installed){updateInstalling=false;updateBusy(false);$('appRoot').inert=false;if(preferences.denonIp)startPolling();}
      }
    }
    function showModal(id,focusId) { stopHolding();$(id).hidden=false;if(focusId)$(focusId).focus(); }
    function hideModal(id) {
      if(id==='ipModal' && connectionSaving)return;
      if(id==='updateModal' && updateInstalling)return;
      if(id==='updateModal'){dismissedUpdateVersion=updateInfo && updateInfo.available?updateInfo.latestVersion:'';pendingUpdateOffer=false;}
      $(id).hidden=true;
    }
    function renderConnectionHelp() {
      const direct=$('connectionModeInput').value==='direct';
      $('directSettings').hidden=!direct;
      ['directSchemeInput','directPortInput','directReadbackInput'].forEach(id=>$(id).disabled=!direct);
      $('connectionHelp').textContent=direct?'This browser must reach the Denon LAN. The PHP host only serves the app and saves settings; it does not connect to the Denon.':'The PHP host must reach the Denon LAN, locally or through a VPN. Its existing HTTP command fallback remains available in All commands.';
      $('directPolicyHelp').textContent=location.protocol==='https:' && $('directSchemeInput').value==='http'
        ? 'HTTPS → HTTP may be blocked as mixed content. Supporting browsers can request Local Network Access permission. Other browsers may need the app served over HTTP on your LAN, or a trusted Denon HTTPS endpoint.'
        : 'Browser Local/Private Network Access policies still apply. HTTPS needs a certificate trusted by this browser. CORS readback requires the Denon to allow this app’s origin.';
    }
    function showConnectionSettings() {
      $('denonIpInput').value=preferences.denonIp || '';
      $('connectionModeInput').value=preferences.connectionMode || 'relay';
      $('directSchemeInput').value=preferences.directScheme || 'http';
      $('directPortInput').value=preferences.directPort || 80;
      $('directReadbackInput').value=preferences.directReadback?'1':'0';
      $('ipError').textContent='';renderConnectionHelp();showModal('ipModal','denonIpInput');
    }
    async function saveConnection(ev) {
      ev.preventDefault();if(connectionSaving)return;
      const button=ev.submitter || $('ipForm').querySelector('[type="submit"]');button.disabled=true;$('ipError').textContent='';
      connectionSaving=true;stopHolding();clearInterval(pollTimer);
      connectionEpoch++;remoteQueue.splice(0).forEach(task=>task.resolve());
      const data={ip:$('denonIpInput').value.trim(),connectionMode:$('connectionModeInput').value};
      if(data.connectionMode==='direct')Object.assign(data,{directScheme:$('directSchemeInput').value,directPort:$('directPortInput').value,directReadback:$('directReadbackInput').value});
      try {
        const result=await api('save_ip',data);preferences=result.preferences;
        liveState={power:'unknown',mute:null,volumeNumber:null,display:[],sourceRaw:''};
        connectionSaving=false;hideModal('ipModal');setLog('Connection saved: '+(isDirectMode()?'Browser direct':'Server relay')+' · '+result.ip);startPolling(true);
      } catch(error){$('ipError').textContent=error.message;connectionSaving=false;startPolling();}
      finally {button.disabled=false;}
    }
    $('checkUpdatesButton').addEventListener('click',()=>checkForUpdates(true));
    $('retryUpdateButton').addEventListener('click',()=>checkForUpdates(true));
    $('installUpdateButton').addEventListener('click',installUpdate);
    $('connectionButton').addEventListener('click',showConnectionSettings);
    $('connectionModeInput').addEventListener('change',renderConnectionHelp);
    $('directSchemeInput').addEventListener('change',()=>{
      if(['80','443'].includes($('directPortInput').value))$('directPortInput').value=$('directSchemeInput').value==='https'?'443':'80';
      renderConnectionHelp();
    });
    $('themesButton').addEventListener('click',()=>{renderThemeGallery();showModal('themesModal');});
    $('editorButton').addEventListener('click',requestEditor);
    $('miniViewButton').addEventListener('click',()=>saveSelection({view:'mini'}));$('fullViewButton').addEventListener('click',()=>saveSelection({view:'full'}));
    document.querySelectorAll('[data-close]').forEach(btn=>btn.addEventListener('click',()=>hideModal(btn.dataset.close)));
    document.querySelectorAll('.overlay').forEach(overlay=>overlay.addEventListener('pointerdown',ev=>{if(ev.target===overlay)hideModal(overlay.id);}));
    $('ipForm').addEventListener('submit',saveConnection);
    $('authForm').addEventListener('submit',async ev=>{
      ev.preventDefault();$('authError').textContent='';const password=$('adminPassword').value;
      if(setupRequired && password!==$('confirmAdminPassword').value){$('authError').textContent='The passwords do not match.';return;}
      $('authSubmit').disabled=true;
      try{const result=await api(setupRequired?'editor_setup':'editor_login',{password});authenticated=true;setupRequired=false;if(!resumeEditorDraft)preferences=result.preferences;$('authForm').reset();hideModal('authModal');if(authPurpose==='update'){await installUpdate();}else if(resumeEditorDraft){resumeEditorDraft=false;notice('Editor unlocked. Your draft is still available.');}else openEditor();}
      catch(error){$('authError').textContent=error.message;}finally{$('authSubmit').disabled=false;}
    });
    $('passwordForm').addEventListener('submit',async ev=>{
      ev.preventDefault();$('passwordError').textContent='';if($('newPassword').value!==$('confirmNewPassword').value){$('passwordError').textContent='The new passwords do not match.';return;}
      const submit=$('passwordForm').querySelector('[type="submit"]');submit.disabled=true;
      try{await api('editor_password',{currentPassword:$('currentPassword').value,newPassword:$('newPassword').value});$('passwordForm').reset();hideModal('passwordModal');notice('Admin password changed. Other editor sessions are now locked.');}
      catch(error){$('passwordError').textContent=error.message;}finally{submit.disabled=false;}
    });
    $('saveThemeButton').addEventListener('click',saveTheme);$('closeEditorButton').addEventListener('click',()=>closeEditor());
    $('undoButton').addEventListener('click',()=>historyAction());$('redoButton').addEventListener('click',()=>historyAction(true));
    $('editMiniButton').addEventListener('click',()=>switchEditView('mini'));$('editFullButton').addEventListener('click',()=>switchEditView('full'));
    function switchEditView(view) { endCanvasPointer();editView=view;selectedId=null;renderEditorCanvas();renderThemeProperties();renderItemProperties();renderLayers(); }
    $('editorZoom').addEventListener('change',sizeEditorCanvas);$('snapGrid').addEventListener('change',()=>{$('editorCanvas').classList.toggle('grid-on',$('snapGrid').checked && !previewMode);});
    $('paletteSearch').addEventListener('input',renderPalette);
    $('paletteTab').addEventListener('click',()=>{$('palettePanel').hidden=false;$('layersPanel').hidden=true;$('paletteTab').classList.add('active');$('layersTab').classList.remove('active');});
    $('layersTab').addEventListener('click',()=>{$('palettePanel').hidden=true;$('layersPanel').hidden=false;$('paletteTab').classList.remove('active');$('layersTab').classList.add('active');renderLayers();});
    $('themePropertiesTab').addEventListener('click',()=>showPropertyTab('theme'));$('itemPropertiesTab').addEventListener('click',()=>{renderItemProperties();showPropertyTab('item');});
    document.querySelectorAll('[data-mobile-panel]').forEach(button=>{if(button.tagName==='BUTTON')button.addEventListener('click',()=>{$('editorWorkspace').dataset.mobilePanel=button.dataset.mobilePanel;sizeEditorCanvas();});});
    $('editorCanvas').addEventListener('pointerdown',ev=>{if(ev.target===$('editorCanvas') && !previewMode){selectNode(null);showPropertyTab('theme');}});
    $('editorCanvas').addEventListener('dragover',ev=>{if(previewMode)return;if(paletteDragging!==null){ev.preventDefault();ev.dataTransfer.dropEffect='copy';}});
    $('editorCanvas').addEventListener('drop',ev=>{
      ev.preventDefault();if(previewMode || paletteDragging===null)return;
      const rect=$('editorCanvas').getBoundingClientRect();const index=paletteDragging;paletteDragging=null;
      addPaletteItem(index,{x:(ev.clientX-rect.left)/editorScale,y:(ev.clientY-rect.top)/editorScale});
    });
    document.addEventListener('pointermove',moveCanvasPointer);document.addEventListener('pointerup',ev=>{endCanvasPointer(ev);stopHolding();});
    document.addEventListener('pointercancel',ev=>{endCanvasPointer(ev);stopHolding();});
    window.addEventListener('blur',()=>{endCanvasPointer();stopHolding();});document.addEventListener('visibilitychange',()=>{if(document.hidden){endCanvasPointer();stopHolding();}});
    $('previewButton').addEventListener('click',()=>{
      endCanvasPointer();previewMode=!previewMode;$('editorShell').dataset.preview=previewMode?'1':'0';$('previewButton').textContent=previewMode?'Back to editing':'Preview';
      renderEditorCanvas();updateDraftStatus();setEditorMessage(previewMode?'Visual preview · Device commands stay paused.':'Drag to move · Handles to resize · Right-click for properties');
    });
    $('itemContextMenu').querySelectorAll('[data-context]').forEach(button=>button.addEventListener('click',()=>itemAction(button.dataset.context)));
    $('editorMoreButton').addEventListener('click',ev=>{ev.stopPropagation();const rect=ev.currentTarget.getBoundingClientRect();placeMenu($('editorMoreMenu'),rect.left,rect.bottom+6);});
    document.addEventListener('click',ev=>{if(!ev.target.closest('.context-menu') && !ev.target.closest('#editorMoreButton'))closeMenus();});
    $('newThemeButton').addEventListener('click',()=>{closeMenus();mutate(()=>{draft.id=newId('custom');draft.name+=' Copy';},true);});
    $('reloadPrefsButton').addEventListener('click',async()=>{
      closeMenus();try{const result=await api('editor_state');preferences=result.preferences;renderThemeProperties();setEditorMessage('Saved preferences reloaded. Your draft is still available.');notice('Preferences reloaded.');}catch(error){notice(error.message);}
    });
    $('exportThemeButton').addEventListener('click',()=>{
      closeMenus();const theme=clone(draft);if(!theme.id.startsWith('custom-'))theme.id=newId('custom');
      const url=URL.createObjectURL(new Blob([JSON.stringify(theme,null,2)],{type:'application/json'}));const link=document.createElement('a');link.href=url;
      link.download=theme.name.replace(/[^a-z0-9_-]+/gi,'-').replace(/^-|-$/g,'')+'.json';document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
    });
    $('importThemeButton').addEventListener('click',()=>{closeMenus();if(!previewMode)$('importThemeInput').click();});
    $('importThemeInput').addEventListener('change',async()=>{
      const file=$('importThemeInput').files[0];if(!file)return;
      try{
        if(file.size>1572864)throw new Error('Choose a theme JSON smaller than 1.5MB.');
        const theme=JSON.parse(await file.text());theme.id=newId('custom');
        const result=await api('skin_validate',{theme:JSON.stringify(theme)});
        if(dirty() && !confirm('Discard this draft and open the imported theme?'))return;
        openDraft(result.theme);draftBase='';updateDraftStatus();notice('Theme imported. Save to keep it.');
      }catch(error){notice(error.message);}finally{$('importThemeInput').value='';}
    });
    $('deleteThemeButton').addEventListener('click',async()=>{
      closeMenus();if(!draft.id.startsWith('custom-') || !preferences.customThemes.some(t=>t.id===draft.id)){notice('Only saved custom themes can be deleted.');return;}
      if(!confirm('Delete the saved theme “'+draft.name+'”?'))return;
      try{const result=await api('skin_delete',{themeId:draft.id,revision:preferences.revision});preferences=result.preferences;applyTheme(findTheme(preferences.activeTheme));openDraft(activeTheme);notice('Custom theme deleted.');}catch(error){notice(error.message);}
    });
    $('changePasswordButton').addEventListener('click',()=>{closeMenus();$('passwordForm').reset();$('passwordError').textContent='';showModal('passwordModal','currentPassword');});
    $('lockEditorButton').addEventListener('click',async()=>{
      closeMenus();if(!closeEditor())return;try{await api('editor_logout');authenticated=false;notice('Editor locked.');}catch(error){notice(error.message);}
    });
    document.addEventListener('keydown',ev=>{
      if(ev.key==='Escape'){
        if(!$('itemContextMenu').hidden || !$('editorMoreMenu').hidden){closeMenus();return;}
        const visible=Array.from(document.querySelectorAll('.overlay')).filter(el=>!el.hidden).pop();
        if(visible){hideModal(visible.id);return;}if(editorOpen)closeEditor();return;
      }
      const typing=ev.target && (['INPUT','TEXTAREA','SELECT'].includes(ev.target.tagName) || ev.target.isContentEditable);
      if(editorOpen){
        if((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase()==='s'){ev.preventDefault();saveTheme();return;}
        if(typing || previewMode)return;
        if((ev.ctrlKey || ev.metaKey) && ['z','y'].includes(ev.key.toLowerCase())){ev.preventDefault();historyAction(ev.shiftKey || ev.key.toLowerCase()==='y');return;}
        if((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase()==='d'){ev.preventDefault();itemAction('duplicate');return;}
        if(ev.key==='Delete' || ev.key==='Backspace'){ev.preventDefault();itemAction('delete');return;}
        const offsets={ArrowLeft:[-1,0],ArrowRight:[1,0],ArrowUp:[0,-1],ArrowDown:[0,1]};
        if(offsets[ev.key] && selectedNode()){ev.preventDefault();const item=selectedNode(),step=ev.shiftKey?10:1;mutate(()=>{item.x=clamp(item.x+offsets[ev.key][0]*step,0,currentLayout().width-item.w);item.y=clamp(item.y+offsets[ev.key][1]*step,0,currentLayout().height-item.h);});renderItemProperties();}return;
      }
      if(typing || !Array.from(document.querySelectorAll('.overlay')).every(el=>el.hidden))return;
      if(ev.key==='+' || ev.key==='=')runAction('MVUP');if(ev.key==='-')runAction('MVDOWN');if(ev.key.toLowerCase()==='m')runAction('toggle_mute');if(ev.key.toLowerCase()==='r')refreshStatus(true);
    });
    window.addEventListener('beforeunload',ev=>{if(editorOpen && dirty()){ev.preventDefault();ev.returnValue='';}});
    window.addEventListener('resize',()=>{sizeRuntime();sizeEditorCanvas();});
    new ResizeObserver(()=>{sizeRuntime();if(editorOpen)sizeEditorCanvas();}).observe($('runtimeStage'));
    renderPalette();applyTheme(activeTheme);updateConnectionInfo();setLog('Ready.');
    if(bootData.storageError)notice(bootData.storageError);
    if(new URLSearchParams(location.search).get('connection')==='direct') {
      // A legacy bookmark suggests direct mode; saving is still explicit.
      showConnectionSettings();$('connectionModeInput').value='direct';renderConnectionHelp();
      if(!preferences.denonIp) {
        try {
          const saved=new URL(localStorage.getItem('denon_ceol_base_url'));
          if(['http:','https:'].includes(saved.protocol)) {
            $('denonIpInput').value=saved.hostname;$('directSchemeInput').value=saved.protocol.slice(0,-1);
            $('directPortInput').value=saved.port || (saved.protocol==='https:'?'443':'80');renderConnectionHelp();
          }
        } catch(error) { /* No usable legacy target. */ }
      }
    } else if(preferences.denonIp)startPolling();else showConnectionSettings();
    setTimeout(()=>checkForUpdates(),1500);
    setInterval(()=>{if(!document.hidden)checkForUpdates();},6*60*60*1000);
    setInterval(offerPendingUpdate,2000);
  </script>
</body>
</html>
