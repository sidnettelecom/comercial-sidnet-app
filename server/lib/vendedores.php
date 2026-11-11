<?php
declare(strict_types=1);
require_once __DIR__ . '/base.php';

/** Texto aleatório legível (sem 0/O/1/l). */
function gerar_chave(int $tam = 24): string
{
    $alf = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < $tam; $i++) {
        $s .= $alf[random_int(0, strlen($alf) - 1)];
    }
    return $s;
}

/** Senha provisória para o gestor passar ao vendedor: 10 caracteres com letras e números. */
function gerar_senha(): string
{
    do {
        $s = gerar_chave(10);
    } while (problema_senha($s) !== '');
    return $s;
}

/** Cria o vendedor com usuário e senha. Lança RuntimeException com a mensagem para o gestor. */
function vendedor_novo(string $nome, string $crmId, string $usuario, string $senha): int
{
    $u = usuario_valido($usuario);
    if ($u === '') throw new RuntimeException('Usuário inválido: use de 3 a 40 letras minúsculas, números, ponto ou hífen.');
    if ($p = problema_senha($senha)) throw new RuntimeException($p);
    $q = db()->prepare('SELECT 1 FROM vendedores WHERE usuario = ?');
    $q->execute([$u]);
    if ($q->fetchColumn()) throw new RuntimeException("Já existe um vendedor com o usuário \"$u\".");
    db()->prepare('INSERT INTO vendedores (nome, crm_vendedor_id, usuario, senha_hash) VALUES (?, ?, ?, ?)')
        ->execute([up($nome), trim($crmId), $u, password_hash($senha, PASSWORD_DEFAULT)]);
    return (int)db()->lastInsertId();
}

/** Define usuário e senha (vendedor antigo, ou senha esquecida). Derruba os logins abertos nos celulares. */
function vendedor_definir_acesso(int $id, string $usuario, string $senha): void
{
    $u = usuario_valido($usuario);
    if ($u === '') throw new RuntimeException('Usuário inválido: use de 3 a 40 letras minúsculas, números, ponto ou hífen.');
    if ($p = problema_senha($senha)) throw new RuntimeException($p);
    $q = db()->prepare('SELECT 1 FROM vendedores WHERE usuario = ? AND id <> ?');
    $q->execute([$u, $id]);
    if ($q->fetchColumn()) throw new RuntimeException("Já existe outro vendedor com o usuário \"$u\".");
    db()->prepare('UPDATE vendedores SET usuario = ?, senha_hash = ?, chave_hash = NULL WHERE id = ?')
        ->execute([$u, password_hash($senha, PASSWORD_DEFAULT), $id]);
    vendedor_derrubar_sessoes($id);
}

/** Login do celular vale até 7 dias sem uso e no máximo 30 dias; depois o vendedor entra de novo. */
const SESSAO_INATIVA_DIAS = 7;
const SESSAO_MAX_DIAS = 30;

function sessoes_vencidas_apagar(): void
{
    db()->prepare("DELETE FROM sessoes WHERE ultimo_uso < datetime('now','localtime',?) OR criado_em < datetime('now','localtime',?)")
        ->execute(['-' . SESSAO_INATIVA_DIAS . ' days', '-' . SESSAO_MAX_DIAS . ' days']);
}

function vendedor_derrubar_sessoes(int $id): void
{
    db()->prepare('DELETE FROM sessoes WHERE vendedor_id = ?')->execute([$id]);
}

/**
 * Login do celular. Devolve [vendedor, token] ou lança RuntimeException com a mensagem para o vendedor.
 * O token fica no celular e vai em cada envio; o servidor guarda só o hash.
 */
function vendedor_login(string $usuario, string $senha, string $aparelho): array
{
    $u = strtolower(trim($usuario));
    $ip = ip_cliente();
    if ($min = travado('app:u:' . $u, 'app:ip:' . $ip)) {
        throw new RuntimeException("Muitas tentativas erradas. Tente de novo em $min min.");
    }
    $q = db()->prepare('SELECT * FROM vendedores WHERE usuario = ?');
    $q->execute([$u]);
    $v = $q->fetch();
    // compara mesmo quando o usuário não existe, para não dar pista de quais usuários existem
    $hash = ($v['senha_hash'] ?? '') !== '' ? $v['senha_hash'] : password_hash(gerar_chave(), PASSWORD_DEFAULT);
    $ok = password_verify($senha, $hash);
    if (!$v || !$ok) {
        anotar_falha('app:u:' . $u, 5);
        anotar_falha('app:ip:' . $ip, 20);
        throw new RuntimeException('Usuário ou senha incorretos.');
    }
    if (!$v['ativo']) {
        throw new RuntimeException('Seu acesso está bloqueado. Fale com o gestor.');
    }
    limpar_falhas('app:u:' . $u);
    if (password_needs_rehash($v['senha_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE vendedores SET senha_hash = ? WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $v['id']]);
    }
    $token = gerar_chave(40);
    db()->prepare('INSERT INTO sessoes (token_hash, vendedor_id, aparelho) VALUES (?, ?, ?)')
        ->execute([hash('sha256', $token), $v['id'], mb_substr($aparelho, 0, 120)]);
    return [$v, $token];
}
