<?php
// COPIE para config.local.php (ignorado pelo Git) OU, preferencialmente,
// salve como ttlock_ekey_config.php acima de public_html.
// Substitua TODOS os placeholders. Nunca compartilhe este arquivo.
return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'SEU_BANCO_LOCAWEB',
    'db_user' => 'SEU_USUARIO',
    'db_password' => 'SUA_SENHA',
    // Gere 32 bytes aleatórios: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    'app_key_hex' => 'COLOQUE_64_CARACTERES_HEXADECIMAIS',
    'install_key' => 'SEGREDO_UNICO_PARA_INSTALACAO',
    'ttlock_client_id' => '',
    'ttlock_client_secret' => '',
    'ttlock_admin_username' => '',
    'ttlock_admin_password' => '', // Conta TTLock ADMINISTRADORA; nunca o desenvolvedor
    'ttlock_api_base' => 'https://api.sciener.com',
    'ttlock_live_enabled' => false,
    'ttlock_bench_lock_id' => 0, // obrigatório para qualquer escrita real
    'hfvm_signing_key' => '', // Segredo compartilhado SOMENTE entre backends
    'webhook_secret' => '', // HMAC independente do HFVM
];
