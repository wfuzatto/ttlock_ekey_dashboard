# TTLock eKey Dashboard — Locaweb / XAMPP

Painel administrativo **PHP 8.1+ / MySQL ou MariaDB / Apache** para controle de eKeys temporárias vinculadas às reservas do hotel. Interface responsiva em português brasileiro. **Não requer Node.js, Docker, npm, Composer, SSH ou processo residente** na hospedagem Locaweb: basta FTP, PHP e banco MySQL.

**Situação da integração:** os módulos de cadastro, reservas, CSV, segurança, emissão **simulada**, controle de períodos, auditoria e webhook operam no banco local. A leitura da TTLock e as operações reais só são ativadas deliberadamente com credenciais novas e uma fechadura de bancada. **A entrega de uma eKey pessoal ao app HFVM e a revogação física offline não estão validadas.** O dashboard não apresenta esse fluxo como concluído.

## 1. Instalação na Locaweb (hospedagem PHP)

1. Crie um banco MySQL/MariaDB no painel de hospedagem. Anote nome, servidor, usuário e senha. Escolha **PHP 8.1 ou superior** e habilite as extensões PDO MySQL, OpenSSL, cURL e mbstring.
2. Suba o conteúdo deste repositório via FTP para `public_html/ttlock_ekey_dashboard/` (sem `.git`, sem arquivos de configuração privados). O endereço será `https://SEU_DOMINIO/ttlock_ekey_dashboard/`.
3. Copie `config.example.php` para um arquivo **fora de `public_html`**, chamado `ttlock_ekey_config.php`, e preencha seus valores **novos**. O código procura o arquivo três níveis acima de `src/`, portanto no cenário habitual ele fica na pasta de usuário da hospedagem, acima de `public_html`. Caso não haja acesso a essa pasta na conta Locaweb, use `config.local.php` na raiz do projeto, protegido pelo `.htaccess` incluído; confirme o bloqueio HTTP desse arquivo antes de habilitar produção.
4. Gere `app_key_hex` com 32 bytes aleatórios (64 caracteres hexadecimais). Exemplo no XAMPP ou em um PHP local: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Gere uma `install_key` aleatória e independente, com ao menos 20 caracteres. **Não reutilize senhas ou segredos colados em conversas.**
5. Acesse `https://SEU_DOMINIO/ttlock_ekey_dashboard/install.php`, informe a chave de instalação, escolha um usuário administrador e senha forte (mínimo 14 caracteres). A página cria tabelas e o primeiro usuário. **Remova `install.php` do servidor após a instalação**, mesmo com o arquivo `storage/install.lock` bloqueando novas execuções.
6. Acesse `index.php` em HTTPS; a primeira instalação inicia sem TTLock real habilitada. Crie uma fechadura em **Simulação**, cadastre uma reserva e use **eKeys → SIMULAR**. O banco registrará ações e auditoria sem acionar fechaduras reais.

> Use HTTPS válido e teste `https://SEU_DOMINIO/ttlock_ekey_dashboard/src/bootstrap.php` e `.../storage/install.lock`: devem retornar acesso negado pelo Apache. O arquivo de configuração **nunca** deve ser disponibilizado para download. Faça backup do banco e mantenha o FTP/credenciais protegidos.

### Instalação local no Windows/XAMPP

Copie a pasta do projeto para `C:\xampp\htdocs\ttlock_ekey_dashboard`. Copie `config.example.php` para `C:\xampp\ttlock_ekey_config.php` (ou `config.local.php` na pasta do projeto, apenas durante o desenvolvimento). Inicie Apache e MySQL, crie um banco vazio pelo phpMyAdmin, preencha config, visite `http://localhost/ttlock_ekey_dashboard/install.php` e siga o assistente. Para seu primeiro teste, **deixe `ttlock_live_enabled=false`**.

### Configuração privada

`config.example.php` contém os nomes de todos os campos; `.env.example` é somente uma referência e **não** é carregado automaticamente. O aplicativo lê o array do arquivo PHP privado.

| Campo | Uso |
|---|---|
| `db_host`, `db_port`, `db_name`, `db_user`, `db_password` | Banco do site |
| `app_key_hex` | AES-256-GCM para cache de tokens OAuth; **não trocar** sem invalidar o cache |
| `install_key` | Chave de instalação inicial, não reaproveitar em outras aplicações |
| `ttlock_api_base` | URL HTTPS da API da **região correta da conta**, padrão `https://api.sciener.com`, conforme documentação EU |
| `ttlock_client_id`, `ttlock_client_secret` | Dados de aplicativo TTLock aprovado |
| `ttlock_admin_username`, `ttlock_admin_password` | Conta **TTLock App** administradora das fechaduras, diferente da conta de desenvolvedor |
| `ttlock_live_enabled` | `false` por padrão; `true` só em ensaio controlado |
| `ttlock_bench_lock_id` | `lockId` exato da fechadura de **bancada** autorizada |
| `hfvm_signing_key` | Segredo HMAC único, compartilhado apenas com **backend** do HFVM |
| `webhook_secret` | Segredo HMAC diferente, somente para o webhook do sistema de reservas |

O código solicita OAuth2 com conta administradora via `/oauth2/token`, fornece à TTLock o hash MD5 que sua API legada documenta, armazena `access_token` / `refresh_token` cifrados e utiliza `expires_in` para expiração e renovação. **Nunca transmita essas credenciais para o JavaScript nem para o APK.** A API pode exigir conta, região e app previamente autorizados.

## 2. Fluxo do dashboard

- **Fechaduras:** cadastrar, editar, mapear quarto e `lockId`, definir modo `simulation`, `bench` ou `production`; o último é apenas uma classificação e **não** habilita escrita remota.
- **Reservas:** cadastrar/atualizar manualmente por código, definir hóspede, destinatário TTLock individual, fechadura, check-in e checkout, alterar status, observações e importar CSV de até 500 reservas. Cada update de reserva que já tinha eKey fica marcado para **revisão manual**.
- **eKeys:** emitir em modo simulado por padrão; selecionar modo real exige que a fechadura esteja cadastrada como `bench`, que o `lockId` corresponda à configuração privada, que `ttlock_live_enabled=true` e que o administrador digite `EMITIR`. A validade vai sempre do check-in ao checkout, com horário São Paulo convertido para **UTC** no banco e **milissegundos Unix** no envio.
- **Sobreposição:** bloqueio por reserva/fechadura quando os intervalos se cruzam (ignorando reservas canceladas e com checkout concluído). Administrador pode digitar `SOBREPOR` como autorização explícita; a exceção aparece na auditoria.
- **Destinatário:** a conta emissora não pode receber a própria eKey. Se o mesmo destinatário já tiver uma eKey ativa registrada para a mesma fechadura, o sistema impede novo envio, pois a TTLock substituiria a anterior. O fluxo exige **conta TTLock/Sciener já existente do hóspede**. Não usa `createUser=1`, que criaria senha fraca; contas técnicas por reserva estão desativadas até validação real.
- **Operações:** consultar o estado na base local ou lista de eKeys comuns da TTLock (`/v3/lock/listKey`); congelar (`/v3/key/freeze`), descongelar (`/v3/key/unfreeze`) e apagar (`/v3/key/delete`) somente mediante `CONFIRMAR`. Escritas reais limitadas à bancada; se houver timeout, **não há retry automático do envio** e o registro fica em revisão para evitar duplicidade.
- **Painel:** cartões de reservas próximas, válidas agora (inclui simulações), pendências, expiradas, revogações sem confirmação física e chamadas mensais; alertas visuais aos 80%, 90% e 100% da cota configurável.
- **Auditoria:** registra ação, usuário, reserva, fechadura, `keyId`, estado, instante e resultado sanitizado. Exportação CSV não contém senha, token, `lockData` ou chave criptográfica.

### CSV de reservas

```csv
code;guest_name;receiver_username;room;checkin;checkout;status;notes
RES-001;Hóspede Exemplo;hospede@example.org;203;2026-11-08T15:00;2026-11-10T11:00;confirmed;Exemplo
```

Quarto deve estar previamente mapeado de maneira **única** a uma fechadura ativa. Datas sem offset são interpretadas como `America/Sao_Paulo`. Datas ISO 8601 com offset também são aceitas. Status permitidos: `confirmed`, `checked_in`, `checked_out`, `cancelled`. A importação **não emite eKey automaticamente**.

## 3. Integração de reservas via webhook HFVM

**POST `https://SEU_DOMINIO/ttlock_ekey_dashboard/api.php?route=reservation`** com JSON:

```json
{
  "event_id": "reserva-RES-001-revisao-7",
  "code": "RES-001",
  "guest_name": "Hóspede Exemplo",
  "receiver_username": "hospede@example.org",
  "room": "203",
  "checkin": "2026-11-08T15:00",
  "checkout": "2026-11-10T11:00",
  "status": "confirmed",
  "notes": "Exemplo"
}
```

Headers obrigatórios:

- `X-HFVM-Timestamp: <segundos Unix atuais>`
- `X-HFVM-Signature: <hex(HMAC-SHA256(webhook_secret, timestamp + "." + corpo bruto JSON))>`
- `Content-Type: application/json`

Janela temporal ±5 minutos. Reenvio do mesmo `event_id` retorna `duplicate=true` sem reprocessamento. O quarto deve corresponder a **uma** fechadura ativa. Cancelamentos/checkout alteram a reserva e sinalizam revisão, mas **não executam revogação remota silenciosa**.

O banco HFVM ainda **não** está conectado; o webhook e CSV são contratos disponibilizados, não uma sincronização inventada.

## 4. Endpoint autenticado para o app HFVM

**POST `api.php?route=guest-key`**, sempre HTTPS, com bearer JWT-like HMAC de uso único gerado **no backend HFVM**, nunca assinado no APK.

Formato do bearer: `base64url(JSON claims) + "." + hex(HMAC-SHA256(hfvm_signing_key, base64url(JSON claims)))`.

Claims JSON obrigatórios:
```json
{"reservation_code":"RES-001","iat":1794160800,"exp":1794161100,"nonce":"aleatorioUnicoDePeloMenos16Caracteres"}
```

`iat` não pode ser maior do que 5 minutos atrás, `exp` deve ser futuro e no máximo `iat+300`. O nonce é consumido no banco ao fazer a consulta para impedir replay. O backend identifica a reserva a partir do token assinado, **nunca de um código fornecido isoladamente pelo APK**.

**Bloqueio explícito:** no estado atual, o endpoint valida a autorização, mas retorna HTTP **409 `guest_credential_delivery_not_validated`** sem `lockData`. Isso é intencional. Com o token da conta **administradora**, `/v3/key/get` não comprova posse da eKey pessoal do hóspede. É necessária validação na bancada com a conta receptora e o SDK TTLock Android, para fornecer uma credencial que pertença **àquela identidade** sem colocar `client_secret`, senha TTLock ou OAuth do emissor no app. **Não libere a entrega de `lockData` do administrador como atalho.** Não há senha de hóspede cadastrada ou persistida pelo painel.

## 5. Diagnóstico e validação TTLock

A tela Diagnóstico verifica a configuração local e, se o operador **autorizar leitura remota**, obtém/renova OAuth e chama `/v3/lock/list` para conferir se a fechadura configurada de bancada está visível. Não seleciona automaticamente nenhum `lockId`.

Para um teste real autorizado:
1. Use uma conta TTLock App administradora e app desenvolvedor aprovado; confirme a região/base URL correta e troque quaisquer segredos antigos que foram expostos.
2. Registre **somente** o `lockId` da fechadura Saga de bancada no arquivo privado e cadastre-a no painel em modo `bench` com o mesmo ID.
3. Configure `ttlock_live_enabled=true` manualmente no arquivo privado. Execute um diagnóstico de **leitura**.
4. Cadastre uma reserva fictícia futura com destinatário TTLock **diferente do emissor**, já existente.
5. Em eKeys, selecione modo **REAL** e digite `EMITIR`; confira `keyId` e o recebimento no dispositivo **via SDK oficial**. Para congelar/excluir, digite `CONFIRMAR` e faça verificação física da abertura.
6. Retorne `ttlock_live_enabled=false` após a bancada.

**Nenhuma etapa deste repositório realizou teste em fechadura física**, pois credenciais e bancada devem ser fornecidas no ambiente autorizado. O SDK de bancada existente, se estiver em outro repositório, não foi alterado.

### Por que checkout antecipado não corta o Bluetooth na hora?

Uma eKey recebida e armazenada em telefone pode continuar usável enquanto a fechadura estiver sem gateway ou sem sincronização Bluetooth. Apagar/congelar na nuvem **não equivale** a atualizar fisicamente a fechadura offline. A validade de checkout fica embutida desde a emissão e limita seu uso; para revogação antecipada é preciso sincronização via SDK presencial ou gateway compatível e **testar** o resultado. O painel mantém `sync_state=pending` e alerta ao administrador. Nunca muda para `confirmed` só porque a nuvem respondeu OK.

## 6. Cota e custo de chamadas

A referência inicial configurável é **30.000 chamadas por mês por aplicação**, conforme FAQ oficial da TTLock (o plano contratado pode ser diferente).

Estimativa por reserva:
- Cadastro local/manual/CSV/webhook: **0 chamadas** TTLock.
- Emissão de uma eKey existente: **1** chamada `/v3/key/send`, mais **0 ou 1** para obter/renovar OAuth quando necessário.
- Diagnóstico: **1** chamada `/v3/lock/list` mais OAuth, se necessário.
- Conferência por fechadura: **1** chamada `/v3/lock/listKey` por página de até 100 itens, mais OAuth se necessário.
- Congelar, descongelar ou apagar: **1** chamada por ação.
- Cadastro automático de conta técnica: **0**, porque está desabilitado.
- Obter eKey da conta destinatária / `lockData` para HFVM: **não implementado** até validação do SDK/conta receptora; não invente custos nem um fluxo funcional.
- Abrir a fechadura por Bluetooth no app: **0 chamadas Cloud API** por abertura.

O painel armazena apenas endpoint, horário, fechadura interna e resultado das chamadas; não salva respostas sensíveis. Se um envio externo der timeout, pode existir eKey na nuvem embora o painel não conheça seu `keyId`. A reserva fica em revisão e não há retransmissão automática.

## 7. Segurança e manutenção

- Login com `password_hash` / `password_verify`, cookie `HttpOnly` / `SameSite`, CSRF, regeneração de sessão e limite de tentativas por conta/IP em 15 minutos.
- Preparação SQL parametrizada, escape HTML, validação dos campos e controle de transições de eKeys.
- Credenciais no servidor fora do Git; cache OAuth com **AES-256-GCM**; histórico sem tokens; exportação neutraliza fórmulas CSV.
- Configuração de banco e chaves é feita por arquivo privado: **não há tela para revelar nem alterar segredos pelo navegador**.
- Proteção do Apache para `src` e `storage`, bloqueio de listagem de diretórios e HTTPS obrigatório fora de localhost.
- Mantenha `install.php` excluído depois de instalar. Faça backup periódico do MySQL; não exporte o cache de tokens para terceiros.
- Testes sintáticos e smoke de datas: GitHub Actions `PHP checks`; localmente, execute `php tests/smoke.php`. Testes de navegação com MySQL, integração da conta TTLock e abertura Bluetooth **não podem ser considerados aprovados sem executar no ambiente**.

## 8. Referências oficiais verificadas

- [TTLock — envio de eKey](https://euopen.ttlock.com/doc/api/v3/key/send)
- [TTLock — lista de eKeys comuns](https://euopen.ttlock.com/doc/api/v3/lock/listKey)
- [TTLock — congelar](https://euopen.ttlock.com/doc/api/v3/key/freeze), [descongelar](https://euopen.ttlock.com/doc/api/v3/key/unfreeze), [apagar](https://euopen.ttlock.com/doc/api/v3/key/delete)
- [TTLock — OAuth](https://euopen.ttlock.com/doc/oauth2), [renovar token](https://euopen.ttlock.com/doc/oauth2/refreshToken)
- [TTLock — primeiros passos](https://euopen.ttlock.com/documentPages/htmlPages/userGuide/getStartedEn.html), [FAQ](https://euopen.ttlock.com/documentPages/htmlPages/example/FAQEn.html)

**A integração HFVM não depende de chamada Cloud API em cada tentativa de abertura.** O APK precisa usar o SDK Bluetooth oficial para trabalhar com a eKey de seu destinatário legítimo.
