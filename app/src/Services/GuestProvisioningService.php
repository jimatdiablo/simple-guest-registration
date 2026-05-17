<?php

class GuestProvisioningService
{
    public function __construct(
        private GuestRepository $guests,
        private ModemRepository $modems,
        private ProfileBootfileRepository $profileBootfiles,
        private GunslingerClient $gunslinger,
        private DdnetClient $ddnet,
        private SnmpRebootService $snmp
    ) {
    }

    public static function detectLotMacChanges(array $previousMap, array $currentMap): array
    {
        $changes = [];
        foreach ($currentMap as $lotKey => $currentMac) {
            $normalizedCurrent = strtolower(trim((string) $currentMac));
            $previousMac = strtolower(trim((string) ($previousMap[$lotKey] ?? '')));
            if ($normalizedCurrent === '' || $previousMac === '' || $previousMac === $normalizedCurrent) {
                continue;
            }

            [$sgRaw, $unitRaw] = array_pad(explode('|', (string) $lotKey, 2), 2, '');
            $changes[(string) $lotKey] = [
                'sg' => (int) $sgRaw,
                'unit' => (string) $unitRaw,
                'old_mac' => $previousMac,
                'new_mac' => $normalizedCurrent,
            ];
        }

        return $changes;
    }

    public function reprovisionGuestAgainstCurrentModem(
        array $guest,
        array $serviceGroups,
        array $defaultProfilesBySg,
        string $defaultProfile,
        string $actor = 'admin',
        string $reasonNote = '',
        bool $cleanupOldModemScopedReservation = true
    ): array {
        $guestId = (int) ($guest['id'] ?? 0);
        $guestUnit = trim((string) ($guest['unit'] ?? ''));
        $guestSg = (int) ($guest['sg'] ?? 0);
        $previousGuestMac = strtolower(trim((string) ($guest['modem_mac'] ?? '')));

        $modem = $guestSg > 0
            ? $this->modems->findByUnitAndServiceGroup($guestUnit, $guestSg)
            : null;

        if ($modem === null) {
            $modem = $this->modems->findByUnitAndGroups($guestUnit, $serviceGroups);
        }

        if ($modem === null) {
            return ['ok' => false, 'error' => 'No modem record found for the guest lot after refresh.'];
        }

        $targetSg = (int) ($modem['sg'] ?? 0);
        $targetUnit = (string) ($modem['unit'] ?? '');
        $targetMac = strtolower(trim((string) ($modem['mac'] ?? '')));
        $targetProfile = (string) ($defaultProfilesBySg[$targetSg] ?? $defaultProfile);

        if ($targetSg <= 0 || $targetUnit === '' || $targetMac === '') {
            return ['ok' => false, 'error' => 'Current modem mapping is incomplete for this guest lot.'];
        }
        if (!$this->profileMatchesServiceGroup($targetProfile, $targetSg)) {
            return ['ok' => false, 'error' => sprintf('Configured default profile "%s" must start with %02d for SG %d.', $targetProfile, $targetSg, $targetSg)];
        }

        $profileResult = $this->gunslinger->updateProfile($targetSg, $targetUnit, $targetMac, $targetProfile);
        $bootfile = $this->profileBootfiles->findBootfileFilename($targetSg, $targetProfile);
        if ($bootfile === null) {
            $bootfileSyncResult = $this->gunslinger->fetchWorkingProfileBootfiles(
                [$targetSg],
                [$targetSg => $targetProfile]
            );
            if (($bootfileSyncResult['ok'] ?? false) === true) {
                $this->profileBootfiles->upsertMappings((array) ($bootfileSyncResult['rows'] ?? []));
                $bootfile = $this->profileBootfiles->findBootfileFilename($targetSg, $targetProfile);
            }
        }

        if ($bootfile === null) {
            $reservationResult = [
                'ok' => false,
                'error' => sprintf('Missing cached bootfile mapping for SG %d working profile %s.', $targetSg, $targetProfile),
            ];
        } else {
            $reservationResult = $this->ddnet->upsertReservationBootfile($targetMac, $bootfile);
        }

        $modemScopedReservationResult = $this->ddnet->modemScopedReservationsCreate([
            'mac_address' => $targetMac,
            'dhcp_options' => [
                '6' => ['1.1.1.1', '8.8.8.8'],
                '51' => 3600,
            ],
            'description' => trim((string) ($guest['guest_name'] ?? '') . ' - Lot ' . $targetUnit),
        ]);
        $oldModemScopedReservationDeleteResult = ['ok' => true, 'skipped' => true, 'error' => null, 'path' => null];
        if ($cleanupOldModemScopedReservation && $previousGuestMac !== '' && $previousGuestMac !== $targetMac) {
            $oldModemScopedReservationDeleteResult = $this->ddnet->modemScopedReservationsDelete($previousGuestMac);
        }

        $rebootIpCandidates = $this->buildRebootIpCandidates([
            (string) ($guest['dhcp_ip'] ?? ''),
            (string) ($modem['lease_ip'] ?? ''),
        ]);
        if (($reservationResult['ok'] ?? false) !== true) {
            $snmpResult = ['ok' => false, 'error' => 'SNMP reboot skipped because DDNet reservation failed.'];
        } else {
            $snmpResult = $rebootIpCandidates !== []
                ? $this->snmp->rebootWithCandidates($rebootIpCandidates)
                : ['ok' => false, 'error' => 'No reboot IP candidates available'];
        }

        $status = 'submitted';
        $noteParts = [];
        if (!($profileResult['ok'] ?? false)) {
            $status = 'partial_failure';
            $noteParts[] = 'Profile update failed: ' . (string) ($profileResult['error'] ?? 'unknown');
        }
        if (!(($reservationResult['ok'] ?? false) === true)) {
            $status = 'partial_failure';
            $noteParts[] = 'DDNet reservation failed: ' . (string) ($reservationResult['error'] ?? 'unknown');
        }
        if (!(($modemScopedReservationResult['ok'] ?? false) === true)) {
            $status = 'partial_failure';
            $noteParts[] = 'DDNet modem scoped reservation failed: ' . (string) ($modemScopedReservationResult['error'] ?? 'unknown');
        }
        if (($oldModemScopedReservationDeleteResult['skipped'] ?? false) !== true && !(($oldModemScopedReservationDeleteResult['ok'] ?? false) === true)) {
            $status = 'partial_failure';
            $noteParts[] = 'Old DDNet modem scoped reservation cleanup failed: ' . (string) ($oldModemScopedReservationDeleteResult['error'] ?? 'unknown');
        }
        if (!($snmpResult['ok'] ?? false)) {
            $status = 'partial_failure';
            $noteParts[] = $this->buildSnmpFailureNote((array) $snmpResult);
        }

        $audit = sprintf(
            '[Reprovision %s] SG %d Unit %s MAC %s Profile %s',
            date('Y-m-d H:i:s'),
            $targetSg,
            $targetUnit,
            $targetMac,
            $targetProfile
        );
        $detailText = $noteParts !== [] ? implode(' ; ', $noteParts) : 'Reprovision completed successfully.';
        if (trim($reasonNote) !== '') {
            $detailText = trim($reasonNote) . ' ; ' . $detailText;
        }
        $existingNotes = trim((string) ($guest['notes'] ?? ''));
        $baseNotes = $this->stripReprovisionNotes($existingNotes);
        $combinedNotes = $baseNotes === '' ? ($audit . ' | ' . $detailText) : ($baseNotes . ' | ' . $audit . ' | ' . $detailText);

        $this->guests->updateById($guestId, [
            'guest_name' => (string) ($guest['guest_name'] ?? ''),
            'phone' => (string) ($guest['phone'] ?? ''),
            'unit' => $targetUnit,
            'sg' => $targetSg,
            'modem_mac' => $targetMac,
            'arrival_date' => (string) ($guest['arrival_date'] ?? ''),
            'departure_date' => (string) ($guest['departure_date'] ?? ''),
            'profile_applied' => $targetProfile,
            'submission_status' => $status,
            'notes' => $combinedNotes,
        ]);

        $this->guests->addReprovisionLog([
            'guest_id' => $guestId,
            'guest_name' => (string) ($guest['guest_name'] ?? ''),
            'unit' => $targetUnit,
            'sg' => $targetSg,
            'modem_mac' => $targetMac,
            'profile_applied' => $targetProfile,
            'status' => $status,
            'details' => $detailText,
            'actor' => $actor,
        ]);

        try {
            $modemScopedLogStatus = (($modemScopedReservationResult['ok'] ?? false) === true) ? 'submitted' : 'failed';
            $modemScopedLogDetails = (($modemScopedReservationResult['ok'] ?? false) === true)
                ? ('Created during reprovision via ' . (string) ($modemScopedReservationResult['path'] ?? 'DDNet endpoint'))
                : (string) ($modemScopedReservationResult['error'] ?? 'unknown');
            $this->guests->addModemScopedReservationLog([
                'guest_id' => $guestId,
                'guest_name' => (string) ($guest['guest_name'] ?? ''),
                'unit' => $targetUnit,
                'sg' => $targetSg,
                'modem_mac' => $targetMac,
                'client_ip' => (string) ($guest['dhcp_ip'] ?? ''),
                'status' => $modemScopedLogStatus,
                'details' => $modemScopedLogDetails,
                'actor' => $actor,
            ]);
        } catch (Throwable $e) {
            // Non-blocking log write.
        }

        if (($oldModemScopedReservationDeleteResult['skipped'] ?? false) !== true) {
            try {
                $deleteLogStatus = (($oldModemScopedReservationDeleteResult['ok'] ?? false) === true) ? 'removed' : 'remove_failed';
                $deleteLogDetails = (($oldModemScopedReservationDeleteResult['ok'] ?? false) === true)
                    ? ('Removed old modem scoped reservation via ' . (string) ($oldModemScopedReservationDeleteResult['path'] ?? 'DDNet endpoint'))
                    : (string) ($oldModemScopedReservationDeleteResult['error'] ?? 'unknown');
                $this->guests->addModemScopedReservationLog([
                    'guest_id' => $guestId,
                    'guest_name' => (string) ($guest['guest_name'] ?? ''),
                    'unit' => $targetUnit,
                    'sg' => $targetSg,
                    'modem_mac' => $previousGuestMac,
                    'client_ip' => (string) ($guest['dhcp_ip'] ?? ''),
                    'status' => $deleteLogStatus,
                    'details' => $deleteLogDetails,
                    'actor' => $actor,
                ]);
            } catch (Throwable $e) {
                // Non-blocking log write.
            }
        }

        return [
            'ok' => true,
            'status' => $status,
            'detail_text' => $detailText,
            'target_unit' => $targetUnit,
            'target_sg' => $targetSg,
            'target_mac' => $targetMac,
            'target_profile' => $targetProfile,
            'ddnet_reservation_ok' => ($reservationResult['ok'] ?? false) === true,
            'modem_scoped_reservation_ok' => ($modemScopedReservationResult['ok'] ?? false) === true,
            'old_modem_scoped_reservation_removed' => ($oldModemScopedReservationDeleteResult['skipped'] ?? false) === true || ($oldModemScopedReservationDeleteResult['ok'] ?? false) === true,
        ];
    }

    public function autoReprovisionAffectedGuests(
        array $lotMacChanges,
        array $serviceGroups,
        array $defaultProfilesBySg,
        string $defaultProfile,
        string $actor = 'auto_sync'
    ): array {
        if ($lotMacChanges === [] || $serviceGroups === []) {
            return [
                'attempted' => 0,
                'updated' => 0,
                'warnings' => 0,
                'failed' => 0,
                'results' => [],
            ];
        }

        $activeGuests = $this->guests->activeRegistrationsByServiceGroups($serviceGroups, 2000);
        $results = [];
        $summary = [
            'attempted' => 0,
            'updated' => 0,
            'warnings' => 0,
            'failed' => 0,
            'results' => [],
        ];

        $activeGuestLotKeys = [];
        foreach ($activeGuests as $guest) {
            $activeGuestLotKeys[((int) ($guest['sg'] ?? 0)) . '|' . trim((string) ($guest['unit'] ?? ''))] = true;
        }

        $incomingActiveModemMacs = [];
        foreach ($lotMacChanges as $change) {
            $changedLotKey = ((int) ($change['sg'] ?? 0)) . '|' . trim((string) ($change['unit'] ?? ''));
            if (!isset($activeGuestLotKeys[$changedLotKey])) {
                continue;
            }

            $incomingMac = strtolower(trim((string) ($change['new_mac'] ?? '')));
            if ($incomingMac !== '') {
                $incomingActiveModemMacs[$incomingMac] = true;
            }
        }

        foreach ($activeGuests as $guest) {
            $lotKey = ((int) ($guest['sg'] ?? 0)) . '|' . trim((string) ($guest['unit'] ?? ''));
            $change = $lotMacChanges[$lotKey] ?? null;
            if (!is_array($change)) {
                continue;
            }

            $currentGuestMac = strtolower(trim((string) ($guest['modem_mac'] ?? '')));
            $newMac = strtolower(trim((string) ($change['new_mac'] ?? '')));
            if ($newMac === '' || $currentGuestMac === $newMac) {
                continue;
            }

            $summary['attempted']++;
            $reasonNote = sprintf(
                'Auto reprovision triggered by modem swap on SG %d Unit %s: %s -> %s',
                (int) ($change['sg'] ?? 0),
                (string) ($change['unit'] ?? ''),
                (string) ($change['old_mac'] ?? 'unknown'),
                (string) ($change['new_mac'] ?? 'unknown')
            );
            $cleanupOldScoped = !isset($incomingActiveModemMacs[$currentGuestMac]);
            $result = $this->reprovisionGuestAgainstCurrentModem($guest, $serviceGroups, $defaultProfilesBySg, $defaultProfile, $actor, $reasonNote, $cleanupOldScoped);
            $result['guest_id'] = (int) ($guest['id'] ?? 0);
            $result['guest_name'] = (string) ($guest['guest_name'] ?? '');
            $result['unit'] = (string) ($guest['unit'] ?? '');
            $result['sg'] = (int) ($guest['sg'] ?? 0);
            $result['change_summary'] = $reasonNote;
            $results[] = $result;

            if (!($result['ok'] ?? false)) {
                $summary['failed']++;
                continue;
            }

            if ((string) ($result['status'] ?? '') === 'partial_failure') {
                $summary['warnings']++;
            } else {
                $summary['updated']++;
            }
        }

        $summary['results'] = $results;
        return $summary;
    }

    private function profileMatchesServiceGroup(string $profile, int $serviceGroup): bool
    {
        $profile = trim($profile);
        if ($profile === '' || strlen($profile) < 2) {
            return false;
        }

        $requiredPrefix = str_pad((string) $serviceGroup, 2, '0', STR_PAD_LEFT);
        return substr($profile, 0, 2) === $requiredPrefix;
    }

    private function stripReprovisionNotes(string $notes): string
    {
        $parts = array_map('trim', explode(' | ', $notes));
        $hasReprovisionMarker = false;
        foreach ($parts as $part) {
            if (strpos($part, '[Reprovision ') === 0) {
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
            if ($hasReprovisionMarker && $isLegacyReprovisionDetail($part)) {
                continue;
            }

            $kept[] = $part;
        }

        return implode(' | ', $kept);
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
