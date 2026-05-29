<?php

class Config
{
    private array $env;

    public function __construct(array $env)
    {
        $this->env = $env;
    }

    public static function fromEnvironment(): self
    {
        $keys = [
            'APP_NAME', 'APP_TITLE', 'APP_URL', 'APP_TIMEZONE',
            'APP_VERSION', 'APP_BUILD_DATE', 'APP_IMAGE_TAG',
            'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',
            'SGR_MASTER_ADMIN_SEEDS',
            'SERVICE_GROUPS', 'DEFAULT_SERVICE_PROFILE',
            'MODEM_EXCLUDED_UNIT_VALUES',
            'ADMIN_ACCESS_KEY',
            'GUNSLINGER_REFRESH_URL', 'GUNSLINGER_PROFILE_UPDATE_URL', 'GUNSLINGER_PROFILE_BOOTFILE_URL',
            'GUNSLINGER_REFRESH_URL_BY_SG', 'GUNSLINGER_PROFILE_UPDATE_URL_BY_SG', 'GUNSLINGER_PROFILE_BOOTFILE_URL_BY_SG',
            'GUNSLINGER_API_TOKEN', 'GUNSLINGER_BASIC_USER', 'GUNSLINGER_BASIC_PASS', 'GUNSLINGER_REFRESH_API_KEY', 'GUNSLINGER_UPDATE_ALLOWED_SGS', 'GUNSLINGER_TIMEOUT',
            'DDNET_BASE_URL', 'DDNET_TIMEOUT',
            'SNMP_ENABLED', 'SNMP_COMMUNITY', 'SNMP_REBOOT_OID', 'SNMP_REBOOT_VALUE', 'SNMP_TIMEOUT', 'SNMP_RETRIES', 'SNMP_RETRY_DELAY_MS',
            'SNMP_AUDIT_LOG_ENABLED', 'SNMP_AUDIT_LOG_PATH'
        ];

        $env = [];
        foreach ($keys as $key) {
            $env[$key] = getenv($key) === false ? '' : (string) getenv($key);
        }

        return new self($env);
    }

    public function get(string $key, ?string $default = null): string
    {
        $value = $this->env[$key] ?? '';
        if ($value === '') {
            return $default ?? '';
        }

        return $value;
    }

    public function getInt(string $key, int $default): int
    {
        $raw = $this->get($key, (string) $default);
        return is_numeric($raw) ? (int) $raw : $default;
    }

    public function getBool(string $key, bool $default): bool
    {
        $raw = strtolower($this->get($key, $default ? 'true' : 'false'));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    public function serviceGroups(): array
    {
        $raw = $this->get('SERVICE_GROUPS', '10');
        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== '');
        return array_values(array_map('intval', $parts));
    }

    public function modemExcludedUnitValues(): array
    {
        $raw = $this->get('MODEM_EXCLUDED_UNIT_VALUES', 'stock');
        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== '');
        return array_values(array_unique(array_map('strtolower', $parts)));
    }
}
