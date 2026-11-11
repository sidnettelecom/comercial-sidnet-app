<?php
/**
 * Gestores do painel pela linha de comando (útil se esquecer a senha do painel).
 *   php bin/gestor.php novo <usuario> "NOME"     cria e mostra a senha provisória
 *   php bin/gestor.php senha <usuario>           senha provisória nova
 *   php bin/gestor.php lista
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/vendedores.php';

[$_, $cmd, $usuario] = $argv + [null, null, null];
$u = usuario_valido((string)$usuario);
if ($cmd === 'novo' && $u !== '') {
    $senha = gerar_senha();
    db()->prepare('INSERT INTO gestores (usuario, nome, senha_hash) VALUES (?, ?, ?)')->execute([$u, up($argv[3] ?? $u), password_hash($senha, PASSWORD_DEFAULT)]);
    echo "Gestor criado. Usuário: $u  Senha provisória: $senha\n";
} elseif ($cmd === 'senha' && $u !== '') {
    $senha = gerar_senha();
    $q = db()->prepare('UPDATE gestores SET senha_hash = ?, ativo = 1 WHERE usuario = ?');
    $q->execute([password_hash($senha, PASSWORD_DEFAULT), $u]);
    echo $q->rowCount() ? "Usuário: $u  Senha nova: $senha\n" : "Gestor \"$u\" não existe.\n";
    limpar_falhas('painel:u:' . $u);
} elseif ($cmd === 'lista') {
    foreach (db()->query('SELECT usuario, nome, ativo, ultimo_acesso FROM gestores ORDER BY usuario') as $g) {
        printf("%-15s %-30s %s  último acesso: %s\n", $g['usuario'], $g['nome'], $g['ativo'] ? 'ativo' : 'BLOQUEADO', $g['ultimo_acesso'] ?? '-');
    }
} else {
    fwrite(STDERR, "Uso: novo <usuario> \"NOME\" | senha <usuario> | lista\n");
    exit(1);
}
