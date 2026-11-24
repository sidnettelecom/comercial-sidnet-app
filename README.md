# Portal Sidnet (antes Comercial + Sidnet)

O painel agora se chama **Portal Sidnet**, porque atende o comercial, o financeiro e o campo. O app dos vendedores continua com o nome antigo até o próximo APK.

App dos vendedores para cadastrar **Nova venda**, **Upgrade** e **2º plano (mesmo cliente)**.
Funciona sem internet: cada cadastro fica guardado no celular, entra numa fila e é enviado
sozinho ao CRM (crm.sidnet.com.br) quando o celular conecta.

## Como funciona

```
celular (app, funciona offline)  →  servidor do app (Debian)  →  CRM (crm.sidnet.com.br)
   fila no próprio celular            guarda tudo e repassa         Novo Lead / Upgrades
```

1. O vendedor salva o cadastro. No histórico ele fica **Na fila**.
2. Com internet (ao abrir o app, ao conectar e a cada 20 s), o app manda a fila para o servidor do app
   com o login do vendedor. O selo vira **No servidor**: o cadastro já saiu do celular.
3. A cada minuto o servidor entra no CRM com um usuário do sistema e preenche a tela
   **Novo Lead** (venda e 2º plano) ou **Upgrades → Lançar à mão** (upgrade), do mesmo jeito que uma pessoa faria.
4. O app pergunta a situação e o selo vira **No CRM Lead #123**. Se o CRM recusar (ex.: CPF duplicado),
   o motivo aparece no app e no painel, onde dá para tentar de novo, enviar mesmo assim ou descartar.

Cada cadastro tem um `id_app` único: reenviar não cria venda duplicada.
O app só abre com **usuário e senha** do vendedor (criados no painel). Depois do primeiro login com internet,
dá para entrar sem internet com a mesma senha; o app pede a senha de novo a cada 12 horas.

## Envio ao IXC

Com `'destino' => 'ixc'` no `config.php`, o servidor manda tudo para o IXC Provedor pela API oficial:

| No app | No IXC |
|---|---|
| Venda nova | Lead no CRM do IXC (Leads). Avisa se o CPF já é lead ou cliente. |
| Upgrade | Só com `ixc.enviar_upgrade = true`. Não muda o plano. Atualiza telefone principal, telefone 2 e e-mail no cadastro do cliente (só o que mudou) e abre o atendimento de upgrade no contrato e login escolhidos no app, com o texto no modelo do comercial (cliente, login, upgrade ou downgrade, plano atual, novo plano). Se o IXC recusar a mudança do contato, o atendimento sai assim mesmo, pedindo para atualizar à mão. |
| 2º ponto | Contrato novo (pré-contrato, sem cobrança até ativar), login novo ligado a ele e atendimento com o assunto de 2º ponto. Se der erro no meio, "Tentar de novo" continua de onde parou, sem duplicar. |

Para ligar:

1. No IXC, crie um usuário só para o app, com token e acesso apenas a clientes, contratos, logins, leads e atendimentos.
2. No servidor: `php server/bin/ixc.php token` e cole o token (ele não aparece na tela e fica em `/etc/comercial-sidnet/ixc-token`).
3. `php server/bin/ixc.php listar assuntos` (e `setores`, `filiais`, `planos`) e preencha o bloco `ixc` do `config.php`.
4. `php server/bin/ixc.php teste` confere o token e os assuntos.
5. No painel, em Vendedores, "Carregar vendedores do IXC" e ligue cada vendedor.

Os nomes dos planos no app precisam ser iguais aos planos de venda do IXC.

Upgrade no IXC (desligado até o ALEX liberar): no `config.php`, `'enviar_upgrade' => true`, `'assunto_upgrade' => 63`
(ALTERAÇÃO DE PLANO DE VENDA) e `'setor_id'` do Comercial. O usuário do app no IXC precisa poder **alterar cliente** e incluir atendimento.
Para abrir só o atendimento, sem mexer no cadastro: `'atualizar_contato' => false`.

## Recolhimento de equipamentos (painel)

Menu **Recolhimento** do painel, com duas telas:

- **Recolhimentos**: o financeiro lança as O.S de recolhimento que já fez no IXC. Digita o nº da O.S e clica **Buscar O.S**: vêm do IXC o cliente, o contrato, a abertura e o fechamento; o recolhedor já vem escolhido se houver só um na lista. Depois preenche o MAC e o **modelo** de cada equipamento recolhido, se está funcionando e uma observação. Sem nº da O.S, dá para buscar só o nome pelo ID do contrato ou digitar tudo à mão. **O sistema não abre nem fecha O.S e não grava nada no IXC** (só lê `su_oss_chamado`, `cliente` e `funcionarios`). O nº de equipamentos é contado pelos MACs e o mês da O.S é o do fechamento. Avisa se o MAC já está em outra O.S e recusa nº de O.S repetido. Liberada em Configurações → Permissões ("Recolhimentos").
- **Comissão** (só o admin): mostra cada cliente com os modelos recolhidos e o resumo de modelos do mês; valor padrão para quem lançou (financeiro) e para quem recolheu (recolhedor), por equipamento ou por O.S, com opção de não pagar o recolhedor em O.S sem funcionar. O admin ajusta linha por linha, marca ✔ e registra o mês do pagamento (paga uma vez só; O.S paga não muda mais).

**Puxar sozinho do IXC** (desde 2026-11-24): a cada 6 horas (`bin/repassar.php`, junto da análise da instalação) e no botão **Puxar agora** da aba "A preencher", o sistema lê as O.S **finalizadas** no mês com o assunto **22 RETIRADA DE EQUIPAMENTOS** (o admin pode trocar os assuntos na mesma tela) e cria a linha com cliente, contrato, nº da O.S, abertura e fechamento. Não repete O.S que já está aqui. O diagnóstico do fechamento separa as abas: **9 EQUIPAMENTO RECOLHIDO** (ou sem diagnóstico) vai para **A preencher**, onde o financeiro clica **Preencher** e só coloca os equipamentos; diagnóstico com "não recolhido" vai para **Fechadas sem recolher** (o financeiro também pode mandar uma O.S para lá com o motivo). Só a aba **Recolhidos** entra na comissão. O diagnóstico é procurado na O.S e, se não estiver nela, nas mensagens da O.S (`su_oss_chamado_mensagem`); o nome vem de `su_diagnostico`. Continua só leitura no IXC.

Os recolhedores (quem vai buscar o equipamento, hoje um vendedor; só aparece pelo nome, não entra no sistema) são cadastrados em Configurações → Permissões, no fim da página. Tabelas `recolhimentos`, `rec_tecnicos` e `rec_regra` (criadas sozinhas).

## Instalações concluídas (painel)

Menu **Instalação** do painel, com duas telas:

- **Instalações concluídas**: o gestor lança as O.S de instalação já feitas no IXC. Digita o nº da O.S e clica **Buscar O.S**: vêm do IXC o cliente, o contrato, a abertura, o fechamento e o técnico (que entra sozinho na lista de técnicos). Se não vier, o gestor preenche à mão. Depois escolhe o **tipo de serviço** (vem sozinho se o assunto da O.S tiver o mesmo nome), **quem agendou** (também recebe comissão), os técnicos que fizeram (**+ Adicionar técnico** para a dupla) e, se teve, os equipamentos recolhidos na instalação (MAC e modelo, **+ Adicionar equipamento**). **O sistema não abre nem fecha O.S e não grava nada no IXC.** O mês da O.S é o do fechamento; nº de O.S repetido é recusado. Liberada em Configurações → Permissões ("Instalações").
- **Comissões** (só o admin): em cada O.S, um campo de valor para cada técnico (dupla: um valor para cada; sozinho: um valor) e um para quem agendou. Valor padrão **por tipo de serviço** (técnico sozinho, cada técnico em dupla, quem agendou), editável e com novos serviços nessa tela. O admin marca ✔ e registra o mês do pagamento (paga uma vez só; O.S paga não muda mais). No fim, a tabela **Total de cada pessoa** do mês (técnicos e quem agendou), com o que falta pagar.

Os técnicos e quem agenda são cadastrados em Configurações → Permissões, no fim da página. Tabelas `instalacoes`, `inst_tec`, `inst_tecnicos`, `inst_agendadores` e `inst_servicos` (criadas sozinhas; serviços iniciais: Instalação nova, 2º ponto, Mudança de endereço).

O menu ficou agrupado: Vendas (Vendas, Vendas do mês), Comissões, Recolhimento e Configurações (Permissões, Gestores, Usuários, Planos). "Usuários" é a antiga aba Vendedores.

## Serviços em execução (painel)

Menu **Serviços em execução**, só do admin até ele liberar em Configurações → Permissões. Serve para não esquecer serviços de rede feitos por equipes (lançamento de fibra em área rural, rede em poste da CEMIG, rede em postes de eucalipto próprios) e ir acertando aos poucos:

- **Saldos**: um cartão por equipe com o que falta pagar, o total geral e o saldo por obra. **Ver extrato** mostra tudo da equipe como extrato de banco (serviço soma, pagamento desconta, saldo do dia), com botão de imprimir e de recibo.
- **Lançar**: serviço feito (equipe, data, obra opcional, serviço da tabela × quantidade, ou valor fechado com descrição) e pagamento (parcial ou não; acima do saldo vira crédito da equipe).
- **Serviços lançados**: todos os serviços com a situação. O pagamento abate primeiro o serviço mais antigo da equipe: em aberto, pago em parte ou quitado.
- **Equipes, obras e preços**: cadastro das equipes (terceirizada ou própria, contato/PIX), das obras (tipo de rede, concluir) e da tabela de preços sugeridos (o valor pode ser trocado em cada lançamento).

Os pagamentos do mês entram na tela **Recibos** (origem "Serviços em execução") para a equipe assinar. Permissões: `serv.saldo`, `serv.lanc`, `serv.pag`, `serv.cad` e `recibos.serv`. Tabelas `serv_equipes`, `serv_obras`, `serv_precos` e `serv_lanc` (criadas sozinhas).

## Dashboard comercial (painel)

Aba **Dashboard** do painel, para o gestor admin e para quem o admin liberar em **Configurações** (quem não é admin vê as metas, mas só o admin define):

- Resumo do mês: vendas (venda nova + 2º ponto), upgrades, mensalidades novas, % da meta da equipe e projeção do mês.
- **Quem mais vendeu** e **quem mais fez upgrade**, com a variação contra o mês anterior.
  No mês em andamento a comparação é com o mesmo período do mês anterior (dia 1 até hoje).
- Gráfico dos últimos 6 meses por vendedor (vendas, upgrades ou total), ou de um vendedor só, com a linha da meta.
- **Metas**: meta de vendas e de upgrades por vendedor e por mês (tabela `metas`, criada sozinha), com barra de progresso
  e o ritmo esperado pelo dia do mês. "Copiar metas do mês anterior" preenche só quem ainda não tem meta.

**Supervisor** (Configurações → Supervisor da equipe): um vendedor marcado como supervisor não tem meta própria.
A meta e o resultado dele são a soma de todos os outros vendedores; ele sai do ranking e aparece na linha de total.

Conta tudo o que os vendedores cadastraram no app (menos os descartados), pelo dia em que chegou ao servidor.

## Permissões do painel (Configurações)

O admin vê tudo. Para os outros gestores, o admin marca em **Configurações** o que cada um pode abrir:
Dashboard, Vendas (com Vendas do mês), Vendedores, Planos, Comissões (com Comissões planilha) e Gestores.
O menu mostra só o que está liberado, e o servidor recusa ações de áreas não liberadas.
Gestor novo nasce só com Vendas. Configurações, metas da dashboard e criar/bloquear/dar admin a gestores são só do admin.
Todo gestor troca a própria senha em **Trocar minha senha** (embaixo do menu).

## Consultar CPF (já foi cliente?)

Aba **Consultar** no app (celular e navegador): o vendedor digita o CPF e o servidor consulta o IXC só para leitura
(`acao=consulta_cpf`). Mostra se a pessoa nunca foi cliente, é cliente ativo ou ex-cliente (contratos, quando entrou e saiu)
e os boletos **vencidos em aberto** com o total. Com débito, o botão **Enviar no WhatsApp para negociar** abre o WhatsApp
com a mensagem pronta, e o vendedor escolhe para quem mandar. Cada consulta fica no registro (CPF mascarado), no máximo 60 por hora por vendedor.

## Segurança

- Vendedores e gestores entram com usuário e senha (senhas guardadas com `password_hash`).
- 5 senhas erradas em 15 minutos travam o usuário por 15 minutos (20 por IP).
- O celular guarda só um token de sessão e uma impressão PBKDF2 da senha (para o login sem internet), nunca a senha.
- Bloquear um vendedor ou gerar senha nova derruba os logins dele em todos os celulares.
- Painel: cookie `HttpOnly`/`Secure`/`SameSite=Strict`, CSRF em todo formulário, sai sozinho após 8 h parado.
- Excluir venda no painel fica registrado em Gestores → Últimas ações, e o celular não consegue reenviar a venda excluída.
- Esqueceu a senha do painel: `php server/bin/gestor.php senha <usuario>` no servidor.
- Login do celular vale até 7 dias sem uso e no máximo 30 dias. A chave antiga (antes do login com senha) não é mais aceita.
- Tudo o que vai para o CRM passa por `texto_crm()`: sem caracteres de controle e sem `< > " \``, para ninguém colocar HTML ou script no CRM pelos campos do app.
- Cada vendedor manda no máximo 40 cadastros por hora; acima disso a API responde 429 e o app tenta depois.
- Um cadastro com dado inesperado vira "Erro" no painel e não trava a fila dos outros.
- O usuário do CRM usado pelo servidor deve ser exclusivo do app, só com acesso a Novo Lead e Upgrades.

## Pastas

| Pasta | O que tem |
|---|---|
| `app/` | A tela do app (`index.html`). É o que vai dentro do APK (pasta `assets/`). |
| `server/` | Servidor do app: API (`public/api/app-vendas.php`), painel (`public/painel/`), repasse ao CRM (`bin/repassar.php`, roda no cron) e instalação (`deploy/install.sh`). PHP 8 + SQLite + nginx. |

## Instalar no Debian

Antes: faça backup do sistema antigo e pare o que estiver usando as portas 80/443 nesse servidor.
Aponte o domínio (`portal.sidnet.com.br`) para o servidor (hoje pelo Cloudflare Tunnel).

```
curl -fsSLO https://raw.githubusercontent.com/sidnettelecom/comercial-sidnet-app/main/server/deploy/install.sh
sudo DOMINIO="portal.sidnet.com.br comercial.sidnet.com.br" bash install.sh
sudo nano /etc/comercial-sidnet/config.php
```

No `config.php`: usuário e senha do CRM só para o app (crie no CRM um usuário como `app.vendas`,
com acesso ao Novo Lead e aos Upgrades) e a senha do painel. Depois, em `https://portal.sidnet.com.br/painel/`:
carregue os vendedores do CRM, crie cada vendedor (o painel gera uma senha provisória) e passe usuário e senha para ele.

Atualizar depois: `sudo git -C /opt/comercial-sidnet pull`.

O endereço antigo `comercial.sidnet.com.br` continua respondendo junto com o novo (os dois nomes no
`server_name` do nginx e no Cloudflare Tunnel), porque os APKs antigos dos vendedores apontam para ele.
O APK novo já usa `portal.sidnet.com.br` e troca sozinho o endereço antigo salvo no celular.
Só desligue o nome antigo quando todos os celulares estiverem com o APK novo.

Log do repasse: `/var/lib/comercial-sidnet/repasse.log`.

## Campos que vão para o CRM

O mapeamento fica no servidor (`server/lib/repasse.php`): se a tela do CRM mudar, corrige-se lá, sem atualizar os celulares.
O servidor lê o formulário do CRM antes de cada envio e mantém os valores padrão dos campos que o app não preenche.

Venda / 2º plano → `novo-lead.php`: empresa_id, data_lead, nome, tipo_pessoa, cpf, whatsapp,
telefone_residencial, email, data_nascimento, logradouro, cidade, uf, referencia, vendedor_id,
origem, plano_interesse, valor (do catálogo), status (Venda Fechada), dia_vencimento, perfil_cliente
(Novo cliente / Cliente atual), tipo_servico (Instalação), observacao.

Upgrade → `servicos-vendedor.php` (lançar à mão): cliente, doc, data, vendedor_id, plano_de,
plano_para, valor_de, valor_para, observacao.

## Pendências

- Primeiro envio de verdade: fazer 1 lead de teste com o usuário do app, conferir no CRM e apagar.
  O repasse foi testado contra uma cópia das telas do CRM, não contra o CRM real.
- Fotos e vídeo continuam indo pelo WhatsApp (o servidor ainda não recebe anexos).
- Gerar o APK novo (versão 2.0) com esta tela e publicar no Navita.

## Comissões planilha (temporário)

Aba do painel com as comissões que vieram da planilha do Excel (paga uma vez só, marcando o mês do pagamento), até pagar todas.
Os dados dos clientes não ficam no GitHub: o admin importa o CSV em **Comissões planilha → Importar a planilha**.
Para tirar depois: apagar `server/lib/comissoes_planilha.php` e os trechos marcados `PLANILHA-TEMP` em
`server/public/painel/index.php`. A tabela `comissoes_planilha` pode ficar no banco sem atrapalhar.
