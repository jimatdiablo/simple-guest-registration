<?php

class DdnetClient
{
    private DhcpClient $client;

    public function __construct(
        private string $baseUrl,
        private int $timeout
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
        $this->client = new DhcpClient($this->baseUrl);
        $this->client->setTimeout($this->timeout);
    }

    public function lookupLeaseByMac(string $mac): array
    {
        $normalized = strtolower(trim($mac));
        $compact = preg_replace('/[^a-f0-9]/i', '', $normalized) ?? '';
        $dashed = implode('-', str_split($compact, 2));
        $colon = implode(':', str_split($compact, 2));

        $queries = [
            ['mac' => $normalized],
            ['mac' => $colon],
            ['mac' => $dashed],
            ['mac' => $compact],
            ['mac_address' => $normalized],
            ['mac_address' => $colon],
            ['mac_address' => $compact],
        ];

        $seen = [];
        $errors = [];

        foreach ($queries as $query) {
            $key = json_encode($query);
            if ($key === false || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $response = $this->client->leasesList($query);
            if (!($response['ok'] ?? false)) {
                $errors[] = 'query=' . $key . ' error=' . (string) ($response['error'] ?? 'unknown error');
                continue;
            }

            $ip = $this->extractLeaseIp($response['body'] ?? null, $compact);
            if ($ip !== null && $ip !== '') {
                return ['ok' => true, 'ip' => $ip, 'error' => null];
            }

            $errors[] = 'query=' . $key . ' error=no ip in response';
        }

        return [
            'ok' => false,
            'ip' => null,
            'error' => 'No active lease IP found in DDNet response for MAC ' . $normalized . '. Attempts: ' . implode(' ; ', $errors),
        ];
    }

    public function reservationByCpeIp(string $cpeIp): array
    {
        $ip = trim($cpeIp);
        if ($ip === '') {
            return ['ok' => false, 'error' => 'CPE IP is required.', 'modem_mac' => null, 'body' => null];
        }

        $path = '/reservation_by_cpe_ip';
        $response = $this->client->request('GET', $path, ['cpe_ip' => $ip], null);
        if (!($response['ok'] ?? false)) {
            return [
                'ok' => false,
                'modem_mac' => null,
                'body' => $response['body'] ?? null,
                'error' => 'DDNet reservation_by_cpe_ip failed for IP ' . $ip . ': ' . (string) ($response['error'] ?? 'request failed'),
            ];
        }

        $modemMac = $this->extractModemMacFromReservationBody($response['body'] ?? null);
        if ($modemMac !== '') {
            return [
                'ok' => true,
                'modem_mac' => $modemMac,
                'body' => $response['body'] ?? null,
                'error' => null,
            ];
        }

        return [
            'ok' => false,
            'modem_mac' => null,
            'body' => $response['body'] ?? null,
            'error' => 'DDNet reservation_by_cpe_ip response did not include modem_mac for IP ' . $ip . '.',
        ];
    }

    public function modemScopedReservationsCreate(array $payload): array
    {
        $attempts = [
            '/modem-scoped-reservations',
        ];

        $errors = [];
        foreach ($attempts as $path) {
            $response = $this->client->request('POST', $path, [], $payload);
            if ($response['ok'] ?? false) {
                return [
                    'ok' => true,
                    'body' => $response['body'] ?? null,
                    'error' => null,
                    'path' => $path,
                ];
            }

            $errors[] = $path . ': ' . (string) ($response['error'] ?? 'request failed');
        }

        return [
            'ok' => false,
            'body' => null,
            'error' => 'DDNet modem-scoped reservation create failed. Attempts: ' . implode(' ; ', $errors),
            'path' => null,
        ];
    }

    public function modemScopedReservationsDelete(string $modemMac): array
    {
        $normalizedMac = $this->normalizeMac($modemMac);
        if ($normalizedMac === '') {
            return ['ok' => false, 'error' => 'Invalid MAC address for modem-scoped reservation delete.', 'path' => null];
        }

        $response = $this->client->modemScopedReservationsDelete($normalizedMac);
        if (($response['ok'] ?? false) === true) {
            return [
                'ok' => true,
                'body' => $response['body'] ?? null,
                'error' => null,
                'path' => '/modem-scoped-reservations/{macAddress}',
            ];
        }

        return [
            'ok' => false,
            'body' => null,
            'error' => 'DDNet modem-scoped reservation delete failed: ' . (string) ($response['error'] ?? 'request failed'),
            'path' => '/modem-scoped-reservations/{macAddress}',
        ];
    }

    public function reservationDelete(string $modemMac): array
    {
        $normalizedMac = $this->normalizeMac($modemMac);
        if ($normalizedMac === '') {
            return ['ok' => false, 'error' => 'Invalid MAC address for reservation delete.', 'path' => null];
        }

        $response = $this->client->reservationsDelete($normalizedMac);
        if (($response['ok'] ?? false) === true || (int) ($response['status'] ?? 0) === 404) {
            return [
                'ok' => true,
                'body' => $response['body'] ?? null,
                'error' => null,
                'path' => '/reservations/{macAddress}',
                'status' => $response['status'] ?? null,
                'not_found' => (int) ($response['status'] ?? 0) === 404,
            ];
        }

        return [
            'ok' => false,
            'body' => null,
            'error' => 'DDNet reservation delete failed: ' . (string) ($response['error'] ?? 'request failed'),
            'path' => '/reservations/{macAddress}',
            'status' => $response['status'] ?? null,
        ];
    }

    public function upsertReservationBootfile(string $mac, string $bootfile): array
    {
        $normalizedMac = $this->normalizeMac($mac);
        $normalizedBootfile = trim($bootfile);
        if ($normalizedMac === '') {
            return ['ok' => false, 'error' => 'Invalid MAC address for reservation create.'];
        }
        if ($normalizedBootfile === '') {
            return ['ok' => false, 'error' => 'Bootfile is required for reservation create.'];
        }

        $reservation = [
            'mac_address' => $normalizedMac,
            'dhcp_options' => [
                '67' => $normalizedBootfile,
            ],
        ];

        $createResponse = $this->client->reservationsCreate($reservation);
        if (($createResponse['ok'] ?? false) === true) {
            return ['ok' => true, 'mode' => 'create', 'response' => $createResponse['body'] ?? null];
        }

        $updateResponse = $this->client->reservationsUpdate($normalizedMac, $reservation);
        if (($updateResponse['ok'] ?? false) === true) {
            return ['ok' => true, 'mode' => 'update', 'response' => $updateResponse['body'] ?? null];
        }

        $createError = trim((string) ($createResponse['error'] ?? 'reservation create failed'));
        $updateError = trim((string) ($updateResponse['error'] ?? 'reservation update failed'));

        return [
            'ok' => false,
            'error' => 'DDNet reservation failed. Create error: ' . $createError . ' | Update error: ' . $updateError,
        ];
    }

    private function normalizeMac(string $mac): string
    {
        $compact = preg_replace('/[^a-f0-9]/i', '', strtolower(trim($mac))) ?? '';
        if (strlen($compact) !== 12) {
            return '';
        }

        return implode(':', str_split($compact, 2));
    }

    private function extractLeaseIp(mixed $body, string $targetCompactMac): ?string
    {
        if (!is_array($body)) {
            return null;
        }

        $candidates = [];
        if (isset($body[0]) && is_array($body[0])) {
            $candidates = $body;
        } elseif (isset($body['leases']) && is_array($body['leases'])) {
            $candidates = $body['leases'];
        } elseif (isset($body['data']) && is_array($body['data'])) {
            $candidates = isset($body['data'][0]) ? $body['data'] : [$body['data']];
        } elseif (isset($body['lease']) && is_array($body['lease'])) {
            $candidates = [$body['lease']];
        } else {
            $candidates = [$body];
        }

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $candidateMac = (string) ($candidate['mac'] ?? $candidate['mac_address'] ?? $candidate['macAddress'] ?? $candidate['hwaddr'] ?? '');
            $candidateCompact = preg_replace('/[^a-f0-9]/i', '', strtolower($candidateMac)) ?? '';
            if ($candidateCompact !== '' && $targetCompactMac !== '' && $candidateCompact !== $targetCompactMac) {
                continue;
            }

            $ip = (string) ($candidate['ip_address'] ?? $candidate['ip'] ?? $candidate['address'] ?? '');
            if ($ip !== '') {
                return $ip;
            }
        }

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $ip = (string) ($candidate['ip_address'] ?? $candidate['ip'] ?? $candidate['address'] ?? '');
            if ($ip !== '') {
                return $ip;
            }
        }

        return null;
    }

    private function extractModemMacFromReservationBody(mixed $body): string
    {
        if (!is_array($body)) {
            return '';
        }

        $candidates = [];
        if (isset($body['data']) && is_array($body['data'])) {
            if (isset($body['data'][0]) && is_array($body['data'][0])) {
                $candidates = $body['data'];
            } else {
                $candidates[] = $body['data'];
            }
        }
        $candidates[] = $body;

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $rawValues = [];
            $rawValues[] = $candidate['modem_mac'] ?? null;
            $rawValues[] = $candidate['modemMac'] ?? null;
            if (isset($candidate['modem']) && is_array($candidate['modem'])) {
                $rawValues[] = $candidate['modem']['mac'] ?? null;
            }
            if (isset($candidate['option_82']) && is_array($candidate['option_82'])) {
                $rawValues[] = $candidate['option_82']['modem_mac'] ?? null;
            }
            if (isset($candidate['modem_lease']) && is_array($candidate['modem_lease'])) {
                $rawValues[] = $candidate['modem_lease']['mac_address'] ?? null;
            }
            if (isset($candidate['cpe_lease']) && is_array($candidate['cpe_lease'])) {
                $rawValues[] = $candidate['cpe_lease']['relay_agent_modem_mac'] ?? null;
            }
            $rawValues[] = $candidate['mac'] ?? null;

            foreach ($rawValues as $rawValue) {
                $compact = preg_replace('/[^a-f0-9]/i', '', strtolower((string) $rawValue)) ?? '';
                if (strlen($compact) === 12) {
                    return implode(':', str_split($compact, 2));
                }
            }
        }

        return '';
    }
}
