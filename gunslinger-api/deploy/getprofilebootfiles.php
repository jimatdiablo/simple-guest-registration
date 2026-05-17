<?php

require_once __DIR__ . '/common.php';

api_require_post();
$config = api_get_config();
api_require_basic_auth($config);
$pdo = api_connect($config);
$body = api_post_json_or_form();

$allowedSgs = array_values(array_map('intval', $config['allowed_refresh_sgs'] ?? [10]));
$requestedSgs = api_parse_service_groups((string) ($body['service_groups'] ?? ''), $allowedSgs);
$sgs = array_values(array_intersect($requestedSgs, $allowedSgs));

if ($sgs === []) {
    api_json([
        'ok' => false,
        'error' => 'No allowed service groups requested',
        'allowed_service_groups' => $allowedSgs,
    ], 400);
}

$rawProfileNames = trim((string) ($body['profile_names'] ?? ''));
$profileNames = [];
if ($rawProfileNames !== '') {
    $parts = array_filter(array_map('trim', explode(',', $rawProfileNames)), static fn ($v) => $v !== '');
    $profileNames = array_values(array_unique($parts));
}

$sql = "SELECT sname, filename FROM `cqm`.`profiles` WHERE filename IS NOT NULL AND filename <> ''";
$params = [];
if ($profileNames !== []) {
    $placeholders = implode(',', array_fill(0, count($profileNames), '?'));
    $sql .= " AND sname IN ($placeholders)";
    $params = $profileNames;
}
$sql .= ' ORDER BY sname ASC';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sourceRows = $stmt->fetchAll();
} catch (Throwable $e) {
    api_json([
        'ok' => false,
        'error' => 'Failed to read cqm.profiles',
        'details' => $e->getMessage(),
    ], 500);
}

$allowedSgMap = array_fill_keys(array_map('intval', $sgs), true);
$requestedProfileMap = [];
foreach ($profileNames as $profileName) {
    $requestedProfileMap[strtolower($profileName)] = true;
}

$out = [];
foreach ($sourceRows as $row) {
    $profileName = trim((string) ($row['sname'] ?? ''));
    $bootfile = trim((string) ($row['filename'] ?? ''));
    if ($profileName === '' || $bootfile === '') {
        continue;
    }

    if ($requestedProfileMap !== [] && !isset($requestedProfileMap[strtolower($profileName)])) {
        continue;
    }

    if (!preg_match('/^(\d{1,3})/', $profileName, $match)) {
        continue;
    }

    $sg = (int) $match[1];
    if ($sg <= 0 || !isset($allowedSgMap[$sg])) {
        continue;
    }

    $key = $sg . '|' . strtolower($profileName);
    $out[$key] = [
        'sg' => $sg,
        'profile_name' => $profileName,
        'bootfile_filename' => $bootfile,
    ];
}

$data = array_values($out);

api_json([
    'ok' => true,
    'service_groups' => $sgs,
    'count' => count($data),
    'data' => $data,
], 200);
