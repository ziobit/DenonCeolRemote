<?php
declare(strict_types=1);

$testRoot = sys_get_temp_dir() . '/ceol-test-' . bin2hex(random_bytes(8));
mkdir($testRoot, 0700);
register_shutdown_function(function () use ($testRoot) {
  foreach (glob($testRoot . '/*') as $file) unlink($file);
  rmdir($testRoot);
});
copy(__DIR__ . '/../index.php', $testRoot . '/index.php');
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
require $testRoot . '/index.php';
$html = ob_get_clean();

$checks = 0;
function check(bool $condition, string $label): void {
  global $checks;
  if (!$condition) throw new RuntimeException('FAIL: ' . $label);
  $checks++;
}

check(APP_VERSION === 'micro-remote-v7-2026-06-19', 'application version');
check(current_denon_ip() === DEFAULT_DENON_IP, 'default IP when no server file exists');
check(strpos($html, DEFAULT_DENON_IP) !== false, 'default IP appears in rendered page');
check(validate_denon_ip('192.168.50.182')[0] && !validate_denon_ip('8.8.8.8')[0]
  && !validate_denon_ip('192.168.0.999')[0], 'private IPv4 validation');
file_put_contents(DENON_IP_FILE, "192.168.50.182\n");
check(current_denon_ip() === '192.168.50.182', 'shared server IP is read');
file_put_contents(DENON_IP_FILE, "not-an-ip\n");
check(current_denon_ip() === DEFAULT_DENON_IP, 'invalid server IP falls back to default');

foreach (array('PWON', 'PWSTANDBY', 'PW?', 'MV00', 'MV60', 'FV01', 'FV50', 'TFAN105000', 'NS9D', 'NS9E') as $command) {
  check(is_allowed_denon_command($command), 'allowed command ' . $command);
}
foreach (array('MV61', 'FV00', 'FV51', 'TFAN12345', 'PWON&x=1', "PWON\rMUOFF", 'arbitrary') as $command) {
  check(!is_allowed_denon_command($command), 'rejected command ' . $command);
}

$source = file_get_contents(__DIR__ . '/../index.php');
check(strpos($source, 'session_start(') === false, 'PHP sessions are not used');
check(strpos($source, "const PAGE_ID = Array.from(crypto.getRandomValues(new Uint8Array(16))") !== false,
  'each page creates a random identifier');
check(strpos($source, 'claim_session($page)') !== false, 'Denon requests claim the single control session');
check(strpos($html, 'data-goto="tools"') !== false && strpos($html, 'color-scheme: light') !== false,
  'phone tabs and light theme render');

echo 'PASS: ' . $checks . " PHP connection checks\n";
