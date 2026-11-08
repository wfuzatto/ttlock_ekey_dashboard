<?php
declare(strict_types=1);

final class TTLockApiException extends RuntimeException {
    public function __construct(public readonly string $errorCode = 'HTTP', string $message = 'TTLock indisponível') {
        parent::__construct($message);
    }
}

final class TTLockClient {
    private string $base;
    private string $clientId;
    private string $clientSecret;
    public function __construct() {
        $this->base = rtrim((string) config('ttlock_api_base','https://api.sciener.com'),'/');
        $this->clientId = trim((string) config('ttlock_client_id',''));
        $this->clientSecret = trim((string) config('ttlock_client_secret',''));
        $parts = parse_url($this->base);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('A API TTLock precisa usar URL HTTPS válida.');
        }
        if (!$this->clientId || !$this->clientSecret) throw new RuntimeException('Credenciais TTLock não configuradas.');
        if (!extension_loaded('curl')) throw new RuntimeException('Extensão PHP cURL indisponível.');
    }
    private function raw(string $path, array $args, string $method, ?int $lockRowId = null): array {
        $url = $this->base . $path;
        $query = http_build_query($args, '', '&', PHP_QUERY_RFC3986);
        $ch = curl_init();
        $options = [
            CURLOPT_URL => $method === 'GET' ? $url . '?' . $query : $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $query;
        }
        curl_setopt_array($ch,$options);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $failed = ($body === false) || $http < 200 || $http >= 300;
        curl_close($ch);
        $decoded = !$failed ? json_decode((string)$body,true) : null;
        $success = is_array($decoded) && (int) ($decoded['errcode'] ?? 0) === 0;
        // Nenhum corpo de resposta, token ou query string é persistido.
        sql_exec('INSERT INTO api_calls (endpoint,lock_id,result) VALUES (?,?,?)',[$path,$lockRowId,$success ? 'ok' : 'error']);
        if ($failed || !is_array($decoded)) throw new TTLockApiException('HTTP');
        if (isset($decoded['errcode']) && (int) $decoded['errcode'] !== 0) {
            throw new TTLockApiException((string) $decoded['errcode']);
        }
        return $decoded;
    }
    private function token(): string {
        $row = sql_one('SELECT access_token_enc,refresh_token_enc,expires_at FROM ttlock_tokens WHERE id=1');
        if ($row && $row['expires_at'] && $row['expires_at'] > gmdate('Y-m-d H:i:s',time()+90)) {
            return decrypt_private((string)$row['access_token_enc']);
        }
        if ($row && !empty($row['refresh_token_enc'])) {
            try {
                $refreshed = $this->raw('/oauth2/token',[
                    'client_id'=>$this->clientId,
                    'client_secret'=>$this->clientSecret,
                    'grant_type'=>'refresh_token',
                    'refresh_token'=>decrypt_private((string)$row['refresh_token_enc'])
                ],'POST');
                if (!empty($refreshed['access_token'])) return $this->saveToken($refreshed);
            } catch (TTLockApiException) {
                // OAuth refresh negado: autenticação nova, sem logar o refresh token.
            }
        }
        $username = (string) config('ttlock_admin_username','');
        $password = (string) config('ttlock_admin_password','');
        if ($username === '' || $password === '') throw new RuntimeException('Conta emissora TTLock não configurada.');
        $response = $this->raw('/oauth2/token',[
            'client_id'=>$this->clientId,
            'client_secret'=>$this->clientSecret,
            'username'=>$username,
            'password'=>md5($password),
            'grant_type'=>'password'
        ],'POST');
        if (empty($response['access_token'])) throw new TTLockApiException('OAUTH');
        return $this->saveToken($response);
    }
    private function saveToken(array $response): string {
        $ttl = max(1,(int)($response['expires_in'] ?? 3600));
        $until = gmdate('Y-m-d H:i:s',time()+$ttl);
        $access = encrypt_private((string)$response['access_token']);
        $refresh = !empty($response['refresh_token']) ? encrypt_private((string)$response['refresh_token']) : null;
        sql_exec('INSERT INTO ttlock_tokens (id,access_token_enc,refresh_token_enc,expires_at) VALUES (1,?,?,?) ON DUPLICATE KEY UPDATE access_token_enc=VALUES(access_token_enc),refresh_token_enc=COALESCE(VALUES(refresh_token_enc),refresh_token_enc),expires_at=VALUES(expires_at)',
            [$access,$refresh,$until]);
        return (string)$response['access_token'];
    }
    public function call(string $path,array $params=[],string $method='GET',?int $lockRowId=null): array {
        $params = ['clientId'=>$this->clientId,'accessToken'=>$this->token(),'date'=>ms_now()] + $params;
        return $this->raw($path,$params,$method,$lockRowId);
    }
    public function send(int $ttlockId,string $receiver,string $name,int $startMs,int $endMs,int $rowId): int {
        $response = $this->call('/v3/key/send',[
            'lockId'=>$ttlockId, 'receiverUsername'=>$receiver, 'keyName'=>$name,
            'startDate'=>$startMs, 'endDate'=>$endMs, 'remoteEnable'=>2,
            'createUser'=>2
        ],'POST',$rowId);
        if (empty($response['keyId'])) throw new TTLockApiException('MISSING_KEY_ID');
        return (int)$response['keyId'];
    }
    public function mutate(string $action,int $keyId,int $lockRowId): void {
        $paths = ['freeze'=>'/v3/key/freeze','unfreeze'=>'/v3/key/unfreeze','delete'=>'/v3/key/delete'];
        if (!isset($paths[$action])) throw new InvalidArgumentException('Ação não permitida.');
        $this->call($paths[$action], ['keyId'=>$keyId], 'POST', $lockRowId);
    }
    public function listKeys(int $ttlockId,int $rowId,int $pageNo=1): array {
        $reply = $this->call('/v3/lock/listKey',['lockId'=>$ttlockId,'pageNo'=>max(1,min(1000,$pageNo)),'pageSize'=>100],'GET',$rowId);
        $items = [];
        foreach (array_slice((array)($reply['list'] ?? []),0,100) as $entry) {
            if (!is_array($entry)) continue;
            $items[] = [
                'keyId'=>(int)($entry['keyId'] ?? 0),
                'username'=>substr((string)($entry['username'] ?? ''),0,150),
                'status'=>substr((string)($entry['keyStatus'] ?? ''),0,30),
                'startDate'=>(int)($entry['startDate'] ?? 0),
                'endDate'=>(int)($entry['endDate'] ?? 0),
            ];
        }
        return $items;
    }
    public function diagnostics(int $benchId): array {
        // Apenas leitura. Não cria, congela nem apaga eKeys.
        $response = $this->call('/v3/lock/list',['pageNo'=>1,'pageSize'=>100],'GET');
        $ids = array_map(static fn($i)=>(int)($i['lockId'] ?? 0),(array)($response['list'] ?? []));
        return ['visible'=>count($ids),'bench_found'=>in_array($benchId,$ids,true)];
    }
}
