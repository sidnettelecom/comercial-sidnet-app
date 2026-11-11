<?php
/**
 * Painel do servidor do app: vendas recebidas dos celulares, situação no CRM, vendedores e gestores.
 * Entrada com usuário e senha do gestor; 5 senhas erradas travam o usuário por 15 minutos.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/repasse.php';
require_once __DIR__ . '/../../lib/vendedores.php';

const PAINEL_INATIVO_MAX = 8 * 3600; // sai sozinho depois de 8 horas sem uso

session_name('painel_app');
session_start(['cookie_httponly' => true, 'cookie_secure' => conexao_https(), 'cookie_samesite' => 'Strict', 'use_strict_mode' => true]);
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

$cfg = config();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$msg = '';
$novaSenha = null; // ['quem' => ..., 'usuario' => ..., 'senha' => ...] mostrada uma vez

function confere_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Formulário expirado. Volte e tente de novo.');
    }
}
function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

// ---- entrada ----
if (isset($_POST['entrar'])) {
    confere_csrf();
    $u = strtolower(trim((string)($_POST['usuario'] ?? '')));
    $ip = ip_cliente();
    if ($min = travado('painel:u:' . $u, 'painel:ip:' . $ip)) {
        $msg = "Muitas tentativas erradas. Tente de novo em $min min.";
    } else {
        $q = db()->prepare('SELECT * FROM gestores WHERE usuario = ?');
        $q->execute([$u]);
        $g = $q->fetch();
        $hash = $g['senha_hash'] ?? password_hash(gerar_chave(), PASSWORD_DEFAULT);
        if ($g && $g['ativo'] && password_verify((string)($_POST['senha'] ?? ''), $hash)) {
            limpar_falhas('painel:u:' . $u);
            session_regenerate_id(true);
            $_SESSION['gestor'] = ['id' => (int)$g['id'], 'usuario' => $g['usuario'], 'nome' => $g['nome']];
            $_SESSION['visto'] = time();
            db()->prepare("UPDATE gestores SET ultimo_acesso = datetime('now','localtime') WHERE id = ?")->execute([$g['id']]);
            registrar($g['usuario'], 'entrou', $ip);
            header('Location: ./');
            exit;
        }
        anotar_falha('painel:u:' . $u, 5);
        anotar_falha('painel:ip:' . $ip, 20);
        sleep(1);
        $msg = 'Usuário ou senha incorretos.';
    }
}
if (isset($_GET['sair'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}
$gestor = $_SESSION['gestor'] ?? null;
if ($gestor) {
    // sessão parada há muito tempo, ou gestor bloqueado/apagado depois que entrou
    $q = db()->prepare('SELECT ativo FROM gestores WHERE id = ?');
    $q->execute([$gestor['id']]);
    if (time() - (int)($_SESSION['visto'] ?? 0) > PAINEL_INATIVO_MAX || !$q->fetchColumn()) {
        $_SESSION = [];
        session_destroy();
        session_start(['cookie_httponly' => true, 'cookie_secure' => conexao_https(), 'cookie_samesite' => 'Strict', 'use_strict_mode' => true]);
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        $gestor = null;
        $msg = 'Sua sessão terminou. Entre de novo.';
    } else {
        $_SESSION['visto'] = time();
    }
}
$logado = (bool)$gestor;
$quem = $gestor['usuario'] ?? '';

// ---- ações ----
if ($logado && $_SERVER['REQUEST_METHOD'] === 'POST') {
    confere_csrf();
    $a = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($a === 'tentar') {
            db()->prepare("UPDATE vendas SET status='fila', erro='', tentativas=0 WHERE id=? AND status<>'crm'")->execute([$id]);
            $msg = 'Venda #' . $id . ' voltou para a fila.';
        } elseif ($a === 'forcar') {
            db()->prepare("UPDATE vendas SET status='forcar', erro='', tentativas=0 WHERE id=? AND status<>'crm'")->execute([$id]);
            $msg = 'Venda #' . $id . ' vai ser enviada mesmo com aviso de duplicado.';
        } elseif ($a === 'descartar') {
            db()->prepare("UPDATE vendas SET status='descartada' WHERE id=? AND status<>'crm'")->execute([$id]);
            $msg = 'Venda #' . $id . ' descartada (não vai para o CRM).';
        } elseif ($a === 'excluir') {
            $q = db()->prepare('SELECT v.*, d.nome AS vendedor FROM vendas v JOIN vendedores d ON d.id = v.vendedor_id WHERE v.id = ?');
            $q->execute([$id]);
            if ($v = $q->fetch()) {
                $pdo = db();
                $pdo->beginTransaction();
                $pdo->prepare('INSERT OR REPLACE INTO excluidas (id_app, nome, por) VALUES (?, ?, ?)')->execute([$v['id_app'], $v['nome'], $quem]);
                $pdo->prepare('DELETE FROM vendas WHERE id = ?')->execute([$id]);
                $pdo->commit();
                registrar($quem, 'excluiu venda', "#{$v['id']} {$v['nome']} (vendedor {$v['vendedor']}, situação {$v['status']}" . ($v['crm_id'] ? ", {$v['crm_id']} no CRM" : '') . ')');
                $msg = "Venda #{$v['id']} de {$v['nome']} excluída do painel." . ($v['status'] === 'crm' ? " Ela já tinha entrado no CRM ({$v['crm_id']}): apague lá também, se for o caso." : '');
            }
        } elseif ($a === 'enviar_agora') {
            $r = processar_fila_com_trava(true);
            $msg = $r ? implode(' | ', $r) : 'Nada na fila.';
        } elseif ($a === 'vend_novo') {
            $senha = gerar_senha();
            vendedor_novo((string)($_POST['nome'] ?? ''), (string)($_POST['crm_vendedor_id'] ?? ''), (string)($_POST['usuario'] ?? ''), $senha);
            $novaSenha = ['quem' => up($_POST['nome'] ?? ''), 'usuario' => usuario_valido((string)$_POST['usuario']), 'senha' => $senha];
            registrar($quem, 'criou vendedor', up($_POST['nome'] ?? '') . ' (' . $novaSenha['usuario'] . ')');
        } elseif ($a === 'vend_senha') {
            $senha = gerar_senha();
            vendedor_definir_acesso($id, (string)($_POST['usuario'] ?? ''), $senha);
            $n = db()->query('SELECT nome FROM vendedores WHERE id=' . $id)->fetchColumn();
            $novaSenha = ['quem' => $n, 'usuario' => usuario_valido((string)$_POST['usuario']), 'senha' => $senha];
            registrar($quem, 'nova senha de vendedor', (string)$n);
        } elseif ($a === 'vend_crm') {
            db()->prepare('UPDATE vendedores SET crm_vendedor_id=? WHERE id=?')->execute([trim((string)$_POST['crm_vendedor_id']), $id]);
            $msg = 'Vendedor atualizado.';
        } elseif ($a === 'vend_ativo') {
            db()->prepare('UPDATE vendedores SET ativo = 1 - ativo WHERE id=?')->execute([$id]);
            vendedor_derrubar_sessoes($id);
            registrar($quem, 'bloqueou/liberou vendedor', '#' . $id);
            $msg = 'Acesso do vendedor alterado. Se foi bloqueio, o celular dele sai na próxima conexão.';
        } elseif ($a === 'crm_vendedores') {
            $lista = Crm::opcoes(crm()->abrir('/novo-lead.php'), 'vendedor_id');
            file_put_contents(dirname($cfg['banco']) . '/crm-vendedores.json', json_encode($lista, JSON_UNESCAPED_UNICODE));
            $msg = count($lista) . ' vendedores carregados do CRM.';
        } elseif ($a === 'gest_novo') {
            $u = usuario_valido((string)($_POST['usuario'] ?? ''));
            if ($u === '' || up($_POST['nome'] ?? '') === '') throw new RuntimeException('Preencha nome e usuário (3 a 40 letras minúsculas, números, ponto ou hífen).');
            $q = db()->prepare('SELECT 1 FROM gestores WHERE usuario = ?');
            $q->execute([$u]);
            if ($q->fetchColumn()) throw new RuntimeException("Já existe o gestor \"$u\".");
            $senha = gerar_senha();
            db()->prepare('INSERT INTO gestores (usuario, nome, senha_hash) VALUES (?, ?, ?)')->execute([$u, up($_POST['nome']), password_hash($senha, PASSWORD_DEFAULT)]);
            $novaSenha = ['quem' => up($_POST['nome']), 'usuario' => $u, 'senha' => $senha];
            registrar($quem, 'criou gestor', $u);
        } elseif ($a === 'gest_ativo' && $id !== $gestor['id']) {
            db()->prepare('UPDATE gestores SET ativo = 1 - ativo WHERE id = ?')->execute([$id]);
            registrar($quem, 'bloqueou/liberou gestor', '#' . $id);
            $msg = 'Acesso do gestor alterado.';
        } elseif ($a === 'minha_senha') {
            $q = db()->prepare('SELECT senha_hash FROM gestores WHERE id = ?');
            $q->execute([$gestor['id']]);
            $nova = (string)($_POST['nova'] ?? '');
            if (!password_verify((string)($_POST['atual'] ?? ''), (string)$q->fetchColumn())) throw new RuntimeException('A senha atual não confere.');
            if ($p = problema_senha($nova)) throw new RuntimeException($p);
            if ($nova !== (string)($_POST['nova2'] ?? '')) throw new RuntimeException('A confirmação não é igual à senha nova.');
            db()->prepare('UPDATE gestores SET senha_hash = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $gestor['id']]);
            registrar($quem, 'trocou a própria senha');
            $msg = 'Senha trocada.';
        }
    } catch (Throwable $e) {
        $msg = $e instanceof ErroCrm ? 'Não consegui falar com o CRM: ' . $e->getMessage() : $e->getMessage();
    }
}

$p = $_GET['p'] ?? 'vendas';
$NOMES = ['venda' => 'Venda', 'segundo' => '2º plano', 'upgrade' => 'Upgrade'];
$SITUACAO = ['fila' => ['Na fila', 'q'], 'forcar' => ['Na fila', 'q'], 'tentar' => ['Tentando de novo', 'q'], 'crm' => ['No CRM', 'ok'], 'erro' => ['Erro', 'er'], 'descartada' => ['Descartada', 'mu']];
$crmVend = [];
$arqV = dirname($cfg['banco']) . '/crm-vendedores.json';
if (is_file($arqV)) {
    foreach (json_decode((string)file_get_contents($arqV), true) ?: [] as $o) {
        $crmVend[$o['valor']] = $o['texto'];
    }
}
$aba = fn(string $k, string $t) => '<a href="?p=' . $k . '"' . ($p === $k ? ' aria-current="page"' : '') . '>' . $t . '</a>';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Painel Comercial + Sidnet</title>
<style>
/* Painel de operação: barra no topo, uma tabela por tela, estado em selos coloridos */
:root{--bg:#eef3f4;--surface:#fff;--ink:#122a30;--muted:#5b7177;--line:#d3dfe2;--brand:#0a6fa3;--brand-ink:#fff;
--q:#f2a516;--ok:#1d7a46;--ok-bg:#e3f3e9;--er:#b42318;--er-bg:#fdecea;
--f:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;--mono:ui-monospace,Menlo,Consolas,monospace}
@media (prefers-color-scheme:dark){:root{--bg:#0c1719;--surface:#14252a;--ink:#e4eef0;--muted:#93aab0;--line:#27414a;--brand:#3fb3c8;--brand-ink:#06272e;
--q:#f5b73d;--ok:#5fd393;--ok-bg:#12321f;--er:#ff8a7a;--er-bg:#3a1714;color-scheme:dark}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.45 var(--f)}
header{background:var(--brand);color:var(--brand-ink);padding:12px max(16px,env(safe-area-inset-left))}
header .in{max-width:1200px;margin:0 auto;display:flex;gap:16px;align-items:center;flex-wrap:wrap}
header b{font-size:16px;margin-right:auto}header a{color:inherit;text-decoration:none;opacity:.85;font-weight:600}header a[aria-current]{opacity:1;text-decoration:underline}
main{max-width:1200px;margin:0 auto;padding:16px max(16px,env(safe-area-inset-right)) 40px max(16px,env(safe-area-inset-left));display:grid;gap:14px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:14px}
.msg{background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--brand);border-radius:8px;padding:10px 12px;overflow-wrap:anywhere}
.chave{font:600 18px/1.3 var(--mono);letter-spacing:.04em;background:var(--bg);padding:8px 10px;border-radius:6px;user-select:all;display:inline-block;margin:6px 0}
.tw{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:760px}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:top}th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
td.n{font-variant-numeric:tabular-nums;white-space:nowrap}
.pill{display:inline-block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:3px 8px;border-radius:20px;white-space:nowrap}
.pill.q{background:color-mix(in srgb,var(--q) 25%,var(--surface))}.pill.ok{background:var(--ok-bg);color:var(--ok)}.pill.er{background:var(--er-bg);color:var(--er)}.pill.mu{background:var(--bg);color:var(--muted)}
.err{color:var(--er);font-size:12.5px;max-width:380px}
button,input,select{font:inherit;color:inherit}input,select{padding:7px 9px;border:1px solid var(--line);border-radius:6px;background:var(--bg)}
button{padding:6px 11px;border-radius:6px;border:1px solid var(--line);background:var(--surface);cursor:pointer;font-weight:600}
button.pri{background:var(--brand);color:var(--brand-ink);border-color:var(--brand)}button:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid color-mix(in srgb,var(--brand) 45%,transparent)}
form.in{display:inline}.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
details summary{cursor:pointer;color:var(--brand);font-size:12.5px}pre{white-space:pre-wrap;font:12px/1.4 var(--mono);max-width:420px;overflow-wrap:anywhere}
.small{color:var(--muted);font-size:12.5px}
a.bt{display:inline-block;padding:6px 11px;border-radius:6px;border:1px solid var(--line);background:var(--surface);font-weight:600;text-decoration:none}
button.del,a.bt.del{color:var(--er);border-color:color-mix(in srgb,var(--er) 40%,var(--line))}button.del.pri{background:var(--er);color:#fff;border-color:var(--er)}
.login{max-width:380px;display:grid;gap:8px}.login input{width:100%}h2{margin:0 0 8px;font-size:16px}
</style>
</head>
<body>
<header><div class="in"><b>Comercial + Sidnet · Painel</b>
<?php if ($logado): ?><?= $aba('vendas', 'Vendas') . $aba('vendedores', 'Vendedores') . $aba('gestores', 'Gestores') ?><a href="?sair=1">Sair (<?= h($quem) ?>)</a><?php endif ?>
</div></header>
<main>
<?php if ($msg): ?><div class="msg" role="status"><?= h($msg) ?></div><?php endif ?>
<?php if ($novaSenha): ?>
  <div class="card"><b>Acesso de <?= h($novaSenha['quem']) ?></b><br>
    Usuário: <span class="chave"><?= h($novaSenha['usuario']) ?></span> Senha: <span class="chave"><?= h($novaSenha['senha']) ?></span><br>
    <span class="small">Anote e entregue agora: a senha não aparece de novo. Se perder, gere outra.</span></div>
<?php endif ?>

<?php if (!$logado): ?>
  <form method="post" class="card login">
    <?= csrf_campo() ?>
    <h2>Entrar no painel</h2>
    <label for="usuario">Usuário</label><input id="usuario" name="usuario" autocomplete="username" autocapitalize="none" autofocus required>
    <label for="senha">Senha</label><input id="senha" type="password" name="senha" autocomplete="current-password" required>
    <button class="pri" name="entrar" value="1">Entrar</button>
  </form>

<?php elseif ($p === 'excluir'):
    $q = db()->prepare('SELECT v.*, d.nome AS vendedor FROM vendas v JOIN vendedores d ON d.id = v.vendedor_id WHERE v.id = ?');
    $q->execute([(int)($_GET['id'] ?? 0)]);
    $v = $q->fetch(); ?>
  <?php if (!$v): ?><div class="msg">Essa venda não existe mais.</div><p><a href="?p=vendas">Voltar às vendas</a></p>
  <?php else: [$rot] = $SITUACAO[$v['status']] ?? [$v['status']]; ?>
  <form method="post" action="?p=vendas" class="card" style="max-width:560px;display:grid;gap:10px">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
    <h2>Excluir a venda #<?= (int)$v['id'] ?>?</h2>
    <p style="margin:0"><b><?= h($v['nome']) ?></b> · <?= h($NOMES[$v['tipo']] ?? $v['tipo']) ?> · vendedor <?= h($v['vendedor']) ?> · recebida em <?= h($v['recebido_em']) ?> · <?= h($rot) ?> <?= h($v['crm_id']) ?></p>
    <p class="small" style="margin:0">Ela some do painel e o celular do vendedor passa a mostrar "Excluída pelo gestor". Não dá para desfazer.
      <?php if ($v['status'] === 'crm'): ?><br><b>Ela já entrou no CRM (<?= h($v['crm_id']) ?>).</b> Excluir aqui não apaga lá: apague no CRM também, se for o caso.<?php endif ?></p>
    <div class="row"><button class="del pri">Excluir venda</button><a href="?p=vendas">Cancelar</a></div>
  </form>
  <?php endif ?>

<?php elseif ($p === 'vendedores'): ?>
  <form method="post" class="card row">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="vend_novo">
    <label for="nome">Novo vendedor</label><input id="nome" name="nome" placeholder="NOME COMPLETO" required>
    <label for="nusu">Usuário</label><input id="nusu" name="usuario" placeholder="ex.: hellen" autocapitalize="none" size="12" required pattern="[a-z0-9._-]{3,40}">
    <label for="crmv">Vendedor no CRM</label>
    <?php if ($crmVend): ?><select id="crmv" name="crm_vendedor_id"><option value="">escolha</option><?php foreach ($crmVend as $v => $t): ?><option value="<?= h((string)$v) ?>"><?= h($t) ?></option><?php endforeach ?></select>
    <?php else: ?><input id="crmv" name="crm_vendedor_id" placeholder="id no CRM" size="8"><?php endif ?>
    <button class="pri">Criar e gerar senha</button>
  </form>
  <form method="post" class="row"><?= csrf_campo() ?><input type="hidden" name="acao" value="crm_vendedores">
    <button>Carregar vendedores do CRM</button><span class="small">Lê a lista do campo "Vendedor" do Novo Lead. Não grava nada no CRM.</span></form>
  <div class="card tw"><table>
    <tr><th>Vendedor</th><th>Usuário e senha</th><th>No CRM</th><th>Acesso</th><th>Último uso</th><th></th></tr>
    <?php foreach (db()->query('SELECT * FROM vendedores ORDER BY ativo DESC, nome') as $v): ?>
    <tr><td><?= h($v['nome']) ?></td>
      <td><form method="post" class="row"><?= csrf_campo() ?><input type="hidden" name="acao" value="vend_senha"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
        <input name="usuario" value="<?= h($v['usuario'] ?? '') ?>" placeholder="usuário" size="10" autocapitalize="none" required aria-label="Usuário de <?= h($v['nome']) ?>">
        <button><?= ($v['usuario'] ?? '') === '' ? 'Criar acesso' : 'Gerar senha nova' ?></button></form>
        <?php if (($v['usuario'] ?? '') === ''): ?><span class="small">Ainda usa a chave antiga. Crie usuário e senha.</span><?php endif ?></td>
      <td><form method="post" class="row"><?= csrf_campo() ?><input type="hidden" name="acao" value="vend_crm"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
        <?php if ($crmVend): ?><select name="crm_vendedor_id" aria-label="Vendedor no CRM"><option value="">não ligado</option><?php foreach ($crmVend as $cv => $t): ?><option value="<?= h((string)$cv) ?>" <?= (string)$cv === $v['crm_vendedor_id'] ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach ?></select>
        <?php else: ?><input name="crm_vendedor_id" value="<?= h($v['crm_vendedor_id']) ?>" size="8" aria-label="id no CRM"><?php endif ?>
        <button>Salvar</button></form></td>
      <td><?= $v['ativo'] ? '<span class="pill ok">Ativo</span>' : '<span class="pill er">Bloqueado</span>' ?></td>
      <td class="n"><?= h($v['ultimo_uso'] ?? '—') ?></td>
      <td><form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="vend_ativo"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button class="<?= $v['ativo'] ? 'del' : '' ?>"><?= $v['ativo'] ? 'Bloquear' : 'Liberar' ?></button></form></td></tr>
    <?php endforeach ?>
  </table></div>
  <p class="small">Senha gerada aqui é provisória e aparece uma vez só. Gerar senha nova tira o vendedor de todos os celulares em que ele estava logado.</p>

<?php elseif ($p === 'gestores'): ?>
  <div class="card tw"><table>
    <tr><th>Gestor</th><th>Usuário</th><th>Acesso</th><th>Último acesso</th><th></th></tr>
    <?php foreach (db()->query('SELECT * FROM gestores ORDER BY ativo DESC, nome') as $g): ?>
    <tr><td><?= h($g['nome']) ?></td><td><?= h($g['usuario']) ?></td>
      <td><?= $g['ativo'] ? '<span class="pill ok">Ativo</span>' : '<span class="pill er">Bloqueado</span>' ?></td><td class="n"><?= h($g['ultimo_acesso'] ?? '—') ?></td>
      <td><?php if ((int)$g['id'] !== $gestor['id']): ?><form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="gest_ativo"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button class="<?= $g['ativo'] ? 'del' : '' ?>"><?= $g['ativo'] ? 'Bloquear' : 'Liberar' ?></button></form><?php else: ?><span class="small">você</span><?php endif ?></td></tr>
    <?php endforeach ?>
  </table></div>
  <form method="post" class="card row"><?= csrf_campo() ?><input type="hidden" name="acao" value="gest_novo">
    <label for="gnome">Novo gestor</label><input id="gnome" name="nome" placeholder="NOME" required>
    <label for="gusu">Usuário</label><input id="gusu" name="usuario" autocapitalize="none" size="12" required pattern="[a-z0-9._-]{3,40}">
    <button class="pri">Criar e gerar senha</button></form>
  <form method="post" class="card login"><?= csrf_campo() ?><input type="hidden" name="acao" value="minha_senha">
    <h2>Trocar minha senha</h2>
    <label for="atual">Senha atual</label><input id="atual" name="atual" type="password" autocomplete="current-password" required>
    <label for="nova">Senha nova (8+ caracteres, letras e números)</label><input id="nova" name="nova" type="password" autocomplete="new-password" minlength="8" required>
    <label for="nova2">Repita a senha nova</label><input id="nova2" name="nova2" type="password" autocomplete="new-password" minlength="8" required>
    <button class="pri">Trocar senha</button></form>
  <div class="card tw"><h2>Últimas ações</h2><table>
    <tr><th>Quando</th><th>Quem</th><th>O quê</th></tr>
    <?php foreach (db()->query('SELECT * FROM registro ORDER BY id DESC LIMIT 40') as $l): ?>
    <tr><td class="n"><?= h($l['em']) ?></td><td><?= h($l['quem']) ?></td><td><?= h($l['acao'] . ($l['detalhe'] !== '' ? ': ' . $l['detalhe'] : '')) ?></td></tr>
    <?php endforeach ?>
  </table></div>

<?php else:
    $filtro = $_GET['st'] ?? '';
    $where = match ($filtro) { 'erro' => "WHERE v.status='erro'", 'fila' => "WHERE v.status IN ('fila','forcar','tentar')", 'crm' => "WHERE v.status='crm'", default => '' };
    $cont = db()->query("SELECT status, COUNT(*) n FROM vendas GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $nFila = ($cont['fila'] ?? 0) + ($cont['forcar'] ?? 0) + ($cont['tentar'] ?? 0);
?>
  <div class="row">
    <a href="?p=vendas"><span class="pill mu">Todas</span></a>
    <a href="?p=vendas&amp;st=fila"><span class="pill q">Na fila <?= $nFila ?></span></a>
    <a href="?p=vendas&amp;st=erro"><span class="pill er">Com erro <?= (int)($cont['erro'] ?? 0) ?></span></a>
    <a href="?p=vendas&amp;st=crm"><span class="pill ok">No CRM <?= (int)($cont['crm'] ?? 0) ?></span></a>
    <form method="post" class="in" style="margin-left:auto"><?= csrf_campo() ?><input type="hidden" name="acao" value="enviar_agora"><button class="pri">Enviar fila ao CRM agora</button></form>
  </div>
  <div class="card tw"><table>
    <tr><th>#</th><th>Recebido</th><th>Tipo</th><th>Cliente</th><th>Vendedor</th><th>CRM</th><th></th></tr>
    <?php foreach (db()->query("SELECT v.*, d.nome AS vendedor FROM vendas v JOIN vendedores d ON d.id=v.vendedor_id $where ORDER BY v.id DESC LIMIT 300") as $v):
        [$rot, $cls] = $SITUACAO[$v['status']] ?? [$v['status'], 'mu']; $r = json_decode($v['dados'], true) ?: []; ?>
    <tr><td class="n"><?= (int)$v['id'] ?></td><td class="n"><?= h($v['recebido_em']) ?></td><td><?= h($NOMES[$v['tipo']] ?? $v['tipo']) ?></td>
      <td><?= h($v['nome']) ?><br><span class="small"><?= h(($r['cpf'] ?? '') . ' · ' . ($r['tel1'] ?? '') . ' · ' . ($r['cidade'] ?? '')) ?></span>
        <details><summary>ver cadastro</summary><pre><?= h(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></details></td>
      <td><?= h($v['vendedor']) ?></td>
      <td><span class="pill <?= $cls ?>"><?= h($rot) ?></span> <?= h($v['crm_id']) ?><?php if ($v['erro']): ?><div class="err"><?= h($v['erro']) ?></div><?php endif ?></td>
      <td class="row"><?php if (in_array($v['status'], ['erro', 'tentar'], true)): ?>
        <form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="tentar"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button>Tentar de novo</button></form>
        <?php if (stripos($v['erro'], 'duplicado') !== false): ?><form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="forcar"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button>Enviar mesmo assim</button></form><?php endif ?>
        <form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="descartar"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button>Descartar</button></form>
      <?php endif ?>
        <a class="bt del" href="?p=excluir&amp;id=<?= (int)$v['id'] ?>">Excluir</a></td></tr>
    <?php endforeach ?>
  </table></div>
<?php endif ?>
</main>
</body>
</html>
