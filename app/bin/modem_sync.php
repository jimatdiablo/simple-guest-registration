#!/usr/bin/env php
<?php

require_once __DIR__ . '/../src/bootstrap.php';

$start = microtime(true);
$defaultProfilesBySg = [];
foreach ($serviceGroups as $sg) {
    $sg = (int) $sg;
    $fallbackProfile = (string) $defaultProfile;
    $requiredPrefix = str_pad((string) $sg, 2, '0', STR_PAD_LEFT);
    if (substr($fallbackProfile, 0, 2) !== $requiredPrefix) {
        $fallbackProfile = $requiredPrefix . 'baseservice';
    }

    $configuredProfile = trim((string) ($settings->get('default_service_profile_sg_' . $sg, $fallbackProfile) ?? $fallbackProfile));
    $defaultProfilesBySg[$sg] = $configuredProfile === '' ? $fallbackProfile : $configuredProfile;
}

try {
    $previousLotMacMap = $modems->lotMacMapByServiceGroups($serviceGroups);
    $refreshResult = $gunslinger->refreshCustomers($serviceGroups);
    if (!($refreshResult['ok'] ?? false)) {
        $error = (string) ($refreshResult['error'] ?? 'unknown');
        fwrite(STDERR, sprintf("[%s] modem-sync error: Gunslinger refresh failed: %s\n", date('c'), $error));
        exit(1);
    }

    $rows = (array) ($refreshResult['rows'] ?? []);
    if ($rows !== []) {
        $modems->replaceByServiceGroups($rows, $serviceGroups);
    }

    $remapped = $guests->remapUnitsFromModemsByMac($serviceGroups);
    $currentLotMacMap = $modems->lotMacMapByServiceGroups($serviceGroups);
    $lotMacChanges = GuestProvisioningService::detectLotMacChanges($previousLotMacMap, $currentLotMacMap);
    $autoReprovision = $guestProvisioningService->autoReprovisionAffectedGuests(
        $lotMacChanges,
        $serviceGroups,
        $defaultProfilesBySg,
        (string) $defaultProfile,
        'auto_sync'
    );
    $autoReprovisionLots = [];
    foreach ((array) ($autoReprovision['results'] ?? []) as $result) {
        $sg = (int) ($result['sg'] ?? 0);
        $unit = trim((string) ($result['unit'] ?? ''));
        if ($sg <= 0 || $unit === '') {
            continue;
        }
        $autoReprovisionLots[] = sprintf('%d/%s', $sg, $unit);
    }
    $autoReprovisionLots = array_values(array_unique($autoReprovisionLots));
    $elapsedMs = (int) round((microtime(true) - $start) * 1000);

    fwrite(
        STDOUT,
        sprintf(
            "[%s] modem-sync executed; service_groups=%s; imported_rows=%d; remapped_units=%d; auto_reprovision_attempted=%d; auto_reprovision_updated=%d; auto_reprovision_warnings=%d; auto_reprovision_failed=%d; auto_reprovision_lots=%s; elapsed_ms=%d\n",
            date('c'),
            implode(',', array_map('strval', $serviceGroups)),
            count($rows),
            (int) $remapped,
            (int) ($autoReprovision['attempted'] ?? 0),
            (int) ($autoReprovision['updated'] ?? 0),
            (int) ($autoReprovision['warnings'] ?? 0),
            (int) ($autoReprovision['failed'] ?? 0),
            $autoReprovisionLots === [] ? 'none' : implode(',', $autoReprovisionLots),
            $elapsedMs
        )
    );

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("[%s] modem-sync exception: %s\n", date('c'), $e->getMessage()));
    exit(1);
}
