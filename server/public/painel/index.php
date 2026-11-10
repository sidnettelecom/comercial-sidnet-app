<?php
/**
 * Painel do servidor do app: vendas recebidas dos celulares, situação no CRM e vendedores.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/repasse.php';
require_once __DIR__ . '/../../lib/vendedores.php';

session_name('painel_app');
session_start(['cookie_httponly' => true, 'cookie_secure' => !empty($_SERVER['HTTPS']), 'cookie_samesite' => 'Strict']);
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'");

$cfg = config();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$msg = '';
$chaveNova = null;

function confere_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Formulário expirado. Volte e tente de novo.');
    }
}

// ---- entrada ----
if (isset($_POST['entrar'])) {
    confere_csrf();
    $ok = $cfg['painel_senha_hash'] !== '' && password_verify((string)($_POST['senha'] ?? ''), $cfg['painel_senha_hash']);
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        header('Location: ./');
        exit;
    }
    sleep(1);
    $msg = 'Senha incorreta.';
}
if (isset($_GET['sair'])) {
    session_destroy();
    header('Location: ./');
    exit;
}
$logado = !empty($_SESSION['ok']);

// ---- ações ----
if ($logado && $_SERVER['REQUEST_METHOD'] === 'POST') {
    confere_csrf();
    $a = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($a === 'tentar') {
        db()->prepare("UPDATE vendas SET status='fila', erro='', tentativas=0 WHERE id=? AND status<>'crm'")->execute([$id]);
        $msg = 'Venda #' . $id . ' voltou para a fila.';
    } elseif ($a === 'forcar') {
        db()->prepare("UPDATE vendas SET status='forcar', erro='', tentativas=0 WHERE id=? AND status<>'crm'")->execute([$id]);
        $msg = 'Venda #' . $id . ' vai ser enviada mesmo com aviso de duplicado.';
    } elseif ($a === 'descartar') {
        db()->prepare("UPDATE vendas SET status='descartada' WHERE id=? AND status<>'crm'")->execute([$id]);
        $msg = 'Venda #' . $id . ' descartada (não vai para o CRM).';
    } elseif ($a === 'enviar_agora') {
        $r = processar_fila();
        $msg = $r ? implode(' | ', $r) : 'Nada na fila.';
    } elseif ($a === 'vend_novo' && up($_POST['nome'] ?? '') !== '') {
        [$vid, $chaveNova] = vendedor_novo($_POST['nome'], (string)($_POST['crm_vendedor_id'] ?? ''));
        $chaveNova = ['nome' => up($_POST['nome']), 'chave' => $chaveNova];
    } elseif ($a === 'vend_chave') {
        $n = db()->query('SELECT nome FROM vendedores WHERE id=' . $id)->fetchColumn();
        $chaveNova = ['nome' => $n, 'chave' => vendedor_nova_chave($id)];
    } elseif ($a === 'vend_crm') {
        db()->prepare('UPDATE vendedores SET crm_vendedor_id=? WHERE id=?')->execute([trim((string)$_POST['crm_vendedor_id']), $id]);
        $msg = 'Vendedor atualizado.';
    } elseif ($a === 'vend_ativo') {
        db()->prepare('UPDATE vendedores SET ativo = 1 - ativo WHERE id=?')->execute([$id]);
        $msg = 'Acesso do vendedor alterado.';
    } elseif ($a === 'crm_vendedores') {
        try {
            $lista = Crm::opcoes(crm()->abrir('/novo-lead.php'), 'vendedor_id');
            file_put_contents(dirname($cfg['banco']) . '/crm-vendedores.json', json_encode($lista, JSON_UNESCAPED_UNICODE));
            $msg = count($lista) . ' vendedores carregados do CRM.';
        } catch (Throwable $e) {
            $msg = 'Não consegui ler os vendedores do CRM: ' . $e->getMessage();
        }
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
</style>
</head>
<body>
<header><div class="in"><b>Comercial + Sidnet · Painel</b>
<?php if ($logado): ?><a href="?p=vendas" <?= $p === 'vendas' ? 'aria-current="page"' : '' ?>>Vendas</a><a href="?p=vendedores" <?= $p === 'vendedores' ? 'aria-current="page"' : '' ?>>Vendedores</a><a href="?sair=1">Sair</a><?php endif ?>
</div></header>
<main>
<?php if ($msg): ?><div class="msg"><?= h($msg) ?></div><?php endif ?>

<?php if (!$logado): ?>
  <form method="post" class="card row" style="max-width:420px">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <label for="senha">Senha do painel</label>
    <input id="senha" type="password" name="senha" autofocus required>
    <button class="pri" name="entrar" value="1">Entrar</button>
    <?php if ($cfg['painel_senha_hash'] === ''): ?><p class="small">Defina painel_senha_hash no config.php para entrar.</p><?php endif ?>
  </form>

<?php elseif ($p === 'vendedores'): ?>
  <?php if ($chaveNova): ?>
    <div class="card"><b>Chave de acesso de <?= h($chaveNova['nome']) ?></b><br>
      <span class="chave"><?= h($chaveNova['chave']) ?></span><br>
      <span class="small">Anote agora: ela não aparece de novo. No celular: Ajustes → Chave de acesso do app.</span></div>
  <?php endif ?>
  <form method="post" class="card row">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="acao" value="vend_novo">
    <label for="nome">Novo vendedor</label><input id="nome" name="nome" placeholder="NOME COMPLETO" required>
    <label for="crmv">Vendedor no CRM</label>
    <?php if ($crmVend): ?><select id="crmv" name="crm_vendedor_id"><option value="">escolha</option><?php foreach ($crmVend as $v => $t): ?><option value="<?= h((string)$v) ?>"><?= h($t) ?></option><?php endforeach ?></select>
    <?php else: ?><input id="crmv" name="crm_vendedor_id" placeholder="id no CRM" size="8"><?php endif ?>
    <button class="pri">Criar e gerar chave</button>
  </form>
  <form method="post" class="row"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="acao" value="crm_vendedores">
    <button>Carregar vendedores do CRM</button><span class="small">Lê a lista do campo "Vendedor" do Novo Lead. Não grava nada no CRM.</span></form>
  <div class="card tw"><table>
    <tr><th>Vendedor</th><th>No CRM</th><th>Acesso</th><th>Último envio</th><th></th></tr>
    <?php foreach (db()->query('SELECT * FROM vendedores ORDER BY ativo DESC, nome') as $v): ?>
    <tr><td><?= h($v['nome']) ?></td>
      <td><form method="post" class="row"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="acao" value="vend_crm"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
        <?php if ($crmVend): ?><select name="crm_vendedor_id" aria-label="Vendedor no CRM"><option value="">não ligado</option><?php foreach ($crmVend as $cv => $t): ?><option value="<?= h((string)$cv) ?>" <?= (string)$cv === $v['crm_vendedor_id'] ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach ?></select>
        <?php else: ?><input name="crm_vendedor_id" value="<?= h($v['crm_vendedor_id']) ?>" size="8" aria-label="id no CRM"><?php endif ?>
        <button>Salvar</button></form></td>
      <td><?= $v['ativo'] ? '<span class="pill ok">Ativo</span>' : '<span class="pill er">Bloqueado</span>' ?></td>
      <td class="n"><?= h($v['ultimo_uso'] ?? '—') ?></td>
      <td class="row">
        <form method="post" class="in"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="acao" value="vend_chave"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button>Gerar chave nova</button></form>
        <form method="post" class="in"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="acao" value="vend_ativo"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button><?= $v['ativo'] ? 'Bloquear' : 'Liberar' ?></button></form>
      </td></tr>
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
    <form method="post" class="in" style="margin-left:auto"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="acao" value="enviar_agora"><button class="pri">Enviar fila ao CRM agora</button></form>
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
        <form method="post" class="in"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="acao" value="tentar"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button>Tentar de novo</button></form>
        <?php if (stripos($v['erro'], 'duplicado') !== false): ?><form method="post" class="in"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="acao" value="forcar"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button>Enviar mesmo assim</button></form><?php endif ?>
        <form method="post" class="in"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="acao" value="descartar"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button>Descartar</button></form>
      <?php endif ?></td></tr>
    <?php endforeach ?>
  </table></div>
<?php endif ?>
</main>
</body>
</html>
