<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

function go(string $page='overview'): never {
    header('Location: index.php?page=' . rawurlencode($page)); exit;
}
function field_csrf(): void { echo '<input type="hidden" name="csrf" value="'.h(csrf_token()).'">'; }
function select_status(string $chosen='confirmed'): string {
    $out = '';
    foreach (['confirmed'=>'Confirmada','checked_in'=>'Hospedado','checked_out'=>'Checkout','cancelled'=>'Cancelada'] as $key=>$label) {
        $out .= '<option value="'.h($key).'"'.($key===$chosen?' selected':'').'>'.h($label).'</option>';
    }
    return $out;
}
function list_locks(): array {
    return sql_all('SELECT * FROM locks ORDER BY room,label');
}
function csv_cell(mixed $value): string {
    $text = (string) $value;
    return preg_match('/^[=+@\-\t\r]/u',$text) ? "'".$text : $text;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'login' && !admin_id()) {
            if (do_login((string)($_POST['username']??''),(string)($_POST['password']??''))) {
                flash('ok','Sessão iniciada com sucesso.'); go();
            }
            flash('error','Usuário ou senha inválidos.'); go('login');
        }
        require_admin();
        if ($action === 'logout') {
            $_SESSION=[]; session_regenerate_id(true); session_destroy();
            header('Location: index.php'); exit;
        }
        if ($action === 'add_lock' || $action === 'edit_lock') {
            $label = require_string($_POST,'label',120);
            $room = require_string($_POST,'room',60);
            $ttid = trim((string)($_POST['ttlock_id']??''));
            if ($ttid!=='' && (!ctype_digit($ttid) || (int)$ttid<=0)) throw new InvalidArgumentException('lockId deve ser um número positivo.');
            $mode = (string)($_POST['mode']??'simulation');
            $status = (string)($_POST['status']??'active');
            if (!in_array($mode,['simulation','bench','production'],true) || !in_array($status,['active','inactive'],true)) throw new InvalidArgumentException('Modo/status inválido.');
            $mac = substr(trim((string)($_POST['mac']??'')),0,32);
            if ($action==='edit_lock') {
                $id=(int)($_POST['id']??0);
                sql_exec('UPDATE locks SET label=?,room=?,ttlock_id=?,mac=?,mode=?,status=? WHERE id=?',
                    [$label,$room,$ttid!==''?(int)$ttid:null,$mac,$mode,$status,$id]);
                audit_event('lock.update','ok',null,$id);
            } else {
                sql_exec('INSERT INTO locks (label,room,ttlock_id,mac,mode,status) VALUES (?,?,?,?,?,?)',
                    [$label,$room,$ttid!==''?(int)$ttid:null,$mac,$mode,$status]);
                $id=(int)db()->lastInsertId();
                audit_event('lock.create','ok',null,$id);
            }
            flash('ok','Fechadura salva. A opção de produção permanece bloqueada para comandos reais.'); go('locks');
        }
        if ($action === 'save_reservation') {
            db()->beginTransaction();
            $id=reservation_upsert($_POST);
            audit_event('reservation.save','ok',$id,(int)$_POST['lock_id']);
            db()->commit();
            flash('ok','Reserva salva. Alterações em reserva já emitida exigem revisão manual.'); go('reservations');
        }
        if ($action === 'csv_reservations') {
            if (!isset($_FILES['csv']) || $_FILES['csv']['error']!==UPLOAD_ERR_OK ||
                $_FILES['csv']['size']>1048576) throw new InvalidArgumentException('Envie CSV de até 1 MB.');
            $fp=fopen($_FILES['csv']['tmp_name'],'rb');
            if (!$fp) throw new InvalidArgumentException('CSV não pôde ser lido.');
            $first=fgets($fp);
            if ($first===false) throw new InvalidArgumentException('CSV vazio.');
            $delimiter=substr_count($first,';')>=substr_count($first,',')?';':',';
            rewind($fp);
            $headers=fgetcsv($fp,0,$delimiter);
            if (!$headers) throw new InvalidArgumentException('Cabeçalho ausente.');
            $headers=array_map(static fn($x)=>trim(str_replace("\xEF\xBB\xBF",'',(string)$x)),$headers);
            $required=['code','guest_name','receiver_username','room','checkin','checkout','status'];
            if (array_diff($required,$headers)) throw new InvalidArgumentException('Cabeçalho: '.implode(';',$required).';notes (opcional).');
            $rooms=sql_all('SELECT id,room FROM locks WHERE status=?',['active']);
            $map=[]; foreach ($rooms as $l) $map[$l['room']][]=(int)$l['id'];
            $count=0;
            db()->beginTransaction();
            while (($row=fgetcsv($fp,0,$delimiter))!==false) {
                if (count($row)===1 && trim((string)$row[0])==='') continue;
                if (++$count>500 || count($row)!==count($headers)) throw new InvalidArgumentException('CSV excedeu 500 linhas ou possui colunas incorretas.');
                $data=array_combine($headers,$row);
                if (count($map[trim((string)$data['room'])]??[])!==1) throw new InvalidArgumentException('Quarto não mapeado unicamente: linha '.($count+1));
                $data['lock_id']=$map[trim((string)$data['room'])][0];
                $id=reservation_upsert($data);
                audit_event('reservation.csv','ok',$id,(int)$data['lock_id']);
            }
            fclose($fp);
            db()->commit();
            flash('ok',$count.' reserva(s) importadas/atualizadas. Nenhuma eKey foi enviada.'); go('reservations');
        }
        if ($action === 'issue_key') {
            $resId=(int)($_POST['reservation_id']??0);
            $real=(string)($_POST['execution']??'simulate')==='real';
            $existing=sql_one('SELECT id FROM ekeys WHERE reservation_id=?',[$resId]);
            if ($existing) throw new InvalidArgumentException('Já existe emissão (ou tentativa incerta) para esta reserva. Revise o histórico.');
            db()->beginTransaction();
            $row=sql_one('SELECT r.*,l.ttlock_id,l.mode,l.status AS lock_status FROM reservations r JOIN locks l ON l.id=r.lock_id WHERE r.id=? FOR UPDATE',[$resId]);
            if (!$row || !in_array($row['status'],['confirmed','checked_in'],true) || $row['lock_status']!=='active') throw new InvalidArgumentException('Reserva/fechadura indisponível para emissão.');
            if ($row['checkout_utc']<=utc_now()) throw new InvalidArgumentException('Reserva já está expirada.');
            if (strcasecmp(trim((string)config('ttlock_admin_username','')),$row['receiver_username'])===0) throw new InvalidArgumentException('A conta emissora não pode ser destinatária.');
            $duplicates=sql_all("SELECT e.id FROM ekeys e WHERE e.lock_id=? AND e.receiver_username=? AND e.state IN ('issued','frozen')",
                [(int)$row['lock_id'],$row['receiver_username']]);
            if ($duplicates) throw new InvalidArgumentException('Este destinatário já possui eKey vinculada à fechadura. Novo envio substituiria a anterior.');
            $overlaps=find_overlap((int)$row['lock_id'],$resId,$row['checkin_utc'],$row['checkout_utc']);
            if ($overlaps && (string)($_POST['overlap_confirmation']??'')!=='SOBREPOR') {
                throw new InvalidArgumentException('Conflito com reserva(s): '.implode(', ',array_column($overlaps,'code')).'. Digite SOBREPOR para autorizar exceção.');
            }
            if ($real) {
                if (!is_live_allowed($row) || (string)($_POST['confirmation']??'')!=='EMITIR') {
                    throw new InvalidArgumentException('Envio real restrito a lockId de bancada configurado; digite EMITIR.');
                }
                $keyId=(new TTLockClient())->send((int)$row['ttlock_id'],$row['receiver_username'],
                    'Reserva '.$row['code'],utc_millis($row['checkin_utc']),utc_millis($row['checkout_utc']),(int)$row['lock_id']);
            } else {
                $keyId=null;
            }
            sql_exec('INSERT INTO ekeys (reservation_id,lock_id,key_id,receiver_username,valid_from_utc,valid_until_utc,state,simulated,sync_state) VALUES (?,?,?,?,?,?,?,?,?)',
                [$resId,$row['lock_id'],$keyId,$row['receiver_username'],$row['checkin_utc'],$row['checkout_utc'],'issued',$real?0:1,'pending']);
            if ($overlaps) audit_event('key.overlap_override','ok',$resId,(int)$row['lock_id'],$keyId,'Sobreposição autorizada explicitamente.');
            audit_event('key.issue',$real?'real':'simulated',$resId,(int)$row['lock_id'],$keyId);
            db()->commit();
            flash('ok',$real?'eKey enviada à TTLock. Valide recebimento/abertura na bancada.':'eKey SIMULADA criada, sem comunicação TTLock.');
            go('keys');
        }
        if ($action === 'key_mutate') {
            $id=(int)($_POST['key_row_id']??0);
            $verb=(string)($_POST['verb']??'');
            if (!in_array($verb,['freeze','unfreeze','delete'],true) || (string)($_POST['confirmation']??'')!=='CONFIRMAR') {
                throw new InvalidArgumentException('Digite CONFIRMAR para autorizar a operação.');
            }
            db()->beginTransaction();
            $key=load_key($id);
            if (!$key || $key['state']==='deleted' || $key['state']==='failed') throw new InvalidArgumentException('eKey inexistente ou não operável.');
            if (($verb==='freeze' && $key['state']!=='issued') || ($verb==='unfreeze' && $key['state']!=='frozen')) throw new InvalidArgumentException('Transição de estado não permitida.');
            if (!(bool)$key['simulated']) {
                if (!is_live_allowed($key) || !$key['key_id']) throw new InvalidArgumentException('Ação real só é permitida na fechadura de bancada.');
                (new TTLockClient())->mutate($verb,(int)$key['key_id'],(int)$key['lock_id']);
            }
            $next=['freeze'=>'frozen','unfreeze'=>'issued','delete'=>'deleted'][$verb];
            sql_exec('UPDATE ekeys SET state=?,sync_state=? WHERE id=?',[$next,'pending',$id]);
            if ($verb==='delete' || $verb==='freeze') sql_exec('UPDATE reservations SET needs_review=1 WHERE id=?',[(int)$key['reservation_id']]);
            audit_event('key.'.$verb,$key['simulated']?'simulated':'cloud_accepted',(int)$key['reservation_id'],(int)$key['lock_id'],$key['key_id']?(int)$key['key_id']:null,'Sincronização física não confirmada.');
            db()->commit();
            flash('ok','Operação registrada. Se a fechadura estiver offline, a revogação física pode estar pendente.');
            go('keys');
        }
        if ($action === 'key_consult') {
            $id=(int)($_POST['key_row_id']??0);
            $key=load_key($id);
            if (!$key) throw new InvalidArgumentException('eKey não encontrada.');
            if ((bool)$key['simulated']) {
                audit_event('key.consult','simulated',(int)$key['reservation_id'],(int)$key['lock_id']);
                flash('ok','Consulta simulada: estado local '.$key['state'].'.');
            } else {
                if (!is_live_allowed($key)) throw new InvalidArgumentException('Consulta real bloqueada fora da bancada.');
                $items=(new TTLockClient())->listKeys((int)$key['ttlock_id'],(int)$key['lock_id']);
                $found=false;
                foreach ($items as $item) if ($item['keyId']===(int)$key['key_id']) $found=true;
                audit_event('key.consult','read_only',(int)$key['reservation_id'],(int)$key['lock_id'],(int)$key['key_id'],'Conferência na lista de eKeys comuns.');
                flash($found?'ok':'error',$found?'keyId encontrado na nuvem; isso NÃO confirma sincronização Bluetooth.':'keyId não apareceu na primeira página (100 itens) da nuvem.');
            }
            go('keys');
        }
        if ($action === 'lock_list') {
            $id=(int)($_POST['lock_id']??0);
            $lock=sql_one('SELECT * FROM locks WHERE id=?',[$id]);
            if (!$lock) throw new InvalidArgumentException('Fechadura não encontrada.');
            if (is_live_allowed($lock) && (string)($_POST['execution']??'simulate')==='real') {
                $items=(new TTLockClient())->listKeys((int)$lock['ttlock_id'],$id);
                $rows=[];
                foreach ($items as $item) $rows[]=[
                    'key_id'=>$item['keyId'], 'receiver'=>$item['username'], 'state'=>$item['status'],
                    'period'=>($item['startDate']?gmdate('d/m/Y',$item['startDate']/1000):'—')
                ];
                audit_event('lock.list_keys','read_only',null,$id,null,'Primeira página de eKeys comuns.');
                $_SESSION['listed_keys']=$rows;
                flash('ok','Lista TTLock: '.count($rows).' item(ns) na primeira página; valores sensíveis omitidos.');
            } else {
                $_SESSION['listed_keys']=sql_all('SELECT key_id,receiver_username AS receiver,state,valid_from_utc AS period FROM ekeys WHERE lock_id=? ORDER BY id DESC LIMIT 100',[$id]);
                flash('ok','Lista local (simulada/sem acesso remoto).');
            }
            go('keys');
        }
        if ($action === 'diagnose') {
            $report=[
                'Banco MySQL'=>'Conexão OK',
                'Modo de execução'=>config('ttlock_live_enabled',false)?'Escrita real restrita à bancada':'SIMULAÇÃO (padrão)',
                'API TTLock'=>'Host: '.(string)config('ttlock_api_base','https://api.sciener.com'),
                'Conta e credenciais'=>config('ttlock_client_id')&&config('ttlock_admin_username')?'Campos presentes (não validados)':'Ausentes',
                'Chave de criptografia'=>'Configurada',
                'HFVM'=>'Entrega de credencial receptora BLOQUEADA até validação do SDK',
            ];
            crypt_key();
            if ((string)($_POST['remote_read']??'')==='yes') {
                $bench=(int)config('ttlock_bench_lock_id',0);
                if ($bench<=0) throw new InvalidArgumentException('Configure lockId da bancada antes do diagnóstico remoto.');
                $data=(new TTLockClient())->diagnostics($bench);
                $report['Acesso TTLock'=>'Autenticado: '.(int)$data['visible'].' fechaduras na primeira página; bancada '.($data['bench_found']?'encontrada':'NÃO encontrada');
                audit_event('integration.diagnose','read_only',null,null,null,'Leitura sem escrita.');
            }
            $_SESSION['diagnostics']=$report;
            flash('ok','Diagnóstico executado.'); go('diagnostics');
        }
        if ($action === 'settings_save') {
            $quota=filter_var($_POST['monthly_quota']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>100,'max_range'=>100000000]]);
            if (!$quota) throw new InvalidArgumentException('Cota mensal inválida.');
            sql_exec("INSERT INTO settings (name,value) VALUES ('monthly_quota',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[(string)$quota]);
            audit_event('settings.quota','ok',null,null,null,'Cota modificada');
            flash('ok','Cota mensal atualizada.'); go('settings');
        }
        throw new InvalidArgumentException('Ação desconhecida.');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        if (($action ?? '')==='issue_key' && !empty($real) && !empty($resId) && !empty($row)) {
            // Resposta incerta de envio não é reexecutada automaticamente.
            try {
                sql_exec('INSERT IGNORE INTO ekeys (reservation_id,lock_id,receiver_username,valid_from_utc,valid_until_utc,state,simulated,sync_state,last_error_code) VALUES (?,?,?,?,?,?,?,?,?)',
                    [$resId,$row['lock_id'],$row['receiver_username'],$row['checkin_utc'],$row['checkout_utc'],'failed',0,'unknown',$e instanceof TTLockApiException?$e->errorCode:'UNKNOWN']);
                sql_exec('UPDATE reservations SET needs_review=1 WHERE id=?',[$resId]);
                audit_event('key.issue','needs_review',$resId,(int)$row['lock_id'],null,'Resultado de envio real incerto; revisar antes de reenviar.');
            } catch (Throwable) {}
        }
        flash('error',$e instanceof InvalidArgumentException || $e instanceof RuntimeException && !($e instanceof TTLockApiException) && !($e instanceof PDOException) ? $e->getMessage() : safe_error($e));
        go(admin_id() ? (string)($_POST['back']??'overview') : 'login');
    }
}

if (!admin_id()) {
    ?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login • TTLock eKeys</title><link rel="stylesheet" href="assets/style.css"></head><body>
<main class="login-shell"><section class="login-card">
<div class="brand-symbol">T</div><p class="eyebrow">HOTEL • ACESSO SEGURO</p><h1>TTLock eKeys</h1><p>Entre para administrar reservas e chaves temporárias.</p>
<?php foreach ($_SESSION['flash']??[] as $f): ?><div class="alert alert-<?=h($f[0])?>"><?=h($f[1])?></div><?php endforeach; unset($_SESSION['flash']); ?>
<form method="post" autocomplete="off"><?php field_csrf(); ?><input type="hidden" name="action" value="login">
<label>Usuário<input name="username" autocomplete="username" required autofocus></label>
<label>Senha<input type="password" name="password" autocomplete="current-password" required></label>
<button class="btn primary full" type="submit">Entrar no dashboard</button></form>
<p class="footnote">Primeiro acesso? Execute o instalador descrito no README.</p>
</section></main></body></html>
<?php exit;
}

if (($_GET['export']??'')==='csv') {
    $records=sql_all('SELECT r.code,r.guest_name,r.receiver_username,l.room,r.checkin_utc,r.checkout_utc,r.status,e.key_id,e.state AS key_state,e.simulated,e.sync_state FROM reservations r JOIN locks l ON l.id=r.lock_id LEFT JOIN ekeys e ON e.reservation_id=r.id ORDER BY r.id DESC LIMIT 10000');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ttlock-ekeys-'.gmdate('Ymd').'.csv"');
    echo "\xEF\xBB\xBF";
    $fp=fopen('php://output','wb');
    fputcsv($fp,['reserva','hospede','destinatario','quarto','checkin_utc','checkout_utc','status_reserva','keyId','status_ekey','simulada','sync'], ';');
    foreach ($records as $record) fputcsv($fp,array_map('csv_cell',array_values($record)),';');
    fclose($fp); exit;
}
$page=(string)($_GET['page']??'overview');
$pages=['overview'=>'Visão geral','locks'=>'Fechaduras','reservations'=>'Reservas','keys'=>'eKeys','audit'=>'Auditoria','diagnostics'=>'Diagnóstico','settings'=>'Configurações'];
if (!isset($pages[$page])) $page='overview';
$quota=quota_data();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($pages[$page])?> • TTLock eKeys</title><link rel="stylesheet" href="assets/style.css"></head><body>
<div class="layout"><aside class="sidebar">
<div class="brand"><span class="brand-symbol">T</span><span><strong>TTLock eKeys</strong><small>HOTEL • PAINEL DE ACESSOS</small></span></div>
<nav aria-label="Menu principal">
<?php foreach ($pages as $slug=>$label): ?><a class="<?=($page===$slug?'active':'')?>" href="index.php?page=<?=h($slug)?>"><?=h($label)?></a><?php endforeach; ?>
</nav>
<div class="sidebar-bottom"><span class="pill">● SIMULAÇÃO PADRÃO</span>
<p>Horário: America/Sao_Paulo</p><form method="post"><?php field_csrf(); ?><input type="hidden" name="action" value="logout"><button class="btn subtle" type="submit">Sair da conta</button></form></div></aside>
<main class="content"><header class="topbar"><div><p class="eyebrow">GESTÃO DE HOSPEDAGEM E ACESSOS</p><h1><?=h($pages[$page])?></h1></div>
<div class="topbar-meta"><span class="pill <?=config('ttlock_live_enabled')?'warning':''?>"><?=config('ttlock_live_enabled')?'BANCADA HABILITADA':'MODO SEGURO'?></span><span><?=h(date('d/m/Y H:i'))?></span></div></header>
<?php foreach ($_SESSION['flash']??[] as $f): ?><div class="alert alert-<?=h($f[0])?>"><?=h($f[1])?></div><?php endforeach; unset($_SESSION['flash']); ?>
<div class="warning-panel"><strong>Bluetooth offline:</strong> congelar ou apagar na nuvem não garante revogação imediata na fechadura. Para checkout antecipado, sincronize junto à fechadura via SDK Bluetooth ou gateway compatível e valide o estado.</div>

<?php if ($page==='overview'):
    $upcoming=(int)(sql_one("SELECT COUNT(*) AS n FROM reservations WHERE checkin_utc BETWEEN UTC_TIMESTAMP() AND DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 DAY) AND status='confirmed'")['n']??0);
    $active=(int)(sql_one("SELECT COUNT(*) AS n FROM ekeys WHERE state='issued' AND valid_from_utc<=UTC_TIMESTAMP() AND valid_until_utc>UTC_TIMESTAMP()")['n']??0);
    $pending=(int)(sql_one("SELECT COUNT(*) AS n FROM reservations r LEFT JOIN ekeys e ON e.reservation_id=r.id WHERE e.id IS NULL AND r.status IN ('confirmed','checked_in')")['n']??0);
    $expired=(int)(sql_one("SELECT COUNT(*) AS n FROM ekeys WHERE valid_until_utc<=UTC_TIMESTAMP()")['n']??0);
    $revocations=(int)(sql_one("SELECT COUNT(*) AS n FROM ekeys WHERE state IN ('frozen','deleted') AND sync_state<>'confirmed'")['n']??0);
    $recent=sql_all('SELECT r.code,r.guest_name,l.room,r.checkin_utc,r.checkout_utc,r.status,e.state AS key_state,e.simulated FROM reservations r JOIN locks l ON l.id=r.lock_id LEFT JOIN ekeys e ON e.reservation_id=r.id ORDER BY r.created_at DESC LIMIT 10');
?>
<div class="metric-grid">
<?php foreach ([['Reservas próximas',$upcoming],['eKeys válidas agora',$active],['Aguardando emissão',$pending],['eKeys expiradas',$expired],['Revogação pendente',$revocations],['Chamadas API no mês',$quota['used']]] as $m): ?>
<div class="metric"><span><?=h($m[0])?></span><strong><?=h($m[1])?></strong></div><?php endforeach; ?></div>
<div class="panel"><div class="panel-head"><div><h2>Consumo TTLock</h2><p>Cota configurável, contabilizada por tentativa de chamada registrada.</p></div><strong><?=h($quota['used'])?> / <?=h($quota['max'])?></strong></div>
<progress max="100" value="<?=h(min(100,$quota['pct']))?>"></progress><p class="muted"><?=h($quota['pct'])?>% do limite mensal.
<?php if ($quota['pct']>=100): ?>Limite de referência atingido.
<?php elseif ($quota['pct']>=90): ?>Alerta de 90%.
<?php elseif ($quota['pct']>=80): ?>Alerta de 80%.<?php endif; ?></p></div>
<div class="panel"><div class="panel-head"><h2>Últimas reservas</h2><a class="btn subtle" href="index.php?page=reservations">Ver reservas</a></div>
<div class="table-wrap"><table><thead><tr><th>Reserva</th><th>Hóspede</th><th>Quarto</th><th>Check-in</th><th>Checkout</th><th>eKey</th></tr></thead><tbody>
<?php foreach ($recent as $r): ?><tr><td><?=h($r['code'])?></td><td><?=h($r['guest_name'])?></td><td><?=h($r['room'])?></td><td><?=h(local_date($r['checkin_utc']))?></td><td><?=h(local_date($r['checkout_utc']))?></td><td><?=h($r['key_state']??'pendente')?><?=isset($r['simulated'])&&$r['simulated']?' · sim.':''?></td></tr><?php endforeach; ?>
<?php if (!$recent): ?><tr><td colspan="6" class="empty">Cadastre sua primeira fechadura e reserva para começar.</td></tr><?php endif; ?></tbody></table></div></div>

<?php elseif ($page==='locks'): $locks=list_locks(); ?>
<div class="panel"><div class="panel-head"><div><h2>Fechaduras e quartos</h2><p>O modo produção é apenas classificatório. Escritas reais exigem bancada explicitamente autorizada.</p></div></div>
<div class="table-wrap"><table><thead><tr><th>Quarto</th><th>Nome</th><th>lockId</th><th>Modo</th><th>Status</th><th>Alterar cadastro</th></tr></thead><tbody>
<?php foreach ($locks as $l): ?><tr><td><?=h($l['room'])?></td><td><?=h($l['label'])?></td><td><?=h($l['ttlock_id']??'—')?></td><td><span class="tag"><?=h($l['mode'])?></span></td><td><?=h($l['status'])?></td><td><details><summary>Editar / listar</summary>
<form method="post" class="stack"><?php field_csrf(); ?><input type="hidden" name="action" value="edit_lock"><input type="hidden" name="id" value="<?=h($l['id'])?>"><input type="hidden" name="back" value="locks">
<label>Nome<input name="label" value="<?=h($l['label'])?>" required></label><label>Quarto<input name="room" value="<?=h($l['room'])?>" required></label><label>lockId<input name="ttlock_id" inputmode="numeric" value="<?=h($l['ttlock_id'])?>"></label><label>MAC<input name="mac" value="<?=h($l['mac'])?>"></label>
<label>Modo<select name="mode"><?php foreach (['simulation','bench','production'] as $m): ?><option value="<?=h($m)?>" <?=$m===$l['mode']?'selected':''?>><?=h($m)?></option><?php endforeach; ?></select></label><label>Status<select name="status"><?php foreach (['active','inactive'] as $m): ?><option value="<?=h($m)?>" <?=$m===$l['status']?'selected':''?>><?=h($m)?></option><?php endforeach; ?></select></label><button class="btn" type="submit">Salvar alterações</button></form>
<form method="post" class="stack"><?php field_csrf(); ?><input type="hidden" name="action" value="lock_list"><input type="hidden" name="lock_id" value="<?=h($l['id'])?>"><input type="hidden" name="back" value="locks">
<label>Listagem<select name="execution"><option value="simulate">Base local</option><option value="real">Nuvem TTLock (somente bancada autorizada)</option></select></label><button class="btn subtle" type="submit">Listar eKeys</button></form></details></td></tr><?php endforeach; ?>
<?php if (!$locks): ?><tr><td colspan="6" class="empty">Nenhuma fechadura cadastrada.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="panel"><h2>Adicionar fechadura</h2><form method="post" class="form-grid"><?php field_csrf(); ?><input type="hidden" name="action" value="add_lock"><input type="hidden" name="back" value="locks">
<label>Nome da fechadura<input name="label" placeholder="Saga 203" required></label><label>Quarto<input name="room" placeholder="203" required></label><label>lockId TTLock<input name="ttlock_id" inputmode="numeric" placeholder="Opcional na simulação"></label><label>MAC Bluetooth<input name="mac" placeholder="Opcional"></label>
<label>Modo<select name="mode"><option value="simulation">Simulação</option><option value="bench">Bancada</option><option value="production">Produção (real desabilitado)</option></select></label><label>Status<select name="status"><option value="active">Ativa</option><option value="inactive">Inativa</option></select></label><button class="btn primary" type="submit">Salvar fechadura</button></form></div>

<?php elseif ($page==='reservations'):
    $locks=list_locks();
    $reservations=sql_all('SELECT r.*,l.room,e.state AS key_state FROM reservations r JOIN locks l ON l.id=r.lock_id LEFT JOIN ekeys e ON e.reservation_id=r.id ORDER BY r.id DESC LIMIT 200');
?>
<div class="panel"><h2>Cadastrar ou atualizar reserva</h2><p>Para atualizar, preencha novamente o mesmo código. Se houver eKey, o painel sinaliza revisão necessária; não altera credenciais automaticamente.</p>
<form method="post" class="form-grid"><?php field_csrf(); ?><input type="hidden" name="action" value="save_reservation"><input type="hidden" name="back" value="reservations">
<label>Código da reserva<input name="code" maxlength="80" required></label><label>Hóspede<input name="guest_name" maxlength="150" required></label>
<label>Username TTLock do destinatário<input name="receiver_username" maxlength="150" placeholder="Conta existente do hóspede" required></label>
<label>Quarto / fechadura<select name="lock_id" required><option value="">Selecione</option><?php foreach ($locks as $l): if ($l['status']!=='active') continue; ?><option value="<?=h($l['id'])?>"><?=h($l['room'].' — '.$l['label'])?></option><?php endforeach; ?></select></label>
<label>Check-in (hora local)<input type="datetime-local" name="checkin" required></label><label>Checkout (hora local)<input type="datetime-local" name="checkout" required></label>
<label>Status<select name="status"><?=select_status()?></select></label><label>Observações<input name="notes" maxlength="2000"></label><button class="btn primary" type="submit">Salvar reserva</button></form></div>
<div class="panel"><h2>Importação CSV</h2><p>Até 500 linhas por arquivo (máximo 1 MB). Cabeçalho: <code>code;guest_name;receiver_username;room;checkin;checkout;status;notes</code>. Datas: <code>2026-11-08T15:00</code>, fuso de São Paulo, ou ISO 8601 com offset.</p>
<form method="post" enctype="multipart/form-data" class="inline-form"><?php field_csrf(); ?><input type="hidden" name="action" value="csv_reservations"><input type="hidden" name="back" value="reservations"><input name="csv" type="file" accept=".csv,text/csv" required><button class="btn" type="submit">Importar CSV</button></form></div>
<div class="panel"><div class="panel-head"><h2>Reservas cadastradas</h2><a class="btn subtle" href="index.php?export=csv">Exportar CSV seguro</a></div><div class="table-wrap"><table><thead><tr><th>Código</th><th>Hóspede</th><th>Destinatário</th><th>Quarto</th><th>Check-in</th><th>Checkout</th><th>Status</th><th>eKey</th></tr></thead><tbody>
<?php foreach ($reservations as $r): ?><tr><td><?=h($r['code'])?><?=$r['needs_review']?' <span class="tag warning">Revisar</span>':''?></td><td><?=h($r['guest_name'])?></td><td><?=h($r['receiver_username'])?></td><td><?=h($r['room'])?></td><td><?=h(local_date($r['checkin_utc']))?></td><td><?=h(local_date($r['checkout_utc']))?></td><td><?=h($r['status'])?></td><td><?=h($r['key_state']??'Não emitida')?></td></tr><?php endforeach; ?>
<?php if (!$reservations): ?><tr><td colspan="8" class="empty">Ainda não há reservas.</td></tr><?php endif; ?></tbody></table></div></div>

<?php elseif ($page==='keys'):
    $keyReservations=sql_all("SELECT r.id,r.code,l.room,r.checkin_utc,r.checkout_utc,r.receiver_username FROM reservations r JOIN locks l ON l.id=r.lock_id LEFT JOIN ekeys e ON e.reservation_id=r.id WHERE e.id IS NULL AND r.status IN ('confirmed','checked_in') ORDER BY r.checkin_utc LIMIT 100");
    $search=substr(trim((string)($_GET['q']??'')),0,100);
    $filter=(string)($_GET['status']??'');
    $args=[]; $where=' WHERE 1=1';
    if ($search!=='') { $where.=" AND (r.code LIKE ? OR l.room LIKE ? OR e.receiver_username LIKE ?)";$like='%'.$search.'%';$args=[$like,$like,$like]; }
    if (in_array($filter,['issued','frozen','deleted','failed'],true)) { $where.=' AND e.state=?'; $args[]=$filter; }
    $keys=sql_all('SELECT e.*,r.code,r.guest_name,l.room,l.mode FROM ekeys e JOIN reservations r ON r.id=e.reservation_id JOIN locks l ON l.id=e.lock_id'.$where.' ORDER BY e.id DESC LIMIT 200',$args);
?>
<div class="panel"><h2>Emitir eKey temporária</h2><p>Identidade destinatária: conta TTLock existente por hóspede. O cadastro técnico automático está desativado. Cada eKey recebe a validade integral de check-in/checkout.</p>
<form method="post" class="form-grid"><?php field_csrf(); ?><input type="hidden" name="action" value="issue_key"><input type="hidden" name="back" value="keys">
<label class="wide">Reserva sem emissão<select name="reservation_id" required><option value="">Selecione a reserva</option><?php foreach ($keyReservations as $r): ?><option value="<?=h($r['id'])?>"><?=h($r['code'].' · quarto '.$r['room'].' · '.$r['receiver_username'].' · '.local_date($r['checkin_utc']))?></option><?php endforeach; ?></select></label>
<label>Execução<select name="execution"><option value="simulate">SIMULAR (não chama TTLock)</option><option value="real">REAL: apenas bancada liberada na configuração</option></select></label>
<label>Confirmação de escrita real<input name="confirmation" placeholder="Digite EMITIR só para operação real"></label>
<label>Exceção de sobreposição<input name="overlap_confirmation" placeholder="SOBREPOR somente se autorizado"></label>
<div class="wide"><button class="btn primary" type="submit">Emitir eKey</button><p class="footnote">Não envia chaves para a conta administradora nem registra senhas de hóspedes.</p></div></form></div>
<div class="panel"><div class="panel-head"><h2>eKeys registradas</h2><form method="get" class="inline-form"><input type="hidden" name="page" value="keys"><input name="q" value="<?=h($search)?>" placeholder="Reserva, quarto ou destinatário"><select name="status"><option value="">Todos os estados</option><?php foreach (['issued','frozen','deleted','failed'] as $s): ?><option value="<?=h($s)?>" <?=$filter===$s?'selected':''?>><?=h($s)?></option><?php endforeach; ?></select><button type="submit" class="btn subtle">Filtrar</button></form></div>
<div class="table-wrap"><table><thead><tr><th>Reserva</th><th>Quarto</th><th>Destinatário</th><th>Validade</th><th>keyId</th><th>Estado</th><th>Origem</th><th>Ações</th></tr></thead><tbody>
<?php foreach ($keys as $k): ?><tr>
<td><?=h($k['code'])?></td><td><?=h($k['room'])?></td><td><?=h($k['receiver_username'])?></td>
<td><?=h(local_date($k['valid_from_utc']))?><br><span class="muted">até <?=h(local_date($k['valid_until_utc']))?></span></td><td><?=h($k['key_id']??'—')?></td>
<td><span class="tag"><?=h($k['state'])?></span><small class="table-note">Sync: <?=h($k['sync_state'])?></small></td><td><?= $k['simulated']?'<span class="tag">SIMULADA</span>':'<span class="tag warning">REAL</span>' ?></td>
<td><details><summary>Operações</summary><div class="action-stack">
<form method="post"><?php field_csrf(); ?><input type="hidden" name="action" value="key_consult"><input type="hidden" name="key_row_id" value="<?=h($k['id'])?>"><input type="hidden" name="back" value="keys"><button class="btn subtle" type="submit">Consultar nuvem/local</button></form>
<?php if (!in_array($k['state'],['deleted','failed'],true)): ?>
<?php foreach (['freeze'=>'Congelar','unfreeze'=>'Descongelar','delete'=>'Apagar'] as $verb=>$label): if (($verb==='freeze'&&$k['state']!=='issued')||($verb==='unfreeze'&&$k['state']!=='frozen')) continue; ?>
<form method="post" class="stack"><?php field_csrf(); ?><input type="hidden" name="action" value="key_mutate"><input type="hidden" name="verb" value="<?=h($verb)?>"><input type="hidden" name="key_row_id" value="<?=h($k['id'])?>"><input type="hidden" name="back" value="keys"><label><?=h($label)?> — digite CONFIRMAR<input name="confirmation" required placeholder="CONFIRMAR"></label><button class="btn <?=$verb==='delete'?'danger':'subtle'?>" type="submit"><?=h($label)?></button></form>
<?php endforeach; endif; ?></div></details></td></tr><?php endforeach; ?>
<?php if (!$keys): ?><tr><td colspan="8" class="empty">Nenhuma eKey emitida.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php if (isset($_SESSION['listed_keys'])): $listed=$_SESSION['listed_keys']; unset($_SESSION['listed_keys']); ?>
<div class="panel"><h2>Última listagem da fechadura (até 100 itens)</h2><div class="table-wrap"><table><thead><tr><th>keyId</th><th>Destinatário</th><th>Estado</th><th>Data</th></tr></thead><tbody><?php foreach ($listed as $entry): ?><tr><td><?=h($entry['key_id'])?></td><td><?=h($entry['receiver'])?></td><td><?=h($entry['state'])?></td><td><?=h($entry['period'])?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>

<?php elseif ($page==='audit'):
    $events=sql_all('SELECT a.*,u.username,r.code,l.room FROM audit_logs a LEFT JOIN admin_users u ON u.id=a.user_id LEFT JOIN reservations r ON r.id=a.reservation_id LEFT JOIN locks l ON l.id=a.lock_id ORDER BY a.id DESC LIMIT 250');
    $calls=sql_all('SELECT endpoint,lock_id,result,created_at FROM api_calls ORDER BY id DESC LIMIT 60');
?>
<div class="panel"><h2>Histórico de auditoria</h2><div class="table-wrap"><table><thead><tr><th>Data</th><th>Usuário</th><th>Reserva</th><th>Quarto</th><th>keyId</th><th>Ação</th><th>Resultado</th><th>Detalhe sanitizado</th></tr></thead><tbody>
<?php foreach ($events as $e): ?><tr><td><?=h(local_date($e['created_at']))?></td><td><?=h($e['username']??'Integração')?></td><td><?=h($e['code']??'—')?></td><td><?=h($e['room']??'—')?></td><td><?=h($e['key_id']??'—')?></td><td><?=h($e['action'])?></td><td><?=h($e['result'])?></td><td><?=h($e['detail'])?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="panel"><h2>Chamadas TTLock (sem tokens)</h2><div class="table-wrap"><table><thead><tr><th>Data</th><th>Endpoint</th><th>Fechadura interna</th><th>Resultado</th></tr></thead><tbody><?php foreach ($calls as $c): ?><tr><td><?=h(local_date($c['created_at']))?></td><td><?=h($c['endpoint'])?></td><td><?=h($c['lock_id']??'—')?></td><td><?=h($c['result'])?></td></tr><?php endforeach; ?></tbody></table></div></div>

<?php elseif ($page==='diagnostics'): ?>
<div class="panel"><h2>Diagnóstico da integração</h2><p>Por padrão, apenas verifica o ambiente local. A leitura remota, se marcada, obtém token OAuth e lista fechaduras da conta administradora — nunca emite eKey.</p>
<form method="post" class="stack"><?php field_csrf(); ?><input type="hidden" name="action" value="diagnose"><input type="hidden" name="back" value="diagnostics"><label class="check"><input type="checkbox" name="remote_read" value="yes"> Autorizar teste REMOTO de leitura TTLock nesta execução</label><button class="btn primary" type="submit">Executar diagnóstico</button></form></div>
<?php if (isset($_SESSION['diagnostics'])): $diag=$_SESSION['diagnostics'];unset($_SESSION['diagnostics']); ?><div class="panel"><h2>Resultado do último diagnóstico</h2><table><tbody><?php foreach ($diag as $name=>$value): ?><tr><th><?=h($name)?></th><td><?=h($value)?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<div class="panel"><h2>Fluxo bloqueado até validação</h2><p>O endpoint do HFVM exige identidade da reserva assinada pelo backend e aplica proteção anti-replay, mas <strong>não entrega lockData de administrador ao hóspede</strong>. O fluxo para obter a eKey da conta receptora via SDK Android TTLock precisa ser validado na fechadura Saga de bancada. Cadastro automático de contas técnicas permanece desativado.</p></div>

<?php elseif ($page==='settings'): ?>
<div class="panel"><h2>Política de uso da API</h2><form method="post" class="form-grid"><?php field_csrf(); ?><input type="hidden" name="action" value="settings_save"><input type="hidden" name="back" value="settings"><label>Cota mensal de referência<input name="monthly_quota" type="number" min="100" max="100000000" value="<?=h($quota['max'])?>" required></label><div><p>Alertas automáticos visuais aos 80%, 90% e 100%. Sem polling frequente.</p><button class="btn primary" type="submit">Salvar cota</button></div></form></div>
<div class="panel"><h2>Modo de execução</h2><p>O padrão é simulação. Para leituras e escritas de bancada, edite a configuração privada no servidor, configure <code>ttlock_bench_lock_id</code>, defina <code>ttlock_live_enabled=true</code> e confirme cada ação real explicitamente. Não existe interruptor pelo navegador que libere fechaduras de produção.</p></div>
<div class="panel"><h2>Integração de reservas HFVM</h2><p>Webhook autenticado: <code>POST api.php?route=reservation</code>. Considere assinatura HMAC, idempotência por <code>event_id</code>, fuso e mapeamento único do quarto. O endpoint <code>api.php?route=guest-key</code> exige token HMAC curto por reserva, mas não libera credencial Bluetooth sem validação da identidade receptora.</p>
<p><a href="README.md">Documentação e exemplos no README</a></p></div>
<?php endif; ?>
<footer class="footer">TTLock eKeys Dashboard · Dados e operações classificados como simulados ou reais · Nenhum segredo é enviado para o navegador.</footer>
</main></div></body></html>
