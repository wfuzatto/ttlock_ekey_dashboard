<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

function ok(bool $condition, string $description): void {
    if (!$condition) { fwrite(STDERR, 'FALHOU: '.$description.PHP_EOL); exit(1); }
    echo 'OK: '.$description.PHP_EOL;
}
$from = utc_date('2026-11-08T15:00');
$until = utc_date('2026-11-10T11:00');
ok($from === '2026-11-08 18:00:00','conversão São Paulo → UTC');
ok($until === '2026-11-10 14:00:00','checkout em UTC');
ok(utc_millis($from) === 1794160800000,'timestamp em milissegundos');
ok(local_input($from)==='2026-11-08T15:00','ida/volta fuso local');
valid_period($from,$until);
$threw=false;
try { valid_period($until,$from); } catch (InvalidArgumentException) { $threw=true; }
ok($threw,'período invertido bloqueado');
$threw=false;
try { utc_date('2026-02-30T12:30'); } catch (InvalidArgumentException) { $threw=true; }
ok($threw,'data impossível bloqueada');
ok(h('<script>')==='&lt;script&gt;','escape HTML');
echo 'Smoke test concluído sem banco e sem chamadas TTLock.'.PHP_EOL;
