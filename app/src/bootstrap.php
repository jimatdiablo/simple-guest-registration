<?php

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CustomersRepository.php';
require_once __DIR__ . '/ModemRepository.php';
require_once __DIR__ . '/GuestRepository.php';
require_once __DIR__ . '/ProfileBootfileRepository.php';
require_once __DIR__ . '/SettingsRepository.php';
require_once __DIR__ . '/UserRepository.php';
require_once __DIR__ . '/External/DhcpClient.php';
require_once __DIR__ . '/Services/HttpClient.php';
require_once __DIR__ . '/Services/DdnetClient.php';
require_once __DIR__ . '/Services/AutoCheckoutService.php';
require_once __DIR__ . '/Services/GuestProvisioningService.php';
require_once __DIR__ . '/Services/GunslingerClient.php';
require_once __DIR__ . '/Services/SnmpRebootService.php';

$config = Config::fromEnvironment();
date_default_timezone_set($config->get('APP_TIMEZONE', 'America/New_York'));

$pdo = Database::connect($config);
$customers = new CustomersRepository($pdo);
$modems = new ModemRepository($pdo, $config->modemExcludedUnitValues());
$guests = new GuestRepository($pdo);
$profileBootfiles = new ProfileBootfileRepository($pdo);
$settings = new SettingsRepository($pdo);
$users = new UserRepository($pdo);
$http = new HttpClient();

$ddnet = new DdnetClient(
    $config->get('DDNET_BASE_URL', 'http://192.168.160.220:4000/api/dhcp'),
    $config->getInt('DDNET_TIMEOUT', 10)
);

$allowedUpdateSgsRaw = trim($config->get('GUNSLINGER_UPDATE_ALLOWED_SGS', '10'));
$allowedUpdateSgs = array_values(array_unique(array_map(
    'intval',
    array_filter(array_map('trim', explode(',', $allowedUpdateSgsRaw)), static fn ($v) => $v !== '')
)));
if ($allowedUpdateSgs === []) {
    $allowedUpdateSgs = [10];
}

$parseSgUrlMap = static function (string $raw): array {
    $result = [];
    $raw = trim($raw);
    if ($raw === '') {
        return $result;
    }

    $parts = preg_split('/[;,]/', $raw);
    if (!is_array($parts)) {
        return $result;
    }

    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }

        if (preg_match('/^(\d+)\s*[:=]\s*(.+)$/', $part, $matches) !== 1) {
            continue;
        }

        $sg = (int) ($matches[1] ?? 0);
        $url = trim((string) ($matches[2] ?? ''));
        if ($sg <= 0 || $url === '') {
            continue;
        }

        $result[$sg] = $url;
    }

    return $result;
};

$refreshUrlBySg = $parseSgUrlMap($config->get('GUNSLINGER_REFRESH_URL_BY_SG', ''));
$profileUpdateUrlBySg = $parseSgUrlMap($config->get('GUNSLINGER_PROFILE_UPDATE_URL_BY_SG', ''));
$profileBootfileUrlBySg = $parseSgUrlMap($config->get('GUNSLINGER_PROFILE_BOOTFILE_URL_BY_SG', ''));

$gunslinger = new GunslingerClient(
    $http,
    $config->get('GUNSLINGER_REFRESH_URL', ''),
    $config->get('GUNSLINGER_PROFILE_UPDATE_URL', ''),
    $config->get('GUNSLINGER_PROFILE_BOOTFILE_URL', ''),
    $refreshUrlBySg,
    $profileUpdateUrlBySg,
    $profileBootfileUrlBySg,
    $config->get('GUNSLINGER_API_TOKEN', ''),
    $config->get('GUNSLINGER_BASIC_USER', ''),
    $config->get('GUNSLINGER_BASIC_PASS', ''),
    $config->getInt('GUNSLINGER_TIMEOUT', 10),
    $allowedUpdateSgs
);

$snmp = new SnmpRebootService(
    $config->getBool('SNMP_ENABLED', false),
    $config->get('SNMP_COMMUNITY', 'private'),
    $config->get('SNMP_REBOOT_OID', '1.3.6.1.2.1.69.1.1.3.0'),
    $config->get('SNMP_REBOOT_VALUE', 'i 1'),
    $config->getInt('SNMP_TIMEOUT', 3),
    $config->getInt('SNMP_RETRIES', 3),
    $config->getInt('SNMP_RETRY_DELAY_MS', 750),
    $config->getBool('SNMP_AUDIT_LOG_ENABLED', true),
    $config->get('SNMP_AUDIT_LOG_PATH', '/var/www/html/storage/logs/snmp_audit.log')
);

$autoCheckoutService = new AutoCheckoutService(
    $guests,
    $settings,
    $modems,
    $gunslinger,
    $ddnet,
    $snmp
);

$guestProvisioningService = new GuestProvisioningService(
    $guests,
    $modems,
    $profileBootfiles,
    $gunslinger,
    $ddnet,
    $snmp
);

$serviceGroups = $config->serviceGroups();
$envDefaultProfile = $config->get('DEFAULT_SERVICE_PROFILE', '10baseservice');
$defaultProfile = $settings->get('default_service_profile', $envDefaultProfile) ?? $envDefaultProfile;
