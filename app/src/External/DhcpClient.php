<?php
// DhcpClient - Single-file REST client for DHCP Provisioning
// Requirements: PHP 7.4+ with curl and json extensions.
// Usage: include_once 'php/dhcp_client.php';

class DhcpClient
{
    // Defaults suitable for local development
    private string $baseUrl = "http://127.0.0.1:4000/api/dhcp";
    private int $timeout = 30; // seconds

    // Default headers applied to every request (merged per-call)
    private array $defaultHeaders = [
        "Accept" => "application/json",
        "User-Agent" => "DhcpClient/1.0 (PHP)"
    ];

    public function __construct(
        string $baseUrl = "http://127.0.0.1:4000/api/dhcp"
    ) {
        $this->baseUrl = rtrim($baseUrl, "/");
    }

    // --- Configuration setters (chainable) ---
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, "/");
        return $this;
    }

    public function setTimeout(int $seconds): self
    {
        if ($seconds > 0) {
            $this->timeout = $seconds;
        }
        return $this;
    }

    public function setDefaultHeaders(array $headers): self
    {
        foreach ($headers as $k => $v) {
            $this->defaultHeaders[$k] = $v;
        }
        return $this;
    }

    // --- Generic request helper ---
    public function request(
        string $method,
        string $path,
        array $query = [],
        $jsonBody = null,
        array $headers = []
    ): array {
        $url = $this->buildUrl($path, $query);

        $ch = curl_init();
        if ($ch === false) {
            return $this->fail("curl_init failed", $url);
        }

        $respHeaders = [];
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HEADERFUNCTION => function ($ch, $headerLine) use (
                &$respHeaders
            ) {
                $len = strlen($headerLine);
                $parts = explode(":", $headerLine, 2);
                if (count($parts) == 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    if (!isset($respHeaders[$name])) {
                        $respHeaders[$name] = $value;
                    } else {
                        $respHeaders[$name] .= ", " . $value;
                    }
                }
                return $len;
            },
        ];

        $flatHeaders = $this->flattenHeaders(
            $this->mergeHeaders($headers, $jsonBody !== null)
        );
        if (!empty($flatHeaders)) {
            $opts[CURLOPT_HTTPHEADER] = $flatHeaders;
        }

        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody);
            if ($payload === false) {
                curl_close($ch);
                return $this->fail(
                    "Failed to JSON-encode body: " . json_last_error_msg(),
                    $url
                );
            }
            $opts[CURLOPT_POSTFIELDS] = $payload;
        }

        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $errstr = $errno ? curl_error($ch) : null;
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: null;
        curl_close($ch);

        if ($errno) {
            return [
                "ok" => false,
                "status" => $status,
                "body" => null,
                "headers" => $respHeaders,
                "error" => "curl error " . $errno . ": " . $errstr,
                "url" => $url
            ];
        }

        $body = null;
        $isJson = false;
        if (is_string($raw) && $raw !== "") {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $body = $decoded;
                $isJson = true;
            } else {
                $body = $raw;
            }
        }

        $ok = $status !== null && $status >= 200 && $status < 300;
        return [
            "ok" => $ok,
            "status" => $status,
            "body" => $body,
            "headers" => $respHeaders,
            "error" => $ok
                ? null
                : $this->extractError($body, $isJson, $status),
            "url" => $url
        ];
    }

    // --- Templates (Keyed by Name) ---
    public function templatesList(array $query = []): array
    {
        return $this->request("GET", "/templates", $query);
    }

    public function templatesGet(string $name): array
    {
        return $this->request("GET", "/templates/" . urlencode($name));
    }

    public function templatesCreate(array $template): array
    {
        // Expected keys: name, description, is_system
        return $this->request("POST", "/templates", [], $template);
    }

    public function templatesUpdate(string $name, array $template): array
    {
        return $this->request(
            "PUT",
            "/templates/" . urlencode($name),
            [],
            $template
        );
    }

    public function templatesDelete(string $name): array
    {
        return $this->request("DELETE", "/templates/" . urlencode($name));
    }

    // --- Profiles (Keyed by Name) ---
    public function profilesList(array $query = []): array
    {
        return $this->request("GET", "/profiles", $query);
    }

    public function profilesGet(string $name): array
    {
        return $this->request("GET", "/profiles/" . urlencode($name));
    }

    public function profilesCreate(array $profile): array
    {
        // Expected keys: name, template_name, description
        return $this->request("POST", "/profiles", [], $profile);
    }

    public function profilesUpdate(string $name, array $profile): array
    {
        return $this->request(
            "PUT",
            "/profiles/" . urlencode($name),
            [],
            $profile
        );
    }

    public function profilesDelete(string $name): array
    {
        return $this->request("DELETE", "/profiles/" . urlencode($name));
    }

    // --- Profile Tag Management ---

    /**
     * Add a tag to a profile. Idempotent — no duplicate if tag already exists.
     * For key:value tags (e.g. "region:east"), replaces any existing tag with
     * the same key prefix.
     */
    public function profilesAddTag(string $name, string $tag): array
    {
        return $this->request("POST", "/profiles/" . urlencode($name) . "/add-tag", [], ["tag" => $tag]);
    }

    /**
     * Remove a tag from a profile. No-op if the tag doesn't exist.
     * For key:value tags, pass just the key prefix (e.g. "region") to remove
     * any tag starting with "region:", or the full tag for an exact match.
     */
    public function profilesRemoveTag(string $name, string $tag): array
    {
        return $this->request("POST", "/profiles/" . urlencode($name) . "/remove-tag", [], ["tag" => $tag]);
    }

    // --- Profile Options (Per-Profile Options) ---
    public function profileOptionsList(string $profileName): array
    {
        return $this->request(
            "GET",
            "/profiles/" . urlencode($profileName) . "/options"
        );
    }

    public function profileOptionsCreate(string $profileName, array $option): array
    {
        // Expected keys: option_key, value, value_type, description (optional)
        // option_key accepts codes, names, or aliases (e.g. '67', 'bootfile-name', 'option_67')
        // Example:
        // [
        //   'option_key' => 'bootfile-name',
        //   'value' => 'apple-island-cm.bin',
        //   'value_type' => 'string',
        //   'description' => 'Bootfile option for this profile'
        // ]
        return $this->request(
            "POST",
            "/profiles/" . urlencode($profileName) . "/options",
            [],
            $option
        );
    }

    public function profileOptionsUpdate(
        string $profileName,
        string $optionKey,
        array $option
    ): array {
        // Partial updates allowed: value, value_type, description
        // $optionKey accepts codes, names, or aliases (e.g. '67', 'bootfile-name', 'option_67')
        return $this->request(
            "PUT",
            "/profiles/" . urlencode($profileName) . "/options/" . urlencode($optionKey),
            [],
            $option
        );
    }

    public function profileOptionsDelete(string $profileName, string $optionKey): array
    {
        // $optionKey accepts codes, names, or aliases (e.g. '67', 'bootfile-name', 'option_67')
        return $this->request(
            "DELETE",
            "/profiles/" . urlencode($profileName) . "/options/" . urlencode($optionKey)
        );
    }

    // --- Reservations (Keyed by MAC) ---
    public function reservationsList(array $query = []): array
    {
        return $this->request("GET", "/reservations", $query);
    }

    public function reservationsGet(string $macAddress): array
    {
        return $this->request("GET", "/reservations/" . urlencode($macAddress));
    }

    public function reservationsCreate(array $reservation): array
    {
        // Expected keys: mac_address, ip_address (optional), hostname, dhcp_options, etc.
        return $this->request("POST", "/reservations", [], $reservation);
    }

    public function reservationsUpdate(
        string $macAddress,
        array $reservation
    ): array {
        return $this->request(
            "PUT",
            "/reservations/" . urlencode($macAddress),
            [],
            $reservation
        );
    }

    public function reservationsDelete(string $macAddress): array
    {
        return $this->request(
            "DELETE",
            "/reservations/" . urlencode($macAddress)
        );
    }

    public function reservationsEnable(string $macAddress): array
    {
        return $this->request(
            "POST",
            "/reservations/" . urlencode($macAddress) . "/enable"
        );
    }

    public function reservationsDisable(string $macAddress): array
    {
        return $this->request(
            "POST",
            "/reservations/" . urlencode($macAddress) . "/disable"
        );
    }

    public function modemScopedReservationsDelete(string $macAddress): array
    {
        return $this->request(
            "DELETE",
            "/modem-scoped-reservations/" . urlencode($macAddress)
        );
    }

    // --- DHCP Lease Operations ---
    public function leasesList(array $query = []): array
    {
        return $this->request("GET", "/leases", $query);
    }

    public function leasesGet(string $ipAddress): array
    {
        return $this->request("GET", "/leases/" . urlencode($ipAddress));
    }

    public function leasesDelete(string $ipAddress): array
    {
        return $this->request("DELETE", "/leases/" . urlencode($ipAddress));
    }

    public function leasesStats(): array
    {
        return $this->request("GET", "/stats");
    }

    /**
     * Flush the queued lease database sync updates immediately.
     */
    public function leasesPersist(): array
    {
        return $this->request("POST", "/persist");
    }

    /**
     * Trigger lease expiration cleanup.
     */
    public function leasesCleanup(): array
    {
        return $this->request("POST", "/cleanup");
    }

    // --- Vendor Bootfile Mappings (Keyed by Profile ID + UUID) ---
    /**
     * List all vendor bootfile mappings for a profile.
     *
     * @param int $profileId Profile ID
     * @param array $query Optional query params: active_only=true
     * @return array Response with 'ok', 'status', 'body', 'error'
     */
    public function vendorMappingsList(int $profileId, array $query = []): array
    {
        return $this->request(
            "GET",
            "/profiles/" . $profileId . "/vendor-mappings",
            $query
        );
    }

    /**
     * Get a specific vendor bootfile mapping.
     *
     * @param int $profileId Profile ID
     * @param string $mappingId Mapping UUID
     * @return array Response
     */
    public function vendorMappingsGet(int $profileId, string $mappingId): array
    {
        return $this->request(
            "GET",
            "/profiles/" . $profileId . "/vendor-mappings/" . urlencode($mappingId)
        );
    }

    /**
     * Create a new vendor bootfile mapping.
     *
     * @param int $profileId Profile ID
     * @param array $mapping Mapping data:
     *   - vendor_pattern (string, required): Pattern to match vendor names
     *   - bootfile (string, required): Bootfile filename
     *   - priority (int, optional): Lower = higher priority (default: 100)
     *   - description (string, optional): Notes about this mapping
     *   - is_active (bool, optional): Whether mapping is active (default: true)
     * @return array Response
     */
    public function vendorMappingsCreate(int $profileId, array $mapping): array
    {
        return $this->request(
            "POST",
            "/profiles/" . $profileId . "/vendor-mappings",
            [],
            $mapping
        );
    }

    /**
     * Update an existing vendor bootfile mapping.
     *
     * @param int $profileId Profile ID
     * @param string $mappingId Mapping UUID
     * @param array $mapping Updated fields (all optional):
     *   - vendor_pattern, bootfile, priority, description, is_active
     * @return array Response
     */
    public function vendorMappingsUpdate(
        int $profileId,
        string $mappingId,
        array $mapping
    ): array {
        return $this->request(
            "PUT",
            "/profiles/" . $profileId . "/vendor-mappings/" . urlencode($mappingId),
            [],
            $mapping
        );
    }

    /**
     * Delete a vendor bootfile mapping.
     *
     * @param int $profileId Profile ID
     * @param string $mappingId Mapping UUID
     * @return array Response
     */
    public function vendorMappingsDelete(int $profileId, string $mappingId): array
    {
        return $this->request(
            "DELETE",
            "/profiles/" . $profileId . "/vendor-mappings/" . urlencode($mappingId)
        );
    }

    /**
     * Toggle the is_active status of a vendor mapping.
     *
     * @param int $profileId Profile ID
     * @param string $mappingId Mapping UUID
     * @return array Response with updated mapping
     */
    public function vendorMappingsToggleActive(
        int $profileId,
        string $mappingId
    ): array {
        return $this->request(
            "POST",
            "/profiles/" . $profileId . "/vendor-mappings/" . urlencode($mappingId) . "/toggle-active"
        );
    }

    /**
     * Set the priority of a vendor mapping.
     *
     * @param int $profileId Profile ID
     * @param string $mappingId Mapping UUID
     * @param int $priority New priority (positive integer, lower = higher priority)
     * @return array Response with updated mapping
     */
    public function vendorMappingsSetPriority(
        int $profileId,
        string $mappingId,
        int $priority
    ): array {
        return $this->request(
            "PUT",
            "/profiles/" . $profileId . "/vendor-mappings/" . urlencode($mappingId) . "/priority",
            [],
            ["priority" => $priority]
        );
    }

    // --- Internals ---
    private function buildUrl(string $path, array $query = []): string
    {
        $path = "/" . ltrim($path, "/");
        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $qs = http_build_query($query);
            if ($qs !== "") {
                $url .= "?" . $qs;
            }
        }
        return $url;
    }

    private function mergeHeaders(array $extra, bool $hasBody): array
    {
        $headers = $this->defaultHeaders;
        if ($hasBody) {
            $headers["Content-Type"] =
                $headers["Content-Type"] ?? "application/json";
        }
        foreach ($extra as $k => $v) {
            $headers[$k] = $v;
        }
        return $headers;
    }

    private function flattenHeaders(array $assoc): array
    {
        $flat = [];
        foreach ($assoc as $k => $v) {
            $flat[] = $k . ": " . $v;
        }
        return $flat;
    }

    private function extractError($body, bool $isJson, ?int $status): string
    {
        if ($isJson && is_array($body)) {
            if (isset($body["errors"])) {
                return "errors: " . json_encode($body["errors"]);
            }
            if (isset($body["error"])) {
                return is_string($body["error"])
                    ? $body["error"]
                    : json_encode($body["error"]);
            }
            if (isset($body["code"]) || isset($body["message"])) {
                return trim(
                    ($body["code"] ?? "") . " " . ($body["message"] ?? "")
                );
            }
        }
        $prefix = $status !== null ? "HTTP " . $status . " " : "";
        if (is_string($body) && $body !== "") {
            return $prefix . $body;
        }
        return $prefix . "request failed";
    }

    private function fail(string $msg, string $url): array
    {
        return [
            "ok" => false,
            "status" => null,
            "body" => null,
            "headers" => [],
            "error" => $msg,
            "url" => $url
        ];
    }
}
