<?php

class SnmpRebootService
{
    public function __construct(
        private bool $enabled,
        private string $community,
        private string $oid,
        private string $value,
        private int $timeout,
        private int $retries,
        private int $retryDelayMs,
        private bool $auditLogEnabled,
        private string $auditLogPath
    ) {
    }

    public function reboot(string $ip): array
    {
        return $this->rebootWithCandidates([$ip]);
    }

    public function rebootWithCandidates(array $ips): array
    {
        if (!$this->enabled) {
            $result = ['ok' => true, 'skipped' => true, 'error' => null];
            $this->writeAudit(implode(',', $this->normalizeIpCandidates($ips)), 'SKIPPED', 'SNMP disabled');
            return $result;
        }

        $normalizedIps = $this->normalizeIpCandidates($ips);
        if ($normalizedIps === []) {
            return [
                'ok' => false,
                'skipped' => false,
                'error' => 'No valid reboot IP candidates were available',
            ];
        }

        $valueParts = preg_split('/\s+/', trim($this->value), 2);
        $valueType = $valueParts[0] ?? 'i';
        $valueData = $valueParts[1] ?? '1';

        $attemptLimit = max(1, $this->retries);
        $failureMessages = [];
        $candidateCount = count($normalizedIps);

        foreach ($normalizedIps as $candidateIndex => $ip) {
            for ($attempt = 1; $attempt <= $attemptLimit; $attempt++) {
                $command = sprintf(
                    'snmpset -v2c -c %s -t %d -r 0 %s %s %s %s',
                    escapeshellarg($this->community),
                    max(1, $this->timeout),
                    escapeshellarg($ip),
                    escapeshellarg($this->oid),
                    escapeshellarg($valueType),
                    escapeshellarg($valueData)
                );

                $output = [];
                $code = 0;
                exec($command . ' 2>&1', $output, $code);

                $error = $code === 0 ? null : implode("\n", $output);
                $meta = sprintf(
                    'oid=%s type=%s value=%s timeout=%d attempt=%d/%d candidate=%d/%d',
                    $this->oid,
                    $valueType,
                    $valueData,
                    max(1, $this->timeout),
                    $attempt,
                    $attemptLimit,
                    $candidateIndex + 1,
                    $candidateCount
                );

                $this->writeAudit(
                    $ip,
                    $code === 0 ? 'OK' : 'ERROR',
                    $code === 0 ? null : $error,
                    $code,
                    $meta
                );

                if ($code === 0) {
                    return [
                        'ok' => true,
                        'skipped' => false,
                        'error' => null,
                        'ip' => $ip,
                        'attempt' => $attempt,
                    ];
                }

                $errorText = trim((string) $error);
                if ($errorText === '') {
                    $errorText = 'unknown';
                }
                $failureMessages[] = sprintf('ip=%s attempt=%d error=%s', $ip, $attempt, preg_replace('/\s+/', ' ', $errorText));

                if ($attempt < $attemptLimit && $this->retryDelayMs > 0) {
                    usleep(max(0, $this->retryDelayMs) * 1000);
                }
            }
        }

        return [
            'ok' => false,
            'skipped' => false,
            'error' => 'SNMP reboot failed after retries. ' . implode(' ; ', $failureMessages),
            'tried_ips' => $normalizedIps,
        ];
    }

    private function normalizeIpCandidates(array $ips): array
    {
        $normalized = [];
        foreach ($ips as $ip) {
            $candidate = trim((string) $ip);
            if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_IP)) {
                continue;
            }
            $normalized[$candidate] = $candidate;
        }

        return array_values($normalized);
    }

    private function writeAudit(string $ip, string $status, ?string $error = null, ?int $exitCode = null, ?string $meta = null): void
    {
        if (!$this->auditLogEnabled) {
            return;
        }

        $dir = dirname($this->auditLogPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = [
            date('c'),
            'ip=' . $ip,
            'status=' . $status,
        ];

        if ($exitCode !== null) {
            $line[] = 'exit=' . $exitCode;
        }

        if ($meta !== null && $meta !== '') {
            $line[] = $meta;
        }

        if ($error !== null && $error !== '') {
            $singleLineError = preg_replace('/\s+/', ' ', trim($error));
            $line[] = 'error=' . $singleLineError;
        }

        @file_put_contents($this->auditLogPath, implode(' | ', $line) . PHP_EOL, FILE_APPEND);
    }
}
