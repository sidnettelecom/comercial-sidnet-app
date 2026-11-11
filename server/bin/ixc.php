<?php
/**
 * Ferramentas do IXC, para rodar no servidor:
 *
 *   php server/bin/ixc.php token        grava o token do usuário do app no IXC (digitado, sem aparecer na tela)
 *   php server/bin/ixc.php teste        confere se o servidor fala com o IXC usando o token
 *   php server/bin/ixc.php listar X     mostra id e nome, para preencher o config.php. X pode ser:
 *        assuntos | setores | filiais | planos | vendedores | tipos-contrato | estagios
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/ixc.php';

$cmd = $argv[1] ?? '';

if ($cmd === 'token') {
    $arq = ixc_token_arquivo();
    fwrite(STDOUT, "Cole o token do usuário do app no IXC (formato 12:abc...), e tecle Enter.\nEle não aparece na tela: ");
    $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) shell_exec('stty -echo');
    $tok = trim((string)fgets(STDIN));
    if ($tty) shell_exec('stty echo');
    fwrite(STDOUT, "\n");
    if (!preg_match('/^\d+:[A-Za-z0-9]{20,}$/', $tok)) {
        fwrite(STDERR, "Isso não parece um token do IXC (esperado: número, dois-pontos e o código). Nada foi gravado.\n");
        exit(1);
    }
    $antes = umask(0027);
    file_put_contents($arq, $tok . "\n");
    umask($antes);
    chmod($arq, 0640);
    @chgrp($arq, 'www-data');
    fwrite(STDOUT, "Token gravado em $arq (só root e www-data leem).\nAgora rode: php server/bin/ixc.php teste\n");
    exit(0);
}

try {
    if ($cmd === 'teste') {
        $l = ixc()->listar('cliente', 'id', '0', '>', 1);
        echo "OK: o IXC respondeu com o token" . ($l ? " (último cliente #{$l[0]['id']})" : '') . ".\n";
        $cfg = config()['ixc'] ?? [];
        foreach (['assunto_upgrade' => 'su_oss_assunto', 'assunto_segundo' => 'su_oss_assunto'] as $k => $t) {
            if (!empty($cfg[$k])) {
                $a = ixc()->um($t, 'id', (string)$cfg[$k]);
                echo "  $k = {$cfg[$k]}: " . ($a ? $a['assunto'] . (empty($a['id_processo']) || $a['id_processo'] === '0' ? '  (ATENÇÃO: sem processo ligado, não vai abrir O.S.)' : "  (processo #{$a['id_processo']})") : 'NÃO EXISTE') . "\n";
            } else {
                echo "  $k: ainda não preenchido no config.php\n";
            }
        }
        exit(0);
    }
    if ($cmd === 'listar') {
        $tabelas = [
            'assuntos' => ['su_oss_assunto', ['assunto']],
            'setores' => ['su_ticket_setor', ['setor', 'descricao']],
            'filiais' => ['filial', ['fantasia', 'razao']],
            'planos' => ['vd_contratos', ['nome']],
            'vendedores' => ['vendedor', ['nome']],
            'tipos-contrato' => ['cliente_contrato_tipo', ['tipo_contrato', 'descricao', 'nome']],
            'estagios' => ['crm_negociacoes_estagios', ['descricao', 'nome', 'estagio']],
        ];
        $o = $tabelas[$argv[2] ?? ''] ?? null;
        if (!$o) {
            fwrite(STDERR, 'Use: php server/bin/ixc.php listar ' . implode('|', array_keys($tabelas)) . "\n");
            exit(1);
        }
        foreach (ixc()->listar($o[0], 'id', '0', '>', 500, 'asc') as $r) {
            $nome = '';
            foreach ($o[1] as $c) {
                if (!empty($r[$c])) { $nome = $r[$c]; break; }
            }
            $extra = $o[0] === 'su_oss_assunto' ? '  (processo ' . (($r['id_processo'] ?? '') ?: '-') . ')' : '';
            $extra .= isset($r['ativo']) && $r['ativo'] === 'N' ? '  [inativo]' : '';
            printf("%6s  %s%s\n", $r['id'], $nome, $extra);
        }
        exit(0);
    }
} catch (ErroCrm $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDERR, "Use: php server/bin/ixc.php token | teste | listar <assuntos|setores|filiais|planos|vendedores|tipos-contrato|estagios>\n");
exit(1);
