<?php
$host = "192.168.1.3";
$db   = "transport";
$user = "application";
$pass = "Int3124&DI";

$t0 = microtime(true);
try {
  $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 3,
  ]);

  $t1 = microtime(true);
  $pdo->query("SELECT 1");
  $t2 = microtime(true);

  echo "connect_ms=" . round(($t1-$t0)*1000, 2) . "\n";
  echo "query_ms="   . round(($t2-$t1)*1000, 2) . "\n";
  echo "total_ms="   . round(($t2-$t0)*1000, 2) . "\n";
} catch (Exception $e) {
  echo "ERROR: " . $e->getMessage() . "\n";
}