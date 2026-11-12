<?php
// Copie para /etc/comercial-sidnet/config.php (o install.sh faz isso) e preencha.
// Este arquivo tem senha: NUNCA coloque o config.php no git.
return [
    // banco do servidor do app (SQLite); a pasta precisa ser gravável pelo www-data
    'banco' => '/var/lib/comercial-sidnet/app.sqlite',

    // para onde as vendas vão: 'ixc' (IXC Provedor, pela API oficial) ou 'crm' (crm.sidnet.com.br)
    'destino' => 'ixc',

    // IXC Provedor. O token NÃO fica aqui: grave com  php server/bin/ixc.php token
    // Os ids abaixo saem de  php server/bin/ixc.php listar assuntos|setores|filiais|tipos-contrato|estagios
    'ixc' => [
        'url' => 'https://isp.sidnet.com.br',
        'filial_id' => 1,              // filial dos leads novos
        'assunto_upgrade' => 0,        // assunto do atendimento de upgrade (o processo dele abre a O.S.)
        'assunto_segundo' => 0,        // assunto do atendimento de 2º ponto
        'setor_id' => 0,               // setor (departamento) dos atendimentos
        'tipo_contrato_id' => 0,       // tipo do contrato do 2º ponto; 0 = o mesmo do contrato atual do cliente
        'login_padrao' => '{cpf}-{contrato}', // nome do login PPPoE do 2º ponto ({cpf}, {contrato}, {cliente})
        'login_autenticacao' => 'L',   // L = PPPoE
        'login_tipo_conexao' => 'F',   // F = fibra
        'estagio_lead' => 0,           // estágio do lead no funil do CRM do IXC (0 = o padrão)
        'origem_lead' => 'outros',
        'uf_padrao' => 'MG',
        // venda nova: 'prospect' = lead + prospecção (negociação e Vencemos no IXC); 'lead' = só o lead; 'negociacao' = até a negociação
        'venda_ate' => 'prospect',
        'responsavel_id' => 0,         // colaborador responsável no CRM (php server/bin/ixc.php listar colaboradores)
        'regiao_cobertura_id' => 4,    // tipo de região de cobertura do lead (4 = Região de Cobertura)
        'canal_padrao' => 12,
        'tipo_cliente_id' => 1,        // tipo de cliente do prospect (1 = Clientes em geral)          // canal de venda quando o app não manda (12 = PRESENCIAL)
    ],

    // CRM antigo (só se 'destino' => 'crm')
    'crm_url' => 'https://crm.sidnet.com.br',
    // crie no CRM um usuário só para o app (ex.: "app.vendas"), com permissão de Novo Lead e Upgrades
    'crm_usuario' => 'app.vendas',
    'crm_senha' => 'TROQUE-AQUI',
    'empresa_id' => 3,              // 3 = Sidnet (2 AG Link, 10 Gd Net, 1 Gns, 4 Live Net)
    'origem' => 'Outros',           // origem do lead no CRM
    'status_venda' => 'Venda Fechada',
    'uf_padrao' => 'MG',

    // senha do painel (https://SEU-SERVIDOR/painel/). Gere com:
    //   php -r 'echo password_hash("sua-senha", PASSWORD_DEFAULT), PHP_EOL;'
    'painel_senha_hash' => '',
];
