<?php
declare(strict_types=1);

function h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function authorization_header_value(array $server, array $headers = []): string {
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $name) {
        $value = trim((string)($server[$name] ?? ''));
        if ($value !== '') return $value;
    }
    foreach ($headers as $name => $value) {
        if (strcasecmp((string)$name, 'Authorization') === 0) return trim((string)$value);
    }
    return '';
}
function ms_now(): int { return (int) round(microtime(true) * 1000); }
function utc_now(): string { return gmdate('Y-m-d H:i:s'); }
function sql_one(string $sql, array $params = []): ?array {
    $stmt = db()->prepare($sql); $stmt->execute($params);
    $row = $stmt->fetch(); return $row ?: null;
}
function sql_all(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
}
function sql_exec(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt->rowCount();
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string) $_SESSION['csrf'];
}
function check_csrf(): void {
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        throw new RuntimeException('Token de formulário inválido. Recarregue a página.');
    }
}
function admin_id(): ?int { return isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null; }
function require_admin(bool $json = false): void {
    if (admin_id()) return;
    if ($json) json_response(['error' => 'unauthorized'], 401);
    header('Location: index.php'); exit;
}
function flash(string $kind, string $message): void {
    $_SESSION['flash'][] = [$kind, $message];
}
function safe_error(Throwable $error): string {
    if ($error instanceof TTLockApiException) return 'TTLock: código ' . $error->errorCode;
    if ($error instanceof PDOException) return 'Falha de banco de dados. Confira a configuração.';
    return 'Não foi possível concluir a operação. Verifique os dados e o diagnóstico.';
}
function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
function require_string(array $input, string $key, int $max = 150): string {
    $value = trim((string) ($input[$key] ?? ''));
    if ($value === '' || mb_strlen($value, 'UTF-8') > $max) throw new InvalidArgumentException('Campo obrigatório inválido: ' . $key);
    return $value;
}
function utc_date(string $input): string {
    $input = trim($input);
    $timezone = new DateTimeZone('America/Sao_Paulo');
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $input)) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $input, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d\TH:i') !== $input) {
            throw new InvalidArgumentException('Data/hora local inválida.');
        }
    } else {
        if (!preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $input)) throw new InvalidArgumentException('Data deve conter fuso ou ser local no formato AAAA-MM-DDTHH:MM.');
        try { $date = new DateTimeImmutable($input); }
        catch (Throwable) { throw new InvalidArgumentException('Data/hora inválida.'); }
    }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
function local_date(?string $utc): string {
    if (!$utc) return '—';
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i');
}
function local_input(?string $utc): string {
    if (!$utc) return '';
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d\TH:i');
}
function utc_millis(string $utc): int {
    return ((int) (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('U')) * 1000;
}
function valid_period(string $from, string $until): void {
    if ($from >= $until) throw new InvalidArgumentException('Checkout precisa ser posterior ao check-in.');
}
function crypt_key(): string {
    $hex = (string) config('app_key_hex', '');
    if (!preg_match('/^[a-f0-9]{64}$/i', $hex)) throw new RuntimeException('Chave de criptografia não configurada.');
    return hex2bin($hex);
}
function encrypt_private(string $plain): string {
    $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', crypt_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Falha de criptografia.');
    return base64_encode($iv . $tag . $cipher);
}
function decrypt_private(string $encoded): string {
    $bytes = base64_decode($encoded, true);
    if ($bytes === false || strlen($bytes) < 28) throw new RuntimeException('Credencial criptografada inválida.');
    $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', crypt_key(), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16));
    if ($plain === false) throw new RuntimeException('Não foi possível decifrar o token.');
    return $plain;
}
function audit_event(string $action, string $result, ?int $reservationId = null, ?int $lockId = null, ?int $keyId = null, string $detail = ''): void {
    // O detalhe é fornecido pelo código, jamais pelas respostas integrais da API.
    sql_exec('INSERT INTO audit_logs (user_id,reservation_id,lock_id,key_id,action,result,detail) VALUES (?,?,?,?,?,?,?)',
        [admin_id(), $reservationId, $lockId, $keyId, substr($action,0,80), substr($result,0,40), substr($detail,0,220)]);
}
function setting(string $name, string $fallback): string {
    return (string) (sql_one('SELECT value FROM settings WHERE name=?', [$name])['value'] ?? $fallback);
}
function quota_data(): array {
    $start = gmdate('Y-m-01 00:00:00');
    $used = (int) (sql_one('SELECT COUNT(*) AS n FROM api_calls WHERE created_at >= ?', [$start])['n'] ?? 0);
    $max = max(1, (int) setting('monthly_quota','30000'));
    return ['used'=>$used,'max'=>$max,'pct'=>round(100*$used/$max,1)];
}
function current_ip_hash(): string {
    return hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), crypt_key());
}
function do_login(string $username, string $password): bool {
    $username = trim($username);
    $ip = current_ip_hash();
    $recent = sql_one('SELECT COUNT(*) AS n FROM login_attempts WHERE created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) AND (username = ? OR ip_hash = ?)',[$username,$ip]);
    if ((int) ($recent['n'] ?? 0) >= 6) throw new RuntimeException('Muitas tentativas. Tente novamente após 15 minutos.');
    $user = sql_one('SELECT id,password_hash FROM admin_users WHERE username=?',[$username]);
    $valid = $user && password_verify($password, $user['password_hash']);
    if (!$valid) {
        sql_exec('INSERT INTO login_attempts (username,ip_hash) VALUES (?,?)', [substr($username,0,80),$ip]);
        return false;
    }
    sql_exec('DELETE FROM login_attempts WHERE username=? OR ip_hash=?', [$username,$ip]);
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $user['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return true;
}
function find_overlap(int $lockId, int $reservationId, string $from, string $until): array {
    return sql_all("SELECT code FROM reservations WHERE lock_id=? AND id<>? AND status NOT IN ('cancelled','checked_out') AND checkin_utc < ? AND checkout_utc > ? ORDER BY checkin_utc LIMIT 10",
        [$lockId,$reservationId,$until,$from]);
}
function reservation_upsert(array $input): int {
    $code = require_string($input,'code',80);
    $name = require_string($input,'guest_name',150);
    $receiver = require_string($input,'receiver_username',150);
    $lockId = (int) ($input['lock_id'] ?? 0);
    if ($lockId <= 0 || !sql_one('SELECT id FROM locks WHERE id=? AND status=?',[$lockId,'active'])) throw new InvalidArgumentException('Fechadura não cadastrada ou inativa.');
    $from = utc_date((string) ($input['checkin'] ?? ''));
    $until = utc_date((string) ($input['checkout'] ?? ''));
    valid_period($from, $until);
    $status = (string) ($input['status'] ?? 'confirmed');
    if (!in_array($status,['confirmed','checked_in','checked_out','cancelled'],true)) throw new InvalidArgumentException('Status de reserva inválido.');
    $notes = substr((string) ($input['notes'] ?? ''),0,2000);
    $existing = sql_one('SELECT id FROM reservations WHERE code=?',[$code]);
    if ($existing) {
        $id = (int) $existing['id'];
        $hasKey = sql_one('SELECT id FROM ekeys WHERE reservation_id=?',[$id]);
        sql_exec('UPDATE reservations SET guest_name=?,receiver_username=?,lock_id=?,checkin_utc=?,checkout_utc=?,status=?,notes=?,needs_review=? WHERE id=?',
            [$name,$receiver,$lockId,$from,$until,$status,$notes,$hasKey ? 1 : 0,$id]);
        return $id;
    }
    sql_exec('INSERT INTO reservations (code,guest_name,receiver_username,lock_id,checkin_utc,checkout_utc,status,notes) VALUES (?,?,?,?,?,?,?,?)',
        [$code,$name,$receiver,$lockId,$from,$until,$status,$notes]);
    return (int) db()->lastInsertId();
}
function is_live_allowed(array $lock): bool {
    return (bool) config('ttlock_live_enabled', false) &&
        $lock['mode'] === 'bench' &&
        (int) $lock['ttlock_id'] > 0 &&
        (int) $lock['ttlock_id'] === (int) config('ttlock_bench_lock_id',0);
}
function load_key(int $id): ?array {
    return sql_one('SELECT e.*,r.code,r.status AS reservation_status,l.label,l.ttlock_id,l.mode,l.status AS lock_status FROM ekeys e JOIN reservations r ON r.id=e.reservation_id JOIN locks l ON l.id=e.lock_id WHERE e.id=?',[$id]);
}
