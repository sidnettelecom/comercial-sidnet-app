<?php
// Copie para /etc/comercial-sidnet/config.php (o install.sh faz isso) e preencha.
// Este arquivo tem senha: NUNCA coloque o config.php no git.
return [
    // banco do servidor do app (SQLite); a pasta precisa ser gravável pelo www-data
    'banco' => '/var/lib/comercial-sidnet/app.sqlite',

    // CRM para onde as vendas vão
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
