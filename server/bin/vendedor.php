<?php
/**
 * Cadastro de vendedores pela linha de comando (o painel faz o mesmo).
 *   php bin/vendedor.php novo "NOME DO VENDEDOR" <id do vendedor no CRM>
 *   php bin/vendedor.php chave <id>        gera chave nova (a antiga para de funcionar)
 *   php bin/vendedor.php lista
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/vendedores.php';

[$_, $cmd] = $argv + [null, null];
if ($cmd === 'novo' && isset($argv[2])) {
    [$id, $chave] = vendedor_novo($argv[2], $argv[3] ?? '');
    echo "Vendedor #$id criado. Chave de acesso (mostrada só agora): $chave\n";
} elseif ($cmd === 'chave' && isset($argv[2])) {
    echo 'Chave nova: ' . vendedor_nova_chave((int)$argv[2]) . "\n";
} elseif ($cmd === 'lista') {
    foreach (db()->query('SELECT id, nome, crm_vendedor_id, ativo, ultimo_uso FROM vendedores ORDER BY nome') as $v) {
        printf("#%d  %-30s crm=%-6s %s  último uso: %s\n", $v['id'], $v['nome'], $v['crm_vendedor_id'], $v['ativo'] ? 'ativo' : 'BLOQUEADO', $v['ultimo_uso'] ?? '-');
    }
} else {
    fwrite(STDERR, "Uso: novo \"NOME\" <crm_id> | chave <id> | lista\n");
    exit(1);
}
