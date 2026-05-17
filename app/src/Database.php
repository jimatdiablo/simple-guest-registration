<?php

class Database
{
    public static function connect(Config $config): PDO
    {
        $host = $config->get('DB_HOST', 'db');
        $port = $config->get('DB_PORT', '3306');
        $db = $config->get('DB_NAME', 'guest_registration');
        $user = $config->get('DB_USER', 'guestapp');
        $pass = $config->get('DB_PASSWORD', 'guestapp');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
