<?php
// api/v1/_helpers.php
declare(strict_types=1);

function json_in(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function out(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function base64url_encode(string $data): string {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode(string $data): string {
  $pad = strlen($data) % 4;
  if ($pad) $data .= str_repeat('=', 4 - $pad);
  return base64_decode(strtr($data, '-_', '+/')) ?: '';
}

function sign_token(array $payload): string {
  $payload['iat'] = time();
  $payload['exp'] = time() + (defined('API_TOKEN_TTL_SECONDS') ? (int)API_TOKEN_TTL_SECONDS : 2592000);

  $p = base64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));
  $sig = hash_hmac('sha256', $p, (string)API_SECRET, true);
  return $p . '.' . base64url_encode($sig);
}

function verify_token(string $token): ?array {
  $parts = explode('.', $token);
  if (count($parts) !== 2) return null;
  [$p, $s] = $parts;

  $expected = base64url_encode(hash_hmac('sha256', $p, (string)API_SECRET, true));
  if (!hash_equals($expected, $s)) return null;

  $payload = json_decode(base64url_decode($p), true);
  if (!is_array($payload)) return null;

  if (isset($payload['exp']) && time() > (int)$payload['exp']) return null;
  return $payload;
}

function require_auth(): array {
  $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
    out(['success' => false, 'message' => 'Falta Authorization: Bearer <token>'], 401);
  }
  $payload = verify_token(trim($m[1]));
  if (!$payload) out(['success' => false, 'message' => 'Token inválido o expirado'], 401);
  return $payload;
}

function db_has_column(PDO $pdo, string $table, string $column): bool {
  $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c";
  $st = $pdo->prepare($sql);
  $st->execute([':t' => $table, ':c' => $column]);
  return (int)$st->fetchColumn() > 0;
}