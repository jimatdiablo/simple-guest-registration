<?php

class GunslingerClient
{
    public function __construct(
        private HttpClient $http,
        private string $refreshUrl,
        private string $profileUpdateUrl,
        private string $profileBootfileUrl,
        private array $refreshUrlBySg,
        private array $profileUpdateUrlBySg,
        private array $profileBootfileUrlBySg,
        private string $token,
        private string $basicUser,
        private string $basicPass,
        private int $timeout,
        private array $allowedUpdateSgs = [10]
    ) {
    }

    public function refreshCustomers(array $serviceGroups): array
    {
        $requestedGroups = array_values(array_unique(array_map('intval', $serviceGroups)));
        if ($requestedGroups === []) {
            $requestedGroups = [10];
        }

        $batches = $this->groupServiceGroupsByEndpoint($requestedGroups, $this->refreshUrlBySg, $this->refreshUrl);
        if ($batches === []) {
            return ['ok' => true, 'rows' => [], 'skipped' => true, 'requested_service_groups' => $requestedGroups];
        }

        $headers = $this->authHeaders();
        $allRows = [];
        $allReturnedGroups = [];

        foreach ($batches as $batch) {
            $endpoint = (string) ($batch['endpoint'] ?? '');
            $batchGroups = (array) ($batch['service_groups'] ?? []);
            if ($endpoint === '' || $batchGroups === []) {
                continue;
            }

            $payload = [
                'service_groups' => implode(',', $batchGroups),
                'limit' => 5000,
            ];
            $response = $this->http->request('POST', $endpoint, $headers, $payload, $this->timeout);
            if (!$response['ok']) {
                $detail = 'Refresh API request failed';
                if (isset($response['status'])) {
                    $detail .= ' (HTTP ' . (int) $response['status'] . ')';
                }

                if (isset($response['body']) && is_array($response['body']) && isset($response['body']['error'])) {
                    $detail .= ': ' . (string) $response['body']['error'];
                } elseif (isset($response['body']) && is_string($response['body']) && trim($response['body']) !== '') {
                    $detail .= ': ' . trim($response['body']);
                }

                return [
                    'ok' => false,
                    'rows' => [],
                    'skipped' => false,
                    'error' => $detail,
                    'response' => $response['body'] ?? null,
                    'requested_service_groups' => $requestedGroups,
                    'endpoint' => $endpoint,
                    'endpoint_service_groups' => $batchGroups,
                ];
            }

            $body = $response['body'];
            if (is_array($body) && isset($body['data']) && is_array($body['data'])) {
                $allRows = array_merge($allRows, $body['data']);
            } elseif (is_array($body) && isset($body[0])) {
                $allRows = array_merge($allRows, $body);
            }

            $returnedGroups = $batchGroups;
            if (is_array($body) && isset($body['service_groups']) && is_array($body['service_groups'])) {
                $returnedGroups = array_values(array_unique(array_map('intval', $body['service_groups'])));
                $missing = array_values(array_diff($batchGroups, $returnedGroups));
                if ($missing !== []) {
                    return [
                        'ok' => false,
                        'rows' => [],
                        'skipped' => false,
                        'error' => 'Refresh response did not include all configured service groups: missing ' . implode(',', $missing),
                        'response' => $body,
                        'requested_service_groups' => $requestedGroups,
                        'returned_service_groups' => array_values(array_unique($allReturnedGroups)),
                        'endpoint' => $endpoint,
                        'endpoint_service_groups' => $batchGroups,
                    ];
                }
            }

            $allReturnedGroups = array_merge($allReturnedGroups, $returnedGroups);
        }

        return [
            'ok' => true,
            'rows' => $allRows,
            'skipped' => false,
            'requested_service_groups' => $requestedGroups,
            'returned_service_groups' => array_values(array_unique(array_map('intval', $allReturnedGroups))),
        ];
    }

    public function updateProfile(int $sg, string $unit, string $mac, string $profile): array
    {
        if (!in_array($sg, $this->allowedUpdateSgs, true)) {
            return [
                'ok' => false,
                'skipped' => false,
                'error' => 'Profile update blocked: sg is not allowed for alteration',
                'response' => [
                    'sg' => $sg,
                    'allowed_sg' => $this->allowedUpdateSgs,
                ],
            ];
        }

        $endpoint = $this->endpointForServiceGroup($sg, $this->profileUpdateUrlBySg, $this->profileUpdateUrl);
        if ($endpoint === '') {
            return ['ok' => true, 'skipped' => true];
        }

        $headers = $this->authHeaders();
        $payload = [
            'sg' => $sg,
            'unit' => $unit,
            'mac' => strtolower($mac),
            'profile' => $profile,
            'limit' => 1,
        ];

        $response = $this->http->request('POST', $endpoint, $headers, $payload, $this->timeout);

        $error = null;
        if (!$response['ok']) {
            $error = 'Profile update API request failed';
            if (isset($response['status'])) {
                $error .= ' (HTTP ' . (int) $response['status'] . ')';
            }

            if (isset($response['body']) && is_array($response['body']) && isset($response['body']['error'])) {
                $error .= ': ' . (string) $response['body']['error'];
                if (isset($response['body']['details']) && is_string($response['body']['details']) && trim($response['body']['details']) !== '') {
                    $error .= ' | details: ' . trim($response['body']['details']);
                }
            } elseif (isset($response['body']) && is_string($response['body']) && trim($response['body']) !== '') {
                $error .= ': ' . trim($response['body']);
            }
        }

        return [
            'ok' => $response['ok'],
            'skipped' => false,
            'error' => $error,
            'response' => $response['body'],
            'status' => $response['status'] ?? null,
            'endpoint' => $endpoint,
        ];
    }

    public function fetchWorkingProfileBootfiles(array $serviceGroups, array $profilesBySg): array
    {
        $requestedGroups = array_values(array_unique(array_map('intval', $serviceGroups)));
        if ($requestedGroups === []) {
            $requestedGroups = [10];
        }

        $batches = $this->groupServiceGroupsByEndpoint($requestedGroups, $this->profileBootfileUrlBySg, $this->profileBootfileUrl);
        if ($batches === []) {
            return ['ok' => true, 'rows' => [], 'skipped' => true];
        }

        $headers = $this->authHeaders();
        $allRows = [];

        foreach ($batches as $batch) {
            $endpoint = (string) ($batch['endpoint'] ?? '');
            $batchGroups = (array) ($batch['service_groups'] ?? []);
            if ($endpoint === '' || $batchGroups === []) {
                continue;
            }

            $batchProfiles = [];
            foreach ($batchGroups as $sg) {
                $name = trim((string) ($profilesBySg[(int) $sg] ?? ''));
                if ($name !== '') {
                    $batchProfiles[] = $name;
                }
            }
            $batchProfiles = array_values(array_unique($batchProfiles));

            $payload = [
                'service_groups' => implode(',', $batchGroups),
            ];
            if ($batchProfiles !== []) {
                $payload['profile_names'] = implode(',', $batchProfiles);
            }

            $response = $this->http->request('POST', $endpoint, $headers, $payload, $this->timeout);
            if (!$response['ok']) {
                $detail = 'Bootfile sync API request failed';
                if (isset($response['status'])) {
                    $detail .= ' (HTTP ' . (int) $response['status'] . ')';
                }

                if (isset($response['body']) && is_array($response['body']) && isset($response['body']['error'])) {
                    $detail .= ': ' . (string) $response['body']['error'];
                } elseif (isset($response['body']) && is_string($response['body']) && trim($response['body']) !== '') {
                    $detail .= ': ' . trim($response['body']);
                }

                return [
                    'ok' => false,
                    'rows' => [],
                    'skipped' => false,
                    'error' => $detail,
                    'response' => $response['body'] ?? null,
                    'endpoint' => $endpoint,
                    'endpoint_service_groups' => $batchGroups,
                ];
            }

            $rows = $this->extractBootfileRows($response['body'] ?? null, $batchGroups, $profilesBySg);
            if ($rows !== []) {
                $allRows = array_merge($allRows, $rows);
            }
        }

        return ['ok' => true, 'rows' => array_values($allRows), 'skipped' => false];
    }

    private function extractBootfileRows(mixed $body, array $requestedGroups, array $profilesBySg): array
    {
        $sourceRows = [];
        if (is_array($body) && isset($body['data']) && is_array($body['data'])) {
            $sourceRows = $body['data'];
        } elseif (is_array($body) && isset($body[0])) {
            $sourceRows = $body;
        } elseif (is_array($body)) {
            $sourceRows = [$body];
        }

        $profilesBySgNormalized = [];
        foreach ($profilesBySg as $sg => $profileName) {
            $normalizedSg = (int) $sg;
            $normalizedProfile = trim((string) $profileName);
            if ($normalizedSg <= 0 || $normalizedProfile === '') {
                continue;
            }
            $profilesBySgNormalized[$normalizedSg] = strtolower($normalizedProfile);
        }

        $requestedMap = array_fill_keys(array_map('intval', $requestedGroups), true);
        $out = [];
        foreach ($sourceRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sg = (int) ($row['sg'] ?? $row['service_group'] ?? $row['serviceGroup'] ?? 0);
            $profileName = trim((string) ($row['profile_name'] ?? $row['profile'] ?? $row['sname'] ?? ''));
            $bootfile = trim((string) ($row['bootfile_filename'] ?? $row['bootfile'] ?? $row['filename'] ?? ''));

            if ($sg <= 0 || $profileName === '' || $bootfile === '') {
                continue;
            }
            if (!isset($requestedMap[$sg])) {
                continue;
            }

            $expectedProfile = $profilesBySgNormalized[$sg] ?? '';
            if ($expectedProfile !== '' && strtolower($profileName) !== $expectedProfile) {
                continue;
            }

            $key = $sg . '|' . strtolower($profileName);
            $out[$key] = [
                'sg' => $sg,
                'profile_name' => $profileName,
                'bootfile_filename' => $bootfile,
            ];
        }

        return array_values($out);
    }

    private function endpointForServiceGroup(int $sg, array $urlBySg, string $defaultUrl): string
    {
        $candidate = trim((string) ($urlBySg[$sg] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        return trim($defaultUrl);
    }

    private function groupServiceGroupsByEndpoint(array $serviceGroups, array $urlBySg, string $defaultUrl): array
    {
        $groups = array_values(array_unique(array_map('intval', $serviceGroups)));
        if ($groups === []) {
            return [];
        }

        $grouped = [];
        foreach ($groups as $sg) {
            if ($sg <= 0) {
                continue;
            }

            $endpoint = $this->endpointForServiceGroup($sg, $urlBySg, $defaultUrl);
            if ($endpoint === '') {
                continue;
            }

            if (!isset($grouped[$endpoint])) {
                $grouped[$endpoint] = [];
            }
            $grouped[$endpoint][] = $sg;
        }

        $out = [];
        foreach ($grouped as $endpoint => $sgs) {
            $out[] = [
                'endpoint' => $endpoint,
                'service_groups' => array_values(array_unique(array_map('intval', $sgs))),
            ];
        }

        return $out;
    }

    private function authHeaders(): array
    {
        if (trim($this->basicUser) !== '' || trim($this->basicPass) !== '') {
            $token = base64_encode($this->basicUser . ':' . $this->basicPass);
            return ['Authorization' => 'Basic ' . $token];
        }

        if (trim($this->token) !== '') {
            return ['Authorization' => 'Bearer ' . $this->token];
        }

        return [];
    }
}
