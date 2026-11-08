<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && ($_GET['route'] ?? '') !== 'health') {
    json_response(['error'=>'method_not_allowed'],405);
}
$route = (string) ($_GET['route'] ?? '');
if ($route === 'health') {
    json_response(['status'=>'ok','service'=>'ttlock-ekey-dashboard','mode'=>'safe']);
}
try {
    if ($route === 'reservation') {
        $secret = (string) config('webhook_secret','');
        if (strlen($secret) < 32) json_response(['error'=>'webhook_not_configured'],503);
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if ($raw === false || strlen($raw) > 65536) json_response(['error'=>'payload_too_large'],413);
        $timestamp = (string) ($_SERVER['HTTP_X_HFVM_TIMESTAMP'] ?? '');
        $signature = (string) ($_SERVER['HTTP_X_HFVM_SIGNATURE'] ?? '');
        if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300 ||
            !preg_match('/^[a-f0-9]{64}$/i',$signature) ||
            !hash_equals(hash_hmac('sha256',$timestamp . '.' . $raw,$secret),strtolower($signature))) {
            json_response(['error'=>'invalid_signature'],401);
        }
        $data = json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($data)) json_response(['error'=>'invalid_body'],422);
        $event = require_string($data,'event_id',120);
        $room = require_string($data,'room',60);
        $matches = sql_all('SELECT id FROM locks WHERE room=? AND status=?',[$room,'active']);
        if (count($matches) !== 1) json_response(['error'=>'room_not_uniquely_mapped'],422);
        $mapped = [
            'code'=>($data['code'] ?? ''),
            'guest_name'=>($data['guest_name'] ?? ''),
            'receiver_username'=>($data['receiver_username'] ?? ''),
            'lock_id'=>$matches[0]['id'],
            'checkin'=>($data['checkin'] ?? ''),
            'checkout'=>($data['checkout'] ?? ''),
            'status'=>($data['status'] ?? 'confirmed'),
            'notes'=>($data['notes'] ?? '')
        ];
        db()->beginTransaction();
        try {
            $affected = sql_exec('INSERT IGNORE INTO webhook_events (event_id) VALUES (?)',[$event]);
            if ($affected === 0) {
                db()->commit();
                json_response(['ok'=>true,'duplicate'=>true]);
            }
            $reservationId = reservation_upsert($mapped);
            audit_event('webhook.reservation','ok',$reservationId,(int)$matches[0]['id'],null,'Evento autenticado; nenhuma eKey emitida automaticamente');
            db()->commit();
            json_response(['ok'=>true,'duplicate'=>false,'reservation_id'=>$reservationId]);
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            throw $error;
        }
    }

    if ($route === 'guest-key') {
        $secret = (string) config('hfvm_signing_key','');
        if (strlen($secret) < 32) json_response(['error'=>'hfvm_integration_not_configured'],503);
        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer ([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/i',$authorization,$m)) json_response(['error'=>'unauthorized'],401);
        $payload = $m[1]; $provided = strtolower($m[2]);
        if (!hash_equals(hash_hmac('sha256',$payload,$secret),$provided)) json_response(['error'=>'unauthorized'],401);
        $decoded = base64_decode(strtr($payload,'-_','+/') . str_repeat('=',(4 - strlen($payload) % 4) % 4),true);
        if ($decoded === false || strlen($decoded) > 2048) json_response(['error'=>'unauthorized'],401);
        $claims = json_decode($decoded,true,8,JSON_THROW_ON_ERROR);
        if (!is_array($claims)) json_response(['error'=>'unauthorized'],401);
        $exp = (int) ($claims['exp'] ?? 0);
        $iat = (int) ($claims['iat'] ?? 0);
        $nonce = (string) ($claims['nonce'] ?? '');
        $code = (string) ($claims['reservation_code'] ?? '');
        if ($iat > time()+30 || $iat < time()-300 || $exp <= time() || $exp > $iat+300 ||
            !preg_match('/^[a-zA-Z0-9_-]{16,120}$/',$nonce) || $code === '' || strlen($code)>80) {
            json_response(['error'=>'invalid_claims'],401);
        }
        try {
            sql_exec('INSERT INTO hfvm_nonces (nonce_hash,expires_at) VALUES (?,?)',[
                hash('sha256',$nonce),gmdate('Y-m-d H:i:s',$exp)
            ]);
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') json_response(['error'=>'replay'],409);
            throw $error;
        }
        $reservation = sql_one('SELECT r.*,e.state AS key_state,e.simulated,e.valid_from_utc,e.valid_until_utc,e.sync_state FROM reservations r LEFT JOIN ekeys e ON e.reservation_id=r.id WHERE r.code=?',[$code]);
        if (!$reservation || in_array($reservation['status'],['cancelled','checked_out'],true)) json_response(['error'=>'not_authorized_reservation'],403);
        if (empty($reservation['key_state'])) json_response(['error'=>'ekey_not_issued'],404);
        // Intencional: NÃO devolver a eKey da conta administradora; /key/get com token
        // da conta emissora não corresponde à credencial pessoal do destinatário.
        json_response([
            'error'=>'guest_credential_delivery_not_validated',
            'credential_available'=>false,
            'reservation_code'=>$reservation['code'],
            'key_state'=>$reservation['key_state'],
            'simulated'=>(bool)$reservation['simulated'],
            'valid_from'=>local_date($reservation['valid_from_utc']),
            'valid_until'=>local_date($reservation['valid_until_utc']),
            'sync_state'=>$reservation['sync_state'],
            'action'=>'Validar obtenção da eKey da conta receptora via SDK TTLock no app HFVM.'
        ],409);
    }
    json_response(['error'=>'not_found'],404);
} catch (JsonException|InvalidArgumentException $error) {
    json_response(['error'=>'invalid_payload'],422);
} catch (Throwable $error) {
    error_log('ttlock_api_error:' . get_class($error)); // NUNCA registra payload, token ou credencial
    json_response(['error'=>'request_failed'],500);
}
