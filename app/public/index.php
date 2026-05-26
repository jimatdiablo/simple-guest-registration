<?php

require_once __DIR__ . '/../src/bootstrap.php';
session_start();

$action = $_GET['action'] ?? 'home';
$flash = null;
$error = null;
$showClearRegistrationsConfirm = false;
$showFactoryResetConfirm = false;
$showServiceGroupResyncPrompt = false;
$serviceGroupResyncPromptContext = '';
$registrationCountForClear = 0;
$pendingDeleteUserId = 0;
$pendingVoidGuestId = 0;
$showVacantAuditResults = false;
$vacantAuditRowsBySg = [];
$vacantAuditTotals = [
  'total_vacant' => 0,
  'already_target' => 0,
  'needs_change' => 0,
  'config_missing' => 0,
];
$vacantAuditRefreshedAt = '';
$vacantAuditSource = '';
$openVacantProfileCard = false;
$openVacantSettingsSection = false;
$openVacantPreviewSection = false;
$showVacantApplyConfirm = false;
$vacantApplyPendingRows = [];
$showVacantApplyResults = false;
$vacantApplyRunResults = [];
$vacantApplySummary = [
  'attempted' => 0,
  'updated' => 0,
  'failed' => 0,
];
$registrationConfirmation = null;
$regeneratedGuestAccessInfo = null;
$colorPresetOptions = [
  'forest' => 'Forest',
  'ocean' => 'Ocean',
  'slate' => 'Slate',
  'sunset' => 'Sunset',
  'canyon' => 'Canyon',
  'tropic' => 'Tropic',
  'ember' => 'Ember',
  'volt' => 'Volt',
  'citrus' => 'Citrus',
];
$selectedColorPreset = 'forest';
$currentUser = $_SESSION['auth_user'] ?? null;
$isLoggedIn = is_array($currentUser) && isset($currentUser['username'], $currentUser['role']);
$userRole = $isLoggedIn ? (string) $currentUser['role'] : 'guest';
$isPropertyUser = in_array($userRole, ['staff', 'admin', 'master_admin'], true);
$isAdmin = in_array($userRole, ['admin', 'master_admin'], true);
$isMasterAdmin = $userRole === 'master_admin';

if ($action === 'health') {
  header('Content-Type: application/json');
  header('Cache-Control: no-store, no-cache, must-revalidate');
  echo json_encode([
    'ok' => true,
    'app' => $config->get('APP_NAME', 'Simple Guest Registration'),
    'version' => $config->get('APP_VERSION', 'dev'),
    'time' => date(DATE_ATOM),
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

if ($action === 'logout') {
  $_SESSION = [];
  session_destroy();
  header('Location: /');
  exit;
}

if ($action === 'guest_access_logout') {
  unset($_SESSION['guest_access_guest_id']);
  header('Location: /');
  exit;
}

if ($action === 'captive_portal_api') {
  $portalUrl = rtrim((string) $config->get('APP_URL', 'http://192.168.160.4'), '/') . '/';
  $payload = [
    'captive' => true,
    'user-portal-url' => $portalUrl,
    'venue-info-url' => $portalUrl,
  ];
  $responseJson = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  try {
    $guests->addCaptivePortalApiLog([
      'client_ip' => detectClientIpAddress(),
      'host_header' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
      'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
      'request_method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
      'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
      'response_json' => $responseJson,
    ]);
  } catch (Throwable $e) {
    // Non-blocking log write.
  }
  header('Content-Type: application/captive+json');
  header('Cache-Control: no-store, no-cache, must-revalidate');
  echo $responseJson;
  exit;
}

if ($action === 'captive_probe') {
  $portalUrl = rtrim((string) $config->get('APP_URL', 'http://192.168.160.4'), '/') . '/';
  $probe = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_GET['probe'] ?? 'legacy')));

  if ($probe === 'wpad') {
    $pac = "function FindProxyForURL(url, host) {\n  return \"DIRECT\";\n}\n";
    try {
      $guests->addCaptivePortalApiLog([
        'client_ip' => detectClientIpAddress(),
        'host_header' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
        'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'request_method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        'response_json' => (string) json_encode([
          'type' => 'legacy_probe',
          'probe' => $probe,
          'response' => 'direct_pac',
        ], JSON_UNESCAPED_SLASHES),
      ]);
    } catch (Throwable $e) {
      // Non-blocking log write.
    }
    header('Content-Type: application/x-ns-proxy-autoconfig');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $pac;
    exit;
  }

  try {
    $guests->addCaptivePortalApiLog([
      'client_ip' => detectClientIpAddress(),
      'host_header' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
      'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
      'request_method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
      'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
      'response_json' => (string) json_encode([
        'type' => 'legacy_probe',
        'probe' => $probe,
        'response' => 'redirect',
        'location' => $portalUrl,
      ], JSON_UNESCAPED_SLASHES),
    ]);
  } catch (Throwable $e) {
    // Non-blocking log write.
  }
  header('Cache-Control: no-store, no-cache, must-revalidate');
  header('Location: ' . $portalUrl, true, 302);
  exit;
}

function parseServiceGroupList(string $raw, array $default): array
{
  $trimmed = trim($raw);
  if ($trimmed === '') {
    return $default;
  }

  $parts = array_filter(array_map('trim', explode(',', $trimmed)), static fn ($v) => $v !== '');
  $groups = array_values(array_unique(array_map('intval', $parts)));
  return $groups === [] ? $default : $groups;
}

function profileMatchesServiceGroup(string $profile, int $serviceGroup): bool
{
  $profile = trim($profile);
  if ($profile === '' || strlen($profile) < 2) {
    return false;
  }

  $requiredPrefix = str_pad((string) $serviceGroup, 2, '0', STR_PAD_LEFT);
  return substr($profile, 0, 2) === $requiredPrefix;
}

function defaultProfileFallbackForServiceGroup(int $serviceGroup, string $envDefault): string
{
  if (profileMatchesServiceGroup($envDefault, $serviceGroup)) {
    return $envDefault;
  }

  return str_pad((string) $serviceGroup, 2, '0', STR_PAD_LEFT) . 'baseservice';
}

function serviceGroupDisplayLabel(int $serviceGroup, array $namesBySg): string
{
  $name = trim((string) ($namesBySg[$serviceGroup] ?? ''));
  if ($name === '') {
    return 'Service Group ' . $serviceGroup;
  }

  return $name . ' (SG ' . $serviceGroup . ')';
}

function stripReprovisionNotes(string $notes): string
{
  $parts = array_map('trim', explode(' | ', $notes));
  $hasReprovisionMarker = false;
  foreach ($parts as $p) {
    if (strpos($p, '[Reprovision ') === 0) {
      $hasReprovisionMarker = true;
      break;
    }
  }

  $isLegacyReprovisionDetail = static function (string $part): bool {
    return strpos($part, 'SNMP reboot failed or skipped') === 0
      || strpos($part, 'Profile update failed:') === 0
      || strpos($part, 'DDNet lease lookup failed:') === 0
      || $part === 'Reprovision completed successfully.';
  };

  $kept = [];
  $skipDetailsAfterMarker = false;
  foreach ($parts as $part) {
    if ($part === '') {
      continue;
    }

    if (strpos($part, '[Reprovision ') === 0) {
      $skipDetailsAfterMarker = true;
      continue;
    }

    if ($skipDetailsAfterMarker && $isLegacyReprovisionDetail($part)) {
      continue;
    }

    $skipDetailsAfterMarker = false;

    // Older runs may already have had marker tokens removed, leaving orphan detail fragments.
    if ($hasReprovisionMarker && $isLegacyReprovisionDetail($part)) {
      continue;
    }

    $kept[] = $part;
  }

  return implode(' | ', $kept);
}

function buildSnmpFailureNote(array $snmpResult): string
{
  $detail = trim((string) ($snmpResult['error'] ?? ''));
  if ($detail === '') {
    $detail = 'unknown';
  }

  return 'SNMP reboot failed or skipped: ' . $detail;
}

function buildRebootIpCandidates(array $values): array
{
  $candidates = [];
  foreach ($values as $value) {
    $ip = trim((string) $value);
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
      continue;
    }
    $candidates[$ip] = $ip;
  }

  return array_values($candidates);
}

function removeModemScopedReservationForGuest(array $guest, $ddnet, $guests = null, string $actor = 'system'): array
{
  $guestId = (int) ($guest['id'] ?? 0);
  $guestName = (string) ($guest['guest_name'] ?? '');
  $unit = (string) ($guest['unit'] ?? '');
  $sg = (int) ($guest['sg'] ?? 0);
  $modemMac = strtolower(trim((string) ($guest['modem_mac'] ?? '')));
  $clientIp = (string) ($guest['dhcp_ip'] ?? '');

  if ($modemMac === '') {
    return ['ok' => false, 'error' => 'Unable to determine modem MAC for modem-scoped reservation cleanup.'];
  }

  $reservationResult = method_exists($ddnet, 'reservationDelete')
    ? $ddnet->reservationDelete($modemMac)
    : ['ok' => true, 'path' => null];
  $scopedResult = $ddnet->modemScopedReservationsDelete($modemMac);
  $ok = (($reservationResult['ok'] ?? false) === true) && (($scopedResult['ok'] ?? false) === true);

  $detailsParts = [];
  if (($reservationResult['ok'] ?? false) === true) {
    $detailsParts[] = ((bool) ($reservationResult['not_found'] ?? false))
      ? 'DDNet reservation was already absent via /reservations/{macAddress}'
      : 'Removed DDNet reservation via ' . (string) ($reservationResult['path'] ?? '/reservations/{macAddress}');
  } else {
    $detailsParts[] = 'Failed to remove DDNet reservation: ' . (string) ($reservationResult['error'] ?? 'unknown');
  }
  $detailsParts[] = (($scopedResult['ok'] ?? false) === true)
    ? ('Removed modem-scoped reservation via ' . (string) ($scopedResult['path'] ?? 'DDNet endpoint'))
    : ('Failed to remove modem-scoped reservation: ' . (string) ($scopedResult['error'] ?? 'unknown'));
  $details = implode(' | ', $detailsParts);

  if ($guests !== null && method_exists($guests, 'addModemScopedReservationLog')) {
    try {
      $guests->addModemScopedReservationLog([
        'guest_id' => $guestId,
        'guest_name' => $guestName,
        'unit' => $unit,
        'sg' => $sg,
        'modem_mac' => $modemMac,
        'client_ip' => $clientIp,
        'status' => $ok ? 'removed' : 'remove_failed',
        'details' => $details,
        'actor' => $actor,
      ]);
    } catch (Throwable $e) {
      // Non-blocking log write.
    }
  }

  return [
    'ok' => $ok,
    'error' => $ok ? null : $details,
    'details' => $details,
    'path' => $scopedResult['path'] ?? null,
  ];
}

function detectClientIpAddress(): string
{
  return trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
}

function readLogTailLines(string $filePath, int $maxLines = 200): array
{
  $path = trim($filePath);
  if ($path === '' || !is_file($path) || !is_readable($path)) {
    return [];
  }

  $lines = @file($path, FILE_IGNORE_NEW_LINES);
  if (!is_array($lines) || $lines === []) {
    return [];
  }

  $max = max(1, min(1000, $maxLines));
  return array_values(array_reverse(array_slice($lines, -1 * $max)));
}

function clearFileLogs(array $paths): array
{
  $cleared = [];
  $seen = [];

  foreach ($paths as $path) {
    $path = trim((string) $path);
    if ($path === '') {
      continue;
    }

    $realPath = realpath($path);
    if ($realPath === false || isset($seen[$realPath]) || !is_file($realPath) || !is_writable($realPath)) {
      continue;
    }

    if (@file_put_contents($realPath, '') !== false) {
      $seen[$realPath] = true;
      $cleared[] = basename($realPath);
    }
  }

  return $cleared;
}

function discoverStorageLogFiles(string $configuredSnmpAuditPath): array
{
  $paths = [];
  $logDir = realpath(__DIR__ . '/../storage/logs');

  if ($logDir !== false && is_dir($logDir)) {
    $files = glob($logDir . DIRECTORY_SEPARATOR . '*');
    if (is_array($files)) {
      foreach ($files as $file) {
        if (is_file($file)) {
          $paths[] = $file;
        }
      }
    }
  }

  $paths[] = $configuredSnmpAuditPath;
  return $paths;
}

function syncLeaseStatusesFromRows(array $rows, array $serviceGroups, $ddnet, $modems): int
{
  if ($rows === [] || $serviceGroups === []) {
    return 0;
  }

  $allowedSgs = array_map('intval', $serviceGroups);
  $seenMacs = [];
  $leaseChecks = 0;

  foreach ($rows as $row) {
    $rowSg = (int) ($row['sg'] ?? 0);
    if (!in_array($rowSg, $allowedSgs, true)) {
      continue;
    }

    $mac = strtolower(trim((string) ($row['mac'] ?? '')));
    if ($mac === '' || isset($seenMacs[$mac])) {
      continue;
    }
    $seenMacs[$mac] = true;

    $leaseResult = $ddnet->lookupLeaseByMac($mac);
    $leaseIp = trim((string) ($leaseResult['ip'] ?? ''));
    $leaseOk = (($leaseResult['ok'] ?? false) === true) && $leaseIp !== '';
    $leaseError = trim((string) ($leaseResult['error'] ?? ''));
    if (!$leaseOk && $leaseError === '') {
      $leaseError = 'No active lease returned by DDNet.';
    }

    $modems->setLeaseStatusByMac($mac, $leaseOk, $leaseOk ? $leaseIp : null, $leaseOk ? null : $leaseError);
    $leaseChecks++;
  }

  return $leaseChecks;
}

function runVacantCheckoutTransition(
  array $guest,
  array $vacantProfileOverridesBySg,
  $modems,
  $gunslinger,
  $ddnet,
  $snmp,
  $guests = null,
  string $actor = 'system'
): array {
  $sg = (int) ($guest['sg'] ?? 0);
  $unit = trim((string) ($guest['unit'] ?? ''));
  $guestMac = strtolower(trim((string) ($guest['modem_mac'] ?? '')));

  if ($sg <= 0 || $unit === '') {
    return ['ok' => false, 'error' => 'Guest service group or lot is missing.'];
  }

  $modemRow = $modems->findByUnitAndServiceGroup($unit, $sg);
  $modemMac = strtolower(trim((string) ($modemRow['mac'] ?? '')));
  $targetMac = $guestMac !== '' ? $guestMac : $modemMac;
  if ($targetMac === '') {
    return ['ok' => false, 'error' => 'Unable to determine modem MAC for checkout.'];
  }

  $vacantProfile = trim((string) ($vacantProfileOverridesBySg[$sg] ?? sprintf('%02dvacant', $sg)));
  if ($vacantProfile === '' || !profileMatchesServiceGroup($vacantProfile, $sg)) {
    return ['ok' => false, 'error' => 'Vacant profile is missing or invalid for service group.'];
  }

  $profileResult = $gunslinger->updateProfile($sg, $unit, $targetMac, $vacantProfile);
  if (!($profileResult['ok'] ?? false)) {
    if ($guests !== null && method_exists($guests, 'addVacantProfileLog')) {
      $detailParts = [];
      if (isset($profileResult['status'])) {
        $detailParts[] = 'HTTP ' . (int) $profileResult['status'];
      }
      if (isset($profileResult['endpoint'])) {
        $detailParts[] = 'endpoint=' . (string) $profileResult['endpoint'];
      }
      $detailParts[] = (string) ($profileResult['error'] ?? 'unknown');
      $guests->addVacantProfileLog([
        'sg' => $sg,
        'unit' => $unit,
        'modem_mac' => $targetMac,
        'old_profile' => (string) ($guest['profile_applied'] ?? ''),
        'target_profile' => $vacantProfile,
        'status' => 'failed',
        'details' => implode(' | ', array_filter($detailParts)),
        'actor' => $actor,
      ]);
    }
    return [
      'ok' => false,
      'error' => 'Failed to apply vacant profile: ' . (string) ($profileResult['error'] ?? 'unknown'),
    ];
  }

  if ($guests !== null && method_exists($guests, 'addVacantProfileLog')) {
    $detailParts = [];
    if (isset($profileResult['status'])) {
      $detailParts[] = 'HTTP ' . (int) $profileResult['status'];
    }
    if (isset($profileResult['endpoint'])) {
      $detailParts[] = 'endpoint=' . (string) $profileResult['endpoint'];
    }
    if (array_key_exists('response', $profileResult)) {
      $encoded = json_encode($profileResult['response'], JSON_UNESCAPED_SLASHES);
      if (is_string($encoded) && $encoded !== 'null') {
        $detailParts[] = 'response=' . substr($encoded, 0, 500);
      }
    }
    $guests->addVacantProfileLog([
      'sg' => $sg,
      'unit' => $unit,
      'modem_mac' => $targetMac,
      'old_profile' => (string) ($guest['profile_applied'] ?? ''),
      'target_profile' => $vacantProfile,
      'status' => 'updated',
      'details' => implode(' | ', array_filter($detailParts)),
      'actor' => $actor,
    ]);
  }

  $notes = [
    sprintf('[Checkout %s] Vacant profile applied (%s).', date('Y-m-d H:i:s'), $vacantProfile),
  ];

  $cleanupGuest = $guest;
  $cleanupGuest['modem_mac'] = $targetMac;
  $cleanupResult = removeModemScopedReservationForGuest($cleanupGuest, $ddnet, $guests, $actor);
  if (!($cleanupResult['ok'] ?? false)) {
    return [
      'ok' => false,
      'error' => 'Failed to remove modem-scoped reservation during checkout: ' . (string) ($cleanupResult['error'] ?? 'unknown'),
    ];
  }
  $notes[] = (string) ($cleanupResult['details'] ?? 'Removed modem-scoped reservation.');

  $rebootIpCandidates = buildRebootIpCandidates([
    (string) ($guest['dhcp_ip'] ?? ''),
    (string) ($modemRow['lease_ip'] ?? ''),
  ]);
  if ($rebootIpCandidates !== []) {
    $snmpResult = $snmp->rebootWithCandidates($rebootIpCandidates);
    if (!($snmpResult['ok'] ?? false)) {
      $notes[] = buildSnmpFailureNote((array) $snmpResult);
    }
  } else {
    $notes[] = 'No cached reboot IP candidates available during checkout.';
  }

  return [
    'ok' => true,
    'profile' => $vacantProfile,
    'modem_mac' => $targetMac,
    'notes' => $notes,
  ];
}

function parseProvisioningFailureReasons(string $notes): array
{
  $reasons = [];
  $parts = array_filter(array_map('trim', explode(' | ', $notes)), static fn ($v) => $v !== '');
  foreach ($parts as $part) {
    if (stripos($part, 'Gunslinger profile update failed') !== false) {
      $reasons['gunslinger'] = 'Gunslinger profile update failed';
      continue;
    }
    if (stripos($part, 'DDNet lease lookup failed') !== false) {
      $reasons['ddnet'] = 'DDNet lease lookup failed';
      continue;
    }
    if (stripos($part, 'SNMP reboot failed or skipped') !== false) {
      $reasons['snmp'] = 'SNMP reboot failed or skipped';
      continue;
    }
  }

  return array_values($reasons);
}

function renderActiveGuestNotesCell(array $row): string
{
  $notes = trim((string) ($row['notes'] ?? ''));
  if ($notes === '') {
    return '<span class="notes-summary notes-summary-muted">None</span>';
  }

  $status = (string) ($row['submission_status'] ?? '');
  $title = htmlspecialchars($notes, ENT_QUOTES, 'UTF-8');
  if ($status === 'partial_failure') {
    $issues = parseProvisioningFailureReasons($notes);
    if ($issues === []) {
      $issues = ['Provisioning issue'];
    }

    $html = '<div class="notes-summary-cell" title="' . $title . '">';
    $html .= '<span class="status-chip status-chip-alert notes-summary-chip">Action Needed</span>';
    foreach ($issues as $issue) {
      $html .= '<span class="notes-summary-reason">' . htmlspecialchars($issue) . '</span>';
    }
    $html .= '</div>';
    return $html;
  }

  $parts = array_values(array_filter(array_map('trim', explode(' | ', $notes)), static fn ($v) => $v !== ''));
  $latest = (string) ($parts !== [] ? end($parts) : $notes);
  if (strlen($latest) > 90) {
    $latest = substr($latest, 0, 87) . '...';
  }

  return '<div class="notes-summary-cell" title="' . $title . '">'
    . '<span class="notes-summary notes-summary-normal">Notes</span>'
    . '<span class="notes-summary-latest">' . htmlspecialchars($latest) . '</span>'
    . '</div>';
}

function renderSubmissionStatusText(string $status, bool $guestFacing = false): string
{
  $normalized = trim($status);
  if ($guestFacing) {
    if ($normalized === 'partial_failure') {
      return 'Setup issue under review';
    }
    if ($normalized === 'submitted') {
      return 'Active';
    }
  }

  return match ($normalized) {
    'partial_failure' => 'Action Needed',
    'submitted' => 'Submitted',
    'checked_out' => 'Checked Out',
    default => $normalized === '' ? 'Unknown' : $normalized,
  };
}

function renderActiveGuestStatusText(array $row): string
{
  $requestType = (string) ($row['latest_approved_request_type'] ?? '');
  $requestStatus = (string) ($row['latest_approved_request_status'] ?? '');

  if (in_array($requestStatus, ['approved', 'auto_approved'], true)) {
    return match ($requestType) {
      'extend_departure' => $requestStatus === 'auto_approved' ? 'Extension Auto-Approved' : 'Extension Approved',
      'early_checkout' => $requestStatus === 'auto_approved' ? 'Checkout Auto-Approved' : 'Checkout Approved',
      default => $requestStatus === 'auto_approved' ? 'Auto-Approved' : 'Approved',
    };
  }

  if ((string) ($row['submission_status'] ?? '') === 'submitted') {
    return 'Active';
  }

  return renderSubmissionStatusText((string) ($row['submission_status'] ?? ''));
}

function submissionStatusClass(string $status): string
{
  $normalized = trim($status);
  return match ($normalized) {
    'partial_failure' => 'status-chip status-chip-alert',
    'checked_out' => 'status-chip status-chip-muted',
    default => 'status-chip status-chip-normal',
  };
}

function activeGuestStatusClass(array $row): string
{
  $requestStatus = (string) ($row['latest_approved_request_status'] ?? '');
  if (in_array($requestStatus, ['approved', 'auto_approved'], true)) {
    return 'status-chip status-chip-success';
  }

  return submissionStatusClass((string) ($row['submission_status'] ?? ''));
}

function renderNetworkIdentityCell(string $ip, string $mac): string
{
  $ipValue = trim($ip);
  $macValue = strtolower(trim($mac));
  $ipDisplay = $ipValue === '' ? 'Not available' : htmlspecialchars($ipValue);
  $macDisplay = $macValue === '' ? 'Not available' : htmlspecialchars($macValue);

  $warningHtml = '';
  if ($ipValue !== '' && preg_match('/^10\.48\.6\.(\d{1,3})$/', $ipValue) === 1) {
    $warningHtml = '<span class="network-id-warning">Unexpected subnet (10.48.6.0/24)</span>';
  }

  return '<div class="network-id-cell">'
    . '<span class="network-id-ip">IP: ' . $ipDisplay . '</span>'
    . '<span class="network-id-mac">MAC: ' . $macDisplay . '</span>'
    . $warningHtml
    . '</div>';
}

function vacantPreviewRowKey(int $sg, string $unit, string $mac): string
{
  return $sg . '|' . trim($unit) . '|' . strtolower(trim($mac));
}

function stashVacantPreviewRowsInSession(array $rowsBySg): void
{
  $map = [];
  foreach ($rowsBySg as $sg => $rows) {
    foreach ((array) $rows as $row) {
      $rowSg = (int) ($row['sg'] ?? $sg ?? 0);
      $rowUnit = trim((string) ($row['unit'] ?? ''));
      $rowMac = strtolower(trim((string) ($row['mac'] ?? '')));
      $targetProfile = trim((string) ($row['target_profile'] ?? ''));
      if ($rowSg <= 0 || $rowUnit === '' || $rowMac === '' || $targetProfile === '') {
        continue;
      }

      $key = vacantPreviewRowKey($rowSg, $rowUnit, $rowMac);
      $map[$key] = [
        'sg' => $rowSg,
        'unit' => $rowUnit,
        'mac' => $rowMac,
        'current_profile' => trim((string) ($row['current_profile'] ?? '')),
        'target_profile' => $targetProfile,
      ];
    }
  }

  $_SESSION['vacant_preview_rows'] = $map;
}

function previewRowsFromSession(array $allowedServiceGroups): array
{
  $raw = $_SESSION['vacant_preview_rows'] ?? [];
  if (!is_array($raw)) {
    return [];
  }

  $allowed = array_fill_keys(array_map('intval', $allowedServiceGroups), true);
  $normalized = [];

  foreach ($raw as $row) {
    if (!is_array($row)) {
      continue;
    }

    $sg = (int) ($row['sg'] ?? 0);
    $unit = trim((string) ($row['unit'] ?? ''));
    $mac = strtolower(trim((string) ($row['mac'] ?? '')));
    $currentProfile = trim((string) ($row['current_profile'] ?? ''));
    $targetProfile = trim((string) ($row['target_profile'] ?? ''));

    if ($sg <= 0 || !isset($allowed[$sg]) || $unit === '' || $mac === '' || $targetProfile === '') {
      continue;
    }

    $normalized[vacantPreviewRowKey($sg, $unit, $mac)] = [
      'sg' => $sg,
      'unit' => $unit,
      'mac' => $mac,
      'current_profile' => $currentProfile,
      'target_profile' => $targetProfile,
    ];
  }

  return $normalized;
}

function hasValidReprovisionToken(string $submittedToken): bool
{
  return hash_equals((string) ($_SESSION['reprovision_form_token'] ?? ''), $submittedToken);
}

function detectAdminDeviceClassFromUserAgent(string $userAgent): string
{
  $ua = strtolower(trim($userAgent));
  if ($ua === '') {
    return 'desktop';
  }

  if (preg_match('/iphone|ipod|android.*mobile|windows phone|blackberry|opera mini|mobile safari/i', $ua) === 1) {
    return 'mobile';
  }

  return 'desktop';
}

function parseVacantApplyRowsFromPost(array $postedRows, array $previewRowsMap): array
{
  $normalized = [];

  foreach ($postedRows as $encoded) {
    $raw = trim((string) $encoded);
    if ($raw === '') {
      continue;
    }

    $json = base64_decode($raw, true);
    if ($json === false || $json === '') {
      continue;
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
      continue;
    }

    $sg = (int) ($data['sg'] ?? 0);
    $unit = trim((string) ($data['unit'] ?? ''));
    $mac = strtolower(trim((string) ($data['mac'] ?? '')));
    if ($sg <= 0 || $unit === '' || $mac === '') {
      continue;
    }

    $key = vacantPreviewRowKey($sg, $unit, $mac);
    if (!isset($previewRowsMap[$key])) {
      continue;
    }

    $normalized[$key] = $previewRowsMap[$key];
  }

  return array_values($normalized);
}

function generateGuestAccessId(int $length = 8): string
{
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $max = strlen($alphabet) - 1;
  $out = 'PO-';
  for ($i = 0; $i < $length; $i++) {
    $out .= $alphabet[random_int(0, $max)];
  }

  return $out;
}

function generateGuestAccessCode(int $length = 10): string
{
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $max = strlen($alphabet) - 1;
  $size = max(8, $length);
  $out = '';
  for ($i = 0; $i < $size; $i++) {
    $out .= $alphabet[random_int(0, $max)];
  }

  return $out;
}

function buildGuestAccessCodeLookup(string $code, string $pepper): string
{
  return hash_hmac('sha256', strtoupper(trim($code)), $pepper);
}

$defaultProfilesBySg = [];
$defaultProfilesUpdatedAtBySg = [];
$serviceGroupNamesBySg = [];
$serviceGroupLabelsBySg = [];
$vacantProfileOverridesBySg = [];
foreach ($serviceGroups as $sg) {
  $sg = (int) $sg;
  $fallback = defaultProfileFallbackForServiceGroup($sg, $envDefaultProfile);
  $vacantFallback = str_pad((string) $sg, 2, '0', STR_PAD_LEFT) . 'vacant';
  $profile = (string) ($settings->get('default_service_profile_sg_' . $sg, $fallback) ?? $fallback);
  $updatedAt = (string) ($settings->get('default_service_profile_sg_' . $sg . '_updated_at', '') ?? '');
  $name = trim((string) ($settings->get('service_group_name_sg_' . $sg, '') ?? ''));
  if ($profile === '') {
    $profile = $fallback;
  }
  $defaultProfilesBySg[$sg] = $profile;
  $defaultProfilesUpdatedAtBySg[$sg] = $updatedAt;
  $serviceGroupNamesBySg[$sg] = $name;
  $vacantProfileOverridesBySg[$sg] = trim((string) ($settings->get('vacant_profile_sg_' . $sg, $vacantFallback) ?? $vacantFallback));
  $serviceGroupLabelsBySg[$sg] = serviceGroupDisplayLabel($sg, $serviceGroupNamesBySg);
}

if (!isset($defaultProfilesBySg[(int) ($serviceGroups[0] ?? 10)])) {
  $defaultProfilesBySg[(int) ($serviceGroups[0] ?? 10)] = $defaultProfile;
}

if (!isset($_SESSION['reprovision_form_token']) || !is_string($_SESSION['reprovision_form_token']) || $_SESSION['reprovision_form_token'] === '') {
  $_SESSION['reprovision_form_token'] = bin2hex(random_bytes(16));
}
$reprovisionFormToken = (string) $_SESSION['reprovision_form_token'];
if (!isset($_SESSION['vacant_preview_rows']) || !is_array($_SESSION['vacant_preview_rows'])) {
  $_SESSION['vacant_preview_rows'] = [];
}

$adminDeviceClass = detectAdminDeviceClassFromUserAgent((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
$adminViewMode = $adminDeviceClass === 'mobile' ? 'compact' : 'standard';
if ($isAdmin && $isLoggedIn) {
  $currentUserId = (int) ($currentUser['id'] ?? 0);
  if ($currentUserId > 0) {
    try {
      $savedViewMode = $users->getAdminViewModeForDevice($currentUserId, $adminDeviceClass);
      if (in_array((string) $savedViewMode, ['standard', 'compact'], true)) {
        $adminViewMode = (string) $savedViewMode;
      }
    } catch (Throwable $e) {
      // Ignore preference lookup failures and keep device default.
    }
  }
}

$configuredColorPreset = trim((string) ($settings->get('color_preset', 'forest') ?? 'forest'));
if (array_key_exists($configuredColorPreset, $colorPresetOptions)) {
  $selectedColorPreset = $configuredColorPreset;
}
$appTitle = trim($config->get('APP_TITLE', ''));
if ($appTitle === '') {
  $appTitle = $config->get('APP_NAME', 'Simple Guest Registration');
}

$guestSelfServiceEnabled = ($settings->get('guest_self_service_enabled', '0') ?? '0') === '1';
$guestSelfServiceAllowEarlyCheckout = ($settings->get('guest_self_service_allow_early_checkout', '1') ?? '1') === '1';
$guestSelfServiceAllowExtension = ($settings->get('guest_self_service_allow_extension', '1') ?? '1') === '1';
$guestSelfServiceRequireReason = ($settings->get('guest_self_service_require_reason', '0') ?? '0') === '1';
$guestSelfServiceMaxExtensionDays = max(1, min(30, (int) ($settings->get('guest_self_service_max_extension_days', '7') ?? '7')));
$guestSelfServiceApprovalMode = trim((string) ($settings->get('guest_self_service_approval_mode', 'manual') ?? 'manual'));
if (!in_array($guestSelfServiceApprovalMode, ['manual', 'auto'], true)) {
  $guestSelfServiceApprovalMode = 'manual';
}
$guestSelfServiceMaxFailedAttempts = max(1, min(20, (int) ($settings->get('guest_self_service_max_failed_attempts', '5') ?? '5')));
$guestSelfServiceLockoutMinutes = max(1, min(120, (int) ($settings->get('guest_self_service_lockout_minutes', '15') ?? '15')));
$guestSelfServiceIpWindowMinutes = max(1, min(120, (int) ($settings->get('guest_self_service_ip_window_minutes', '10') ?? '10')));
$guestSelfServiceIpFailureThreshold = max(0, min(200, (int) ($settings->get('guest_self_service_ip_failure_threshold', '20') ?? '20')));
$guestSelfServiceAuthMode = trim((string) ($settings->get('guest_self_service_auth_mode', 'id_and_code') ?? 'id_and_code'));
if (!in_array($guestSelfServiceAuthMode, ['id_and_code', 'code_only'], true)) {
  $guestSelfServiceAuthMode = 'id_and_code';
}
$globalCheckoutTime = trim((string) ($settings->get('global_checkout_time', '11:00') ?? '11:00'));
if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $globalCheckoutTime)) {
  $globalCheckoutTime = '11:00';
}
$guestAccessCodeLookupPepper = $config->get('ADMIN_ACCESS_KEY', 'sgr-guest-access-pepper');

try {
  $autoCheckoutService->maybeRunForCurrentMinute($globalCheckoutTime, $vacantProfileOverridesBySg, 200);
} catch (Throwable $e) {
  // Keep request flow running if auto-checkout processing has a transient failure.
}

$autoCheckoutDiagnostics = $autoCheckoutService->lastDiagnostics();
$autoCheckoutLastRunAt = trim((string) ($autoCheckoutDiagnostics['ran_at'] ?? ''));
$autoCheckoutLastDueCount = max(0, (int) ($autoCheckoutDiagnostics['due_count'] ?? 0));
$autoCheckoutLastSuccessCount = max(0, (int) ($autoCheckoutDiagnostics['success_count'] ?? 0));
$autoCheckoutLastFailureReasons = is_array($autoCheckoutDiagnostics['failure_reasons'] ?? null)
  ? (array) $autoCheckoutDiagnostics['failure_reasons']
  : [];

$guestAccessGuestId = (int) ($_SESSION['guest_access_guest_id'] ?? 0);
$guestAccessGuest = null;
$guestRequestFilterStatus = 'all';
$guestRequestFilterType = 'all';
$guestRequestFilterSg = 0;
$guestRequestFilterDateFrom = '';
$guestRequestFilterDateTo = '';
$guestRequestFilterSearch = '';
$guestRequestSort = 'created_desc';
$guestRequestPage = 1;
$guestRequestPerPage = 50;
$guestRequestTotalRows = 0;
$guestRequestTotalPages = 1;
$upcomingWindowDays = '30';
$upcomingWindowCutoffDate = date('Y-m-d', strtotime('+30 days'));
$openGuestRequestsPanel = false;
$guestRequestQueueMetrics = [
  'open_requests' => 0,
  'oldest_pending_age_minutes' => null,
  'median_pending_age_minutes' => null,
];
$hasPendingGuestChangeRequests = false;
$activeGuestStatusFilter = 'all';
$activeGuestTotalCount = 0;
$activeGuestActionNeededCount = 0;
$activeGuestVisibleCount = 0;
$offlineModemCount = 0;
$uncheckedModemCount = 0;
$offlineModemRows = [];
$guestAutoLotSelection = '';
$guestAutoLotUnit = '';
$guestAutoLotSg = 0;
$guestAutoLotMac = '';
$guestAutoLotIp = '';
$guestAutoLotError = null;

if (in_array($action, ['login', 'admin'], true) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
  $username = trim((string) ($_POST['username'] ?? ''));
  $password = (string) ($_POST['password'] ?? '');
  $authUser = $users->authenticate($username, $password);

  if ($authUser === null) {
    $error = 'Invalid username or password.';
  } elseif (!in_array((string) $authUser['role'], ['staff', 'admin', 'master_admin'], true)) {
    $error = 'Property user access required for this area.';
  } else {
    $_SESSION['auth_user'] = $authUser;
    $currentUser = $authUser;
    $isLoggedIn = true;
    $userRole = (string) $authUser['role'];
    $isPropertyUser = in_array($userRole, ['staff', 'admin', 'master_admin'], true);
    $isAdmin = in_array($userRole, ['admin', 'master_admin'], true);
    $isMasterAdmin = $userRole === 'master_admin';
    header('Location: /?action=registration_list');
    exit;
  }
}

if ($action === 'login') {
  $action = 'admin';
}

if ($action === 'reprovision_log') {
  $action = 'admin';
}

if ($action === 'logs') {
  $action = 'admin';
}

if ($action === 'modem_scoped_reservation_log') {
  header('Location: /?action=admin');
  exit;
}

if (!$isPropertyUser && in_array($action, ['registration_list', 'upcoming_registrations', 'reports', 'history_report', 'report', 'edit_guest', 'modem_lot_sync', 'vacancy_mgmt', 'reprovision_log', 'logs', 'modem_scoped_reservation_log', 'guest_requests', 'account', 'faq'], true)) {
  $action = 'admin';
  $error = 'Property user access required.';
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] !== 'POST' && $isPropertyUser && !$isAdmin) {
  $action = 'registration_list';
  $error = 'Admin settings access is for Admin users only.';
}

if (($action === 'api_refresh' || $action === 'api_profile_update') && !$isPropertyUser) {
  http_response_code(403);
}

if ($action === 'api_refresh') {
  if (!$isPropertyUser) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT);
    exit;
  }
}

if ($action === 'gunslinger_refresh_api') {
  $requiredApiKey = trim($config->get('GUNSLINGER_REFRESH_API_KEY', ''));
  $providedApiKey = (string) ($_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? ''));
  if ($requiredApiKey !== '' && !hash_equals($requiredApiKey, $providedApiKey)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
      'ok' => false,
      'error' => 'Unauthorized'
    ], JSON_PRETTY_PRINT);
    exit;
  }

  $groups = parseServiceGroupList((string) ($_GET['service_groups'] ?? ''), $serviceGroups);
  $limit = (int) ($_GET['limit'] ?? 5000);
  $limit = max(1, min($limit, 20000));

  try {
    $rows = $customers->listByServiceGroups($groups, $limit);
    header('Content-Type: application/json');
    echo json_encode([
      'ok' => true,
      'service_groups' => $groups,
      'source_table' => $customers->lastSourceTable(),
      'count' => count($rows),
      'data' => $rows,
    ], JSON_PRETTY_PRINT);
  } catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
      'ok' => false,
      'error' => 'Failed to query customers source table',
      'details' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
  }
  exit;
}

if ($action === 'api_available_units') {
  $arrival = trim((string) ($_GET['arrival_date'] ?? ''));
  $departure = trim((string) ($_GET['departure_date'] ?? ''));
  $allUnitsBySg = $modems->listUnitsByServiceGroup($serviceGroups);

  $flattenUnits = static function (array $grouped): array {
    $out = [];
    foreach ($grouped as $sg => $units) {
      foreach ((array) $units as $u) {
        $out[] = (string) $u;
      }
    }
    return $out;
  };

  if ($arrival === '' || $departure === '' || strtotime($departure) < strtotime($arrival)) {
    header('Content-Type: application/json');
    echo json_encode([
      'ok' => true,
      'units' => $flattenUnits($allUnitsBySg),
      'units_by_sg' => $allUnitsBySg,
    ], JSON_PRETTY_PRINT);
    exit;
  }

  $occupied = $guests->occupiedUnitKeysForDateRange($arrival, $departure);
  $occupiedMap = array_fill_keys($occupied, true);
  $availableBySg = [];
  foreach ($allUnitsBySg as $sg => $units) {
    $filtered = array_values(array_filter((array) $units, static function ($u) use ($occupiedMap, $sg) {
      return !isset($occupiedMap[((int) $sg) . '|' . (string) $u]);
    }));
    if ($filtered !== []) {
      $availableBySg[(string) $sg] = $filtered;
    }
  }

  header('Content-Type: application/json');
  echo json_encode([
    'ok' => true,
    'units' => $flattenUnits($availableBySg),
    'units_by_sg' => $availableBySg,
  ], JSON_PRETTY_PRINT);
  exit;
}

if ($action === 'api_refresh') {
    $result = $gunslinger->refreshCustomers($serviceGroups);
    if (($result['ok'] ?? false) && !empty($result['rows'])) {
        $modems->replaceByServiceGroups($result['rows'], $serviceGroups);
    }

    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'api_profile_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$isPropertyUser) {
      http_response_code(403);
      header('Content-Type: application/json');
      echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT);
      exit;
    }

    $sg = (int) ($_POST['sg'] ?? 0);
    $unit = trim((string) ($_POST['unit'] ?? ''));
  $mac = trim((string) ($_POST['mac'] ?? ''));
    $fallbackProfile = (string) ($defaultProfilesBySg[$sg] ?? $defaultProfile);
    $profile = trim((string) ($_POST['profile'] ?? $fallbackProfile));

  if (!profileMatchesServiceGroup($profile, $sg)) {
    header('Content-Type: application/json');
    echo json_encode([
      'ok' => false,
      'error' => sprintf('Profile must start with SG prefix %02d', $sg),
    ], JSON_PRETTY_PRINT);
    exit;
  }

  $result = $gunslinger->updateProfile($sg, $unit, $mac, $profile);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_admin_view_mode'])) {
  header('Content-Type: application/json');

  if (!$isAdmin || !$isLoggedIn) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin access required.'], JSON_PRETTY_PRINT);
    exit;
  }

  $submittedToken = (string) ($_POST['reprovision_token'] ?? '');
  if (!hasValidReprovisionToken($submittedToken)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid or expired token.'], JSON_PRETTY_PRINT);
    exit;
  }

  $requestedMode = trim((string) ($_POST['admin_view_mode'] ?? ''));
  $requestedDeviceClass = trim((string) ($_POST['admin_view_device_class'] ?? ''));
  if (!in_array($requestedMode, ['standard', 'compact'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid view mode.'], JSON_PRETTY_PRINT);
    exit;
  }
  if (!in_array($requestedDeviceClass, ['desktop', 'mobile'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid device class.'], JSON_PRETTY_PRINT);
    exit;
  }

  try {
    $users->setAdminViewModeForDevice((int) ($currentUser['id'] ?? 0), $requestedDeviceClass, $requestedMode);
    $adminViewMode = $requestedMode;
    echo json_encode([
      'ok' => true,
      'mode' => $requestedMode,
      'device_class' => $requestedDeviceClass,
    ], JSON_PRETTY_PRINT);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save admin view preference.'], JSON_PRETTY_PRINT);
  }
  exit;
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_admin_settings'])) {
    if (!$isAdmin) {
      $error = 'Admin access required.';
    } else {
    $requestedBySg = $_POST['default_service_profile_by_sg'] ?? [];
    if (!is_array($requestedBySg)) {
      $requestedBySg = [];
    }

    $normalizedProfiles = [];
    foreach ($serviceGroups as $sg) {
      $sg = (int) $sg;
      $requestedProfile = trim((string) ($requestedBySg[(string) $sg] ?? ''));
      if ($requestedProfile === '') {
        $error = sprintf('Default profile is required for SG %d.', $sg);
        break;
      }
      if (!profileMatchesServiceGroup($requestedProfile, $sg)) {
        $error = sprintf('Default profile for SG %d must start with %02d.', $sg, $sg);
        break;
      }
      $normalizedProfiles[$sg] = $requestedProfile;
    }

    if ($error === null) {
      try {
        foreach ($normalizedProfiles as $sg => $profile) {
          $settings->set('default_service_profile_sg_' . $sg, $profile);
          $settings->set('default_service_profile_sg_' . $sg . '_updated_at', date('Y-m-d H:i:s'));
          $defaultProfilesBySg[(int) $sg] = $profile;
          $defaultProfilesUpdatedAtBySg[(int) $sg] = date('Y-m-d H:i:s');
        }
        $firstSg = (int) ($serviceGroups[0] ?? 10);
        $defaultProfile = (string) ($defaultProfilesBySg[$firstSg] ?? $defaultProfile);
        $settings->set('default_service_profile', $defaultProfile);
        $flash = 'Updated default service profiles for all configured service groups.';
      } catch (Throwable $e) {
        $error = 'Failed to save admin setting: ' . $e->getMessage();
      }
    }
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_admin_profile_sg'])) {
  if (!$isAdmin) {
    $error = 'Admin access required.';
  } else {
    $sg = (int) ($_POST['profile_sg'] ?? 0);
    $requestedProfile = trim((string) ($_POST['default_service_profile'] ?? ''));

    if (!in_array($sg, array_map('intval', $serviceGroups), true)) {
      $error = 'Invalid service group.';
    } elseif ($requestedProfile === '') {
      $error = sprintf('Default profile is required for SG %d.', $sg);
    } elseif (!profileMatchesServiceGroup($requestedProfile, $sg)) {
      $error = sprintf('Default profile for SG %d must start with %02d.', $sg, $sg);
    } else {
      try {
        $settings->set('default_service_profile_sg_' . $sg, $requestedProfile);
        $settings->set('default_service_profile_sg_' . $sg . '_updated_at', date('Y-m-d H:i:s'));
        $defaultProfilesBySg[$sg] = $requestedProfile;
        $defaultProfilesUpdatedAtBySg[$sg] = date('Y-m-d H:i:s');
        $firstSg = (int) ($serviceGroups[0] ?? 10);
        if ($sg === $firstSg) {
          $settings->set('default_service_profile', $requestedProfile);
          $defaultProfile = $requestedProfile;
        }
        $flash = sprintf('Updated SG %d default profile to "%s".', $sg, $requestedProfile);
      } catch (Throwable $e) {
        $error = 'Failed to save admin setting: ' . $e->getMessage();
      }
    }
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_global_checkout_time'])) {
  if (!$isAdmin) {
    $error = 'Admin access required.';
  } else {
    $requestedCheckoutTime = trim((string) ($_POST['global_checkout_time'] ?? ''));
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $requestedCheckoutTime)) {
      $error = 'Checkout time must be in HH:MM 24-hour format.';
    } else {
      try {
        $settings->set('global_checkout_time', $requestedCheckoutTime);
        $globalCheckoutTime = $requestedCheckoutTime;
        $flash = 'Global checkout time updated.';
      } catch (Throwable $e) {
        $error = 'Failed to save checkout time: ' . $e->getMessage();
      }
    }
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_service_group_names'])) {
  if (!$isAdmin) {
    $error = 'Admin access required.';
  } else {
    $requestedNames = $_POST['service_group_name_by_sg'] ?? [];
    if (!is_array($requestedNames)) {
      $requestedNames = [];
    }

    try {
      foreach ($serviceGroups as $sg) {
        $sg = (int) $sg;
        $name = trim((string) ($requestedNames[(string) $sg] ?? ''));
        $settings->set('service_group_name_sg_' . $sg, $name);
        $serviceGroupNamesBySg[$sg] = $name;
      }

      foreach ($serviceGroups as $sg) {
        $sg = (int) $sg;
        $serviceGroupLabelsBySg[$sg] = serviceGroupDisplayLabel($sg, $serviceGroupNamesBySg);
      }

      $flash = 'Service group names updated.';
    } catch (Throwable $e) {
      $error = 'Failed to save service group names: ' . $e->getMessage();
    }
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_service_group_name_sg'])) {
  if (!$isAdmin) {
    $error = 'Admin access required.';
  } else {
    $sg = (int) ($_POST['sg_name_sg'] ?? 0);
    if (!in_array($sg, array_map('intval', $serviceGroups), true)) {
      $error = 'Invalid service group.';
    } else {
      $name = trim((string) ($_POST['service_group_name'] ?? ''));
      try {
        $settings->set('service_group_name_sg_' . $sg, $name);
        $serviceGroupNamesBySg[$sg] = $name;
        $serviceGroupLabelsBySg[$sg] = serviceGroupDisplayLabel($sg, $serviceGroupNamesBySg);
        $flash = sprintf('Updated display name for SG %d.', $sg);
      } catch (Throwable $e) {
        $error = 'Failed to save service group name: ' . $e->getMessage();
      }
    }
  }
}

if (in_array($action, ['admin', 'vacancy_mgmt'], true) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_vacant_profile_settings'])) {
  $openVacantProfileCard = true;
  $openVacantSettingsSection = true;
  if (!$isPropertyUser) {
    $error = 'Property user access required.';
  } elseif (!hasValidReprovisionToken((string) ($_POST['reprovision_token'] ?? ''))) {
    $error = 'Vacant profile settings request is invalid or expired. Please retry from Admin Settings.';
  } else {
    $requestedOverrides = $_POST['vacant_profile_by_sg'] ?? [];
    if (!is_array($requestedOverrides)) {
      $requestedOverrides = [];
    }
    $normalizedOverrides = [];

    foreach ($serviceGroups as $sg) {
      $sg = (int) $sg;
      $profile = trim((string) ($requestedOverrides[(string) $sg] ?? ''));
      if ($profile === '') {
        $error = sprintf('Vacant profile is required for SG %d.', $sg);
        break;
      }
      if (!profileMatchesServiceGroup($profile, $sg)) {
        $error = sprintf('Vacant profile for SG %d must start with %02d.', $sg, $sg);
        break;
      }
      $normalizedOverrides[$sg] = $profile;
    }

    if ($error === null) {
      try {
        foreach ($normalizedOverrides as $sg => $profile) {
          $settings->set('vacant_profile_sg_' . $sg, $profile);
          $vacantProfileOverridesBySg[$sg] = $profile;
        }
        $flash = 'Vacant profile settings updated.';
      } catch (Throwable $e) {
        $error = 'Failed to save vacant profile settings: ' . $e->getMessage();
      }
    }
  }
}

if (in_array($action, ['admin', 'vacancy_mgmt'], true) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_vacant_profile_audit'])) {
  $openVacantProfileCard = true;
  $openVacantPreviewSection = true;
  if (!$isPropertyUser) {
    $error = 'Property user access required.';
  } elseif (!hasValidReprovisionToken((string) ($_POST['reprovision_token'] ?? ''))) {
    $error = 'Vacant preview request is invalid or expired. Please retry from Admin Settings.';
  } else {
    $refreshResult = $gunslinger->refreshCustomers($serviceGroups);
    if (!($refreshResult['ok'] ?? false)) {
      $error = 'Vacant lot audit refresh failed: ' . (string) ($refreshResult['error'] ?? 'unknown');
    } else {
      try {
        $rows = (array) ($refreshResult['rows'] ?? []);
        if ($rows !== []) {
          $modems->replaceByServiceGroups($rows, $serviceGroups);
        }

        $today = date('Y-m-d');
        $occupiedKeys = $guests->occupiedUnitKeysForDateRange($today, $today);
        $occupiedMap = array_fill_keys($occupiedKeys, true);

        $latestByLotKey = [];
        foreach ($rows as $row) {
          $sg = (int) ($row['sg'] ?? 0);
          $unit = trim((string) ($row['unit'] ?? ''));
          if ($sg <= 0 || $unit === '' || strtolower($unit) === 'stock') {
            continue;
          }
          $key = $sg . '|' . $unit;
          $latestByLotKey[$key] = [
            'sg' => $sg,
            'unit' => $unit,
            'mac' => strtolower(trim((string) ($row['mac'] ?? ''))),
            'current_profile' => trim((string) ($row['profile'] ?? '')),
          ];
        }

        foreach ($latestByLotKey as $key => $lot) {
          if (isset($occupiedMap[$key])) {
            continue;
          }

          $sg = (int) $lot['sg'];
          $targetProfile = trim((string) ($vacantProfileOverridesBySg[$sg] ?? ''));

          $hasConfig = $targetProfile !== '';
          $needsChange = $hasConfig && strtolower((string) $lot['current_profile']) !== strtolower($targetProfile);
          $isAlreadyTarget = $hasConfig && !$needsChange;

          if (!isset($vacantAuditRowsBySg[$sg])) {
            $vacantAuditRowsBySg[$sg] = [];
          }

          $vacantAuditRowsBySg[$sg][] = [
            'sg' => $sg,
            'unit' => (string) $lot['unit'],
            'mac' => (string) $lot['mac'],
            'current_profile' => (string) $lot['current_profile'],
            'target_profile' => (string) $targetProfile,
            'has_config' => $hasConfig,
            'needs_change' => $needsChange,
          ];

          $vacantAuditTotals['total_vacant']++;
          if ($isAlreadyTarget) {
            $vacantAuditTotals['already_target']++;
          }
          if ($needsChange) {
            $vacantAuditTotals['needs_change']++;
          }
          if (!$hasConfig) {
            $vacantAuditTotals['config_missing']++;
          }
        }

        if ($vacantAuditRowsBySg !== []) {
          ksort($vacantAuditRowsBySg);
          foreach ($vacantAuditRowsBySg as $sg => $rowsBySg) {
            usort($rowsBySg, static function (array $a, array $b): int {
              return strcmp((string) ($a['unit'] ?? ''), (string) ($b['unit'] ?? ''));
            });
            $vacantAuditRowsBySg[$sg] = $rowsBySg;
          }
        }

        $vacantAuditRefreshedAt = date('Y-m-d H:i:s');
        $vacantAuditSource = implode(',', array_map('strval', $serviceGroups));
        $showVacantAuditResults = true;
        stashVacantPreviewRowsInSession($vacantAuditRowsBySg);
      } catch (Throwable $e) {
        $error = 'Failed to prepare vacant lot audit preview: ' . $e->getMessage();
      }
    }
  }
}

if (in_array($action, ['admin', 'vacancy_mgmt'], true) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_apply_vacant_profiles'])) {
  $openVacantProfileCard = true;
  $openVacantPreviewSection = true;
  if (!$isPropertyUser) {
    $error = 'Property user access required.';
  } elseif (!hasValidReprovisionToken((string) ($_POST['reprovision_token'] ?? ''))) {
    $error = 'Vacant apply selection request is invalid or expired. Please rerun preview and retry.';
  } else {
    $rawRows = $_POST['vacant_apply_rows'] ?? [];
    if (!is_array($rawRows)) {
      $rawRows = [];
    }

    $previewRowsMap = previewRowsFromSession($serviceGroups);
    $selectedRows = parseVacantApplyRowsFromPost($rawRows, $previewRowsMap);
    if ($selectedRows === []) {
      $error = 'Select at least one vacant lot row to apply profile updates.';
    } else {
      $vacantApplyPendingRows = $selectedRows;
      $showVacantApplyConfirm = true;
      $showVacantAuditResults = true;
    }
  }
}

if (in_array($action, ['admin', 'vacancy_mgmt'], true) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_apply_vacant_profiles'])) {
  $openVacantProfileCard = true;
  $openVacantPreviewSection = true;
  if (!$isPropertyUser) {
    $error = 'Property user access required.';
  } elseif (!hasValidReprovisionToken((string) ($_POST['reprovision_token'] ?? ''))) {
    $error = 'Vacant apply confirmation request is invalid or expired. Please rerun preview and retry.';
  } else {
    $decision = trim((string) ($_POST['confirm_apply_vacant_profiles'] ?? 'cancel'));
    $payloadRows = $_POST['vacant_apply_payload'] ?? [];
    if (!is_array($payloadRows)) {
      $payloadRows = [];
    }

    $previewRowsMap = previewRowsFromSession($serviceGroups);
    $selectedRows = parseVacantApplyRowsFromPost($payloadRows, $previewRowsMap);
    if ($decision !== 'proceed') {
      $flash = 'Vacant profile apply cancelled.';
    } elseif ($selectedRows === []) {
      $error = 'No valid rows were selected for apply.';
    } else {
      $actor = $isLoggedIn ? (string) ($currentUser['username'] ?? 'admin') : 'admin';
      $showVacantApplyResults = true;
      $showVacantAuditResults = true;

      foreach ($selectedRows as $row) {
        $sg = (int) ($row['sg'] ?? 0);
        $unit = (string) ($row['unit'] ?? '');
        $mac = (string) ($row['mac'] ?? '');
        $oldProfile = (string) ($row['current_profile'] ?? '');
        $targetProfile = (string) ($row['target_profile'] ?? '');

        $vacantApplySummary['attempted']++;
        $status = 'failed';
        $details = '';

        if (!profileMatchesServiceGroup($targetProfile, $sg)) {
          $details = sprintf('Target profile must start with %02d.', $sg);
        } else {
          $result = $gunslinger->updateProfile($sg, $unit, $mac, $targetProfile);
          if (($result['ok'] ?? false) === true) {
            $status = 'updated';
            $details = 'Profile update request accepted.';
            $vacantApplySummary['updated']++;
          } else {
            $details = (string) ($result['error'] ?? 'Profile update failed');
          }
        }

        if ($status !== 'updated') {
          $vacantApplySummary['failed']++;
        }

        $vacantApplyRunResults[] = [
          'sg' => $sg,
          'unit' => $unit,
          'mac' => $mac,
          'old_profile' => $oldProfile,
          'target_profile' => $targetProfile,
          'status' => $status,
          'details' => $details,
        ];

        try {
          $guests->addVacantProfileLog([
            'sg' => $sg,
            'unit' => $unit,
            'modem_mac' => $mac,
            'old_profile' => $oldProfile,
            'target_profile' => $targetProfile,
            'status' => $status,
            'details' => $details,
            'actor' => $actor,
          ]);
        } catch (Throwable $e) {
          // Keep apply flow running even if logging fails for a row.
        }
      }

      $flash = sprintf(
        'Vacant profile apply completed. Attempted %d, updated %d, failed %d.',
        (int) $vacantApplySummary['attempted'],
        (int) $vacantApplySummary['updated'],
        (int) $vacantApplySummary['failed']
      );
    }
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_color_preset'])) {
  if (!$isAdmin) {
    $error = 'Admin access required.';
  } else {
    $requestedPreset = trim((string) ($_POST['color_preset'] ?? 'forest'));
    if (!array_key_exists($requestedPreset, $colorPresetOptions)) {
      $error = 'Invalid color preset selected.';
    } else {
      try {
        $settings->set('color_preset', $requestedPreset);
        $selectedColorPreset = $requestedPreset;
        $flash = sprintf('Application color preset updated to %s.', $colorPresetOptions[$requestedPreset]);
      } catch (Throwable $e) {
        $error = 'Failed to save color preset: ' . $e->getMessage();
      }
    }
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_color_preset'])) {
  if (!$isAdmin) {
    $error = 'Admin access required.';
  } else {
    try {
      $settings->set('color_preset', 'forest');
      $selectedColorPreset = 'forest';
      $flash = 'Application color preset reset to Forest.';
    } catch (Throwable $e) {
      $error = 'Failed to reset color preset: ' . $e->getMessage();
    }
  }
}

if (in_array($action, ['admin', 'modem_lot_sync'], true) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_and_remap_lots'])) {
  if (!$isPropertyUser) {
    $error = 'Property user access required.';
  } else {
    $previousLotMacMap = $modems->lotMacMapByServiceGroups($serviceGroups);
    $refreshResult = $gunslinger->refreshCustomers($serviceGroups);
    if (!($refreshResult['ok'] ?? false)) {
      $error = 'Gunslinger refresh failed: ' . (string) ($refreshResult['error'] ?? 'unknown');
    } else {
      try {
        $rows = (array) ($refreshResult['rows'] ?? []);
        $workingProfilesBySg = [];
        foreach ($serviceGroups as $sg) {
          $workingProfilesBySg[(int) $sg] = (string) ($defaultProfilesBySg[(int) $sg] ?? '');
        }
        $bootfileSyncResult = $gunslinger->fetchWorkingProfileBootfiles($serviceGroups, $workingProfilesBySg);
        $bootfileSyncUpserts = 0;
        $bootfileSyncWarning = '';
        if (($bootfileSyncResult['ok'] ?? false) === true) {
          $bootfileSyncUpserts = $profileBootfiles->upsertMappings((array) ($bootfileSyncResult['rows'] ?? []));
        } else {
          $bootfileSyncWarning = 'Bootfile sync warning: ' . (string) ($bootfileSyncResult['error'] ?? 'unknown');
        }

        if ($rows !== []) {
          $modems->replaceByServiceGroups($rows, $serviceGroups);
          $leaseChecks = syncLeaseStatusesFromRows($rows, $serviceGroups, $ddnet, $modems);
        }
        $remapped = $guests->remapUnitsFromModemsByMac($serviceGroups);
        $currentLotMacMap = $modems->lotMacMapByServiceGroups($serviceGroups);
        $lotMacChanges = GuestProvisioningService::detectLotMacChanges($previousLotMacMap, $currentLotMacMap);
        $actor = $isLoggedIn ? (string) ($currentUser['username'] ?? 'staff') : 'staff';
        $autoReprovision = $guestProvisioningService->autoReprovisionAffectedGuests(
          $lotMacChanges,
          $serviceGroups,
          $defaultProfilesBySg,
          (string) $defaultProfile,
          $actor
        );
        $autoReprovisionLots = [];
        foreach ((array) ($autoReprovision['results'] ?? []) as $result) {
          $sg = (int) ($result['sg'] ?? 0);
          $unit = trim((string) ($result['unit'] ?? ''));
          if ($sg <= 0 || $unit === '') {
            continue;
          }
          $autoReprovisionLots[] = sprintf('SG %d lot %s', $sg, $unit);
        }
        $autoReprovisionLots = array_values(array_unique($autoReprovisionLots));
        $offlineCountNow = $modems->countOfflineLeaseByServiceGroups($serviceGroups);
        $flash = sprintf(
          'Refresh completed for SG %s. Imported %d modem rows, remapped %d guest lot assignments, checked %d unique modem leases, found %d lot(s) without active leases, synced %d profile-bootfile mapping(s), and auto-reprovisioned %d active guest(s) with %d warning run(s) and %d failure(s).',
          implode(',', $serviceGroups),
          count($rows),
          $remapped,
          isset($leaseChecks) ? (int) $leaseChecks : 0,
          $offlineCountNow,
          $bootfileSyncUpserts,
          (int) ($autoReprovision['updated'] ?? 0),
          (int) ($autoReprovision['warnings'] ?? 0),
          (int) ($autoReprovision['failed'] ?? 0)
        );
        if ($autoReprovisionLots !== []) {
          $flash .= ' Auto-reprovision lots: ' . implode(', ', $autoReprovisionLots) . '.';
        }
        if ($bootfileSyncWarning !== '') {
          $flash .= ' ' . $bootfileSyncWarning;
        }
      } catch (Throwable $e) {
        $error = 'Refresh completed, but lot remap failed: ' . $e->getMessage();
      }
    }
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_clear_registrations'])) {
  if (!$isMasterAdmin) {
    $error = 'Only master admin can clear registrations.';
  } else {
    $showClearRegistrationsConfirm = true;
    $registrationCountForClear = $guests->countAll();
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_clear_registrations'])) {
  if (!$isMasterAdmin) {
    $error = 'Only master admin can clear registrations.';
  } else {
    $decision = trim((string) ($_POST['confirm_clear_registrations'] ?? 'cancel'));
    if ($decision === 'proceed') {
      try {
        $result = $guests->backupAndClearAll();
        $backupTables = (array) ($result['backup_tables'] ?? []);
        $backupSummary = $backupTables === []
          ? 'No rows were present to back up.'
          : ('Backup tables created: ' . implode(', ', array_values($backupTables)) . '.');
        $flash = sprintf(
          'Reset completed. Cleared %d registration record(s), modem cache, and related logs/queues. %s',
          (int) ($result['cleared_rows'] ?? 0),
          $backupSummary
        );
        $showServiceGroupResyncPrompt = true;
        $serviceGroupResyncPromptContext = 'Registration reset';
      } catch (Throwable $e) {
        $error = 'Failed to reset registrations, modem cache, and logs: ' . $e->getMessage();
      }
    } else {
      $flash = 'Registration clear cancelled.';
    }
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_log_key'])) {
  if (!$isAdmin) {
    $error = 'Only admin users can clear logs.';
  } else {
    $logKey = trim((string) ($_POST['clear_log_key'] ?? ''));
    $labelsByKey = [
      'reprovision' => 'Reprovision Log',
      'vacant_profile' => 'Vacant Profile Apply Log',
      'modem_scoped' => 'Modem Scoped Reservation Log',
      'guest_access' => 'Guest Access Security Log',
      'captive_portal' => 'API Captive Portal Log',
    ];
    try {
      $cleared = $guests->clearOperationalLog($logKey);
      $flash = sprintf('Cleared %d row(s) from %s.', $cleared, (string) ($labelsByKey[$logKey] ?? 'selected log'));
    } catch (Throwable $e) {
      $error = 'Failed to clear logs: ' . $e->getMessage();
    }
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_factory_reset'])) {
  if (!$isMasterAdmin) {
    $error = 'Only master admin can run full factory reset.';
  } else {
    $showFactoryResetConfirm = true;
    $registrationCountForClear = $guests->countAll();
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_factory_reset'])) {
  if (!$isMasterAdmin) {
    $error = 'Only master admin can run full factory reset.';
  } else {
    $decision = trim((string) ($_POST['confirm_factory_reset'] ?? 'cancel'));
    if ($decision === 'proceed') {
      try {
        $result = $guests->backupAndFactoryResetPreservingMasterAdmins();
        $clearedFileLogs = clearFileLogs(discoverStorageLogFiles($config->get('SNMP_AUDIT_LOG_PATH', '/var/www/html/storage/logs/snmp_audit.log')));
        $backupTables = (array) ($result['backup_tables'] ?? []);
        $backupSummary = $backupTables === []
          ? 'No rows were present to back up.'
          : ('Backup tables created: ' . implode(', ', array_values($backupTables)) . '.');
        $fileLogSummary = $clearedFileLogs === []
          ? 'No writable file logs were found to clear.'
          : ('File logs cleared: ' . implode(', ', $clearedFileLogs) . '.');
        $flash = sprintf(
          'Full factory reset completed. Cleared registrations, modem cache, logs/queues, file logs, settings, and non-master admin users. Preserved %d master admin user(s). %s %s',
          (int) ($result['preserved_master_admin_users'] ?? 0),
          $backupSummary,
          $fileLogSummary
        );
        $showServiceGroupResyncPrompt = true;
        $serviceGroupResyncPromptContext = 'Full factory reset';
      } catch (Throwable $e) {
        $error = 'Failed to run full factory reset: ' . $e->getMessage();
      }
    } else {
      $flash = 'Full factory reset cancelled.';
    }
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_void_registration'])) {
  if (!$isPropertyUser) {
    $error = 'Property user access required.';
  } else {
    $pendingVoidGuestId = (int) ($_POST['guest_id'] ?? 0);
    $action = 'registration_list';
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate_guest_access_code'])) {
  if (!$isPropertyUser) {
    $error = 'Property user access required.';
  } else {
    $guestId = (int) ($_POST['guest_id'] ?? 0);
    $guest = $guests->findById($guestId);
    if ($guest === null) {
      $error = 'Guest record not found.';
    } else {
      $guestAccessId = trim((string) ($guest['guest_access_id'] ?? ''));
      if ($guestAccessId === '') {
        for ($attempt = 0; $attempt < 6; $attempt++) {
          $candidate = generateGuestAccessId();
          if ($guests->isGuestAccessIdAvailable($candidate)) {
            $guestAccessId = $candidate;
            break;
          }
        }
      }

      if ($guestAccessId === '') {
        $error = 'Unable to generate unique guest access ID for this reservation.';
      } else {
        $guestAccessCode = '';
        $guestAccessCodeLookup = '';
        for ($attempt = 0; $attempt < 12; $attempt++) {
          $candidateCode = generateGuestAccessCode(10);
          $candidateLookup = buildGuestAccessCodeLookup($candidateCode, $guestAccessCodeLookupPepper);
          if ($guests->isGuestAccessCodeLookupAvailable($candidateLookup)) {
            $guestAccessCode = $candidateCode;
            $guestAccessCodeLookup = $candidateLookup;
            break;
          }
        }
        $guestCodeHash = $guestAccessCode !== '' ? password_hash($guestAccessCode, PASSWORD_DEFAULT) : '';
        $codeExpiryAt = date('Y-m-d 23:59:59', strtotime((string) ($guest['departure_date'] ?? date('Y-m-d')) . ' +1 day'));

        if ($guestAccessCode === '' || $guestCodeHash === '' || $guestAccessCodeLookup === '') {
          $error = 'Unable to generate unique guest access code for this reservation.';
        } else {
          try {
            $guests->setGuestAccessCredentials($guestId, $guestAccessId, $guestCodeHash, $guestAccessCodeLookup, $codeExpiryAt);

            $actor = $isLoggedIn ? (string) ($currentUser['username'] ?? 'staff') : 'staff';
            $existingNotes = trim((string) ($guest['notes'] ?? ''));
            $note = sprintf('[Guest Access Reset %s] Access credentials regenerated by %s.', date('Y-m-d H:i:s'), $actor);
            $combinedNotes = $existingNotes === '' ? $note : ($existingNotes . ' | ' . $note);

            $guests->updateById($guestId, [
              'guest_name' => (string) $guest['guest_name'],
              'phone' => (string) $guest['phone'],
              'unit' => (string) $guest['unit'],
              'sg' => (int) $guest['sg'],
              'modem_mac' => (string) $guest['modem_mac'],
              'arrival_date' => (string) $guest['arrival_date'],
              'departure_date' => (string) $guest['departure_date'],
              'profile_applied' => (string) $guest['profile_applied'],
              'submission_status' => (string) ($guest['submission_status'] ?? 'submitted'),
              'notes' => $combinedNotes,
            ]);

            $regeneratedGuestAccessInfo = [
              'guest_name' => (string) ($guest['guest_name'] ?? ''),
              'unit' => (string) ($guest['unit'] ?? ''),
              'access_id' => $guestAccessId,
              'access_code' => $guestAccessCode,
              'expires_at' => $codeExpiryAt,
            ];
            $flash = 'Guest access credentials regenerated. Share the new code with the guest now.';
            $action = 'registration_list';
          } catch (Throwable $e) {
            $error = 'Failed to regenerate guest access credentials: ' . $e->getMessage();
          }
        }
      }
    }
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_void_registration'])) {
  if (!$isPropertyUser) {
    $error = 'Property user access required.';
  } else {
    $action = 'registration_list';
    $guestId = (int) ($_POST['guest_id'] ?? 0);
    $decision = trim((string) ($_POST['confirm_void_registration'] ?? 'cancel'));
    if ($decision !== 'proceed') {
      $flash = 'Void registration cancelled.';
    } else {
      $guest = $guests->findById($guestId);
      if ($guest === null) {
        $error = 'Guest record not found.';
      } else {
        $actor = $isLoggedIn ? (string) ($currentUser['username'] ?? 'admin') : 'admin';
        $today = date('Y-m-d');
        $existingNotes = trim((string) ($guest['notes'] ?? ''));
        $voidNote = sprintf('[Admin Void %s] Marked incorrect registration by %s.', date('Y-m-d H:i:s'), $actor);
        $combinedNotes = $existingNotes === '' ? $voidNote : ($existingNotes . ' | ' . $voidNote);

        try {
          $checkoutResult = runVacantCheckoutTransition(
            $guest,
            $vacantProfileOverridesBySg,
            $modems,
            $gunslinger,
            $ddnet,
            $snmp,
            $guests,
            $actor
          );
          if (!($checkoutResult['ok'] ?? false)) {
            throw new RuntimeException('Failed to apply void checkout workflow: ' . (string) ($checkoutResult['error'] ?? 'unknown'));
          }
          foreach ((array) ($checkoutResult['notes'] ?? []) as $checkoutNote) {
            $checkoutNote = trim((string) $checkoutNote);
            if ($checkoutNote !== '') {
              $combinedNotes .= ' | ' . $checkoutNote;
            }
          }

          $guests->updateById($guestId, [
            'guest_name' => (string) $guest['guest_name'],
            'phone' => (string) $guest['phone'],
            'unit' => (string) $guest['unit'],
            'sg' => (int) $guest['sg'],
            'modem_mac' => (string) ($checkoutResult['modem_mac'] ?? (string) $guest['modem_mac']),
            'arrival_date' => (string) $guest['arrival_date'],
            'departure_date' => $today,
            'profile_applied' => (string) ($checkoutResult['profile'] ?? (string) $guest['profile_applied']),
            'submission_status' => 'checked_out',
            'notes' => $combinedNotes,
          ]);
          $flash = 'Registration marked as void/checked out.';
          $action = 'registration_list';
        } catch (Throwable $e) {
          $error = 'Failed to void registration: ' . $e->getMessage();
        }
      }
    }
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reprovision_active_guest'])) {
  if (!$isPropertyUser) {
    $error = 'Property user access required.';
  } else {
    $submittedToken = (string) ($_POST['reprovision_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['reprovision_form_token'] ?? ''), $submittedToken)) {
      $error = 'Reprovision request already processed or invalid. Please retry from the guest list.';
    } else {
      $_SESSION['reprovision_form_token'] = bin2hex(random_bytes(16));
      $reprovisionFormToken = (string) $_SESSION['reprovision_form_token'];

    $guestId = (int) ($_POST['guest_id'] ?? 0);
    $existingGuest = $guests->findById($guestId);

    if ($existingGuest === null) {
      $error = 'Guest record not found.';
    } else {
      $today = date('Y-m-d');
      $isActiveNow =
        (string) ($existingGuest['submission_status'] ?? '') !== 'checked_out' &&
        strtotime((string) $existingGuest['arrival_date']) <= strtotime($today) &&
        strtotime((string) $existingGuest['departure_date']) >= strtotime($today);

      if (!$isActiveNow) {
        $error = 'Reprovision Active Guest is only available for currently active registrations.';
      } else {
        $refreshResult = $gunslinger->refreshCustomers($serviceGroups);
        if (!($refreshResult['ok'] ?? false)) {
          $error = 'Cannot reprovision because Gunslinger modem refresh failed: ' . (string) ($refreshResult['error'] ?? 'unknown');
        } else {
          $rows = (array) ($refreshResult['rows'] ?? []);
          if ($rows !== []) {
            $modems->replaceByServiceGroups($rows, $serviceGroups);
          }
          try {
            $actor = $isLoggedIn ? (string) ($currentUser['username'] ?? 'admin') : 'admin';
            $reprovisionResult = $guestProvisioningService->reprovisionGuestAgainstCurrentModem(
              $existingGuest,
              $serviceGroups,
              $defaultProfilesBySg,
              (string) $defaultProfile,
              $actor
            );
            if (!($reprovisionResult['ok'] ?? false)) {
              $error = (string) ($reprovisionResult['error'] ?? 'Guest reprovision failed.');
            } else {
              $targetUnit = (string) ($reprovisionResult['target_unit'] ?? (string) ($existingGuest['unit'] ?? ''));
              $targetSg = (int) ($reprovisionResult['target_sg'] ?? (int) ($existingGuest['sg'] ?? 0));
              $flash = (string) ($reprovisionResult['status'] ?? '') === 'submitted'
                ? sprintf('Reprovisioned active guest on %s (SG %d).', $targetUnit, $targetSg)
                : sprintf('Guest reprovision completed with warnings on %s (SG %d). Check notes/status.', $targetUnit, $targetSg);
              $_SESSION['flash_message'] = $flash;
              header('Location: /?action=registration_list');
              exit;
            }
          } catch (Throwable $e) {
            $error = 'Failed to save reprovision updates: ' . $e->getMessage();
          }
        }
      }
    }
  }
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
  if (!$isAdmin) {
    $error = 'Admin access required.';
  } else {
    $newUsername = trim((string) ($_POST['new_username'] ?? ''));
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmNewPassword = (string) ($_POST['confirm_new_password'] ?? '');
    $newRole = trim((string) ($_POST['new_role'] ?? 'staff'));

    if (trim($newPassword) === '' || trim($confirmNewPassword) === '') {
      $error = 'New password and confirmation are required.';
    } elseif ($newPassword !== $confirmNewPassword) {
      $error = 'New password and confirmation do not match.';
    } else {
      try {
        $users->createUser($newUsername, $newPassword, $newRole);
        $flash = 'User created successfully.';
      } catch (Throwable $e) {
        $error = 'Failed to create user: ' . $e->getMessage();
      }
    }
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
  if (!$isAdmin) {
    $error = 'Admin access required.';
  } else {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $editRole = trim((string) ($_POST['edit_role'] ?? 'staff'));
    $editIsActive = ((string) ($_POST['edit_is_active'] ?? '1') === '1') ? 1 : 0;
    $editPassword = (string) ($_POST['edit_password'] ?? '');
    $confirmEditPassword = (string) ($_POST['confirm_edit_password'] ?? '');

    if (trim($editPassword) === '' && trim($confirmEditPassword) !== '') {
      $error = 'Enter a new password before confirming it.';
    } elseif (trim($editPassword) !== '' && trim($confirmEditPassword) === '') {
      $error = 'Confirm the new password before saving.';
    } elseif (trim($editPassword) !== '' && $editPassword !== $confirmEditPassword) {
      $error = 'New password and confirmation do not match.';
    } else {
      try {
        $users->updateUser($userId, $editRole, $editIsActive, $editPassword);
        $flash = 'User updated successfully.';
      } catch (Throwable $e) {
        $error = 'Failed to update user: ' . $e->getMessage();
      }
    }
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_delete_user'])) {
  if (!$isAdmin) {
    $error = 'Admin access required.';
  } else {
    $pendingDeleteUserId = (int) ($_POST['user_id'] ?? 0);
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete_user'])) {
  if (!$isAdmin) {
    $error = 'Admin access required.';
  } else {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $decision = trim((string) ($_POST['confirm_delete_user'] ?? 'cancel'));
    try {
      if ($decision === 'proceed') {
        $users->deleteUser($userId);
        $flash = 'User deleted successfully.';
      } else {
        $flash = 'User delete cancelled.';
      }
    } catch (Throwable $e) {
      $error = 'Failed to delete user: ' . $e->getMessage();
    }
  }
}

if ($action === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_guest_self_service_settings'])) {
  if (!$isAdmin) {
    $error = 'Admin access required.';
  } else {
    $guestSelfServiceEnabled = ((string) ($_POST['guest_self_service_enabled'] ?? '0')) === '1';
    $guestSelfServiceAllowEarlyCheckout = ((string) ($_POST['guest_self_service_allow_early_checkout'] ?? '0')) === '1';
    $guestSelfServiceAllowExtension = ((string) ($_POST['guest_self_service_allow_extension'] ?? '0')) === '1';
    $guestSelfServiceRequireReason = ((string) ($_POST['guest_self_service_require_reason'] ?? '0')) === '1';
    $guestSelfServiceMaxExtensionDays = max(1, min(30, (int) ($_POST['guest_self_service_max_extension_days'] ?? 7)));
    $guestSelfServiceApprovalMode = ((string) ($_POST['guest_self_service_approval_mode'] ?? 'manual')) === 'auto' ? 'auto' : 'manual';
    $guestSelfServiceMaxFailedAttempts = max(1, min(20, (int) ($_POST['guest_self_service_max_failed_attempts'] ?? 5)));
    $guestSelfServiceLockoutMinutes = max(1, min(120, (int) ($_POST['guest_self_service_lockout_minutes'] ?? 15)));
    $guestSelfServiceIpWindowMinutes = max(1, min(120, (int) ($_POST['guest_self_service_ip_window_minutes'] ?? 10)));
    $guestSelfServiceIpFailureThreshold = max(0, min(200, (int) ($_POST['guest_self_service_ip_failure_threshold'] ?? 20)));
    $guestSelfServiceAuthMode = ((string) ($_POST['guest_self_service_auth_mode'] ?? 'id_and_code')) === 'code_only'
      ? 'code_only'
      : 'id_and_code';

    try {
      $settings->set('guest_self_service_enabled', $guestSelfServiceEnabled ? '1' : '0');
      $settings->set('guest_self_service_allow_early_checkout', $guestSelfServiceAllowEarlyCheckout ? '1' : '0');
      $settings->set('guest_self_service_allow_extension', $guestSelfServiceAllowExtension ? '1' : '0');
      $settings->set('guest_self_service_require_reason', $guestSelfServiceRequireReason ? '1' : '0');
      $settings->set('guest_self_service_max_extension_days', (string) $guestSelfServiceMaxExtensionDays);
      $settings->set('guest_self_service_approval_mode', $guestSelfServiceApprovalMode);
      $settings->set('guest_self_service_max_failed_attempts', (string) $guestSelfServiceMaxFailedAttempts);
      $settings->set('guest_self_service_lockout_minutes', (string) $guestSelfServiceLockoutMinutes);
      $settings->set('guest_self_service_ip_window_minutes', (string) $guestSelfServiceIpWindowMinutes);
      $settings->set('guest_self_service_ip_failure_threshold', (string) $guestSelfServiceIpFailureThreshold);
      $settings->set('guest_self_service_auth_mode', $guestSelfServiceAuthMode);
      $flash = 'Guest self-service settings updated.';
    } catch (Throwable $e) {
      $error = 'Failed to save guest self-service settings: ' . $e->getMessage();
    }
  }
}

if ($action === 'manage_reservation' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guest_access_login'])) {
  $submittedAccessId = strtoupper(trim((string) ($_POST['guest_access_id'] ?? '')));
  $submittedCode = trim((string) ($_POST['guest_access_code'] ?? ''));
  $submittedCodeLookup = $submittedCode === '' ? '' : buildGuestAccessCodeLookup($submittedCode, $guestAccessCodeLookupPepper);
  $requiresAccessId = $guestSelfServiceAuthMode !== 'code_only';
  $eventAccessReference = $requiresAccessId
    ? ($submittedAccessId !== '' ? $submittedAccessId : 'UNKNOWN')
    : 'CODE_ONLY';
  $ipAddress = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
  if ($ipAddress !== '') {
    $ipParts = explode(',', $ipAddress);
    $ipAddress = trim((string) ($ipParts[0] ?? ''));
  }
  if ($ipAddress === '') {
    $ipAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
  }

  if (!$guestSelfServiceEnabled) {
    $error = 'Guest self-service is currently disabled by property settings.';
  } elseif (($requiresAccessId && $submittedAccessId === '') || $submittedCode === '') {
    $error = $requiresAccessId
      ? 'Guest Access ID and Access Code are required.'
      : 'Guest Access Code is required.';
  } else {
    if ($guestSelfServiceIpFailureThreshold > 0) {
      $recentFailures = $guests->countRecentAccessFailuresByIp($ipAddress, $guestSelfServiceIpWindowMinutes);
      if ($recentFailures >= $guestSelfServiceIpFailureThreshold) {
        $guests->recordGuestAccessEvent($eventAccessReference, $ipAddress, 'login_failure');
        $error = sprintf(
          'Too many failed attempts from this network. Please wait %d minute(s) and try again.',
          $guestSelfServiceIpWindowMinutes
        );
      }
    }

    if ($error === null) {
      $candidateGuest = $requiresAccessId
        ? $guests->findByAccessId($submittedAccessId)
        : $guests->findByAccessCodeLookup($submittedCodeLookup);
      if ($candidateGuest !== null) {
        $lockedUntilRaw = trim((string) ($candidateGuest['guest_access_locked_until'] ?? ''));
        if ($lockedUntilRaw !== '' && strtotime($lockedUntilRaw) !== false) {
          if (strtotime($lockedUntilRaw) > time()) {
            $error = 'Access is temporarily locked until ' . date('Y-m-d H:i', strtotime($lockedUntilRaw)) . '.';
          } else {
            $guests->resetGuestAccessAttempts((int) ($candidateGuest['id'] ?? 0));
            $candidateAccessId = trim((string) ($candidateGuest['guest_access_id'] ?? ''));
            $guests->recordGuestAccessEvent($candidateAccessId !== '' ? $candidateAccessId : $eventAccessReference, $ipAddress, 'unlock');
          }
        }
      }
    }

    if ($error === null) {
      $verifiedGuest = $requiresAccessId
        ? $guests->verifyGuestAccess($submittedAccessId, $submittedCode)
        : $guests->verifyGuestAccessByCodeLookup($submittedCodeLookup, $submittedCode);
      if ($verifiedGuest === null) {
        $error = $requiresAccessId ? 'Invalid Guest Access ID or code.' : 'Invalid Guest Access Code.';
        if (isset($candidateGuest) && is_array($candidateGuest)) {
          $failure = $guests->registerGuestAccessFailure(
            (int) ($candidateGuest['id'] ?? 0),
            $guestSelfServiceMaxFailedAttempts,
            $guestSelfServiceLockoutMinutes
          );
          $candidateAccessId = trim((string) ($candidateGuest['guest_access_id'] ?? ''));
          $guests->recordGuestAccessEvent($candidateAccessId !== '' ? $candidateAccessId : $eventAccessReference, $ipAddress, 'login_failure');
          if (($failure['locked'] ?? false) === true) {
            $guests->recordGuestAccessEvent($candidateAccessId !== '' ? $candidateAccessId : $eventAccessReference, $ipAddress, 'lockout');
            $error = 'Too many failed attempts. Access is locked until ' . date('Y-m-d H:i', strtotime((string) ($failure['locked_until'] ?? 'now'))) . '.';
          }
        } else {
          $guests->recordGuestAccessEvent($eventAccessReference, $ipAddress, 'login_failure');
        }
      } else {
        $guests->resetGuestAccessAttempts((int) ($verifiedGuest['id'] ?? 0));
        $verifiedAccessId = trim((string) ($verifiedGuest['guest_access_id'] ?? ''));
        $guests->recordGuestAccessEvent($verifiedAccessId !== '' ? $verifiedAccessId : $eventAccessReference, $ipAddress, 'login_success');
        $_SESSION['guest_access_guest_id'] = (int) ($verifiedGuest['id'] ?? 0);
        $guestAccessGuestId = (int) ($_SESSION['guest_access_guest_id'] ?? 0);
        $flash = 'Reservation access granted.';
      }
    }
  }
}

if ($action === 'manage_reservation' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_guest_self_service_request'])) {
  $guestAccessGuestId = (int) ($_SESSION['guest_access_guest_id'] ?? 0);
  if (!$guestSelfServiceEnabled) {
    $error = 'Guest self-service is currently disabled by property settings.';
  } elseif ($guestAccessGuestId <= 0) {
    $error = $guestSelfServiceAuthMode === 'code_only'
      ? 'Please access your reservation first using your Guest Access Code.'
      : 'Please access your reservation first using your Guest Access ID and code.';
  } else {
    $requestType = trim((string) ($_POST['request_type'] ?? ''));
    $requestedDepartureDate = trim((string) ($_POST['requested_departure_date'] ?? ''));
    $reason = trim((string) ($_POST['request_reason'] ?? ''));
    $guestAccessGuest = $guests->findById($guestAccessGuestId);

    if ($guestAccessGuest === null) {
      $error = 'Reservation not found.';
      unset($_SESSION['guest_access_guest_id']);
      $guestAccessGuestId = 0;
    } elseif (!in_array($requestType, ['early_checkout', 'extend_departure'], true)) {
      $error = 'Invalid request type.';
    } elseif ($requestedDepartureDate === '') {
      $error = 'Requested date is required.';
    } elseif ($guestSelfServiceRequireReason && $reason === '') {
      $error = 'A reason is required for this request.';
    } elseif ($requestType === 'early_checkout' && !$guestSelfServiceAllowEarlyCheckout) {
      $error = 'Early checkout requests are disabled for this property.';
    } elseif ($requestType === 'extend_departure' && !$guestSelfServiceAllowExtension) {
      $error = 'Departure extension requests are disabled for this property.';
    } else {
      $currentDepartureDate = (string) ($guestAccessGuest['departure_date'] ?? '');
      $currentArrivalDate = (string) ($guestAccessGuest['arrival_date'] ?? '');
      $today = date('Y-m-d');
      $guestId = (int) ($guestAccessGuest['id'] ?? 0);
      $guestSg = (int) ($guestAccessGuest['sg'] ?? 0);
      $guestUnit = (string) ($guestAccessGuest['unit'] ?? '');

      if (strtotime($requestedDepartureDate) === false) {
        $error = 'Requested date is not valid.';
      } elseif ($requestType === 'early_checkout') {
        if ($requestedDepartureDate < $today || $requestedDepartureDate < $currentArrivalDate || $requestedDepartureDate > $currentDepartureDate) {
          $error = 'Early checkout date must be between today and your current departure date.';
        }
      } else {
        $maxAllowed = date('Y-m-d', strtotime($currentDepartureDate . ' +' . $guestSelfServiceMaxExtensionDays . ' day'));
        if ($requestedDepartureDate <= $currentDepartureDate) {
          $error = 'Extension date must be after your current departure date.';
        } elseif ($requestedDepartureDate > $maxAllowed) {
          $error = sprintf('Extension date exceeds maximum allowed extension of %d day(s).', $guestSelfServiceMaxExtensionDays);
        } elseif ($guests->hasUnitDateConflict($guestUnit, $currentArrivalDate, $requestedDepartureDate, $guestId, $guestSg)) {
          $error = 'Requested extension conflicts with another reservation for this lot.';
        }
      }

      if ($error === null) {
        if ($guests->hasPendingSelfServiceRequest($guestId, $requestType)) {
          $error = 'A pending request of this type already exists.';
        } else {
          $requestStatus = $guestSelfServiceApprovalMode === 'auto' ? 'auto_approved' : 'pending';
          try {
            $guests->createSelfServiceRequest([
              'guest_id' => $guestId,
              'guest_name' => (string) ($guestAccessGuest['guest_name'] ?? ''),
              'unit' => $guestUnit,
              'sg' => $guestSg,
              'request_type' => $requestType,
              'current_departure_date' => $currentDepartureDate,
              'requested_departure_date' => $requestedDepartureDate,
              'reason' => $reason,
              'status' => $requestStatus,
              'requested_by_access_id' => (string) ($guestAccessGuest['guest_access_id'] ?? ''),
            ]);

            if ($requestStatus === 'auto_approved') {
              $updated = $guestAccessGuest;
              $updated['departure_date'] = $requestedDepartureDate;
              $checkoutTransitionNotes = [];
              if ($requestType === 'early_checkout' && $requestedDepartureDate <= date('Y-m-d')) {
                  $checkoutResult = runVacantCheckoutTransition(
                    $updated,
                    $vacantProfileOverridesBySg,
                    $modems,
                    $gunslinger,
                    $ddnet,
                    $snmp,
                    $guests,
                    'guest_self_service'
                  );
                if (!($checkoutResult['ok'] ?? false)) {
                  throw new RuntimeException('Early checkout modem transition failed: ' . (string) ($checkoutResult['error'] ?? 'unknown'));
                }
                $updated['submission_status'] = 'checked_out';
                $updated['profile_applied'] = (string) ($checkoutResult['profile'] ?? (string) ($updated['profile_applied'] ?? ''));
                $updated['modem_mac'] = (string) ($checkoutResult['modem_mac'] ?? (string) ($updated['modem_mac'] ?? ''));
                $checkoutTransitionNotes = (array) ($checkoutResult['notes'] ?? []);
              }
              $existingNotes = trim((string) ($updated['notes'] ?? ''));
              $note = sprintf('[Guest Self-Service %s] %s requested %s.', date('Y-m-d H:i:s'), (string) ($guestAccessGuest['guest_access_id'] ?? ''), $requestType);
              $notesParts = [];
              if ($existingNotes !== '') {
                $notesParts[] = $existingNotes;
              }
              $notesParts[] = $note;
              foreach ($checkoutTransitionNotes as $checkoutNote) {
                $checkoutNote = trim((string) $checkoutNote);
                if ($checkoutNote !== '') {
                  $notesParts[] = $checkoutNote;
                }
              }
              $updated['notes'] = implode(' | ', $notesParts);
              $guests->updateById((int) $updated['id'], [
                'guest_name' => (string) $updated['guest_name'],
                'phone' => (string) $updated['phone'],
                'unit' => (string) $updated['unit'],
                'sg' => (int) $updated['sg'],
                'modem_mac' => (string) $updated['modem_mac'],
                'arrival_date' => (string) $updated['arrival_date'],
                'departure_date' => (string) $updated['departure_date'],
                'profile_applied' => (string) $updated['profile_applied'],
                'submission_status' => (string) ($updated['submission_status'] ?? 'submitted'),
                'notes' => (string) $updated['notes'],
              ]);
              $flash = 'Request auto-approved and reservation updated.';
            } else {
              $flash = 'Your request was submitted and is pending staff review.';
            }
          } catch (Throwable $e) {
            $error = 'Failed to submit request: ' . $e->getMessage();
          }
        }
      }
    }
  }
}

if ($action === 'guest_requests' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decide_guest_request'])) {
  if (!$isPropertyUser) {
    $error = 'Property user access required.';
  } else {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $decision = trim((string) ($_POST['decision'] ?? ''));
    $decisionNote = trim((string) ($_POST['decision_note'] ?? ''));
    $actor = (string) ($currentUser['username'] ?? 'staff');

    if (!in_array($decision, ['approve', 'deny'], true)) {
      $error = 'Invalid request decision.';
    } else {
      $request = $guests->findSelfServiceRequestById($requestId);
      if ($request === null) {
        $error = 'Request not found.';
      } elseif ((string) ($request['status'] ?? '') !== 'pending') {
        $error = 'Only pending requests can be decided.';
      } else {
        try {
          if ($decision === 'approve') {
            $guest = $guests->findById((int) ($request['guest_id'] ?? 0));
            if ($guest === null) {
              $error = 'Related guest reservation not found.';
            } else {
              $requestType = (string) ($request['request_type'] ?? '');
              $requestedDate = (string) ($request['requested_departure_date'] ?? '');
              $currentDepartureDate = (string) ($guest['departure_date'] ?? '');
              $currentArrivalDate = (string) ($guest['arrival_date'] ?? '');
              $today = date('Y-m-d');

              if (strtotime($requestedDate) === false) {
                $error = 'Requested date is not valid.';
              } elseif ((string) ($guest['submission_status'] ?? '') === 'checked_out') {
                $error = 'Cannot approve requests for a checked-out reservation.';
              } elseif ($requestType === 'early_checkout') {
                if ($requestedDate < $today || $requestedDate < $currentArrivalDate || $requestedDate > $currentDepartureDate) {
                  $error = 'Early checkout date is no longer valid for this reservation.';
                }
              } elseif ($requestType === 'extend_departure') {
                $maxAllowed = date('Y-m-d', strtotime($currentDepartureDate . ' +' . $guestSelfServiceMaxExtensionDays . ' day'));
                if ($requestedDate <= $currentDepartureDate) {
                  $error = 'Extension date must be after current departure date.';
                } elseif ($requestedDate > $maxAllowed) {
                  $error = sprintf('Extension date exceeds maximum allowed extension of %d day(s).', $guestSelfServiceMaxExtensionDays);
                } elseif ($guests->hasUnitDateConflict((string) ($guest['unit'] ?? ''), $currentArrivalDate, $requestedDate, (int) ($guest['id'] ?? 0), (int) ($guest['sg'] ?? 0))) {
                  $error = 'Extension approval would conflict with another reservation for this lot.';
                }
              } else {
                $error = 'Unsupported request type.';
              }

              if ($error === null) {
                $updatedStatus = (string) ($guest['submission_status'] ?? 'submitted');
                $updatedProfileApplied = (string) ($guest['profile_applied'] ?? '');
                $updatedModemMac = (string) ($guest['modem_mac'] ?? '');
                $checkoutTransitionNotes = [];
                if ($requestType === 'early_checkout' && $requestedDate <= date('Y-m-d')) {
                  $checkoutResult = runVacantCheckoutTransition(
                    $guest,
                    $vacantProfileOverridesBySg,
                    $modems,
                    $gunslinger,
                    $ddnet,
                    $snmp,
                    $guests,
                    $actor
                  );
                  if (!($checkoutResult['ok'] ?? false)) {
                    $error = 'Early checkout modem transition failed: ' . (string) ($checkoutResult['error'] ?? 'unknown');
                  } else {
                    $updatedStatus = 'checked_out';
                    $updatedProfileApplied = (string) ($checkoutResult['profile'] ?? $updatedProfileApplied);
                    $updatedModemMac = (string) ($checkoutResult['modem_mac'] ?? $updatedModemMac);
                    $checkoutTransitionNotes = (array) ($checkoutResult['notes'] ?? []);
                  }
                }
              }

              if ($error === null) {
                $existingNotes = trim((string) ($guest['notes'] ?? ''));
                $note = sprintf('[Guest Request %s] %s approved by %s.', date('Y-m-d H:i:s'), $requestType, $actor);
                if ($decisionNote !== '') {
                  $note .= ' Note: ' . $decisionNote;
                }
                $notesParts = [];
                if ($existingNotes !== '') {
                  $notesParts[] = $existingNotes;
                }
                $notesParts[] = $note;
                foreach ($checkoutTransitionNotes as $checkoutNote) {
                  $checkoutNote = trim((string) $checkoutNote);
                  if ($checkoutNote !== '') {
                    $notesParts[] = $checkoutNote;
                  }
                }
                $combinedNotes = implode(' | ', $notesParts);

                $guests->updateById((int) $guest['id'], [
                  'guest_name' => (string) $guest['guest_name'],
                  'phone' => (string) $guest['phone'],
                  'unit' => (string) $guest['unit'],
                  'sg' => (int) $guest['sg'],
                  'modem_mac' => $updatedModemMac,
                  'arrival_date' => (string) $guest['arrival_date'],
                  'departure_date' => $requestedDate,
                  'profile_applied' => $updatedProfileApplied,
                  'submission_status' => $updatedStatus,
                  'notes' => $combinedNotes,
                ]);
                $guests->decideSelfServiceRequest($requestId, 'approved', $decisionNote, $actor);
                $flash = 'Guest self-service request approved and reservation updated.';
              }
            }
          } else {
            $guests->decideSelfServiceRequest($requestId, 'denied', $decisionNote, $actor);
            $flash = 'Guest self-service request denied.';
          }
        } catch (Throwable $e) {
          $error = 'Failed to process request decision: ' . $e->getMessage();
        }
      }
    }
  }
}

if ($action === 'account' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_own_password'])) {
  if (!$isPropertyUser || !$isLoggedIn) {
    $error = 'Property user access required.';
  } else {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmNewPassword = (string) ($_POST['confirm_new_password'] ?? '');

    if (trim($currentPassword) === '' || trim($newPassword) === '' || trim($confirmNewPassword) === '') {
      $error = 'All password fields are required.';
    } elseif ($newPassword !== $confirmNewPassword) {
      $error = 'New password and confirmation do not match.';
    } else {
      try {
        $users->changeOwnPassword((int) ($currentUser['id'] ?? 0), $currentPassword, $newPassword);
        $flash = 'Your password was updated successfully.';
      } catch (Throwable $e) {
        $error = 'Failed to update your password: ' . $e->getMessage();
      }
    }
  }
}

if ($action === 'edit_guest' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$isPropertyUser) {
    $error = 'Property user access required.';
  } else {
    $guestId = (int) ($_POST['guest_id'] ?? 0);
    $guestName = trim((string) ($_POST['guest_name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $unit = trim((string) ($_POST['unit'] ?? ''));
    $arrival = trim((string) ($_POST['arrival_date'] ?? ''));
    $departure = trim((string) ($_POST['departure_date'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $checkoutNow = (string) ($_POST['checkout_now'] ?? '') === '1';

    if ($guestId <= 0 || $guestName === '' || $phone === '' || $unit === '' || $arrival === '' || $departure === '') {
      $error = 'All guest edit fields except notes are required.';
    } elseif (strtotime($departure) < strtotime($arrival)) {
      $error = 'Departure Date must be on or after Arrival Date.';
    } elseif ($guests->hasUnitDateConflict($unit, $arrival, $departure, $guestId)) {
      $error = 'That lot already has another guest registered for overlapping dates.';
    } else {
      $existingGuest = $guests->findById($guestId);
      if ($existingGuest === null) {
        $error = 'Guest record not found.';
      } else {
        $modem = $modems->findByUnitAndGroups($unit, $serviceGroups);
        if ($modem === null) {
          $error = 'No modem record found for the selected lot number in configured service groups.';
        } else {
          $defaultProfileForModemSg = (string) ($defaultProfilesBySg[(int) $modem['sg']] ?? $defaultProfile);
          $profileToApply = (string) ($existingGuest['profile_applied'] ?? $defaultProfileForModemSg);
          $submissionStatus = (string) ($existingGuest['submission_status'] ?? 'submitted');
          $isCheckoutByDate = strtotime($departure) <= strtotime(date('Y-m-d'));
          if ($checkoutNow || $isCheckoutByDate) {
            $departure = date('Y-m-d');
            $checkoutGuest = $existingGuest;
            $checkoutGuest['unit'] = $unit;
            $checkoutGuest['sg'] = (int) $modem['sg'];
            $checkoutGuest['modem_mac'] = strtolower((string) $modem['mac']);
            $checkoutResult = runVacantCheckoutTransition(
              $checkoutGuest,
              $vacantProfileOverridesBySg,
              $modems,
              $gunslinger,
              $ddnet,
              $snmp,
              $guests,
              (string) ($currentUser['username'] ?? 'staff')
            );
            if (!($checkoutResult['ok'] ?? false)) {
              $error = 'Failed to reset lot during checkout edit: ' . (string) ($checkoutResult['error'] ?? 'unknown');
            } else {
              $profileToApply = (string) ($checkoutResult['profile'] ?? $profileToApply);
              $submissionStatus = 'checked_out';
              foreach ((array) ($checkoutResult['notes'] ?? []) as $checkoutNote) {
                $checkoutNote = trim((string) $checkoutNote);
                if ($checkoutNote !== '') {
                  $notes = trim($notes) === '' ? $checkoutNote : ($notes . ' | ' . $checkoutNote);
                }
              }
            }
          }

          if ($error !== null) {
            // Skip DB update when forced vacant profile reset fails.
          } else {
          try {
            $guests->updateById($guestId, [
              'guest_name' => $guestName,
              'phone' => $phone,
              'unit' => $unit,
              'sg' => (int) $modem['sg'],
              'modem_mac' => strtolower((string) $modem['mac']),
              'arrival_date' => $arrival,
              'departure_date' => $departure,
              'profile_applied' => $profileToApply,
              'submission_status' => $submissionStatus,
              'notes' => $notes,
            ]);
            $flash = $checkoutNow ? 'Guest checked out and reservation updated.' : 'Guest information updated.';
            $action = 'registration_list';
          } catch (Throwable $e) {
            $error = 'Failed to update guest: ' . $e->getMessage();
          }
          }
        }
      }
    }
  }
}

if ($action === 'admin' && $isPropertyUser && !$isAdmin) {
  $action = 'registration_list';
}

$refresh = ['ok' => true, 'rows' => []];
$sessionFlash = (string) ($_SESSION['flash_message'] ?? '');
if ($sessionFlash !== '') {
  $flash = $sessionFlash;
  unset($_SESSION['flash_message']);
}
$sessionRegistrationConfirmation = $_SESSION['registration_confirmation'] ?? null;
if ($action === 'register_confirmation' && is_array($sessionRegistrationConfirmation)) {
  $registrationConfirmation = $sessionRegistrationConfirmation;
  unset($_SESSION['registration_confirmation']);
}
$shouldRefresh = $action === 'home' || $action === 'register';
if ($shouldRefresh) {
  $refresh = $gunslinger->refreshCustomers($serviceGroups);
  if (($refresh['ok'] ?? false) && !empty($refresh['rows'])) {
      $rows = (array) ($refresh['rows'] ?? []);
      $modems->replaceByServiceGroups($rows, $serviceGroups);
      syncLeaseStatusesFromRows($rows, $serviceGroups, $ddnet, $modems);
  } else {
      $leaseCandidates = $modems->listLeaseCheckCandidatesByServiceGroups($serviceGroups, 1000);
      syncLeaseStatusesFromRows($leaseCandidates, $serviceGroups, $ddnet, $modems);
  }
}

if (!$isPropertyUser && in_array($action, ['home', 'register'], true)) {
  $guestAutoLotIp = detectClientIpAddress();
  if ($guestAutoLotIp === '' || filter_var($guestAutoLotIp, FILTER_VALIDATE_IP) === false) {
    $guestAutoLotError = 'Unable to determine your connection IP for automatic lot assignment.';
  } else {
    $reservationByIpResult = $ddnet->reservationByCpeIp($guestAutoLotIp);
    if (!($reservationByIpResult['ok'] ?? false)) {
      $guestAutoLotError = 'Unable to identify your lot from DDNet by IP lookup.';
    } else {
      $guestAutoLotMac = strtolower(trim((string) ($reservationByIpResult['modem_mac'] ?? '')));
      $modemFromIp = $modems->findByMacAndGroups($guestAutoLotMac, $serviceGroups);
      if ($modemFromIp === null) {
        $guestAutoLotError = 'Unable to match your modem to a lot in the local modem cache.';
      } else {
        $guestAutoLotUnit = trim((string) ($modemFromIp['unit'] ?? ''));
        $guestAutoLotSg = (int) ($modemFromIp['sg'] ?? 0);
        if ($guestAutoLotUnit === '' || $guestAutoLotSg <= 0) {
          $guestAutoLotError = 'Detected modem record is missing lot mapping.';
        } else {
          $guestAutoLotSelection = $guestAutoLotSg . '|' . $guestAutoLotUnit;
          $guestAutoLotError = null;
        }
      }
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'register') {
    $guestName = trim((string) ($_POST['guest_name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $lotSelection = trim((string) ($_POST['lot_selection'] ?? ''));
    $unit = '';
    $selectedSg = 0;
    if ($lotSelection !== '' && strpos($lotSelection, '|') !== false) {
      [$sgRaw, $unitRaw] = explode('|', $lotSelection, 2);
      $selectedSg = (int) $sgRaw;
      $unit = trim((string) $unitRaw);
    } else {
      $unit = trim((string) ($_POST['unit'] ?? ''));
    }

    if (!$isPropertyUser) {
      if ($guestAutoLotSelection !== '' && $guestAutoLotUnit !== '' && $guestAutoLotSg > 0) {
        $lotSelection = $guestAutoLotSelection;
        $unit = $guestAutoLotUnit;
        $selectedSg = $guestAutoLotSg;
      }
    }

    $arrival = trim((string) ($_POST['arrival_date'] ?? ''));
    $departure = trim((string) ($_POST['departure_date'] ?? ''));
    $overrideConflict = $isAdmin && (string) ($_POST['override_lot_conflict'] ?? '') === '1';

    if ($guestName === '' || $phone === '' || $unit === '' || $arrival === '' || $departure === '') {
        $error = 'All form fields are required.';
    } elseif (strtotime($departure) < strtotime($arrival)) {
        $error = 'Departure Date must be on or after Arrival Date.';
    }

    if ($error === null) {
      if ($selectedSg > 0) {
        $modem = $modems->findByUnitAndServiceGroup($unit, $selectedSg);
      } else {
        $modem = $modems->findByUnitAndGroups($unit, $serviceGroups);
      }

      if ($modem === null) {
        $error = 'No modem record found for the selected lot number in configured service groups.';
      } elseif ($guests->hasUnitDateConflict($unit, $arrival, $departure, null, (int) $modem['sg'])) {
        if ($overrideConflict) {
          $actor = $isLoggedIn ? (string) ($currentUser['username'] ?? 'admin') : 'admin';
          $guests->closeUnitConflictsEarly($unit, $arrival, $departure, $actor, (int) $modem['sg']);
          if ($guests->hasUnitDateConflict($unit, $arrival, $departure, null, (int) $modem['sg'])) {
            $error = 'Admin override could not fully clear conflict for this lot and period. Please edit the existing guest record and retry.';
          }
        } else {
          $error = 'That lot is already registered to another guest for overlapping dates. Please choose another lot or dates.';
        }
      }
    }

    if ($error === null && isset($modem) && $modem !== null) {
          $defaultProfileForModemSg = (string) ($defaultProfilesBySg[(int) $modem['sg']] ?? $defaultProfile);
          $profilePrefixValid = profileMatchesServiceGroup($defaultProfileForModemSg, (int) $modem['sg']);
          if (!$profilePrefixValid) {
            $error = sprintf(
              'Configured default profile "%s" must start with %02d for service group %d. Update it in Admin Settings.',
              $defaultProfileForModemSg,
              (int) $modem['sg'],
              (int) $modem['sg']
            );
          } else {
          $profileResult = $gunslinger->updateProfile((int) $modem['sg'], $unit, (string) $modem['mac'], $defaultProfileForModemSg);
            $bootfile = $profileBootfiles->findBootfileFilename((int) $modem['sg'], $defaultProfileForModemSg);
            if ($bootfile === null) {
              $bootfileSyncResult = $gunslinger->fetchWorkingProfileBootfiles(
                [(int) $modem['sg']],
                [(int) $modem['sg'] => $defaultProfileForModemSg]
              );
              if (($bootfileSyncResult['ok'] ?? false) === true) {
                $profileBootfiles->upsertMappings((array) ($bootfileSyncResult['rows'] ?? []));
                $bootfile = $profileBootfiles->findBootfileFilename((int) $modem['sg'], $defaultProfileForModemSg);
              }
            }

            if ($bootfile === null) {
              $reservationResult = [
                'ok' => false,
                'error' => sprintf(
                  'Missing cached bootfile mapping for SG %d working profile %s. Run modem/lot refresh sync to populate profile bootfiles.',
                  (int) $modem['sg'],
                  $defaultProfileForModemSg
                ),
              ];
            } else {
              $reservationResult = $ddnet->upsertReservationBootfile((string) $modem['mac'], $bootfile);
            }

            $modemScopedReservationResult = $ddnet->modemScopedReservationsCreate([
              'mac_address' => strtolower((string) $modem['mac']),
              'dhcp_options' => [
                '6' => ['1.1.1.1', '8.8.8.8'],
                '51' => 3600,
              ],
              'description' => trim($guestName . ' - Lot ' . $unit),
            ]);

            $ip = trim((string) ($modem['lease_ip'] ?? ''));
            if ($ip === '') {
              $ip = null;
            }
            $rebootIpCandidates = buildRebootIpCandidates([
              (string) ($modem['lease_ip'] ?? ''),
            ]);
            if (($reservationResult['ok'] ?? false) !== true) {
              $snmpResult = ['ok' => false, 'error' => 'SNMP reboot skipped because DDNet reservation failed.'];
            } else {
              $snmpResult = $rebootIpCandidates !== []
                ? $snmp->rebootWithCandidates($rebootIpCandidates)
                : ['ok' => false, 'error' => 'No reboot IP candidates available'];
            }

            $status = 'submitted';
            $notes = [];

            if (!$profileResult['ok']) {
                $status = 'partial_failure';
              $detail = trim((string) ($profileResult['error'] ?? ''));
              $notes[] = $detail === '' ? 'Gunslinger profile update failed' : ('Gunslinger profile update failed: ' . $detail);
            }

            if (!(($reservationResult['ok'] ?? false) === true)) {
              $status = 'partial_failure';
              $notes[] = 'DDNet reservation failed: ' . (string) ($reservationResult['error'] ?? 'unknown');
            }

            if (!(($modemScopedReservationResult['ok'] ?? false) === true)) {
              $status = 'partial_failure';
              $notes[] = 'DDNet modem scoped reservation failed: ' . (string) ($modemScopedReservationResult['error'] ?? 'unknown');
            }

            if (!($snmpResult['ok'] ?? false)) {
                $status = 'partial_failure';
              $notes[] = buildSnmpFailureNote($snmpResult);
            }

            $guestId = $guests->create([
                ':guest_name' => $guestName,
                ':phone' => $phone,
                ':unit' => $unit,
                ':sg' => (int) $modem['sg'],
                ':modem_mac' => strtolower((string) $modem['mac']),
                ':arrival_date' => $arrival,
                ':departure_date' => $departure,
                ':profile_applied' => $defaultProfileForModemSg,
                ':dhcp_ip' => $ip,
                ':submission_status' => $status,
                ':notes' => implode(' | ', $notes),
            ]);

            try {
              $modemScopedLogStatus = (($modemScopedReservationResult['ok'] ?? false) === true) ? 'submitted' : 'failed';
              $modemScopedLogDetails = (($modemScopedReservationResult['ok'] ?? false) === true)
                ? ('Created via ' . (string) ($modemScopedReservationResult['path'] ?? 'DDNet endpoint'))
                : (string) ($modemScopedReservationResult['error'] ?? 'unknown');
              $guests->addModemScopedReservationLog([
                'guest_id' => $guestId,
                'guest_name' => $guestName,
                'unit' => $unit,
                'sg' => (int) $modem['sg'],
                'modem_mac' => strtolower((string) $modem['mac']),
                'client_ip' => detectClientIpAddress(),
                'status' => $modemScopedLogStatus,
                'details' => $modemScopedLogDetails,
                'actor' => $isLoggedIn ? (string) ($currentUser['username'] ?? 'staff') : 'guest',
              ]);
            } catch (Throwable $e) {
              // Non-blocking log write.
            }

            $guestAccessCode = '';
            $guestAccessCodeLookup = '';
            for ($attempt = 0; $attempt < 12; $attempt++) {
              $candidateCode = generateGuestAccessCode(10);
              $candidateLookup = buildGuestAccessCodeLookup($candidateCode, $guestAccessCodeLookupPepper);
              if ($guests->isGuestAccessCodeLookupAvailable($candidateLookup)) {
                $guestAccessCode = $candidateCode;
                $guestAccessCodeLookup = $candidateLookup;
                break;
              }
            }
            $guestCodeHash = $guestAccessCode !== '' ? password_hash($guestAccessCode, PASSWORD_DEFAULT) : '';
            $guestCodeExpiryAt = date('Y-m-d 23:59:59', strtotime($departure . ' +1 day'));

            $guestAccessId = '';
            for ($attempt = 0; $attempt < 6; $attempt++) {
              $candidate = generateGuestAccessId();
              if ($guests->isGuestAccessIdAvailable($candidate)) {
                $guestAccessId = $candidate;
                break;
              }
            }

            if ($guestAccessId === '' || $guestAccessCode === '' || $guestCodeHash === '' || $guestAccessCodeLookup === '') {
              $error = 'Unable to generate unique guest access credentials. Please retry registration.';
            } else {
              $guests->setGuestAccessCredentials($guestId, $guestAccessId, $guestCodeHash, $guestAccessCodeLookup, $guestCodeExpiryAt);
            }

            if ($error === null) {
              $guestSupportMessage = null;
              if ($status === 'partial_failure') {
                $snmpSucceeded = ($snmpResult['ok'] ?? false) === true;
                if (!$snmpSucceeded) {
                  $guestSupportMessage = 'Registration completed, but this modem appears offline or still provisioning. Please contact office staff for assistance.';
                } else {
                  $guestSupportMessage = 'Your reservation is confirmed, but one network provisioning step needs office staff review.';
                }
              }
              $_SESSION['registration_confirmation'] = [
                'guest_name' => $guestName,
                'phone' => $phone,
                'unit' => $unit,
                'sg' => (int) $modem['sg'],
                'modem_mac' => strtolower((string) $modem['mac']),
                'arrival_date' => $arrival,
                'departure_date' => $departure,
                'profile_applied' => $defaultProfileForModemSg,
                'dhcp_ip' => $ip,
                'submission_status' => $status,
                'notes' => implode(' | ', $notes),
                'guest_support_message' => $guestSupportMessage,
                'modem_reboot_notice' => (($snmpResult['ok'] ?? false) === true)
                  ? 'Your reservation is confirmed. Your modem is now rebooting, and internet service should be available in about 2-3 minutes.'
                  : '',
                'completed_at' => date('Y-m-d H:i:s'),
                'guest_access_id' => $guestAccessId,
                'guest_access_code' => $guestAccessCode,
                'guest_access_expires_at' => $guestCodeExpiryAt,
              ];
              header('Location: /?action=register_confirmation');
              exit;
            }
          }
    }
}

$units = $modems->listUnits($serviceGroups);
$unitsByServiceGroup = $modems->listUnitsByServiceGroup($serviceGroups);

if ($action === 'upcoming_registrations' && $isPropertyUser) {
  $upcomingWindowDays = trim((string) ($_GET['window_days'] ?? '30'));
  if (!in_array($upcomingWindowDays, ['7', '14', '30', '60', '90', 'all'], true)) {
    $upcomingWindowDays = '30';
  }
  $upcomingWindowCutoffDate = $upcomingWindowDays === 'all'
    ? null
    : date('Y-m-d', strtotime('+' . (int) $upcomingWindowDays . ' days'));
}

if ($action === 'registration_list' && $isPropertyUser) {
  $activeGuestStatusFilter = trim((string) ($_GET['status_filter'] ?? 'all'));
  if (!in_array($activeGuestStatusFilter, ['all', 'action_needed'], true)) {
    $activeGuestStatusFilter = 'all';
  }
}

$registrationRows = ($action === 'registration_list' && $isPropertyUser) ? $guests->registrationListByLot() : [];
$registrationRowsBySg = [];
$provisioningActionItems = [];
if ($registrationRows !== []) {
  $activeGuestTotalCount = count($registrationRows);
  foreach ($registrationRows as $row) {
    $sgKey = (int) ($row['sg'] ?? 0);
    $statusRaw = trim((string) ($row['submission_status'] ?? ''));
    if ($statusRaw === 'partial_failure') {
      $activeGuestActionNeededCount++;
      $issues = parseProvisioningFailureReasons((string) ($row['notes'] ?? ''));
      $provisioningActionItems[] = [
        'guest_name' => (string) ($row['guest_name'] ?? ''),
        'unit' => (string) ($row['unit'] ?? ''),
        'sg' => $sgKey,
        'issues' => $issues,
      ];
    }

    if ($activeGuestStatusFilter === 'action_needed' && $statusRaw !== 'partial_failure') {
      continue;
    }

    if (!isset($registrationRowsBySg[$sgKey])) {
      $registrationRowsBySg[$sgKey] = [];
    }
    $registrationRowsBySg[$sgKey][] = $row;
    $activeGuestVisibleCount++;
  }
  ksort($registrationRowsBySg);
}
$upcomingRegistrationRows = ($action === 'upcoming_registrations' && $isPropertyUser)
  ? $guests->upcomingRegistrationListByLot(500, $upcomingWindowCutoffDate)
  : [];
$upcomingRegistrationRowsBySg = [];
if ($upcomingRegistrationRows !== []) {
  foreach ($upcomingRegistrationRows as $row) {
    $sgKey = (int) ($row['sg'] ?? 0);
    if (!isset($upcomingRegistrationRowsBySg[$sgKey])) {
      $upcomingRegistrationRowsBySg[$sgKey] = [];
    }
    $upcomingRegistrationRowsBySg[$sgKey][] = $row;
  }
  ksort($upcomingRegistrationRowsBySg);
}
$historyRows = ($action === 'history_report' && $isPropertyUser) ? $guests->reportHistory() : [];
$usersList = ($action === 'admin' && $isAdmin) ? $users->listUsers() : [];
$reprovisionLogs = ($action === 'admin' && $isAdmin) ? $guests->listReprovisionLogs(200) : [];
$modemScopedReservationLogs = ($action === 'admin' && $isAdmin) ? $guests->listModemScopedReservationLogs(200) : [];
if ($isPropertyUser) {
  $hasPendingGuestChangeRequests = $guests->countSelfServiceRequestsFiltered(['status' => 'pending']) > 0;
  if ($action === 'admin') {
    $uncheckedModemCount = $modems->countUncheckedLeaseByServiceGroups($serviceGroups);
    if ($uncheckedModemCount > 0) {
      $leaseCandidates = $modems->listLeaseCheckCandidatesByServiceGroups($serviceGroups, 1000);
      syncLeaseStatusesFromRows($leaseCandidates, $serviceGroups, $ddnet, $modems);
    }

    $offlineModemCount = $modems->countOfflineLeaseByServiceGroups($serviceGroups);
    $offlineModemRows = $modems->listOfflineLeaseByServiceGroups($serviceGroups, 200);
  }
}
$guestRequestRows = [];
if ($action === 'guest_requests' && $isPropertyUser) {
  $guestRequestFilterStatus = trim((string) ($_GET['status'] ?? 'all'));
  if (!in_array($guestRequestFilterStatus, ['all', 'pending', 'approved', 'denied', 'auto_approved'], true)) {
    $guestRequestFilterStatus = 'all';
  }

  $guestRequestFilterType = trim((string) ($_GET['request_type'] ?? 'all'));
  if (!in_array($guestRequestFilterType, ['all', 'early_checkout', 'extend_departure'], true)) {
    $guestRequestFilterType = 'all';
  }

  $guestRequestFilterSg = (int) ($_GET['sg'] ?? 0);
  $allowedSgs = array_map('intval', $serviceGroups);
  if ($guestRequestFilterSg !== 0 && !in_array($guestRequestFilterSg, $allowedSgs, true)) {
    $guestRequestFilterSg = 0;
  }

  $guestRequestFilterDateFrom = trim((string) ($_GET['date_from'] ?? ''));
  if ($guestRequestFilterDateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $guestRequestFilterDateFrom) !== 1) {
    $guestRequestFilterDateFrom = '';
  }

  $guestRequestFilterDateTo = trim((string) ($_GET['date_to'] ?? ''));
  if ($guestRequestFilterDateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $guestRequestFilterDateTo) !== 1) {
    $guestRequestFilterDateTo = '';
  }

  $guestRequestFilterSearch = trim((string) ($_GET['search'] ?? ''));
  $guestRequestSort = trim((string) ($_GET['sort'] ?? 'created_desc'));
  if (!in_array($guestRequestSort, ['created_desc', 'created_asc', 'requested_desc', 'requested_asc', 'age_desc', 'age_asc'], true)) {
    $guestRequestSort = 'created_desc';
  }

  $guestRequestPerPage = (int) ($_GET['per_page'] ?? 50);
  if (!in_array($guestRequestPerPage, [25, 50, 100, 200], true)) {
    $guestRequestPerPage = 50;
  }

  $guestRequestPage = max(1, (int) ($_GET['page'] ?? 1));

  $requestFilters = [
    'status' => $guestRequestFilterStatus,
    'request_type' => $guestRequestFilterType,
    'sg' => $guestRequestFilterSg,
    'date_from' => $guestRequestFilterDateFrom,
    'date_to' => $guestRequestFilterDateTo,
    'search' => $guestRequestFilterSearch,
    'sort' => $guestRequestSort,
  ];

  $guestRequestTotalRows = $guests->countSelfServiceRequestsFiltered($requestFilters);
  $guestRequestTotalPages = max(1, (int) ceil($guestRequestTotalRows / max(1, $guestRequestPerPage)));
  if ($guestRequestPage > $guestRequestTotalPages) {
    $guestRequestPage = $guestRequestTotalPages;
  }
  $guestRequestOffset = ($guestRequestPage - 1) * $guestRequestPerPage;

  $guestRequestRows = $guests->listSelfServiceRequestsFiltered($requestFilters, $guestRequestPerPage, $guestRequestOffset);
  $guestRequestQueueMetrics = $guests->getSelfServiceQueueMetrics();
  $openGuestRequestsPanel = ($flash !== null) || ($error !== null);

  $exportMode = trim((string) ($_GET['export'] ?? ''));
  if ($exportMode === 'csv') {
    $exportRows = $guests->listSelfServiceRequestsFiltered($requestFilters, 5000, 0);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="guest_requests_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    if ($out !== false) {
      fputcsv($out, ['id', 'created_at', 'age_minutes', 'guest_name', 'unit', 'sg', 'request_type', 'current_departure_date', 'requested_departure_date', 'status', 'reason', 'decision_note', 'requested_by_access_id', 'decided_by', 'decided_at']);
      foreach ($exportRows as $row) {
        $createdAt = (string) ($row['created_at'] ?? '');
        $ageMinutes = '';
        if ($createdAt !== '' && strtotime($createdAt) !== false) {
          $ageMinutes = (string) max(0, (int) floor((time() - strtotime($createdAt)) / 60));
        }
        fputcsv($out, [
          (int) ($row['id'] ?? 0),
          $createdAt,
          $ageMinutes,
          (string) ($row['guest_name'] ?? ''),
          (string) ($row['unit'] ?? ''),
          (int) ($row['sg'] ?? 0),
          (string) ($row['request_type'] ?? ''),
          (string) ($row['current_departure_date'] ?? ''),
          (string) ($row['requested_departure_date'] ?? ''),
          (string) ($row['status'] ?? ''),
          (string) ($row['reason'] ?? ''),
          (string) ($row['decision_note'] ?? ''),
          (string) ($row['requested_by_access_id'] ?? ''),
          (string) ($row['decided_by'] ?? ''),
          (string) ($row['decided_at'] ?? ''),
        ]);
      }
      fclose($out);
    }
    exit;
  }
}
$vacantProfileLogs = ($action === 'admin' && $isAdmin) ? $guests->listVacantProfileLogs(200) : [];
$guestAccessEventLogs = ($action === 'admin' && $isAdmin) ? $guests->listGuestSelfServiceAccessEvents(200) : [];
$captivePortalApiLogs = ($action === 'admin' && $isAdmin) ? $guests->listCaptivePortalApiLogs(200) : [];
$snmpAuditLogLines = ($action === 'admin' && $isAdmin)
  ? readLogTailLines($config->get('SNMP_AUDIT_LOG_PATH', '/var/www/html/storage/logs/snmp_audit.log'), 200)
  : [];
$editGuest = ($action === 'edit_guest' && $isPropertyUser) ? $guests->findById((int) ($_GET['id'] ?? 0)) : null;

if ($action === 'manage_reservation' && $guestAccessGuestId > 0) {
  $guestAccessGuest = $guests->findById($guestAccessGuestId);
  if ($guestAccessGuest === null) {
    unset($_SESSION['guest_access_guest_id']);
    $guestAccessGuestId = 0;
  }
}
$guestAccessRequests = ($action === 'manage_reservation' && $guestAccessGuestId > 0)
  ? $guests->listSelfServiceRequestsForGuest($guestAccessGuestId, 50)
  : [];

$styleAssetVersion = '1';
$styleAssetPath = __DIR__ . DIRECTORY_SEPARATOR . 'style.css';
if (is_file($styleAssetPath)) {
  $styleAssetVersion = (string) filemtime($styleAssetPath);
}
$appVersion = trim($config->get('APP_VERSION', 'dev'));
$appBuildDate = trim($config->get('APP_BUILD_DATE', ''));
$appImageTag = trim($config->get('APP_IMAGE_TAG', ''));
$versionParts = ['SGR ' . ($appVersion !== '' ? $appVersion : 'dev')];
if ($isMasterAdmin && $appBuildDate !== '') {
  $versionParts[] = 'build ' . $appBuildDate;
}
if ($isMasterAdmin && $appImageTag !== '') {
  $versionParts[] = 'image ' . $appImageTag;
}
$versionLabel = implode(' · ', $versionParts);

?><!doctype html>
<html lang="en" data-color-preset="<?php echo htmlspecialchars($selectedColorPreset); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($appTitle); ?></title>
  <link rel="stylesheet" href="/style.css?v=<?php echo urlencode($styleAssetVersion); ?>">
</head>
<body<?php echo ($action === 'admin' && $isAdmin) ? ' data-admin-view="' . htmlspecialchars($adminViewMode) . '" data-admin-device-class="' . htmlspecialchars($adminDeviceClass) . '" data-admin-view-token="' . htmlspecialchars($reprovisionFormToken) . '"' : ''; ?>>
  <main class="page<?php echo ($action === 'admin' && $isAdmin) ? ' page-admin' : ''; ?>">
    <header>
      <div class="header-row">
        <h1><?php echo htmlspecialchars($appTitle); ?></h1>
        <div class="header-controls">
          <div class="header-action-row">
            <?php if ($isPropertyUser): ?>
              <button type="button" class="theme-toggle help-btn" data-help-key="top-nav-actions" title="Show help for all top navigation actions.">Nav Help</button>
              <form class="inline-action-form" method="get" action="/">
                <input type="hidden" name="action" value="faq">
                <button type="submit" class="theme-toggle" title="View common staff questions and answers.">FAQ</button>
              </form>
            <?php endif; ?>
            <button type="button" id="theme-toggle" class="theme-toggle" aria-label="Toggle light or dark theme">Dark</button>
            <?php if ($isPropertyUser): ?>
              <form class="inline-action-form" method="get" action="/">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="theme-toggle" aria-label="Logout" title="Logout">Logout</button>
              </form>
            <?php endif; ?>
          </div>
          <?php if ($isMasterAdmin && $action === 'admin'): ?>
            <details class="admin-preview-test-controls" role="group" aria-label="Admin preview controls">
              <summary class="admin-preview-test-title">Test Preview</summary>
              <p class="admin-preview-test-sub">Simulation only. Does not change real device preference.</p>
              <label class="admin-view-select-label" for="admin-device-preview">Preview Device</label>
              <select id="admin-device-preview" class="admin-view-select" aria-label="Choose admin preview device">
                <option value="auto">Auto</option>
                <option value="desktop">Desktop</option>
                <option value="mobile">Mobile</option>
              </select>
              <button type="button" id="admin-view-toggle" class="theme-toggle" aria-label="Toggle admin view density">Compact View</button>
            </details>
          <?php endif; ?>
          <?php if ($isMasterAdmin && $action !== 'admin'): ?>
            <details class="nonadmin-preview-test-controls" role="group" aria-label="Non-admin preview controls">
              <summary class="admin-preview-test-title">Test Preview</summary>
              <p class="admin-preview-test-sub">Simulation only. Guests cannot access this tool.</p>
              <label class="admin-view-select-label" for="nonadmin-device-preview">Preview Device</label>
              <select id="nonadmin-device-preview" class="admin-view-select" aria-label="Choose non-admin preview device">
                <option value="auto">Auto</option>
                <option value="desktop">Desktop</option>
                <option value="mobile">Mobile</option>
              </select>
            </details>
          <?php endif; ?>
          <?php if (!$isPropertyUser): ?>
            <form class="inline-action-form" method="get" action="/">
              <input type="hidden" name="action" value="admin">
              <button type="submit" class="header-admin-btn">Staff Login</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($isLoggedIn): ?>
        <p class="sub">Signed in as <?php echo htmlspecialchars((string) $currentUser['username']); ?> (<?php echo htmlspecialchars($userRole); ?>)</p>
      <?php endif; ?>
      <?php if ($isPropertyUser): ?>
      <nav>
          <span class="nav-group-label">Reservations</span>
          <form class="inline-action-form" method="get" action="/">
            <input type="hidden" name="action" value="register">
            <button type="submit" class="nav-link-btn" title="Open the guest registration form to create a new reservation.">Register Guest</button>
          </form>
          <form class="inline-action-form" method="get" action="/">
            <input type="hidden" name="action" value="registration_list">
            <button type="submit" class="nav-link-btn" title="View currently active guests and perform active-guest actions.">Active Guests</button>
          </form>
          <form class="inline-action-form" method="get" action="/">
            <input type="hidden" name="action" value="upcoming_registrations">
            <button type="submit" class="nav-link-btn" title="View future reservations with arrival dates after today.">Upcoming Registrations</button>
          </form>
          <form class="inline-action-form" method="get" action="/">
            <input type="hidden" name="action" value="guest_requests">
            <button type="submit" class="nav-link-btn<?php echo $hasPendingGuestChangeRequests ? ' nav-link-btn-alert' : ''; ?>" title="Review and decide guest checkout/extension change requests.">Guest Change Requests</button>
          </form>
          <form class="inline-action-form" method="get" action="/">
            <input type="hidden" name="action" value="history_report">
            <button type="submit" class="nav-link-btn" title="Open combined active and historical reservation history.">Rental History</button>
          </form>
          <form class="inline-action-form" method="get" action="/">
            <input type="hidden" name="action" value="vacancy_mgmt">
            <button type="submit" class="nav-link-btn" title="Preview and apply vacant-lot profile updates.">Vacancy Mgmt</button>
          </form>
          <span class="nav-row-break" aria-hidden="true"></span>
          <span class="nav-group-label">Account</span>
          <form class="inline-action-form" method="get" action="/">
            <input type="hidden" name="action" value="account">
            <button type="submit" class="nav-link-btn" title="Manage your own account and password settings.">My Account</button>
          </form>
      </nav>
      <?php if ($isAdmin): ?>
        <form class="header-admin-gear-form" method="get" action="/">
          <input type="hidden" name="action" value="admin">
          <button type="submit" class="nav-gear" aria-label="Admin settings" title="Admin">&#9881;</button>
        </form>
      <?php endif; ?>
      <?php endif; ?>
    </header>

    <?php if ($flash !== null): ?>
      <section class="notice success"><?php echo htmlspecialchars($flash); ?></section>
    <?php endif; ?>

    <?php if ($error !== null): ?>
      <section class="notice error"><?php echo htmlspecialchars($error); ?></section>
    <?php endif; ?>

    <?php if ($isPropertyUser && $shouldRefresh && !($refresh['ok'] ?? false)): ?>
      <section class="notice warning">Landing-page modem refresh failed. Local data is still available.</section>
    <?php endif; ?>

    <?php if ($isPropertyUser && $action === 'admin' && $offlineModemCount > 0): ?>
      <section class="notice warning notice-persistent" data-persist="true">
        <p><strong>Action Needed:</strong> <?php echo (int) $offlineModemCount; ?> lot modem(s) in configured service groups (<?php echo htmlspecialchars(implode(',', array_map('strval', $serviceGroups))); ?>) do not currently have active DDNet leases. Check the Modem and Lot Sync card in Admin Settings for details.</p>
      </section>
    <?php endif; ?>

    <?php if ($action === 'registration_list'): ?>
      <section>
        <div class="section-title-row">
          <h2>Active Guests</h2>
          <button type="button" class="help-btn" data-help-key="active-guests-page">Help</button>
        </div>
        <?php if (is_array($regeneratedGuestAccessInfo)): ?>
          <section class="notice warning notice-persistent" data-persist="true">
            <p><strong>New guest access credentials generated (show once):</strong></p>
            <p>Guest: <strong><?php echo htmlspecialchars((string) ($regeneratedGuestAccessInfo['guest_name'] ?? '')); ?></strong> | Lot: <strong><?php echo htmlspecialchars((string) ($regeneratedGuestAccessInfo['unit'] ?? '')); ?></strong></p>
            <p>Guest Access ID: <strong><?php echo htmlspecialchars((string) ($regeneratedGuestAccessInfo['access_id'] ?? '')); ?></strong></p>
            <p>Guest Access Code: <strong><?php echo htmlspecialchars((string) ($regeneratedGuestAccessInfo['access_code'] ?? '')); ?></strong></p>
            <p>Code Expires: <?php echo htmlspecialchars((string) ($regeneratedGuestAccessInfo['expires_at'] ?? '')); ?></p>
          </section>
        <?php endif; ?>
        <?php if ($provisioningActionItems !== []): ?>
          <section class="notice warning notice-persistent provisioning-alert" data-persist="true">
            <p><strong>Action Needed:</strong> <?php echo count($provisioningActionItems); ?> active reservation(s) have provisioning issues.</p>
            <ul>
              <?php foreach ($provisioningActionItems as $item): ?>
                <li>
                  <strong><?php echo htmlspecialchars((string) ($item['guest_name'] ?? 'Guest')); ?></strong>
                  at <?php echo htmlspecialchars((string) ($item['unit'] ?? 'Unknown')); ?>
                  (SG <?php echo (int) ($item['sg'] ?? 0); ?>)
                  <?php if (!empty($item['issues'])): ?>
                    : <?php echo htmlspecialchars(implode('; ', (array) ($item['issues'] ?? []))); ?>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
            <p><strong>How to fix:</strong></p>
            <ol>
              <li>Click <strong>Edit</strong> to verify lot, service group, and modem MAC are correct.</li>
              <li>Click <strong>Reprovision Active Guest</strong> to retry Gunslinger profile apply and modem reboot path.</li>
              <li>If failure remains, check <strong>Reprovision Log</strong> details and correct external system issue:
                Gunslinger profile update, DDNet lease data, or modem reachability/SNMP.</li>
              <li>Retry reprovision after correction and confirm status no longer shows Action Needed.</li>
            </ol>
          </section>
        <?php endif; ?>
        <p class="sub">Showing currently active registrations only (today is between arrival and departure).</p>
        <div class="button-row">
          <a href="/?action=registration_list&amp;status_filter=all" class="header-admin-btn<?php echo $activeGuestStatusFilter === 'all' ? ' is-active-filter' : ''; ?>">All Active (<?php echo (int) $activeGuestTotalCount; ?>)</a>
          <a href="/?action=registration_list&amp;status_filter=action_needed" class="header-admin-btn<?php echo $activeGuestStatusFilter === 'action_needed' ? ' is-active-filter' : ''; ?>">Action Needed (<?php echo (int) $activeGuestActionNeededCount; ?>)</a>
        </div>
        <p class="sub">Showing <?php echo (int) $activeGuestVisibleCount; ?> of <?php echo (int) $activeGuestTotalCount; ?> active guest(s).</p>
        <?php if ($registrationRowsBySg === []): ?>
          <p class="sub"><?php echo $activeGuestStatusFilter === 'action_needed' ? 'No active guests currently require action.' : 'No active registrations found.'; ?></p>
        <?php endif; ?>
        <?php foreach ($registrationRowsBySg as $sg => $sgRows): ?>
        <h3><?php echo htmlspecialchars((string) ($serviceGroupLabelsBySg[(int) $sg] ?? ('Service Group ' . (int) $sg))); ?></h3>
        <div class="record-table-scroll-container">
        <table class="js-sortable-table sticky-header-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Guest</th>
              <th><button type="button" class="sort-btn" data-sort-type="unit">Unit</button></th>
              <th>SG</th>
              <th><button type="button" class="sort-btn" data-sort-type="date">Arrival</button></th>
              <th><button type="button" class="sort-btn" data-sort-type="date">Departure</button></th>
              <th>Stay Days</th>
              <th>IP / MAC</th>
              <th>Status</th>
              <th>Notes</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sgRows as $row): ?>
              <tr>
                <td><?php echo (int) $row['id']; ?></td>
                <td>
                  <div><strong><?php echo htmlspecialchars($row['guest_name']); ?></strong></div>
                  <div class="sub"><?php echo htmlspecialchars($row['phone']); ?></div>
                </td>
                <td data-sort-value="<?php echo htmlspecialchars((string) $row['unit']); ?>"><?php echo htmlspecialchars($row['unit']); ?></td>
                <td><?php echo (int) $row['sg']; ?></td>
                <td data-sort-value="<?php echo htmlspecialchars((string) $row['arrival_date']); ?>"><?php echo htmlspecialchars($row['arrival_date']); ?></td>
                <td data-sort-value="<?php echo htmlspecialchars((string) $row['departure_date']); ?>"><?php echo htmlspecialchars($row['departure_date']); ?></td>
                <td><?php echo (int) $row['stay_days']; ?></td>
                <td><?php echo renderNetworkIdentityCell((string) ($row['dhcp_ip'] ?? ''), (string) ($row['modem_mac'] ?? '')); ?></td>
                <td><span class="<?php echo htmlspecialchars(activeGuestStatusClass((array) $row)); ?>"><?php echo htmlspecialchars(renderActiveGuestStatusText((array) $row)); ?></span></td>
                <td><?php echo renderActiveGuestNotesCell((array) $row); ?></td>
                <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                <td>
                  <div class="table-action-stack">
                    <a class="mini-admin-btn table-action-btn table-action-link" href="/?action=edit_guest&amp;id=<?php echo (int) $row['id']; ?>" aria-label="Edit guest">&#9881; Edit Guest</a>
                    <form class="inline-action-form" method="post" action="/?action=admin">
                      <input type="hidden" name="reprovision_active_guest" value="1">
                      <input type="hidden" name="guest_id" value="<?php echo (int) $row['id']; ?>">
                      <input type="hidden" name="reprovision_token" value="<?php echo htmlspecialchars($reprovisionFormToken); ?>">
                      <button type="submit" class="mini-admin-btn table-action-btn">Reprovision Active Guest</button>
                    </form>
                    <form class="inline-action-form" method="post" action="/?action=admin">
                      <input type="hidden" name="regenerate_guest_access_code" value="1">
                      <input type="hidden" name="guest_id" value="<?php echo (int) $row['id']; ?>">
                      <button type="submit" class="mini-admin-btn table-action-btn">Regenerate Access Code</button>
                    </form>
                    <form class="inline-action-form" method="post" action="/?action=admin">
                      <input type="hidden" name="request_void_registration" value="1">
                      <input type="hidden" name="guest_id" value="<?php echo (int) $row['id']; ?>">
                      <button type="submit" class="mini-admin-btn table-action-btn danger-btn">Void Incorrect Registration</button>
                    </form>
                    <?php if ($pendingVoidGuestId === (int) $row['id']): ?>
                      <form class="inline-action-form" method="post" action="/?action=admin">
                        <input type="hidden" name="guest_id" value="<?php echo (int) $row['id']; ?>">
                        <div class="table-action-confirm">
                          <span class="sub">Confirm void?</span>
                          <button type="submit" name="confirm_void_registration" value="proceed" class="mini-admin-btn table-action-btn danger-btn">Proceed</button>
                          <button type="submit" name="confirm_void_registration" value="cancel" class="mini-admin-btn table-action-btn secondary-btn">Cancel</button>
                        </div>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endforeach; ?>
      </section>
    <?php elseif ($action === 'upcoming_registrations'): ?>
      <section>
        <div class="section-title-row">
          <h2>Upcoming Registrations</h2>
        </div>
        <p class="sub">Shows future reservations with arrival dates after today.</p>
        <form method="get" action="/" class="log-filter-row">
          <input type="hidden" name="action" value="upcoming_registrations">
          <label>
            Arrival Window
            <select name="window_days">
              <option value="7" <?php echo $upcomingWindowDays === '7' ? 'selected' : ''; ?>>Next 7 days</option>
              <option value="14" <?php echo $upcomingWindowDays === '14' ? 'selected' : ''; ?>>Next 14 days</option>
              <option value="30" <?php echo $upcomingWindowDays === '30' ? 'selected' : ''; ?>>Next 30 days</option>
              <option value="60" <?php echo $upcomingWindowDays === '60' ? 'selected' : ''; ?>>Next 60 days</option>
              <option value="90" <?php echo $upcomingWindowDays === '90' ? 'selected' : ''; ?>>Next 90 days</option>
              <option value="all" <?php echo $upcomingWindowDays === 'all' ? 'selected' : ''; ?>>All upcoming</option>
            </select>
          </label>
          <div class="button-row upcoming-filter-buttons">
            <button type="submit">Apply</button>
            <a href="/?action=upcoming_registrations&amp;window_days=30" class="header-admin-btn">Reset</a>
          </div>
        </form>
        <p class="sub">Window: <?php echo htmlspecialchars($upcomingWindowDays === 'all' ? 'All upcoming dates' : ('Next ' . $upcomingWindowDays . ' days')); ?>.</p>
        <?php if ($upcomingRegistrationRowsBySg === []): ?>
          <p class="sub">No upcoming registrations found.</p>
        <?php endif; ?>
        <?php foreach ($upcomingRegistrationRowsBySg as $sg => $sgRows): ?>
        <h3><?php echo htmlspecialchars((string) ($serviceGroupLabelsBySg[(int) $sg] ?? ('Service Group ' . (int) $sg))); ?></h3>
        <table class="js-sortable-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Guest</th>
              <th><button type="button" class="sort-btn" data-sort-type="unit">Unit</button></th>
              <th>SG</th>
              <th><button type="button" class="sort-btn" data-sort-type="date">Arrival</button></th>
              <th><button type="button" class="sort-btn" data-sort-type="date">Departure</button></th>
              <th>Stay Days</th>
              <th>Status</th>
              <th>Notes</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sgRows as $row): ?>
              <tr>
                <td><?php echo (int) $row['id']; ?></td>
                <td>
                  <div><strong><?php echo htmlspecialchars($row['guest_name']); ?></strong></div>
                  <div class="sub"><?php echo htmlspecialchars($row['phone']); ?></div>
                </td>
                <td data-sort-value="<?php echo htmlspecialchars((string) $row['unit']); ?>"><?php echo htmlspecialchars($row['unit']); ?></td>
                <td><?php echo (int) $row['sg']; ?></td>
                <td data-sort-value="<?php echo htmlspecialchars((string) $row['arrival_date']); ?>"><?php echo htmlspecialchars($row['arrival_date']); ?></td>
                <td data-sort-value="<?php echo htmlspecialchars((string) $row['departure_date']); ?>"><?php echo htmlspecialchars($row['departure_date']); ?></td>
                <td><?php echo (int) $row['stay_days']; ?></td>
                <td><span class="<?php echo htmlspecialchars(activeGuestStatusClass((array) $row)); ?>"><?php echo htmlspecialchars(renderActiveGuestStatusText((array) $row)); ?></span></td>
                <td><?php echo htmlspecialchars((string) ($row['notes'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                <td>
                  <form class="inline-action-form" method="get" action="/">
                    <input type="hidden" name="action" value="edit_guest">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <button type="submit" class="icon-gear-btn" aria-label="Edit guest" title="Edit">&#9881;</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endforeach; ?>
      </section>
    <?php elseif ($action === 'reports'): ?>
      <section>
        <h2>Reports</h2>
        <div class="button-row">
          <form class="inline-action-form" method="get" action="/">
            <input type="hidden" name="action" value="history_report">
            <button type="submit" class="mini-admin-btn">History Report</button>
          </form>
        </div>
      </section>
    <?php elseif ($action === 'history_report'): ?>
      <section>
        <div class="section-title-row">
          <h2>Rental History</h2>
          <button type="button" class="help-btn" data-help-key="rental-history-page">Help</button>
        </div>
        <p class="sub">Shows active and historical registrations. Historical rows are shaded for quick scanning.</p>
        <div class="record-table-scroll-container">
        <table class="js-sortable-table sticky-header-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Phone</th>
              <th><button type="button" class="sort-btn" data-sort-type="unit">Unit</button></th>
              <th>SG</th>
              <th><button type="button" class="sort-btn" data-sort-type="date">Arrival</button></th>
              <th><button type="button" class="sort-btn" data-sort-type="date">Departure</button></th>
              <th>Stay Days</th>
              <th>IP / MAC</th>
              <th>Status</th>
              <th>Notes</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($historyRows as $row): ?>
              <tr class="<?php echo (($row['row_scope'] ?? '') === 'historical') ? 'row-historical' : 'row-active'; ?>">
                <td><?php echo (int) $row['id']; ?></td>
                <td><?php echo htmlspecialchars($row['guest_name']); ?></td>
                <td><?php echo htmlspecialchars($row['phone']); ?></td>
                <td data-sort-value="<?php echo htmlspecialchars((string) $row['unit']); ?>"><?php echo htmlspecialchars($row['unit']); ?></td>
                <td><?php echo (int) $row['sg']; ?></td>
                <td data-sort-value="<?php echo htmlspecialchars((string) $row['arrival_date']); ?>"><?php echo htmlspecialchars($row['arrival_date']); ?></td>
                <td data-sort-value="<?php echo htmlspecialchars((string) $row['departure_date']); ?>"><?php echo htmlspecialchars($row['departure_date']); ?></td>
                <td><?php echo (int) $row['stay_days']; ?></td>
                <td><?php echo renderNetworkIdentityCell((string) ($row['dhcp_ip'] ?? ''), (string) ($row['modem_mac'] ?? '')); ?></td>
                <td><span class="<?php echo htmlspecialchars(submissionStatusClass((string) ($row['submission_status'] ?? ''))); ?>"><?php echo htmlspecialchars(renderSubmissionStatusText((string) ($row['submission_status'] ?? ''))); ?></span></td>
                <td><?php echo htmlspecialchars((string) ($row['notes'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars($row['created_at']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </section>
    <?php elseif ($action === 'modem_lot_sync'): ?>
      <?php header('Location: /?action=admin'); exit; ?>
    <?php elseif ($action === 'vacancy_mgmt'): ?>
      <section>
        <div class="section-title-row">
          <h2>Vacancy Mgmt</h2>
          <button type="button" class="help-btn" data-help-key="vacancy-mgmt-page">Help</button>
        </div>
        <p class="sub">Preview lots with no active registration and compare current Gunslinger profile to target vacant profile.</p>

        <details class="vacant-mgmt-section vacant-mgmt-preview"<?php echo $openVacantPreviewSection ? ' open' : ''; ?>>
          <summary><strong>Vacant Lot Preview</strong></summary>
          <button type="button" class="help-btn" data-help-key="vacant-lot-preview-section">Help</button>
          <p class="sub">Refreshes Gunslinger data, computes vacant lots, and shows what each selected lot would be set to.</p>
          <form method="post" action="/?action=vacancy_mgmt">
            <input type="hidden" name="reprovision_token" value="<?php echo htmlspecialchars($reprovisionFormToken); ?>">
            <div class="button-row">
              <button type="submit" name="preview_vacant_profile_audit" value="1">Preview Vacant Lots from Gunslinger</button>
            </div>
          </form>
          <?php if ($showVacantAuditResults): ?>
            <form method="post" action="/?action=vacancy_mgmt">
            <input type="hidden" name="reprovision_token" value="<?php echo htmlspecialchars($reprovisionFormToken); ?>">
            <section class="notice warning vacant-mgmt-results">
              <p><strong>Preview generated:</strong> <?php echo htmlspecialchars($vacantAuditRefreshedAt); ?> (SGs: <?php echo htmlspecialchars($vacantAuditSource); ?>)</p>
              <p>Total vacant lots: <strong><?php echo (int) $vacantAuditTotals['total_vacant']; ?></strong> | Needs change: <strong><?php echo (int) $vacantAuditTotals['needs_change']; ?></strong> | Already on target: <strong><?php echo (int) $vacantAuditTotals['already_target']; ?></strong> | Missing profile config: <strong><?php echo (int) $vacantAuditTotals['config_missing']; ?></strong></p>
              <p class="sub">Rows needing change are auto-selected by default. Apply action is next in implementation.</p>
            </section>

            <h4 class="vacant-defaults-title">Current Vacant Profile Defaults</h4>
            <table>
              <thead>
                <tr>
                  <th>Service Group</th>
                  <th>Vacant Profile Target</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($serviceGroups as $sg): ?>
                  <?php $sg = (int) $sg; ?>
                  <tr>
                    <td><?php echo htmlspecialchars((string) ($serviceGroupLabelsBySg[$sg] ?? ('Service Group ' . $sg))); ?></td>
                    <td><?php echo htmlspecialchars((string) ($vacantProfileOverridesBySg[$sg] ?? '')); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

            <?php if ($vacantAuditRowsBySg === []): ?>
              <p class="sub">No vacant lots found for configured service groups.</p>
            <?php endif; ?>

            <?php foreach ($vacantAuditRowsBySg as $sg => $rowsBySg): ?>
              <h4><?php echo htmlspecialchars((string) ($serviceGroupLabelsBySg[(int) $sg] ?? ('Service Group ' . (int) $sg))); ?></h4>
              <div class="vacant-preview-controls">
                <label class="inline-checkbox">
                  <input type="checkbox" class="vacant-sg-toggle" data-sg="<?php echo (int) $sg; ?>">
                  Select/Unselect all for SG <?php echo (int) $sg; ?>
                </label>
              </div>
              <table class="sticky-header-table vacant-preview-table">
                <thead>
                  <tr>
                    <th>Select</th>
                    <th>Lot</th>
                    <th>MAC</th>
                    <th>Current Profile</th>
                    <th>Target Vacant Profile</th>
                    <th>State</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rowsBySg as $row): ?>
                    <?php $rowNeedsChange = (bool) ($row['needs_change'] ?? false); ?>
                    <?php $rowHasConfig = (bool) ($row['has_config'] ?? false); ?>
                  <?php $rowPayload = base64_encode((string) json_encode([
                    'sg' => (int) $sg,
                    'unit' => (string) ($row['unit'] ?? ''),
                    'mac' => (string) ($row['mac'] ?? ''),
                  ], JSON_UNESCAPED_SLASHES)); ?>
                    <tr>
                      <td>
                      <input type="checkbox" class="vacant-row-checkbox" name="vacant_apply_rows[]" value="<?php echo htmlspecialchars($rowPayload); ?>" data-sg="<?php echo (int) $sg; ?>" <?php echo ($rowNeedsChange && $rowHasConfig) ? 'checked' : ''; ?>>
                      </td>
                      <td><?php echo htmlspecialchars((string) ($row['unit'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($row['mac'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($row['current_profile'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($row['target_profile'] ?? '')); ?></td>
                      <td>
                        <?php if (!$rowHasConfig): ?>
                          Missing target profile
                        <?php elseif ($rowNeedsChange): ?>
                          Needs update
                        <?php else: ?>
                          Already target
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endforeach; ?>

            <div class="button-row">
              <button type="submit" name="request_apply_vacant_profiles" value="1" class="danger-btn">Apply Selected Vacant Profiles</button>
            </div>
            </form>

            <?php if ($showVacantApplyConfirm): ?>
              <section class="notice warning vacant-mgmt-results">
                <p><strong>Confirm apply vacant profiles?</strong></p>
                <p>Proceed will update <strong><?php echo count($vacantApplyPendingRows); ?></strong> selected lot(s) in Gunslinger.</p>
                <form method="post" action="/?action=vacancy_mgmt">
                  <input type="hidden" name="reprovision_token" value="<?php echo htmlspecialchars($reprovisionFormToken); ?>">
                  <?php foreach ($vacantApplyPendingRows as $pendingRow): ?>
                    <?php $pendingPayload = base64_encode((string) json_encode($pendingRow, JSON_UNESCAPED_SLASHES)); ?>
                    <input type="hidden" name="vacant_apply_payload[]" value="<?php echo htmlspecialchars($pendingPayload); ?>">
                  <?php endforeach; ?>
                  <div class="button-row">
                    <button type="submit" name="confirm_apply_vacant_profiles" value="proceed" class="danger-btn">Proceed</button>
                    <button type="submit" name="confirm_apply_vacant_profiles" value="cancel" class="secondary-btn">Cancel</button>
                  </div>
                </form>
              </section>
            <?php endif; ?>

            <?php if ($showVacantApplyResults): ?>
              <section class="notice warning vacant-mgmt-results">
                <p><strong>Apply Results</strong></p>
                <p>Attempted: <strong><?php echo (int) $vacantApplySummary['attempted']; ?></strong> | Updated: <strong><?php echo (int) $vacantApplySummary['updated']; ?></strong> | Failed: <strong><?php echo (int) $vacantApplySummary['failed']; ?></strong></p>
              </section>
              <table class="sticky-header-table vacant-apply-results-table">
                <thead>
                  <tr>
                    <th>SG</th>
                    <th>Lot</th>
                    <th>MAC</th>
                    <th>Current Profile</th>
                    <th>Target Profile</th>
                    <th>Status</th>
                    <th>Details</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($vacantApplyRunResults as $applyRow): ?>
                    <tr>
                      <td><?php echo (int) ($applyRow['sg'] ?? 0); ?></td>
                      <td><?php echo htmlspecialchars((string) ($applyRow['unit'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($applyRow['mac'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($applyRow['old_profile'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($applyRow['target_profile'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($applyRow['status'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($applyRow['details'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          <?php endif; ?>
        </details>
      </section>
    <?php elseif ($action === 'logs'): ?>
      <section>
        <div class="section-title-row">
          <h2>Logs</h2>
        </div>
        <p class="sub">Centralized operations and security log access for administrators and staff.</p>

        <details class="admin-subcard">
          <summary><strong>Reprovision Log</strong></summary>
          <?php if ($isAdmin): ?>
            <form method="post" action="/?action=logs" class="button-row">
              <button type="submit" name="clear_log_key" value="reprovision" class="danger-btn" onclick="return confirm('Clear only the Reprovision Log?');">Clear Reprovision Log</button>
            </form>
          <?php endif; ?>
          <p class="sub">Recent reprovision actions are recorded here. Guest notes keep only the latest reprovision summary.</p>
          <div class="log-filter-row">
            <label>
              Filter by Guest Name
              <input type="text" id="reprov-filter-guest" placeholder="Type guest name">
            </label>
            <label>
              Filter by Unit
              <input type="text" id="reprov-filter-unit" placeholder="Type unit (e.g. Lot A2)">
            </label>
          </div>
          <div class="log-table-container">
          <table class="sticky-header-table">
            <thead>
              <tr>
                <th>Time</th>
                <th>Guest</th>
                <th>Unit</th>
                <th>SG</th>
                <th>MAC</th>
                <th>Profile</th>
                <th>Status</th>
                <th>Details</th>
                <th>Actor</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($reprovisionLogs === []): ?>
                <tr>
                  <td colspan="9">No reprovision actions logged yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($reprovisionLogs as $log): ?>
                  <tr class="reprov-log-row" data-guest-name="<?php echo htmlspecialchars(strtolower((string) $log['guest_name'])); ?>" data-unit="<?php echo htmlspecialchars(strtolower((string) $log['unit'])); ?>">
                    <td><?php echo htmlspecialchars((string) $log['created_at']); ?></td>
                    <td><?php echo htmlspecialchars((string) $log['guest_name']); ?> (#<?php echo (int) $log['guest_id']; ?>)</td>
                    <td><?php echo htmlspecialchars((string) $log['unit']); ?></td>
                    <td><?php echo (int) $log['sg']; ?></td>
                    <td><?php echo htmlspecialchars((string) $log['modem_mac']); ?></td>
                    <td><?php echo htmlspecialchars((string) $log['profile_applied']); ?></td>
                    <td><?php echo htmlspecialchars((string) $log['status']); ?></td>
                    <td><?php echo htmlspecialchars((string) $log['details']); ?></td>
                    <td><?php echo htmlspecialchars((string) $log['actor']); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
          </div>
        </details>

        <details class="admin-subcard">
          <summary><strong>API Captive Portal Log</strong></summary>
          <?php if ($isAdmin): ?>
            <form method="post" action="/?action=logs" class="button-row">
              <button type="submit" name="clear_log_key" value="captive_portal" class="danger-btn" onclick="return confirm('Clear only the API Captive Portal Log?');">Clear API Captive Portal Log</button>
            </form>
          <?php endif; ?>
          <p class="sub">Requests to the RFC captive portal API advertised by DHCP Option 114.</p>
          <div class="log-table-container">
          <table class="sticky-header-table">
            <thead>
              <tr>
                <th>Time</th>
                <th>Client IP</th>
                <th>Host</th>
                <th>Method</th>
                <th>URI</th>
                <th>User Agent</th>
                <th>Response</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($captivePortalApiLogs === []): ?>
                <tr>
                  <td colspan="7">No captive portal API requests logged yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($captivePortalApiLogs as $log): ?>
                  <tr>
                    <td><?php echo htmlspecialchars((string) ($log['created_at'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string) ($log['client_ip'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string) ($log['host_header'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string) ($log['request_method'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string) ($log['request_uri'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string) ($log['user_agent'] ?? '')); ?></td>
                    <td><code><?php echo htmlspecialchars((string) ($log['response_json'] ?? '')); ?></code></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
          </div>
        </details>

        <?php if ($isAdmin): ?>
          <details class="admin-subcard">
            <summary><strong>Vacant Profile Apply Log</strong></summary>
            <form method="post" action="/?action=logs" class="button-row">
              <button type="submit" name="clear_log_key" value="vacant_profile" class="danger-btn" onclick="return confirm('Clear only the Vacant Profile Apply Log?');">Clear Vacant Profile Apply Log</button>
            </form>
            <p class="sub">Most recent attempts to update vacant lot profiles in Gunslinger.</p>
            <div class="log-table-container">
            <table class="sticky-header-table existing-users-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>SG</th>
                  <th>Lot</th>
                  <th>MAC</th>
                  <th>Old Profile</th>
                  <th>Target Profile</th>
                  <th>Status</th>
                  <th>Details</th>
                  <th>Actor</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($vacantProfileLogs === []): ?>
                  <tr>
                    <td colspan="9">No vacant profile apply actions logged yet.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($vacantProfileLogs as $log): ?>
                    <tr>
                      <td><?php echo htmlspecialchars((string) ($log['created_at'] ?? '')); ?></td>
                      <td><?php echo (int) ($log['sg'] ?? 0); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['unit'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['modem_mac'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['old_profile'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['target_profile'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['status'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['details'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['actor'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
            </div>
          </details>

          <details class="admin-subcard">
            <summary><strong>Modem Scoped Reservation Log</strong></summary>
            <form method="post" action="/?action=logs" class="button-row">
              <button type="submit" name="clear_log_key" value="modem_scoped" class="danger-btn" onclick="return confirm('Clear only the Modem Scoped Reservation Log?');">Clear Modem Scoped Reservation Log</button>
            </form>
            <p class="sub">Recent DDNet modem-scoped reservation submissions for guest registrations.</p>
            <div class="log-table-container">
            <table class="sticky-header-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Guest</th>
                  <th>Lot</th>
                  <th>SG</th>
                  <th>Modem MAC</th>
                  <th>Client IP</th>
                  <th>Status</th>
                  <th>Details</th>
                  <th>Actor</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($modemScopedReservationLogs === []): ?>
                  <tr>
                    <td colspan="9">No modem-scoped reservation activity logged yet.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($modemScopedReservationLogs as $log): ?>
                    <tr>
                      <td><?php echo htmlspecialchars((string) ($log['created_at'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['guest_name'] ?? '')); ?> (#<?php echo (int) ($log['guest_id'] ?? 0); ?>)</td>
                      <td><?php echo htmlspecialchars((string) ($log['unit'] ?? '')); ?></td>
                      <td><?php echo (int) ($log['sg'] ?? 0); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['modem_mac'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['client_ip'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['status'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['details'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['actor'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
            </div>
          </details>

          <details class="admin-subcard">
            <summary><strong>Guest Access Security Log</strong></summary>
            <form method="post" action="/?action=logs" class="button-row">
              <button type="submit" name="clear_log_key" value="guest_access" class="danger-btn" onclick="return confirm('Clear only the Guest Access Security Log?');">Clear Guest Access Security Log</button>
            </form>
            <p class="sub">Recent guest self-service login success, failure, lockout, and unlock events.</p>
            <div class="log-table-container">
            <table class="sticky-header-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Access ID</th>
                  <th>IP Address</th>
                  <th>Event</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($guestAccessEventLogs === []): ?>
                  <tr>
                    <td colspan="4">No guest access security events logged yet.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($guestAccessEventLogs as $event): ?>
                    <tr>
                      <td><?php echo htmlspecialchars((string) ($event['created_at'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($event['guest_access_id'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($event['ip_address'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($event['event_type'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
            </div>
          </details>

          <details class="admin-subcard">
            <summary><strong>SNMP Audit Log</strong></summary>
            <p class="sub">Latest device reboot audit lines from the SNMP audit file.</p>
            <div class="log-table-container">
            <table class="sticky-header-table">
              <thead>
                <tr>
                  <th>Line</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($snmpAuditLogLines === []): ?>
                  <tr>
                    <td>No SNMP audit lines available.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($snmpAuditLogLines as $line): ?>
                    <tr>
                      <td><code><?php echo htmlspecialchars((string) $line); ?></code></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
            </div>
          </details>
        <?php endif; ?>
      </section>
    <?php elseif ($action === 'guest_requests'): ?>
      <section>
        <div class="section-title-row">
          <h2>Guest Change Requests</h2>
          <button type="button" class="help-btn" data-help-key="guest-requests-page">Help</button>
        </div>
        <details class="admin-subcard"<?php echo $openGuestRequestsPanel ? ' open' : ''; ?>>
          <summary><strong>Guest Change Request Queue</strong></summary>
        <?php
          $formatQueueAge = static function ($minutes): string {
            if (!is_int($minutes) || $minutes < 0) {
              return 'n/a';
            }
            if ($minutes < 60) {
              return $minutes . 'm';
            }
            $hours = (int) floor($minutes / 60);
            $mins = (int) ($minutes % 60);
            return $hours . 'h ' . $mins . 'm';
          };
          $openRequestCount = (int) ($guestRequestQueueMetrics['open_requests'] ?? 0);
          $oldestPendingAgeText = $formatQueueAge($guestRequestQueueMetrics['oldest_pending_age_minutes'] ?? null);
          $medianPendingAgeText = $formatQueueAge($guestRequestQueueMetrics['median_pending_age_minutes'] ?? null);
        ?>
        <p class="sub guest-request-metrics">Queue Metrics: Open Pending <?php echo $openRequestCount; ?> | Median Pending Age <?php echo htmlspecialchars($medianPendingAgeText); ?> | Oldest Pending Age <?php echo htmlspecialchars($oldestPendingAgeText); ?></p>
        <p class="sub">Review and decide guest change requests for early checkout or departure extension.</p>
        <form method="get" action="/" class="log-filter-row">
          <input type="hidden" name="action" value="guest_requests">
          <label>
            Status
            <select name="status">
              <option value="all" <?php echo $guestRequestFilterStatus === 'all' ? 'selected' : ''; ?>>All</option>
              <option value="pending" <?php echo $guestRequestFilterStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
              <option value="approved" <?php echo $guestRequestFilterStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
              <option value="denied" <?php echo $guestRequestFilterStatus === 'denied' ? 'selected' : ''; ?>>Denied</option>
              <option value="auto_approved" <?php echo $guestRequestFilterStatus === 'auto_approved' ? 'selected' : ''; ?>>Auto-Approved</option>
            </select>
          </label>
          <label>
            Request Type
            <select name="request_type">
              <option value="all" <?php echo $guestRequestFilterType === 'all' ? 'selected' : ''; ?>>All</option>
              <option value="early_checkout" <?php echo $guestRequestFilterType === 'early_checkout' ? 'selected' : ''; ?>>Early Checkout</option>
              <option value="extend_departure" <?php echo $guestRequestFilterType === 'extend_departure' ? 'selected' : ''; ?>>Departure Extension</option>
            </select>
          </label>
          <label>
            Service Group
            <select name="sg">
              <option value="0" <?php echo $guestRequestFilterSg === 0 ? 'selected' : ''; ?>>All SGs</option>
              <?php foreach ($serviceGroups as $sg): ?>
                <?php $sg = (int) $sg; ?>
                <option value="<?php echo $sg; ?>" <?php echo $guestRequestFilterSg === $sg ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($serviceGroupLabelsBySg[$sg] ?? ('SG ' . $sg))); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            Date From
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($guestRequestFilterDateFrom); ?>">
          </label>
          <label>
            Date To
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($guestRequestFilterDateTo); ?>">
          </label>
          <label>
            Search
            <input type="text" name="search" value="<?php echo htmlspecialchars($guestRequestFilterSearch); ?>" placeholder="Guest, lot, access ID">
          </label>
          <label>
            Sort
            <select name="sort">
              <option value="created_desc" <?php echo $guestRequestSort === 'created_desc' ? 'selected' : ''; ?>>Newest First</option>
              <option value="created_asc" <?php echo $guestRequestSort === 'created_asc' ? 'selected' : ''; ?>>Oldest First</option>
              <option value="requested_desc" <?php echo $guestRequestSort === 'requested_desc' ? 'selected' : ''; ?>>Requested Date Desc</option>
              <option value="requested_asc" <?php echo $guestRequestSort === 'requested_asc' ? 'selected' : ''; ?>>Requested Date Asc</option>
              <option value="age_desc" <?php echo $guestRequestSort === 'age_desc' ? 'selected' : ''; ?>>Oldest Age First</option>
              <option value="age_asc" <?php echo $guestRequestSort === 'age_asc' ? 'selected' : ''; ?>>Newest Age First</option>
            </select>
          </label>
          <label>
            Rows Per Page
            <select name="per_page">
              <option value="25" <?php echo $guestRequestPerPage === 25 ? 'selected' : ''; ?>>25</option>
              <option value="50" <?php echo $guestRequestPerPage === 50 ? 'selected' : ''; ?>>50</option>
              <option value="100" <?php echo $guestRequestPerPage === 100 ? 'selected' : ''; ?>>100</option>
              <option value="200" <?php echo $guestRequestPerPage === 200 ? 'selected' : ''; ?>>200</option>
            </select>
          </label>
          <div class="button-row">
            <button type="submit">Apply Filters</button>
            <button type="submit" name="export" value="csv" class="secondary-btn">Export CSV</button>
            <a href="/?action=guest_requests" class="header-admin-btn">Clear</a>
          </div>
        </form>
        <p class="sub">Showing <?php echo count($guestRequestRows); ?> of <?php echo (int) $guestRequestTotalRows; ?> request(s). Page <?php echo (int) $guestRequestPage; ?> of <?php echo (int) $guestRequestTotalPages; ?>.</p>
        <table class="sticky-header-table">
          <thead>
            <tr>
              <th>Time</th>
              <th>Age</th>
              <th>Guest</th>
              <th>Lot</th>
              <th>Type</th>
              <th>Current Departure</th>
              <th>Requested Departure</th>
              <th>Status</th>
              <th>Reason</th>
              <th>Decision</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($guestRequestRows === []): ?>
              <tr>
                <td colspan="10">No guest change requests found.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($guestRequestRows as $req): ?>
                <?php
                  $createdAtRaw = (string) ($req['created_at'] ?? '');
                  $ageMinutes = null;
                  if ($createdAtRaw !== '' && strtotime($createdAtRaw) !== false) {
                    $ageMinutes = max(0, (int) floor((time() - strtotime($createdAtRaw)) / 60));
                  }
                  $ageText = 'n/a';
                  if ($ageMinutes !== null) {
                    if ($ageMinutes < 60) {
                      $ageText = $ageMinutes . 'm';
                    } else {
                      $hours = (int) floor($ageMinutes / 60);
                      $mins = (int) ($ageMinutes % 60);
                      $ageText = $hours . 'h ' . $mins . 'm';
                    }
                  }
                  $slaClass = '';
                  if ($ageMinutes !== null && (string) ($req['status'] ?? '') === 'pending') {
                    if ($ageMinutes >= 720) {
                      $slaClass = 'sla-critical';
                    } elseif ($ageMinutes >= 240) {
                      $slaClass = 'sla-warning';
                    } else {
                      $slaClass = 'sla-normal';
                    }
                  }
                ?>
                <tr>
                  <td><?php echo htmlspecialchars($createdAtRaw); ?></td>
                  <td><span class="sla-chip <?php echo htmlspecialchars($slaClass); ?>"><?php echo htmlspecialchars($ageText); ?></span></td>
                  <td><?php echo htmlspecialchars((string) ($req['guest_name'] ?? '')); ?></td>
                  <td><?php echo htmlspecialchars((string) ($req['unit'] ?? '')); ?> (SG <?php echo (int) ($req['sg'] ?? 0); ?>)</td>
                  <td><?php echo htmlspecialchars((string) ($req['request_type'] ?? '')); ?></td>
                  <td><?php echo htmlspecialchars((string) ($req['current_departure_date'] ?? '')); ?></td>
                  <td><?php echo htmlspecialchars((string) ($req['requested_departure_date'] ?? '')); ?></td>
                  <td><?php echo htmlspecialchars((string) ($req['status'] ?? '')); ?></td>
                  <td><?php echo htmlspecialchars((string) ($req['reason'] ?? '')); ?></td>
                  <td>
                    <?php if ((string) ($req['status'] ?? '') === 'pending'): ?>
                      <form method="post" action="/?action=guest_requests" class="user-row-form">
                        <input type="hidden" name="request_id" value="<?php echo (int) ($req['id'] ?? 0); ?>">
                        <select name="decision" required>
                          <option value="approve">Approve</option>
                          <option value="deny">Deny</option>
                        </select>
                        <input type="text" name="decision_note" placeholder="Decision note (optional)">
                        <button type="submit" name="decide_guest_request" value="1">Save</button>
                      </form>
                    <?php else: ?>
                      <?php
                        $decisionMetaParts = [];
                        $decidedBy = trim((string) ($req['decided_by'] ?? ''));
                        if ($decidedBy !== '') {
                          $decisionMetaParts[] = 'By ' . $decidedBy;
                        }
                        $decidedAtRaw = trim((string) ($req['decided_at'] ?? ''));
                        if ($decidedAtRaw !== '') {
                          $decisionMetaParts[] = 'At ' . $decidedAtRaw;
                        }
                        $decisionNote = trim((string) ($req['decision_note'] ?? ''));
                        if ($decisionNote !== '') {
                          $decisionMetaParts[] = 'Note: ' . $decisionNote;
                        }
                        $decisionMetaText = $decisionMetaParts !== [] ? implode(' | ', $decisionMetaParts) : 'Closed';
                      ?>
                      <span class="sub decision-breadcrumb"><?php echo htmlspecialchars($decisionMetaText); ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
        <?php if ($guestRequestTotalPages > 1): ?>
          <?php
            $paginationBase = [
              'action' => 'guest_requests',
              'status' => $guestRequestFilterStatus,
              'request_type' => $guestRequestFilterType,
              'sg' => (string) $guestRequestFilterSg,
              'date_from' => $guestRequestFilterDateFrom,
              'date_to' => $guestRequestFilterDateTo,
              'search' => $guestRequestFilterSearch,
              'sort' => $guestRequestSort,
              'per_page' => (string) $guestRequestPerPage,
            ];
            $firstUrl = '/?' . http_build_query(array_merge($paginationBase, ['page' => 1]));
            $prevUrl = '/?' . http_build_query(array_merge($paginationBase, ['page' => max(1, $guestRequestPage - 1)]));
            $nextUrl = '/?' . http_build_query(array_merge($paginationBase, ['page' => min($guestRequestTotalPages, $guestRequestPage + 1)]));
            $lastUrl = '/?' . http_build_query(array_merge($paginationBase, ['page' => $guestRequestTotalPages]));
            $pageWindowStart = max(1, $guestRequestPage - 2);
            $pageWindowEnd = min($guestRequestTotalPages, $guestRequestPage + 2);
          ?>
          <div class="button-row pagination-row">
            <a href="<?php echo htmlspecialchars($firstUrl); ?>" class="header-admin-btn">First</a>
            <a href="<?php echo htmlspecialchars($prevUrl); ?>" class="header-admin-btn">Previous</a>
            <?php for ($p = $pageWindowStart; $p <= $pageWindowEnd; $p++): ?>
              <?php if ($p === $guestRequestPage): ?>
                <span class="pagination-current" aria-current="page"><?php echo (int) $p; ?></span>
              <?php else: ?>
                <a href="<?php echo htmlspecialchars('/?' . http_build_query(array_merge($paginationBase, ['page' => $p]))); ?>" class="header-admin-btn"><?php echo (int) $p; ?></a>
              <?php endif; ?>
            <?php endfor; ?>
            <a href="<?php echo htmlspecialchars($nextUrl); ?>" class="header-admin-btn">Next</a>
            <a href="<?php echo htmlspecialchars($lastUrl); ?>" class="header-admin-btn">Last</a>
          </div>
        <?php endif; ?>
        </details>
      </section>
    <?php elseif ($action === 'manage_reservation'): ?>
      <section>
        <div class="section-title-row">
          <h2>Manage My Reservation</h2>
          <button type="button" class="help-btn" data-help-key="manage-reservation-page">Help</button>
        </div>
        <?php if (!$guestSelfServiceEnabled): ?>
          <p class="sub">Guest self-service is currently disabled for this property.</p>
        <?php elseif ($guestAccessGuest === null): ?>
          <p class="sub">
            <?php if ($guestSelfServiceAuthMode === 'code_only'): ?>
              Enter your Guest Access Code from your registration confirmation.
            <?php else: ?>
              Enter your Guest Access ID and code from your registration confirmation.
            <?php endif; ?>
          </p>
          <form method="post" action="/?action=manage_reservation">
            <?php if ($guestSelfServiceAuthMode !== 'code_only'): ?>
              <label>
                Guest Access ID
                <input type="text" name="guest_access_id" required placeholder="Example: PO-AB12CD34" autocapitalize="characters">
              </label>
            <?php endif; ?>
            <label>
              Guest Access Code
              <input type="text" name="guest_access_code" required placeholder="Example: A9D4K7Q2M3" autocapitalize="characters">
            </label>
            <div class="button-row">
              <button type="submit" name="guest_access_login" value="1">Access Reservation</button>
            </div>
          </form>
        <?php else: ?>
          <p class="sub">Reservation for <strong><?php echo htmlspecialchars((string) ($guestAccessGuest['guest_name'] ?? '')); ?></strong> at <?php echo htmlspecialchars((string) ($guestAccessGuest['unit'] ?? '')); ?> (SG <?php echo (int) ($guestAccessGuest['sg'] ?? 0); ?>).</p>
          <table>
            <tbody>
              <tr>
                <th>Arrival Date</th>
                <td><?php echo htmlspecialchars((string) ($guestAccessGuest['arrival_date'] ?? '')); ?></td>
              </tr>
              <tr>
                <th>Departure Date</th>
                <td><?php echo htmlspecialchars((string) ($guestAccessGuest['departure_date'] ?? '')); ?></td>
              </tr>
              <tr>
                <th>Status</th>
                <td><span class="<?php echo htmlspecialchars(submissionStatusClass((string) ($guestAccessGuest['submission_status'] ?? ''))); ?>"><?php echo htmlspecialchars(renderSubmissionStatusText((string) ($guestAccessGuest['submission_status'] ?? ''), true)); ?></span></td>
              </tr>
            </tbody>
          </table>

          <details class="admin-subcard" open>
            <summary><strong>Request Early Checkout</strong></summary>
            <button type="button" class="help-btn help-btn-card" data-help-key="guest-early-checkout-card">Help</button>
            <?php if (!$guestSelfServiceAllowEarlyCheckout): ?>
              <p class="sub">This request type is disabled by property settings.</p>
            <?php else: ?>
              <form method="post" action="/?action=manage_reservation">
                <input type="hidden" name="request_type" value="early_checkout">
                <label>
                  New Departure Date
                  <input type="date" name="requested_departure_date" required>
                </label>
                <label>
                  Reason<?php echo $guestSelfServiceRequireReason ? ' (required)' : ' (optional)'; ?>
                  <input type="text" name="request_reason" <?php echo $guestSelfServiceRequireReason ? 'required' : ''; ?>>
                </label>
                <div class="button-row">
                  <button type="submit" name="submit_guest_self_service_request" value="1">Submit Early Checkout Request</button>
                </div>
              </form>
            <?php endif; ?>
          </details>

          <details class="admin-subcard" open>
            <summary><strong>Request Departure Extension</strong></summary>
            <button type="button" class="help-btn help-btn-card" data-help-key="guest-extension-card">Help</button>
            <?php if (!$guestSelfServiceAllowExtension): ?>
              <p class="sub">This request type is disabled by property settings.</p>
            <?php else: ?>
              <form method="post" action="/?action=manage_reservation">
                <input type="hidden" name="request_type" value="extend_departure">
                <label>
                  New Departure Date
                  <input type="date" name="requested_departure_date" required>
                </label>
                <label>
                  Reason<?php echo $guestSelfServiceRequireReason ? ' (required)' : ' (optional)'; ?>
                  <input type="text" name="request_reason" <?php echo $guestSelfServiceRequireReason ? 'required' : ''; ?>>
                </label>
                <div class="button-row">
                  <button type="submit" name="submit_guest_self_service_request" value="1">Submit Extension Request</button>
                </div>
              </form>
            <?php endif; ?>
          </details>

          <details class="admin-subcard" open>
            <summary><strong>Request History</strong></summary>
            <table class="sticky-header-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Type</th>
                  <th>Current Departure</th>
                  <th>Requested Departure</th>
                  <th>Status</th>
                  <th>Decision Note</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($guestAccessRequests === []): ?>
                  <tr>
                    <td colspan="6">No requests submitted yet.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($guestAccessRequests as $req): ?>
                    <tr>
                      <td><?php echo htmlspecialchars((string) ($req['created_at'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($req['request_type'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($req['current_departure_date'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($req['requested_departure_date'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($req['status'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($req['decision_note'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </details>

          <div class="button-row">
            <form class="inline-action-form" method="get" action="/">
              <input type="hidden" name="action" value="guest_access_logout">
              <button type="submit" class="secondary-btn">Sign Out of Reservation Access</button>
            </form>
          </div>
        <?php endif; ?>
      </section>
    <?php elseif ($action === 'account'): ?>
      <section>
        <div class="section-title-row">
          <h2>My Account</h2>
          <button type="button" class="help-btn" data-help-key="my-account-page">Help</button>
        </div>
        <?php if (!$isPropertyUser || !$isLoggedIn): ?>
          <p class="sub">Sign in as a property user to manage your account.</p>
        <?php else: ?>
          <p class="sub">Signed in as <?php echo htmlspecialchars((string) $currentUser['username']); ?> (<?php echo htmlspecialchars($userRole); ?>).</p>
          <details class="admin-subcard" open>
            <summary><strong>Change My Password</strong></summary>
            <button type="button" class="help-btn help-btn-card" data-help-key="change-password-card">Help</button>
            <form method="post" action="/?action=account" autocomplete="off">
              <label>
                Current Password
                <input type="password" name="current_password" required autocomplete="current-password">
              </label>
              <label>
                New Password
                <input type="password" name="new_password" required autocomplete="new-password">
              </label>
              <label>
                Confirm New Password
                <input type="password" name="confirm_new_password" required autocomplete="new-password">
              </label>
              <div class="button-row">
                <button type="submit" name="change_own_password" value="1">Update My Password</button>
              </div>
            </form>
          </details>
        <?php endif; ?>
      </section>
    <?php elseif ($action === 'register_confirmation'): ?>
      <section>
        <h2>Registration Confirmation</h2>
        <?php if ($registrationConfirmation === null): ?>
          <p class="sub">No recent registration details were found.</p>
        <?php else: ?>
          <?php if (trim((string) ($registrationConfirmation['guest_support_message'] ?? '')) !== ''): ?>
            <section class="notice warning notice-persistent" data-persist="true"><?php echo htmlspecialchars((string) ($registrationConfirmation['guest_support_message'] ?? '')); ?></section>
          <?php endif; ?>
          <?php if (trim((string) ($registrationConfirmation['modem_reboot_notice'] ?? '')) !== ''): ?>
            <section class="notice success notice-persistent" data-persist="true"><?php echo htmlspecialchars((string) ($registrationConfirmation['modem_reboot_notice'] ?? '')); ?></section>
          <?php endif; ?>
          <p class="sub">Thank you. Your registration was submitted with the details below.</p>
          <table>
            <tbody>
              <tr>
                <th>Name</th>
                <td><?php echo htmlspecialchars((string) ($registrationConfirmation['guest_name'] ?? '')); ?></td>
              </tr>
              <tr>
                <th>Phone</th>
                <td><?php echo htmlspecialchars((string) ($registrationConfirmation['phone'] ?? '')); ?></td>
              </tr>
              <tr>
                <th>Lot</th>
                <td><?php echo htmlspecialchars((string) ($registrationConfirmation['unit'] ?? '')); ?></td>
              </tr>
              <tr>
                <th>Service Group</th>
                <td><?php echo (int) ($registrationConfirmation['sg'] ?? 0); ?></td>
              </tr>
              <tr>
                <th>Arrival Date</th>
                <td><?php echo htmlspecialchars((string) ($registrationConfirmation['arrival_date'] ?? '')); ?></td>
              </tr>
              <tr>
                <th>Departure Date</th>
                <td><?php echo htmlspecialchars((string) ($registrationConfirmation['departure_date'] ?? '')); ?></td>
              </tr>
              <tr>
                <th>Applied Profile</th>
                <td><?php echo htmlspecialchars((string) ($registrationConfirmation['profile_applied'] ?? '')); ?></td>
              </tr>
              <tr>
                <th>Network (IP / MAC)</th>
                <td><?php echo renderNetworkIdentityCell((string) ($registrationConfirmation['dhcp_ip'] ?? ''), (string) ($registrationConfirmation['modem_mac'] ?? '')); ?></td>
              </tr>
              <tr>
                <th>Status</th>
                <td><?php echo htmlspecialchars((string) ($registrationConfirmation['submission_status'] ?? '')); ?></td>
              </tr>
              <tr>
                <th>Details</th>
                <td><?php echo htmlspecialchars((string) (($registrationConfirmation['notes'] ?? '') !== '' ? $registrationConfirmation['notes'] : 'No additional notes')); ?></td>
              </tr>
              <tr>
                <th>Completed</th>
                <td><?php echo htmlspecialchars((string) ($registrationConfirmation['completed_at'] ?? '')); ?></td>
              </tr>
              <tr>
                <th>Guest Access ID</th>
                <td><strong><?php echo htmlspecialchars((string) ($registrationConfirmation['guest_access_id'] ?? '')); ?></strong></td>
              </tr>
              <tr>
                <th>Guest Access Code</th>
                <td><strong><?php echo htmlspecialchars((string) ($registrationConfirmation['guest_access_code'] ?? '')); ?></strong></td>
              </tr>
              <tr>
                <th>Code Expires</th>
                <td><?php echo htmlspecialchars((string) ($registrationConfirmation['guest_access_expires_at'] ?? '')); ?></td>
              </tr>
            </tbody>
          </table>
          <p class="sub">
            <?php if ($guestSelfServiceAuthMode === 'code_only'): ?>
              <strong>Save your Guest Access Code now.</strong> You will need it later to request early checkout or a departure extension.
            <?php else: ?>
              <strong>Save your Guest Access ID and code now.</strong> You will need them later to request early checkout or a departure extension.
            <?php endif; ?>
          </p>
        <?php endif; ?>

        <div class="button-row">
          <form class="inline-action-form" method="get" action="/">
            <button type="submit" class="mini-admin-btn">Register Another Guest</button>
          </form>
          <form class="inline-action-form" method="get" action="/">
            <input type="hidden" name="action" value="manage_reservation">
            <button type="submit" class="mini-admin-btn">Manage My Reservation</button>
          </form>
        </div>
      </section>
    <?php elseif ($action === 'faq'): ?>
      <section>
        <div class="section-title-row">
          <h2>Staff FAQ</h2>
        </div>
        <p class="sub">Common questions about keeping Guest Registration lined up with modem and lot changes.</p>

        <details class="admin-subcard faq-card">
          <summary><strong>I needed to change the modem at a lot in Gunslinger. Do I need to do anything in Guest Registration?</strong></summary>
          <p>Usually, no extra step is needed. The app refreshes modem and lot information from Gunslinger when the guest registration page is opened.</p>
          <p>If you want to confirm the change right away, or if a guest cannot be matched to the correct lot, an Admin user can run <strong>Modem and Lot Sync</strong> from Admin Settings. If the lot has an active guest, review that guest record afterward and use <strong>Reprovision</strong> only if their internet setup needs to be applied again.</p>
        </details>

        <details class="admin-subcard faq-card">
          <summary><strong>I swapped modems between two lots while troubleshooting RF problems. Do I need to do anything in Guest Registration?</strong></summary>
          <p>Usually, no. Once the swap is complete in Gunslinger, the app should pick up the updated modem and lot information during its normal refresh.</p>
          <p>Because modem swaps can affect active guests, it is still a good idea to check both affected lots in <strong>Active Guests</strong>. If either lot has a current guest and something looks wrong, an Admin user can run <strong>Modem and Lot Sync</strong>, then use <strong>Reprovision</strong> if service needs to be refreshed.</p>
        </details>

        <details class="admin-subcard faq-card">
          <summary><strong>I need to expand my facility and add more lots. Is there anything to change in the app?</strong></summary>
          <p>Start by adding the new lots and modem information in Gunslinger. The Guest Registration app should learn about those lots during its normal refresh.</p>
          <p>If you need the new lots to appear immediately, an Admin user can run <strong>Modem and Lot Sync</strong>. If the new lots are in a new service group, an Admin should also check Admin Settings before taking registrations: service group names, working profiles, vacant profiles, and checkout behavior may need to be updated.</p>
          <p>After setup, use <strong>Vacancy Mgmt</strong> to preview the new vacant lots and make sure their vacant profile looks correct before guests begin registering.</p>
        </details>
      </section>
    <?php elseif ($action === 'edit_guest'): ?>
      <section>
        <h2>Edit Guest</h2>
        <?php if ($editGuest === null): ?>
          <p class="sub">Guest record not found.</p>
        <?php else: ?>
          <form method="post" action="/?action=edit_guest&id=<?php echo (int) $editGuest['id']; ?>">
            <input type="hidden" name="guest_id" value="<?php echo (int) $editGuest['id']; ?>">
            <label>
              Name
              <input type="text" name="guest_name" value="<?php echo htmlspecialchars((string) $editGuest['guest_name']); ?>" required>
            </label>

            <label>
              Phone Number
              <input type="text" name="phone" value="<?php echo htmlspecialchars((string) $editGuest['phone']); ?>" required>
            </label>

            <label>
              Lot Number
              <select name="unit" required>
                <option value="">Select lot number</option>
                <?php foreach ($units as $unit): ?>
                  <option value="<?php echo htmlspecialchars($unit); ?>" <?php echo $unit === (string) $editGuest['unit'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($unit); ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <label>
              Arrival Date
              <input type="date" name="arrival_date" value="<?php echo htmlspecialchars((string) $editGuest['arrival_date']); ?>" required>
            </label>

            <label>
              Departure Date
              <input type="date" name="departure_date" value="<?php echo htmlspecialchars((string) $editGuest['departure_date']); ?>" required>
            </label>

            <label>
              Notes
              <input type="text" name="notes" value="<?php echo htmlspecialchars((string) ($editGuest['notes'] ?? '')); ?>">
            </label>

            <div class="button-row">
              <label class="inline-checkbox" for="checkout-now">
                <input id="checkout-now" type="checkbox" name="checkout_now" value="1">
                Checkout
              </label>
              <button type="submit">Save Guest Changes</button>
              <form class="inline-action-form" method="get" action="/">
                <input type="hidden" name="action" value="registration_list">
                <button type="submit" class="mini-admin-btn">Back to Registered Guests</button>
              </form>
            </div>
          </form>
        <?php endif; ?>
      </section>
    <?php elseif ($action === 'admin'): ?>
      <section>
        <div class="section-title-row">
          <h2>Admin Settings</h2>
          <button type="button" class="help-btn" data-help-key="admin-settings-page">Help</button>
        </div>

        <?php if (!$isAdmin): ?>
          <form method="post" action="/?action=admin">
            <label>
              Username
              <input type="text" name="username" required autocomplete="username">
            </label>
            <label>
              Password
              <input type="password" name="password" required autocomplete="current-password">
            </label>
            <div class="button-row">
              <button type="submit" name="admin_login" value="1">Staff Login</button>
            </div>
          </form>
        <?php else: ?>
          <?php $adminCardOrder = 1; ?>
          <div class="admin-cards">
          <details class="admin-subcard card-profiles" style="order: 4;">
            <summary><strong>Profiles</strong></summary>
            <p class="sub">Working and vacant profile settings used during registration, provisioning, checkout, and vacancy operations.</p>
            <div class="admin-nested-subcards">

          <details class="admin-nested-subcard card-default-working-profile">
            <summary><strong>Default Working Profile</strong></summary>
            <button type="button" class="help-btn help-btn-card" data-help-key="default-working-profile-card">Help</button>
            <p class="sub">Update the default profile used for Gunslinger profile updates. Each service group has its own required profile prefix.</p>
            <h4>Current Defaults</h4>
            <table>
              <thead>
                <tr>
                  <th>Service Group</th>
                  <th>Current Default Profile</th>
                  <th>Last Saved</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($serviceGroups as $sg): ?>
                  <?php $sg = (int) $sg; ?>
                  <tr>
                    <td><?php echo $sg; ?></td>
                    <td><?php echo htmlspecialchars((string) ($defaultProfilesBySg[$sg] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string) (($defaultProfilesUpdatedAtBySg[$sg] ?? '') !== '' ? $defaultProfilesUpdatedAtBySg[$sg] : 'Not yet saved')); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

            <?php foreach ($serviceGroups as $sg): ?>
              <?php $sg = (int) $sg; ?>
              <form class="sg-profile-form" method="post" action="/?action=admin">
                <input type="hidden" name="profile_sg" value="<?php echo $sg; ?>">
                <p class="sg-profile-form-title"><?php echo htmlspecialchars((string) ($serviceGroupLabelsBySg[$sg] ?? ('Service Group ' . $sg))); ?></p>
                <label>
                  Default Service Profile for <?php echo htmlspecialchars((string) ($serviceGroupLabelsBySg[$sg] ?? ('Service Group ' . $sg))); ?>
                  <input type="text" name="default_service_profile" value="<?php echo htmlspecialchars((string) ($defaultProfilesBySg[$sg] ?? '')); ?>" required>
                </label>
                <p class="sub">Validation rule: profile must start with <strong><?php echo htmlspecialchars(str_pad((string) $sg, 2, '0', STR_PAD_LEFT)); ?></strong> for SG <?php echo $sg; ?>.</p>
                <p class="sub">Last saved: <?php echo htmlspecialchars((string) (($defaultProfilesUpdatedAtBySg[$sg] ?? '') !== '' ? $defaultProfilesUpdatedAtBySg[$sg] : 'Not yet saved')); ?></p>
                <div class="button-row">
                  <button type="submit" name="save_admin_profile_sg" value="1">Save SG <?php echo $sg; ?> Profile</button>
                </div>
              </form>
            <?php endforeach; ?>
          </details>

          <details class="admin-nested-subcard card-vacant-profile-settings">
            <summary><strong>Vacant Profile Settings</strong></summary>
            <button type="button" class="help-btn help-btn-card" data-help-key="vacant-profile-settings-section">Help</button>
            <p class="sub">These values are saved in this app and used as target profiles during vacant-lot updates, checkout resets, voids, and auto-checkout.</p>
            <form method="post" action="/?action=admin">
              <input type="hidden" name="reprovision_token" value="<?php echo htmlspecialchars($reprovisionFormToken); ?>">
              <?php foreach ($serviceGroups as $sg): ?>
                <?php $sg = (int) $sg; ?>
                <label>
                  Vacant Profile for <?php echo htmlspecialchars((string) ($serviceGroupLabelsBySg[$sg] ?? ('Service Group ' . $sg))); ?>
                  <input type="text" name="vacant_profile_by_sg[<?php echo $sg; ?>]" value="<?php echo htmlspecialchars((string) ($vacantProfileOverridesBySg[$sg] ?? '')); ?>" required>
                </label>
                <p class="sub">Validation rule: profile must start with <strong><?php echo htmlspecialchars(str_pad((string) $sg, 2, '0', STR_PAD_LEFT)); ?></strong>.</p>
              <?php endforeach; ?>

              <div class="button-row">
                <button type="submit" name="save_vacant_profile_settings" value="1">Save Vacant Profile Settings</button>
              </div>
            </form>
          </details>

          <details class="admin-nested-subcard card-service-group-names">
            <summary><strong>Service Group Names</strong></summary>
            <button type="button" class="help-btn help-btn-card" data-help-key="service-group-names-card">Help</button>
            <p class="sub">Set friendly names shown in headings and titles. The SG number remains the system identifier.</p>
            <?php foreach ($serviceGroups as $sg): ?>
              <?php $sg = (int) $sg; ?>
              <form class="sg-profile-form" method="post" action="/?action=admin">
                <input type="hidden" name="sg_name_sg" value="<?php echo $sg; ?>">
                <p class="sg-profile-form-title">Service Group <?php echo $sg; ?></p>
                <label>
                  Display Name for SG <?php echo $sg; ?>
                  <input type="text" name="service_group_name" value="<?php echo htmlspecialchars((string) ($serviceGroupNamesBySg[$sg] ?? '')); ?>" placeholder="Optional friendly name">
                </label>
                <div class="button-row">
                  <button type="submit" name="save_service_group_name_sg" value="1">Save SG <?php echo $sg; ?> Name</button>
                </div>
              </form>
            <?php endforeach; ?>
          </details>

            </div>
          </details>

          <details class="admin-subcard card-global-checkout-time" style="order: 1;">
            <summary><strong>Global Checkout Time</strong></summary>
            <button type="button" class="help-btn help-btn-card" data-help-key="global-checkout-time-card">Help</button>
            <p class="sub">Reservations due by this time are auto-checked-out and moved to each SG vacant profile.</p>
            <form method="post" action="/?action=admin">
              <label>
                Checkout Time (24-hour)
                <input type="time" name="global_checkout_time" value="<?php echo htmlspecialchars($globalCheckoutTime); ?>" required>
              </label>
              <div class="button-row">
                <button type="submit" name="save_global_checkout_time" value="1">Save Checkout Time</button>
              </div>
            </form>
          </details>

          <details class="admin-subcard card-logs" style="order: 5;">
            <summary><strong>Diagnostics and Logs</strong></summary>
            <p class="sub">Operational diagnostics, provisioning activity, captive portal events, guest access events, and SNMP audit logs.</p>
            <div class="admin-nested-subcards admin-log-subcards">

          <details class="admin-nested-subcard card-auto-checkout-diagnostics">
            <summary><strong>Auto-Checkout Diagnostics</strong></summary>
            <button type="button" class="help-btn help-btn-card" data-help-key="auto-checkout-diagnostics-card">Help</button>
            <p class="sub">Visibility for the most recent auto-checkout run.</p>
            <table>
              <tbody>
                <tr>
                  <th>Last Run</th>
                  <td><?php echo htmlspecialchars($autoCheckoutLastRunAt !== '' ? $autoCheckoutLastRunAt : 'Not run yet'); ?></td>
                </tr>
                <tr>
                  <th>Due Count</th>
                  <td><?php echo (int) $autoCheckoutLastDueCount; ?></td>
                </tr>
                <tr>
                  <th>Success Count</th>
                  <td><?php echo (int) $autoCheckoutLastSuccessCount; ?></td>
                </tr>
              </tbody>
            </table>

            <h4>Failure Reasons</h4>
            <?php if ($autoCheckoutLastFailureReasons === []): ?>
              <p class="sub">No failures recorded in the most recent run.</p>
            <?php else: ?>
              <table>
                <thead>
                  <tr>
                    <th>Reason</th>
                    <th>Count</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($autoCheckoutLastFailureReasons as $reason => $count): ?>
                    <tr>
                      <td><?php echo htmlspecialchars((string) $reason); ?></td>
                      <td><?php echo (int) $count; ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </details>

          <details class="admin-nested-subcard card-modem-lot-sync">
            <summary><strong>Modem and Lot Sync</strong></summary>
            <button type="button" class="help-btn help-btn-card" data-help-key="modem-lot-sync-page">Help</button>
            <p class="sub">Sync modem data from Gunslinger, remap existing guest lot numbers by modem MAC, and refresh working-profile bootfile cache for configured SGs: <strong><?php echo htmlspecialchars(implode(',', array_map('strval', $serviceGroups))); ?></strong>.</p>
            <form method="post" action="/?action=admin">
              <div class="button-row">
                <button type="submit" name="refresh_and_remap_lots" value="1">Refresh Modems and Remap Lots</button>
              </div>
            </form>

            <h4>Lease Audit (Configured SGs)</h4>
            <p class="sub">Lots listed below currently have no active DDNet lease for their modem MAC and require staff review.</p>
            <table>
              <thead>
                <tr>
                  <th>Service Group</th>
                  <th>Lot</th>
                  <th>Modem MAC</th>
                  <th>Last Lease IP</th>
                  <th>Lease Check Result</th>
                  <th>Checked At</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($offlineModemRows === []): ?>
                  <tr>
                    <td colspan="6">No offline modem lease rows detected for configured service groups.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($offlineModemRows as $offlineRow): ?>
                    <tr>
                      <td><?php echo (int) ($offlineRow['sg'] ?? 0); ?></td>
                      <td><?php echo htmlspecialchars((string) ($offlineRow['unit'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($offlineRow['mac'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) (($offlineRow['lease_ip'] ?? '') !== '' ? $offlineRow['lease_ip'] : 'Not available')); ?></td>
                      <td><?php echo htmlspecialchars((string) (($offlineRow['lease_error'] ?? '') !== '' ? $offlineRow['lease_error'] : 'No active lease returned by DDNet.')); ?></td>
                      <td><?php echo htmlspecialchars((string) (($offlineRow['lease_checked_at'] ?? '') !== '' ? $offlineRow['lease_checked_at'] : 'Not checked yet')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </details>

          <details class="admin-nested-subcard admin-log-subcard card-reprovision-log">
            <summary><strong>Reprovision Log</strong></summary>
            <form method="post" action="/?action=admin" class="button-row">
              <button type="submit" name="clear_log_key" value="reprovision" class="danger-btn" onclick="return confirm('Clear only the Reprovision Log?');">Clear Reprovision Log</button>
            </form>
            <p class="sub">Recent reprovision actions are recorded here. Guest notes keep only the latest reprovision summary.</p>
            <div class="log-filter-row">
              <label>
                Filter by Guest Name
                <input type="text" id="reprov-filter-guest" placeholder="Type guest name">
              </label>
              <label>
                Filter by Unit
                <input type="text" id="reprov-filter-unit" placeholder="Type unit (e.g. Lot A2)">
              </label>
            </div>
            <div class="log-table-container">
            <table class="sticky-header-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Guest</th>
                  <th>Unit</th>
                  <th>SG</th>
                  <th>MAC</th>
                  <th>Profile</th>
                  <th>Status</th>
                  <th>Details</th>
                  <th>Actor</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($reprovisionLogs === []): ?>
                  <tr>
                    <td colspan="9">No reprovision actions logged yet.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($reprovisionLogs as $log): ?>
                    <tr class="reprov-log-row" data-guest-name="<?php echo htmlspecialchars(strtolower((string) $log['guest_name'])); ?>" data-unit="<?php echo htmlspecialchars(strtolower((string) $log['unit'])); ?>">
                      <td><?php echo htmlspecialchars((string) $log['created_at']); ?></td>
                      <td><?php echo htmlspecialchars((string) $log['guest_name']); ?> (#<?php echo (int) $log['guest_id']; ?>)</td>
                      <td><?php echo htmlspecialchars((string) $log['unit']); ?></td>
                      <td><?php echo (int) $log['sg']; ?></td>
                      <td><?php echo htmlspecialchars((string) $log['modem_mac']); ?></td>
                      <td><?php echo htmlspecialchars((string) $log['profile_applied']); ?></td>
                      <td><?php echo htmlspecialchars((string) $log['status']); ?></td>
                      <td><?php echo htmlspecialchars((string) $log['details']); ?></td>
                      <td><?php echo htmlspecialchars((string) $log['actor']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
            </div>
          </details>

          <details class="admin-nested-subcard admin-log-subcard card-api-captive-portal-log">
            <summary><strong>API Captive Portal Log</strong></summary>
            <form method="post" action="/?action=admin" class="button-row">
              <button type="submit" name="clear_log_key" value="captive_portal" class="danger-btn" onclick="return confirm('Clear only the API Captive Portal Log?');">Clear API Captive Portal Log</button>
            </form>
            <p class="sub">Requests to the RFC captive portal API advertised by DHCP Option 114 and legacy captive probe handlers.</p>
            <div class="log-table-container">
            <table class="sticky-header-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Client IP</th>
                  <th>Host</th>
                  <th>Method</th>
                  <th>URI</th>
                  <th>User Agent</th>
                  <th>Response</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($captivePortalApiLogs === []): ?>
                  <tr>
                    <td colspan="7">No captive portal API requests logged yet.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($captivePortalApiLogs as $log): ?>
                    <tr>
                      <td><?php echo htmlspecialchars((string) ($log['created_at'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['client_ip'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['host_header'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['request_method'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['request_uri'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['user_agent'] ?? '')); ?></td>
                      <td><code><?php echo htmlspecialchars((string) ($log['response_json'] ?? '')); ?></code></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
            </div>
          </details>

          <details class="admin-nested-subcard admin-log-subcard card-vacant-profile-log">
            <summary><strong>Vacant Profile Apply Log</strong></summary>
            <form method="post" action="/?action=admin" class="button-row">
              <button type="submit" name="clear_log_key" value="vacant_profile" class="danger-btn" onclick="return confirm('Clear only the Vacant Profile Apply Log?');">Clear Vacant Profile Apply Log</button>
            </form>
            <p class="sub">Most recent attempts to update vacant lot profiles in Gunslinger.</p>
            <div class="log-table-container">
            <table class="sticky-header-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>SG</th>
                  <th>Lot</th>
                  <th>MAC</th>
                  <th>Old Profile</th>
                  <th>Target Profile</th>
                  <th>Status</th>
                  <th>Details</th>
                  <th>Actor</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($vacantProfileLogs === []): ?>
                  <tr>
                    <td colspan="9">No vacant profile apply actions logged yet.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($vacantProfileLogs as $log): ?>
                    <tr>
                      <td><?php echo htmlspecialchars((string) ($log['created_at'] ?? '')); ?></td>
                      <td><?php echo (int) ($log['sg'] ?? 0); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['unit'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['modem_mac'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['old_profile'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['target_profile'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['status'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['details'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['actor'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
            </div>
          </details>

          <details class="admin-nested-subcard admin-log-subcard card-modem-scoped-log">
            <summary><strong>Modem Scoped Reservation Log</strong></summary>
            <form method="post" action="/?action=admin" class="button-row">
              <button type="submit" name="clear_log_key" value="modem_scoped" class="danger-btn" onclick="return confirm('Clear only the Modem Scoped Reservation Log?');">Clear Modem Scoped Reservation Log</button>
            </form>
            <p class="sub">Recent DDNet modem-scoped reservation submissions for guest registrations.</p>
            <div class="log-table-container">
            <table class="sticky-header-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Guest</th>
                  <th>Lot</th>
                  <th>SG</th>
                  <th>Modem MAC</th>
                  <th>Client IP</th>
                  <th>Status</th>
                  <th>Details</th>
                  <th>Actor</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($modemScopedReservationLogs === []): ?>
                  <tr>
                    <td colspan="9">No modem-scoped reservation activity logged yet.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($modemScopedReservationLogs as $log): ?>
                    <tr>
                      <td><?php echo htmlspecialchars((string) ($log['created_at'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['guest_name'] ?? '')); ?> (#<?php echo (int) ($log['guest_id'] ?? 0); ?>)</td>
                      <td><?php echo htmlspecialchars((string) ($log['unit'] ?? '')); ?></td>
                      <td><?php echo (int) ($log['sg'] ?? 0); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['modem_mac'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['client_ip'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['status'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['details'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($log['actor'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
            </div>
          </details>

          <details class="admin-nested-subcard admin-log-subcard card-guest-access-log">
            <summary><strong>Guest Access Security Log</strong></summary>
            <form method="post" action="/?action=admin" class="button-row">
              <button type="submit" name="clear_log_key" value="guest_access" class="danger-btn" onclick="return confirm('Clear only the Guest Access Security Log?');">Clear Guest Access Security Log</button>
            </form>
            <p class="sub">Recent guest self-service login success, failure, lockout, and unlock events.</p>
            <div class="log-table-container">
            <table class="sticky-header-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Access ID</th>
                  <th>IP Address</th>
                  <th>Event</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($guestAccessEventLogs === []): ?>
                  <tr>
                    <td colspan="4">No guest access security events logged yet.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($guestAccessEventLogs as $event): ?>
                    <tr>
                      <td><?php echo htmlspecialchars((string) ($event['created_at'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($event['guest_access_id'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($event['ip_address'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string) ($event['event_type'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
            </div>
          </details>

          <details class="admin-nested-subcard admin-log-subcard card-snmp-audit-log">
            <summary><strong>SNMP Audit Log</strong></summary>
            <p class="sub">Latest device reboot audit lines from the SNMP audit file.</p>
            <div class="log-table-container">
            <table class="sticky-header-table">
              <thead>
                <tr>
                  <th>Line</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($snmpAuditLogLines === []): ?>
                  <tr>
                    <td>No SNMP audit lines available.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($snmpAuditLogLines as $line): ?>
                    <tr>
                      <td><code><?php echo htmlspecialchars((string) $line); ?></code></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
            </div>
          </details>

            </div>
          </details>

          <?php if (false): ?>
          <details class="admin-subcard"<?php echo $openVacantProfileCard ? ' open' : ''; ?>>
            <summary><strong>Vacant Lot Profile Management</strong></summary>
            <p class="sub">Preview lots with no active registration and compare current Gunslinger profile to target vacant profile.</p>

            <details class="vacant-mgmt-section vacant-mgmt-settings"<?php echo $openVacantSettingsSection ? ' open' : ''; ?>>
              <summary><strong>Vacant Profile Settings</strong></summary>
              <p class="sub">These values are saved in this app and used as target profiles during vacant-lot updates.</p>
              <form method="post" action="/?action=admin">
                <input type="hidden" name="reprovision_token" value="<?php echo htmlspecialchars($reprovisionFormToken); ?>">
                <?php foreach ($serviceGroups as $sg): ?>
                  <?php $sg = (int) $sg; ?>
                  <label>
                    Vacant Profile for <?php echo htmlspecialchars((string) ($serviceGroupLabelsBySg[$sg] ?? ('Service Group ' . $sg))); ?>
                    <input type="text" name="vacant_profile_by_sg[<?php echo $sg; ?>]" value="<?php echo htmlspecialchars((string) ($vacantProfileOverridesBySg[$sg] ?? '')); ?>" required>
                  </label>
                  <p class="sub">Validation rule: profile must start with <strong><?php echo htmlspecialchars(str_pad((string) $sg, 2, '0', STR_PAD_LEFT)); ?></strong>.</p>
                <?php endforeach; ?>

                <div class="button-row">
                  <button type="submit" name="save_vacant_profile_settings" value="1">Save Vacant Profile Settings</button>
                </div>
              </form>
            </details>

            <section class="vacant-mgmt-section vacant-mgmt-preview">
              <h4>Vacant Lot Preview</h4>
              <p class="sub">Refreshes Gunslinger data, computes vacant lots, and shows what each selected lot would be set to.</p>
              <form method="post" action="/?action=admin">
                <input type="hidden" name="reprovision_token" value="<?php echo htmlspecialchars($reprovisionFormToken); ?>">
                <div class="button-row">
                  <button type="submit" name="preview_vacant_profile_audit" value="1">Preview Vacant Lots from Gunslinger</button>
                </div>
              </form>
              <?php if ($showVacantAuditResults): ?>
                <form method="post" action="/?action=admin">
                <input type="hidden" name="reprovision_token" value="<?php echo htmlspecialchars($reprovisionFormToken); ?>">
                <section class="notice warning vacant-mgmt-results">
                  <p><strong>Preview generated:</strong> <?php echo htmlspecialchars($vacantAuditRefreshedAt); ?> (SGs: <?php echo htmlspecialchars($vacantAuditSource); ?>)</p>
                  <p>Total vacant lots: <strong><?php echo (int) $vacantAuditTotals['total_vacant']; ?></strong> | Needs change: <strong><?php echo (int) $vacantAuditTotals['needs_change']; ?></strong> | Already on target: <strong><?php echo (int) $vacantAuditTotals['already_target']; ?></strong> | Missing profile config: <strong><?php echo (int) $vacantAuditTotals['config_missing']; ?></strong></p>
                  <p class="sub">Rows needing change are auto-selected by default. Apply action is next in implementation.</p>
                </section>

                <h4 class="vacant-defaults-title">Current Vacant Profile Defaults</h4>
                <table>
                  <thead>
                    <tr>
                      <th>Service Group</th>
                      <th>Vacant Profile Target</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($serviceGroups as $sg): ?>
                      <?php $sg = (int) $sg; ?>
                      <tr>
                        <td><?php echo htmlspecialchars((string) ($serviceGroupLabelsBySg[$sg] ?? ('Service Group ' . $sg))); ?></td>
                        <td><?php echo htmlspecialchars((string) ($vacantProfileOverridesBySg[$sg] ?? '')); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>

                <?php if ($vacantAuditRowsBySg === []): ?>
                  <p class="sub">No vacant lots found for configured service groups.</p>
                <?php endif; ?>

                <?php foreach ($vacantAuditRowsBySg as $sg => $rowsBySg): ?>
                  <h4><?php echo htmlspecialchars((string) ($serviceGroupLabelsBySg[(int) $sg] ?? ('Service Group ' . (int) $sg))); ?></h4>
                  <div class="vacant-preview-controls">
                    <label class="inline-checkbox">
                      <input type="checkbox" class="vacant-sg-toggle" data-sg="<?php echo (int) $sg; ?>">
                      Select/Unselect all for SG <?php echo (int) $sg; ?>
                    </label>
                  </div>
                  <table class="sticky-header-table vacant-preview-table">
                    <thead>
                      <tr>
                        <th>Select</th>
                        <th>Lot</th>
                        <th>MAC</th>
                        <th>Current Profile</th>
                        <th>Target Vacant Profile</th>
                        <th>State</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($rowsBySg as $row): ?>
                        <?php $rowNeedsChange = (bool) ($row['needs_change'] ?? false); ?>
                        <?php $rowHasConfig = (bool) ($row['has_config'] ?? false); ?>
                      <?php $rowPayload = base64_encode((string) json_encode([
                        'sg' => (int) $sg,
                        'unit' => (string) ($row['unit'] ?? ''),
                        'mac' => (string) ($row['mac'] ?? ''),
                      ], JSON_UNESCAPED_SLASHES)); ?>
                        <tr>
                          <td>
                          <input type="checkbox" class="vacant-row-checkbox" name="vacant_apply_rows[]" value="<?php echo htmlspecialchars($rowPayload); ?>" data-sg="<?php echo (int) $sg; ?>" <?php echo ($rowNeedsChange && $rowHasConfig) ? 'checked' : ''; ?>>
                          </td>
                          <td><?php echo htmlspecialchars((string) ($row['unit'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string) ($row['mac'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string) ($row['current_profile'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string) ($row['target_profile'] ?? '')); ?></td>
                          <td>
                            <?php if (!$rowHasConfig): ?>
                              Missing target profile
                            <?php elseif ($rowNeedsChange): ?>
                              Needs update
                            <?php else: ?>
                              Already target
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endforeach; ?>

                <div class="button-row">
                  <button type="submit" name="request_apply_vacant_profiles" value="1" class="danger-btn">Apply Selected Vacant Profiles</button>
                </div>
                </form>

                <?php if ($showVacantApplyConfirm): ?>
                  <section class="notice warning vacant-mgmt-results">
                    <p><strong>Confirm apply vacant profiles?</strong></p>
                    <p>Proceed will update <strong><?php echo count($vacantApplyPendingRows); ?></strong> selected lot(s) in Gunslinger.</p>
                    <form method="post" action="/?action=admin">
                      <input type="hidden" name="reprovision_token" value="<?php echo htmlspecialchars($reprovisionFormToken); ?>">
                      <?php foreach ($vacantApplyPendingRows as $pendingRow): ?>
                        <?php $pendingPayload = base64_encode((string) json_encode($pendingRow, JSON_UNESCAPED_SLASHES)); ?>
                        <input type="hidden" name="vacant_apply_payload[]" value="<?php echo htmlspecialchars($pendingPayload); ?>">
                      <?php endforeach; ?>
                      <div class="button-row">
                        <button type="submit" name="confirm_apply_vacant_profiles" value="proceed" class="danger-btn">Proceed</button>
                        <button type="submit" name="confirm_apply_vacant_profiles" value="cancel" class="secondary-btn">Cancel</button>
                      </div>
                    </form>
                  </section>
                <?php endif; ?>

                <?php if ($showVacantApplyResults): ?>
                  <section class="notice warning vacant-mgmt-results">
                    <p><strong>Apply Results</strong></p>
                    <p>Attempted: <strong><?php echo (int) $vacantApplySummary['attempted']; ?></strong> | Updated: <strong><?php echo (int) $vacantApplySummary['updated']; ?></strong> | Failed: <strong><?php echo (int) $vacantApplySummary['failed']; ?></strong></p>
                  </section>
                  <table class="sticky-header-table vacant-apply-results-table">
                    <thead>
                      <tr>
                        <th>SG</th>
                        <th>Lot</th>
                        <th>MAC</th>
                        <th>Current Profile</th>
                        <th>Target Profile</th>
                        <th>Status</th>
                        <th>Details</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($vacantApplyRunResults as $applyRow): ?>
                        <tr>
                          <td><?php echo (int) ($applyRow['sg'] ?? 0); ?></td>
                          <td><?php echo htmlspecialchars((string) ($applyRow['unit'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string) ($applyRow['mac'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string) ($applyRow['old_profile'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string) ($applyRow['target_profile'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string) ($applyRow['status'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string) ($applyRow['details'] ?? '')); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              <?php endif; ?>
            </section>

            <p class="sub">Recent apply activity now lives on the centralized Logs page.</p>
          </details>
          <?php endif; ?>

          <details class="admin-subcard card-application-colors" style="order: 3;">
            <summary><strong>Application Colors</strong></summary>
            <button type="button" class="help-btn help-btn-card" data-help-key="application-colors-card">Help</button>
            <p class="sub">Choose a base color preset for the entire application. Click a color tile to select it, then save.</p>
            <form class="app-colors-form" method="post" action="/?action=admin">
              <div class="theme-preview-grid">
                <?php foreach ($colorPresetOptions as $presetKey => $presetLabel): ?>
                  <label class="theme-preview-choice">
                    <input type="radio" name="color_preset" value="<?php echo htmlspecialchars($presetKey); ?>" <?php echo $presetKey === $selectedColorPreset ? 'checked' : ''; ?> required>
                    <span class="theme-preview-chip preset-<?php echo htmlspecialchars($presetKey); ?>"><span class="chip-swatch" aria-hidden="true"></span><span><?php echo htmlspecialchars($presetLabel); ?></span></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="button-row">
                <button type="submit" name="save_color_preset" value="1">Save Color Preset</button>
                <button type="submit" name="reset_color_preset" value="1" class="secondary-btn">Reset to Forest</button>
              </div>
            </form>
          </details>

          <details class="admin-subcard card-guest-self-service-access" style="order: 2;">
            <summary><strong>Guest Self-Service Access</strong></summary>
            <button type="button" class="help-btn help-btn-card" data-help-key="guest-self-service-settings-card">Help</button>
            <p class="sub">Configure whether guests can manage their reservation using generated access credentials.</p>
            <form method="post" action="/?action=admin">
              <label class="inline-checkbox">
                <input type="checkbox" name="guest_self_service_enabled" value="1" <?php echo $guestSelfServiceEnabled ? 'checked' : ''; ?>>
                Enable Guest Self-Service
              </label>
              <label class="inline-checkbox">
                <input type="checkbox" name="guest_self_service_allow_early_checkout" value="1" <?php echo $guestSelfServiceAllowEarlyCheckout ? 'checked' : ''; ?>>
                Allow Early Checkout Requests
              </label>
              <label class="inline-checkbox">
                <input type="checkbox" name="guest_self_service_allow_extension" value="1" <?php echo $guestSelfServiceAllowExtension ? 'checked' : ''; ?>>
                Allow Departure Extension Requests
              </label>
              <label class="inline-checkbox">
                <input type="checkbox" name="guest_self_service_require_reason" value="1" <?php echo $guestSelfServiceRequireReason ? 'checked' : ''; ?>>
                Require Guest Reason
              </label>
              <label>
                Approval Mode
                <select name="guest_self_service_approval_mode" required>
                  <option value="manual" <?php echo $guestSelfServiceApprovalMode === 'manual' ? 'selected' : ''; ?>>Manual Approval</option>
                  <option value="auto" <?php echo $guestSelfServiceApprovalMode === 'auto' ? 'selected' : ''; ?>>Auto-Approve If Policy Passes</option>
                </select>
              </label>
              <label>
                Guest Login Mode
                <select name="guest_self_service_auth_mode" required>
                  <option value="id_and_code" <?php echo $guestSelfServiceAuthMode === 'id_and_code' ? 'selected' : ''; ?>>Require Access ID and Access Code</option>
                  <option value="code_only" <?php echo $guestSelfServiceAuthMode === 'code_only' ? 'selected' : ''; ?>>Require Access Code Only</option>
                </select>
              </label>
              <label>
                Max Extension Days
                <input type="number" name="guest_self_service_max_extension_days" min="1" max="30" value="<?php echo (int) $guestSelfServiceMaxExtensionDays; ?>" required>
              </label>
              <label>
                Max Failed Login Attempts
                <input type="number" name="guest_self_service_max_failed_attempts" min="1" max="20" value="<?php echo (int) $guestSelfServiceMaxFailedAttempts; ?>" required>
              </label>
              <label>
                Lockout Duration (minutes)
                <input type="number" name="guest_self_service_lockout_minutes" min="1" max="120" value="<?php echo (int) $guestSelfServiceLockoutMinutes; ?>" required>
              </label>
              <label>
                IP Failure Window (minutes)
                <input type="number" name="guest_self_service_ip_window_minutes" min="1" max="120" value="<?php echo (int) $guestSelfServiceIpWindowMinutes; ?>" required>
              </label>
              <label>
                IP Failure Threshold (0 disables)
                <input type="number" name="guest_self_service_ip_failure_threshold" min="0" max="200" value="<?php echo (int) $guestSelfServiceIpFailureThreshold; ?>" required>
              </label>
              <div class="button-row">
                <button type="submit" name="save_guest_self_service_settings" value="1">Save Guest Self-Service Settings</button>
              </div>
            </form>
          </details>

          <?php $adminCardOrder = 6; ?>
          <?php if ($isMasterAdmin): ?>
            <details class="admin-subcard card-deployment-tools" style="order: <?php echo $adminCardOrder++; ?>;"<?php echo ($showClearRegistrationsConfirm || $showFactoryResetConfirm || $showServiceGroupResyncPrompt) ? ' open' : ''; ?>>
              <summary><strong>Deployment Tools</strong></summary>
              <button type="button" class="help-btn help-btn-card" data-help-key="deployment-tools-card">Help</button>
              <p class="sub">Use this only during initial container deployment or controlled reset operations.</p>
              <form method="post" action="/?action=admin">
                <div class="button-row">
                  <button type="submit" name="request_clear_registrations" value="1" class="danger-btn">Clear All Registrations</button>
                  <button type="submit" name="request_factory_reset" value="1" class="danger-btn">Full Factory Reset</button>
                </div>
              </form>

              <?php if ($showClearRegistrationsConfirm): ?>
                <section class="notice warning">
                  <p><strong>Confirm clear registrations?</strong></p>
                  <p>Proceed will create backup copies (when rows exist) and then clear registrations, modem cache, and related logs/queues.</p>
                  <p>This will remove <strong><?php echo (int) $registrationCountForClear; ?></strong> registration record(s) and reset guest-request, guest-access-event, reprovision, and vacant-profile logs.</p>
                  <form method="post" action="/?action=admin">
                    <div class="button-row">
                      <button type="submit" name="confirm_clear_registrations" value="proceed" class="danger-btn">Proceed</button>
                      <button type="submit" name="confirm_clear_registrations" value="cancel" class="secondary-btn">Cancel</button>
                    </div>
                  </form>
                </section>
              <?php endif; ?>

              <?php if ($showFactoryResetConfirm): ?>
                <section class="notice warning">
                  <p><strong>Confirm full factory reset?</strong></p>
                  <p>Proceed will create backup copies (when rows exist), then clear registrations, modem cache, guest logs/queues, and app settings.</p>
                  <p>All non-master admin users will be removed. Master admin users are preserved.</p>
                  <form method="post" action="/?action=admin">
                    <div class="button-row">
                      <button type="submit" name="confirm_factory_reset" value="proceed" class="danger-btn">Proceed Full Factory Reset</button>
                      <button type="submit" name="confirm_factory_reset" value="cancel" class="secondary-btn">Cancel</button>
                    </div>
                  </form>
                </section>
              <?php endif; ?>

              <?php if ($showServiceGroupResyncPrompt): ?>
                <section class="notice warning">
                  <p><strong><?php echo htmlspecialchars($serviceGroupResyncPromptContext); ?> complete. Are service group settings configured?</strong></p>
                  <p>If SG names/profiles are ready, run modem and lot resync now to repopulate cache.</p>
                  <form method="post" action="/?action=admin">
                    <div class="button-row">
                      <button type="submit" name="refresh_and_remap_lots" value="1">Yes, Resync Modems and Lots Now</button>
                    </div>
                  </form>
                </section>
              <?php endif; ?>
            </details>
          <?php endif; ?>

          <?php if (false): ?>
          <details class="admin-subcard">
            <summary><strong>Reprovision Log</strong></summary>
            <p class="sub">Recent reprovision actions are recorded here. Guest notes keep only the latest reprovision summary.</p>
            <div class="log-filter-row">
              <label>
                Filter by Guest Name
                <input type="text" id="reprov-filter-guest" placeholder="Type guest name">
              </label>
              <label>
                Filter by Unit
                <input type="text" id="reprov-filter-unit" placeholder="Type unit (e.g. Lot A2)">
              </label>
            </div>
            <table class="sticky-header-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Guest</th>
                  <th>Unit</th>
                  <th>SG</th>
                  <th>MAC</th>
                  <th>Profile</th>
                  <th>Status</th>
                  <th>Details</th>
                  <th>Actor</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($reprovisionLogs === []): ?>
                  <tr>
                    <td colspan="9">No reprovision actions logged yet.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($reprovisionLogs as $log): ?>
                    <tr class="reprov-log-row" data-guest-name="<?php echo htmlspecialchars(strtolower((string) $log['guest_name'])); ?>" data-unit="<?php echo htmlspecialchars(strtolower((string) $log['unit'])); ?>">
                      <td><?php echo htmlspecialchars((string) $log['created_at']); ?></td>
                      <td><?php echo htmlspecialchars((string) $log['guest_name']); ?> (#<?php echo (int) $log['guest_id']; ?>)</td>
                      <td><?php echo htmlspecialchars((string) $log['unit']); ?></td>
                      <td><?php echo (int) $log['sg']; ?></td>
                      <td><?php echo htmlspecialchars((string) $log['modem_mac']); ?></td>
                      <td><?php echo htmlspecialchars((string) $log['profile_applied']); ?></td>
                      <td><?php echo htmlspecialchars((string) $log['status']); ?></td>
                      <td><?php echo htmlspecialchars((string) $log['details']); ?></td>
                      <td><?php echo htmlspecialchars((string) $log['actor']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </details>
          <?php endif; ?>

          <?php if ($isAdmin): ?>
            <details class="admin-subcard card-users" style="order: <?php echo $adminCardOrder++; ?>;">
              <summary><strong>Users</strong></summary>
              <p class="sub">Manage staff and admin user access.</p>
              <div class="admin-nested-subcards">

            <details class="admin-nested-subcard card-existing-users">
              <summary><strong>Existing Users</strong></summary>
              <button type="button" class="help-btn help-btn-card" data-help-key="existing-users-card">Help</button>
              <table class="sticky-header-table existing-users-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Active</th>
                    <th>Created</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($usersList as $user): ?>
                    <?php
                      $userId = (int) $user['id'];
                      $username = (string) $user['username'];
                      $role = (string) $user['role'];
                      $isMasterUser = $role === 'master_admin' || $username === 'jim';
                    ?>
                    <tr>
                      <td><?php echo $userId; ?></td>
                      <td><?php echo htmlspecialchars($username); ?></td>
                      <td><?php echo htmlspecialchars($role); ?></td>
                      <td><?php echo ((int) $user['is_active'] === 1) ? 'Yes' : 'No'; ?></td>
                      <td><?php echo htmlspecialchars((string) $user['created_at']); ?></td>
                      <td>
                        <?php if ($isMasterUser): ?>
                          <span class="sub">Protected</span>
                        <?php else: ?>
                          <div class="user-row-scroll">
                            <form class="user-row-form" method="post" action="/?action=admin">
                              <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
                              <select name="edit_role" required>
                                <option value="staff" <?php echo ($role === 'staff' || $role === 'user') ? 'selected' : ''; ?>>Staff</option>
                                <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                              </select>
                              <select name="edit_is_active" required>
                                <option value="1" <?php echo ((int) $user['is_active'] === 1) ? 'selected' : ''; ?>>Active</option>
                                <option value="0" <?php echo ((int) $user['is_active'] === 0) ? 'selected' : ''; ?>>Inactive</option>
                              </select>
                              <input type="password" name="edit_password" placeholder="New password (optional)" autocomplete="new-password">
                              <input type="password" name="confirm_edit_password" placeholder="Confirm new password" autocomplete="new-password">
                              <div class="user-row-actions">
                                <button type="submit" name="update_user" value="1">Save</button>
                                <button type="submit" name="request_delete_user" value="1" class="danger-btn">Delete</button>
                              </div>
                            </form>
                          </div>
                          <?php if ($pendingDeleteUserId === $userId): ?>
                            <div class="user-row-scroll">
                              <form class="user-delete-confirm-form" method="post" action="/?action=admin">
                                <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
                                <span class="sub">Confirm delete <?php echo htmlspecialchars($username); ?>?</span>
                                <div class="user-row-actions">
                                  <button type="submit" name="confirm_delete_user" value="proceed" class="danger-btn">Proceed</button>
                                  <button type="submit" name="confirm_delete_user" value="cancel" class="secondary-btn">Cancel</button>
                                </div>
                              </form>
                            </div>
                          <?php endif; ?>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </details>

            <details class="admin-nested-subcard card-user-management">
              <summary><strong>User Management</strong></summary>
              <button type="button" class="help-btn help-btn-card" data-help-key="user-management-card">Help</button>
              <form method="post" action="/?action=admin" autocomplete="off">
                <label>
                  New Username
                  <input type="text" name="new_username" required autocomplete="off" autocapitalize="off" spellcheck="false">
                </label>

                <label>
                  New Password
                  <input type="password" name="new_password" required autocomplete="new-password">
                </label>

                <label>
                  Confirm New Password
                  <input type="password" name="confirm_new_password" required autocomplete="new-password">
                </label>

                <label>
                  Role
                  <select name="new_role" required>
                    <option value="staff">Staff</option>
                    <option value="admin">Admin</option>
                  </select>
                </label>

                <div class="button-row">
                  <button type="submit" name="create_user" value="1">Create User</button>
                </div>
              </form>
            </details>

              </div>
            </details>
          <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <section>
        <form method="post" action="/?action=register">
          <label>
            Name
            <input type="text" name="guest_name" required>
          </label>

          <label>
            Phone Number
            <input type="text" name="phone" required>
          </label>

          <label>
            Arrival Date
            <input id="arrival-date" type="date" name="arrival_date" value="<?php echo date('Y-m-d'); ?>" required>
          </label>

          <label>
            Departure Date
            <input id="departure-date" type="date" name="departure_date" required>
          </label>

          <label>
            Lot Number
            <?php if ($isPropertyUser || $guestAutoLotSelection === ''): ?>
              <select id="unit-select" name="lot_selection" required>
                <option value="">Select lot number</option>
                <?php foreach ($unitsByServiceGroup as $sg => $sgUnits): ?>
                  <optgroup label="<?php echo htmlspecialchars((string) ($serviceGroupLabelsBySg[(int) $sg] ?? ('Service Group ' . (int) $sg))); ?>">
                  <?php foreach ($sgUnits as $unit): ?>
                    <option value="<?php echo htmlspecialchars((string) $sg . '|' . (string) $unit); ?>"><?php echo htmlspecialchars($unit); ?></option>
                  <?php endforeach; ?>
                  </optgroup>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input type="text" value="<?php echo htmlspecialchars($guestAutoLotUnit !== '' ? $guestAutoLotUnit : 'Unable to detect lot'); ?>" readonly>
              <input type="hidden" name="lot_selection" value="<?php echo htmlspecialchars($guestAutoLotSelection); ?>">
            <?php endif; ?>
          </label>

          <p id="unit-availability-hint" class="sub">
            <?php if ($isPropertyUser): ?>
              Choose arrival and departure dates to see currently available lots.
            <?php else: ?>
              <?php if ($guestAutoLotSelection !== ''): ?>
                <?php echo htmlspecialchars('Lot auto-detected from your connection: ' . $guestAutoLotUnit . ' (SG ' . $guestAutoLotSg . ').'); ?>
              <?php else: ?>
                <?php echo htmlspecialchars(($guestAutoLotError !== null ? $guestAutoLotError : 'Automatic lot detection unavailable.') . ' Please choose your lot from the list below.'); ?>
              <?php endif; ?>
            <?php endif; ?>
          </p>

          <div class="button-row">
            <button type="submit">Register Me</button>
            <button type="button" class="secondary-btn" onclick="window.location.href='/?action=manage_reservation';">Manage My Reservation</button>
          </div>
        </form>
      </section>
    <?php endif; ?>
    <?php if ($isPropertyUser): ?>
      <footer class="app-version-footer" aria-label="Application version">
        <?php echo htmlspecialchars($versionLabel); ?>
      </footer>
    <?php endif; ?>
  </main>

  <div id="help-modal" class="help-modal" hidden>
    <div class="help-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="help-modal-title">
      <div class="help-modal-header">
        <h3 id="help-modal-title">Help</h3>
        <button type="button" id="help-modal-close" class="help-modal-close" aria-label="Close help">&times;</button>
      </div>
      <div id="help-modal-body" class="help-modal-body"></div>
    </div>
  </div>

  <script>
    (function () {
      var notices = document.querySelectorAll('.notice');
      Array.prototype.forEach.call(notices, function (notice) {
        if (notice.classList.contains('notice-persistent') || notice.dataset.persist === 'true') {
          return;
        }
        setTimeout(function () {
          notice.classList.add('notice-hidden');
          setTimeout(function () {
            if (notice && notice.parentNode) {
              notice.parentNode.removeChild(notice);
            }
          }, 350);
        }, 10000);
      });

      var passwordInputs = document.querySelectorAll('input[type="password"]');
      Array.prototype.forEach.call(passwordInputs, function (input, index) {
        if (!input || input.dataset.passwordToggleBound === '1') {
          return;
        }

        input.dataset.passwordToggleBound = '1';
        if (!input.id) {
          input.id = 'pwd-field-' + index;
        }

        var toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.className = 'mini-admin-btn password-toggle-btn';
        toggleBtn.textContent = '';
        toggleBtn.setAttribute('aria-label', 'Show password');
        toggleBtn.setAttribute('title', 'Show password');
        toggleBtn.setAttribute('aria-controls', input.id);
        toggleBtn.setAttribute('aria-pressed', 'false');

        toggleBtn.addEventListener('click', function () {
          var isHidden = input.type === 'password';
          input.type = isHidden ? 'text' : 'password';
          toggleBtn.classList.toggle('is-visible', isHidden);
          toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
          toggleBtn.setAttribute('title', isHidden ? 'Hide password' : 'Show password');
          toggleBtn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
        });

        input.insertAdjacentElement('afterend', toggleBtn);
      });

      var storageKey = 'sgr_theme';
      var toggle = document.getElementById('theme-toggle');
      var viewToggle = document.getElementById('admin-view-toggle');
      var devicePreviewSelect = document.getElementById('admin-device-preview');
      var nonAdminDevicePreviewSelect = document.getElementById('nonadmin-device-preview');
      var isAdminSettingsPage = !!document.querySelector('.page.page-admin');

      function applyTheme(mode) {
        document.documentElement.dataset.theme = mode;
        if (toggle) {
          toggle.textContent = mode === 'dark' ? 'Light' : 'Dark';
        }
      }

      function currentTheme() {
        return document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
      }

      var savedMode = localStorage.getItem(storageKey);
      var initialMode = savedMode === 'light' || savedMode === 'dark'
        ? savedMode
        : ((window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light');

      applyTheme(initialMode);
      localStorage.setItem(storageKey, initialMode);

      window.toggleThemeMode = function () {
        var mode = currentTheme() === 'dark' ? 'light' : 'dark';
        localStorage.setItem(storageKey, mode);
        applyTheme(mode);
      };

      if (toggle) {
        toggle.addEventListener('click', window.toggleThemeMode);
      }

      if (viewToggle && isAdminSettingsPage) {
        var previewDeviceStorageKey = 'sgr_admin_preview_device';

        var applyPreviewDevice = function (value) {
          var normalized = value === 'mobile' || value === 'desktop' ? value : 'auto';
          document.body.dataset.adminPreviewDevice = normalized;
          if (devicePreviewSelect) {
            devicePreviewSelect.value = normalized;
          }
        };

        var applyAdminView = function (mode) {
          var normalized = mode === 'compact' ? 'compact' : 'standard';
          document.body.dataset.adminView = normalized;
          viewToggle.textContent = normalized === 'compact' ? 'Standard View' : 'Compact View';
          viewToggle.setAttribute('aria-pressed', normalized === 'compact' ? 'true' : 'false');
        };

        var currentMode = (document.body && document.body.dataset && document.body.dataset.adminView === 'compact')
          ? 'compact'
          : 'standard';
        var savedPreviewDevice = localStorage.getItem(previewDeviceStorageKey);
        applyPreviewDevice(savedPreviewDevice);
        var currentDeviceClass = (document.body && document.body.dataset)
          ? String(document.body.dataset.adminDeviceClass || 'desktop')
          : 'desktop';
        var viewToken = (document.body && document.body.dataset)
          ? String(document.body.dataset.adminViewToken || '')
          : '';

        applyAdminView(currentMode);

        if (devicePreviewSelect) {
          devicePreviewSelect.addEventListener('change', function () {
            var selected = String(devicePreviewSelect.value || 'auto');
            applyPreviewDevice(selected);
            localStorage.setItem(previewDeviceStorageKey, selected);
          });
        }

        viewToggle.addEventListener('click', function () {
          var previousMode = currentMode;
          var nextMode = (document.body.dataset.adminView || 'standard') === 'compact' ? 'standard' : 'compact';
          applyAdminView(nextMode);

          var payload = new FormData();
          payload.append('save_admin_view_mode', '1');
          payload.append('admin_view_mode', nextMode);
          payload.append('admin_view_device_class', currentDeviceClass === 'mobile' ? 'mobile' : 'desktop');
          payload.append('reprovision_token', viewToken);

          fetch('/?action=admin', {
            method: 'POST',
            body: payload,
            credentials: 'same-origin'
          }).then(function (response) {
            if (!response.ok) {
              throw new Error('save-failed');
            }
            return response.json();
          }).then(function (json) {
            if (!json || json.ok !== true) {
              throw new Error('save-failed');
            }
            currentMode = nextMode;
          }).catch(function () {
            applyAdminView(previousMode);
            currentMode = previousMode;
          });
        });
      }

      if (nonAdminDevicePreviewSelect) {
        var nonAdminPreviewStorageKey = 'sgr_nonadmin_preview_device';

        var applyNonAdminPreviewDevice = function (value) {
          var normalized = value === 'mobile' || value === 'desktop' ? value : 'auto';
          document.body.dataset.nonadminPreviewDevice = normalized;
          nonAdminDevicePreviewSelect.value = normalized;
        };

        var savedNonAdminPreview = localStorage.getItem(nonAdminPreviewStorageKey);
        applyNonAdminPreviewDevice(savedNonAdminPreview);

        nonAdminDevicePreviewSelect.addEventListener('change', function () {
          var selected = String(nonAdminDevicePreviewSelect.value || 'auto');
          applyNonAdminPreviewDevice(selected);
          localStorage.setItem(nonAdminPreviewStorageKey, selected);
        });
      }

      var helpContentByKey = {
        'active-guests-page': {
          title: 'Active Guests Help',
          points: [
            'This page shows guests who are currently checked in.',
            'Rows marked Action Needed have provisioning failures that require staff follow-up.',
            'If internet setup gets out of sync, the Reprovision button quickly re-applies the correct setup for that guest.',
            'It is a faster and safer fix than re-entering the whole registration, and the app records what happened in the Reprovision Log.',
            'Use Regenerate Access Code to issue a new guest self-service code and invalidate the prior code.',
            'Use Void Incorrect Registration when a guest was registered by mistake, the wrong lot was chosen, or duplicate/invalid entries were created and should not stay active.'
          ]
        },
        'modem-lot-sync-page': {
          title: 'Modem and Lot Sync Help',
          points: [
            'Runs a Gunslinger refresh for configured service groups.',
            'Replaces local modem rows and remaps guest lot values by modem MAC.',
            'Checks DDNet for an active lease for each refreshed modem MAC.',
            'Rows without active leases are flagged in Lease Audit so staff can investigate offline modems.',
            'Run this after source data changes or before troubleshooting lot mismatches.'
          ]
        },
        'vacancy-mgmt-page': {
          title: 'Vacancy Mgmt Help',
          points: [
            'Use this page to prepare and apply internet settings for empty lots.',
            'Use Profile Settings for choosing what empty lots should be set to.',
            'Use Lot Preview to see what will change before you apply anything.',
            'Use the two section Help buttons for step-by-step guidance for each part.'
          ]
        },
        'vacant-profile-settings-section': {
          title: 'Vacant Profile Settings Help',
          points: [
            'Set the default setting to use when a lot is vacant.',
            'Save these values first so Lot Preview knows what target to compare against.',
            'If these values are missing, the app will warn you and skip those rows.'
          ]
        },
        'vacant-lot-preview-section': {
          title: 'Vacant Lot Preview Help',
          points: [
            'Run Preview to build a current list of lots that appear vacant.',
            'Review each row before applying: current value, target value, and status are shown side by side.',
            'Rows that need updates are selected for you, but you can uncheck anything you do not want to change.',
            'Use Apply Selected only after review; then check Apply Results to confirm what succeeded or failed.'
          ]
        },
        'reprovision-log-page': {
          title: 'Reprovision Log Help',
          points: [
            'Shows recent reprovision activity with status and actor details.',
            'Use filter fields to quickly narrow by guest name or unit.',
            'Logs are useful for confirming the last reprovision attempt and outcome.'
          ]
        },
        'my-account-page': {
          title: 'My Account Help',
          points: [
            'Use this page to manage your own sign-in details.',
            'Staff and Admin users can change their own password here.',
            'For security, your current password is required before saving a new one.'
          ]
        },
        'top-nav-actions': {
          title: 'Top Navigation Help',
          points: [
            'Register Guest: open the registration form to create a new reservation.',
            'Active Guests: view currently active guests and run active-guest actions.',
            'Upcoming Registrations: view future reservations with arrival dates after today.',
            'Guest Change Requests: review and decide guest checkout/extension requests.',
            'Rental History: open combined active and historical reservation records.',
            'Modem and Lot Sync: refresh modem data and remap lot assignments.',
            'Vacancy Mgmt: preview and apply vacant-lot profile updates.',
            'Reprovision Log: review recent reprovision attempts and outcomes.',
            'FAQ: view common staff questions and plain-language answers.',
            'My Account: manage your own account settings and password.',
            'Admin gear: open Admin Settings (admin users only).'
          ]
        },
        'change-password-card': {
          title: 'Change My Password Help',
          points: [
            'Enter your current password, then your new password twice.',
            'Use a strong password that you do not use on other sites.',
            'If the current password is wrong, no change is saved.'
          ]
        },
        'manage-reservation-page': {
          title: 'Manage My Reservation Help',
          points: [
            'Enter your Guest Access credentials from your confirmation screen (mode depends on property settings).',
            'After access is granted, you can request early checkout or a later departure date.',
            'Most properties use manual approval, so submitted requests may show as pending until staff review.'
          ]
        },
        'guest-early-checkout-card': {
          title: 'Request Early Checkout Help',
          points: [
            'Use this when you plan to leave before your current departure date.',
            'Choose the new departure date and include a reason if required by property policy.',
            'Requests are logged and can be approved or denied by staff.'
          ]
        },
        'guest-extension-card': {
          title: 'Request Departure Extension Help',
          points: [
            'Use this to request staying past your current departure date.',
            'The requested date must be within the property maximum extension window.',
            'Extensions can be denied when they conflict with another reservation on the same lot.'
          ]
        },
        'guest-requests-page': {
          title: 'Guest Change Requests Queue Help',
          points: [
            'Staff and admin users review all guest self-service requests here.',
            'Approve to apply the requested departure-date change, or deny with an optional note.',
            'Use filters for status, request type, service group, date range, and search text.',
            'Age chips highlight pending request SLA (warning after 4 hours, critical after 12 hours).',
            'Only pending requests can be decided.'
          ]
        },
        'rental-history-page': {
          title: 'Rental History Help',
          points: [
            'Displays both active and historical registrations.',
            'Historical rows are visually shaded for quick scanning.',
            'Use sortable date and unit columns to inspect occupancy history patterns.'
          ]
        },
        'guest-self-service-settings-card': {
          title: 'Guest Self-Service Access Help',
          points: [
            'Enable this to let guests manage reservation changes using generated access credentials.',
            'Choose login mode to require both Access ID and Code, or Access Code only.',
            'Choose which request types are allowed and whether reason text is required.',
            'Set approval mode and extension limits to match your property policy.',
            'Use failed-attempt and lockout fields to reduce brute-force login attempts.'
          ]
        },
        'admin-settings-page': {
          title: 'Admin Settings Help',
          points: [
            'Use cards below to manage defaults, naming, colors, and users.',
            'Each card has its own Help button for targeted guidance.',
            'Save operations apply immediately and are intended for admin users.'
          ]
        },
        'default-working-profile-card': {
          title: 'Default Working Profile Help',
          points: [
            'Set default service profiles per Service Group.',
            'Each profile must begin with that SG prefix (for example, SG 11 must start with 11).',
            'These defaults are used by registration provisioning operations.'
          ]
        },
        'global-checkout-time-card': {
          title: 'Global Checkout Time Help',
          points: [
            'Set one checkout time used by automatic expiration processing.',
            'After this time, registrations due today (or already past due) are auto-checked-out.',
            'Auto-checkout applies each SG vacant profile and attempts an SNMP reboot if lease lookup succeeds.'
          ]
        },
        'auto-checkout-diagnostics-card': {
          title: 'Auto-Checkout Diagnostics Help',
          points: [
            'Shows the latest auto-checkout execution snapshot.',
            'Due Count is the number of reservations evaluated as due in that run.',
            'Success Count is how many transitioned to checked out after vacant profile application.',
            'Failure Reasons aggregate transition failures by message for quick troubleshooting.'
          ]
        },
        'service-group-names-card': {
          title: 'Service Group Names Help',
          points: [
            'Set friendly display names while keeping SG numeric identifiers intact.',
            'Names are used in headings and selection labels across pages.',
            'Leave blank to fall back to default Service Group naming.'
          ]
        },
        'application-colors-card': {
          title: 'Application Colors Help',
          points: [
            'Choose and save a site-wide color preset.',
            'Use Reset to Forest to quickly return to baseline styling.',
            'Color presets affect cards, forms, and admin subsection accents.'
          ]
        },
        'existing-users-card': {
          title: 'Existing Users Help',
          points: [
            'Review users, roles, and account active state.',
            'Use Save to update role and status; if setting a new password, enter and confirm it.',
            'Staff users can access daily operational pages but not Admin Settings.',
            'Protected master user cannot be edited or deleted from this grid.'
          ]
        },
        'user-management-card': {
          title: 'User Management Help',
          points: [
            'Create new Staff or Admin accounts from this form.',
            'New user passwords must be entered twice for confirmation.',
            'Use strong passwords and assign the lowest required role.',
            'Only Admin and Master Admin users can access this card.'
          ]
        },
        'deployment-tools-card': {
          title: 'Deployment Tools Help',
          points: [
            'Contains destructive environment reset actions.',
            'Clear All Registrations creates a backup copy before deletion.',
            'Use only during controlled deployments or approved data resets.'
          ]
        }
      };

      var helpModal = document.getElementById('help-modal');
      var helpModalTitle = document.getElementById('help-modal-title');
      var helpModalBody = document.getElementById('help-modal-body');
      var helpModalClose = document.getElementById('help-modal-close');
      var helpButtons = document.querySelectorAll('.help-btn');

      var closeHelpModal = function () {
        if (!helpModal) {
          return;
        }
        helpModal.setAttribute('hidden', 'hidden');
      };

      var openHelpModal = function (helpKey) {
        if (!helpModal || !helpModalTitle || !helpModalBody) {
          return;
        }

        var data = helpContentByKey[helpKey] || {
          title: 'Help',
          points: ['Help content is not configured for this section yet.']
        };

        helpModalTitle.textContent = String(data.title || 'Help');
        var points = Array.isArray(data.points) ? data.points : [];
        var html = '<ul>';
        points.forEach(function (point) {
          html += '<li>' + String(point)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;') + '</li>';
        });
        html += '</ul>';
        helpModalBody.innerHTML = html;
        helpModal.removeAttribute('hidden');
      };

      Array.prototype.forEach.call(helpButtons, function (button) {
        button.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
          var key = String(button.getAttribute('data-help-key') || '');
          openHelpModal(key);
        });
      });

      if (helpModalClose) {
        helpModalClose.addEventListener('click', function () {
          closeHelpModal();
        });
      }

      if (helpModal) {
        helpModal.addEventListener('click', function (event) {
          if (event.target === helpModal) {
            closeHelpModal();
          }
        });
      }

      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && helpModal && !helpModal.hasAttribute('hidden')) {
          closeHelpModal();
        }
      });

      var arrivalInput = document.getElementById('arrival-date');
      var departureInput = document.getElementById('departure-date');
      var unitSelect = document.getElementById('unit-select');
      var hint = document.getElementById('unit-availability-hint');
      if (arrivalInput && departureInput && unitSelect) {
        var baseUnitsBySg = <?php echo json_encode($unitsByServiceGroup, JSON_UNESCAPED_SLASHES); ?> || {};
        var baseServiceGroupLabels = <?php echo json_encode($serviceGroupLabelsBySg, JSON_UNESCAPED_SLASHES); ?> || {};

        function renderUnits(grouped) {
          var selected = unitSelect.value;
          while (unitSelect.firstChild) {
            unitSelect.removeChild(unitSelect.firstChild);
          }

          var first = document.createElement('option');
          first.value = '';
          first.textContent = 'Select lot number';
          unitSelect.appendChild(first);

          var keys = Object.keys(grouped || {}).sort(function (a, b) {
            return parseInt(a, 10) - parseInt(b, 10);
          });
          var allValues = [];
          keys.forEach(function (sgKey) {
            var units = Array.isArray(grouped[sgKey]) ? grouped[sgKey].slice() : [];
            units.sort(function (a, b) {
              return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
            });

            if (units.length === 0) {
              return;
            }

            var group = document.createElement('optgroup');
            group.label = baseServiceGroupLabels[sgKey] || ('Service Group ' + sgKey);
            units.forEach(function (u) {
              var value = sgKey + '|' + u;
              var opt = document.createElement('option');
              opt.value = value;
              opt.textContent = u;
              allValues.push(value);
              group.appendChild(opt);
            });
            unitSelect.appendChild(group);
          });

          if (selected && allValues.indexOf(selected) !== -1) {
            unitSelect.value = selected;
          } else {
            unitSelect.value = '';
          }
        }

        function showAllUnits() {
          renderUnits(baseUnitsBySg);
          if (hint) {
            hint.textContent = 'Choose arrival and departure dates to see currently available lots.';
          }
        }

        function refreshAvailableUnits() {
          var arrival = arrivalInput.value;
          var departure = departureInput.value;
          if (!arrival || !departure || departure < arrival) {
            showAllUnits();
            return;
          }

          fetch('/?action=api_available_units&arrival_date=' + encodeURIComponent(arrival) + '&departure_date=' + encodeURIComponent(departure))
            .then(function (res) { return res.json(); })
            .then(function (data) {
              if (!data || !data.ok || !data.units_by_sg || typeof data.units_by_sg !== 'object') {
                showAllUnits();
                return;
              }

              renderUnits(data.units_by_sg);
              if (hint) {
                hint.textContent = Object.keys(data.units_by_sg).length > 0
                  ? 'Showing lots available for the selected date range.'
                  : 'No lots are available for the selected date range.';
              }
            })
            .catch(function () {
              showAllUnits();
            });
        }

        arrivalInput.addEventListener('change', refreshAvailableUnits);
        departureInput.addEventListener('change', refreshAvailableUnits);
        refreshAvailableUnits();
      }

      function unitSortParts(raw) {
        var value = String(raw || '').trim().toLowerCase();
        var match = value.match(/(\d+)(?!.*\d)/);
        var number = match ? parseInt(match[1], 10) : Number.MAX_SAFE_INTEGER;
        var prefix = match ? value.slice(0, match.index).trim() : value;
        return { prefix: prefix, number: number, whole: value };
      }

      function compareValues(a, b, type) {
        if (type === 'date') {
          var leftDate = String(a || '');
          var rightDate = String(b || '');
          if (leftDate === rightDate) {
            return 0;
          }
          return leftDate < rightDate ? -1 : 1;
        }

        if (type === 'unit') {
          var left = unitSortParts(a);
          var right = unitSortParts(b);
          if (left.prefix !== right.prefix) {
            return left.prefix < right.prefix ? -1 : 1;
          }
          if (left.number !== right.number) {
            return left.number - right.number;
          }
          if (left.whole === right.whole) {
            return 0;
          }
          return left.whole < right.whole ? -1 : 1;
        }

        var leftText = String(a || '').toLowerCase();
        var rightText = String(b || '').toLowerCase();
        if (leftText === rightText) {
          return 0;
        }
        return leftText < rightText ? -1 : 1;
      }

      Array.prototype.forEach.call(document.querySelectorAll('.js-sortable-table'), function (table) {
        var body = table.tBodies && table.tBodies[0] ? table.tBodies[0] : null;
        if (!body) {
          return;
        }

        var buttons = table.querySelectorAll('thead .sort-btn');
        Array.prototype.forEach.call(buttons, function (btn) {
          btn.addEventListener('click', function () {
            var th = btn.closest('th');
            if (!th) {
              return;
            }

            var columnIndex = Array.prototype.indexOf.call(th.parentNode.children, th);
            if (columnIndex < 0) {
              return;
            }

            var nextDir = btn.dataset.sortDir === 'asc' ? 'desc' : 'asc';
            Array.prototype.forEach.call(buttons, function (b) {
              if (b !== btn) {
                b.removeAttribute('data-sort-dir');
              }
            });
            btn.dataset.sortDir = nextDir;

            var rows = Array.prototype.slice.call(body.rows);
            var sortType = btn.dataset.sortType || 'text';
            rows.sort(function (rowA, rowB) {
              var cellA = rowA.cells[columnIndex];
              var cellB = rowB.cells[columnIndex];
              var valueA = cellA ? (cellA.getAttribute('data-sort-value') || cellA.textContent || '') : '';
              var valueB = cellB ? (cellB.getAttribute('data-sort-value') || cellB.textContent || '') : '';
              var result = compareValues(valueA, valueB, sortType);
              return nextDir === 'asc' ? result : -result;
            });

            rows.forEach(function (row) {
              body.appendChild(row);
            });
          });
        });
      });

      var adminCards = Array.prototype.slice.call(document.querySelectorAll('.admin-subcard'));
      if (adminCards.length > 0) {
        var cardOrder = {
          'Modem and Lot Sync': 1,
          'Vacant Lot Profile Management': 2,
          'Default Working Profile': 3,
          'Default Service Profile': 3,
          'Reprovision Log': 4,
          'Service Group Names': 5,
          'Application Colors': 6,
          'Existing Users': 7,
          'User Management': 8,
          'Deployment Tools': 9
        };

        var cardParent = adminCards[0].parentNode;
        adminCards.sort(function (a, b) {
          var aTitleNode = a.querySelector('summary strong');
          var bTitleNode = b.querySelector('summary strong');
          var aTitle = aTitleNode ? String(aTitleNode.textContent || '').trim() : '';
          var bTitle = bTitleNode ? String(bTitleNode.textContent || '').trim() : '';
          var aOrder = Object.prototype.hasOwnProperty.call(cardOrder, aTitle) ? cardOrder[aTitle] : 999;
          var bOrder = Object.prototype.hasOwnProperty.call(cardOrder, bTitle) ? cardOrder[bTitle] : 999;

          if (aOrder !== bOrder) {
            return aOrder - bOrder;
          }

          return aTitle.localeCompare(bTitle);
        });

        adminCards.forEach(function (card) {
          cardParent.appendChild(card);
        });
      }

      var guestFilterInput = document.getElementById('reprov-filter-guest');
      var unitFilterInput = document.getElementById('reprov-filter-unit');
      var logRows = document.querySelectorAll('.reprov-log-row');
      if (logRows.length > 0 && (guestFilterInput || unitFilterInput)) {
        var applyReprovLogFilter = function () {
          var guestQuery = guestFilterInput ? String(guestFilterInput.value || '').trim().toLowerCase() : '';
          var unitQuery = unitFilterInput ? String(unitFilterInput.value || '').trim().toLowerCase() : '';

          Array.prototype.forEach.call(logRows, function (row) {
            var rowGuest = String(row.getAttribute('data-guest-name') || '').toLowerCase();
            var rowUnit = String(row.getAttribute('data-unit') || '').toLowerCase();
            var guestMatches = guestQuery === '' || rowGuest.indexOf(guestQuery) !== -1;
            var unitMatches = unitQuery === '' || rowUnit.indexOf(unitQuery) !== -1;
            row.style.display = guestMatches && unitMatches ? '' : 'none';
          });
        };

        if (guestFilterInput) {
          guestFilterInput.addEventListener('input', applyReprovLogFilter);
        }
        if (unitFilterInput) {
          unitFilterInput.addEventListener('input', applyReprovLogFilter);
        }
      }

      var sgToggles = document.querySelectorAll('.vacant-sg-toggle');
      if (sgToggles.length > 0) {
        var syncSgToggleState = function (sg) {
          var toggle = document.querySelector('.vacant-sg-toggle[data-sg="' + sg + '"]');
          var rows = document.querySelectorAll('.vacant-row-checkbox[data-sg="' + sg + '"]');
          if (!toggle || rows.length === 0) {
            return;
          }

          var checkedCount = 0;
          Array.prototype.forEach.call(rows, function (row) {
            if (row.checked) {
              checkedCount++;
            }
          });

          if (checkedCount === 0) {
            toggle.checked = false;
            toggle.indeterminate = false;
          } else if (checkedCount === rows.length) {
            toggle.checked = true;
            toggle.indeterminate = false;
          } else {
            toggle.checked = false;
            toggle.indeterminate = true;
          }
        };

        Array.prototype.forEach.call(sgToggles, function (toggle) {
          var sg = String(toggle.getAttribute('data-sg') || '');
          if (sg === '') {
            return;
          }

          toggle.addEventListener('change', function () {
            var rows = document.querySelectorAll('.vacant-row-checkbox[data-sg="' + sg + '"]');
            Array.prototype.forEach.call(rows, function (row) {
              row.checked = toggle.checked;
            });
            syncSgToggleState(sg);
          });

          var sgRows = document.querySelectorAll('.vacant-row-checkbox[data-sg="' + sg + '"]');
          Array.prototype.forEach.call(sgRows, function (row) {
            row.addEventListener('change', function () {
              syncSgToggleState(sg);
            });
          });

          syncSgToggleState(sg);
        });
      }
    })();
  </script>
</body>
</html>
