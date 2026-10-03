<?php
declare(strict_types=1);

// Load the real single-file app with isolated legacy preferences. No Denon I/O.
$testRoot = sys_get_temp_dir() . '/ceol-test-' . bin2hex(random_bytes(8));
mkdir($testRoot, 0700);
register_shutdown_function(function () use ($testRoot) {
  foreach (glob($testRoot . '/*') as $file) unlink($file);
  rmdir($testRoot);
});
session_save_path($testRoot);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS'] = 'off';
$source = file_get_contents(__DIR__ . '/../index.php');
file_put_contents($testRoot . '/index.php', $source);
$legacy = array('schema' => 1, 'revision' => 7, 'denonIp' => '192.168.50.182',
  'activeTheme' => 'midnight', 'activeView' => 'full', 'useHttpFallback' => true,
  'customThemes' => array(array('id' => 'custom-kept', 'name' => 'Saved theme')));
file_put_contents($testRoot . '/denon-ceol-preferences.json', json_encode($legacy));
ob_start();
require $testRoot . '/index.php';
$html = ob_get_clean();
preg_match('/const bootData = (.+);/', $html, $match);
$boot = json_decode($match[1], true);
$checks = 0;
function check(bool $condition, string $label): void {
  global $checks;
  if (!$condition) throw new RuntimeException('FAIL: ' . $label);
  $checks++;
}

check(APP_VERSION === '8.2.0' && ceol_source_version($source) === APP_VERSION, 'updater recognizes bumped version');
check($boot['preferences']['connectionMode'] === 'relay', 'legacy installs default to relay');
check($boot['preferences']['directScheme'] === 'http' && $boot['preferences']['directPort'] === 80
  && !$boot['preferences']['directReadback'], 'safe direct defaults');
foreach ($legacy as $key => $value) check($boot['preferences'][$key] === $value, 'legacy ' . $key . ' preserved');
check(ceol_connection_settings(array(), $boot['preferences']) === $boot['preferences'], 'omitted fields preserved');
$updated = ceol_update_preferences(function ($data) {
  return ceol_connection_settings(array('connectionMode' => 'direct', 'directScheme' => 'https',
    'directPort' => '8443', 'directReadback' => '1'), $data);
});
check($updated['connectionMode'] === 'direct' && $updated['directScheme'] === 'https'
  && $updated['directPort'] === 8443 && $updated['directReadback'], 'direct settings persisted');
check(ceol_preferences() === $updated, 'settings reload from the shared file');
check($updated['revision'] === 8 && $updated['customThemes'] === $legacy['customThemes']
  && $updated['activeTheme'] === 'midnight' && $updated['useHttpFallback'], 'saving transport preserves themes and relay option');
foreach (array(array('connectionMode' => 'auto'), array('connectionMode' => array('direct')),
  array('directScheme' => 'file'), array('directPort' => '0'), array('directPort' => '65536'),
  array('directPort' => '80.5'), array('directPort' => 'not-a-port'), array('directReadback' => 'yes')) as $invalid) {
  $blocked = false;
  try { ceol_connection_settings($invalid, $updated); } catch (RuntimeException $error) { $blocked = true; }
  check($blocked, 'invalid settings rejected: ' . json_encode($invalid));
}
$paths = array('PWON' => '/goform/formiPhoneAppPower.xml?1+PowerOn',
  'PWSTANDBY' => '/goform/formiPhoneAppPower.xml?1+PowerStandby',
  'MUON' => '/goform/formiPhoneAppMute.xml?1+MuteOn', 'MUOFF' => '/goform/formiPhoneAppMute.xml?1+MuteOff',
  'SIANALOGIN' => '/goform/formiPhoneAppDirect.xml?SIANALOGIN', 'PW?' => '/goform/formiPhoneAppDirect.xml?PW%3F',
  'MV60' => '/goform/formiPhoneAppDirect.xml?MV60', 'FV50' => '/goform/formiPhoneAppDirect.xml?FV50',
  'TFAN105000' => '/goform/formiPhoneAppDirect.xml?TFAN105000');
foreach ($paths as $command => $path) check(denon_http_command_path($command) === $path, 'endpoint ' . $command);
foreach (allowed_fixed_commands() as $command) check($boot['httpCommandPaths'][$command] === denon_http_command_path($command), 'shared boot mapping ' . $command);
foreach (array('MV61', 'FV00', 'FV51', 'TFAN12345', 'PWON&x=1', "PWON\rMUOFF", 'toggle_power') as $command) {
  check(!is_allowed_denon_command($command), 'command rejected ' . $command);
  check(!denon_http_fallback_command('127.0.0.1', $command)['ok'], 'relay fallback rejects ' . $command);
}
check(validate_denon_ip('192.168.50.182')[0] && !validate_denon_ip('8.8.8.8')[0]
  && !validate_denon_ip('192.168.50.999')[0], 'IP restrictions retained');
check(strpos(file_get_contents(__DIR__ . '/../localwifi.php'), 'Location: index.php?connection=direct') !== false, 'legacy entrypoint uses shared UI');

if (isset($argv[1]) && $argv[1] === '--boot') echo json_encode($boot);
else echo 'PASS: ' . $checks . " PHP connection checks\n";
