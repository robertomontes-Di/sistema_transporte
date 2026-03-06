<?php
// includes/db.php
// Ajusta credenciales antes de usar
date_default_timezone_set('America/El_Salvador');
$DB_HOST = '192.168.1.3';
$DB_NAME = 'transport';
$DB_USER = 'application';
$DB_PASS = 'Int3124&DI';

// ===== API AUTH =====
define('API_SECRET', 'DI_TRANSPORTE_' . 'Integracion2026$');
define('API_TOKEN_TTL_SECONDS', 60 * 60 * 24 * 30); // 30 días

try {
    $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
     $pdo->exec("SET time_zone = '-06:00'");
} catch (Exception $e) {
    die('Error DB: '.$e->getMessage());
}
?>