<?php

class HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?array $json = null, int $timeout = 10): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Unable to initialize curl'];
        }

        $flatHeaders = ['Accept: application/json'];
        foreach ($headers as $k => $v) {
            $flatHeaders[] = $k . ': ' . $v;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $flatHeaders,
        ];

        if ($json !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($json);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return ['ok' => false, 'status' => $status, 'body' => null, 'error' => $error];
        }

        $body = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $body = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $body,
            'error' => null,
        ];
    }
}
