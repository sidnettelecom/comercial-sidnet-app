<?php
/**
 * Painel do servidor do app: vendas recebidas dos celulares, situação no CRM, vendedores e gestores.
 * Entrada com usuário e senha do gestor; 5 senhas erradas travam o usuário por 15 minutos.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/repasse.php';
require_once __DIR__ . '/../../lib/vendedores.php';
require_once __DIR__ . '/../../lib/comissoes.php';

const PAINEL_INATIVO_MAX = 8 * 3600; // sai sozinho depois de 8 horas sem uso

session_name('painel_app');
session_start(['cookie_httponly' => true, 'cookie_secure' => conexao_https(), 'cookie_samesite' => 'Strict', 'use_strict_mode' => true]);
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

$cfg = config();
$DEST = nome_destino();                                          // "IXC" ou "CRM"
$colV = destino() === 'ixc' ? 'ixc_vendedor_id' : 'crm_vendedor_id'; // id do vendedor no sistema de destino
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
    $q = db()->prepare('SELECT ativo, admin, trocar_senha FROM gestores WHERE id = ?');
    $q->execute([$gestor['id']]);
    $gq = $q->fetch() ?: ['ativo' => 0, 'admin' => 0, 'trocar_senha' => 0];
    $gestor['admin'] = (bool)$gq['admin'];
    $gestor['trocar'] = (bool)$gq['trocar_senha']; // senha provisória: só deixa trocar a senha
    if (time() - (int)($_SESSION['visto'] ?? 0) > PAINEL_INATIVO_MAX || !$gq['ativo']) {
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
$admin = $logado && !empty($gestor['admin']); // só o admin vê e mexe nas comissões
$quem = $gestor['usuario'] ?? '';

// ---- ações ----
/** "109,90", "R$ 109.90", "109" -> "109.90" ('' se vazio) */
function plano_valor(string $v): string
{
    $v = preg_replace('/[^\d,.]/', '', $v);
    if ($v === '') return '';
    if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
    return is_numeric($v) && (float)$v > 0 ? number_format((float)$v, 2, '.', '') : '';
}
/** Planos de venda do IXC carregados pelo botão, por nome. */
function planos_ixc_ler(string $arq): array
{
    $out = [];
    if (is_file($arq)) {
        foreach (json_decode((string)file_get_contents($arq), true) ?: [] as $o) $out[$o['nome']] = $o;
    }
    return $out;
}
$arqPlanosIxc = dirname($cfg['banco']) . '/ixc-planos.json';
$ixcPlanos = destino() === 'ixc' ? planos_ixc_ler($arqPlanosIxc) : [];

if ($logado && $_SERVER['REQUEST_METHOD'] === 'POST') {
    confere_csrf();
    $a = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if (!empty($gestor['trocar']) && $a !== 'minha_senha') $a = '';
    try {
        if ($a === 'tentar') {
            db()->prepare("UPDATE vendas SET status='fila', erro='', tentativas=0 WHERE id=? AND status<>'crm'")->execute([$id]);
            $msg = 'Venda #' . $id . ' voltou para a fila.';
        } elseif ($a === 'forcar') {
            db()->prepare("UPDATE vendas SET status='forcar', erro='', tentativas=0 WHERE id=? AND status<>'crm'")->execute([$id]);
            $msg = 'Venda #' . $id . ' vai ser enviada mesmo com aviso de duplicado.';
        } elseif ($a === 'descartar') {
            db()->prepare("UPDATE vendas SET status='descartada' WHERE id=? AND status<>'crm'")->execute([$id]);
            $msg = 'Venda #' . $id . " descartada (não vai para o $DEST).";
        } elseif ($a === 'excluir') {
            $q = db()->prepare('SELECT v.*, d.nome AS vendedor FROM vendas v JOIN vendedores d ON d.id = v.vendedor_id WHERE v.id = ?');
            $q->execute([$id]);
            if ($v = $q->fetch()) {
                $pdo = db();
                $pdo->beginTransaction();
                $pdo->prepare('INSERT OR REPLACE INTO excluidas (id_app, nome, por) VALUES (?, ?, ?)')->execute([$v['id_app'], $v['nome'], $quem]);
                $pdo->prepare('DELETE FROM vendas WHERE id = ?')->execute([$id]);
                $pdo->commit();
                registrar($quem, 'excluiu venda', "#{$v['id']} {$v['nome']} (vendedor {$v['vendedor']}, situação {$v['status']}" . ($v['crm_id'] ? ", {$v['crm_id']} no $DEST" : '') . ')');
                $msg = "Venda #{$v['id']} de {$v['nome']} excluída do painel." . ($v['status'] === 'crm' ? " Ela já tinha entrado no $DEST ({$v['crm_id']}): apague lá também, se for o caso." : '');
            }
        } elseif ($a === 'enviar_agora') {
            $r = processar_fila_com_trava(true);
            $msg = $r ? implode(' | ', $r) : 'Nada na fila.';
        } elseif ($a === 'vend_novo') {
            $senha = gerar_senha();
            $novoId = vendedor_novo((string)($_POST['nome'] ?? ''), destino() === 'crm' ? (string)($_POST['crm_vendedor_id'] ?? '') : '', (string)($_POST['usuario'] ?? ''), $senha);
            if (destino() === 'ixc') {
                db()->prepare('UPDATE vendedores SET ixc_vendedor_id=?, ixc_colaborador_id=? WHERE id=?')->execute([trim((string)($_POST['crm_vendedor_id'] ?? '')), so_digitos((string)($_POST['ixc_colaborador_id'] ?? '')), $novoId]);
            }
            $novaSenha = ['quem' => up($_POST['nome'] ?? ''), 'usuario' => usuario_valido((string)$_POST['usuario']), 'senha' => $senha];
            registrar($quem, 'criou vendedor', up($_POST['nome'] ?? '') . ' (' . $novaSenha['usuario'] . ')');
        } elseif ($a === 'vend_senha') {
            $senha = gerar_senha();
            vendedor_definir_acesso($id, (string)($_POST['usuario'] ?? ''), $senha);
            $n = db()->query('SELECT nome FROM vendedores WHERE id=' . $id)->fetchColumn();
            $novaSenha = ['quem' => $n, 'usuario' => usuario_valido((string)$_POST['usuario']), 'senha' => $senha];
            registrar($quem, 'nova senha de vendedor', (string)$n);
        } elseif ($a === 'vend_crm') {
            db()->prepare("UPDATE vendedores SET $colV=? WHERE id=?")->execute([trim((string)$_POST['crm_vendedor_id']), $id]);
            if (destino() === 'ixc' && isset($_POST['ixc_colaborador_id'])) {
                db()->prepare('UPDATE vendedores SET ixc_colaborador_id=? WHERE id=?')->execute([so_digitos((string)$_POST['ixc_colaborador_id']), $id]);
            }
            $msg = 'Vendedor atualizado.';
        } elseif ($a === 'vend_ativo') {
            db()->prepare('UPDATE vendedores SET ativo = 1 - ativo WHERE id=?')->execute([$id]);
            vendedor_derrubar_sessoes($id);
            registrar($quem, 'bloqueou/liberou vendedor', '#' . $id);
            $msg = 'Acesso do vendedor alterado. Se foi bloqueio, o celular dele sai na próxima conexão.';
        } elseif ($a === 'crm_vendedores') {
            if (destino() === 'ixc') {
                $lista = [];
                foreach (ixc()->listar('vendedor', 'id', '0', '>', 500, 'asc') as $o) {
                    if (($o['status'] ?? $o['ativo'] ?? 'A') !== 'I' && ($o['ativo'] ?? 'S') !== 'N') $lista[] = ['valor' => (string)$o['id'], 'texto' => up($o['nome'] ?? '')];
                }
                file_put_contents(dirname($cfg['banco']) . '/ixc-vendedores.json', json_encode($lista, JSON_UNESCAPED_UNICODE));
                $cols = [];
                foreach (ixc()->listar('funcionarios', 'id', '0', '>', 1000, 'asc') as $o) {
                    if (($o['ativo'] ?? 'S') === 'N') continue;
                    $cols[] = ['valor' => (string)$o['id'], 'texto' => up($o['funcionario'] ?? ($o['nome'] ?? ''))];
                }
                file_put_contents(dirname($cfg['banco']) . '/ixc-colaboradores.json', json_encode($cols, JSON_UNESCAPED_UNICODE));
                // liga sozinho quem ainda não tem colaborador e tem o mesmo nome no IXC
                $porNome = [];
                foreach ($cols as $c) $porNome[up(sem_acento($c['texto']))][] = $c['valor'];
                $ligados = 0;
                foreach (db()->query("SELECT id, nome FROM vendedores WHERE ixc_colaborador_id = ''")->fetchAll() as $vd) {
                    $ids = $porNome[up(sem_acento((string)$vd['nome']))] ?? [];
                    if (count($ids) === 1) {
                        db()->prepare('UPDATE vendedores SET ixc_colaborador_id=? WHERE id=?')->execute([$ids[0], $vd['id']]);
                        $ligados++;
                    }
                }
                $msg = count($lista) . ' vendedores e ' . count($cols) . ' colaboradores carregados do IXC' . ($ligados ? "; $ligados ligados pelo nome" : '') . '.';
            } else {
                $lista = Crm::opcoes(crm()->abrir('/novo-lead.php'), 'vendedor_id');
                file_put_contents(dirname($cfg['banco']) . '/crm-vendedores.json', json_encode($lista, JSON_UNESCAPED_UNICODE));
            }
            $msg = $msg ?: count($lista) . " vendedores carregados do $DEST.";
        } elseif ($a === 'plano_novo' || $a === 'plano_salvar') {
            $nome = up(preg_replace('/\s+/', ' ', trim((string)($_POST['nome'] ?? ''))));
            if ($nome === '') throw new RuntimeException('Digite o nome do plano.');
            $valor = plano_valor((string)($_POST['valor'] ?? ''));
            if ($valor === '' && isset($ixcPlanos[$nome]) && $ixcPlanos[$nome]['valor'] !== '') $valor = $ixcPlanos[$nome]['valor'];
            $q = db()->prepare('SELECT id FROM planos WHERE nome = ? AND id <> ?');
            $q->execute([$nome, $a === 'plano_novo' ? 0 : $id]);
            if ($q->fetchColumn()) throw new RuntimeException("O plano \"$nome\" já está na lista.");
            if ($a === 'plano_novo') {
                $ordem = (int)db()->query('SELECT COALESCE(MAX(ordem), 0) + 1 FROM planos')->fetchColumn();
                db()->prepare('INSERT INTO planos (nome, valor, ordem) VALUES (?, ?, ?)')->execute([$nome, $valor, $ordem]);
                registrar($quem, 'criou plano', $nome);
                $msg = "Plano $nome criado. Os celulares recebem a lista nova na próxima vez que abrirem o app com internet.";
            } else {
                db()->prepare('UPDATE planos SET nome = ?, valor = ? WHERE id = ?')->execute([$nome, $valor, $id]);
                registrar($quem, 'alterou plano', $nome);
                $msg = "Plano $nome salvo.";
            }
            if ($ixcPlanos && !isset($ixcPlanos[$nome])) $msg .= " Atenção: não achei um plano de venda com esse nome no IXC.";
        } elseif ($a === 'plano_ativo') {
            db()->prepare('UPDATE planos SET ativo = 1 - ativo WHERE id = ?')->execute([$id]);
            registrar($quem, 'escondeu/mostrou plano', '#' . $id);
            $msg = 'Plano alterado.';
        } elseif ($a === 'plano_excluir') {
            db()->prepare('DELETE FROM planos WHERE id = ?')->execute([$id]);
            registrar($quem, 'excluiu plano', '#' . $id);
            $msg = 'Plano excluído. As vendas antigas com ele não mudam.';
        } elseif ($a === 'plano_mover') {
            $ids = db()->query('SELECT id FROM planos ORDER BY ordem, nome')->fetchAll(PDO::FETCH_COLUMN);
            $i = array_search($id, array_map('intval', $ids), true);
            $j = $i === false ? false : $i + (($_POST['dir'] ?? '') === 'cima' ? -1 : 1);
            if ($i !== false && isset($ids[$j])) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                $up = db()->prepare('UPDATE planos SET ordem = ? WHERE id = ?');
                foreach ($ids as $n => $pid) $up->execute([$n + 1, $pid]);
            }
        } elseif ($a === 'planos_ixc') {
            $lista = [];
            foreach (ixc()->listar('vd_contratos', 'id', '0', '>', 1000, 'asc') as $o) {
                if (($o['Ativo'] ?? $o['ativo'] ?? 'S') === 'N') continue;
                $lista[] = ['id' => (string)$o['id'], 'nome' => up($o['nome'] ?? ''), 'valor' => plano_valor((string)($o['valor_contrato'] ?? ''))];
            }
            file_put_contents($arqPlanosIxc, json_encode($lista, JSON_UNESCAPED_UNICODE));
            $ixcPlanos = planos_ixc_ler($arqPlanosIxc);
            $msg = count($lista) . ' planos de venda ativos carregados do IXC. Escolha abaixo quais aparecem no app.';
        } elseif ($a === 'gest_novo') {
            $u = usuario_valido((string)($_POST['usuario'] ?? ''));
            if ($u === '' || up($_POST['nome'] ?? '') === '') throw new RuntimeException('Preencha nome e usuário (3 a 40 letras minúsculas, números, ponto ou hífen).');
            $q = db()->prepare('SELECT 1 FROM gestores WHERE usuario = ?');
            $q->execute([$u]);
            if ($q->fetchColumn()) throw new RuntimeException("Já existe o gestor \"$u\".");
            $senha = gerar_senha();
            db()->prepare('INSERT INTO gestores (usuario, nome, senha_hash, trocar_senha) VALUES (?, ?, ?, 1)')->execute([$u, up($_POST['nome']), password_hash($senha, PASSWORD_DEFAULT)]);
            $novaSenha = ['quem' => up($_POST['nome']), 'usuario' => $u, 'senha' => $senha];
            registrar($quem, 'criou gestor', $u);
        } elseif ($a === 'comissoes_salvar') {
            if (!$admin) throw new RuntimeException('Só o gestor admin mexe nas comissões.');
            $n = comissoes_salvar((array)($_POST['c'] ?? []), $quem);
            registrar($quem, 'salvou comissões', $n . ' venda(s)');
            $msg = "Comissões salvas ($n venda" . ($n === 1 ? '' : 's') . ').';
        } elseif ($a === 'comissoes_ixc') {
            if (!$admin) throw new RuntimeException('Só o gestor admin mexe nas comissões.');
            $n = comissoes_atualizar_ixc(300);
            registrar($quem, 'atualizou comissões do IXC', (string)$n);
            $msg = $n ? "$n venda(s) atualizadas com dados do IXC." : 'Nada novo no IXC: o que dava para achar já está preenchido.';
        } elseif ($a === 'gest_admin' && $id !== $gestor['id']) {
            if (!$admin) throw new RuntimeException('Só um gestor admin pode dar ou tirar admin.');
            db()->prepare('UPDATE gestores SET admin = 1 - admin WHERE id = ?')->execute([$id]);
            registrar($quem, 'deu/tirou admin', '#' . $id);
            $msg = 'Permissão de admin alterada.';
        } elseif ($a === 'gest_senha' && $id !== $gestor['id']) {
            if (!$admin) throw new RuntimeException('Só um gestor admin pode trocar a senha de outro gestor.');
            $g = db()->query('SELECT nome, usuario FROM gestores WHERE id = ' . $id)->fetch();
            if (!$g) throw new RuntimeException('Gestor não encontrado.');
            $senha = gerar_senha();
            db()->prepare('UPDATE gestores SET senha_hash = ?, trocar_senha = 1 WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
            $novaSenha = ['quem' => $g['nome'], 'usuario' => $g['usuario'], 'senha' => $senha];
            registrar($quem, 'nova senha de gestor', $g['usuario']);
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
            if ($nova === (string)($_POST['atual'] ?? '')) throw new RuntimeException('A senha nova precisa ser diferente da atual.');
            db()->prepare('UPDATE gestores SET senha_hash = ?, trocar_senha = 0 WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $gestor['id']]);
            $gestor['trocar'] = false;
            registrar($quem, 'trocou a própria senha');
            $msg = 'Senha trocada.';
        }
    } catch (Throwable $e) {
        $msg = $e instanceof ErroCrm ? "Não consegui falar com o $DEST: " . $e->getMessage() : $e->getMessage();
    }
}

$p = !empty($gestor['trocar']) ? 'trocar' : ($_GET['p'] ?? 'vendas');
$NOMES = ['venda' => 'Venda', 'segundo' => '2º plano', 'upgrade' => 'Upgrade'];
$SITUACAO = ['fila' => ['Na fila', 'q'], 'forcar' => ['Na fila', 'q'], 'tentar' => ['Tentando de novo', 'q'], 'crm' => ['No ' . $DEST, 'ok'], 'erro' => ['Erro', 'er'], 'descartada' => ['Descartada', 'mu']];
$crmVend = [];
$arqV = dirname($cfg['banco']) . (destino() === 'ixc' ? '/ixc-vendedores.json' : '/crm-vendedores.json');
if (is_file($arqV)) {
    foreach (json_decode((string)file_get_contents($arqV), true) ?: [] as $o) {
        $crmVend[$o['valor']] = $o['texto'];
    }
}
$ixcCol = [];
if (destino() === 'ixc' && is_file(dirname($cfg['banco']) . '/ixc-colaboradores.json')) {
    foreach (json_decode((string)file_get_contents(dirname($cfg['banco']) . '/ixc-colaboradores.json'), true) ?: [] as $o) {
        $ixcCol[$o['valor']] = $o['texto'];
    }
}
$selCol = function (string $atual, string $id = '') use ($ixcCol): string {
    if (!$ixcCol) {
        return '<input' . ($id ? ' id="' . $id . '"' : '') . ' name="ixc_colaborador_id" value="' . h($atual) . '" size="6" placeholder="id" aria-label="Colaborador no IXC (responsável)">';
    }
    $o = '<select' . ($id ? ' id="' . $id . '"' : '') . ' name="ixc_colaborador_id" aria-label="Colaborador no IXC (responsável)"><option value="">padrão do config</option>';
    foreach ($ixcCol as $v => $t) $o .= '<option value="' . h((string)$v) . '"' . ((string)$v === $atual ? ' selected' : '') . '>' . h($t) . '</option>';
    return $o . '</select>';
};
$ICONES = [
    'vendas' => '<path d="M4 6h16M4 12h16M4 18h10"/>',
    'vendedores' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c2 .7 3.2 2.4 3.6 5.2"/>',
    'planos' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 10h6M7 14h10"/>',
    'comissoes' => '<circle cx="12" cy="12" r="9"/><path d="M15 9.5c-.5-1-1.6-1.5-3-1.5-1.8 0-3 .9-3 2.1 0 2.9 6 1.6 6 4.6 0 1.3-1.3 2.3-3 2.3-1.5 0-2.7-.6-3.2-1.7M12 6.5V8m0 9v1.5"/>',
    'gestores' => '<path d="M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
];
$aba = fn(string $k, string $t) => '<a href="?p=' . $k . '"' . (($p === $k || ($k === 'vendas' && $p === 'excluir')) ? ' aria-current="page"' : '') . '><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $ICONES[$k] . '</svg>' . $t . '</a>';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Central do Gestor · Comercial + Sidnet</title>
<link rel="icon" href="/app/icon-192.png">
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
header b{font-size:16px;margin-right:auto}header .marca{display:flex;align-items:center;gap:10px}header .marca img{background:#fff;border-radius:12px;padding:2px}header a{color:inherit;text-decoration:none;opacity:.85;font-weight:600}header a[aria-current]{opacity:1;text-decoration:underline}
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

/* ===== versão viva: menu lateral em degradê, cartões arredondados, cores por tipo ===== */
:root{--bg:#f3f6fc;--surface:#fff;--ink:#0f1b3d;--muted:#56627d;--line:#e1e7f2;--brand:#1d4ed8;--brand-ink:#fff;
--q:#f59e0b;--ok:#047857;--ok-bg:#dcfce7;--er:#be123c;--er-bg:#ffe4e6;--sombra:0 6px 20px rgba(15,27,61,.07);
--g-lado:linear-gradient(180deg,#0b1e5b 0%,#102a7a 60%,#0e7490 100%);--g-btn:linear-gradient(90deg,#1d4ed8,#0891b2);--g-cta:linear-gradient(90deg,#f59e0b,#f97316)}
@media (prefers-color-scheme:dark){:root{--bg:#0a1330;--surface:#111d42;--ink:#e7ecfb;--muted:#a3b0d0;--line:#24315c;--brand:#7aa2ff;--brand-ink:#0a1330;
--ok:#6ee7a8;--ok-bg:#0f3326;--er:#fda4af;--er-bg:#3b1220;--sombra:0 6px 20px rgba(0,0,0,.35)}}
body.dentro{display:flex;flex-wrap:wrap;min-height:100vh}
body.fora{min-height:100vh;background:linear-gradient(165deg,#0b1e5b 0%,#1d4ed8 48%,#0891b2 100%);display:grid;place-items:center}
body.fora main{width:100%;max-width:420px}
.lado{flex:1 1 230px;max-width:100%;box-sizing:border-box;background:var(--g-lado);color:#fff;padding:22px 16px;display:flex;flex-direction:column;gap:24px}
.lado .marca{display:flex;align-items:center;gap:12px}
.lado .marca img{background:#fff;border-radius:14px;padding:2px;flex:none}
.lado .marca b{display:block;font-size:15.5px}.lado .marca span{font-size:12px;color:#bfdbfe}
.lado nav{display:flex;flex-direction:column;gap:4px}
.lado nav a{display:flex;align-items:center;gap:12px;height:44px;padding:0 12px;border-radius:14px;color:#dbeafe;text-decoration:none;font-weight:700}
.lado nav a svg{width:20px;height:20px}
.lado nav a[aria-current]{background:rgba(255,255,255,.16);color:#fff}
.lado nav a:hover{background:rgba(255,255,255,.1)}
.lado .status{margin-top:auto;background:rgba(255,255,255,.12);border-radius:16px;padding:14px;display:grid;gap:6px;font-size:13px}
.lado .status div{display:flex;align-items:center;gap:8px;font-weight:700}
.lado .status i{width:8px;height:8px;border-radius:50%;background:#4ade80;box-shadow:0 0 0 4px rgba(74,222,128,.25)}
.lado .status span{font-size:12px;color:#bfdbfe}.lado .status a{color:#fff;font-weight:700}
body.dentro main{flex:999 1 560px;min-width:0;max-width:none;margin:0;padding:26px clamp(16px,3vw,32px) 40px;align-content:start}
.topo{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px}
.topo h1{margin:0;font-size:28px;letter-spacing:-.02em}.topo p{margin:2px 0 0}
button.cta{background:var(--g-cta);color:#1c1203;border:0;height:46px;padding:0 18px;border-radius:14px;font-weight:800;box-shadow:0 10px 22px rgba(249,115,22,.35)}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.kpi{background:var(--surface);border-radius:20px;padding:16px 18px;box-shadow:var(--sombra);display:grid;gap:4px}
.kpi span{font-size:13px;font-weight:700;color:var(--muted)}.kpi b{font-size:32px;line-height:1.1}.kpi small{font-size:12px;color:var(--muted)}
.kpi b.q{color:#b45309}.kpi b.er{color:var(--er)}.kpi b.ok{color:var(--ok)}
.kpi.forte{background:var(--g-btn);color:#fff;box-shadow:0 12px 28px rgba(29,78,216,.28)}.kpi.forte span,.kpi.forte small{color:#dbeafe}
.card{border:0;border-radius:20px;box-shadow:var(--sombra)}
.msg{border:0;border-left:5px solid var(--brand);border-radius:14px;box-shadow:var(--sombra)}
.pill{border-radius:10px;padding:6px 11px;font-size:12px}
.acoes{display:flex;gap:6px;flex-wrap:nowrap;align-items:center}.acoes form{margin:0}
.pill.q{background:#fef3c7;color:#92400e}
button{border-radius:10px}button.pri{background:var(--g-btn);border:0;color:#fff}
input,select{border-radius:10px}
th{background:transparent}tr:hover td{background:color-mix(in srgb,var(--brand) 4%,transparent)}
.tp{display:inline-block;padding:4px 10px;border-radius:10px;font-size:12px;font-weight:800;white-space:nowrap}
.tp-venda{background:#dbeafe;color:#1e40af}.tp-upgrade{background:#ede9fe;color:#5b21b6}.tp-segundo{background:#d1fae5;color:#065f46}
.entrada{max-width:420px;margin:0 auto;border-radius:26px;padding:26px 22px;text-align:left}
.entrada .logo-entrada{display:block;margin:0 auto;border-radius:32px}
.entrada h2{text-align:center;font-size:22px;margin:4px 0 6px}.entrada h2 span{display:block;font-size:13px;color:var(--muted);font-weight:600}
.entrada button.pri{background:var(--g-cta);color:#1c1203;height:48px;font-weight:800;font-size:16px;margin-top:6px}


/* comissões */
.filtros{gap:8px}.filtros label{font-weight:600}
.kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.kpis div{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:12px 14px}
.kpis b{display:block;font-size:20px;font-variant-numeric:tabular-nums}.kpis span{font-size:12.5px;color:var(--muted)}
.kpis .ok b{color:var(--ok)}.kpis .q b{color:#b45309}
@media (max-width:800px){.kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
.com table{min-width:1180px}.com th,.com td{padding:8px 6px}.com td{vertical-align:middle}.com input,.com select{height:34px;padding:0 8px;font-size:13px}.com input{width:82px}.com input[type=date]{width:136px}.com td.small{max-width:130px}
.com input.forte{font-weight:700}
.com tr.paga td{background:color-mix(in srgb,var(--ok-bg) 70%,transparent)}
.com tr.apagar td{background:#fef9c3}
@media (prefers-color-scheme:dark){.com tr.apagar td{background:#3a3210}}
.com .salvar{position:sticky;bottom:0;display:flex;gap:12px;justify-content:flex-end;align-items:center;padding:12px 0 2px;background:var(--surface)}
tr.soma td{font-weight:700;border-top:2px solid var(--line)}
/* entrada do painel: logo animada à esquerda, formulário à direita */
body.fora main{max-width:none;margin:0;padding:0;min-height:100vh;display:grid;grid-template-columns:minmax(0,1.15fr) minmax(400px,1fr);gap:0;background:#f8fafc}
.entra-arte{display:grid;place-items:center;position:relative;overflow:hidden;background:#0a1430}
.entrada{align-self:center;justify-self:center;width:100%;max-width:400px;padding:24px;display:grid;gap:8px;background:transparent;border:0;box-shadow:none;animation:rise .7s ease-out both;color:#0f1b3d}
.entrada .sobre{font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#2563eb}
.entrada h1{font-size:32px;line-height:1.1;margin:0;color:#0f1b3d;letter-spacing:-.02em}
.entrada .small{margin:0 0 8px;color:#475569}
.entrada label{font-weight:600;color:#0f1b3d}
.entrada input{background:#fff;border:1.5px solid #cbd5e1;color:#0f1b3d;height:46px;padding:0 12px}
.entrada input:focus{outline:0;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.2)}
.entrada .msg{margin:0 0 4px}
@media (max-width:899px){body.fora main{grid-template-columns:1fr;grid-template-rows:auto 1fr}.entra-arte{height:300px}.entra-arte .lg-wrap{width:260px;height:260px}.entra-arte .lg{transform:scale(.5)}}
.fx{position:absolute;inset:0}
.fx i{position:absolute;border-radius:50%;filter:blur(60px);opacity:.75;animation:drift 18s ease-in-out infinite alternate}
.fx .b1{width:420px;height:420px;background:#2563eb;left:-120px;top:-100px}
.fx .b2{width:360px;height:360px;background:#7c3aed;right:-120px;top:30%;animation-duration:22s;animation-delay:-6s}
.fx .b3{width:380px;height:380px;background:#0891b2;left:20%;bottom:-160px;animation-duration:26s;animation-delay:-12s}
.fx canvas{position:absolute;inset:0;width:100%;height:100%}
@keyframes drift{0%{transform:translate(0,0) scale(1)}50%{transform:translate(60px,40px) scale(1.15)}100%{transform:translate(-40px,70px) scale(.95)}}
.lg-wrap{position:relative;width:420px;height:420px}
.lg{transform:scale(.8077);transform-origin:0 0}
.lg-ring{animation:spin 40s linear infinite}
.lg-conic{position:absolute;inset:-30%;background:conic-gradient(from 0deg,#2563eb,#7c3aed,#e11d48,#d97706,#059669,#0891b2,#2563eb);animation:spin 6s linear infinite;z-index:0}
.lg-glow{animation:breathe 3.2s ease-in-out infinite}
.lg-sat{animation:bob 3.4s ease-in-out infinite;animation-delay:var(--d)}
.lg-spoke::after{content:"";position:absolute;top:-2px;left:0;width:12px;height:12px;border-radius:50%;background:#fff;box-shadow:0 0 12px 4px #ffffffaa;animation:packet 2.4s ease-in infinite}
.lg-spoke:nth-of-type(2n)::after{animation-delay:-.8s}.lg-spoke:nth-of-type(3n)::after{animation-delay:-1.6s}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes breathe{0%,100%{transform:scale(.92);opacity:.55}50%{transform:scale(1.08);opacity:1}}
@keyframes bob{0%,100%{translate:0 0}50%{translate:0 -10px}}
@keyframes packet{0%{transform:translateX(0);opacity:0}15%{opacity:1}100%{transform:translateX(56px);opacity:0}}
@keyframes rise{from{opacity:.001;transform:translateY(14px)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.fx i,.lg-ring,.lg-conic,.lg-glow,.lg-sat,.lg-spoke::after,.entra form{animation:none!important}}


td.row{display:table-cell;white-space:nowrap;text-align:right}td.row>*{margin:2px 0 2px 4px;vertical-align:middle}
</style>
<style>.senha-box{position:relative;display:block}.senha-box input{width:100%;box-sizing:border-box;padding-right:48px}.olho{position:absolute;right:4px;top:50%;transform:translateY(-50%);width:40px;height:40px;border:0!important;background:transparent!important;box-shadow:none!important;color:#475569;display:flex;align-items:center;justify-content:center;border-radius:8px;cursor:pointer;padding:0}.olho:hover{background:#eef2ff}.olho .risco{display:none}.olho[aria-pressed="true"] .risco{display:inline}</style>
<script src="/painel/olho.js" defer></script>
</head>
<body class="<?= $logado ? 'dentro' : 'fora' ?>">
<?php if ($logado):
    $ultimo = db()->query("SELECT MAX(enviado_crm_em) FROM vendas")->fetchColumn(); ?>
<aside class="lado">
  <div class="marca"><img src="/app/logo-96.png" alt="" width="48" height="48"><div><b>Comercial + SIDNET</b><span>Painel do gestor</span></div></div>
  <nav aria-label="Seções"><?= $aba('vendas', 'Vendas') . $aba('vendedores', 'Vendedores') . $aba('planos', 'Planos') . ($admin ? $aba('comissoes', 'Comissões') : '') . $aba('gestores', 'Gestores') ?></nav>
  <div class="status"><div><i></i>Servidor no ar</div><span>Último envio ao <?= $DEST ?>: <?= h($ultimo ?: 'nenhum ainda') ?></span><a href="?sair=1">Sair (<?= h($quem) ?>)</a></div>
</aside>
<?php endif ?>
<main>
<?php if ($msg && $logado): ?><div class="msg" role="status"><?= h($msg) ?></div><?php endif ?>
<?php if ($novaSenha): ?>
  <div class="card"><b>Acesso de <?= h($novaSenha['quem']) ?></b><br>
    Usuário: <span class="chave"><?= h($novaSenha['usuario']) ?></span> Senha: <span class="chave"><?= h($novaSenha['senha']) ?></span><br>
    <span class="small">Anote e entregue agora: a senha não aparece de novo. Se perder, gere outra.</span></div>
<?php endif ?>

<?php if (!$logado): ?>
  <div class="entra-arte" aria-hidden="true"><div class="fx" aria-hidden="true"><i class="b1"></i><i class="b2"></i><i class="b3"></i></div>
      <div class="lg-wrap"><div class="lg" data-x="Ícone Comercial + Sidnet: robô da Sidnet ligado a www, navegação, wi-fi, localização, roteador e internet" style="width: 520px; height: 520px; position: relative; background: transparent">
  <div class="lg-ring" style="position: absolute; left: 70px; top: 70px; width: 380px; height: 380px; box-sizing: border-box; border-radius: 50%; border: 3px dashed #c7d2fe; "></div>

  <div class="lg-spoke" style="position: absolute; left: 260px; top: 256px; width: 60px; height: 8px; border-radius: 4px; background: linear-gradient(90deg, rgba(255,255,255,0), #2563eb); transform-origin: 0 4px; transform: rotate(-90deg) translateX(100px)"></div>
  <div class="lg-spoke" style="position: absolute; left: 260px; top: 256px; width: 60px; height: 8px; border-radius: 4px; background: linear-gradient(90deg, rgba(255,255,255,0), #7c3aed); transform-origin: 0 4px; transform: rotate(-30deg) translateX(100px)"></div>
  <div class="lg-spoke" style="position: absolute; left: 260px; top: 256px; width: 60px; height: 8px; border-radius: 4px; background: linear-gradient(90deg, rgba(255,255,255,0), #0891b2); transform-origin: 0 4px; transform: rotate(30deg) translateX(100px)"></div>
  <div class="lg-spoke" style="position: absolute; left: 260px; top: 256px; width: 60px; height: 8px; border-radius: 4px; background: linear-gradient(90deg, rgba(255,255,255,0), #e11d48); transform-origin: 0 4px; transform: rotate(90deg) translateX(100px)"></div>
  <div class="lg-spoke" style="position: absolute; left: 260px; top: 256px; width: 60px; height: 8px; border-radius: 4px; background: linear-gradient(90deg, rgba(255,255,255,0), #d97706); transform-origin: 0 4px; transform: rotate(150deg) translateX(100px)"></div>
  <div class="lg-spoke" style="position: absolute; left: 260px; top: 256px; width: 60px; height: 8px; border-radius: 4px; background: linear-gradient(90deg, rgba(255,255,255,0), #059669); transform-origin: 0 4px; transform: rotate(210deg) translateX(100px)"></div>

  <div class="lg-glow" style="position: absolute; left: 132px; top: 132px; width: 256px; height: 256px; border-radius: 50%; background: radial-gradient(circle,#22d3ee99,#22d3ee00 70%)"></div>
  <div style="position: absolute; left: 152px; top: 152px; width: 216px; height: 216px; box-sizing: border-box; border-radius: 50%; padding: 10px; background: transparent; --cg: conic-gradient(from 0deg, #2563eb, #7c3aed, #e11d48, #d97706, #059669, #0891b2, #2563eb); box-shadow: 0 16px 40px rgba(37,99,235,0.35); overflow:hidden" class="lg-core"><div class="lg-conic"></div>
    <div style="position:relative; width: 196px; height: 196px; border-radius: 50%; background: #ffffff; overflow: hidden; display: flex; align-items: center; justify-content: center">
      <img src="/app/robo.jpg" alt="" style="width: 196px; height: 196px; object-fit: cover; object-position: 50% 30%; mix-blend-mode: multiply; filter: contrast(1.08) brightness(1.04)">
    </div>
  </div>

  <div class="lg-sat" style="--d:0.35s; position: absolute; left: 218px; top: 26px; width: 84px; height: 84px; border-radius: 26px; background: linear-gradient(145deg, #3b82f6, #1d4ed8); box-shadow: 0 10px 24px rgba(37,99,235,0.45); color: #ffffff; display: flex; align-items: center; justify-content: center; font: 900 22px/1 'Arial Black', Arial, sans-serif; letter-spacing: -0.03em; ">www</div>

  <div class="lg-sat" style="--d:0.7s; position: absolute; left: 384px; top: 122px; width: 84px; height: 84px; border-radius: 26px; background: linear-gradient(145deg, #8b5cf6, #6d28d9); box-shadow: 0 10px 24px rgba(124,58,237,0.45); display: flex; align-items: center; justify-content: center; ">
    <svg width="42" height="42" viewBox="0 0 24 24" fill="#ffffff" aria-hidden="true"><path d="M20.5 3.5 3.8 10.3c-.8.3-.7 1.4.1 1.6l6.6 1.6 1.6 6.6c.2.8 1.3.9 1.6.1z"/></svg>
  </div>

  <div class="lg-sat" style="--d:1.0499999999999998s; position: absolute; left: 384px; top: 314px; width: 84px; height: 84px; border-radius: 26px; background: linear-gradient(145deg, #22d3ee, #0e7490); box-shadow: 0 10px 24px rgba(8,145,178,0.45); display: flex; align-items: center; justify-content: center; ">
    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M2.5 9a14 14 0 0 1 19 0"/><path d="M5.8 12.6a9 9 0 0 1 12.4 0"/><path d="M9.1 16.1a4.2 4.2 0 0 1 5.8 0"/><circle cx="12" cy="19.6" r="1.3" fill="#ffffff" stroke="none"/></svg>
  </div>

  <div class="lg-sat" style="--d:1.4s; position: absolute; left: 218px; top: 410px; width: 84px; height: 84px; border-radius: 26px; background: linear-gradient(145deg, #fb7185, #be123c); box-shadow: 0 10px 24px rgba(225,29,72,0.45); display: flex; align-items: center; justify-content: center; ">
    <svg width="42" height="42" viewBox="0 0 24 24" fill="#ffffff" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.6A2.6 2.6 0 1 1 12 6.4a2.6 2.6 0 0 1 0 5.2z"/></svg>
  </div>

  <div class="lg-sat" style="--d:1.75s; position: absolute; left: 52px; top: 314px; width: 84px; height: 84px; border-radius: 26px; background: linear-gradient(145deg, #fbbf24, #b45309); box-shadow: 0 10px 24px rgba(217,119,6,0.45); display: flex; align-items: center; justify-content: center; ">
    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 13V5M17 13V5"/><rect x="2.5" y="13" width="19" height="7" rx="2"/><circle cx="7" cy="16.5" r="1" fill="#ffffff" stroke="none"/><circle cx="10.5" cy="16.5" r="1" fill="#ffffff" stroke="none"/><path d="M15 16.5h3.5"/></svg>
  </div>

  <div class="lg-sat" style="--d:2.0999999999999996s; position: absolute; left: 52px; top: 122px; width: 84px; height: 84px; border-radius: 26px; background: linear-gradient(145deg, #34d399, #047857); box-shadow: 0 10px 24px rgba(5,150,105,0.45); display: flex; align-items: center; justify-content: center; ">
    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><path d="M2.5 12h19"/><path d="M12 2.5c2.6 2.6 3.9 5.8 3.9 9.5s-1.3 6.9-3.9 9.5c-2.6-2.6-3.9-5.8-3.9-9.5S9.4 5.1 12 2.5z"/></svg>
  </div>
</div></div></div>
  <form method="post" class="login entrada">
    <?= csrf_campo() ?>
    <span class="sobre">Comercial + Sidnet</span>
    <h1>Central do Gestor</h1>
    <p class="small">Entre com seu usuário e senha de gestor.</p>
    <?php if ($msg): ?><div class="msg" role="status"><?= h($msg) ?></div><?php endif ?>
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
      <?php if ($v['status'] === 'crm'): ?><br><b>Ela já entrou no <?= $DEST ?> (<?= h($v['crm_id']) ?>).</b> Excluir aqui não apaga lá: apague no <?= $DEST ?> também, se for o caso.<?php endif ?></p>
    <div class="row"><button class="del pri">Excluir venda</button><a href="?p=vendas">Cancelar</a></div>
  </form>
  <?php endif ?>

<?php elseif ($p === 'vendedores'): ?>
  <form method="post" class="card row">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="vend_novo">
    <label for="nome">Novo vendedor</label><input id="nome" name="nome" placeholder="NOME COMPLETO" required>
    <label for="nusu">Usuário</label><input id="nusu" name="usuario" placeholder="ex.: hellen" autocapitalize="none" size="12" required pattern="[a-z0-9._-]{3,40}">
    <label for="crmv">Vendedor no <?= $DEST ?></label>
    <?php if ($crmVend): ?><select id="crmv" name="crm_vendedor_id"><option value="">escolha</option><?php foreach ($crmVend as $v => $t): ?><option value="<?= h((string)$v) ?>"><?= h($t) ?></option><?php endforeach ?></select>
    <?php else: ?><input id="crmv" name="crm_vendedor_id" placeholder="id no <?= $DEST ?>" size="8"><?php endif ?>
    <?php if (destino() === 'ixc'): ?><label for="ncol">Responsável no IXC</label><?= $selCol('', 'ncol') ?><?php endif ?>
    <button class="pri">Criar e gerar senha</button>
  </form>
  <form method="post" class="row"><?= csrf_campo() ?><input type="hidden" name="acao" value="crm_vendedores">
    <button>Carregar vendedores do <?= $DEST ?></button><span class="small">Só lê a lista de vendedores<?= destino() === 'ixc' ? ' e colaboradores' : '' ?>. Não grava nada no <?= $DEST ?>.</span></form>
  <?php if (destino() === 'ixc'): ?><p class="small">"Vendedor no IXC" vai no contrato. "Responsável no IXC" é o colaborador que aparece como responsável no lead, no prospect e na negociação das vendas que ele cadastra.</p><?php endif ?>
  <div class="card tw"><table>
    <tr><th>Vendedor</th><th>Usuário e senha</th><th>No <?= $DEST ?><?= destino() === 'ixc' ? ' (vendedor · responsável)' : '' ?></th><th>Acesso</th><th>Último uso</th><th></th></tr>
    <?php foreach (db()->query('SELECT * FROM vendedores ORDER BY ativo DESC, nome') as $v): ?>
    <tr><td><?= h($v['nome']) ?></td>
      <td><form method="post" class="row"><?= csrf_campo() ?><input type="hidden" name="acao" value="vend_senha"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
        <input name="usuario" value="<?= h($v['usuario'] ?? '') ?>" placeholder="usuário" size="10" autocapitalize="none" required aria-label="Usuário de <?= h($v['nome']) ?>">
        <button><?= ($v['usuario'] ?? '') === '' ? 'Criar acesso' : 'Gerar senha nova' ?></button></form>
        <?php if (($v['usuario'] ?? '') === ''): ?><span class="small">Ainda usa a chave antiga. Crie usuário e senha.</span><?php endif ?></td>
      <td><form method="post" class="row"><?= csrf_campo() ?><input type="hidden" name="acao" value="vend_crm"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
        <?php if ($crmVend): ?><select name="crm_vendedor_id" aria-label="Vendedor no <?= $DEST ?>"><option value="">não ligado</option><?php foreach ($crmVend as $cv => $t): ?><option value="<?= h((string)$cv) ?>" <?= (string)$cv === (string)$v[$colV] ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach ?></select>
        <?php else: ?><input name="crm_vendedor_id" value="<?= h($v[$colV]) ?>" size="8" aria-label="id no <?= $DEST ?>"><?php endif ?>
        <?php if (destino() === 'ixc'): ?><?= $selCol((string)($v['ixc_colaborador_id'] ?? '')) ?><?php endif ?>
        <button>Salvar</button></form></td>
      <td><?= $v['ativo'] ? '<span class="pill ok">Ativo</span>' : '<span class="pill er">Bloqueado</span>' ?></td>
      <td class="n"><?= h($v['ultimo_uso'] ?? '—') ?></td>
      <td><form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="vend_ativo"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button class="<?= $v['ativo'] ? 'del' : '' ?>"><?= $v['ativo'] ? 'Bloquear' : 'Liberar' ?></button></form></td></tr>
    <?php endforeach ?>
  </table></div>
  <p class="small">Senha gerada aqui é provisória e aparece uma vez só: no primeiro login o vendedor é obrigado a criar a dele. Gerar senha nova tira o vendedor de todos os celulares em que ele estava logado.</p>

<?php elseif ($p === 'planos'):
    $planos = db()->query('SELECT * FROM planos ORDER BY ordem, nome')->fetchAll();
    $ativos = count(array_filter($planos, fn($x) => $x['ativo']));
    $jaTem = array_column($planos, 'nome'); ?>
  <div class="card"><h2>Planos do app</h2>
    <p class="small">Estes planos aparecem na lista "Plano selecionado" de todos os vendedores, no celular e na versão web. O app busca a lista ao entrar e sempre que abre com internet.
    <?= $ativos ? '' : '<br><b>Nenhum plano ativo ainda:</b> enquanto a lista estiver vazia, cada celular continua usando a lista que tem em Ajustes.' ?>
    <?php if (destino() === 'ixc'): ?><br>O nome precisa ser igual ao do plano de venda no IXC, senão a negociação entra sem plano.<?php endif ?></p></div>
  <div class="card tw"><table>
    <tr><th>Ordem</th><th>Plano</th><th>Valor mensal (R$)</th><?= destino() === 'ixc' ? '<th>No IXC</th>' : '' ?><th>No app</th><th></th></tr>
    <?php foreach ($planos as $n => $pl): $fid = 'pl' . (int)$pl['id']; ?>
    <tr>
      <td class="n"><form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="plano_mover"><input type="hidden" name="id" value="<?= (int)$pl['id'] ?>">
        <button name="dir" value="cima" aria-label="Subir" <?= $n === 0 ? 'disabled' : '' ?>>↑</button><button name="dir" value="baixo" aria-label="Descer" <?= $n === count($planos) - 1 ? 'disabled' : '' ?>>↓</button></form></td>
      <td><form method="post" id="<?= $fid ?>"><?= csrf_campo() ?><input type="hidden" name="acao" value="plano_salvar"><input type="hidden" name="id" value="<?= (int)$pl['id'] ?>"></form>
        <input form="<?= $fid ?>" name="nome" value="<?= h($pl['nome']) ?>" aria-label="Nome do plano" size="22" style="width:100%;min-width:290px" list="ixcpl" required></td>
      <td><input form="<?= $fid ?>" name="valor" value="<?= $pl['valor'] !== '' ? h(number_format((float)$pl['valor'], 2, ',', '')) : '' ?>" aria-label="Valor mensal" size="7" style="width:90px" inputmode="decimal" placeholder="0,00"></td>
      <?php if (destino() === 'ixc'): ?><td><?= !$ixcPlanos ? '<span class="small">carregue do IXC</span>' : (isset($ixcPlanos[$pl['nome']]) ? '<span class="pill ok">Achado #' . h($ixcPlanos[$pl['nome']]['id']) . '</span>' : '<span class="pill er">Nome não existe</span>') ?></td><?php endif ?>
      <td><?= $pl['ativo'] ? '<span class="pill ok">Aparece</span>' : '<span class="pill mu">Escondido</span>' ?></td>
      <td><div class="acoes"><button form="<?= $fid ?>">Salvar</button>
        <form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="plano_ativo"><input type="hidden" name="id" value="<?= (int)$pl['id'] ?>"><button><?= $pl['ativo'] ? 'Esconder' : 'Mostrar' ?></button></form>
        <form method="post" class="in" onsubmit="return confirm('Excluir o plano <?= h(addslashes($pl['nome'])) ?>?')"><?= csrf_campo() ?><input type="hidden" name="acao" value="plano_excluir"><input type="hidden" name="id" value="<?= (int)$pl['id'] ?>"><button class="del">Excluir</button></form></div></td>
    </tr>
    <?php endforeach ?>
    <?php if (!$planos): ?><tr><td colspan="6" class="small">Nenhum plano cadastrado.</td></tr><?php endif ?>
  </table></div>
  <form method="post" class="card row"><?= csrf_campo() ?><input type="hidden" name="acao" value="plano_novo">
    <label for="pnome">Novo plano</label><input id="pnome" name="nome" placeholder="NOME IGUAL AO DO IXC" size="34" list="ixcpl" required>
    <label for="pval">Valor mensal</label><input id="pval" name="valor" size="8" inputmode="decimal" placeholder="<?= $ixcPlanos ? 'do IXC' : '0,00' ?>">
    <button class="pri">Adicionar</button></form>
  <?php if (destino() === 'ixc'): ?>
  <form method="post" class="row"><?= csrf_campo() ?><input type="hidden" name="acao" value="planos_ixc">
    <button>Carregar planos de venda do IXC</button><span class="small">Só lê os planos de venda ativos. Não grava nada no IXC.</span></form>
  <?php if ($ixcPlanos): ?>
  <div class="card tw"><h2>Planos de venda ativos no IXC</h2><table>
    <tr><th>ID</th><th>Plano de venda</th><th>Valor</th><th></th></tr>
    <?php foreach ($ixcPlanos as $ip): ?>
    <tr><td class="n"><?= h($ip['id']) ?></td><td><?= h($ip['nome']) ?></td><td class="n"><?= $ip['valor'] !== '' ? 'R$ ' . h(number_format((float)$ip['valor'], 2, ',', '.')) : '—' ?></td>
      <td><?php if (in_array($ip['nome'], $jaTem, true)): ?><span class="small">já está no app</span><?php else: ?><form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="plano_novo"><input type="hidden" name="nome" value="<?= h($ip['nome']) ?>"><button>Pôr no app</button></form><?php endif ?></td></tr>
    <?php endforeach ?>
  </table></div>
  <?php endif ?>
  <?php endif ?>
  <datalist id="ixcpl"><?php foreach ($ixcPlanos as $ip): ?><option value="<?= h($ip['nome']) ?>"><?php endforeach ?></datalist>

<?php elseif ($p === 'trocar'): ?>
  <form method="post" class="card login"><?= csrf_campo() ?><input type="hidden" name="acao" value="minha_senha">
    <h2>Crie a sua senha</h2>
    <p class="small">Você entrou com uma senha provisória. Para continuar, crie uma senha só sua.</p>
    <label for="atual">Senha provisória</label><input id="atual" name="atual" type="password" autocomplete="current-password" required>
    <label for="nova">Senha nova (8+ caracteres, letras e números)</label><input id="nova" name="nova" type="password" autocomplete="new-password" minlength="8" required>
    <label for="nova2">Repita a senha nova</label><input id="nova2" name="nova2" type="password" autocomplete="new-password" minlength="8" required>
    <button class="pri">Criar senha e entrar</button></form>

<?php elseif ($p === 'comissoes' && !$admin): ?>
  <div class="msg">Só o gestor admin vê as comissões.</div>

<?php elseif ($p === 'comissoes'):
    $meses = comissoes_meses();
    $mesAtual = date('Y-m');
    $mes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? $_GET['mes'] : (array_key_first($meses) ?? $mesAtual);
    if (!isset($meses[$mes])) $meses[$mes] = ['n' => 0, 'abertas' => 0];
    krsort($meses);
    $filtro = ['vend' => (int)($_GET['vend'] ?? 0), 'tipo' => (string)($_GET['tipo'] ?? ''), 'pagou' => (string)($_GET['pagou'] ?? ''), 'paga' => (string)($_GET['paga'] ?? '')];
    $linhas = comissoes_do_mes($mes, $filtro);
    $tot = ['n' => count($linhas), 'paga' => 0.0, 'apagar' => 0.0, 'espera' => 0.0, 'semvalor' => 0];
    foreach ($linhas as $l) {
        $v = (float)$l['valor'];
        if ($l['valor'] === '') $tot['semvalor']++;
        if ($l['pago_em'] !== '') $tot['paga'] += $v;
        elseif ($l['cliente_pagou'] === 'S') $tot['apagar'] += $v;
        else $tot['espera'] += $v;
    }
    $opMeses = [];
    for ($m = $mes; $m <= mes_soma($mesAtual, 1); $m = mes_soma($m, 1)) $opMeses[] = $m;
    $pagoMes = (string)($_GET['pago'] ?? $mesAtual);
    if (!preg_match('/^\d{4}-\d{2}$/', $pagoMes)) $pagoMes = $mesAtual;
    $pagas = comissoes_pagas_em($pagoMes);
    $vendList = db()->query('SELECT id, nome FROM vendedores ORDER BY nome')->fetchAll();
    $qs = fn(array $mais) => '?' . http_build_query(array_merge(['p' => 'comissoes', 'mes' => $mes], array_filter($filtro), $mais)); ?>
  <div class="card"><h2>Comissões</h2>
    <p class="small"><b>Automático do IXC</b> (a cada 30 minutos): ID do cliente, valor do plano (pelo plano de venda no IXC), vencimento do primeiro boleto depois da venda e "Cliente pagou" (Sim quando o boleto é recebido, Não quando vence em aberto). Instalação e roteador vêm do que o vendedor preencheu no app. No upgrade, o valor do plano é o novo valor informado pelo vendedor.<br>Cada comissão fica no mês da venda e é paga uma vez só. Quando o cliente pagar, marque "Cliente pagou: Sim"; quando você pagar o vendedor, escolha o mês em "Comissão paga em". O que ficar sem pagar continua aqui no mês da venda: volte nele para conferir.</p>
    <form method="get" class="row filtros"><input type="hidden" name="p" value="comissoes">
      <label for="cmes">Vendas de</label>
      <select id="cmes" name="mes"><?php foreach ($meses as $m => $i): ?><option value="<?= h($m) ?>" <?= $m === $mes ? 'selected' : '' ?>><?= h(mes_nome($m)) ?> · <?= $i['abertas'] ? $i['abertas'] . ' em aberto' : 'tudo pago' ?></option><?php endforeach ?></select>
      <select name="vend" aria-label="Vendedor"><option value="">Todos os vendedores</option><?php foreach ($vendList as $vd): ?><option value="<?= (int)$vd['id'] ?>" <?= $filtro['vend'] === (int)$vd['id'] ? 'selected' : '' ?>><?= h($vd['nome']) ?></option><?php endforeach ?></select>
      <select name="tipo" aria-label="Tipo"><option value="">Todos os tipos</option><?php foreach (COMISSAO_TIPOS as $k => $t): ?><option value="<?= $k ?>" <?= $filtro['tipo'] === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach ?></select>
      <select name="pagou" aria-label="Cliente pagou"><option value="">Cliente pagou: todos</option><option value="S" <?= $filtro['pagou'] === 'S' ? 'selected' : '' ?>>Cliente pagou</option><option value="N" <?= $filtro['pagou'] === 'N' ? 'selected' : '' ?>>Cliente não pagou</option><option value="V" <?= $filtro['pagou'] === 'V' ? 'selected' : '' ?>>A vencer</option></select>
      <select name="paga" aria-label="Comissão paga"><option value="">Comissão: todas</option><option value="nao" <?= $filtro['paga'] === 'nao' ? 'selected' : '' ?>>Ainda não paga</option><option value="sim" <?= $filtro['paga'] === 'sim' ? 'selected' : '' ?>>Já paga</option></select>
      <button class="pri">Filtrar</button></form>
    <?php if (destino() === 'ixc'): ?><form method="post" class="row" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="acao" value="comissoes_ixc"><button>Atualizar do IXC agora</button><span class="small">Só lê o IXC.</span></form><?php endif ?>
  </div>
  <div class="kpis">
    <div><b><?= $tot['n'] ?></b><span>vendas em <?= h(mes_nome($mes)) ?></span></div>
    <div class="ok"><b>R$ <?= brl(number_format($tot['paga'], 2, '.', '')) ?></b><span>comissão já paga</span></div>
    <div class="q"><b>R$ <?= brl(number_format($tot['apagar'], 2, '.', '')) ?></b><span>a pagar (cliente já pagou)</span></div>
    <div><b>R$ <?= brl(number_format($tot['espera'], 2, '.', '')) ?></b><span>esperando o cliente<?= $tot['semvalor'] ? ' · ' . $tot['semvalor'] . ' sem valor' : '' ?></span></div>
  </div>
  <form method="post" class="card tw com"><?= csrf_campo() ?><input type="hidden" name="acao" value="comissoes_salvar">
    <table>
      <tr><th>ID cliente IXC</th><th>Cliente</th><th>Vendedor</th><th>Tipo</th><th>Plano</th><th>Valor do plano</th><th>Vencimento do boleto</th><th>Instalação</th><th>Roteador</th><th>Comissão</th><th>Cliente pagou?</th><th>Comissão paga em</th></tr>
      <?php foreach ($linhas as $l): $k = 'c[' . (int)$l['id'] . ']'; $cls = $l['pago_em'] !== '' ? 'paga' : ($l['cliente_pagou'] === 'S' ? 'apagar' : ''); ?>
      <tr class="<?= $cls ?>">
        <td><input name="<?= $k ?>[ixc_cliente]" value="<?= h($l['ixc_cliente']) ?>" size="7" inputmode="numeric" aria-label="ID do cliente no IXC"></td>
        <td><b><?= h($l['nome']) ?></b><br><span class="small">venda <?= h(date('d/m', strtotime($l['recebido_em']))) ?> · <?= h($l['cpf']) ?></span></td>
        <td><?= h($l['vendedor']) ?></td>
        <td><span class="tp tp-<?= h($l['tipo']) ?>"><?= h(COMISSAO_TIPOS[$l['tipo']] ?? $l['tipo']) ?></span></td>
        <td class="small"><?= h($l['plano']) ?></td>
        <td><input name="<?= $k ?>[valor_plano]" value="<?= h(brl($l['valor_plano'])) ?>" size="6" inputmode="decimal" placeholder="0,00" aria-label="Valor mensal do plano"></td>
        <td><input type="date" name="<?= $k ?>[vencimento]" value="<?= h($l['vencimento']) ?>" aria-label="Vencimento do boleto"><?= $l['dia_venc'] !== '' && $l['vencimento'] === '' ? '<br><span class="small">dia ' . h($l['dia_venc']) . '</span>' : '' ?></td>
        <td><input name="<?= $k ?>[instalacao]" value="<?= h(brl($l['instalacao'])) ?>" size="6" inputmode="decimal" placeholder="0,00" aria-label="Valor da instalação"></td>
        <td><input name="<?= $k ?>[roteador]" value="<?= h(brl($l['roteador'])) ?>" size="6" inputmode="decimal" placeholder="0,00" aria-label="Valor do roteador"></td>
        <td><input name="<?= $k ?>[valor]" value="<?= h(brl($l['valor'])) ?>" size="6" inputmode="decimal" placeholder="0,00" aria-label="Valor da comissão" class="forte"></td>
        <td><select name="<?= $k ?>[cliente_pagou]" aria-label="Cliente pagou"><option value="">A vencer</option><option value="S" <?= $l['cliente_pagou'] === 'S' ? 'selected' : '' ?>>Sim</option><option value="N" <?= $l['cliente_pagou'] === 'N' ? 'selected' : '' ?>>Não</option></select></td>
        <td><select name="<?= $k ?>[pago_em]" aria-label="Comissão paga em"><option value="">Não paga</option><?php foreach ($opMeses as $om): ?><option value="<?= $om ?>" <?= $l['pago_em'] === $om ? 'selected' : '' ?>><?= h(mes_nome($om)) ?></option><?php endforeach ?></select>
          <input type="hidden" name="<?= $k ?>[obs]" value="<?= h($l['obs']) ?>"></td>
      </tr>
      <?php endforeach ?>
      <?php if (!$linhas): ?><tr><td colspan="12" class="small">Nenhuma venda nesse mês com esses filtros.</td></tr><?php endif ?>
    </table>
    <?php if ($linhas): ?><div class="salvar"><span class="small">Mudou alguma coisa? Salve antes de trocar de mês.</span><button class="pri">Salvar comissões</button></div><?php endif ?>
  </form>
  <div class="card tw"><form method="get" class="row"><input type="hidden" name="p" value="comissoes"><input type="hidden" name="mes" value="<?= h($mes) ?>">
      <h2 style="margin:0">Pago aos vendedores em</h2>
      <select name="pago" aria-label="Mês do pagamento"><?php for ($i = -1; $i <= 12; $i++): $om = mes_soma($mesAtual, -$i); ?><option value="<?= $om ?>" <?= $om === $pagoMes ? 'selected' : '' ?>><?= h(mes_nome($om)) ?></option><?php endfor ?></select>
      <button>Ver</button></form>
    <table>
      <tr><th>Vendedor</th><th>Vendas</th><th>Upgrades</th><th>2º ponto</th><th>Total</th><th>Inclui vendas de outros meses</th></tr>
      <?php $g = ['venda' => 0, 'upgrade' => 0, 'segundo' => 0, 'total' => 0]; foreach ($pagas as $nome => $pg): foreach (['venda', 'upgrade', 'segundo'] as $t) $g[$t] += $pg['tipos'][$t] ?? 0; $g['total'] += $pg['total']; ?>
      <tr><td><?= h($nome) ?></td>
        <?php foreach (['venda', 'upgrade', 'segundo'] as $t): ?><td class="n">R$ <?= brl(number_format($pg['tipos'][$t] ?? 0, 2, '.', '')) ?></td><?php endforeach ?>
        <td class="n"><b>R$ <?= brl(number_format($pg['total'], 2, '.', '')) ?></b></td>
        <td class="small"><?= h(implode(', ', array_unique($pg['atrasadas'] ?? [])) ?: '—') ?></td></tr>
      <?php endforeach ?>
      <?php if ($pagas): ?><tr class="soma"><td>Total</td><?php foreach (['venda', 'upgrade', 'segundo'] as $t): ?><td class="n">R$ <?= brl(number_format($g[$t], 2, '.', '')) ?></td><?php endforeach ?><td class="n"><b>R$ <?= brl(number_format($g['total'], 2, '.', '')) ?></b></td><td></td></tr>
      <?php else: ?><tr><td colspan="6" class="small">Nenhuma comissão marcada como paga em <?= h(mes_nome($pagoMes)) ?>.</td></tr><?php endif ?>
    </table></div>

<?php elseif ($p === 'gestores'): ?>
  <div class="card tw"><table>
    <tr><th>Gestor</th><th>Usuário</th><th>Acesso</th><th>Admin (comissões)</th><th>Último acesso</th><th></th></tr>
    <?php foreach (db()->query('SELECT * FROM gestores ORDER BY ativo DESC, nome') as $g): ?>
    <tr><td><?= h($g['nome']) ?></td><td><?= h($g['usuario']) ?></td>
      <td><?= $g['ativo'] ? '<span class="pill ok">Ativo</span>' : '<span class="pill er">Bloqueado</span>' ?></td>
      <td><?= $g['admin'] ? '<span class="pill ok">Admin</span>' : '<span class="small">não</span>' ?>
        <?php if ($admin && (int)$g['id'] !== $gestor['id']): ?><form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="gest_senha"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button>Gerar senha nova</button></form>
        <form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="gest_admin"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button><?= $g['admin'] ? 'Tirar admin' : 'Tornar admin' ?></button></form><?php endif ?></td>
      <td class="n"><?= h($g['ultimo_acesso'] ?? '—') ?></td>
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
  <div class="topo"><div><h1>Vendas</h1><p class="small">Tudo o que chegou dos celulares e a situação no <?= $DEST ?>.</p></div>
    <form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="enviar_agora"><button class="cta">Enviar fila ao <?= $DEST ?> agora</button></form></div>
  <?php
    $hoje = (int)db()->query("SELECT COUNT(*) FROM vendas WHERE date(recebido_em) = date('now','localtime')")->fetchColumn();
    $ontem = (int)db()->query("SELECT COUNT(*) FROM vendas WHERE date(recebido_em) = date('now','localtime','-1 day')")->fetchColumn();
    $mes = (int)db()->query("SELECT COUNT(*) FROM vendas WHERE status='crm' AND strftime('%Y-%m', enviado_crm_em) = strftime('%Y-%m','now','localtime')")->fetchColumn();
    $ativos = (int)db()->query("SELECT COUNT(*) FROM vendedores WHERE ativo=1")->fetchColumn();
    $dif = $hoje - $ontem;
  ?>
  <div class="kpis">
    <div class="kpi forte"><span>Recebidas hoje</span><b><?= $hoje ?></b><small><?= $dif === 0 ? 'igual a ontem' : ($dif > 0 ? '+' . $dif . ' que ontem' : $dif . ' que ontem') ?></small></div>
    <div class="kpi"><span>Na fila</span><b class="q"><?= $nFila ?></b><small>entram em segundos</small></div>
    <div class="kpi"><span>Com erro</span><b class="er"><?= (int)($cont['erro'] ?? 0) ?></b><small>precisam de você</small></div>
    <div class="kpi"><span>No <?= $DEST ?> no mês</span><b class="ok"><?= $mes ?></b><small><?= $ativos ?> <?= $ativos === 1 ? 'vendedor ativo' : 'vendedores ativos' ?></small></div>
  </div>
  <div class="row">
    <a href="?p=vendas"><span class="pill mu">Todas</span></a>
    <a href="?p=vendas&amp;st=fila"><span class="pill q">Na fila <?= $nFila ?></span></a>
    <a href="?p=vendas&amp;st=erro"><span class="pill er">Com erro <?= (int)($cont['erro'] ?? 0) ?></span></a>
    <a href="?p=vendas&amp;st=crm"><span class="pill ok">No <?= $DEST ?> <?= (int)($cont['crm'] ?? 0) ?></span></a>
  </div>
  <div class="card tw"><table>
    <tr><th>#</th><th>Recebido</th><th>Tipo</th><th>Cliente</th><th>Vendedor</th><th><?= $DEST ?></th><th></th></tr>
    <?php foreach (db()->query("SELECT v.*, d.nome AS vendedor FROM vendas v JOIN vendedores d ON d.id=v.vendedor_id $where ORDER BY v.id DESC LIMIT 300") as $v):
        [$rot, $cls] = $SITUACAO[$v['status']] ?? [$v['status'], 'mu']; $r = json_decode($v['dados'], true) ?: []; ?>
    <tr><td class="n"><?= (int)$v['id'] ?></td><td class="n"><?= h($v['recebido_em']) ?></td><td><span class="tp tp-<?= h($v['tipo']) ?>"><?= h($NOMES[$v['tipo']] ?? $v['tipo']) ?></span></td>
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
