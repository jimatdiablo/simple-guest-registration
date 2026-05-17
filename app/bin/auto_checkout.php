#!/usr/bin/env php
<?php

require_once __DIR__ . '/../src/bootstrap.php';

$globalCheckoutTime = trim((string) ($settings->get('global_checkout_time', '11:00') ?? '11:00'));
if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $globalCheckoutTime)) {
    $globalCheckoutTime = '11:00';
}

$vacantProfileOverridesBySg = [];
foreach ($serviceGroups as $sg) {
    $sg = (int) $sg;
    $vacantFallback = str_pad((string) $sg, 2, '0', STR_PAD_LEFT) . 'vacant';
    $vacantProfileOverridesBySg[$sg] = trim((string) ($settings->get('vacant_profile_sg_' . $sg, $vacantFallback) ?? $vacantFallback));
}

try {
    $result = $autoCheckoutService->maybeRunForCurrentMinute($globalCheckoutTime, $vacantProfileOverridesBySg, 200);
    $failureSummary = 'none';
    if (!empty($result['failure_reasons']) && is_array($result['failure_reasons'])) {
        $parts = [];
        foreach ($result['failure_reasons'] as $reason => $count) {
            $parts[] = $reason . ' x' . (int) $count;
        }
        $failureSummary = implode(' ; ', $parts);
    }

    $status = ($result['ran'] ?? false) ? 'executed' : 'skipped';
    $skipReason = trim((string) ($result['skip_reason'] ?? ''));
    $suffix = $skipReason !== '' ? ' (' . $skipReason . ')' : '';

    fwrite(
        STDOUT,
        sprintf(
            "[%s] auto-checkout %s%s; due=%d; success=%d; failures=%s\n",
            date('c'),
            $status,
            $suffix,
            (int) ($result['due_count'] ?? 0),
            (int) ($result['success_count'] ?? 0),
            $failureSummary
        )
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("[%s] auto-checkout error: %s\n", date('c'), $e->getMessage()));
    exit(1);
}