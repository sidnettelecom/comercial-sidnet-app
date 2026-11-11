<?php
/**
 * Vendedores pela linha de comando (o painel faz o mesmo).
 *   php bin/vendedor.php novo "NOME DO VENDEDOR" <id no CRM> <usuario>   cria e mostra a senha provisória
 *   php bin/vendedor.php senha <id> <usuario>                           nova senha provisória (derruba os logins)
 *   php bin/vendedor.php lista
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/vendedores.php';

[$_, $cmd] = $argv + [null, null];
try {
    if ($cmd === 'novo' && isset($argv[2], $argv[4])) {
        $senha = gerar_senha();
        $id = vendedor_novo($argv[2], $argv[3] ?? '', $argv[4], $senha);
        echo "Vendedor #$id criado. Usuário: " . usuario_valido($argv[4]) . "  Senha provisória: $senha\n";
    } elseif ($cmd === 'senha' && isset($argv[2], $argv[3])) {
        $senha = gerar_senha();
        vendedor_definir_acesso((int)$argv[2], $argv[3], $senha);
        echo "Usuário: " . usuario_valido($argv[3]) . "  Senha nova: $senha\n";
    } elseif ($cmd === 'lista') {
        foreach (db()->query('SELECT id, nome, usuario, crm_vendedor_id, ativo, ultimo_uso FROM vendedores ORDER BY nome') as $v) {
            printf("#%d  %-30s usuário=%-15s crm=%-6s %s  último uso: %s\n", $v['id'], $v['nome'], $v['usuario'] ?? '-', $v['crm_vendedor_id'], $v['ativo'] ? 'ativo' : 'BLOQUEADO', $v['ultimo_uso'] ?? '-');
        }
    } else {
        fwrite(STDERR, "Uso: novo \"NOME\" <crm_id> <usuario> | senha <id> <usuario> | lista\n");
        exit(1);
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
