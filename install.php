<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
try {
    $installKey = (string) config('install_key','');
    if (strlen($installKey) < 20 || str_contains($installKey,'SEGREDO_')) {
        throw new RuntimeException('Configure install_key único, com pelo menos 20 caracteres.');
    }
    crypt_key(); // Verifica APP_KEY
    if (!extension_loaded('pdo_mysql') || !extension_loaded('openssl')) {
        throw new RuntimeException('Ative pdo_mysql e openssl no PHP.');
    }
    if (is_file(__DIR__ . '/storage/install.lock')) throw new RuntimeException('Instalação já concluída. Remova install.php do servidor.');
    $installed = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $provided = (string) ($_POST['install_key'] ?? '');
        if (!hash_equals($installKey,$provided)) throw new RuntimeException('Chave de instalação inválida.');
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9._-]{3,80}$/',$username) || strlen($password) < 14) {
            throw new RuntimeException('Usuário inválido ou senha com menos de 14 caracteres.');
        }
        $pdo = db();
        $schema = file_get_contents(__DIR__ . '/schema.sql');
        if ($schema === false) throw new RuntimeException('schema.sql não encontrado.');
        foreach (preg_split('/;\s*(?=\r?\n|$)/',$schema) as $statement) {
            if (trim($statement) !== '') $pdo->exec($statement);
        }
        $count = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
        if ($count !== 0) throw new RuntimeException('Já existe administrador; instalador bloqueado.');
        sql_exec('INSERT INTO admin_users (username,password_hash) VALUES (?,?)',[$username,password_hash($password,PASSWORD_DEFAULT)]);
        if (file_put_contents(__DIR__ . '/storage/install.lock',gmdate('c'),LOCK_EX) === false) {
            throw new RuntimeException('Administrador criado, mas storage/ não tem permissão de escrita. Bloqueie/remova install.php manualmente.');
        }
        $installed = true;
    }
} catch (Throwable $error) {
    $problem = $error instanceof PDOException ? 'Não foi possível conectar ao banco. Confira dados do MySQL e permissões.' : $error->getMessage();
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalação • TTLock eKeys</title><link rel="stylesheet" href="assets/style.css"></head>
<body><main class="login-shell"><section class="login-card">
<h1>Instalação inicial</h1>
<p>Execute uma única vez, em HTTPS, após configurar o banco MySQL/MariaDB e o segredo de instalação.</p>
<?php if (isset($problem)): ?><div class="alert alert-error"><?=h($problem)?></div><?php endif; ?>
<?php if (!empty($installed)): ?><div class="alert alert-ok">Instalação concluída. Exclua <code>install.php</code> da hospedagem.</div><a class="btn" href="index.php">Entrar no painel</a>
<?php elseif (!isset($problem)): ?><form method="post" autocomplete="off">
<label>Chave de instalação<input name="install_key" type="password" required></label>
<label>Usuário administrador<input name="username" minlength="3" maxlength="80" required></label>
<label>Senha forte (mínimo 14 caracteres)<input name="password" type="password" minlength="14" required autocomplete="new-password"></label>
<button class="btn primary" type="submit">Criar banco e administrador</button>
</form><?php endif; ?>
</section></main></body></html>
