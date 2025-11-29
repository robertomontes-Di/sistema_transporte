<?php
// includes/db.php
// Ajusta credenciales antes de usar
date_default_timezone_set('America/El_Salvador');
$DB_HOST = '136.116.4.37';
$DB_NAME = 'transport';
$DB_USER = 'transport_user';
$DB_PASS = 'Int3124&DI';

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