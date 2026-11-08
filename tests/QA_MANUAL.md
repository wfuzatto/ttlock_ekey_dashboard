# Checklist de verificação manual (nenhuma fechadura real)

- [ ] XAMPP com PHP 8.1+, Apache e MySQL inicia o instalador com config privada.
- [ ] Instalação cria tabelas, usuário e arquivo de bloqueio.
- [ ] Login válido entra; incorreto falha; CSRF inválido impede POST; logout encerra sessão.
- [ ] Cadastro de fechadura modo Simulação sem lockId.
- [ ] Reserva de teste check-in e checkout, usuário TTLock destinatário fictício.
- [ ] Emissão padrão SIMULAR gera eKey sem chamadas na tabela api_calls.
- [ ] Congelar, descongelar e apagar exigem confirmação.
- [ ] Reserva simultânea no mesmo quarto bloqueia emissão sem SOBREPOR.
- [ ] Não emitir segunda eKey para mesmo destinatário/fechadura.
- [ ] CSV traz e atualiza reservas sem emitir chaves.
- [ ] Exportação CSV não contém segredos.
- [ ] Webhook com assinatura incorreta retorna 401; evento duplicado é idempotente.
- [ ] Token HFVM incorreto retorna 401; token válido não retorna lockData.
- [ ] Simulação mantém aviso de revogação offline e identificação de modo.
- [ ] Verificar em HTTPS e que src/, storage/, config.local.php não sejam públicos.
- [ ] Testar apenas no sandbox local; nenhum comando de escrita TTLock sem autorização.
