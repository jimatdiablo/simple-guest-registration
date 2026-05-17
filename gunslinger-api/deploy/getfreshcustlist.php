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

$maxLimit = (int) ($config['max_refresh_limit'] ?? 5000);
$defaultLimit = (int) ($config['default_refresh_limit'] ?? 5000);
$limit = (int) ($body['limit'] ?? $defaultLimit);
$limit = max(1, min($limit, $maxLimit));

$placeholders = implode(',', array_fill(0, count($sgs), '?'));
$sql = "SELECT * FROM customers WHERE sg IN ($placeholders) ORDER BY id ASC LIMIT $limit";
$stmt = $pdo->prepare($sql);
$stmt->execute($sgs);
$rows = $stmt->fetchAll();

api_json([
    'ok' => true,
    'service_groups' => $sgs,
    'count' => count($rows),
    'data' => $rows,
], 200);
