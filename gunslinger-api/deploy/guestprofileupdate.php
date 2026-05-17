<?php

require_once __DIR__ . '/common.php';

api_require_post();
$config = api_get_config();
api_require_basic_auth($config);
$pdo = api_connect($config);
$body = api_post_json_or_form();

$sg = (int) ($body['sg'] ?? 0);
$unit = trim((string) ($body['unit'] ?? ''));
$mac = strtolower(trim((string) ($body['mac'] ?? '')));
$profile = trim((string) ($body['profile'] ?? ''));

if ($sg <= 0 || $unit === '' || $mac === '' || $profile === '') {
    api_json(['ok' => false, 'error' => 'Missing required fields: sg, unit, mac, profile'], 400);
}

$allowedSgs = array_values(array_map('intval', $config['allowed_update_sgs'] ?? [10]));
if (!in_array($sg, $allowedSgs, true)) {
    api_json([
        'ok' => false,
        'error' => 'SG is not allowed for update',
        'allowed_service_groups' => $allowedSgs,
    ], 403);
}

$select = $pdo->prepare('SELECT id FROM customers WHERE sg = ? AND unit = ? AND LOWER(mac) = ? LIMIT 1');
$select->execute([$sg, $unit, $mac]);
$matched = $select->fetch();

if ($matched === false) {
    api_json([
        'ok' => false,
        'error' => 'No matching record for sg+unit+mac',
        'sg' => $sg,
        'unit' => $unit,
        'mac' => $mac,
    ], 404);
}

$id = (int) $matched['id'];
$update = $pdo->prepare('UPDATE customers SET profile = ? WHERE id = ? LIMIT 1');
$update->execute([$profile, $id]);

api_json([
    'ok' => true,
    'matched' => 1,
    'updated' => $update->rowCount() > 0 ? 1 : 0,
    'sg' => $sg,
    'unit' => $unit,
    'mac' => $mac,
    'profile' => $profile,
    'id' => $id,
], 200);
