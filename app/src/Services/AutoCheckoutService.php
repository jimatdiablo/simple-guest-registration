<?php

class AutoCheckoutService
{
    public function __construct(
        private GuestRepository $guests,
        private SettingsRepository $settings,
        private ModemRepository $modems,
        private GunslingerClient $gunslinger,
        private DdnetClient $ddnet,
        private SnmpRebootService $snmp
    ) {
    }

    public function maybeRunForCurrentMinute(string $checkoutTime, array $vacantProfileOverridesBySg, int $limit = 200): array
    {
        $currentMinute = date('Y-m-d H:i');
        $lastRunMinute = trim((string) ($this->settings->get('auto_checkout_last_run_minute', '') ?? ''));
        if ($lastRunMinute === $currentMinute) {
            $diagnostics = $this->lastDiagnostics();
            $diagnostics['ran'] = false;
            $diagnostics['skip_reason'] = 'already_ran_this_minute';
            return $diagnostics;
        }

        $this->settings->set('auto_checkout_last_run_minute', $currentMinute);

        $result = $this->runNow($checkoutTime, $vacantProfileOverridesBySg, $limit);
        $result['ran'] = true;
        return $result;
    }

    public function runNow(string $checkoutTime, array $vacantProfileOverridesBySg, int $limit = 200): array
    {
        $runAt = date('Y-m-d H:i:s');
        $todayDate = date('Y-m-d');
        $currentTime = date('H:i:s');
        $normalizedCheckoutTime = trim($checkoutTime);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $normalizedCheckoutTime)) {
            $normalizedCheckoutTime = '11:00';
        }
        if (strlen($normalizedCheckoutTime) === 5) {
            $normalizedCheckoutTime .= ':00';
        }

        $dueGuests = $this->guests->listDueAutoCheckout($todayDate, $normalizedCheckoutTime, $currentTime, $limit);
        $dueCount = count($dueGuests);
        $successCount = 0;
        $failureReasons = [];

        foreach ($dueGuests as $dueGuest) {
            $dueSg = (int) ($dueGuest['sg'] ?? 0);
            $dueUnit = trim((string) ($dueGuest['unit'] ?? ''));
            if ($dueSg <= 0 || $dueUnit === '') {
                $this->addFailureReason($failureReasons, 'Missing service group or lot on due guest row');
                continue;
            }

            $checkoutResult = $this->runVacantCheckoutTransition($dueGuest, $vacantProfileOverridesBySg);
            if (!($checkoutResult['ok'] ?? false)) {
                $failureReason = trim((string) ($checkoutResult['error'] ?? 'Checkout transition failed'));
                if ($failureReason === '') {
                    $failureReason = 'Checkout transition failed';
                }
                $this->addFailureReason($failureReasons, $failureReason);
                continue;
            }

            $notesParts = [];
            $existingNotes = trim((string) ($dueGuest['notes'] ?? ''));
            if ($existingNotes !== '') {
                $notesParts[] = $existingNotes;
            }
            $notesParts[] = sprintf('[Auto Checkout %s] Applied configured vacant checkout workflow.', $runAt);
            foreach ((array) ($checkoutResult['notes'] ?? []) as $checkoutNote) {
                $checkoutNote = trim((string) $checkoutNote);
                if ($checkoutNote !== '') {
                    $notesParts[] = $checkoutNote;
                }
            }

            $this->guests->updateById((int) ($dueGuest['id'] ?? 0), [
                'guest_name' => (string) ($dueGuest['guest_name'] ?? ''),
                'phone' => (string) ($dueGuest['phone'] ?? ''),
                'unit' => $dueUnit,
                'sg' => $dueSg,
                'modem_mac' => (string) ($checkoutResult['modem_mac'] ?? (string) ($dueGuest['modem_mac'] ?? '')),
                'arrival_date' => (string) ($dueGuest['arrival_date'] ?? $todayDate),
                'departure_date' => (string) ($dueGuest['departure_date'] ?? $todayDate),
                'profile_applied' => (string) ($checkoutResult['profile'] ?? (string) ($dueGuest['profile_applied'] ?? '')),
                'submission_status' => 'checked_out',
                'notes' => implode(' | ', array_filter($notesParts, static fn ($value) => trim((string) $value) !== '')),
            ]);
            $successCount++;
        }

        $this->persistDiagnostics($runAt, $dueCount, $successCount, $failureReasons);

        return [
            'ran_at' => $runAt,
            'due_count' => $dueCount,
            'success_count' => $successCount,
            'failure_reasons' => $failureReasons,
        ];
    }

    public function lastDiagnostics(): array
    {
        $runAt = trim((string) ($this->settings->get('auto_checkout_last_run_at', '') ?? ''));
        $dueCount = max(0, (int) ($this->settings->get('auto_checkout_last_due_count', '0') ?? '0'));
        $successCount = max(0, (int) ($this->settings->get('auto_checkout_last_success_count', '0') ?? '0'));
        $failureReasonsRaw = trim((string) ($this->settings->get('auto_checkout_last_failure_reasons_json', '[]') ?? '[]'));
        $failureReasons = [];

        if ($failureReasonsRaw !== '') {
            $decoded = json_decode($failureReasonsRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $reason => $count) {
                    $reasonText = trim((string) $reason);
                    $reasonCount = max(0, (int) $count);
                    if ($reasonText === '' || $reasonCount <= 0) {
                        continue;
                    }
                    $failureReasons[$reasonText] = $reasonCount;
                }
            }
        }

        return [
            'ran_at' => $runAt,
            'due_count' => $dueCount,
            'success_count' => $successCount,
            'failure_reasons' => $failureReasons,
        ];
    }

    private function persistDiagnostics(string $runAt, int $dueCount, int $successCount, array $failureReasons): void
    {
        $this->settings->set('auto_checkout_last_run_at', $runAt);
        $this->settings->set('auto_checkout_last_due_count', (string) max(0, $dueCount));
        $this->settings->set('auto_checkout_last_success_count', (string) max(0, $successCount));
        $encoded = json_encode($failureReasons, JSON_UNESCAPED_SLASHES);
        $this->settings->set('auto_checkout_last_failure_reasons_json', $encoded === false ? '[]' : $encoded);
    }

    private function addFailureReason(array &$failureReasons, string $reason): void
    {
        $failureReasons[$reason] = (int) ($failureReasons[$reason] ?? 0) + 1;
    }

    private function runVacantCheckoutTransition(array $guest, array $vacantProfileOverridesBySg): array
    {
        $sg = (int) ($guest['sg'] ?? 0);
        $unit = trim((string) ($guest['unit'] ?? ''));
        $guestMac = strtolower(trim((string) ($guest['modem_mac'] ?? '')));

        if ($sg <= 0 || $unit === '') {
            return ['ok' => false, 'error' => 'Guest service group or lot is missing.'];
        }

        $modemRow = $this->modems->findByUnitAndServiceGroup($unit, $sg);
        $modemMac = strtolower(trim((string) ($modemRow['mac'] ?? '')));
        $targetMac = $guestMac !== '' ? $guestMac : $modemMac;
        if ($targetMac === '') {
            return ['ok' => false, 'error' => 'Unable to determine modem MAC for checkout.'];
        }

        $vacantProfile = trim((string) ($vacantProfileOverridesBySg[$sg] ?? sprintf('%02dvacant', $sg)));
        $requiredPrefix = str_pad((string) $sg, 2, '0', STR_PAD_LEFT);
        if ($vacantProfile === '' || substr($vacantProfile, 0, 2) !== $requiredPrefix) {
            return ['ok' => false, 'error' => 'Vacant profile is missing or invalid for service group.'];
        }

        $profileResult = $this->gunslinger->updateProfile($sg, $unit, $targetMac, $vacantProfile);
        if (!($profileResult['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => 'Failed to apply vacant profile: ' . (string) ($profileResult['error'] ?? 'unknown'),
            ];
        }

        $notes = [
            sprintf('[Checkout %s] Vacant profile applied (%s).', date('Y-m-d H:i:s'), $vacantProfile),
        ];

        $cleanupGuest = $guest;
        $cleanupGuest['modem_mac'] = $targetMac;
        $cleanupResult = $this->removeModemScopedReservationForGuest($cleanupGuest, 'auto_checkout');
        if (!($cleanupResult['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => 'Failed to remove modem-scoped reservation during checkout: ' . (string) ($cleanupResult['error'] ?? 'unknown'),
            ];
        }
        $notes[] = (string) ($cleanupResult['details'] ?? 'Removed modem-scoped reservation.');

        $rebootIpCandidates = $this->buildRebootIpCandidates([
            (string) ($guest['dhcp_ip'] ?? ''),
            (string) ($modemRow['lease_ip'] ?? ''),
        ]);
        if ($rebootIpCandidates !== []) {
            $snmpResult = $this->snmp->rebootWithCandidates($rebootIpCandidates);
            if (!($snmpResult['ok'] ?? false)) {
                $notes[] = $this->buildSnmpFailureNote((array) $snmpResult);
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

    private function removeModemScopedReservationForGuest(array $guest, string $actor): array
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

        $reservationResult = method_exists($this->ddnet, 'reservationDelete')
            ? $this->ddnet->reservationDelete($modemMac)
            : ['ok' => true, 'path' => null];
        $scopedResult = $this->ddnet->modemScopedReservationsDelete($modemMac);
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

        try {
            $this->guests->addModemScopedReservationLog([
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

        return [
            'ok' => $ok,
            'error' => $ok ? null : $details,
            'details' => $details,
            'path' => $scopedResult['path'] ?? null,
        ];
    }

    private function buildSnmpFailureNote(array $snmpResult): string
    {
        $detail = trim((string) ($snmpResult['error'] ?? ''));
        if ($detail === '') {
            $detail = 'unknown';
        }

        return 'SNMP reboot failed or skipped: ' . $detail;
    }

    private function buildRebootIpCandidates(array $values): array
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
}
