# DHCP Client Documentation

A simple, single-file PHP client for interacting with the ddnet DHCP Provisioning API.

## Setup

Include the client file and instantiate it with your API base URL.

```php
<?php
include_once 'dhcp_client.php';

// Initialize client with base URL
$client = new DhcpClient('http://127.0.0.1:4000/api/dhcp');

// Optional: Configure timeout
$client->setTimeout(10);
?>
```

## Templates

Manage base configuration templates (e.g., CM, MTA, CPE).

### List Templates

```php
$response = $client->templatesList();
if ($response['ok']) {
    print_r($response['body']['data']);
}
```

### Create Template

```php
$template = [
    'name' => 'DOCSIS-3.1',
    'description' => 'Baseline for D3.1 Modems',
    'is_system' => false
];

$response = $client->templatesCreate($template);
```

### Get Template

Templates are retrieved by name.

```php
$response = $client->templatesGet('DOCSIS-3.1');
```

### Update Template

```php
$update = ['description' => 'Updated baseline description'];
$response = $client->templatesUpdate('DOCSIS-3.1', $update);
```

### Delete Template

```php
$client->templatesDelete('DOCSIS-3.1');
```

## Profiles

Manage location or group-specific configuration profiles that inherit from templates.

### Create Profile

```php
$profile = [
    'name' => 'Region-East',
    'template_name' => 'DOCSIS-3.1', // Must exist
    'description' => 'East Coast Configuration'
];

$response = $client->profilesCreate($profile);
```

### Get Profile

Profiles are retrieved by name.

```php
$response = $client->profilesGet('Region-East');
```

### Update Profile

```php
$update = ['description' => 'New description for East Region'];
$response = $client->profilesUpdate('Region-East', $update);
```

### Delete Profile

```php
$client->profilesDelete('Region-East');
```

### Profile Tag Management

Add or remove individual tags without replacing the entire tag array.

#### Add a Tag

Idempotent — if the tag already exists, no duplicate is created. For key:value tags,
any existing tag with the same key prefix is replaced.

```php
// Add a simple tag
$client->profilesAddTag('Region-East', 'production');

// Add a key:value tag
$client->profilesAddTag('Region-East', 'region:east');

// Update an existing key:value tag (replaces region:east)
$client->profilesAddTag('Region-East', 'region:west');
```

#### Remove a Tag

No-op if the tag doesn't exist. Supports key prefix matching for key:value tags.

```php
// Remove an exact tag
$client->profilesRemoveTag('Region-East', 'production');

// Remove by key prefix — removes any "region:*" tag
$client->profilesRemoveTag('Region-East', 'region');
```

### Profile Options

Profiles can have per-profile DHCP options (profile options) that customize or override behavior from the base template.

#### List Profile Options

```php
$response = $client->profileOptionsList('Region-East');
if ($response['ok']) {
    // Each entry has: option_key, name, source, value, value_type, description
    print_r($response['body']['data']);
}
```

#### Add a Profile Option

The `option_key` field accepts numeric codes, names, or aliases — e.g. `'67'`, `'bootfile-name'`, `'option_67'`.

```php
$option = [
    'option_key' => 'bootfile-name',
    'value' => 'east-region-cm.bin',
    'value_type' => 'string',
    'description' => 'Bootfile for Region-East'
];

$response = $client->profileOptionsCreate('Region-East', $option);
if (!$response['ok']) {
    echo "Error: " . $response['error'] . PHP_EOL;
}
```

#### Update a Profile Option

The `$optionKey` parameter accepts the same flexible formats.

```php
$update = [
    'value' => 'east-region-updated.bin',
    'description' => 'Updated bootfile for Region-East'
];

$response = $client->profileOptionsUpdate('Region-East', 'bootfile-name', $update);
```

#### Delete a Profile Option

```php
$response = $client->profileOptionsDelete('Region-East', 'bootfile-name');
```

## Reservations

Manage static MAC-to-IP bindings and device-specific options.

### Create Reservation

```php
$reservation = [
    'mac_address' => '00:11:22:33:44:55',
    'ip_address' => '10.10.10.50',
    'hostname' => 'customer-modem-1',
    'dhcp_options' => [
        '67' => 'bootfile.bin'
    ]
];

$response = $client->reservationsCreate($reservation);
```

### Get Reservation

Reservations are retrieved by MAC address.

```php
$response = $client->reservationsGet('00:11:22:33:44:55');
```

### Update Reservation

```php
$update = [
    'hostname' => 'new-hostname-1',
    'ip_address' => '10.10.10.51'
];

$response = $client->reservationsUpdate('00:11:22:33:44:55', $update);
```

### Enable/Disable Reservation

Helper methods are available to quickly toggle status.

```php
// Disable
$client->reservationsDisable('00:11:22:33:44:55');

// Enable
$client->reservationsEnable('00:11:22:33:44:55');
```

### Delete Reservation

```php
$client->reservationsDelete('00:11:22:33:44:55');
```

## DHCP Leases

Query runtime DHCP lease state from the ETS-backed lease API and trigger lease maintenance operations.

### List Leases

```php
$response = $client->leasesList();
if ($response['ok']) {
    print_r($response['body']['leases']);
}
```

Filter examples:

```php
$client->leasesList(['state' => 'active']);
$client->leasesList(['mac' => '00:11:22:33:44:55']);
$client->leasesList(['subnet' => '10.40.1.0/24']);
```

### Get Lease by IP

```php
$response = $client->leasesGet('10.40.1.25');
if ($response['ok']) {
    print_r($response['body']['lease']);
}
```

### Delete Lease by IP

```php
$response = $client->leasesDelete('10.40.1.25');
if ($response['ok']) {
    echo $response['body']['message'] . PHP_EOL;
}
```

### Lease Statistics

```php
$response = $client->leasesStats();
if ($response['ok']) {
    print_r($response['body']['stats']);
}
```

### Flush Lease Database Sync Queue

This calls `POST /api/dhcp/persist`. It does not write to DETS; it flushes queued lease updates to PostgreSQL immediately.

```php
$response = $client->leasesPersist();
if ($response['ok']) {
    echo $response['body']['message'] . PHP_EOL;
    // "Lease database sync queue flushed"
}
```

### Trigger Lease Cleanup

```php
$response = $client->leasesCleanup();
if ($response['ok']) {
    echo $response['body']['message'] . PHP_EOL;
    // "Cleanup triggered"
}
```

## Error Handling

All methods return an associative array with an `ok` boolean.

```php
$response = $client->reservationsGet('invalid-mac');

if (!$response['ok']) {
    echo "Error: " . $response['error']; // e.g., "HTTP 404 Reservation not found..."
    echo "Status Code: " . $response['status'];
}
```
