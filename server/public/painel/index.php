<?php
/**
 * Portal Sidnet (antes "Comercial + Sidnet"): vendas recebidas dos celulares, situação no CRM, usuários (vendedores),
 * gestores, recolhimento de equipamentos, instalações concluídas e RH (horas informais, sobreaviso, recibos).
 * Entrada com usuário e senha do gestor; 5 senhas erradas travam o usuário por 15 minutos.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/repasse.php';
require_once __DIR__ . '/../../lib/vendedores.php';
require_once __DIR__ . '/../../lib/comissoes.php';
require_once __DIR__ . '/../../lib/comissoes_planilha.php'; // PLANILHA-TEMP
require_once __DIR__ . '/../../lib/dashboard.php';
require_once __DIR__ . '/../../lib/permissoes.php';
require_once __DIR__ . '/../../lib/recolhimento.php';
require_once __DIR__ . '/../../lib/instalacao.php';
require_once __DIR__ . '/../../lib/rh.php';
require_once __DIR__ . '/../../lib/recibos.php';
require_once __DIR__ . '/../../lib/servicos.php';
require_once __DIR__ . '/../../lib/colaboradores.php';
dash_tabela();
perm_tabela();
rec_tabela();
inst_tabela();
rh_tabela();
serv_tabela();
recibos_tabela();
colab_tabela();

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
    if ($min = travado('painel:u:' . $u . '|' . $ip, 'painel:ip:' . $ip)) {
        $msg = "Muitas tentativas erradas. Tente de novo em $min min.";
    } else {
        $q = db()->prepare('SELECT * FROM gestores WHERE usuario = ?');
        $q->execute([$u]);
        $g = $q->fetch();
        $hash = $g['senha_hash'] ?? password_hash(gerar_chave(), PASSWORD_DEFAULT);
        if ($g && $g['ativo'] && password_verify((string)($_POST['senha'] ?? ''), $hash)) {
            limpar_falhas('painel:u:' . $u . '|' . $ip);
            session_regenerate_id(true);
            $_SESSION['gestor'] = ['id' => (int)$g['id'], 'usuario' => $g['usuario'], 'nome' => $g['nome']];
            $_SESSION['visto'] = time();
            db()->prepare("UPDATE gestores SET ultimo_acesso = datetime('now','localtime') WHERE id = ?")->execute([$g['id']]);
            registrar($g['usuario'], 'entrou', $ip);
            header('Location: ./');
            exit;
        }
        anotar_falha('painel:u:' . $u . '|' . $ip, 5);
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
    $q = db()->prepare('SELECT ativo, admin, trocar_senha, permissoes FROM gestores WHERE id = ?');
    $q->execute([$gestor['id']]);
    $gq = $q->fetch() ?: ['ativo' => 0, 'admin' => 0, 'trocar_senha' => 0, 'permissoes' => ''];
    $gestor['admin'] = (bool)$gq['admin'];
    $gestor['perms'] = perm_lista($gq['permissoes']);
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
// o que este gestor pode abrir (o admin pode tudo; os outros, o que o admin liberou em Configurações)
// $area pode ser uma subopção ("rh.lanc.compl") ou um grupo inteiro ("rh.lanc", "rh"): o grupo vale se alguma subopção estiver liberada
$pode = fn(string $area): bool => $admin || ($logado && perm_tem($gestor['perms'] ?? [], $area));
// tipos de lançamento do RH que este gestor pode ver e lançar
$rhTipos = array_values(array_filter(array_keys(RH_TIPOS), fn($t) => $pode('rh.lanc.' . $t)));
$rhTudo = count($rhTipos) === count(RH_TIPOS); // vê todos os tipos: pode imprimir recibo e marcar pago
const PERM_ACOES = [
    'tentar' => 'vendas.lista', 'forcar' => 'vendas.lista', 'descartar' => 'vendas.lista', 'excluir' => 'vendas.lista', 'enviar_agora' => 'vendas.lista',
    'vend_novo' => 'cfg.users', 'vend_senha' => 'cfg.users', 'vend_crm' => 'cfg.users', 'vend_ativo' => 'cfg.users', 'crm_vendedores' => 'cfg.users',
    'plano_salvar' => 'cfg.planos', 'plano_novo' => 'cfg.planos', 'plano_ativo' => 'cfg.planos', 'plano_excluir' => 'cfg.planos', 'plano_mover' => 'cfg.planos', 'planos_ixc' => 'cfg.planos',
    'comissoes_salvar' => 'com.lista', 'comissoes_ixc' => 'com.lista', 'planilha_importar' => 'com.planilha', 'planilha_salvar' => 'com.planilha',
    'rec_salvar' => 'rec.lista', 'rec_excluir' => 'rec.lista', 'rec_buscar' => 'rec.lista', 'rec_mais' => 'rec.lista',
    'rec_regra_salvar' => 'rec.com', 'rec_com_salvar' => 'rec.com', 'rec_pagar' => 'rec.com',
    'inst_salvar' => 'inst.lista', 'inst_excluir' => 'inst.lista', 'inst_buscar' => 'inst.lista', 'inst_mais' => 'inst.lista', 'inst_mais_tec' => 'inst.lista',
    'inst_servicos_salvar' => 'inst.com', 'inst_com_salvar' => 'inst.com', 'inst_pagar' => 'inst.com',
    'rh_lanc_salvar' => 'rh.lanc', 'rh_lanc_excluir' => 'rh.lanc', 'rh_func_salvar' => 'rh.func', 'rh_func_ativo' => 'rh.func', 'rh_pagar' => 'rh.fecha',
    'recibos_cpf' => 'recibos',
    'colab_buscar' => 'cfg.colab', 'colab_salvar' => 'cfg.colab', 'colab_mostrar' => 'cfg.colab',
    'serv_servico' => 'serv.lanc', 'serv_pagamento' => 'serv.pag', 'serv_excluir' => 'serv',
    'serv_equipe_salvar' => 'serv.cad', 'serv_equipe_ativo' => 'serv.cad', 'serv_obra_salvar' => 'serv.cad', 'serv_obra_concluir' => 'serv.cad', 'serv_precos_salvar' => 'serv.cad',
];
$recForm = null; // formulário de O.S de recolhimento para mostrar de novo (erro ou "Buscar nome")
$instForm = null; // o mesmo para a O.S de instalação
$rhForm = null; // lançamento do RH que deu erro, para mostrar de novo
$servForm = null; // serviço/pagamento de serviços em execução para mostrar de novo
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
    if (isset(PERM_ACOES[$a]) && !$pode(PERM_ACOES[$a])) {
        $msg = 'Você não tem permissão para isso. Peça ao admin para liberar em Configurações.';
        $a = '';
    }
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
                $msg = "Venda #{$v['id']} de {$v['nome']} excluída do painel." . ($v['status'] === 'crm' && !registro_so_comercial($v['crm_id']) ? " Ela já tinha entrado no $DEST ({$v['crm_id']}): apague lá também, se for o caso." : '');
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
            if (!$admin) throw new RuntimeException('Só um gestor admin pode criar gestores.');
            $u = usuario_valido((string)($_POST['usuario'] ?? ''));
            if ($u === '' || up($_POST['nome'] ?? '') === '') throw new RuntimeException('Preencha nome e usuário (3 a 40 letras minúsculas, números, ponto ou hífen).');
            $q = db()->prepare('SELECT 1 FROM gestores WHERE usuario = ?');
            $q->execute([$u]);
            if ($q->fetchColumn()) throw new RuntimeException("Já existe o gestor \"$u\".");
            $senha = gerar_senha();
            db()->prepare('INSERT INTO gestores (usuario, nome, senha_hash, trocar_senha, permissoes) VALUES (?, ?, ?, 1, ?)')->execute([$u, up($_POST['nome']), password_hash($senha, PASSWORD_DEFAULT), PERM_PADRAO_NOVO]);
            $novaSenha = ['quem' => up($_POST['nome']), 'usuario' => $u, 'senha' => $senha];
            registrar($quem, 'criou gestor', $u);
            $msg = 'Gestor criado só com acesso a Vendas. Libere o resto em Configurações.';
        } elseif ($a === 'comissoes_salvar') {
            $n = comissoes_salvar((array)($_POST['c'] ?? []), $quem);
            registrar($quem, 'salvou comissões', $n . ' venda(s)');
            $msg = "Comissões salvas ($n venda" . ($n === 1 ? '' : 's') . ').';
        } elseif ($a === 'comissoes_ixc') {
            $n = comissoes_atualizar_ixc(300);
            registrar($quem, 'atualizou comissões do IXC', (string)$n);
            $msg = $n ? "$n venda(s) atualizadas com dados do IXC." : 'Nada novo no IXC: o que dava para achar já está preenchido.';
        } elseif ($a === 'planilha_importar') { // PLANILHA-TEMP
            $arq = $_FILES['csv'] ?? null;
            if (!$arq || ($arq['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$arq['tmp_name'])) throw new RuntimeException('Escolha o arquivo CSV da planilha.');
            if (planilha_qtd() > 0 && empty($_POST['substituir'])) throw new RuntimeException('Já tem uma planilha importada. Marque "trocar tudo" para importar de novo (o que você marcou aqui se perde).');
            $n = planilha_importar((string)$arq['tmp_name'], $quem);
            registrar($quem, 'importou comissões da planilha', $n . ' linha(s)');
            $msg = "Planilha importada: $n linhas.";
        } elseif ($a === 'planilha_salvar') { // PLANILHA-TEMP
            $n = planilha_salvar((array)($_POST['pl'] ?? []), $quem);
            registrar($quem, 'salvou comissões da planilha', $n . ' linha(s)');
            $msg = $n ? "Comissões da planilha salvas ($n linha" . ($n === 1 ? '' : 's') . ' alterada' . ($n === 1 ? '' : 's') . ').' : 'Nada mudou.';
            if (empty($_POST['pl_fim'])) $msg .= ' Atenção: a tela era grande demais e parte não chegou ao servidor. Filtre por vendedor e salve de novo o que faltou.';
        } elseif ($a === 'gest_admin' && $id !== $gestor['id']) {
            if (!$admin) throw new RuntimeException('Só um gestor admin pode dar ou tirar admin.');
            db()->prepare('UPDATE gestores SET admin = 1 - admin WHERE id = ?')->execute([$id]);
            registrar($quem, 'deu/tirou admin', '#' . $id);
            $msg = 'Permissão de admin alterada.';
        } elseif ($a === 'supervisor_salvar') {
            if (!$admin) throw new RuntimeException('Só o gestor admin define o supervisor.');
            $sid = (int)($_POST['supervisor'] ?? 0);
            db()->exec('UPDATE vendedores SET supervisor = 0');
            if ($sid) db()->prepare('UPDATE vendedores SET supervisor = 1 WHERE id = ?')->execute([$sid]);
            $nomeS = $sid ? (string)db()->query('SELECT nome FROM vendedores WHERE id = ' . $sid)->fetchColumn() : '';
            registrar($quem, 'definiu supervisor', $nomeS ?: 'nenhum');
            $msg = $nomeS ? "$nomeS agora é o supervisor: a meta e o resultado dele são a soma dos outros vendedores." : 'Sem supervisor.';
        } elseif ($a === 'perm_salvar') {
            if (!$admin) throw new RuntimeException('Só o gestor admin mexe nas permissões.');
            $ids = array_map('intval', (array)($_POST['ids'] ?? []));
            perm_salvar((array)($_POST['perm'] ?? []), $ids);
            $resumo = [];
            $qn = db()->prepare('SELECT usuario, permissoes FROM gestores WHERE id = ? AND admin = 0');
            foreach ($ids as $gid) { $qn->execute([$gid]); if ($r = $qn->fetch()) $resumo[] = $r['usuario'] . ' = ' . ($r['permissoes'] ?: 'nada'); }
            registrar($quem, 'alterou permissões', implode('; ', $resumo));
            $msg = 'Permissões salvas.';
        } elseif ($a === 'gest_senha' && $id !== $gestor['id']) {
            if (!$admin) throw new RuntimeException('Só um gestor admin pode trocar a senha de outro gestor.');
            $g = db()->query('SELECT nome, usuario FROM gestores WHERE id = ' . $id)->fetch();
            if (!$g) throw new RuntimeException('Gestor não encontrado.');
            $senha = gerar_senha();
            db()->prepare('UPDATE gestores SET senha_hash = ?, trocar_senha = 1 WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
            $novaSenha = ['quem' => $g['nome'], 'usuario' => $g['usuario'], 'senha' => $senha];
            registrar($quem, 'nova senha de gestor', $g['usuario']);
        } elseif ($a === 'gest_ativo' && $id !== $gestor['id']) {
            if (!$admin) throw new RuntimeException('Só um gestor admin pode bloquear ou liberar gestores.');
            db()->prepare('UPDATE gestores SET ativo = 1 - ativo WHERE id = ?')->execute([$id]);
            registrar($quem, 'bloqueou/liberou gestor', '#' . $id);
            $msg = 'Acesso do gestor alterado.';
        } elseif ($a === 'metas_salvar' && $admin) { // só o admin define as metas
            $mesMeta = (string)($_POST['mes'] ?? '');
            $n = dash_metas_salvar($mesMeta, (array)($_POST['meta'] ?? []), $quem);
            registrar($quem, 'salvou metas', mes_nome($mesMeta) . " ($n vendedores)");
            $msg = 'Metas de ' . mes_nome($mesMeta) . ' salvas.';
        } elseif ($a === 'metas_copiar' && $admin) {
            $mesMeta = (string)($_POST['mes'] ?? '');
            $n = dash_metas_copiar($mesMeta, $quem);
            if ($n) registrar($quem, 'copiou metas', mes_nome($mesMeta) . " ($n vendedores)");
            $msg = $n ? "Metas de " . mes_nome(mes_soma($mesMeta, -1)) . " copiadas para $n vendedores. Confira e salve se mudar algo." : 'Nada para copiar: o mês anterior não tem metas, ou este mês já tem meta para todos.';
        } elseif ($a === 'rec_mais') { // + Adicionar equipamento: mostra o formulário de novo com uma linha a mais
            $recForm = (array)($_POST['r'] ?? []);
            $recForm['id'] = $id;
            $recForm['linhas_eq'] = count((array)($recForm['eq'] ?? [])) + 1;
        } elseif ($a === 'rec_buscar') {
            $recForm = (array)($_POST['r'] ?? []);
            $recForm['id'] = $id;
            if (destino() !== 'ixc') throw new RuntimeException('A busca só funciona com o IXC. Preencha à mão.');
            if (so_digitos((string)($recForm['os'] ?? '')) !== '') {
                $o = rec_os_ixc((string)$recForm['os']);
                if (!$o) throw new RuntimeException('Não achei a O.S nº ' . so_digitos((string)$recForm['os']) . ' no IXC. Confira o número.');
                foreach (['cliente', 'contrato', 'abertura', 'fechamento'] as $k) if ($o[$k] !== '') $recForm[$k] = $o[$k];
                $avisos = [];
                if ($o['tecnico'] !== '') {
                    if ($tid = rec_tecnico_por_nome($o['tecnico'])) $recForm['tecnico_id'] = $tid;
                }
                if (empty($recForm['tecnico_id']) && count($soAtivos = rec_tecnicos(true)) === 1) $recForm['tecnico_id'] = $soAtivos[0]['id']; // um recolhedor só: já vem escolhido
                if (!$o['finalizada'] || $o['fechamento'] === '') $avisos[] = 'a O.S ainda não está finalizada no IXC, então o fechamento não veio';
                $msg = 'O.S ' . so_digitos((string)$recForm['os']) . ($o['assunto'] !== '' ? " ({$o['assunto']})" : '') . ' carregada do IXC (só leitura, nada foi gravado lá). Confira e preencha MAC e modelo dos equipamentos.' . ($avisos ? ' Atenção: ' . implode('; ', $avisos) . '.' : '');
            } else {
                $nomeIxc = rec_nome_ixc((string)($recForm['contrato'] ?? ''));
                if ($nomeIxc === '') throw new RuntimeException('Não achei esse contrato no IXC. Confira o número ou digite o nome do cliente.');
                $recForm['cliente'] = $nomeIxc;
                $msg = 'Nome do cliente preenchido pelo IXC (só leitura, nada foi gravado lá).';
            }
        } elseif ($a === 'rec_salvar') {
            $recForm = (array)($_POST['r'] ?? []);
            $recForm['id'] = $id;
            [$rid, $aviso] = rec_salvar($recForm, $id, $quem);
            $rr = rec_um($rid);
            registrar($quem, $id ? 'corrigiu O.S de recolhimento' : 'lançou O.S de recolhimento', "#$rid {$rr['cliente']} contrato {$rr['contrato']} ({$rr['qtd']} equip.)");
            $msg = ($id ? 'O.S corrigida.' : "O.S de {$rr['cliente']} lançada ({$rr['qtd']} equipamento" . ($rr['qtd'] == 1 ? '' : 's') . ').') . ($aviso ? ' ' . $aviso : '');
            $recForm = null;
            $_GET['mes'] = substr($rr['fechamento'], 0, 7);
        } elseif ($a === 'rec_excluir') {
            $rr = rec_um($id);
            rec_excluir($id);
            if ($rr) registrar($quem, 'excluiu O.S de recolhimento', "#$id {$rr['cliente']} contrato {$rr['contrato']}");
            $msg = 'O.S excluída.';
        } elseif ($a === 'rec_tec_novo') {
            if (!$admin) throw new RuntimeException('Só o gestor admin mexe na lista de recolhedores.');
            $nt = up(preg_replace('/\s+/', ' ', trim((string)($_POST['nome'] ?? ''))) ?? '');
            if ($nt === '') throw new RuntimeException('Digite o nome do recolhedor.');
            db()->prepare('INSERT OR IGNORE INTO rec_tecnicos (nome) VALUES (?)')->execute([$nt]);
            registrar($quem, 'cadastrou recolhedor', $nt);
            $msg = "Recolhedor $nt na lista.";
        } elseif ($a === 'rec_tec_ativo') {
            if (!$admin) throw new RuntimeException('Só o gestor admin mexe na lista de recolhedores.');
            db()->prepare('UPDATE rec_tecnicos SET ativo = 1 - ativo WHERE id = ?')->execute([$id]);
            registrar($quem, 'escondeu/mostrou recolhedor', '#' . $id);
            $msg = 'Recolhedor alterado. As O.S antigas dele não mudam.';
        } elseif ($a === 'rec_regra_salvar') {
            db()->prepare('UPDATE rec_regra SET valor_fin = ?, valor_tec = ?, base = ?, so_funcionando = ? WHERE id = 1')
                ->execute([dinheiro((string)($_POST['valor_fin'] ?? '')), dinheiro((string)($_POST['valor_tec'] ?? '')), ($_POST['base'] ?? '') === 'os' ? 'os' : 'eq', empty($_POST['so_funcionando']) ? 0 : 1]);
            $mesR = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['mes'] ?? '')) ? (string)$_POST['mes'] : date('Y-m');
            $n = rec_aplicar_regra($mesR, !empty($_POST['sobrescrever']));
            registrar($quem, 'salvou valor padrão do recolhimento', mes_nome($mesR) . " ($n O.S preenchidas)");
            $msg = 'Valor padrão salvo.' . ($n ? " $n O.S de " . mes_nome($mesR) . ' preenchidas com ele.' : '');
        } elseif ($a === 'rec_com_salvar' || $a === 'rec_pagar') {
            $n = rec_comissao_salvar((array)($_POST['c'] ?? []), $quem);
            if ($n) registrar($quem, 'salvou comissão do recolhimento', "$n O.S");
            $msg = $n ? "Comissão salva ($n O.S alterada" . ($n === 1 ? '' : 's') . ').' : 'Nada mudou.';
            if ($a === 'rec_pagar') {
                $mesR = (string)($_POST['mes'] ?? '');
                $pg = (string)($_POST['pago_em'] ?? '');
                $n = rec_pagar($mesR, $pg, $quem);
                registrar($quem, 'pagou comissão do recolhimento', mes_nome($mesR) . " ($n O.S, pago em " . mes_nome($pg) . ')');
                $msg = $n ? "$n O.S conferidas marcadas como pagas em " . mes_nome($pg) . '.' : 'Nenhuma O.S conferida (✔) e ainda não paga neste mês.';
            }
        } elseif ($a === 'inst_mais' || $a === 'inst_mais_tec') { // + Adicionar equipamento / técnico: mostra o formulário de novo com uma linha a mais
            $instForm = (array)($_POST['r'] ?? []);
            $instForm['id'] = $id;
            if ($a === 'inst_mais') $instForm['linhas_eq'] = count((array)($instForm['eq'] ?? [])) + 1;
            else $instForm['linhas_tec'] = min(INST_MAX_TEC, count((array)($instForm['tec'] ?? [])) + 1);
            $instForm['linhas_eq'] ??= count((array)($instForm['eq'] ?? []));
            $instForm['linhas_tec'] ??= count((array)($instForm['tec'] ?? []));
        } elseif ($a === 'inst_buscar') {
            $instForm = (array)($_POST['r'] ?? []);
            $instForm['id'] = $id;
            $instForm['linhas_eq'] = count((array)($instForm['eq'] ?? []));
            $instForm['linhas_tec'] = count((array)($instForm['tec'] ?? []));
            if (destino() !== 'ixc') throw new RuntimeException('A busca só funciona com o IXC. Preencha à mão.');
            if (so_digitos((string)($instForm['os'] ?? '')) !== '') {
                $o = rec_os_ixc((string)$instForm['os']);
                if (!$o) throw new RuntimeException('Não achei a O.S nº ' . so_digitos((string)$instForm['os']) . ' no IXC. Confira o número.');
                foreach (['cliente', 'contrato', 'abertura', 'fechamento', 'assunto'] as $k) if ($o[$k] !== '') $instForm[$k] = $o[$k];
                if (empty($instForm['servico_id']) && ($sid = inst_lista_por_nome('servico', $o['assunto']))) $instForm['servico_id'] = $sid; // assunto da O.S com o mesmo nome de um serviço
                $avisos = [];
                if ($o['tecnico'] !== '') {
                    // técnico da O.S no IXC: entra como o primeiro (e vai para a lista de técnicos se ainda não estiver)
                    $tid = inst_tecnico_por_nome($o['tecnico'], true);
                    $ja = array_values(array_filter(array_map('intval', (array)($instForm['tec'] ?? []))));
                    if ($tid && !in_array($tid, $ja, true)) array_unshift($ja, $tid);
                    $instForm['tec'] = $ja;
                    $instForm['linhas_tec'] = count($ja);
                } else {
                    $avisos[] = 'a O.S não tem técnico no IXC, escolha quem fez';
                }
                if (!$o['finalizada'] || $o['fechamento'] === '') $avisos[] = 'a O.S ainda não está finalizada no IXC, então o fechamento não veio';
                $msg = 'O.S ' . so_digitos((string)$instForm['os']) . ($o['assunto'] !== '' ? " ({$o['assunto']})" : '') . ' carregada do IXC (só leitura, nada foi gravado lá). Confira, acrescente o outro técnico se foram dois e os equipamentos recolhidos, se teve.' . ($avisos ? ' Atenção: ' . implode('; ', $avisos) . '.' : '');
            } else {
                $nomeIxc = rec_nome_ixc((string)($instForm['contrato'] ?? ''));
                if ($nomeIxc === '') throw new RuntimeException('Não achei esse contrato no IXC. Confira o número ou digite o nome do cliente.');
                $instForm['cliente'] = $nomeIxc;
                $msg = 'Nome do cliente preenchido pelo IXC (só leitura, nada foi gravado lá).';
            }
        } elseif ($a === 'inst_salvar') {
            $instForm = (array)($_POST['r'] ?? []);
            $instForm['id'] = $id;
            $instForm['linhas_eq'] = count((array)($instForm['eq'] ?? []));
            $instForm['linhas_tec'] = count((array)($instForm['tec'] ?? []));
            [$iid, $aviso] = inst_salvar($instForm, $id, $quem);
            $ii = inst_um($iid);
            $nomesT = implode(' e ', array_column($ii['tecs'], 'nome'));
            registrar($quem, $id ? 'corrigiu O.S de instalação' : 'lançou O.S de instalação', "#$iid {$ii['cliente']} contrato {$ii['contrato']} ($nomesT)");
            $msg = ($id ? 'O.S corrigida.' : "Instalação de {$ii['cliente']} lançada ($nomesT).") . ($aviso ? ' ' . $aviso : '');
            $instForm = null;
            $_GET['mes'] = substr($ii['fechamento'], 0, 7);
        } elseif ($a === 'inst_excluir') {
            $ii = inst_um($id);
            inst_excluir($id);
            if ($ii) registrar($quem, 'excluiu O.S de instalação', "#$id {$ii['cliente']} contrato {$ii['contrato']}");
            $msg = 'O.S excluída.';
        } elseif ($a === 'inst_lista_novo' || $a === 'inst_lista_ativo') {
            $tl = (string)($_POST['tipo'] ?? '');
            if ($tl === 'servico' ? !$pode('inst.com') : !$admin) throw new RuntimeException($tl === 'servico' ? 'Você não tem permissão para a comissão da instalação.' : 'Só o gestor admin mexe nas listas da instalação.');
            if (!isset(INST_LISTAS[$tl])) throw new RuntimeException('Lista desconhecida.');
            $rot = ['tec' => 'técnico', 'agenda' => 'quem agenda', 'servico' => 'tipo de serviço'][$tl];
            if ($a === 'inst_lista_novo') {
                $nt = up(preg_replace('/\s+/', ' ', trim((string)($_POST['nome'] ?? ''))) ?? '');
                if ($nt === '') throw new RuntimeException('Digite o nome.');
                inst_lista_por_nome($tl, $nt, true);
                registrar($quem, "cadastrou $rot (instalação)", $nt);
                $msg = "$nt na lista.";
            } else {
                inst_lista_ativo($tl, $id);
                registrar($quem, "escondeu/mostrou $rot (instalação)", '#' . $id);
                $msg = 'Lista alterada. As O.S antigas não mudam.';
            }
        } elseif ($a === 'inst_servicos_salvar') {
            inst_servicos_salvar((array)($_POST['sv'] ?? []));
            $mesI = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['mes'] ?? '')) ? (string)$_POST['mes'] : date('Y-m');
            $n = inst_aplicar_regra($mesI, !empty($_POST['sobrescrever']));
            registrar($quem, 'salvou valores por serviço da instalação', mes_nome($mesI) . " ($n O.S preenchidas)");
            $msg = 'Valores por serviço salvos.' . ($n ? " $n O.S de " . mes_nome($mesI) . ' preenchidas com eles.' : '');
        } elseif ($a === 'inst_com_salvar' || $a === 'inst_pagar') {
            $n = inst_comissao_salvar((array)($_POST['c'] ?? []), $quem);
            if ($n) registrar($quem, 'salvou comissão da instalação', "$n O.S");
            $msg = $n ? "Comissão salva ($n O.S alterada" . ($n === 1 ? '' : 's') . ').' : 'Nada mudou.';
            if ($a === 'inst_pagar') {
                $mesI = (string)($_POST['mes'] ?? '');
                $pg = (string)($_POST['pago_em'] ?? '');
                $n = inst_pagar($mesI, $pg, $quem);
                registrar($quem, 'pagou comissão da instalação', mes_nome($mesI) . " ($n O.S, pago em " . mes_nome($pg) . ')');
                $msg = $n ? "$n O.S conferidas marcadas como pagas em " . mes_nome($pg) . '.' : 'Nenhuma O.S conferida (✔) e ainda não paga neste mês.';
            }
        } elseif ($a === 'rh_lanc_salvar') {
            $rhForm = (array)($_POST['l'] ?? []);
            if (!in_array((string)($rhForm['tipo'] ?? ''), $rhTipos, true)) throw new RuntimeException('Você não tem permissão para esse tipo de lançamento.');
            $lid = rh_lanc_salvar($rhForm, $quem);
            $ll = rh_lanc_um($lid);
            registrar($quem, 'lançou no RH', "#$lid {$ll['nome']} " . RH_TIPOS[$ll['tipo']] . ' ' . mes_nome($ll['mes']) . ' R$ ' . brl($ll['valor']));
            $msg = 'Lançado: ' . mb_strtolower(RH_TIPOS[$ll['tipo']]) . " de {$ll['nome']}, R$ " . brl($ll['valor']) . ' (' . mes_nome($ll['mes']) . ').';
            $rhForm = ['funcionario_id' => $ll['funcionario_id'], 'tipo' => $ll['tipo'], 'mes' => $ll['mes'], 'data' => $ll['data']]; // próximo lançamento já vem com o mesmo funcionário
        } elseif ($a === 'rh_lanc_excluir') {
            if (($lx = rh_lanc_um($id)) && !in_array($lx['tipo'], $rhTipos, true)) throw new RuntimeException('Você não tem permissão para esse tipo de lançamento.');
            if ($ll = rh_lanc_excluir($id)) {
                registrar($quem, 'excluiu lançamento do RH', "#$id {$ll['nome']} " . RH_TIPOS[$ll['tipo']] . ' ' . mes_nome($ll['mes']) . ' R$ ' . brl($ll['valor']));
                $msg = 'Lançamento excluído.';
            }
        } elseif ($a === 'rh_func_salvar') {
            $fid = rh_func_salvar((array)($_POST['f'] ?? []), $id);
            $ff = rh_funcionario($fid);
            registrar($quem, $id ? 'corrigiu funcionário do RH' : 'cadastrou funcionário do RH', "#$fid {$ff['nome']}");
            $msg = $id ? "Cadastro de {$ff['nome']} salvo." : "{$ff['nome']} cadastrado.";
        } elseif ($a === 'rh_func_ativo') {
            db()->prepare('UPDATE rh_funcionarios SET ativo = 1 - ativo WHERE id = ?')->execute([$id]);
            $ff = rh_funcionario($id);
            if ($ff) registrar($quem, $ff['ativo'] ? 'reativou funcionário do RH' : 'desativou funcionário do RH', "#$id {$ff['nome']}");
            $msg = $ff ? ($ff['ativo'] ? "{$ff['nome']} voltou para a lista de lançamentos." : "{$ff['nome']} saiu da lista de lançamentos (o histórico continua).") : '';
        } elseif ($a === 'rh_pagar') {
            if (!$rhTudo) throw new RuntimeException('Para marcar pago é preciso ver todos os tipos de lançamento do RH. Peça ao admin.');
            $mesP = (string)($_POST['mes'] ?? '');
            rh_pagar($id, $mesP, (string)($_POST['pago_em'] ?? ''), $quem);
            $ff = rh_funcionario($id);
            registrar($quem, 'marcou pago no RH', "{$ff['nome']} " . mes_nome($mesP) . ' em ' . rh_dt((string)$_POST['pago_em']));
            $msg = "{$ff['nome']}: " . mes_nome($mesP) . ' marcado como pago.';
        } elseif ($a === 'rh_desfazer') {
            if (!$admin) throw new RuntimeException('Só o gestor admin desfaz um pagamento do RH.');
            $mesP = (string)($_POST['mes'] ?? '');
            rh_desfazer($id, $mesP);
            $ff = rh_funcionario($id);
            registrar($quem, 'desfez pagamento do RH', ($ff['nome'] ?? "#$id") . ' ' . mes_nome($mesP));
            $msg = 'Pagamento desfeito: os lançamentos de ' . mes_nome($mesP) . ' podem ser mudados de novo.';
        } elseif ($a === 'recibos_cpf') {
            $n = recibos_cpf_salvar((array)($_POST['cpf'] ?? []));
            if ($n) registrar($quem, 'salvou CPF nos recibos', "$n pessoa(s)");
            $msg = $n ? "CPF salvo ($n)." : 'Nenhum CPF mudou.';
        } elseif ($a === 'serv_servico') {
            $servForm = (array)($_POST['l'] ?? []);
            $lid = serv_servico_salvar($servForm, $quem);
            $ll = serv_lancamentos((int)$servForm['equipe_id']);
            $ll = array_values(array_filter($ll, fn($x) => (int)$x['id'] === $lid))[0];
            registrar($quem, 'lançou serviço em execução', "#$lid {$ll['equipe']} " . serv_oque($ll) . ' R$ ' . brl($ll['valor']));
            $sd = serv_saldos()[(int)$ll['equipe_id']];
            $msg = "Lançado: {$ll['equipe']}, " . rh_rs((float)$ll['valor']) . ($ll['k'] === 's' && (int)$ll['preco_id'] ? ' (' . serv_qtd($ll) . ')' : '') . '. Falta pagar para a equipe: ' . rh_rs($sd['falta']) . '.';
            $servForm = ['equipe_id' => $ll['equipe_id'], 'obra_id' => $ll['obra_id'], 'preco_id' => $ll['preco_id'], 'data' => $ll['data']]; // o próximo já vem com a mesma equipe e obra
        } elseif ($a === 'serv_pagamento') {
            $servForm = (array)($_POST['l'] ?? []);
            $lid = serv_pagamento_salvar($servForm, $quem);
            $ll = serv_lanc_um($lid);
            registrar($quem, 'lançou pagamento de serviço em execução', "#$lid {$ll['equipe']} R$ " . brl($ll['valor']) . " ({$ll['forma']})");
            $sd = serv_saldos()[(int)$ll['equipe_id']];
            $msg = "Pagamento lançado: {$ll['equipe']}, " . rh_rs((float)$ll['valor']) . '. ' . ($sd['falta'] > 0.004 ? 'Ainda falta pagar ' . rh_rs($sd['falta']) . '.' : ($sd['falta'] < -0.004 ? 'A equipe ficou com crédito (adiantamento) de ' . rh_rs(-$sd['falta']) . '.' : 'Tudo acertado com esta equipe.'));
            $servForm = null;
        } elseif ($a === 'serv_excluir') {
            $lx = serv_lanc_um($id);
            if ($lx && !$pode($lx['k'] === 's' ? 'serv.lanc' : 'serv.pag')) throw new RuntimeException('Você não tem permissão para excluir esse lançamento.');
            if ($lx = serv_excluir($id)) {
                registrar($quem, 'excluiu lançamento de serviço em execução', "#$id {$lx['equipe']} " . ($lx['k'] === 's' ? 'serviço' : 'pagamento') . ' ' . rh_dt($lx['data']) . ' R$ ' . brl($lx['valor']));
                $msg = ($lx['k'] === 's' ? 'Serviço' : 'Pagamento') . ' de ' . rh_dt($lx['data']) . ' (' . rh_rs((float)$lx['valor']) . ') excluído.';
            }
        } elseif ($a === 'serv_equipe_salvar') {
            $eid = serv_equipe_salvar((array)($_POST['e'] ?? []), $id);
            $ee = serv_equipe($eid);
            registrar($quem, $id ? 'corrigiu equipe de serviços' : 'cadastrou equipe de serviços', "#$eid {$ee['nome']}");
            $msg = $id ? "Equipe {$ee['nome']} salva." : "Equipe {$ee['nome']} cadastrada.";
        } elseif ($a === 'serv_equipe_ativo') {
            db()->prepare('UPDATE serv_equipes SET ativo = 1 - ativo WHERE id = ?')->execute([$id]);
            if ($ee = serv_equipe($id)) {
                registrar($quem, $ee['ativo'] ? 'reativou equipe de serviços' : 'desativou equipe de serviços', "#$id {$ee['nome']}");
                $msg = $ee['ativo'] ? "{$ee['nome']} voltou para a lista de lançamentos." : "{$ee['nome']} saiu da lista de lançamentos de serviço (o extrato e os pagamentos continuam).";
            }
        } elseif ($a === 'serv_obra_salvar') {
            $oid = serv_obra_salvar((array)($_POST['o'] ?? []), $id);
            $oo = serv_obras()[$oid];
            registrar($quem, $id ? 'corrigiu obra de serviços' : 'cadastrou obra de serviços', "#$oid {$oo['nome']}");
            $msg = $id ? "Obra {$oo['nome']} salva." : "Obra {$oo['nome']} cadastrada.";
        } elseif ($a === 'serv_obra_concluir') {
            db()->prepare('UPDATE serv_obras SET concluida = 1 - concluida WHERE id = ?')->execute([$id]);
            if ($oo = serv_obras()[$id] ?? null) {
                registrar($quem, $oo['concluida'] ? 'concluiu obra de serviços' : 'reabriu obra de serviços', "#$id {$oo['nome']}");
                $msg = $oo['concluida'] ? "{$oo['nome']} marcada como concluída (some da lista de lançamentos)." : "{$oo['nome']} voltou para em execução.";
            }
        } elseif ($a === 'serv_precos_salvar') {
            $n = serv_precos_salvar((array)($_POST['pr'] ?? []), (array)($_POST['novo'] ?? []));
            registrar($quem, 'salvou tabela de preços de serviços', "$n linha(s)");
            $msg = 'Tabela de preços salva.';
        } elseif ($a === 'colab_buscar') {
            if (destino() !== 'ixc') throw new RuntimeException('O portal não está ligado ao IXC (destino no config.php).');
            $nc = colab_buscar_ixc();
            registrar($quem, 'leu colaboradores do IXC', "$nc");
            $msg = "$nc colaboradores lidos do IXC. Nada foi gravado no IXC.";
        } elseif ($a === 'colab_salvar') {
            $rc = colab_salvar((array)($_POST['c'] ?? []));
            registrar($quem, 'cadastrou colaboradores do IXC', "{$rc['novos']} cadastro(s), {$rc['escondidos']} escondido(s), {$rc['cpf']} CPF(s), {$rc['ign']} não colaborador(es)");
            $msg = "Pronto: {$rc['novos']} cadastro(s) nas áreas" . ($rc['escondidos'] ? ", {$rc['escondidos']} tirado(s) da lista da área" : '') . ($rc['cpf'] ? ", {$rc['cpf']} CPF(s) gravado(s)" : '') . ($rc['ign'] ? ", {$rc['ign']} marcado(s) como não colaborador" : '') . '.';
        } elseif ($a === 'colab_mostrar') {
            colab_mostrar((string)($_POST['ixc_id'] ?? ''));
            $msg = 'Voltou para a lista.';
        } elseif ($a === 'rh_empresa_salvar') {
            if (!$admin) throw new RuntimeException('Só o gestor admin muda os dados do recibo.');
            $cn = so_digitos((string)($_POST['cnpj'] ?? ''));
            if ($cn !== '' && strlen($cn) !== 14) throw new RuntimeException('O CNPJ precisa ter 14 números.');
            db()->prepare('UPDATE rh_empresa SET nome = ?, cnpj = ?, cidade = ? WHERE id = 1')
                ->execute([mb_substr(up((string)($_POST['nome'] ?? '')) ?: 'SIDNET TELECOM', 0, 80), $cn, mb_substr(trim((string)($_POST['cidade'] ?? '')), 0, 60)]);
            registrar($quem, 'alterou dados do recibo do RH');
            $msg = 'Dados do recibo salvos.';
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

$p = !empty($gestor['trocar']) ? 'trocar' : ($_GET['p'] ?? '');
$AREA_PAG = ['vendas' => 'vendas.lista', 'excluir' => 'vendas.lista', 'mes' => 'vendas.mes', 'dash' => 'dash', 'vendedores' => 'cfg.users', 'planos' => 'cfg.planos',
    'comissoes' => 'com.lista', 'planilha' => 'com.planilha', 'gestores' => 'cfg.gest', 'config' => 'admin', 'senha' => '', 'trocar' => '',
    'recolhimentos' => 'rec.lista', 'rec_comissao' => 'rec.com', 'instalacoes' => 'inst.lista', 'inst_comissao' => 'inst.com',
    'rh_lancar' => 'rh.lanc', 'rh_fecha' => 'rh.fecha', 'rh_func' => 'rh.func', 'recibos' => 'recibos',
    'serv' => 'serv.saldo', 'serv_extrato' => 'serv.saldo', 'serv_lista' => 'serv.saldo', 'serv_lancar' => 'serv', 'serv_cad' => 'serv.cad', 'colab' => 'cfg.colab'];
if (!isset($AREA_PAG[$p])) $p = 'vendas';
if ($logado && $p !== 'trocar' && ($_GET['p'] ?? '') === '' && !$pode('vendas.lista')) {
    // tela inicial: a primeira opção liberada, na ordem do menu
    foreach (['dash', 'mes', 'comissoes', 'planilha', 'recolhimentos', 'rec_comissao', 'instalacoes', 'inst_comissao', 'rh_lancar', 'rh_fecha', 'rh_func', 'serv', 'serv_lancar', 'serv_cad', 'recibos', 'gestores', 'vendedores', 'planos', 'colab'] as $pg)
        if ($pode($AREA_PAG[$pg])) { $p = $pg; break; }
}
if ($logado && $AREA_PAG[$p] !== '' && !($AREA_PAG[$p] === 'admin' ? $admin : $pode($AREA_PAG[$p]))) $p = 'sem';
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
    'planilha' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M4 9h16M4 15h16M10 3v18"/>', // PLANILHA-TEMP
    'comissoes' => '<circle cx="12" cy="12" r="9"/><path d="M15 9.5c-.5-1-1.6-1.5-3-1.5-1.8 0-3 .9-3 2.1 0 2.9 6 1.6 6 4.6 0 1.3-1.3 2.3-3 2.3-1.5 0-2.7-.6-3.2-1.7M12 6.5V8m0 9v1.5"/>',
    'mes' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2.5v3M16 2.5v3M7.5 13.5h3M7.5 17h6"/>',
    'dash' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    'config' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    'gestores' => '<path d="M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
    'recolhimento' => '<path d="M3 7l9-4 9 4-9 4z"/><path d="M3 7v10l9 4 9-4V7M12 11v10"/>',
    'recolhimentos' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
    'rec_comissao' => '<circle cx="12" cy="12" r="9"/><path d="M15 9.5c-.5-1-1.6-1.5-3-1.5-1.8 0-3 .9-3 2.1 0 2.9 6 1.6 6 4.6 0 1.3-1.3 2.3-3 2.3-1.5 0-2.7-.6-3.2-1.7M12 6.5V8m0 9v1.5"/>',
    'instalacao' => '<path d="M3 10l9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
    'permissoes' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    'recibos' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
    'serv' => '<path d="M2 21h20M5 21V9l3-2v14M16 21V9l3-2v14M8 10h8M8 14h8"/>',
    'rh' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c2 .7 3.2 2.4 3.6 5.2"/>',
];
$svg = fn(string $k) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $ICONES[$k] . '</svg>';
$aba = fn(string $k, string $t, string $ic = '') => '<a href="?p=' . $k . '"' . (($p === $k || ($k === 'vendas' && $p === 'excluir')) ? ' aria-current="page"' : '') . '>' . $svg($ic ?: $k) . $t . '</a>';
/** Grupo do menu que abre e fecha (sem JavaScript); fica aberto quando a página atual está nele. Some se não tiver nada liberado. */
$grupo = function (string $ic, string $t, array $itens) use ($p, $svg): string {
    $itens = array_filter($itens, fn($i) => $i[2]);
    if (!$itens) return '';
    $aberto = in_array($p, array_merge(...array_map(fn($i) => $i[3] ?? [$i[0]], $itens)), true);
    $h = '';
    foreach ($itens as $i) $h .= '<a href="?p=' . $i[0] . '"' . (in_array($p, $i[3] ?? [$i[0]], true) ? ' aria-current="page"' : '') . '>' . h($i[1]) . '</a>';
    return '<details class="grupo"' . ($aberto ? ' open' : '') . '><summary>' . $svg($ic) . $t . '<svg class="seta" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></summary><div class="sub">' . $h . '</div></details>';
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Portal Sidnet</title>
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
.filtros{align-items:center}.filtros a{border-radius:12px;padding:2px;border:2px solid transparent;text-decoration:none}.filtros a.on{border-color:var(--brand)}.filtros .sep{width:1px;height:24px;background:var(--line);margin:0 4px}.filtros select{min-width:200px}
.plv{min-width:130px}.plv b{font-size:14px}
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
.com table{min-width:1260px}.com th,.com td{padding:8px 6px}.com td{vertical-align:middle}.com input,.com select{height:34px;padding:0 8px;font-size:13px}.com input{width:82px}.com input[type=date]{width:136px}.com td.small{max-width:130px}
.com input.forte{font-weight:700}.com .prev{color:#b45309;font-weight:600}
.com tr.vend-h td{background:var(--bg);border-top:2px solid var(--line);padding:10px 8px}.com tr.vend-h b{font-size:15px;margin-right:10px}.com tr.vend-h span{font-size:12.5px;color:var(--muted);font-variant-numeric:tabular-nums}.com tr.vend-h a{margin-left:12px;font-size:13px;font-weight:600;color:#1d4ed8}
.vds h2 .small{font-weight:400}.vds td a{color:#1d4ed8;text-decoration:none}.vds td a:hover{text-decoration:underline}.vds td.n,.vds th.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.vds tr.sel td{background:var(--bg)}.vds tr.sel td:first-child{box-shadow:inset 3px 0 0 #2563eb}
.navv{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.navv .quem{display:grid;flex:1;min-width:160px}.navv .quem b{font-size:18px}.navv .setas{display:flex;gap:8px;flex-wrap:wrap}
.navv a.bt{display:inline-flex;align-items:center;height:38px;padding:0 14px;border:1px solid var(--line);border-radius:10px;text-decoration:none;color:var(--fg);font-weight:600;background:var(--surface)}.navv a.bt.pri{background:var(--g-btn);border:0;color:#fff}
.com .rs{position:relative;display:inline-block}.com .rs::before{content:"R$";position:absolute;left:9px;top:50%;transform:translateY(-50%);font-size:12px;color:var(--muted);pointer-events:none}.com .rs input{padding-left:30px;width:100px}
.mes{display:grid;gap:0;padding-top:6px}.mes>*{min-width:0}
.mes-l{display:grid;grid-template-columns:minmax(200px,2fr) repeat(5,minmax(60px,.7fr)) repeat(3,minmax(96px,1fr));gap:10px;align-items:center;padding:12px 8px;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums}
.mes-l>span:not(.quem){text-align:right}.mes-l>span:first-child{text-align:left}.mes-h{font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
.mes-v>summary{cursor:pointer;list-style:none;border-radius:10px;color:var(--fg)}.mes-v>summary::-webkit-details-marker{display:none}.mes-v>summary:hover{background:var(--bg)}
.mes-v .quem{display:grid;gap:6px}.mes-v .quem b::before{content:"▸ ";color:var(--muted)}.mes-v[open] .quem b::before{content:"▾ "}
.mes-v[open]>summary{background:var(--bg)}.mes-v small{color:var(--muted)}
.mes .barra-m{display:block;height:6px;border-radius:3px;background:var(--line)}.mes .barra-m i{display:block;height:100%;border-radius:3px;background:linear-gradient(90deg,#2563eb,#0891b2)}
.mes table.det{margin:6px 0 14px;font-size:13px;min-width:640px}.mes table.det td,.mes table.det th{padding:7px 8px}
.mes-t{font-weight:700;border-top:2px solid var(--line);border-bottom:0}
@media (max-width:900px){.mes-h{display:none}.mes-l{grid-template-columns:1fr 1fr;row-gap:4px}.mes-l>.quem,.mes-t>span:first-child{grid-column:1/-1}.mes-l>span[data-r]{text-align:left;font-size:13px}.mes-l>span[data-r]::before{content:attr(data-r) ": ";color:var(--muted)}}
.com tr.paga td{background:color-mix(in srgb,var(--ok-bg) 70%,transparent)}
/* PLANILHA-TEMP */ .pl table{min-width:1150px}.pl select{min-width:140px}.pl input.obs{width:180px}
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
/* dashboard comercial (só admin) */
main>*{min-width:0}
.dh-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
.dh-kpis .kpi b.dh-r{font-size:24px}.dh-kpis .kpi small{font-weight:700}
.dh-up{color:var(--ok)}.dh-dn{color:var(--er)}.dh-eq{color:var(--muted)}
.kpi.forte .dh-up,.kpi.forte .dh-dn,.kpi.forte .dh-eq{color:#fff}
.dh-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(380px,100%),1fr));gap:14px}
.dh-card h2{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}.dh-card h2 small{font-weight:600;color:var(--muted);font-size:12.5px}
.dh-rank{display:grid;gap:10px;margin-top:6px}
.dh-rk{display:grid;grid-template-columns:34px 1fr auto;gap:10px;align-items:center}
.dh-rk .pos{width:30px;height:30px;border-radius:50%;display:grid;place-items:center;font-weight:800;background:var(--bg);color:var(--muted)}
.dh-rk:nth-child(1) .pos{background:#fde68a;color:#78350f}.dh-rk:nth-child(2) .pos{background:#e2e8f0;color:#334155}.dh-rk:nth-child(3) .pos{background:#fed7aa;color:#7c2d12}
.dh-rk .nm{font-weight:700;display:flex;gap:6px;align-items:baseline;flex-wrap:wrap}.dh-rk .nm small{color:var(--muted);font-weight:600}
.dh-bar{height:10px;border-radius:6px;background:var(--bg);overflow:hidden;margin-top:5px;position:relative}.dh-bar i{display:block;height:100%;border-radius:6px}
.dh-bar.v i{background:linear-gradient(90deg,#1d4ed8,#0891b2)}.dh-bar.u i{background:linear-gradient(90deg,#7c3aed,#c026d3)}
.dh-bar.ok i{background:linear-gradient(90deg,#059669,#10b981)}.dh-bar.mid i{background:linear-gradient(90deg,#f59e0b,#f97316)}.dh-bar.low i{background:linear-gradient(90deg,#e11d48,#f43f5e)}
.dh-bar .ritmo{position:absolute;top:-2px;bottom:-2px;width:2px;background:var(--ink);opacity:.55}
.dh-rk .num{text-align:right;font-variant-numeric:tabular-nums}.dh-rk .num b{font-size:20px;display:block;line-height:1.1}.dh-rk .num span{font-size:12px;font-weight:800}
.dh-seg{display:inline-flex;background:var(--bg);border-radius:12px;padding:3px;gap:2px}
.dh-seg a{padding:5px 12px;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none;color:inherit}.dh-seg a[aria-current]{background:var(--surface);box-shadow:0 1px 4px rgba(15,27,61,.12);color:var(--brand)}
.dh-chart{width:100%;height:auto;display:block}.dh-chart text{font:12px var(--f);fill:var(--muted)}.dh-chart text.on{font-weight:800;fill:var(--ink)}
.dh-leg{display:flex;flex-wrap:wrap;gap:12px;font-size:12.5px;font-weight:700;margin-top:6px}.dh-leg span{display:flex;align-items:center;gap:6px}.dh-leg i{width:12px;height:12px;border-radius:4px;display:inline-block}
.dh-prog{display:flex;align-items:center;gap:8px;min-width:170px}.dh-prog .dh-bar{flex:1;margin:0;height:12px}.dh-prog b{font-size:12.5px;min-width:42px;text-align:right}
.dh-metas table{min-width:860px}.dh-metas td{vertical-align:middle}.dh-metas input{width:76px;text-align:center;font-weight:700}
.dh-chip{display:inline-block;white-space:nowrap;font-size:11.5px;font-weight:800;padding:3px 8px;border-radius:8px}.dh-chip.ok{background:var(--ok-bg);color:var(--ok)}.dh-chip.q{background:#fef3c7;color:#92400e}.dh-chip.er{background:var(--er-bg);color:var(--er)}
td.dlt{font-weight:800;white-space:nowrap}
.perm{min-width:760px}.perm td,.perm th{vertical-align:middle}.perm th:not(:first-child),.perm td:not(:first-child){text-align:center}.perm .ck input{width:20px;height:20px;cursor:pointer}
.lado .status a+a{margin-top:-2px}
.lado nav details.grupo summary{list-style:none;display:flex;align-items:center;gap:12px;height:44px;padding:0 12px;border-radius:14px;color:#dbeafe;font-weight:700;font-size:14px;cursor:pointer}
.lado nav details.grupo summary::-webkit-details-marker{display:none}
.lado nav details.grupo summary:hover{background:rgba(255,255,255,.1)}.lado nav details.grupo summary svg{width:20px;height:20px;flex:none}
.lado nav details.grupo summary .seta{margin-left:auto;width:16px;height:16px;transition:transform .15s}.lado nav details.grupo[open] summary .seta{transform:rotate(180deg)}
.lado nav .sub{display:flex;flex-direction:column;gap:2px;margin:2px 0 4px 21px;padding-left:12px;border-left:2px solid rgba(255,255,255,.18)}
.lado nav .sub a{height:38px;font-size:13.5px}
.rec-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr));gap:10px 12px;align-items:end}
.rec-form label{display:grid;gap:4px;font-size:12.5px;font-weight:700;color:var(--muted);min-width:0}
.rec-form input,.rec-form select,.rec-form textarea{width:100%;min-width:0;font:inherit;color:var(--ink);padding:8px 10px;border:1px solid var(--line);border-radius:10px;background:var(--bg)}
.rec-form .larga{grid-column:1/-1}.rec-form textarea{min-height:64px;font-family:var(--mono);font-size:13px;text-transform:uppercase}
.rec-form .comb{display:flex;gap:6px}.rec-form .comb input{flex:1}
.rec-eqs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 12px;margin:6px 0 4px;max-width:760px}.rec-eqs input{font-family:var(--mono);text-transform:uppercase}
.mac{display:inline-block;font:600 12px/1.2 var(--mono);background:var(--bg);border-radius:6px;padding:3px 6px;margin:1px 2px 1px 0;white-space:nowrap}
.rec tfoot td{font-weight:800;border-bottom:0}.rec td{vertical-align:middle}
.rec input.val{width:96px;text-align:right;font-weight:700}.rec .ck input{width:18px;height:18px}
.inst-tecs{display:grid;gap:6px}.inst-tecs label{display:flex;align-items:center;justify-content:space-between;gap:10px;font-weight:600}
.rec-pes{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr));gap:12px}
.rec-pes .card{display:grid;gap:4px}.rec-pes .papel{font-size:12.5px;color:var(--muted);font-weight:700}.rec-pes .tot{font-size:26px;font-weight:800}
.tp.pago{background:#ede9fe;color:#5b21b6}
.perm-gestores a.bt.on{background:var(--g-btn);color:#fff;border-color:transparent}.perm-gestores a.bt.on .small{color:#dbeafe}
.perm-arvore{display:grid;gap:2px;max-width:640px}
.pn{display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:10px;font-size:13.5px;cursor:pointer}.pn:hover{background:var(--bg)}.pn input{width:18px;height:18px;flex:none}
.pn0{font-weight:800;margin-top:6px;border-top:1px solid var(--line);border-radius:0;padding-top:10px}.pn1{padding-left:32px;font-weight:600}.pn2{padding-left:58px;font-weight:500;font-size:13px}.pn.off{color:var(--muted);cursor:default}
.vista>*,main>*{min-width:0}
.rh-tipos{display:flex;gap:6px;flex-wrap:wrap}.rh-tipos a{display:inline-block;padding:8px 14px;border-radius:12px;border:1px solid var(--line);background:var(--surface);font-weight:700;text-decoration:none;color:var(--ink)}
.rh-tipos a[aria-current]{background:var(--g-btn);color:#fff;border-color:transparent}
.tp-horas{background:#dbeafe;color:#1e40af}.tp-sobre{background:#ede9fe;color:#5b21b6}.tp-compl{background:#d1fae5;color:#065f46}.tp-pag{background:#fef3c7;color:#92400e}
td.r,th.r{text-align:right}
.rcb-filtro{display:grid;gap:12px}.rcb-filtro .row{align-items:center}
.og{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:12px;border:1px solid var(--line);background:var(--surface);font-weight:700;font-size:13px;cursor:pointer}.og input{width:16px;height:16px}
.op{display:inline-flex;align-items:center;gap:6px;font-weight:700;font-size:13px}
.o-vendas{background:#dbeafe;color:#1e40af}.o-rec{background:#e0f2fe;color:#075985}.o-inst{background:#fce7f3;color:#9d174d}.o-rh{background:#ede9fe;color:#5b21b6}
.rcb-cpfs{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(260px,100%),1fr));gap:10px}.rcb-cpfs label{display:grid;gap:4px;font-weight:700;font-size:13px}
.rfolhas{display:grid;gap:18px;justify-items:start;overflow-x:auto}
.rfolha{width:210mm;height:297mm;background:#fff;color:#111;box-shadow:var(--sombra);padding:8mm 10mm;box-sizing:border-box;display:grid;grid-template-rows:repeat(4,minmax(0,1fr));font:11px/1.35 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.rc{border-bottom:1px dashed #999;padding:4mm 2mm;display:grid;grid-template-rows:auto auto 1fr auto;gap:2mm;overflow:hidden}
.rc:last-child,.rc.vazio{border-bottom:0}
.rc .cab{display:flex;justify-content:space-between;gap:8px;border-bottom:1.5px solid #111;padding-bottom:1.5mm}.rc .cab b{font-size:12.5px}.rc .cab .t{font-weight:800;letter-spacing:.06em;font-size:12px;white-space:nowrap}
.rc .txt{font-size:11.5px}
.rc table{align-self:start;min-width:0;width:100%;border-collapse:collapse}.rc td{padding:.5mm 1mm;border:0;border-bottom:1px dotted #ccc;color:#111;background:none!important;font-size:10.5px}
.rc td.r{text-align:right;white-space:nowrap}.rc tr.tot td{font-weight:800;border-bottom:0;font-size:11.5px}
.rc .ass{display:flex;justify-content:space-between;align-items:end;gap:8mm}.rc .ass .lin{flex:1;border-top:1px solid #111;text-align:center;padding-top:1mm;font-size:10px}.rc .ass .dt{white-space:nowrap;font-size:10.5px}
@media screen and (max-width:1100px){.rfolha{zoom:.6}}
@media screen and (max-width:560px){.rfolha{zoom:.42}}
@media print{.lado,.noprint,main>.msg{display:none!important}body.dentro{display:block;background:#fff}body.dentro main{padding:0}
  @page{size:A4;margin:0}.rfolhas{display:block;overflow:visible}.rfolha{box-shadow:none;zoom:1!important;break-after:page}.rfolha:last-child{break-after:auto}}
.serv-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(280px,100%),1fr));gap:14px}
.serv-eq{display:grid;gap:10px}.serv-eq .quem{font-size:15px}.serv-eq .tot{font-size:26px;font-weight:800}.serv-eq .tot.er{color:var(--er)}.serv-eq .tot.ok{color:var(--ok)}
.serv-cad{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(380px,100%),1fr));gap:14px}
td.ok{color:var(--ok)}a.bt.pri{background:var(--g-btn);color:#fff;border:0}a.bt.cta{background:var(--g-cta);color:#1c1203;border:0}
.o-serv{background:#ffedd5;color:#9a3412}
.rec-form [hidden]{display:none}
input.val{width:96px;text-align:right}
th.c,td.c{text-align:center}
@media print{.lado,.noprint{display:none!important}}
</style>
<style>.senha-box{position:relative;display:block}.senha-box input{width:100%;box-sizing:border-box;padding-right:48px}.olho{position:absolute;right:4px;top:50%;transform:translateY(-50%);width:40px;height:40px;border:0!important;background:transparent!important;box-shadow:none!important;color:#475569;display:flex;align-items:center;justify-content:center;border-radius:8px;cursor:pointer;padding:0}.olho:hover{background:#eef2ff}.olho .risco{display:none}.olho[aria-pressed="true"] .risco{display:inline}</style>
<script src="/painel/olho.js?v=5" defer></script>
</head>
<body class="<?= $logado ? 'dentro' : 'fora' ?>">
<?php if ($logado):
    $ultimo = db()->query("SELECT MAX(enviado_crm_em) FROM vendas")->fetchColumn(); ?>
<aside class="lado">
  <div class="marca"><img src="/app/logo-96.png" alt="" width="48" height="48"><div><b>Portal Sidnet</b><span>Comercial, financeiro e campo</span></div></div>
  <nav aria-label="Seções"><?= ($pode('dash') ? $aba('dash', 'Dashboard') : '')
    . $grupo('vendas', 'Vendas', [['vendas', 'Vendas', $pode('vendas.lista'), ['vendas', 'excluir']], ['mes', 'Vendas do mês', $pode('vendas.mes')]])
    . $grupo('comissoes', 'Comissões', [['comissoes', 'Comissões', $pode('com.lista')], ['planilha', 'Comissões planilha', $pode('com.planilha')] /* PLANILHA-TEMP */])
    . $grupo('recolhimento', 'Recolhimento', [['recolhimentos', 'Recolhimentos', $pode('rec.lista')], ['rec_comissao', 'Comissão', $pode('rec.com')]])
    . $grupo('instalacao', 'Instalação', [['instalacoes', 'Instalações concluídas', $pode('inst.lista')], ['inst_comissao', 'Comissões', $pode('inst.com')]])
    . $grupo('rh', 'RH', [['rh_lancar', 'Lançamentos', $pode('rh.lanc')], ['rh_fecha', 'Fechamento do mês', $pode('rh.fecha')], ['rh_func', 'Funcionários', $pode('rh.func')]])
    . $grupo('serv', 'Serviços em execução', [['serv', 'Saldos', $pode('serv.saldo'), ['serv', 'serv_extrato']], ['serv_lancar', 'Lançar', $pode('serv.lanc') || $pode('serv.pag')], ['serv_lista', 'Serviços lançados', $pode('serv.saldo')], ['serv_cad', 'Equipes, obras e preços', $pode('serv.cad')]])
    . ($pode('recibos') ? $aba('recibos', 'Recibos') : '')
    . $grupo('config', 'Configurações', [['config', 'Permissões', $admin], ['gestores', 'Gestores', $pode('cfg.gest')], ['vendedores', 'Usuários', $pode('cfg.users')], ['planos', 'Planos', $pode('cfg.planos')], ['colab', 'Colaboradores do IXC', $pode('cfg.colab')]]) ?></nav>
  <div class="status"><div><i></i>Servidor no ar</div><span>Último envio ao <?= $DEST ?>: <?= h($ultimo ?: 'nenhum ainda') ?></span><a href="?p=senha">Trocar minha senha</a><a href="?sair=1">Sair (<?= h($quem) ?>)</a></div>
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
      <div class="lg-wrap"><div class="lg" data-x="Ícone Portal Sidnet: robô da Sidnet ligado a www, navegação, wi-fi, localização, roteador e internet" style="width: 520px; height: 520px; position: relative; background: transparent">
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
    <span class="sobre">Portal Sidnet</span>
    <h1>Entrar no portal</h1>
    <p class="small">Entre com seu usuário e senha do painel.</p>
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
  <?php else: [$rot] = $v['status'] === 'crm' && registro_so_comercial($v['crm_id']) ? ['Registrado'] : ($SITUACAO[$v['status']] ?? [$v['status']]); ?>
  <form method="post" action="?p=vendas" class="card" style="max-width:560px;display:grid;gap:10px">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
    <h2>Excluir a venda #<?= (int)$v['id'] ?>?</h2>
    <p style="margin:0"><b><?= h($v['nome']) ?></b> · <?= h($NOMES[$v['tipo']] ?? $v['tipo']) ?> · vendedor <?= h($v['vendedor']) ?> · recebida em <?= h($v['recebido_em']) ?> · <?= h($rot) ?> <?= h($v['crm_id']) ?></p>
    <p class="small" style="margin:0">Ela some do painel e o celular do vendedor passa a mostrar "Excluída pelo gestor". Não dá para desfazer.
      <?php if ($v['status'] === 'crm' && !registro_so_comercial($v['crm_id'])): ?><br><b>Ela já entrou no <?= $DEST ?> (<?= h($v['crm_id']) ?>).</b> Excluir aqui não apaga lá: apague no <?= $DEST ?> também, se for o caso.<?php endif ?></p>
    <div class="row"><button class="del pri">Excluir venda</button><a href="?p=vendas">Cancelar</a></div>
  </form>
  <?php endif ?>

<?php elseif ($p === 'mes'):
    $mesAtualM = date('Y-m');
    $mesM = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? (string)$_GET['mes'] : $mesAtualM;
    $precosM = comissoes_precos_planos();
    $qM = db()->prepare("SELECT v.id, v.tipo, v.nome, v.dados, v.status, v.crm_id, v.recebido_em, d.nome AS vendedor, c.valor_plano
        FROM vendas v JOIN vendedores d ON d.id = v.vendedor_id LEFT JOIN comissoes c ON c.venda_id = v.id
        WHERE v.status <> 'descartada' AND v.tipo IN ('venda','upgrade','segundo') AND strftime('%Y-%m', v.recebido_em, 'localtime') = ?
        ORDER BY d.nome, v.recebido_em");
    $qM->execute([$mesM]);
    $porV = [];
    $totM = ['venda' => 0, 'upgrade' => 0, 'segundo' => 0, 'n' => 0, 'ixc' => 0, 'pend' => 0, 'inst' => 0.0, 'rot' => 0.0, 'plano' => 0.0];
    foreach ($qM as $r) {
        $dd = json_decode((string)$r['dados'], true) ?: [];
        $vp = (string)($r['valor_plano'] ?? '');
        if ($vp === '') $vp = $r['tipo'] === 'upgrade' ? dinheiro((string)($dd['valNovo'] ?? '')) : (string)($precosM[up(sem_acento((string)($dd['plano'] ?? '')))] ?? '');
        $r['plano'] = $r['tipo'] === 'upgrade' ? up((string)($dd['velNova'] ?? '')) : up((string)($dd['plano'] ?? ''));
        $r['vp'] = (float)($vp ?: 0);
        $r['inst'] = (float)(dinheiro((string)($dd['valInst'] ?? '')) ?: 0);
        $r['rot'] = (float)(dinheiro((string)($dd['valRot'] ?? '')) ?: 0);
        $r['cidade'] = up((string)($dd['cidade'] ?? ''));
        $g = &$porV[$r['vendedor']];
        $g ??= ['venda' => 0, 'upgrade' => 0, 'segundo' => 0, 'n' => 0, 'ixc' => 0, 'pend' => 0, 'inst' => 0.0, 'rot' => 0.0, 'plano' => 0.0, 'linhas' => []];
        foreach ([&$g, &$totM] as &$t) {
            $t[$r['tipo']]++; $t['n']++;
            $r['status'] === 'crm' ? $t['ixc']++ : $t['pend']++;
            $t['inst'] += $r['inst']; $t['rot'] += $r['rot']; $t['plano'] += $r['vp'];
        }
        unset($t);
        $g['linhas'][] = $r;
        unset($g);
    }
    uasort($porV, fn($a, $b) => $b['n'] <=> $a['n']);
    $maxM = max([1, ...array_values(array_map(fn($g) => $g['n'], $porV))]);
    $R = fn(float $v) => 'R$ ' . brl(number_format($v, 2, '.', ''));
?>
  <div class="card"><form method="get" class="row"><input type="hidden" name="p" value="mes">
      <h2 style="margin:0">Vendas do mês por vendedor</h2>
      <select name="mes" aria-label="Mês" data-auto><?php for ($i = 0; $i <= 12; $i++): $om = mes_soma($mesAtualM, -$i); ?><option value="<?= $om ?>" <?= $om === $mesM ? 'selected' : '' ?>><?= h(mes_nome($om)) ?></option><?php endfor ?></select>
      <button>Ver</button></form>
    <p class="small" style="margin:8px 0 0">Tudo o que os vendedores cadastraram no mês (vendas novas, upgrades e 2º ponto), mais vendidos primeiro. Clique no vendedor para ver os clientes.</p>
  </div>
  <div class="kpis">
    <div><b><?= $totM['n'] ?></b><span>cadastros em <?= h(mes_nome($mesM)) ?></span></div>
    <div><b><?= $totM['venda'] ?> · <?= $totM['upgrade'] ?> · <?= $totM['segundo'] ?></b><span>vendas novas · upgrades · 2º ponto</span></div>
    <div class="ok"><b><?= $totM['ixc'] ?></b><span>concluídos (no <?= $DEST ?> ou registrados)<?= $totM['pend'] ? ' · ' . $totM['pend'] . ' pendentes' : '' ?></span></div>
    <div><b><?= $R($totM['plano']) ?></b><span>em mensalidades vendidas</span></div>
  </div>
  <div class="card mes">
    <div class="mes-l mes-h"><span>Vendedor</span><span>Vendas novas</span><span>Upgrades</span><span>2º ponto</span><span>Total</span><span>Concluídos</span><span>Mensalidades</span><span>Instalação</span><span>Roteador</span></div>
    <?php foreach ($porV as $nomeV => $g): ?>
    <details class="mes-v"><summary class="mes-l">
        <span class="quem"><b><?= h($nomeV) ?></b><span class="barra-m"><i style="width:<?= round($g['n'] / $maxM * 100) ?>%"></i></span></span>
        <span data-r="Vendas novas"><?= $g['venda'] ?></span><span data-r="Upgrades"><?= $g['upgrade'] ?></span><span data-r="2º ponto"><?= $g['segundo'] ?></span><span data-r="Total"><b><?= $g['n'] ?></b></span>
        <span data-r="Concluídos"><?= $g['ixc'] ?><?= $g['pend'] ? ' <small>(' . $g['pend'] . ' pend.)</small>' : '' ?></span>
        <span data-r="Mensalidades"><?= $R($g['plano']) ?></span><span data-r="Instalação"><?= $R($g['inst']) ?></span><span data-r="Roteador"><?= $R($g['rot']) ?></span></summary>
      <div class="tw"><table class="det"><tr><th>Dia</th><th>Cliente</th><th>Cidade</th><th>Tipo</th><th>Plano</th><th>Mensalidade</th><th>Situação</th></tr>
      <?php foreach ($g['linhas'] as $l): $sit = $l['status'] === 'crm' && registro_so_comercial($l['crm_id']) ? ['Registrado', 'ok'] : ($SITUACAO[$l['status']] ?? [$l['status'], 'mu']); ?>
        <tr><td><?= h(date('d/m', strtotime($l['recebido_em']))) ?></td><td><?= h($l['nome']) ?></td><td class="small"><?= h($l['cidade']) ?></td>
          <td><span class="tp tp-<?= h($l['tipo']) ?>"><?= h(COMISSAO_TIPOS[$l['tipo']] ?? $l['tipo']) ?></span></td>
          <td class="small"><?= h($l['plano']) ?></td><td class="n"><?= $l['vp'] ? $R($l['vp']) : '—' ?></td>
          <td><span class="pill <?= $sit[1] ?>"><?= h($sit[0]) ?></span></td></tr>
      <?php endforeach ?></table></div></details>
    <?php endforeach ?>
    <?php if ($porV): ?><div class="mes-l mes-t"><span>Total</span><span data-r="Vendas novas"><?= $totM['venda'] ?></span><span data-r="Upgrades"><?= $totM['upgrade'] ?></span><span data-r="2º ponto"><?= $totM['segundo'] ?></span><span data-r="Total"><?= $totM['n'] ?></span><span data-r="Concluídos"><?= $totM['ixc'] ?></span><span data-r="Mensalidades"><?= $R($totM['plano']) ?></span><span data-r="Instalação"><?= $R($totM['inst']) ?></span><span data-r="Roteador"><?= $R($totM['rot']) ?></span></div>
    <?php else: ?><p class="small">Nenhum cadastro em <?= h(mes_nome($mesM)) ?>.</p><?php endif ?>
  </div>
<?php elseif ($p === 'dash'):
    // ---- dashboard comercial: ranking, mês a mês e metas ----
    $mesHoje = date('Y-m');
    $mesD = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) && (string)$_GET['mes'] <= $mesHoje ? (string)$_GET['mes'] : $mesHoje;
    $antD = mes_soma($mesD, -1);
    $corrente = $mesD === $mesHoje;
    $diasMes = (int)date('t', strtotime($mesD . '-01'));
    $diaHoje = (int)date('j');
    $esperado = $corrente ? $diaHoje / $diasMes : 1.0;         // quanto do mês já passou
    $mesesG = array_map(fn($i) => mes_soma($mesD, $i), range(-5, 0)); // 6 meses do gráfico
    $num = dash_numeros($mesesG[0], $mesD);
    // mês corrente: compara com o mesmo período do mês anterior (dia 1 até hoje)
    $numAnt = $corrente ? dash_numeros($antD, $antD, $diaHoje) : $num;
    $metasD = dash_metas($mesD);
    $metasG = dash_metas_periodo($mesesG[0], $mesD);
    $vz = ['nova' => 0, 'seg' => 0, 'v' => 0, 'u' => 0, 'r' => 0.0];
    $de = fn(array $n, int $vid, string $m) => $n[$vid][$m] ?? $vz;
    // vendedores: ativos, mais quem teve movimento no período ou tem meta no mês
    // o supervisor não entra como vendedor: a meta e o resultado dele são a soma dos outros
    $vendD = [];
    $supNome = '';
    foreach (db()->query('SELECT id, nome, ativo, supervisor FROM vendedores ORDER BY nome') as $vd) {
        $vid = (int)$vd['id'];
        if ($vd['supervisor']) { $supNome = $supNome ?: (string)$vd['nome']; continue; }
        if ($vd['ativo'] || isset($num[$vid]) || isset($metasD[$vid])) $vendD[$vid] = $vd['nome'];
    }
    $metasD = array_intersect_key($metasD, $vendD);
    $rotEquipe = $supNome !== '' ? h($supNome) . ' <span class="small">supervisor · soma da equipe</span>' : 'EQUIPE';
    $CORES = ['#2563eb', '#db2777', '#059669', '#d97706', '#7c3aed', '#0891b2', '#dc2626', '#65a30d', '#c026d3', '#475569'];
    $corV = [];
    $iC = 0;
    foreach ($vendD as $vid => $_) $corV[$vid] = $CORES[$iC++ % count($CORES)];
    $tot = function (array $n, string $m) use ($vendD, $de, $vz) {
        $t = $vz;
        foreach ($vendD as $vid => $_) foreach ($de($n, $vid, $m) as $k => $x) $t[$k] += $x;
        return $t;
    };
    $tA = $tot($num, $mesD);
    $tB = $tot($numAnt, $antD);
    $metaTV = array_sum(array_map(fn($m) => (int)$m['v'], $metasD));
    $metaTU = array_sum(array_map(fn($m) => (int)$m['u'], $metasD));
    $proj = $corrente ? (int)round($tA['v'] / max(1, $diaHoje) * $diasMes) : $tA['v'];
    $cmpTxt = $corrente ? "vs. dia 1 a $diaHoje de " . mes_nome($antD) : 'vs. ' . mes_nome($antD);
    $R = fn(float $v) => 'R$ ' . brl(number_format($v, 2, '.', ''));
    $g = in_array($_GET['g'] ?? '', ['v', 'u', 't'], true) ? (string)$_GET['g'] : 'v';
    $gv = isset($vendD[(int)($_GET['gv'] ?? 0)]) ? (int)$_GET['gv'] : 0;
    $lnkD = fn(array $mais) => '?' . h(http_build_query(array_merge(['p' => 'dash', 'mes' => $mesD, 'g' => $g, 'gv' => $gv ?: null], $mais)));
    $ranking = function (string $campo) use ($vendD, $num, $numAnt, $de, $mesD, $antD, $metasD) {
        $l = [];
        foreach ($vendD as $vid => $nome) {
            $a = $de($num, $vid, $mesD);
            $b = $de($numAnt, $vid, $antD);
            if ($a[$campo] || $b[$campo]) $l[] = ['id' => $vid, 'nome' => $nome, 'a' => $a, 'b' => $b[$campo], 'meta' => $metasD[$vid][$campo] ?? null];
        }
        usort($l, fn($x, $y) => [$y['a'][$campo], $x['nome']] <=> [$x['a'][$campo], $y['nome']]);
        return $l;
    };
?>
  <div class="topo">
    <div><h1>Dashboard comercial</h1><p class="small"><?= h(mes_nome($mesD)) ?><?= $corrente ? ", dia $diaHoje de $diasMes" : ', mês fechado' ?>. Vendas = venda nova + 2º ponto; upgrades contam à parte.</p></div>
    <form method="get" class="row"><input type="hidden" name="p" value="dash"><input type="hidden" name="g" value="<?= h($g) ?>"><?php if ($gv): ?><input type="hidden" name="gv" value="<?= $gv ?>"><?php endif ?>
      <select name="mes" aria-label="Mês" data-auto><?php for ($i = 0; $i <= 12; $i++): $om = mes_soma($mesHoje, -$i); ?><option value="<?= $om ?>" <?= $om === $mesD ? 'selected' : '' ?>><?= h(mes_nome($om)) ?><?= $i === 0 ? ' (até hoje)' : '' ?></option><?php endfor ?></select>
      <noscript><button>Ver</button></noscript></form>
  </div>

  <section class="dh-kpis" aria-label="Resumo do mês">
    <div class="kpi forte"><span>Vendas no mês</span><b><?= $tA['v'] ?></b><small><?= dash_delta($tA['v'], $tB['v']) ?> <?= h($cmpTxt) ?></small></div>
    <div class="kpi"><span>Upgrades no mês</span><b style="color:#6d28d9"><?= $tA['u'] ?></b><small><?= dash_delta($tA['u'], $tB['u']) ?> <span class="small"><?= h($cmpTxt) ?></span></small></div>
    <div class="kpi"><span>Mensalidades novas</span><b class="dh-r"><?= $R($tA['r']) ?></b><small><?= dash_delta($tA['r'], $tB['r']) ?> <span class="small"><?= h($cmpTxt) ?></span></small></div>
    <div class="kpi"><span><?= $supNome !== '' ? 'Meta do supervisor ' . h($supNome) : 'Meta da equipe' ?> (vendas)</span><b class="<?= $metaTV && $tA['v'] >= $metaTV * $esperado ? 'ok' : 'q' ?>"><?= $metaTV ? round($tA['v'] / $metaTV * 100) . '%' : '—' ?></b><small class="small"><?= $metaTV ? $tA['v'] . ' de ' . $metaTV : 'sem meta' ?> · upgrades <?= $metaTU ? $tA['u'] . ' de ' . $metaTU : 'sem meta' ?></small></div>
    <?php if ($corrente): ?><div class="kpi"><span>Projeção do mês</span><b><?= $proj ?></b><small class="small">vendas no ritmo atual<?= $metaTV ? ($proj >= $metaTV ? ', bate a meta de ' : ', abaixo da meta de ') . $metaTV : '' ?></small></div><?php endif ?>
  </section>

  <div class="dh-2">
  <?php foreach (['v' => ['Quem mais vendeu', 'vendas novas + 2º ponto'], 'u' => ['Quem mais fez upgrade', 'upgrades cadastrados no app']] as $campo => [$tit, $sub]): $lista = $ranking($campo); $mx = max([1, ...array_map(fn($x) => $x['a'][$campo], $lista)]); ?>
    <section class="card dh-card"><h2><?= $tit ?> <small><?= $sub ?></small></h2>
      <div class="dh-rank">
      <?php foreach ($lista as $i => $x): $det = $campo === 'v' ? $x['a']['nova'] . ' novas · ' . $x['a']['seg'] . ' 2º ponto' : ''; ?>
        <div class="dh-rk"><div class="pos"><?= $i + 1 ?>º</div>
          <div><div class="nm"><?= h($x['nome']) ?> <small><?= h(implode(' · ', array_filter([$det, $x['meta'] ? 'meta ' . $x['meta'] : '']))) ?></small></div>
            <div class="dh-bar <?= $campo ?>"><i style="width:<?= round($x['a'][$campo] / $mx * 100) ?>%"></i></div></div>
          <div class="num"><b><?= $x['a'][$campo] ?></b><?= dash_delta($x['a'][$campo], $x['b']) ?></div></div>
      <?php endforeach ?>
      <?php if (!$lista): ?><p class="small">Nada em <?= h(mes_nome($mesD)) ?>.</p><?php endif ?>
      </div></section>
  <?php endforeach ?>
  </div>

  <?php // ---- gráfico mês a mês (SVG feito aqui, o painel não roda script dentro da página) ----
    $val = fn(array $o) => $g === 't' ? $o['v'] + $o['u'] : $o[$g];
    $metaDe = function (int $vid, string $m) use ($metasG, $g) {
        $mt = $metasG[$m][$vid] ?? null;
        if (!$mt) return 0;
        return $g === 't' ? (int)$mt['v'] + (int)$mt['u'] : (int)$mt[$g];
    };
    $series = $gv ? [$gv => $vendD[$gv]] : array_filter($vendD, fn($vid) => array_sum(array_map(fn($m) => $val($de($num, $vid, $m)), $mesesG)) > 0, ARRAY_FILTER_USE_KEY);
    $mx = 0;
    foreach ($series as $vid => $_) foreach ($mesesG as $m) $mx = max($mx, $val($de($num, $vid, $m)), $gv ? $metaDe($vid, $m) : 0);
    $mx = (int)ceil($mx * 1.08);
    $mx = $mx > 20 ? (int)ceil($mx / 20) * 20 : max(4, (int)ceil($mx / 4) * 4);
    [$W, $H, $L, $Rm, $T, $B] = [900, 300, 44, 16, 16, 34];
    $x = fn(int $i) => $L + ($W - $L - $Rm) * ($i + .5) / count($mesesG);
    $y = fn(float $v) => $T + ($H - $T - $B) * (1 - $v / $mx);
    $svg = '';
    for ($k = 0; $k <= 4; $k++) { $yy = round($y($mx * $k / 4), 1); $svg .= "<line x1=\"$L\" x2=\"" . ($W - $Rm) . "\" y1=\"$yy\" y2=\"$yy\" stroke=\"var(--line)\"/><text x=\"" . ($L - 8) . '" y="' . ($yy + 4) . '" text-anchor="end">' . round($mx * $k / 4) . '</text>'; }
    foreach ($mesesG as $i => $m) $svg .= '<text x="' . round($x($i), 1) . '" y="' . ($H - 10) . '" text-anchor="middle"' . ($m === $mesD ? ' class="on"' : '') . '>' . h(substr(MESES_PT[(int)substr($m, 5)], 0, 3) . '/' . substr($m, 2, 2)) . ($m === $mesHoje ? '*' : '') . '</text>';
    if ($gv) {
        foreach ($mesesG as $i => $m) {
            $v = $val($de($num, $gv, $m)); $xx = round($x($i), 1); $yv = round($y($v), 1); $mv = $metaDe($gv, $m);
            $svg .= '<rect x="' . ($xx - 22) . "\" y=\"$yv\" width=\"44\" height=\"" . round($y(0) - $y($v), 1) . '" rx="6" fill="' . $corV[$gv] . '"' . ($m === $mesD ? '' : ' opacity=".7"') . '><title>' . h(mes_nome($m)) . ": $v</title></rect>";
            $svg .= "<text x=\"$xx\" y=\"" . ($yv - 6) . "\" text-anchor=\"middle\" class=\"on\">$v</text>";
            if ($mv) { $ym = round($y($mv), 1); $svg .= '<line x1="' . ($xx - 32) . "\" x2=\"" . ($xx + 32) . "\" y1=\"$ym\" y2=\"$ym\" stroke=\"var(--ink)\" stroke-width=\"2.5\" stroke-dasharray=\"5 4\"><title>meta $mv</title></line>"; }
        }
    } else {
        $nS = max(1, count($series));
        $bw = min(26, ($W - $L - $Rm) / count($mesesG) / ($nS + 1));
        foreach ($mesesG as $i => $m) { $j = 0; foreach ($series as $vid => $nome) {
            $v = $val($de($num, $vid, $m)); $xx = round($x($i) - $bw * $nS / 2 + $j++ * $bw, 1);
            $svg .= '<rect x="' . ($xx + 1) . '" y="' . round($y($v), 1) . '" width="' . round($bw - 2, 1) . '" height="' . round($y(0) - $y($v), 1) . '" rx="3" fill="' . $corV[$vid] . '"' . ($m === $mesD ? '' : ' opacity=".75"') . '><title>' . h($nome . ' · ' . mes_nome($m) . ": $v") . '</title></rect>';
        } }
    }
  ?>
  <section class="card dh-card">
    <h2>Mês a mês
      <span class="row">
        <span class="dh-seg"><?php foreach (['v' => 'Vendas', 'u' => 'Upgrades', 't' => 'Total'] as $k => $tx): ?><a href="<?= $lnkD(['g' => $k]) ?>"<?= $g === $k ? ' aria-current="true"' : '' ?>><?= $tx ?></a><?php endforeach ?></span>
        <form method="get" class="in"><input type="hidden" name="p" value="dash"><input type="hidden" name="mes" value="<?= h($mesD) ?>"><input type="hidden" name="g" value="<?= h($g) ?>">
          <select name="gv" aria-label="Vendedor" data-auto><option value="">Equipe toda (por vendedor)</option><?php foreach ($vendD as $vid => $nome): ?><option value="<?= $vid ?>" <?= $gv === $vid ? 'selected' : '' ?>><?= h($nome) ?></option><?php endforeach ?></select>
          <noscript><button>Ver</button></noscript></form>
      </span>
    </h2>
    <?php if ($series): ?>
    <svg class="dh-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Gráfico mês a mês"><?= $svg ?></svg>
    <div class="dh-leg"><?php foreach ($series as $vid => $nome): ?><span><i style="background:<?= $corV[$vid] ?>"></i><?= h($nome) ?></span><?php endforeach ?>
      <?php if ($gv): ?><span><i style="background:none;border-top:2.5px dashed var(--ink);height:0;border-radius:0"></i>meta</span><?php endif ?>
      <span class="small"><?= in_array($mesHoje, $mesesG, true) ? '* mês em andamento · ' : '' ?>passe o mouse nas barras para ver o número</span></div>
    <?php else: ?><p class="small">Sem cadastros de <?= h(mes_nome($mesesG[0])) ?> a <?= h(mes_nome($mesD)) ?>.</p><?php endif ?>
  </section>

  <?php $ate = $corrente ? " 1-$diaHoje" : ''; $mc = fn(string $m) => substr(MESES_PT[(int)substr($m, 5)], 0, 3) . '/' . substr($m, 2, 2); ?>
  <section class="card dh-card">
    <h2>Comparativo com o mês anterior <small><?= h(mes_nome($mesD) . ' x ' . mes_nome($antD)) ?><?= $corrente ? " (mesmo período: dia 1 ao $diaHoje dos dois meses)" : '' ?></small></h2>
    <div class="tw"><table>
      <tr><th>Vendedor</th><th>Vendas <?= h($mc($antD) . $ate) ?></th><th>Vendas <?= h($mc($mesD) . $ate) ?></th><th>Variação</th><th>Upgrades <?= h($mc($antD) . $ate) ?></th><th>Upgrades <?= h($mc($mesD) . $ate) ?></th><th>Variação</th><th>Mensalidades <?= h($mc($mesD)) ?></th></tr>
      <?php foreach ($vendD as $vid => $nome): $a = $de($num, $vid, $mesD); $b = $de($numAnt, $vid, $antD); if (!$a['v'] && !$a['u'] && !$b['v'] && !$b['u']) continue; ?>
      <tr><td><b><?= h($nome) ?></b></td><td class="n"><?= $b['v'] ?></td><td class="n"><b><?= $a['v'] ?></b></td><td class="dlt"><?= dash_delta($a['v'], $b['v']) ?></td>
        <td class="n"><?= $b['u'] ?></td><td class="n"><b><?= $a['u'] ?></b></td><td class="dlt"><?= dash_delta($a['u'], $b['u']) ?></td><td class="n"><?= $R($a['r']) ?></td></tr>
      <?php endforeach ?>
      <tr class="soma"><td><?= $rotEquipe ?></td><td class="n"><?= $tB['v'] ?></td><td class="n"><?= $tA['v'] ?></td><td class="dlt"><?= dash_delta($tA['v'], $tB['v']) ?></td>
        <td class="n"><?= $tB['u'] ?></td><td class="n"><?= $tA['u'] ?></td><td class="dlt"><?= dash_delta($tA['u'], $tB['u']) ?></td><td class="n"><?= $R($tA['r']) ?></td></tr>
    </table></div>
  </section>

  <section class="card dh-card dh-metas">
    <h2>Metas de <?= h(mes_nome($mesD)) ?>
      <?php if ($admin): ?><form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="metas_copiar"><input type="hidden" name="mes" value="<?= h($mesD) ?>"><button>Copiar metas de <?= h(mes_nome($antD)) ?></button></form><?php endif ?>
    </h2>
    <p class="small" style="margin:0 0 8px"><?= $admin ? 'Digite a meta de cada vendedor e clique em Salvar. Campo vazio = sem meta.' : 'As metas são definidas pelo gestor admin.' ?> A barra mostra quanto já foi feito<?= $corrente ? '; o tracinho preto marca onde ele deveria estar hoje, pelo dia do mês' : '' ?>.</p>
    <?php if ($admin): ?><form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="metas_salvar"><?php endif ?><input type="hidden" name="mes" value="<?= h($mesD) ?>">
    <div class="tw"><table>
      <tr><th>Vendedor</th><th>Meta vendas</th><th>Vendas feitas</th><th>Progresso vendas</th><th>Meta upgrades</th><th>Upgrades feitos</th><th>Progresso upgrades</th><th>Situação</th></tr>
      <?php $situacao = function (array $a, ?int $mv, ?int $mu) use ($admin, $esperado, $corrente, $diasMes, $diaHoje): string {
          if (!$mv && !$mu) return '<span class="dh-chip q">' . ($admin ? 'definir meta' : 'sem meta') . '</span>';
          $ok = (!$mv || $a['v'] >= $mv * $esperado) && (!$mu || $a['u'] >= $mu * $esperado);
          if (!$corrente) return $ok ? '<span class="dh-chip ok">bateu a meta</span>' : '<span class="dh-chip er">não bateu</span>';
          if ($ok) return '<span class="dh-chip ok">no ritmo</span>';
          $falta = implode(' e ', array_filter([$mv && $a['v'] < $mv ? ($mv - $a['v']) . ' vendas' : '', $mu && $a['u'] < $mu ? ($mu - $a['u']) . ' upgrades' : '']));
          return '<span class="dh-chip er">abaixo do ritmo</span><div class="small">faltam ' . h($falta) . ' em ' . ($diasMes - $diaHoje) . ' dias</div>';
      };
      foreach ($vendD as $vid => $nome): $a = $de($num, $vid, $mesD); $mv = $metasD[$vid]['v'] ?? null; $mu = $metasD[$vid]['u'] ?? null; $sit = $situacao($a, $mv, $mu); ?>
      <tr><td><b><?= h($nome) ?></b></td>
        <td><?php if ($admin): ?><input type="number" min="0" inputmode="numeric" name="meta[<?= $vid ?>][v]" value="<?= $mv ?? '' ?>" aria-label="Meta de vendas de <?= h($nome) ?>"><?php else: ?><b><?= $mv ?? '—' ?></b><?php endif ?></td>
        <td class="n"><b><?= $a['v'] ?></b></td><td><?= dash_barra($a['v'], $mv, $esperado) ?></td>
        <td><?php if ($admin): ?><input type="number" min="0" inputmode="numeric" name="meta[<?= $vid ?>][u]" value="<?= $mu ?? '' ?>" aria-label="Meta de upgrades de <?= h($nome) ?>"><?php else: ?><b><?= $mu ?? '—' ?></b><?php endif ?></td>
        <td class="n"><b><?= $a['u'] ?></b></td><td><?= dash_barra($a['u'], $mu, $esperado) ?></td><td><?= $sit ?></td></tr>
      <?php endforeach ?>
      <tr class="soma"><td><?= $rotEquipe ?></td><td class="n"><?= $metaTV ?: '—' ?></td><td class="n"><?= $tA['v'] ?></td><td><?= dash_barra($tA['v'], $metaTV ?: null, $esperado) ?></td>
        <td class="n"><?= $metaTU ?: '—' ?></td><td class="n"><?= $tA['u'] ?></td><td><?= dash_barra($tA['u'], $metaTU ?: null, $esperado) ?></td><td><?= $supNome !== '' && ($metaTV || $metaTU) ? $situacao($tA, $metaTV ?: null, $metaTU ?: null) : '' ?></td></tr>
    </table></div>
    <?php if ($admin): ?><div class="row" style="justify-content:flex-end;margin-top:10px"><button class="pri">Salvar metas</button></div>
    </form><?php endif ?>
  </section>
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

<?php elseif ($p === 'comissoes'):
    $meses = comissoes_meses();
    $mesAtual = date('Y-m');
    $mes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? $_GET['mes'] : (array_key_first($meses) ?? $mesAtual);
    if (!isset($meses[$mes])) $meses[$mes] = ['n' => 0, 'abertas' => 0];
    krsort($meses);
    $filtro = ['vend' => (int)($_GET['vend'] ?? 0), 'tipo' => (string)($_GET['tipo'] ?? ''), 'pagou' => (string)($_GET['pagou'] ?? ''), 'paga' => (string)($_GET['paga'] ?? '')];
    // todas as vendas do mês (com os outros filtros), separadas por vendedor para conferir um de cada vez
    $todas = comissoes_do_mes($mes, ['vend' => 0] + $filtro);
    $porVend = comissoes_por_vendedor($todas);
    $linhas = $filtro['vend'] ? array_values(array_filter($todas, fn($l) => (int)$l['vendedor_id'] === $filtro['vend'])) : $todas;
    $tot = comissoes_totais($linhas);
    $ids = array_keys($porVend);
    $pos = $filtro['vend'] ? array_search($filtro['vend'], $ids, true) : false;
    $antes = $pos !== false && $pos > 0 ? $ids[$pos - 1] : null;
    $depois = $pos !== false && $pos < count($ids) - 1 ? $ids[$pos + 1] : null;
    $grupos = [];
    foreach ($linhas as $l) $grupos[(int)$l['vendedor_id']][] = $l;
    $opMeses = [];
    for ($m = $mes; $m <= mes_soma($mesAtual, 1); $m = mes_soma($m, 1)) $opMeses[] = $m;
    $pagoMes = (string)($_GET['pago'] ?? $mesAtual);
    if (!preg_match('/^\d{4}-\d{2}$/', $pagoMes)) $pagoMes = $mesAtual;
    $pagas = comissoes_pagas_em($pagoMes);
    $vendList = db()->query('SELECT id, nome FROM vendedores ORDER BY nome')->fetchAll();
    $vendNome = '';
    foreach ($vendList as $vd) if ((int)$vd['id'] === $filtro['vend']) $vendNome = $vd['nome'];
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
  <div class="card tw vds"><h2>Por vendedor <span class="small">· clique para conferir um de cada vez</span></h2>
    <table>
      <tr><th>Vendedor</th><th class="n">Vendas</th><th class="n">Upgrades</th><th class="n">2º ponto</th><th class="n">Comissão total</th><th class="n">Já paga</th><th class="n">A pagar</th><th class="n">Esperando o cliente</th><th>Situação</th></tr>
      <?php foreach ($porVend as $vid => $pv): $t = $pv['tot']; ?>
      <tr class="<?= $vid === $filtro['vend'] ? 'sel' : '' ?>">
        <td><a href="<?= h($qs(['vend' => $vid])) ?>"><b><?= h($pv['nome']) ?></b></a></td>
        <?php foreach (['venda', 'upgrade', 'segundo'] as $tp): ?><td class="n"><?= $pv['tipos'][$tp] ?? 0 ?></td><?php endforeach ?>
        <td class="n"><b>R$ <?= brl(number_format($t['total'], 2, '.', '')) ?></b></td>
        <td class="n">R$ <?= brl(number_format($t['paga'], 2, '.', '')) ?></td>
        <td class="n">R$ <?= brl(number_format($t['apagar'], 2, '.', '')) ?></td>
        <td class="n">R$ <?= brl(number_format($t['espera'], 2, '.', '')) ?></td>
        <td class="small"><?= $t['abertas'] ? $t['abertas'] . ' em aberto' : 'tudo pago' ?><?= $t['semvalor'] ? ' · ' . $t['semvalor'] . ' sem valor' : '' ?></td>
      </tr>
      <?php endforeach ?>
      <?php if (!$porVend): ?><tr><td colspan="9" class="small">Nenhuma venda nesse mês com esses filtros.</td></tr><?php endif ?>
    </table></div>
  <?php if ($filtro['vend']): ?>
  <div class="card navv">
    <a class="bt" href="<?= h($qs(['vend' => null])) ?>">Todos os vendedores</a>
    <div class="quem"><span class="small">Conferindo<?= $pos !== false ? ' · ' . ($pos + 1) . ' de ' . count($ids) : '' ?></span><b><?= h($vendNome ?: 'Vendedor') ?></b></div>
    <span class="setas"><?php if ($antes): ?><a class="bt" href="<?= h($qs(['vend' => $antes])) ?>">← <?= h($porVend[$antes]['nome']) ?></a><?php endif ?><?php if ($depois): ?><a class="bt pri" href="<?= h($qs(['vend' => $depois])) ?>"><?= h($porVend[$depois]['nome']) ?> →</a><?php endif ?></span>
  </div>
  <?php endif ?>
  <div class="kpis">
    <div><b><?= $tot['n'] ?></b><span>vendas em <?= h(mes_nome($mes)) ?><?= $filtro['vend'] ? ' · ' . h($vendNome) : '' ?></span></div>
    <div class="ok"><b>R$ <?= brl(number_format($tot['paga'], 2, '.', '')) ?></b><span>comissão já paga</span></div>
    <div class="q"><b>R$ <?= brl(number_format($tot['apagar'], 2, '.', '')) ?></b><span>a pagar (cliente já pagou)</span></div>
    <div><b>R$ <?= brl(number_format($tot['espera'], 2, '.', '')) ?></b><span>esperando o cliente<?= $tot['semvalor'] ? ' · ' . $tot['semvalor'] . ' sem valor' : '' ?></span></div>
  </div>
  <form method="post" class="card tw com"><?= csrf_campo() ?><input type="hidden" name="acao" value="comissoes_salvar">
    <table>
      <tr><th>ID cliente IXC</th><th>Cliente</th><th>Tipo</th><th>Plano</th><th>Valor do plano</th><th>Vencimento do boleto</th><th>Instalação</th><th>Roteador</th><th>Comissão</th><th>Cliente pagou?</th><th>Comissão paga em</th></tr>
      <?php foreach ($grupos as $vid => $doVend): $gt = comissoes_totais($doVend); ?>
      <tr class="vend-h"><td colspan="11"><b><?= h($doVend[0]['vendedor']) ?></b>
        <span><?= $gt['n'] ?> venda<?= $gt['n'] === 1 ? '' : 's' ?> · comissão R$ <?= brl(number_format($gt['total'], 2, '.', '')) ?> · paga R$ <?= brl(number_format($gt['paga'], 2, '.', '')) ?> · a pagar R$ <?= brl(number_format($gt['apagar'], 2, '.', '')) ?> · esperando R$ <?= brl(number_format($gt['espera'], 2, '.', '')) ?></span>
        <?php if (!$filtro['vend']): ?><a href="<?= h($qs(['vend' => $vid])) ?>">Conferir só este vendedor</a><?php endif ?></td></tr>
      <?php foreach ($doVend as $l): $k = 'c[' . (int)$l['id'] . ']'; $cls = $l['pago_em'] !== '' ? 'paga' : ($l['cliente_pagou'] === 'S' ? 'apagar' : ''); ?>
      <tr class="<?= $cls ?>">
        <td><input name="<?= $k ?>[ixc_cliente]" value="<?= h($l['ixc_cliente']) ?>" size="7" inputmode="numeric" aria-label="ID do cliente no IXC"></td>
        <td><b><?= h($l['nome']) ?></b><br><span class="small">venda <?= h(date('d/m', strtotime($l['recebido_em']))) ?> · <?= h($l['cpf']) ?></span></td>
        <td><span class="tp tp-<?= h($l['tipo']) ?>"><?= h(COMISSAO_TIPOS[$l['tipo']] ?? $l['tipo']) ?></span></td>
        <td class="small"><?= h($l['plano']) ?></td>
        <td><span class="rs"><input name="<?= $k ?>[valor_plano]" value="<?= h(brl($l['valor_plano'])) ?>" size="6" inputmode="decimal" placeholder="0,00" aria-label="Valor mensal do plano"></span></td>
        <td><input type="date" name="<?= $k ?>[vencimento]" value="<?= h($l['vencimento']) ?>" aria-label="Vencimento do boleto"><?= $l['vencimento'] === '' && $l['venc_previsto'] !== '' ? '<br><span class="small prev" title="O IXC ainda não tem o boleto. A data real entra sozinha quando ele for gerado.">previsto ' . h(date('d/m/Y', strtotime($l['venc_previsto']))) . '</span>' : ($l['dia_venc'] !== '' && $l['vencimento'] === '' ? '<br><span class="small">dia ' . h($l['dia_venc']) . '</span>' : '') ?></td>
        <td><span class="rs"><input name="<?= $k ?>[instalacao]" value="<?= h(brl($l['instalacao'])) ?>" size="6" inputmode="decimal" placeholder="0,00" aria-label="Valor da instalação"></span></td>
        <td><span class="rs"><input name="<?= $k ?>[roteador]" value="<?= h(brl($l['roteador'])) ?>" size="6" inputmode="decimal" placeholder="0,00" aria-label="Valor do roteador"></span></td>
        <td><span class="rs"><input name="<?= $k ?>[valor]" value="<?= h(brl($l['valor'])) ?>" size="6" inputmode="decimal" placeholder="0,00" aria-label="Valor da comissão" class="forte"></span></td>
        <td><select name="<?= $k ?>[cliente_pagou]" aria-label="Cliente pagou"><option value="">A vencer</option><option value="S" <?= $l['cliente_pagou'] === 'S' ? 'selected' : '' ?>>Sim</option><option value="N" <?= $l['cliente_pagou'] === 'N' ? 'selected' : '' ?>>Não</option></select></td>
        <td><select name="<?= $k ?>[pago_em]" aria-label="Comissão paga em"><option value="">Não paga</option><?php foreach ($opMeses as $om): ?><option value="<?= $om ?>" <?= $l['pago_em'] === $om ? 'selected' : '' ?>><?= h(mes_nome($om)) ?></option><?php endforeach ?></select>
          <input type="hidden" name="<?= $k ?>[obs]" value="<?= h($l['obs']) ?>"></td>
      </tr>
      <?php endforeach ?>
      <?php endforeach ?>
      <?php if (!$linhas): ?><tr><td colspan="11" class="small">Nenhuma venda nesse mês com esses filtros.</td></tr><?php endif ?>
    </table>
    <?php if ($linhas): ?><div class="salvar"><span class="small">Mudou alguma coisa? Salve antes de trocar de mês ou de vendedor.</span><button class="pri">Salvar comissões</button></div><?php endif ?>
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

<?php /* ---- PLANILHA-TEMP: comissões da planilha do Excel (apagar este bloco inteiro quando quitar tudo) ---- */ ?>

<?php elseif ($p === 'planilha'):
    $plVend = (string)($_GET['vend'] ?? '');
    $plAbertas = !empty($_GET['abertas']);
    $plTodas = planilha_linhas();
    $plPorVend = [];
    foreach ($plTodas as $l) $plPorVend[$l['vendedor']][] = $l;
    // um vendedor por vez: com todos juntos o formulário passa do limite do PHP (max_input_vars)
    if (!isset($plPorVend[$plVend])) $plVend = (string)(array_key_first($plPorVend) ?? '');
    $plLinhas = planilha_linhas($plVend, $plAbertas);
    $plGrupos = [];
    foreach ($plLinhas as $l) $plGrupos[$l['vendedor']][] = $l;
    $plTot = planilha_totais($plVend !== '' ? ($plPorVend[$plVend] ?? []) : $plTodas);
    $plIni = min(array_merge([date('Y-m')], array_filter(array_column($plTodas, 'pago_em'))));
    $plOp = [];
    for ($m = mes_soma($plIni, -1); $m <= mes_soma(date('Y-m'), 1); $m = mes_soma($m, 1)) $plOp[] = $m;
    $plPagas = planilha_pagas_por_mes();
    $plQs = fn(array $mais) => '?' . http_build_query(array_filter(array_merge(['p' => 'planilha', 'vend' => $plVend, 'abertas' => $plAbertas ? 1 : null], $mais), fn($v) => $v !== null && $v !== ''));
    $plSelPaga = function (string $nome, string $atual) use ($plOp): string {
        $o = '<select name="' . $nome . '" aria-label="Comissão paga em"><option value="">Não paga</option>';
        foreach ($plOp as $om) $o .= '<option value="' . $om . '"' . ($atual === $om ? ' selected' : '') . '>' . h(mes_nome($om)) . '</option>';
        return $o . '</select>';
    }; ?>
  <div class="card"><h2>Comissões planilha <span class="pill q">temporário</span></h2>
    <p class="small">Comissões que estavam na planilha do Excel, até pagar todas. Cada comissão é paga uma vez só: quando pagar o vendedor, escolha o mês em "Comissão paga em" e salve. O que já estava pago na planilha entrou com o mês da planilha. Linha sem valor é porque a planilha não tinha: digite quando souber.</p>
    <?php if ($plTodas): ?>
    <form method="get" class="row filtros"><input type="hidden" name="p" value="planilha">
      <select name="vend" aria-label="Vendedor"><?php foreach (array_keys($plPorVend) as $vn): ?><option value="<?= h($vn) ?>" <?= $vn === $plVend ? 'selected' : '' ?>><?= h($vn) ?></option><?php endforeach ?></select>
      <label><input type="checkbox" name="abertas" value="1" <?= $plAbertas ? 'checked' : '' ?>> só o que falta pagar</label>
      <button class="pri">Filtrar</button></form>
    <?php endif ?>
    <details <?= $plTodas ? '' : 'open' ?> style="margin-top:10px"><summary><b><?= $plTodas ? 'Importar de novo' : 'Importar a planilha' ?></b></summary>
      <form method="post" enctype="multipart/form-data" class="row" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="acao" value="planilha_importar">
        <input type="file" name="csv" accept=".csv,text/csv" required aria-label="Arquivo CSV">
        <?php if ($plTodas): ?><label><input type="checkbox" name="substituir" value="1" required> trocar tudo (perde o que foi marcado aqui)</label><?php endif ?>
        <button class="pri">Importar</button><span class="small">Arquivo comissoes-planilha.csv, feito a partir do CONTROLE_COMERCIAL.xlsm.</span></form></details>
  </div>
  <?php if ($plTodas): ?>
  <div class="card tw vds"><h2>Por vendedor</h2>
    <table>
      <tr><th>Vendedor</th><th class="n">Vendas</th><th class="n">Upgrades</th><th class="n">Comissão total</th><th class="n">Já paga</th><th class="n">Falta pagar</th><th>Situação</th></tr>
      <?php foreach ($plPorVend as $vn => $ls): $t = planilha_totais($ls); $nv = count(array_filter($ls, fn($l) => $l['tipo'] === 'venda')); ?>
      <tr class="<?= $vn === $plVend ? 'sel' : '' ?>"><td><a href="<?= h($plQs(['vend' => $vn])) ?>"><b><?= h($vn) ?></b></a></td>
        <td class="n"><?= $nv ?></td><td class="n"><?= count($ls) - $nv ?></td>
        <td class="n"><b>R$ <?= brl(number_format($t['total'], 2, '.', '')) ?></b></td>
        <td class="n">R$ <?= brl(number_format($t['pago'], 2, '.', '')) ?></td>
        <td class="n">R$ <?= brl(number_format($t['falta'], 2, '.', '')) ?></td>
        <td class="small"><?= $t['abertas'] ? $t['abertas'] . ' em aberto' : 'tudo pago' ?><?= $t['semvalor'] ? ' · ' . $t['semvalor'] . ' sem valor' : '' ?></td></tr>
      <?php endforeach ?>
    </table></div>
  <div class="kpis">
    <div><b><?= $plTot['n'] ?></b><span>linhas<?= $plVend !== '' ? ' · ' . h($plVend) : '' ?></span></div>
    <div class="ok"><b>R$ <?= brl(number_format($plTot['pago'], 2, '.', '')) ?></b><span>já pago</span></div>
    <div class="q"><b>R$ <?= brl(number_format($plTot['falta'], 2, '.', '')) ?></b><span>falta pagar · <?= $plTot['abertas'] ?> em aberto</span></div>
    <div><b><?= $plTot['semvalor'] ?></b><span>sem valor na planilha</span></div>
  </div>
  <form method="post" class="card tw com pl"><?= csrf_campo() ?><input type="hidden" name="acao" value="planilha_salvar">
    <table>
      <tr><th>Tipo</th><th>ID IXC</th><th>Cliente</th><th>Valor</th><th>1ª mensalidade</th><th>Mensalidade paga?</th>
        <th>Comissão</th><th>Comissão paga em</th><th>Observação</th></tr>
      <?php foreach ($plGrupos as $vn => $ls): $gt = planilha_totais($ls); ?>
      <tr class="vend-h"><td colspan="10"><b><?= h($vn) ?></b>
        <span><?= count($ls) ?> linha<?= count($ls) === 1 ? '' : 's' ?> · comissão R$ <?= brl(number_format($gt['total'], 2, '.', '')) ?> · paga R$ <?= brl(number_format($gt['pago'], 2, '.', '')) ?> · falta R$ <?= brl(number_format($gt['falta'], 2, '.', '')) ?></span>
        </td></tr>
      <?php foreach ($ls as $l): $k = 'pl[' . (int)$l['id'] . ']'; ?>
      <tr class="<?= $l['pago_em'] !== '' ? 'paga' : '' ?>">
        <td><span class="tp tp-<?= h($l['tipo']) ?>"><?= $l['tipo'] === 'upgrade' ? 'Upgrade' : 'Venda' ?></span></td>
        <td class="n"><?= h($l['ixc_cliente']) ?></td>
        <td><b><?= h($l['nome']) ?></b><?= $l['condicao'] !== '' ? '<br><span class="small">' . h($l['condicao']) . '</span>' : '' ?></td>
        <td class="n"><?= $l['tipo'] === 'upgrade' ? 'R$ ' . h(brl($l['valor_antigo'])) . ' → ' . h(brl($l['valor_plano'])) : ($l['valor_plano'] !== '' ? 'R$ ' . h(brl($l['valor_plano'])) : '—') ?>
          <?= ($l['instalacao'] !== '' || $l['roteador'] !== '') ? '<br><span class="small">taxas R$ ' . h(brl(number_format((float)$l['instalacao'] + (float)$l['roteador'], 2, '.', ''))) . '</span>' : '' ?></td>
        <td class="n"><?= $l['vencimento'] !== '' ? h(date('d/m/Y', strtotime($l['vencimento']))) : '—' ?></td>
        <td><?php if ($l['tipo'] === 'venda'): ?><select name="<?= $k ?>[mensalidade]" aria-label="Mensalidade paga"><option value="">—</option><?php foreach (PLANILHA_MENSALIDADE as $o): ?><option <?= $l['mensalidade'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach ?></select><?php else: ?><span class="small">—</span><?php endif ?></td>
        <td><span class="rs"><input name="<?= $k ?>[valor]" value="<?= h(brl($l['valor'])) ?>" inputmode="decimal" placeholder="0,00" aria-label="Valor da comissão" class="forte"></span></td>
        <td><?= $plSelPaga($k . '[pago_em]', $l['pago_em']) ?></td>
        <td><input name="<?= $k ?>[obs]" value="<?= h($l['obs']) ?>" class="obs" aria-label="Observação" title="<?= h($l['obs']) ?>"></td>
      </tr>
      <?php endforeach ?>
      <?php endforeach ?>
      <?php if (!$plLinhas): ?><tr><td colspan="10" class="small">Nada com esse filtro.</td></tr><?php endif ?>
    </table>
    <?php if ($plLinhas): ?><div class="salvar"><span class="small">Mudou alguma coisa? Salve antes de trocar de vendedor.</span><input type="hidden" name="pl_fim" value="1"><button class="pri">Salvar</button></div><?php endif ?>
  </form>
  <div class="card tw"><h2>Pago por mês (planilha)</h2>
    <table><tr><th>Mês do pagamento</th><th>Vendedor</th><th class="n">Total</th></tr>
      <?php foreach ($plPagas as $pm => $porV): foreach ($porV as $vn => $v): ?>
      <tr><td><?= h(mes_nome((string)$pm)) ?></td><td><?= h($vn) ?></td><td class="n">R$ <?= brl(number_format($v, 2, '.', '')) ?></td></tr>
      <?php endforeach; ?><tr class="soma"><td><?= h(mes_nome((string)$pm)) ?></td><td>Total do mês</td><td class="n">R$ <?= brl(number_format(array_sum($porV), 2, '.', '')) ?></td></tr><?php endforeach ?>
      <?php if (!$plPagas): ?><tr><td colspan="3" class="small">Nenhuma comissão marcada como paga.</td></tr><?php endif ?>
    </table></div>
  <?php endif ?>
<?php /* ---- fim PLANILHA-TEMP ---- */ ?>

<?php elseif ($p === 'recolhimentos'):
    $mesesR = rec_meses();
    $mesR = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? (string)$_GET['mes'] : date('Y-m');
    if (!in_array($mesR, $mesesR, true)) { $mesesR[] = $mesR; rsort($mesesR); }
    $buscaR = trim((string)($_GET['q'] ?? ''));
    $linR = rec_do_mes($mesR, $buscaR);
    $tecsR = rec_tecnicos();
    $edit = $recForm;
    if ($edit === null && ($eid = (int)($_GET['editar'] ?? 0)) && ($er = rec_um($eid)) && $er['pago_em'] === '') {
        $edit = $er + ['id' => $eid];
        $edit['eq'] = array_map(fn($e) => ['mac' => $e['mac'], 'modelo' => $e['modelo']], rec_equipamentos($er));
    }
    $abrirForm = $edit !== null || isset($_GET['novo']);
    $edit ??= ['id' => 0, 'funcionando' => 'SIM'];
    $eqForm = (array)($edit['eq'] ?? []);
    if (empty($edit['linhas_eq'])) $eqForm = array_filter($eqForm, fn($e) => trim((string)($e['mac'] ?? '')) !== '' || trim((string)($e['modelo'] ?? '')) !== '');
    $eqForm = array_values($eqForm);
    // começa com 1 equipamento; "+ Adicionar equipamento" põe mais uma linha (a corrigir mostra as que já tem)
    $nLinhasEq = max(1, count($eqForm), min(50, (int)($edit['linhas_eq'] ?? 0)));
    while (count($eqForm) < $nLinhasEq) $eqForm[] = ['mac' => '', 'modelo' => ''];
    $modelosUsados = rec_modelos_usados();
    $eqR = array_sum(array_map(fn($r) => (int)$r['qtd'], $linR));
    $eqF = array_sum(array_map(fn($r) => $r['funcionando'] === 'SIM' ? (int)$r['qtd'] : 0, $linR));
    $vR = fn(string $k) => h((string)($edit[$k] ?? ''));
    $linkMes = '?p=recolhimentos&amp;mes=' . h($mesR);
?>
  <div class="topo"><div><h1>Recolhimentos</h1><p class="small">O financeiro lança aqui as O.S de recolhimento que já fez no IXC, como na planilha do mês. O sistema não abre nem fecha O.S e não grava nada no IXC: serve só para a comissão.</p></div>
    <a class="bt" href="<?= $linkMes ?>&amp;novo=1#form-rec" style="background:var(--g-cta);border:0;color:#1c1203;height:46px;display:inline-flex;align-items:center;padding:0 18px;border-radius:14px;font-weight:800">+ Lançar O.S de recolhimento</a></div>
  <form method="get" class="row"><input type="hidden" name="p" value="recolhimentos">
    <select name="mes" aria-label="Mês do fechamento" data-auto><?php foreach ($mesesR as $om): ?><option value="<?= h($om) ?>" <?= $om === $mesR ? 'selected' : '' ?>><?= h(mes_nome($om)) ?></option><?php endforeach ?></select>
    <input name="q" value="<?= h($buscaR) ?>" placeholder="Buscar nome, contrato, O.S, MAC ou modelo" aria-label="Buscar"><button>Buscar</button>
    <?php if ($buscaR !== ''): ?><a href="<?= $linkMes ?>">limpar</a><?php endif ?></form>
  <div class="kpis">
    <div class="kpi forte"><span>Equipamentos em <?= h(mes_nome($mesR)) ?></span><b><?= $eqR ?></b><small>pela data de fechamento da O.S</small></div>
    <div class="kpi"><span>O.S lançadas</span><b><?= count($linR) ?></b><small><?= $buscaR !== '' ? 'na busca' : 'no mês' ?></small></div>
    <div class="kpi"><span>Funcionando</span><b class="ok"><?= $eqF ?></b><small>equipamentos</small></div>
    <div class="kpi"><span>Sem funcionar</span><b class="er"><?= $eqR - $eqF ?></b><small>equipamentos</small></div>
  </div>
  <?php if ($abrirForm): ?>
  <form method="post" action="<?= $linkMes ?>" class="card" id="form-rec"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <button name="acao" value="rec_salvar" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px">Salvar</button><?php /* Enter salva (não busca o nome) */ ?>
    <h2><?= $edit['id'] ? 'Corrigir O.S de recolhimento' : 'Lançar O.S de recolhimento' ?></h2>
    <?php if (!$tecsR): ?><div class="msg">Ainda não tem recolhedor cadastrado. <?= $admin ? 'Cadastre em Configurações → Permissões, no fim da página.' : 'Peça ao admin para cadastrar em Configurações.' ?></div><?php endif ?>
    <div class="rec-form">
      <label>Nº da O.S no IXC<span class="comb"><input name="r[os]" value="<?= $vR('os') ?>" inputmode="numeric" placeholder="ex.: 104532">
        <?php if (destino() === 'ixc'): ?><button name="acao" value="rec_buscar" formnovalidate title="Só lê a O.S no IXC: cliente, contrato, abertura e fechamento">Buscar O.S</button><?php endif ?></span></label>
      <label>ID do contrato<input name="r[contrato]" value="<?= $vR('contrato') ?>" inputmode="numeric" placeholder="vem da O.S" required></label>
      <label>Nome do cliente<input name="r[cliente]" value="<?= $vR('cliente') ?>" placeholder="vem da O.S" required></label>
      <label>Abertura da O.S<input type="date" name="r[abertura]" value="<?= $vR('abertura') ?>" max="<?= date('Y-m-d') ?>"></label>
      <label>Fechamento da O.S<input type="date" name="r[fechamento]" value="<?= $vR('fechamento') ?>" max="<?= date('Y-m-d') ?>" required></label>
      <label>Funcionando?<select name="r[funcionando]"><option>SIM</option><option <?= ($edit['funcionando'] ?? '') === 'NÃO' ? 'selected' : '' ?>>NÃO</option></select></label>
      <label>Quem recolheu<select name="r[tecnico_id]" required><option value="">escolha</option><?php foreach ($tecsR as $tc): if (!$tc['ativo'] && (int)$tc['id'] !== (int)($edit['tecnico_id'] ?? 0)) continue; ?><option value="<?= (int)$tc['id'] ?>" <?= (int)$tc['id'] === (int)($edit['tecnico_id'] ?? 0) ? 'selected' : '' ?>><?= h($tc['nome']) ?></option><?php endforeach ?></select></label>
      <div class="larga"><b class="small" style="font-weight:700">Equipamentos recolhidos (o nº de equipamentos é contado sozinho; linha vazia é ignorada)</b>
        <div class="rec-eqs"><?php foreach ($eqForm as $n => $e): ?>
          <label>MAC <?= $n + 1 ?><input name="r[eq][<?= $n ?>][mac]" value="<?= h((string)($e['mac'] ?? '')) ?>" placeholder="CCB1822D48C7" autocapitalize="characters" <?= $n === 0 ? 'required' : '' ?>></label>
          <label>Modelo <?= $n + 1 ?><input name="r[eq][<?= $n ?>][modelo]" value="<?= h((string)($e['modelo'] ?? '')) ?>" list="rec-modelos" placeholder="ex.: ONU ZTE F670L" <?= $n === 0 ? 'required' : '' ?>></label>
        <?php endforeach ?></div>
        <datalist id="rec-modelos"><?php foreach ($modelosUsados as $mu): ?><option value="<?= h($mu) ?>"><?php endforeach ?></datalist>
        <button id="eq-fim" name="acao" value="rec_mais" formnovalidate formaction="<?= $linkMes ?>#eq-fim">+ Adicionar equipamento</button></div>
      <label class="larga">Observação<input name="r[obs]" value="<?= $vR('obs') ?>" maxlength="300"></label>
    </div>
    <div class="row" style="margin-top:10px"><button class="pri" name="acao" value="rec_salvar"><?= $edit['id'] ? 'Salvar correção' : 'Salvar O.S' ?></button><a href="<?= $linkMes ?>">Cancelar</a>
      <span class="small">Quem lançou fica gravado. Depois que a comissão é paga, a O.S não muda mais.</span></div>
  </form>
  <?php endif ?>
  <div class="card tw"><table class="rec">
    <thead><tr><th>Cliente</th><th>Contrato</th><th>Nº equip.</th><th>MAC e modelo</th><th>Abertura O.S</th><th>Fechamento O.S</th><th>Funcionando</th><th>Recolhedor</th><th>Lançado por</th><th></th></tr></thead>
    <?php foreach ($linR as $r): ?>
    <tr><td><b><?= h($r['cliente']) ?></b><?php if ($r['os'] !== ''): ?><br><span class="small">O.S <?= h($r['os']) ?></span><?php endif ?><?php if ($r['obs'] !== ''): ?><br><span class="small"><?= h($r['obs']) ?></span><?php endif ?></td>
      <td class="n"><?= h($r['contrato']) ?></td><td class="n"><?= (int)$r['qtd'] ?></td>
      <td><?php foreach (rec_equipamentos($r) as $e): ?><span class="mac"><?= h($e['mac']) ?></span> <span class="small"><?= h($e['modelo'] ?: 'sem modelo') ?></span><br><?php endforeach ?></td>
      <td class="n"><?= h(rec_dt($r['abertura']) ?: '—') ?></td><td class="n"><?= h(rec_dt($r['fechamento'])) ?></td>
      <td><?= $r['funcionando'] === 'SIM' ? '<span class="pill ok">Sim</span>' : '<span class="pill er">Não</span>' ?></td>
      <td><?= h($r['tecnico']) ?></td><td class="small"><?= h($r['lancado_nome']) ?></td>
      <td><div class="acoes"><?php if ($r['pago_em'] !== ''): ?><span class="tp pago">pago</span>
        <?php else: ?><a class="bt" href="<?= $linkMes ?>&amp;editar=<?= (int)$r['id'] ?>#form-rec">Corrigir</a>
        <form method="post" action="<?= $linkMes ?>" class="in"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="del" name="acao" value="rec_excluir" title="Excluir esta O.S">Excluir</button></form><?php endif ?></div></td></tr>
    <?php endforeach ?>
    <?php if (!$linR): ?><tr><td colspan="10" class="small">Nenhuma O.S <?= $buscaR !== '' ? 'nessa busca' : 'fechada em ' . h(mes_nome($mesR)) ?>.</td></tr><?php endif ?>
    <tfoot><tr><td colspan="2">TOTAL</td><td class="n"><?= $eqR ?></td><td colspan="7" class="small">equipamentos nas O.S listadas</td></tr></tfoot>
  </table></div>

<?php elseif ($p === 'rec_comissao'):
    $mesesR = rec_meses();
    $mesR = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? (string)$_GET['mes'] : date('Y-m');
    if (!in_array($mesR, $mesesR, true)) { $mesesR[] = $mesR; rsort($mesesR); }
    $linR = rec_do_mes($mesR);
    $regraR = rec_regra();
    $pesR = rec_por_pessoa($linR);
    $totR = array_sum(array_map(fn($r) => (float)($r['valor_fin'] ?: 0) + (float)($r['valor_tec'] ?: 0), $linR));
    $faltaR = array_sum(array_map(fn($r) => $r['pago_em'] === '' ? (float)($r['valor_fin'] ?: 0) + (float)($r['valor_tec'] ?: 0) : 0, $linR));
    $confR = count(array_filter($linR, fn($r) => $r['conferido']));
    $semValor = count(array_filter($linR, fn($r) => $r['pago_em'] === '' && ($r['valor_fin'] === '' || $r['valor_tec'] === '')));
    $Rr = fn(float $v) => 'R$ ' . brl(number_format($v, 2, '.', ''));
    $linkMes = '?p=rec_comissao&amp;mes=' . h($mesR);
    $modR = rec_por_modelo($linR);
?>
  <div class="topo"><div><h1>Comissão do recolhimento</h1><p class="small">Só o admin e quem tem a permissão "Comissão do recolhimento". Confira cada O.S lançada pelo financeiro, ajuste o valor linha por linha, marque ✔ e registre o mês em que pagou. Paga uma vez só.</p></div>
    <form method="get" class="in"><input type="hidden" name="p" value="rec_comissao"><select name="mes" aria-label="Mês do fechamento" data-auto><?php foreach ($mesesR as $om): ?><option value="<?= h($om) ?>" <?= $om === $mesR ? 'selected' : '' ?>><?= h(mes_nome($om)) ?></option><?php endforeach ?></select><noscript><button>Ver</button></noscript></form></div>
  <form method="post" action="<?= $linkMes ?>" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="rec_regra_salvar"><input type="hidden" name="mes" value="<?= h($mesR) ?>">
    <h2>Valor padrão</h2>
    <p class="small" style="margin:0 0 8px">Sugestão que já vem preenchida nas linhas sem valor. Você muda o que precisar linha por linha.</p>
    <div class="row">
      <label>Financeiro (lançou a O.S) R$ <input name="valor_fin" value="<?= h(brl($regraR['valor_fin'])) ?>" size="7" inputmode="decimal"></label>
      <label>Recolhedor R$ <input name="valor_tec" value="<?= h(brl($regraR['valor_tec'])) ?>" size="7" inputmode="decimal"></label>
      <select name="base" aria-label="Como conta"><option value="eq">por equipamento</option><option value="os" <?= $regraR['base'] === 'os' ? 'selected' : '' ?>>por O.S</option></select>
      <label><input type="checkbox" name="so_funcionando" value="1" <?= $regraR['so_funcionando'] ? 'checked' : '' ?>> recolhedor não recebe O.S sem funcionar</label>
      <label><input type="checkbox" name="sobrescrever" value="1"> trocar também os valores já digitados (não conferidos)</label>
      <button class="pri">Salvar e preencher <?= h(mes_nome($mesR)) ?></button></div>
  </form>
  <div class="kpis">
    <div class="kpi forte"><span>Total de <?= h(mes_nome($mesR)) ?></span><b><?= $Rr($totR) ?></b><small><?= count($linR) ?> O.S lançadas</small></div>
    <div class="kpi"><span>Conferidas</span><b class="ok"><?= $confR ?> de <?= count($linR) ?></b><small>marcadas com ✔</small></div>
    <div class="kpi"><span>Falta pagar</span><b class="q"><?= $Rr($faltaR) ?></b><small><?= $semValor ? "$semValor O.S ainda sem valor" : 'sem mês de pagamento' ?></small></div>
  </div>
  <?php if ($modR): ?><div class="card"><h2>Modelos recolhidos em <?= h(mes_nome($mesR)) ?></h2><div class="row"><?php foreach ($modR as $mn => $mq): ?><span class="pill mu"><?= h($mn) ?>: <?= (int)$mq ?></span><?php endforeach ?></div></div><?php endif ?>
  <?php if ($pesR): ?><div class="rec-pes"><?php foreach ($pesR as $pp): ?>
    <div class="card"><b><?= h($pp['nome']) ?></b><span class="papel"><?= h($pp['papel']) ?></span><span class="tot"><?= $Rr($pp['valor']) ?></span>
      <span class="small"><?= $pp['os'] ?> O.S · <?= $pp['eq'] ?> equipamentos<?= $pp['falta'] > 0 && $pp['falta'] < $pp['valor'] ? ' · falta pagar ' . $Rr($pp['falta']) : ($pp['falta'] <= 0 && $pp['valor'] > 0 ? ' · pago' : '') ?></span></div>
  <?php endforeach ?></div><?php endif ?>
  <form method="post" action="<?= $linkMes ?>" class="card"><?= csrf_campo() ?><input type="hidden" name="mes" value="<?= h($mesR) ?>">
    <h2>Conferência linha por linha</h2>
    <div class="tw"><table class="rec">
      <thead><tr><th>✔</th><th>Cliente</th><th>Equipamentos (modelo)</th><th>Fechou</th><th>Func.</th><th>Quem lançou</th><th>R$ quem lançou</th><th>Recolhedor</th><th>R$ recolhedor</th><th>Pago em</th></tr></thead>
      <?php foreach ($linR as $r): $i = (int)$r['id']; $dis = $r['pago_em'] !== '' ? 'disabled' : '';
          [$sf, $st] = rec_sugestao($r, $regraR); ?>
      <tr><td class="ck"><?php if (!$dis): ?><input type="hidden" name="c[<?= $i ?>][ok]" value="0"><?php endif ?><input type="checkbox" name="c[<?= $i ?>][ok]" value="1" <?= $r['conferido'] ? 'checked' : '' ?> <?= $dis ?> aria-label="Conferido <?= h($r['cliente']) ?>"></td>
        <td><b><?= h($r['cliente']) ?></b><br><span class="small">contrato <?= h($r['contrato']) ?><?= $r['os'] !== '' ? ' · O.S ' . h($r['os']) : '' ?></span></td>
        <td><?php foreach (rec_equipamentos($r) as $e): ?><?= h($e['modelo'] ?: 'sem modelo') ?><br><?php endforeach ?><span class="small"><?= (int)$r['qtd'] ?> equip.</span></td><td class="n"><?= h(rec_dt($r['fechamento'])) ?></td>
        <td><?= $r['funcionando'] === 'SIM' ? '<span class="pill ok">Sim</span>' : '<span class="pill er">Não</span>' ?></td>
        <td class="small"><?= h($r['lancado_nome']) ?></td>
        <td><input class="val" name="c[<?= $i ?>][vf]" value="<?= h(brl($r['valor_fin'])) ?>" placeholder="<?= h(brl($sf)) ?>" inputmode="decimal" <?= $dis ?> aria-label="Valor do financeiro"></td>
        <td class="small"><?= h($r['tecnico']) ?></td>
        <td><input class="val" name="c[<?= $i ?>][vt]" value="<?= h(brl($r['valor_tec'])) ?>" placeholder="<?= h(brl($st)) ?>" inputmode="decimal" <?= $dis ?> aria-label="Valor do recolhedor"></td>
        <td><?= $r['pago_em'] !== '' ? '<span class="tp pago">' . h(mes_nome($r['pago_em'])) . '</span>' : '<span class="small">—</span>' ?></td></tr>
      <?php endforeach ?>
      <?php if (!$linR): ?><tr><td colspan="10" class="small">Nenhuma O.S fechada em <?= h(mes_nome($mesR)) ?>.</td></tr><?php endif ?>
    </table></div>
    <div class="row" style="margin-top:10px;justify-content:space-between">
      <button name="acao" value="rec_com_salvar">Salvar valores e conferência</button>
      <span class="row"><button class="pri" name="acao" value="rec_pagar">Salvar e marcar conferidas como pagas em</button>
        <select name="pago_em" aria-label="Mês do pagamento"><?php for ($k = -1; $k <= 2; $k++): $om = mes_soma(date('Y-m'), $k); ?><option value="<?= $om ?>" <?= $k === 0 ? 'selected' : '' ?>><?= h(mes_nome($om)) ?></option><?php endfor ?></select></span>
    </div>
    <p class="small">Valor em cinza é a sugestão do valor padrão: só vale depois de salvo (use "Salvar e preencher" acima). Linha corrigida pelo financeiro depois de conferida volta sem ✔ se mudar equipamentos, recolhedor ou funcionando.</p>
  </form>

<?php elseif ($p === 'instalacoes'):
    $mesesI = inst_meses();
    $mesI = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? (string)$_GET['mes'] : date('Y-m');
    if (!in_array($mesI, $mesesI, true)) { $mesesI[] = $mesI; rsort($mesesI); }
    $buscaI = trim((string)($_GET['q'] ?? ''));
    $linI = inst_do_mes($mesI, $buscaI);
    $tecsI = inst_tecnicos();
    $servI = inst_lista('servico');
    $agsI = inst_lista('agenda');
    $edit = $instForm;
    if ($edit === null && ($eid = (int)($_GET['editar'] ?? 0)) && ($er = inst_um($eid)) && $er['pago_em'] === '') {
        $edit = $er + ['id' => $eid];
        $edit['eq'] = array_map(fn($e) => ['mac' => $e['mac'], 'modelo' => $e['modelo']], rec_equipamentos($er));
        $edit['tec'] = array_column($er['tecs'], 'id');
    }
    $abrirForm = $edit !== null || isset($_GET['novo']);
    $edit ??= ['id' => 0];
    // equipamentos recolhidos: começa com 1 linha (pode ficar vazia); "+ Adicionar equipamento" põe mais uma
    $eqForm = array_values((array)($edit['eq'] ?? []));
    if (!isset($edit['linhas_eq'])) $eqForm = array_values(array_filter($eqForm, fn($e) => trim((string)($e['mac'] ?? '')) !== '' || trim((string)($e['modelo'] ?? '')) !== ''));
    $nLinhasEq = max(1, count($eqForm), min(50, (int)($edit['linhas_eq'] ?? 0)));
    while (count($eqForm) < $nLinhasEq) $eqForm[] = ['mac' => '', 'modelo' => ''];
    // técnicos: começa com 1; "+ Adicionar técnico" põe mais um
    $tecForm = array_values(array_map('intval', (array)($edit['tec'] ?? [])));
    if (empty($edit['agendador_id']) && empty($edit['id']) && $instForm === null && count($soAg = inst_lista('agenda', true)) === 1) $edit['agendador_id'] = (int)$soAg[0]['id']; // um só agenda: já vem escolhido
    if (!$tecForm && count($soAtivosI = inst_tecnicos(true)) === 1) $tecForm = [(int)$soAtivosI[0]['id']]; // um técnico só: já vem escolhido
    $nLinhasTec = max(1, count($tecForm), min(INST_MAX_TEC, (int)($edit['linhas_tec'] ?? 0)));
    while (count($tecForm) < $nLinhasTec) $tecForm[] = 0;
    $modelosUsados = rec_modelos_usados();
    $eqI = array_sum(array_map(fn($r) => (int)$r['qtd'], $linI));
    $duplaI = count(array_filter($linI, fn($r) => count($r['tecs']) > 1));
    $vI = fn(string $k) => h((string)($edit[$k] ?? ''));
    $linkMes = '?p=instalacoes&amp;mes=' . h($mesI);
?>
  <div class="topo"><div><h1>Instalações concluídas</h1><p class="small">Lance aqui as O.S de instalação que já foram feitas no IXC, com o tipo de serviço, os técnicos que fizeram, quem agendou e os equipamentos recolhidos na instalação. O sistema não abre nem fecha O.S e não grava nada no IXC: serve só para a comissão.</p></div>
    <a class="bt" href="<?= $linkMes ?>&amp;novo=1#form-inst" style="background:var(--g-cta);border:0;color:#1c1203;height:46px;display:inline-flex;align-items:center;padding:0 18px;border-radius:14px;font-weight:800">+ Lançar O.S de instalação</a></div>
  <form method="get" class="row"><input type="hidden" name="p" value="instalacoes">
    <select name="mes" aria-label="Mês do fechamento" data-auto><?php foreach ($mesesI as $om): ?><option value="<?= h($om) ?>" <?= $om === $mesI ? 'selected' : '' ?>><?= h(mes_nome($om)) ?></option><?php endforeach ?></select>
    <input name="q" value="<?= h($buscaI) ?>" placeholder="Buscar nome, contrato, O.S, técnico ou MAC" aria-label="Buscar"><button>Buscar</button>
    <?php if ($buscaI !== ''): ?><a href="<?= $linkMes ?>">limpar</a><?php endif ?></form>
  <div class="kpis">
    <div class="kpi forte"><span>Instalações em <?= h(mes_nome($mesI)) ?></span><b><?= count($linI) ?></b><small><?= $buscaI !== '' ? 'na busca' : 'pela data de fechamento da O.S' ?></small></div>
    <div class="kpi"><span>Feitas em dupla</span><b><?= $duplaI ?></b><small>2 ou mais técnicos</small></div>
    <div class="kpi"><span>Equipamentos recolhidos</span><b><?= $eqI ?></b><small>nas instalações</small></div>
  </div>
  <?php if ($abrirForm): ?>
  <form method="post" action="<?= $linkMes ?>" class="card" id="form-inst"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <button name="acao" value="inst_salvar" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px">Salvar</button><?php /* Enter salva (não busca) */ ?>
    <input type="hidden" name="r[assunto]" value="<?= $vI('assunto') ?>">
    <h2><?= $edit['id'] ? 'Corrigir O.S de instalação' : 'Lançar O.S de instalação' ?></h2>
    <?php if (!$tecsI): ?><div class="msg">Ainda não tem técnico na lista. Clique em Buscar O.S que o técnico do IXC entra sozinho<?= $admin ? ', ou cadastre em Configurações → Permissões, no fim da página' : ', ou peça ao admin para cadastrar em Configurações' ?>.</div><?php endif ?>
    <div class="rec-form">
      <label>Nº da O.S no IXC<span class="comb"><input name="r[os]" value="<?= $vI('os') ?>" inputmode="numeric" placeholder="ex.: 104532">
        <?php if (destino() === 'ixc'): ?><button name="acao" value="inst_buscar" formnovalidate title="Só lê a O.S no IXC: cliente, contrato, abertura, fechamento e técnico">Buscar O.S</button><?php endif ?></span></label>
      <label>ID do contrato<input name="r[contrato]" value="<?= $vI('contrato') ?>" inputmode="numeric" placeholder="vem da O.S" required></label>
      <label>Nome do cliente<input name="r[cliente]" value="<?= $vI('cliente') ?>" placeholder="vem da O.S" required></label>
      <label>Abertura da O.S<input type="date" name="r[abertura]" value="<?= $vI('abertura') ?>" max="<?= date('Y-m-d') ?>"></label>
      <label>Fechamento da O.S<input type="date" name="r[fechamento]" value="<?= $vI('fechamento') ?>" max="<?= date('Y-m-d') ?>" required></label>
      <label>Tipo de serviço<select name="r[servico_id]" required><option value="">escolha</option><?php foreach ($servI as $sv): if (!$sv['ativo'] && (int)$sv['id'] !== (int)($edit['servico_id'] ?? 0)) continue; ?><option value="<?= (int)$sv['id'] ?>" <?= (int)$sv['id'] === (int)($edit['servico_id'] ?? 0) ? 'selected' : '' ?>><?= h($sv['nome']) ?></option><?php endforeach ?></select></label>
      <label>Quem agendou<select name="r[agendador_id]"><option value="">ninguém / não sei</option><?php foreach ($agsI as $ag): if (!$ag['ativo'] && (int)$ag['id'] !== (int)($edit['agendador_id'] ?? 0)) continue; ?><option value="<?= (int)$ag['id'] ?>" <?= (int)$ag['id'] === (int)($edit['agendador_id'] ?? 0) ? 'selected' : '' ?>><?= h($ag['nome']) ?></option><?php endforeach ?></select></label>
      <div class="larga"><b class="small" style="font-weight:700">Técnicos que fizeram a instalação<?= ($edit['assunto'] ?? '') !== '' ? ' · ' . $vI('assunto') : '' ?></b>
        <div class="rec-eqs"><?php foreach ($tecForm as $n => $tsel): ?>
          <label>Técnico <?= $n + 1 ?><select name="r[tec][<?= $n ?>]" <?= $n === 0 ? 'required' : '' ?>><option value=""><?= $n === 0 ? 'escolha' : 'nenhum' ?></option><?php foreach ($tecsI as $tc): if (!$tc['ativo'] && (int)$tc['id'] !== $tsel) continue; ?><option value="<?= (int)$tc['id'] ?>" <?= (int)$tc['id'] === $tsel ? 'selected' : '' ?>><?= h($tc['nome']) ?></option><?php endforeach ?></select></label>
        <?php endforeach ?></div>
        <?php if (count($tecForm) < INST_MAX_TEC): ?><button id="tec-fim" name="acao" value="inst_mais_tec" formnovalidate formaction="<?= $linkMes ?>#tec-fim">+ Adicionar técnico</button><?php endif ?></div>
      <div class="larga"><b class="small" style="font-weight:700">Equipamentos recolhidos na instalação (se não teve, deixe em branco)</b>
        <div class="rec-eqs"><?php foreach ($eqForm as $n => $e): ?>
          <label>MAC <?= $n + 1 ?><input name="r[eq][<?= $n ?>][mac]" value="<?= h((string)($e['mac'] ?? '')) ?>" placeholder="CCB1822D48C7" autocapitalize="characters"></label>
          <label>Modelo <?= $n + 1 ?><input name="r[eq][<?= $n ?>][modelo]" value="<?= h((string)($e['modelo'] ?? '')) ?>" list="rec-modelos" placeholder="ex.: ONU ZTE F670L"></label>
        <?php endforeach ?></div>
        <datalist id="rec-modelos"><?php foreach ($modelosUsados as $mu): ?><option value="<?= h($mu) ?>"><?php endforeach ?></datalist>
        <button id="eq-fim" name="acao" value="inst_mais" formnovalidate formaction="<?= $linkMes ?>#eq-fim">+ Adicionar equipamento</button></div>
      <label class="larga">Observação<input name="r[obs]" value="<?= $vI('obs') ?>" maxlength="300"></label>
    </div>
    <div class="row" style="margin-top:10px"><button class="pri" name="acao" value="inst_salvar"><?= $edit['id'] ? 'Salvar correção' : 'Salvar O.S' ?></button><a href="<?= $linkMes ?>">Cancelar</a>
      <span class="small">Quem lançou fica gravado. Depois que a comissão é paga, a O.S não muda mais.</span></div>
  </form>
  <?php endif ?>
  <div class="card tw"><table class="rec">
    <thead><tr><th>Cliente</th><th>Contrato</th><th>Serviço</th><th>Abertura O.S</th><th>Fechamento O.S</th><th>Técnicos</th><th>Agendou</th><th>Equipamentos recolhidos</th><th>Lançado por</th><th></th></tr></thead>
    <?php foreach ($linI as $r): ?>
    <tr><td><b><?= h($r['cliente']) ?></b><?php if ($r['os'] !== ''): ?><br><span class="small">O.S <?= h($r['os']) ?><?= $r['assunto'] !== '' ? ' · ' . h($r['assunto']) : '' ?></span><?php endif ?><?php if ($r['obs'] !== ''): ?><br><span class="small"><?= h($r['obs']) ?></span><?php endif ?></td>
      <td class="n"><?= h($r['contrato']) ?></td><td class="small"><?= h($r['servico'] ?: '—') ?></td>
      <td class="n"><?= h(rec_dt($r['abertura']) ?: '—') ?></td><td class="n"><?= h(rec_dt($r['fechamento'])) ?></td>
      <td><?= implode('<br>', array_map(fn($t) => h($t['nome']), $r['tecs'])) ?></td><td class="small"><?= h($r['agendador'] ?: '—') ?></td>
      <td><?php foreach (rec_equipamentos($r) as $e): ?><span class="mac"><?= h($e['mac']) ?></span> <span class="small"><?= h($e['modelo'] ?: 'sem modelo') ?></span><br><?php endforeach ?><?= $r['qtd'] ? '' : '<span class="small">—</span>' ?></td>
      <td class="small"><?= h($r['lancado_nome']) ?></td>
      <td><div class="acoes"><?php if ($r['pago_em'] !== ''): ?><span class="tp pago">pago</span>
        <?php else: ?><a class="bt" href="<?= $linkMes ?>&amp;editar=<?= (int)$r['id'] ?>#form-inst">Corrigir</a>
        <form method="post" action="<?= $linkMes ?>" class="in"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="del" name="acao" value="inst_excluir" title="Excluir esta O.S">Excluir</button></form><?php endif ?></div></td></tr>
    <?php endforeach ?>
    <?php if (!$linI): ?><tr><td colspan="10" class="small">Nenhuma O.S de instalação <?= $buscaI !== '' ? 'nessa busca' : 'fechada em ' . h(mes_nome($mesI)) ?>.</td></tr><?php endif ?>
    <tfoot><tr><td colspan="5">TOTAL</td><td class="n"><?= count($linI) ?> O.S</td><td></td><td class="n"><?= $eqI ?> equip.</td><td colspan="2"></td></tr></tfoot>
  </table></div>

<?php elseif ($p === 'inst_comissao'):
    $mesesI = inst_meses();
    $mesI = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? (string)$_GET['mes'] : date('Y-m');
    if (!in_array($mesI, $mesesI, true)) { $mesesI[] = $mesI; rsort($mesesI); }
    $linI = inst_do_mes($mesI);
    $servI = inst_servicos_por_id();
    $porPes = inst_por_pessoa($linI);
    $somaV = fn(array $r) => array_sum(array_map(fn($t) => (float)($t['valor'] ?: 0), $r['tecs'])) + ($r['agendador_id'] ? (float)($r['valor_agenda'] ?: 0) : 0);
    $totI = array_sum(array_map($somaV, $linI));
    $faltaI = array_sum(array_map(fn($r) => $r['pago_em'] === '' ? $somaV($r) : 0, $linI));
    $confI = count(array_filter($linI, fn($r) => $r['conferido']));
    $semValorI = count(array_filter($linI, fn($r) => $r['pago_em'] === '' && (in_array('', array_column($r['tecs'], 'valor'), true) || ($r['agendador_id'] && $r['valor_agenda'] === ''))));
    $Rr = fn(float $v) => 'R$ ' . brl(number_format($v, 2, '.', ''));
    $linkMes = '?p=inst_comissao&amp;mes=' . h($mesI);
?>
  <div class="topo"><div><h1>Comissão da instalação</h1><p class="small">Só o admin e quem tem a permissão "Comissões da instalação". Coloque o valor de cada técnico em cada O.S (feita em dupla: um valor para cada um) e o de quem agendou, marque ✔ e registre o mês em que pagou. No fim, o total de cada pessoa. Paga uma vez só.</p></div>
    <form method="get" class="in"><input type="hidden" name="p" value="inst_comissao"><select name="mes" aria-label="Mês do fechamento" data-auto><?php foreach ($mesesI as $om): ?><option value="<?= h($om) ?>" <?= $om === $mesI ? 'selected' : '' ?>><?= h(mes_nome($om)) ?></option><?php endforeach ?></select><noscript><button>Ver</button></noscript></form></div>
  <form method="post" action="<?= $linkMes ?>" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="inst_servicos_salvar"><input type="hidden" name="mes" value="<?= h($mesI) ?>">
    <h2>Valor padrão por tipo de serviço</h2>
    <p class="small" style="margin:0 0 8px">Sugestão que já vem preenchida nas O.S sem valor, conforme o serviço. Você muda o que precisar O.S por O.S. Em branco: sem sugestão.</p>
    <div class="tw"><table class="rec">
      <thead><tr><th>Serviço</th><th>Técnico sozinho R$</th><th>Cada técnico em dupla R$</th><th>Quem agendou R$</th><th></th></tr></thead>
      <?php foreach ($servI as $sv): $si = (int)$sv['id']; ?>
      <tr><td><b><?= h($sv['nome']) ?></b><?= $sv['ativo'] ? '' : ' <span class="pill er">escondido</span>' ?></td>
        <td><input class="val" name="sv[<?= $si ?>][um]" value="<?= h(brl($sv['valor_um'])) ?>" inputmode="decimal" aria-label="Técnico sozinho em <?= h($sv['nome']) ?>"></td>
        <td><input class="val" name="sv[<?= $si ?>][dupla]" value="<?= h(brl($sv['valor_dupla'])) ?>" placeholder="igual" inputmode="decimal" aria-label="Cada técnico em dupla em <?= h($sv['nome']) ?>"></td>
        <td><input class="val" name="sv[<?= $si ?>][agenda]" value="<?= h(brl($sv['valor_agenda'])) ?>" inputmode="decimal" aria-label="Quem agendou em <?= h($sv['nome']) ?>"></td>
        <td><button form="sv-at-<?= $si ?>" title="<?= $sv['ativo'] ? 'Tirar da lista de serviços' : 'Mostrar de novo' ?>"><?= $sv['ativo'] ? 'Esconder' : 'Mostrar' ?></button></td></tr>
      <?php endforeach ?>
    </table></div>
    <div class="row" style="margin-top:10px">
      <label><input type="checkbox" name="sobrescrever" value="1"> trocar também os valores já digitados (não conferidos)</label>
      <button class="pri">Salvar e preencher <?= h(mes_nome($mesI)) ?></button></div>
  </form>
  <?php foreach ($servI as $sv): ?><form method="post" action="<?= $linkMes ?>" id="sv-at-<?= (int)$sv['id'] ?>" class="in" hidden><?= csrf_campo() ?><input type="hidden" name="acao" value="inst_lista_ativo"><input type="hidden" name="tipo" value="servico"><input type="hidden" name="id" value="<?= (int)$sv['id'] ?>"></form><?php endforeach ?>
  <form method="post" action="<?= $linkMes ?>" class="row" style="margin:-4px 0 14px"><?= csrf_campo() ?><input type="hidden" name="acao" value="inst_lista_novo"><input type="hidden" name="tipo" value="servico"><input name="nome" placeholder="NOVO TIPO DE SERVIÇO" required aria-label="Novo tipo de serviço"><button>Adicionar serviço</button></form>
  <div class="kpis">
    <div class="kpi forte"><span>Total de <?= h(mes_nome($mesI)) ?></span><b><?= $Rr($totI) ?></b><small><?= count($linI) ?> instalações</small></div>
    <div class="kpi"><span>Conferidas</span><b class="ok"><?= $confI ?> de <?= count($linI) ?></b><small>marcadas com ✔</small></div>
    <div class="kpi"><span>Falta pagar</span><b class="q"><?= $Rr($faltaI) ?></b><small><?= $semValorI ? "$semValorI O.S com alguém sem valor" : 'sem mês de pagamento' ?></small></div>
  </div>
  <form method="post" action="<?= $linkMes ?>" class="card"><?= csrf_campo() ?><input type="hidden" name="mes" value="<?= h($mesI) ?>">
    <h2>Valor por O.S e por pessoa</h2>
    <div class="tw"><table class="rec">
      <thead><tr><th>✔</th><th>Cliente</th><th>Serviço</th><th>Fechou</th><th>Técnicos e valor de cada um</th><th>Quem agendou</th><th>Total da O.S</th><th>Pago em</th></tr></thead>
      <?php foreach ($linI as $r): $i = (int)$r['id']; $dis = $r['pago_em'] !== '' ? 'disabled' : ''; [$sugT, $sugA] = inst_sugestao($r, $servI); ?>
      <tr><td class="ck"><?php if (!$dis): ?><input type="hidden" name="c[<?= $i ?>][ok]" value="0"><?php endif ?><input type="checkbox" name="c[<?= $i ?>][ok]" value="1" <?= $r['conferido'] ? 'checked' : '' ?> <?= $dis ?> aria-label="Conferido <?= h($r['cliente']) ?>"></td>
        <td><b><?= h($r['cliente']) ?></b><br><span class="small">contrato <?= h($r['contrato']) ?><?= $r['os'] !== '' ? ' · O.S ' . h($r['os']) : '' ?><?= $r['qtd'] ? ' · ' . (int)$r['qtd'] . ' equip. recolhido' . ($r['qtd'] == 1 ? '' : 's') : '' ?></span></td>
        <td class="small"><?= h($r['servico'] ?: '—') ?></td>
        <td class="n"><?= h(rec_dt($r['fechamento'])) ?></td>
        <td><div class="inst-tecs"><?php foreach ($r['tecs'] as $t): ?><label><span><?= h($t['nome']) ?></span><input class="val" name="c[<?= $i ?>][v][<?= (int)$t['id'] ?>]" value="<?= h(brl($t['valor'])) ?>" placeholder="<?= h(brl($sugT)) ?>" inputmode="decimal" <?= $dis ?> aria-label="Valor de <?= h($t['nome']) ?>"></label><?php endforeach ?></div></td>
        <td><?php if ($r['agendador_id']): ?><div class="inst-tecs"><label><span><?= h($r['agendador']) ?></span><input class="val" name="c[<?= $i ?>][va]" value="<?= h(brl($r['valor_agenda'])) ?>" placeholder="<?= h(brl($sugA)) ?>" inputmode="decimal" <?= $dis ?> aria-label="Valor de quem agendou"></label></div><?php else: ?><span class="small">—</span><?php endif ?></td>
        <td class="n"><b><?= $Rr($somaV($r)) ?></b></td>
        <td><?= $r['pago_em'] !== '' ? '<span class="tp pago">' . h(mes_nome($r['pago_em'])) . '</span>' : '<span class="small">—</span>' ?></td></tr>
      <?php endforeach ?>
      <?php if (!$linI): ?><tr><td colspan="8" class="small">Nenhuma instalação fechada em <?= h(mes_nome($mesI)) ?>.</td></tr><?php endif ?>
    </table></div>
    <div class="row" style="margin-top:10px;justify-content:space-between">
      <button name="acao" value="inst_com_salvar">Salvar valores e conferência</button>
      <span class="row"><button class="pri" name="acao" value="inst_pagar">Salvar e marcar conferidas como pagas em</button>
        <select name="pago_em" aria-label="Mês do pagamento"><?php for ($k = -1; $k <= 2; $k++): $om = mes_soma(date('Y-m'), $k); ?><option value="<?= $om ?>" <?= $k === 0 ? 'selected' : '' ?>><?= h(mes_nome($om)) ?></option><?php endfor ?></select></span>
    </div>
    <p class="small">Valor em cinza é a sugestão do serviço: só vale depois de salvo (use "Salvar e preencher" acima). Se a O.S for corrigida e mudar técnicos, quem agendou ou o serviço, ela volta sem ✔.</p>
  </form>
  <div class="card"><h2>Total de cada pessoa em <?= h(mes_nome($mesI)) ?></h2>
    <div class="tw"><table class="rec">
      <thead><tr><th>Pessoa</th><th>Como</th><th>Instalações</th><th>Sozinho</th><th>Em dupla</th><th>Total</th><th>Falta pagar</th></tr></thead>
      <?php foreach ($porPes as $pt): $tec = $pt['papel'] === 'Técnico'; ?>
      <tr><td><b><?= h($pt['nome']) ?></b><?= $pt['sem_valor'] ? '<br><span class="small er">' . $pt['sem_valor'] . ' O.S sem valor</span>' : '' ?></td><td class="small"><?= $tec ? 'Técnico' : 'Agendou' ?></td><td class="n"><?= $pt['os'] ?></td>
        <td class="n"><?= $tec ? $pt['sozinho'] : '—' ?></td><td class="n"><?= $tec ? $pt['os'] - $pt['sozinho'] : '—' ?></td>
        <td class="n"><b><?= $Rr($pt['valor']) ?></b></td><td class="n"><?= $pt['falta'] > 0 ? $Rr($pt['falta']) : ($pt['valor'] > 0 ? '<span class="tp pago">pago</span>' : '—') ?></td></tr>
      <?php endforeach ?>
      <?php if (!$porPes): ?><tr><td colspan="7" class="small">Nenhuma instalação fechada em <?= h(mes_nome($mesI)) ?>.</td></tr><?php endif ?>
      <tfoot><tr><td>TOTAL</td><td></td><td class="n"><?= count($linI) ?> O.S</td><td colspan="2"></td><td class="n"><?= $Rr($totI) ?></td><td class="n"><?= $Rr($faltaI) ?></td></tr></tfoot>
    </table></div>
  </div>

<?php elseif ($p === 'rh_lancar'):
    $mesesH = rh_meses();
    $mesH = rh_mes_ok((string)($_GET['mes'] ?? '')) ?: date('Y-m');
    if (!in_array($mesH, $mesesH, true)) { $mesesH[] = $mesH; rsort($mesesH); }
    $mesesForm = array_values(array_unique(array_merge([mes_soma(date('Y-m'), 1)], $mesesH))); rsort($mesesForm);
    $fH = (int)($_GET['f'] ?? 0);
    $tiposV = array_intersect_key(RH_TIPOS, array_flip($rhTipos)); // só os tipos que este gestor pode ver
    $tH = isset($tiposV[$_GET['t'] ?? '']) ? (string)$_GET['t'] : '';
    $tipoF = isset($tiposV[$rhForm['tipo'] ?? '']) ? $rhForm['tipo'] : (isset($tiposV[$_GET['tipo'] ?? '']) ? (string)$_GET['tipo'] : (string)array_key_first($tiposV));
    $vF = fn(string $k, string $pad = '') => h((string)($rhForm[$k] ?? $pad));
    $funcsH = rh_funcionarios(true);
    $linH = rh_do_mes($mesH, $fH, $tH, $rhTipos);
    $doMes = $fH || $tH ? rh_do_mes($mesH, 0, '', $rhTipos) : $linH;
    $somaT = fn(string $t) => array_sum(array_map(fn($l) => (float)$l['valor'], array_filter($doMes, fn($l) => $l['tipo'] === $t)));
    $qtdT = fn(string $t) => array_sum(array_map(fn($l) => (float)$l['qtd'], array_filter($doMes, fn($l) => $l['tipo'] === $t)));
    $linkH = '?p=rh_lancar&amp;mes=' . h($mesH);
?>
  <div class="topo"><div><h1>RH · Lançamentos</h1><p class="small">Lance aqui as horas extras informais, o sobreaviso, o complemento de salário e os pagamentos extras de cada funcionário. Cada lançamento entra no mês de referência escolhido; no Fechamento sai o total e o recibo.</p></div>
    <form method="get" class="in"><input type="hidden" name="p" value="rh_lancar"><select name="mes" aria-label="Mês de referência" data-auto><?php foreach ($mesesH as $om): ?><option value="<?= h($om) ?>" <?= $om === $mesH ? 'selected' : '' ?>><?= h(mes_nome($om)) ?></option><?php endforeach ?></select><noscript><button>Ver</button></noscript></form></div>
  <div class="kpis">
    <div class="kpi forte"><span>Total de <?= h(mes_nome($mesH)) ?></span><b><?= rh_rs(array_sum(array_map(fn($l) => (float)$l['valor'], $doMes))) ?></b><small><?= count(array_unique(array_column($doMes, 'funcionario_id'))) ?> funcionário(s)</small></div>
    <?php if (isset($tiposV['horas'])): ?><div class="kpi"><span>Horas informais</span><b><?= rh_rs($somaT('horas')) ?></b><small><?= rh_hm($qtdT('horas')) ?> h lançadas</small></div><?php endif ?>
    <?php if (isset($tiposV['sobre'])): ?><div class="kpi"><span>Sobreaviso</span><b><?= rh_rs($somaT('sobre')) ?></b><small><?= rtrim(rtrim(number_format($qtdT('sobre'), 1, ',', ''), '0'), ',') ?> dia(s)</small></div><?php endif ?>
    <?php if (isset($tiposV['compl'])): ?><div class="kpi"><span>Complemento</span><b><?= rh_rs($somaT('compl')) ?></b><small>&nbsp;</small></div><?php endif ?>
    <?php if (isset($tiposV['pag'])): ?><div class="kpi"><span>Pagamentos extras</span><b><?= rh_rs($somaT('pag')) ?></b><small>&nbsp;</small></div><?php endif ?>
  </div>
  <?php if (!$funcsH): ?><div class="msg">Ainda não tem funcionário cadastrado. Cadastre primeiro em <a href="?p=rh_func">RH → Funcionários</a>.</div>
  <?php else: ?>
  <form method="post" action="<?= $linkH ?>&amp;tipo=<?= h($tipoF) ?>" class="card" id="form-rh"><?= csrf_campo() ?><input type="hidden" name="l[tipo]" value="<?= h($tipoF) ?>">
    <h2>Novo lançamento</h2>
    <nav class="rh-tipos" aria-label="Tipo do lançamento"><?php foreach ($tiposV as $tk => $tt): ?><a href="<?= $linkH ?>&amp;tipo=<?= $tk ?>#form-rh"<?= $tk === $tipoF ? ' aria-current="page"' : '' ?>><?= h($tt) ?></a><?php endforeach ?></nav>
    <div class="rec-form" style="margin-top:12px">
      <label>Funcionário<select name="l[funcionario_id]" required><option value="">escolha</option><?php foreach ($funcsH as $fu):
        $ref = $tipoF === 'horas' ? $fu['valor_hora'] : ($tipoF === 'sobre' ? $fu['valor_sobreaviso'] : ''); ?><option value="<?= (int)$fu['id'] ?>" <?= (int)$fu['id'] === (int)($rhForm['funcionario_id'] ?? 0) ? 'selected' : '' ?>><?= h($fu['nome']) ?><?= $ref !== '' ? ' · R$ ' . h(brl($ref)) . ($tipoF === 'horas' ? '/h' : '/dia') : '' ?></option><?php endforeach ?></select></label>
      <label>Mês de referência<select name="l[mes]"><?php foreach ($mesesForm as $om): ?><option value="<?= h($om) ?>" <?= $om === ($rhForm['mes'] ?? $mesH) ? 'selected' : '' ?>><?= h(mes_nome($om)) ?></option><?php endforeach ?></select></label>
      <label>Data<input type="date" name="l[data]" value="<?= $vF('data', date('Y-m-d')) ?>" required></label>
      <?php if ($tipoF === 'horas'): ?>
      <label>Horas<input name="l[qtd]" value="<?= $vF('qtd') ?>" placeholder="ex.: 2:30" inputmode="decimal" required></label>
      <label>Valor da hora R$<input name="l[unitario]" value="<?= $vF('unitario') ?>" placeholder="do cadastro" inputmode="decimal"></label>
      <?php elseif ($tipoF === 'sobre'): ?>
      <label>Dias de sobreaviso<input name="l[qtd]" value="<?= $vF('qtd', '1') ?>" placeholder="ex.: 2" inputmode="decimal" required></label>
      <label>Valor por dia R$<input name="l[unitario]" value="<?= $vF('unitario') ?>" placeholder="do cadastro" inputmode="decimal"></label>
      <?php else: ?>
      <label>Valor R$<input name="l[valor]" value="<?= $vF('valor') ?>" placeholder="0,00" inputmode="decimal" required></label>
      <?php endif ?>
      <label class="larga">Descrição / motivo (vai no recibo)<input name="l[descricao]" value="<?= $vF('descricao') ?>" maxlength="200" placeholder="<?= h(['horas' => 'ex.: rompimento de fibra na rua 7, após o expediente', 'sobre' => 'ex.: escala do fim de semana', 'compl' => 'ex.: complemento de salário de ' . mb_strtolower(mes_nome($mesH)), 'pag' => 'ex.: ajuda de combustível'][$tipoF]) ?>" <?= in_array($tipoF, ['compl', 'pag'], true) ? 'required' : '' ?>></label>
    </div>
    <div class="row" style="margin-top:10px"><button class="pri" name="acao" value="rh_lanc_salvar">Lançar <?= h(mb_strtolower(RH_TIPOS[$tipoF])) ?></button>
      <span class="small"><?= $tipoF === 'horas' ? 'Valor = horas × valor da hora. Em branco, usa o valor da hora do cadastro do funcionário.' : ($tipoF === 'sobre' ? 'Valor = dias × valor por dia. Em branco, usa o valor do sobreaviso do cadastro do funcionário.' : 'Valor livre, digitado pelo RH.') ?></span></div>
  </form>
  <?php endif ?>
  <div class="card">
    <form method="get" class="row" style="justify-content:space-between"><input type="hidden" name="p" value="rh_lancar"><input type="hidden" name="mes" value="<?= h($mesH) ?>">
      <h2 style="margin:0">Lançamentos de <?= h(mes_nome($mesH)) ?></h2>
      <span class="row"><select name="f" aria-label="Funcionário" data-auto><option value="0">Todos os funcionários</option><?php foreach (rh_funcionarios() as $fu): ?><option value="<?= (int)$fu['id'] ?>" <?= (int)$fu['id'] === $fH ? 'selected' : '' ?>><?= h($fu['nome']) ?></option><?php endforeach ?></select>
        <select name="t" aria-label="Tipo" data-auto><option value="">Todos os tipos</option><?php foreach ($tiposV as $tk => $tt): ?><option value="<?= $tk ?>" <?= $tk === $tH ? 'selected' : '' ?>><?= h($tt) ?></option><?php endforeach ?></select><noscript><button>Filtrar</button></noscript></span></form>
    <div class="tw" style="margin-top:10px"><table class="rec">
      <thead><tr><th>Data</th><th>Funcionário</th><th>Tipo</th><th>Detalhe</th><th>Descrição</th><th class="r">Valor</th><th>Lançado por</th><th></th></tr></thead>
      <?php foreach ($linH as $l): ?>
      <tr><td class="n"><?= h(rh_dt($l['data'])) ?></td><td><b><?= h($l['nome']) ?></b></td><td><span class="tp tp-<?= h($l['tipo']) ?>"><?= h(RH_TIPOS[$l['tipo']]) ?></span></td>
        <td class="n small"><?= h(rh_detalhe($l)) ?></td><td><?= h($l['descricao']) ?></td><td class="n r"><b><?= rh_rs((float)$l['valor']) ?></b></td><td class="small"><?= h($l['lancado_nome']) ?></td>
        <td><?php if ($l['pago_em'] !== ''): ?><span class="tp pago">pago <?= h(rh_dt($l['pago_em'])) ?></span><?php else: ?><form method="post" action="<?= $linkH ?>" class="in"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="del" name="acao" value="rh_lanc_excluir" title="Excluir este lançamento">Excluir</button></form><?php endif ?></td></tr>
      <?php endforeach ?>
      <?php if (!$linH): ?><tr><td colspan="8" class="small">Nenhum lançamento<?= $fH || $tH ? ' com esse filtro' : '' ?> em <?= h(mes_nome($mesH)) ?>.</td></tr><?php else: ?>
      <tfoot><tr><td colspan="5">Total (<?= count($linH) ?> lançamento<?= count($linH) === 1 ? '' : 's' ?>)</td><td class="n r"><?= rh_rs(array_sum(array_map(fn($l) => (float)$l['valor'], $linH))) ?></td><td colspan="2"></td></tr></tfoot><?php endif ?>
    </table></div>
    <p class="small">Lançamento errado: exclua e lance de novo. Depois que o mês do funcionário é marcado como pago, os lançamentos dele ficam travados.</p>
  </div>

<?php elseif ($p === 'rh_fecha'):
    $mesesH = rh_meses();
    $mesH = rh_mes_ok((string)($_GET['mes'] ?? '')) ?: date('Y-m');
    if (!in_array($mesH, $mesesH, true)) { $mesesH[] = $mesH; rsort($mesesH); }
    $totH = rh_totais($mesH, [], $rhTipos);
    $colsH = array_intersect_key(['horas' => 'Horas informais', 'sobre' => 'Sobreaviso', 'compl' => 'Complemento', 'pag' => 'Pagamentos extras'], array_flip($rhTipos));
    $T = fn(string $k) => array_sum(array_column($totH, $k));
    $faltaH = array_sum(array_map(fn($r) => $r['pago_em'] === '' ? $r['tot'] : 0, $totH));
    $linkH = '?p=rh_fecha&amp;mes=' . h($mesH);
?>
  <div class="topo"><div><h1>RH · Fechamento do mês</h1><p class="small">Soma tudo de cada funcionário no mês. O recibo para assinatura sai na tela Recibos (um por pessoa, com tudo somado). Depois de "Marcar pago", o mês daquele funcionário fica travado (paga uma vez só); só o admin desfaz.</p></div>
    <div class="row"><form method="get" class="in"><input type="hidden" name="p" value="rh_fecha"><select name="mes" aria-label="Mês de referência" data-auto><?php foreach ($mesesH as $om): ?><option value="<?= h($om) ?>" <?= $om === $mesH ? 'selected' : '' ?>><?= h(mes_nome($om)) ?></option><?php endforeach ?></select><noscript><button>Ver</button></noscript></form>
      <?php if ($totH && $pode('recibos.rh')): ?><a class="bt" href="?p=recibos&amp;mes=<?= h($mesH) ?>">Ir para Recibos</a><?php endif ?></div></div>
  <?php if (!$rhTudo): ?><div class="msg">Você vê só parte dos tipos de lançamento (<?= h(implode(', ', array_intersect_key(RH_TIPOS, array_flip($rhTipos)))) ?>), por isso os totais abaixo não são o total a pagar. "Marcar pago" fica com quem vê todos os tipos.</div><?php endif ?>
  <div class="kpis">
    <div class="kpi forte"><span>Total de <?= h(mes_nome($mesH)) ?></span><b><?= rh_rs($T('tot')) ?></b><small><?= count($totH) ?> funcionário(s)</small></div>
    <div class="kpi"><span>Falta pagar</span><b class="q"><?= rh_rs($faltaH) ?></b><small><?= count(array_filter($totH, fn($r) => $r['pago_em'] === '')) ?> a pagar</small></div>
    <div class="kpi"><span>Já pago</span><b class="ok"><?= rh_rs($T('tot') - $faltaH) ?></b><small><?= count(array_filter($totH, fn($r) => $r['pago_em'] !== '')) ?> pago(s)</small></div>
  </div>
  <div class="card"><div class="tw"><table class="rec">
    <thead><tr><th>Funcionário</th><?php foreach ($colsH as $ck => $ct): ?><th class="r"><?= h($ct) ?></th><?php endforeach ?><th class="r">Total</th><th>Situação</th><th></th></tr></thead>
    <?php foreach ($totH as $fid => $r): ?>
    <tr><td><b><?= h($r['f']['nome']) ?></b><br><span class="small"><?= h($r['f']['funcao'] ?: '—') ?></span></td>
      <?php foreach ($colsH as $ck => $ct): ?><td class="n r"><?= rh_rs($r[$ck]) ?><?= $ck === 'horas' && $r['h'] ? '<br><span class="small">' . rh_hm($r['h']) . ' h</span>' : '' ?><?= $ck === 'sobre' && $r['dias'] ? '<br><span class="small">' . rtrim(rtrim(number_format($r['dias'], 1, ',', ''), '0'), ',') . ' dia(s)</span>' : '' ?></td><?php endforeach ?>
      <td class="n r"><b><?= rh_rs($r['tot']) ?></b></td>
      <td><?= $r['pago_em'] !== '' ? '<span class="tp pago">pago ' . h(rh_dt($r['pago_em'])) . '</span>' : '<span class="pill q">a pagar</span>' ?></td>
      <td><div class="acoes"><?php if ($pode('recibos.rh')): ?><a class="bt" href="?p=recibos&amp;mes=<?= h($mesH) ?>&amp;g=1&amp;s[]=<?= h(urlencode(recibo_chave((string)$r['f']['nome']))) ?>">Recibo</a><?php endif ?>
        <?php if ($r['pago_em'] === '' && $rhTudo): ?><form method="post" action="<?= $linkH ?>" class="in row"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$fid ?>"><input type="hidden" name="mes" value="<?= h($mesH) ?>"><input type="date" name="pago_em" value="<?= date('Y-m-d') ?>" aria-label="Data do pagamento" required><button class="pri" name="acao" value="rh_pagar">Marcar pago</button></form>
        <?php elseif ($admin): ?><form method="post" action="<?= $linkH ?>" class="in"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$fid ?>"><input type="hidden" name="mes" value="<?= h($mesH) ?>"><button class="del" name="acao" value="rh_desfazer" title="Volta a deixar mudar os lançamentos deste mês">Desfazer pagamento</button></form><?php endif ?></div></td></tr>
    <?php endforeach ?>
    <?php if (!$totH): ?><tr><td colspan="<?= count($colsH) + 4 ?>" class="small">Nada lançado em <?= h(mes_nome($mesH)) ?>.</td></tr><?php else: ?>
    <tfoot><tr><td>TOTAL</td><?php foreach ($colsH as $ck => $ct): ?><td class="n r"><?= rh_rs($T($ck)) ?></td><?php endforeach ?><td class="n r"><?= rh_rs($T('tot')) ?></td><td colspan="2"></td></tr></tfoot><?php endif ?>
  </table></div>
  <p class="small">"Marcar pago" grava a data do pagamento. Lançamento novo para esse funcionário nesse mês, depois de pago, vai para o mês seguinte.</p></div>

<?php elseif ($p === 'recibos'):
    $oPode = array_values(array_filter(array_keys(RECIBO_ORIGENS), fn($o) => $pode('recibos.' . $o)));
    $mesR = rh_mes_ok((string)($_GET['mes'] ?? '')) ?: date('Y-m');
    $mesesR = array_map(fn($i) => mes_soma(date('Y-m'), -$i), range(0, 12));
    if (!in_array($mesR, $mesesR, true)) { $mesesR[] = $mesR; rsort($mesesR); }
    $oSel = isset($_GET['f']) ? array_values(array_intersect($oPode, (array)($_GET['o'] ?? []))) : $oPode;
    $sitR = in_array($_GET['sit'] ?? '', ['apagar', 'pagos'], true) ? $_GET['sit'] : 'todos';
    $partesR = array_filter(recibos_partes($mesR, $oSel), fn($x) => $sitR === 'todos' || ($sitR === 'pagos') === $x['pago']);
    $pessoasR = recibos_por_pessoa($partesR, recibos_cpfs());
    $gerarR = isset($_GET['g']);
    $selR = $gerarR ? array_map('strval', (array)($_GET['s'] ?? [])) : array_keys($pessoasR);
    $impR = $gerarR ? array_values(array_intersect_key($pessoasR, array_flip($selR))) : [];
    $emp = rh_empresa();
    $refR = mb_strtolower(mes_nome($mesR));
    $nomesO = fn(array $os) => preg_replace('/, ([^,]+)$/', ' e $1', implode(', ', array_map(fn($o) => RECIBO_REFERENTE[$o], $os)));
    $semCpf = array_filter($pessoasR, fn($r) => $r['cpf'] === '');
    $aquiR = '?' . h(http_build_query(array_diff_key($_GET, ['g' => 1])));
?>
  <div class="topo noprint"><div><h1>Recibos</h1><p class="small">Um recibo por pessoa no mês, com tudo somado: comissão de vendas, recolhimento, instalação, RH e pagamentos de serviços em execução. Uma via só, para a pessoa assinar e ficar com a empresa. Sai 4 por folha A4.</p></div></div>
  <form method="get" class="card noprint rcb-filtro"><input type="hidden" name="p" value="recibos"><input type="hidden" name="f" value="1">
    <div class="row">
      <label class="op">Mês <select name="mes"><?php foreach ($mesesR as $om): ?><option value="<?= h($om) ?>" <?= $om === $mesR ? 'selected' : '' ?>><?= h(mes_nome($om)) ?></option><?php endforeach ?></select></label>
      <label class="op">Situação <select name="sit"><option value="todos">A pagar e pagos</option><option value="apagar" <?= $sitR === 'apagar' ? 'selected' : '' ?>>Só a pagar</option><option value="pagos" <?= $sitR === 'pagos' ? 'selected' : '' ?>>Só pagos</option></select></label>
    </div>
    <div class="row"><?php foreach ($oPode as $o): ?><label class="og"><input type="checkbox" name="o[]" value="<?= h($o) ?>" <?= in_array($o, $oSel, true) ? 'checked' : '' ?>><?= h(RECIBO_ORIGENS[$o]) ?></label><?php endforeach ?>
      <button>Filtrar</button></div>
    <div class="tw"><table class="rec">
      <thead><tr><th class="ck"></th><th>Pessoa</th><th>De onde vem</th><th>Detalhe</th><th class="r">Valor</th><th>Situação</th></tr></thead>
      <?php foreach ($pessoasR as $k => $r): ?>
      <tr><td class="ck"><input type="checkbox" name="s[]" value="<?= h($k) ?>" <?= in_array($k, $selR, true) ? 'checked' : '' ?> aria-label="Imprimir recibo de <?= h($r['nome']) ?>"></td>
        <td><b><?= h($r['nome']) ?></b><?= $r['cpf'] !== '' ? '<br><span class="small">CPF ' . h(rh_cpf($r['cpf'])) . '</span>' : '<br><span class="small q">sem CPF</span>' ?></td>
        <td><?php foreach ($r['origens'] as $o): ?><span class="tp o-<?= h($o) ?>"><?= h(RECIBO_ORIGENS[$o]) ?></span> <?php endforeach ?></td>
        <td class="small"><?= h(implode(' · ', array_map(fn($l) => $l[0], $r['ls']))) ?></td>
        <td class="n r"><b><?= rh_rs($r['tot']) ?></b></td>
        <td><?= $r['pago'] === 'sim' ? '<span class="tp pago">pago</span>' : ($r['pago'] === 'parte' ? '<span class="pill q">parte paga</span>' : '<span class="pill q">a pagar</span>') ?></td></tr>
      <?php endforeach ?>
      <?php if (!$pessoasR): ?><tr><td colspan="6" class="small">Nada para <?= h($refR) ?> com esse filtro.</td></tr><?php else: ?>
      <tfoot><tr><td></td><td colspan="3">Total da lista (<?= count($pessoasR) ?> pessoa(s))</td><td class="n r"><?= rh_rs(array_sum(array_column($pessoasR, 'tot'))) ?></td><td></td></tr></tfoot><?php endif ?>
    </table></div>
    <?php if ($pessoasR): ?><div class="row"><button class="pri" name="g" value="1">Gerar recibos marcados</button></div><?php endif ?>
    <p class="small">Entra no recibo: comissão de vendas com valor, já paga ou com o cliente tendo pago o 1º boleto; O.S de recolhimento e de instalação conferidas; todos os lançamentos do RH do mês; pagamentos feitos às equipes de serviços em execução no mês. A mesma pessoa em mais de uma área (mesmo nome) sai num recibo só.</p>
  </form>
  <?php if ($gerarR): ?>
  <div class="topo noprint"><div><h2><?= count($impR) ?> recibo(s) em <?= (int)ceil(count($impR) / 4) ?> folha(s) A4</h2><?= $emp['cnpj'] === '' ? '<p class="small">Falta o CNPJ no cabeçalho: ' . ($admin ? 'preencha em RH → Funcionários, no fim da página.' : 'peça ao admin para preencher.') . '</p>' : '' ?></div>
    <?php if ($impR): ?><button class="pri" type="button" data-imprimir>Imprimir</button><?php endif ?></div>
  <div class="rfolhas"><?php foreach (array_chunk($impR, 4) as $folha): ?><div class="rfolha">
    <?php foreach ($folha as $r): ?><div class="rc">
      <div class="cab"><div><b><?= h($emp['nome']) ?></b><?= $emp['cnpj'] !== '' ? ' · CNPJ ' . h(rh_cnpj($emp['cnpj'])) : '' ?></div><div class="t">RECIBO · <?= h(mb_strtoupper($refR)) ?></div></div>
      <div class="txt">Recebi de <?= h($emp['nome']) ?> a importância de <b><?= rh_rs($r['tot']) ?></b> (<?= h(rh_extenso($r['tot'])) ?>), referente a <?= h($nomesO($r['origens'])) ?> de <?= h($refR) ?>, conforme abaixo, dando plena quitação.</div>
      <table><?php foreach ($r['ls'] as $l): ?><tr><td><?= h($l[0]) ?></td><td class="r"><?= rh_rs($l[1]) ?></td></tr><?php endforeach ?><tr class="tot"><td>Total</td><td class="r"><?= rh_rs($r['tot']) ?></td></tr></table>
      <div class="ass"><div class="dt"><?= $emp['cidade'] !== '' ? h($emp['cidade']) : '______________' ?>, ___/___/<?= h(substr($mesR, 0, 4)) ?></div><div class="lin"><?= h($r['nome']) ?><?= $r['cpf'] !== '' ? ' · CPF ' . h(rh_cpf($r['cpf'])) : '' ?></div></div>
    </div><?php endforeach ?>
    <?= str_repeat('<div class="rc vazio"></div>', 4 - count($folha)) ?>
  </div><?php endforeach ?></div>
  <?php if (!$impR): ?><div class="msg noprint">Marque pelo menos uma pessoa na lista.</div><?php endif ?>
  <?php endif ?>
  <?php if ($pessoasR): ?>
  <form method="post" action="<?= $aquiR ?>" class="card noprint"><?= csrf_campo() ?><input type="hidden" name="acao" value="recibos_cpf">
    <h2>CPF de quem recebe <span class="small"><?= count($semCpf) ? count($semCpf) . ' sem CPF' : 'todos com CPF' ?></span></h2>
    <p class="small">Vale para vendedores, técnicos, recolhedores e quem lançou. Funcionário do RH já vem do cadastro do RH. Em branco, o recibo sai só com o nome.</p>
    <div class="rcb-cpfs"><?php foreach ($pessoasR as $k => $r): ?><label><?= h($r['nome']) ?><input type="hidden" name="cpf[<?= h($k) ?>][nome]" value="<?= h($r['nome']) ?>"><input name="cpf[<?= h($k) ?>][cpf]" value="<?= h(rh_cpf($r['cpf'])) ?>" inputmode="numeric" placeholder="000.000.000-00" maxlength="14"></label><?php endforeach ?></div>
    <div class="row" style="margin-top:10px"><button class="pri">Salvar CPFs</button></div>
  </form>
  <?php endif ?>

<?php elseif ($p === 'rh_func'):
    $funcsH = rh_funcionarios();
    $editF = ($eid = (int)($_GET['editar'] ?? 0)) ? rh_funcionario($eid) : null;
    $vE = fn(string $k) => h((string)($editF[$k] ?? ''));
    $emp = rh_empresa();
?>
  <div class="topo"><div><h1>RH · Funcionários</h1><p class="small">Cadastro de quem recebe horas informais, sobreaviso, complemento e pagamentos extras. É só a ficha do RH: o funcionário não precisa ter usuário no portal. Os valores do cadastro só preenchem o lançamento sozinhos; o RH pode trocar em cada lançamento.</p></div></div>
  <div class="card"><div class="tw"><table class="rec">
    <thead><tr><th>Nome</th><th>CPF</th><th>Função</th><th class="r">Valor da hora informal</th><th class="r">Sobreaviso por dia</th><th>Situação</th><th></th></tr></thead>
    <?php foreach ($funcsH as $fu): ?>
    <tr><td><b><?= h($fu['nome']) ?></b></td><td class="n"><?= h(rh_cpf($fu['cpf']) ?: '—') ?></td><td><?= h($fu['funcao'] ?: '—') ?></td>
      <td class="n r"><?= $fu['valor_hora'] !== '' ? 'R$ ' . h(brl($fu['valor_hora'])) : '—' ?></td><td class="n r"><?= $fu['valor_sobreaviso'] !== '' ? 'R$ ' . h(brl($fu['valor_sobreaviso'])) : '—' ?></td>
      <td><?= $fu['ativo'] ? '<span class="pill ok">ativo</span>' : '<span class="pill er">inativo</span>' ?></td>
      <td><div class="acoes"><a class="bt" href="?p=rh_func&amp;editar=<?= (int)$fu['id'] ?>#form-func">Corrigir</a><form method="post" action="?p=rh_func" class="in"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$fu['id'] ?>"><button name="acao" value="rh_func_ativo" title="<?= $fu['ativo'] ? 'Tirar da lista de lançamentos (o histórico continua)' : 'Voltar para a lista de lançamentos' ?>"><?= $fu['ativo'] ? 'Desativar' : 'Reativar' ?></button></form></div></td></tr>
    <?php endforeach ?>
    <?php if (!$funcsH): ?><tr><td colspan="7" class="small">Nenhum funcionário cadastrado ainda.</td></tr><?php endif ?>
  </table></div></div>
  <form method="post" action="?p=rh_func" class="card" id="form-func"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)($editF['id'] ?? 0) ?>">
    <h2><?= $editF ? 'Corrigir ' . h($editF['nome']) : 'Cadastrar funcionário' ?></h2>
    <div class="rec-form">
      <label>Nome<input name="f[nome]" value="<?= $vE('nome') ?>" required maxlength="80"></label>
      <label>CPF<input name="f[cpf]" value="<?= h(rh_cpf((string)($editF['cpf'] ?? ''))) ?>" inputmode="numeric" placeholder="000.000.000-00"></label>
      <label>Função<input name="f[funcao]" value="<?= $vE('funcao') ?>" maxlength="60" placeholder="ex.: Técnico de campo"></label>
      <label>Valor da hora informal R$<input name="f[valor_hora]" value="<?= h(brl((string)($editF['valor_hora'] ?? ''))) ?>" inputmode="decimal" placeholder="25,00"></label>
      <label>Sobreaviso por dia R$<input name="f[valor_sobreaviso]" value="<?= h(brl((string)($editF['valor_sobreaviso'] ?? ''))) ?>" inputmode="decimal" placeholder="60,00"></label>
    </div>
    <div class="row" style="margin-top:10px"><button class="pri" name="acao" value="rh_func_salvar"><?= $editF ? 'Salvar correção' : 'Cadastrar' ?></button><?php if ($editF): ?><a href="?p=rh_func">Cancelar</a><?php endif ?></div>
  </form>
  <?php if ($admin): ?>
  <form method="post" action="?p=rh_func" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="rh_empresa_salvar">
    <h2>Cabeçalho do recibo <span class="small">só o admin</span></h2>
    <div class="rec-form">
      <label>Nome da empresa<input name="nome" value="<?= h($emp['nome']) ?>" maxlength="80" required></label>
      <label>CNPJ<input name="cnpj" value="<?= h(rh_cnpj($emp['cnpj'])) ?>" inputmode="numeric" placeholder="00.000.000/0000-00"></label>
      <label>Cidade (local da assinatura)<input name="cidade" value="<?= h($emp['cidade']) ?>" maxlength="60" placeholder="em branco: linha para escrever"></label>
    </div>
    <div class="row" style="margin-top:10px"><button class="pri">Salvar cabeçalho</button></div>
  </form>
  <?php endif ?>

<?php elseif ($p === 'colab'):
    $lstC = colab_lista_ixc();
    $cadC = colab_cadastrados();
    [$vendNome, $vendId] = colab_vendedores();
    $ignC = colab_ignorados();
    $cpfC = recibos_cpfs();
    $verC = in_array($_GET['ver'] ?? '', ['todos', 'escondidos'], true) ? $_GET['ver'] : 'ativos';
    $linhasC = array_values(array_filter($lstC['lista'], fn($o) => $verC === 'escondidos' ? isset($ignC[$o['id']]) : (!isset($ignC[$o['id']]) && ($verC === 'todos' || $o['ativo']))));
    usort($linhasC, fn($x, $y) => strcmp($x['nome'], $y['nome']));
    $jaC = fn($o) => !empty(array_filter($cadC[recibo_chave($o['nome'])] ?? []));
?>
  <div class="topo"><div><h1>Colaboradores do IXC</h1><p class="small">Busca a lista de funcionários do IXC (só leitura, nada é gravado lá). Marque em que área cada colaborador recebe e salve: o portal cadastra nas listas de Instalação, Recolhimento e RH, e grava o CPF para os recibos. Desmarcar tira da lista da área, sem apagar o histórico. Quem não é colaborador de verdade, marque "Não é colaborador" para sumir desta tela.</p></div></div>
  <form method="post" action="?p=colab&amp;ver=<?= h($verC) ?>" class="card row"><?= csrf_campo() ?><input type="hidden" name="acao" value="colab_buscar">
    <button class="pri"><?= $lstC['em'] ? 'Buscar de novo no IXC' : 'Buscar colaboradores no IXC' ?></button>
    <span class="small"><?= $lstC['em'] ? 'Última busca: ' . h($lstC['em']) . ' · ' . count($lstC['lista']) . ' no IXC' : 'Ainda não buscou.' ?></span>
  </form>
  <?php if ($lstC['em']): ?>
  <div class="row noprint" style="margin:0 0 10px">
    <?php foreach (['ativos' => 'Ativos no IXC', 'todos' => 'Todos (com inativos)', 'escondidos' => 'Não colaboradores (' . count($ignC) . ')'] as $kv => $tv): ?><a class="bt<?= $verC === $kv ? ' pri' : '' ?>" href="?p=colab&amp;ver=<?= $kv ?>"><?= h($tv) ?></a><?php endforeach ?>
  </div>
  <?php if ($verC === 'escondidos'): ?>
  <div class="card"><div class="tw"><table class="rec"><thead><tr><th>Nome no IXC</th><th></th></tr></thead>
    <?php foreach ($linhasC as $o): ?><tr><td><?= h($o['nome']) ?></td><td><form method="post" action="?p=colab&amp;ver=escondidos" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="colab_mostrar"><input type="hidden" name="ixc_id" value="<?= h($o['id']) ?>"><button>Voltar para a lista</button></form></td></tr><?php endforeach ?>
    <?php if (!$linhasC): ?><tr><td colspan="2" class="small">Ninguém marcado como não colaborador.</td></tr><?php endif ?>
  </table></div></div>
  <?php else: ?>
  <form method="post" action="?p=colab&amp;ver=<?= h($verC) ?>" class="card" id="colab-form"><?= csrf_campo() ?><input type="hidden" name="acao" value="colab_salvar">
    <p class="small"><?= count($linhasC) ?> na lista · <?= count(array_filter($linhasC, $jaC)) ?> já cadastrados em alguma área. Vendedor não é criado aqui (precisa de usuário e senha do app, em Configurações → Usuários): a coluna só mostra quem já é.</p>
    <div class="tw"><table class="rec">
      <thead><tr><th>Nome no IXC</th><th>CPF</th><?php foreach (COLAB_PAPEIS as [, $tp]): ?><th class="c"><?= h($tp) ?></th><?php endforeach ?><th>Vendedor</th><th class="c">Não é colaborador</th></tr></thead>
      <?php foreach ($linhasC as $o): $kC = recibo_chave($o['nome']); $iC = h($o['id']); $vd = $vendId[$o['id']] ?? ($vendNome[$kC] ?? ''); ?>
      <tr data-colab><td><b><?= h($o['nome']) ?></b><?= $o['ativo'] ? '' : ' <span class="pill er">inativo no IXC</span>' ?><input type="hidden" name="c[<?= $iC ?>][x]" value="1"></td>
        <td><input name="c[<?= $iC ?>][cpf]" value="<?= h(rh_cpf($cpfC[$kC] ?? $o['cpf'])) ?>" inputmode="numeric" placeholder="000.000.000-00" size="14" aria-label="CPF de <?= h($o['nome']) ?>"></td>
        <?php foreach (COLAB_PAPEIS as $pp => [, $tp]): ?><td class="c"><input type="checkbox" name="c[<?= $iC ?>][p][<?= $pp ?>]" value="1" <?= !empty($cadC[$kC][$pp]) ? 'checked' : '' ?> aria-label="<?= h($tp) ?>: <?= h($o['nome']) ?>"></td><?php endforeach ?>
        <td><?= $vd !== '' ? '<span class="pill ok">' . h($vd) . '</span>' : '<span class="small">—</span>' ?></td>
        <td class="c"><input type="checkbox" name="c[<?= $iC ?>][ign]" value="1" aria-label="Não é colaborador: <?= h($o['nome']) ?>"></td></tr>
      <?php endforeach ?>
      <?php if (!$linhasC): ?><tr><td colspan="<?= 4 + count(COLAB_PAPEIS) ?>" class="small">Ninguém nesta lista.</td></tr><?php endif ?>
    </table></div>
    <div class="row" style="margin-top:10px"><button class="pri">Salvar o que marquei</button><span class="small">Só as linhas que você mexeu são gravadas.</span></div>
  </form>
  <script>
  // manda só as linhas mexidas (lista grande passaria do limite de campos do PHP)
  (function () {
    var f = document.getElementById('colab-form');
    f.addEventListener('change', function (e) { var tr = e.target.closest('tr[data-colab]'); if (tr) tr.dataset.mudou = '1'; });
    f.addEventListener('submit', function () {
      f.querySelectorAll('tr[data-colab]').forEach(function (tr) { if (!tr.dataset.mudou) tr.querySelectorAll('input').forEach(function (i) { i.disabled = true; }); });
    });
  })();
  </script>
  <?php endif ?>
  <?php endif ?>

<?php elseif ($p === 'serv'):
    $lsS = serv_lancamentos();
    $sdS = serv_saldos($lsS);
    $eqS = serv_equipes();
    $obS = serv_obras();
    $mesS = date('Y-m');
    $noMes = fn(string $k) => array_sum(array_map(fn($l) => (float)$l['valor'], array_filter($lsS, fn($l) => $l['k'] === $k && str_starts_with($l['data'], $mesS))));
    $faltaS = array_sum(array_map(fn($r) => max(0, $r['falta']), $sdS));
    $comS = count(array_filter($sdS, fn($r) => $r['falta'] > 0.004));
    $cardsS = array_filter($eqS, fn($e, $id) => $e['ativo'] || abs($sdS[$id]['falta']) > 0.004, ARRAY_FILTER_USE_BOTH);
    $porObra = serv_por_obra($lsS);
?>
  <div class="topo"><div><h1>Serviços em execução</h1><p class="small">O que cada equipe já fez, quanto já foi pago e quanto ainda falta acertar. Cada serviço lançado soma; cada pagamento desconta, até zerar.</p></div>
    <div class="row"><?php if ($pode('serv.lanc')): ?><a class="bt pri" href="?p=serv_lancar&amp;k=s">+ Lançar serviço feito</a><?php endif ?><?php if ($pode('serv.pag')): ?><a class="bt cta" href="?p=serv_lancar&amp;k=p">+ Lançar pagamento</a><?php endif ?></div></div>
  <div class="kpis">
    <div class="kpi forte"><span>Falta pagar (total)</span><b><?= rh_rs($faltaS) ?></b><small><?= $comS ?> equipe(s) com saldo</small></div>
    <div class="kpi"><span>Serviços em <?= h(mb_strtolower(mes_nome($mesS))) ?></span><b><?= rh_rs($noMes('s')) ?></b><small>lançados no mês</small></div>
    <div class="kpi"><span>Pago em <?= h(mb_strtolower(mes_nome($mesS))) ?></span><b class="ok"><?= rh_rs($noMes('p')) ?></b><small>descontado do saldo</small></div>
    <div class="kpi"><span>Serviços desde o início</span><b><?= rh_rs(array_sum(array_column($sdS, 'servicos'))) ?></b><small>todas as obras</small></div>
  </div>
  <?php if (!$eqS): ?><div class="msg">Ainda não tem equipe cadastrada. <?= $pode('serv.cad') ? 'Cadastre primeiro em <a href="?p=serv_cad">Equipes, obras e preços</a>.' : 'Peça ao admin para cadastrar.' ?></div><?php endif ?>
  <div class="serv-cards"><?php foreach ($cardsS as $id => $e): $r = $sdS[$id]; ?>
    <div class="card serv-eq">
      <div class="row" style="justify-content:space-between;align-items:start"><div><b class="quem"><?= h($e['nome']) ?></b><br><span class="small"><?= h(SERV_EQUIPE_TIPOS[$e['tipo']] ?? '') ?><?= $e['ativo'] ? '' : ' · inativa' ?></span></div>
        <?= $r['falta'] > 0.004 ? '<span class="pill q">com saldo</span>' : ($r['falta'] < -0.004 ? '<span class="pill mu">com crédito</span>' : '<span class="pill ok">tudo acertado</span>') ?></div>
      <div><span class="small">Falta pagar</span><div class="tot <?= $r['falta'] > 0.004 ? 'er' : 'ok' ?>"><?= rh_rs($r['falta']) ?></div></div>
      <div class="small">Serviços <?= rh_rs($r['servicos']) ?> · pago <?= rh_rs($r['pago']) ?><?= $r['ultimo'] !== '' ? '<br>Último lançamento ' . h(rh_dt($r['ultimo'])) : '' ?></div>
      <div class="row"><a class="bt" href="?p=serv_extrato&amp;e=<?= (int)$id ?>">Ver extrato</a>
        <?php if ($pode('serv.lanc') && $e['ativo']): ?><a class="bt" href="?p=serv_lancar&amp;k=s&amp;e=<?= (int)$id ?>">+ Serviço</a><?php endif ?>
        <?php if ($pode('serv.pag') && $r['falta'] > 0.004): ?><a class="bt pri" href="?p=serv_lancar&amp;k=p&amp;e=<?= (int)$id ?>">+ Pagamento</a><?php endif ?></div>
    </div><?php endforeach ?></div>
  <?php if ($porObra): ?>
  <div class="card"><h2>Saldo por obra</h2><p class="small" style="margin:0 0 8px">O que cada obra já custou. O pago é o que os pagamentos já quitaram dos serviços dela (o pagamento abate primeiro o serviço mais antigo da equipe).</p>
    <div class="tw"><table class="rec"><thead><tr><th>Obra / local</th><th>Tipo de rede</th><th class="r">Serviços</th><th class="r">Pago</th><th class="r">Falta pagar</th></tr></thead>
    <?php foreach ($porObra as $oid => $r): $o = $obS[$oid] ?? null; ?>
      <tr><td><b><?= h($o['nome'] ?? 'Sem obra') ?></b><?= !empty($o['concluida']) ? ' <span class="pill mu">concluída</span>' : '' ?></td><td><?= h($o ? SERV_REDES[$o['rede']] ?? '' : '—') ?></td>
        <td class="n r"><?= rh_rs($r['servicos']) ?></td><td class="n r"><?= rh_rs($r['pago']) ?></td><td class="n r"><b><?= rh_rs($r['falta']) ?></b></td></tr>
    <?php endforeach ?></table></div></div>
  <?php endif ?>

<?php elseif ($p === 'serv_extrato'):
    $eid = (int)($_GET['e'] ?? 0);
    $eqX = serv_equipe($eid);
    if (!$eqX): ?><div class="msg">Equipe não encontrada. <a href="?p=serv">Voltar para os saldos</a>.</div><?php else:
    $lsX = serv_lancamentos($eid);
    $sdX = serv_saldos($lsX)[$eid];
    $obX = serv_obras();
    $oX = (int)($_GET['o'] ?? 0);
    $obrasX = array_unique(array_map(fn($l) => (int)$l['obra_id'], array_filter($lsX, fn($l) => $l['k'] === 's')));
    $linkX = '?p=serv_extrato&amp;e=' . $eid . ($oX ? '&amp;o=' . $oX : '');
?>
  <div class="topo"><div><h1><?= h($eqX['nome']) ?></h1><p class="small"><?= h(SERV_EQUIPE_TIPOS[$eqX['tipo']] ?? '') ?><?= $eqX['contato'] !== '' ? ' · ' . h($eqX['contato']) : '' ?></p></div>
    <div class="row noprint"><a class="bt" href="?p=serv">← Saldos</a><?php if ($pode('serv.lanc') && $eqX['ativo']): ?><a class="bt pri" href="?p=serv_lancar&amp;k=s&amp;e=<?= $eid ?>">+ Serviço</a><?php endif ?><?php if ($pode('serv.pag')): ?><a class="bt cta" href="?p=serv_lancar&amp;k=p&amp;e=<?= $eid ?>">+ Pagamento</a><?php endif ?></div></div>
  <div class="kpis">
    <div class="kpi"><span>Serviços feitos</span><b><?= rh_rs($sdX['servicos']) ?></b></div>
    <div class="kpi"><span>Já pago</span><b class="ok"><?= rh_rs($sdX['pago']) ?></b></div>
    <div class="kpi forte"><span><?= $sdX['falta'] < -0.004 ? 'Crédito da equipe' : 'Falta pagar' ?></span><b><?= rh_rs(abs($sdX['falta'])) ?></b></div>
  </div>
  <div class="card">
    <form method="get" class="row noprint" style="justify-content:space-between"><input type="hidden" name="p" value="serv_extrato"><input type="hidden" name="e" value="<?= $eid ?>">
      <h2 style="margin:0">Extrato</h2>
      <?php if (count($obrasX) > 1): ?><select name="o" aria-label="Obra" data-auto><option value="0">Todas as obras</option><?php foreach ($obrasX as $oid): ?><option value="<?= $oid ?>" <?= $oid === $oX ? 'selected' : '' ?>><?= h($obX[$oid]['nome'] ?? 'Sem obra') ?></option><?php endforeach ?></select><noscript><button>Filtrar</button></noscript><?php endif ?></form>
    <p class="small" style="margin:4px 0 8px">Igual extrato de banco: cada serviço soma, cada pagamento desconta. A última coluna é quanto faltava pagar naquele dia.<?= $oX ? ' Filtrado por obra: os pagamentos continuam aparecendo, porque são da equipe toda.' : '' ?></p>
    <div class="tw"><table class="rec">
      <thead><tr><th>Data</th><th>O quê</th><th>Obra</th><th class="r">Serviço (+)</th><th class="r">Pagamento (−)</th><th class="r">Falta pagar</th><th class="noprint"></th></tr></thead>
      <?php foreach ($lsX as $l): if ($oX && $l['k'] === 's' && (int)$l['obra_id'] !== $oX) continue; ?>
      <tr><td class="n"><?= h(rh_dt($l['data'])) ?></td>
        <td><?= $l['k'] === 'p' ? '<span class="tp tp-segundo">Pagamento</span> ' . h($l['forma'] . ($l['descricao'] !== '' ? ' · ' . $l['descricao'] : '')) : h(serv_oque($l)) . '<br><span class="small">' . h(serv_qtd($l)) . '</span>' ?></td>
        <td><?= $l['k'] === 's' ? h($l['obra'] ?: '—') : '' ?></td>
        <td class="n r"><?= $l['k'] === 's' ? rh_rs((float)$l['valor']) : '' ?></td>
        <td class="n r ok"><?= $l['k'] === 'p' ? '− ' . rh_rs((float)$l['valor']) : '' ?></td>
        <td class="n r"><b><?= rh_rs($l['saldo']) ?></b></td>
        <td class="noprint"><div class="acoes"><?php if ($l['k'] === 'p' && $pode('recibos.serv')): ?><a class="bt" href="?p=recibos&amp;f=1&amp;mes=<?= h(substr($l['data'], 0, 7)) ?>&amp;o[]=serv&amp;g=1&amp;s[]=<?= h(urlencode(recibo_chave($eqX['nome']))) ?>" title="Recibo dos pagamentos do mês para a equipe assinar">Recibo</a><?php endif ?>
          <?php if ($pode($l['k'] === 's' ? 'serv.lanc' : 'serv.pag')): ?><form method="post" action="<?= $linkX ?>" class="in"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="del" name="acao" value="serv_excluir" title="Excluir este lançamento (lançado por <?= h($l['lancado_nome']) ?>)">Excluir</button></form><?php endif ?></div></td></tr>
      <?php endforeach ?>
      <?php if (!$lsX): ?><tr><td colspan="7" class="small">Nada lançado para esta equipe ainda.</td></tr><?php endif ?>
    </table></div>
    <div class="row noprint" style="margin-top:12px;justify-content:flex-end"><button type="button" data-imprimir>Imprimir extrato para conferir com a equipe</button></div>
  </div>
  <?php endif ?>

<?php elseif ($p === 'serv_lista'):
    $lsL = array_values(array_filter(serv_lancamentos(), fn($l) => $l['k'] === 's'));
    $eqL = serv_equipes();
    $obL = serv_obras();
    $fe = (int)($_GET['e'] ?? 0);
    $fo = (int)($_GET['o'] ?? 0);
    $fs = in_array($_GET['s'] ?? '', ['aberto', 'parte', 'pago'], true) ? (string)$_GET['s'] : '';
    $lsL = array_reverse(array_filter($lsL, fn($l) => (!$fe || (int)$l['equipe_id'] === $fe) && (!$fo || (int)$l['obra_id'] === $fo) && (!$fs || $l['sit'] === $fs)));
    $SIT = ['aberto' => ['Em aberto', 'q'], 'parte' => ['Pago em parte', 'q'], 'pago' => ['Quitado', 'ok']];
?>
  <div class="topo"><div><h1>Serviços lançados</h1><p class="small">Todos os serviços, do mais novo para o mais velho. O pagamento abate primeiro o serviço mais antigo da equipe, então dá para ver o que já está quitado e o que está em aberto.</p></div>
    <?php if ($pode('serv.lanc')): ?><a class="bt pri" href="?p=serv_lancar&amp;k=s">+ Lançar serviço feito</a><?php endif ?></div>
  <div class="card">
    <form method="get" class="row"><input type="hidden" name="p" value="serv_lista">
      <select name="e" aria-label="Equipe" data-auto><option value="0">Todas as equipes</option><?php foreach ($eqL as $id => $e): ?><option value="<?= (int)$id ?>" <?= $id === $fe ? 'selected' : '' ?>><?= h($e['nome']) ?></option><?php endforeach ?></select>
      <select name="o" aria-label="Obra" data-auto><option value="0">Todas as obras</option><?php foreach ($obL as $id => $o): ?><option value="<?= (int)$id ?>" <?= $id === $fo ? 'selected' : '' ?>><?= h($o['nome']) ?></option><?php endforeach ?></select>
      <select name="s" aria-label="Situação" data-auto><option value="">Todas as situações</option><?php foreach ($SIT as $k => [$t]): ?><option value="<?= $k ?>" <?= $k === $fs ? 'selected' : '' ?>><?= $t ?></option><?php endforeach ?></select><noscript><button>Filtrar</button></noscript></form>
    <div class="tw" style="margin-top:10px"><table class="rec">
      <thead><tr><th>Data</th><th>Equipe</th><th>O que foi feito</th><th>Obra</th><th class="r">Qtd × valor</th><th class="r">Total</th><th>Situação</th><th>Lançado por</th></tr></thead>
      <?php foreach ($lsL as $l): ?>
      <tr><td class="n"><?= h(rh_dt($l['data'])) ?></td><td><a href="?p=serv_extrato&amp;e=<?= (int)$l['equipe_id'] ?>"><b><?= h($l['equipe']) ?></b></a></td><td><?= h(serv_oque($l)) ?></td><td><?= h($l['obra'] ?: '—') ?></td>
        <td class="n r small"><?= h(serv_qtd($l)) ?></td><td class="n r"><b><?= rh_rs((float)$l['valor']) ?></b></td>
        <td><span class="pill <?= $SIT[$l['sit']][1] ?>"><?= $SIT[$l['sit']][0] ?></span><?= $l['sit'] === 'parte' ? '<br><span class="small">falta ' . rh_rs($l['falta']) . '</span>' : '' ?></td><td class="small"><?= h($l['lancado_nome']) ?></td></tr>
      <?php endforeach ?>
      <?php if (!$lsL): ?><tr><td colspan="8" class="small">Nenhum serviço<?= $fe || $fo || $fs ? ' com esse filtro' : ' lançado ainda' ?>.</td></tr><?php else: ?>
      <tfoot><tr><td colspan="5">Total da lista (<?= count($lsL) ?>)</td><td class="n r"><?= rh_rs(array_sum(array_map(fn($l) => (float)$l['valor'], $lsL))) ?></td><td class="n small" colspan="2">falta <?= rh_rs(array_sum(array_column($lsL, 'falta'))) ?></td></tr></tfoot><?php endif ?>
    </table></div>
  </div>

<?php elseif ($p === 'serv_lancar'):
    $kL = ($_GET['k'] ?? '') === 'p' ? 'p' : 's';
    if ($kL === 's' && !$pode('serv.lanc')) $kL = 'p';
    if ($kL === 'p' && !$pode('serv.pag')) $kL = 's';
    $eqL = serv_equipes();
    $sdL = serv_saldos();
    $eSel = (int)($servForm['equipe_id'] ?? ($_GET['e'] ?? 0));
    $vL = fn(string $k, string $pad = '') => h((string)($servForm[$k] ?? $pad));
    $prL = serv_precos(true);
    $pSel = (int)($servForm['preco_id'] ?? array_key_first($prL) ?? 0);
?>
  <div class="topo"><div><h1>Serviços em execução · Lançar</h1><p class="small">Lance o serviço assim que ele for feito, para não esquecer. Na hora de acertar, lance o pagamento (pode ser parcial): ele desconta do que falta pagar.</p></div>
    <a class="bt" href="?p=serv">← Saldos</a></div>
  <?php if (!$eqL): ?><div class="msg">Ainda não tem equipe cadastrada. <?= $pode('serv.cad') ? 'Cadastre primeiro em <a href="?p=serv_cad">Equipes, obras e preços</a>.' : 'Peça ao admin para cadastrar.' ?></div>
  <?php else: ?>
  <form method="post" action="?p=serv_lancar&amp;k=<?= $kL ?>" class="card" id="form-serv"><?= csrf_campo() ?>
    <nav class="rh-tipos" aria-label="O que lançar"><?php if ($pode('serv.lanc')): ?><a href="?p=serv_lancar&amp;k=s<?= $eSel ? '&amp;e=' . $eSel : '' ?>"<?= $kL === 's' ? ' aria-current="page"' : '' ?>>Serviço feito</a><?php endif ?><?php if ($pode('serv.pag')): ?><a href="?p=serv_lancar&amp;k=p<?= $eSel ? '&amp;e=' . $eSel : '' ?>"<?= $kL === 'p' ? ' aria-current="page"' : '' ?>>Pagamento</a><?php endif ?></nav>
    <div class="rec-form" style="margin-top:12px"<?= $kL === 's' ? ' data-serv' : '' ?>>
      <label>Equipe<select name="l[equipe_id]" required><option value="">escolha</option><?php foreach ($eqL as $id => $e): if ($kL === 's' && !$e['ativo'] && $id !== $eSel) continue; ?><option value="<?= (int)$id ?>" <?= $id === $eSel ? 'selected' : '' ?>><?= h($e['nome']) ?><?= $kL === 'p' ? ' · falta ' . rh_rs($sdL[$id]['falta']) : '' ?></option><?php endforeach ?></select></label>
      <label>Data<input type="date" name="l[data]" value="<?= $vL('data', date('Y-m-d')) ?>" required></label>
      <?php if ($kL === 's'): ?>
      <label>Obra / local<select name="l[obra_id]"><option value="0">sem obra</option><?php foreach (serv_obras(true) as $id => $o): ?><option value="<?= (int)$id ?>" <?= $id === (int)($servForm['obra_id'] ?? 0) ? 'selected' : '' ?>><?= h($o['nome'] . ' · ' . (SERV_REDES[$o['rede']] ?? '')) ?></option><?php endforeach ?></select></label>
      <label>Serviço<select name="l[preco_id]"><?php foreach ($prL as $id => $pr): ?><option value="<?= (int)$id ?>" data-un="<?= h($pr['unidade']) ?>" data-valor="<?= h($pr['valor']) ?>" <?= $id === $pSel ? 'selected' : '' ?>><?= h($pr['nome']) ?> (por <?= h($pr['unidade']) ?><?= $pr['valor'] !== '' ? ', R$ ' . h(brl($pr['valor'])) : '' ?>)</option><?php endforeach ?><option value="0" data-un="" <?= $pSel === 0 ? 'selected' : '' ?>>Valor fechado (digita o total)</option></select></label>
      <label class="so-qtd">Quantidade<input name="l[qtd]" value="<?= $vL('qtd') ?>" placeholder="ex.: 1.500" inputmode="decimal"></label>
      <label class="so-qtd"><span>Valor por unidade R$</span><input name="l[unitario]" value="<?= $vL('unitario') ?>" placeholder="da tabela" inputmode="decimal"></label>
      <label class="so-fechado">Valor do serviço R$ (valor fechado)<input name="l[valor]" value="<?= $vL('valor') ?>" placeholder="0,00" inputmode="decimal"></label>
      <label class="larga">O que foi feito / observação<input name="l[descricao]" value="<?= $vL('descricao') ?>" maxlength="200" placeholder="ex.: trecho da ponte até a sede da fazenda"></label>
      <?php else: $sAt = $eSel ? $sdL[$eSel]['falta'] ?? 0 : null; ?>
      <label>Valor pago R$<input name="l[valor]" value="<?= $vL('valor') ?>" placeholder="0,00" inputmode="decimal" required></label>
      <label>Forma<select name="l[forma]"><?php foreach (SERV_FORMAS as $f): ?><option <?= $f === ($servForm['forma'] ?? 'PIX') ? 'selected' : '' ?>><?= $f ?></option><?php endforeach ?></select></label>
      <label class="larga">Observação<input name="l[descricao]" value="<?= $vL('descricao') ?>" maxlength="200" placeholder="opcional, ex.: acerto da obra Córrego Fundo"></label>
      <?php if ($sAt !== null): ?><div class="larga msg">Falta pagar para <b><?= h($eqL[$eSel]['nome']) ?></b>: <b><?= rh_rs($sAt) ?></b></div><?php endif ?>
      <?php endif ?>
    </div>
    <div class="row" style="margin-top:10px">
      <?php if ($kL === 's'): ?><button class="pri" name="acao" value="serv_servico">Lançar serviço</button><span class="small" data-serv-total>Com serviço da tabela: total = quantidade × valor por unidade (em branco, usa o preço da tabela). Com valor fechado: digita o total.</span>
      <?php else: ?><button class="pri" name="acao" value="serv_pagamento">Lançar pagamento</button><span class="small">Pode ser parcial. Se for mais do que falta, a diferença fica como crédito (adiantamento) da equipe.</span><?php endif ?>
    </div>
  </form>
  <?php endif ?>

<?php elseif ($p === 'serv_cad'):
    $eqC = serv_equipes();
    $obC = serv_obras();
    $prC = serv_precos();
    $sdC = serv_saldos();
    $x = (int)($_GET['editar'] ?? 0);
    $editE = isset($eqC[$x]) ? $eqC[$x] + ['id' => $x] : null;
    $x = (int)($_GET['obra'] ?? 0);
    $editO = isset($obC[$x]) ? $obC[$x] + ['id' => $x] : null;
?>
  <div class="topo"><div><h1>Equipes, obras e preços</h1><p class="small">Cadastros usados nos lançamentos de serviços em execução. Os preços só preenchem o lançamento sozinhos: dá para mudar o valor em cada serviço.</p></div></div>
  <div class="serv-cad">
    <div class="card"><h2>Equipes / prestadores</h2>
      <div class="tw"><table class="rec" style="min-width:0"><thead><tr><th>Nome</th><th>Contato / PIX</th><th class="r">Falta pagar</th><th></th></tr></thead>
      <?php foreach ($eqC as $id => $e): ?><tr><td><b><?= h($e['nome']) ?></b><br><span class="small"><?= h(SERV_EQUIPE_TIPOS[$e['tipo']] ?? '') ?><?= $e['ativo'] ? '' : ' · inativa' ?></span></td><td class="small"><?= h($e['contato'] ?: '—') ?></td><td class="n r"><?= rh_rs($sdC[$id]['falta']) ?></td>
        <td><div class="acoes"><a class="bt" href="?p=serv_cad&amp;editar=<?= (int)$id ?>#form-eq">Corrigir</a><form method="post" action="?p=serv_cad" class="in"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><button name="acao" value="serv_equipe_ativo"><?= $e['ativo'] ? 'Desativar' : 'Reativar' ?></button></form></div></td></tr><?php endforeach ?>
      <?php if (!$eqC): ?><tr><td colspan="4" class="small">Nenhuma equipe ainda.</td></tr><?php endif ?></table></div>
      <form method="post" action="?p=serv_cad" class="rec-form" id="form-eq" style="margin-top:12px"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)($editE['id'] ?? 0) ?>">
        <label class="larga"><?= $editE ? 'Corrigir ' . h($editE['nome']) : 'Nova equipe' ?><input name="e[nome]" value="<?= h($editE['nome'] ?? '') ?>" maxlength="80" placeholder="ex.: EQUIPE RAFAEL" required></label>
        <label>Tipo<select name="e[tipo]"><?php foreach (SERV_EQUIPE_TIPOS as $k => $t): ?><option value="<?= $k ?>" <?= $k === ($editE['tipo'] ?? 'terc') ? 'selected' : '' ?>><?= $t ?></option><?php endforeach ?></select></label>
        <label>Contato / PIX<input name="e[contato]" value="<?= h($editE['contato'] ?? '') ?>" maxlength="120" placeholder="opcional"></label>
        <div class="larga row"><button class="pri" name="acao" value="serv_equipe_salvar"><?= $editE ? 'Salvar' : 'Cadastrar equipe' ?></button><?php if ($editE): ?><a href="?p=serv_cad">Cancelar</a><?php endif ?></div>
      </form>
    </div>
    <div class="card"><h2>Obras / locais</h2>
      <div class="tw"><table class="rec" style="min-width:0"><thead><tr><th>Obra</th><th>Tipo de rede</th><th></th></tr></thead>
      <?php foreach ($obC as $id => $o): ?><tr><td><b><?= h($o['nome']) ?></b><br><?= $o['concluida'] ? '<span class="pill mu">concluída</span>' : '<span class="pill ok">em execução</span>' ?></td><td><?= h(SERV_REDES[$o['rede']] ?? '') ?></td>
        <td><div class="acoes"><a class="bt" href="?p=serv_cad&amp;obra=<?= (int)$id ?>#form-ob">Corrigir</a><form method="post" action="?p=serv_cad" class="in"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><button name="acao" value="serv_obra_concluir"><?= $o['concluida'] ? 'Reabrir' : 'Concluir' ?></button></form></div></td></tr><?php endforeach ?>
      <?php if (!$obC): ?><tr><td colspan="3" class="small">Nenhuma obra ainda. Obra é opcional: serve para saber quanto cada rede custou.</td></tr><?php endif ?></table></div>
      <form method="post" action="?p=serv_cad" class="rec-form" id="form-ob" style="margin-top:12px"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)($editO['id'] ?? 0) ?>">
        <label class="larga"><?= $editO ? 'Corrigir ' . h($editO['nome']) : 'Nova obra / local' ?><input name="o[nome]" value="<?= h($editO['nome'] ?? '') ?>" maxlength="80" placeholder="ex.: Rural Córrego Fundo" required></label>
        <label>Tipo de rede<select name="o[rede]"><?php foreach (SERV_REDES as $k => $t): ?><option value="<?= $k ?>" <?= $k === ($editO['rede'] ?? 'rural') ? 'selected' : '' ?>><?= $t ?></option><?php endforeach ?></select></label>
        <div class="larga row"><button class="pri" name="acao" value="serv_obra_salvar"><?= $editO ? 'Salvar' : 'Cadastrar obra' ?></button><?php if ($editO): ?><a href="?p=serv_cad">Cancelar</a><?php endif ?></div>
      </form>
    </div>
  </div>
  <form method="post" action="?p=serv_cad" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="serv_precos_salvar">
    <h2>Tabela de preços</h2><p class="small" style="margin:0 0 8px">Preço sugerido de cada serviço. No lançamento, quantidade × preço; se uma equipe cobra diferente, é só trocar o valor na hora de lançar. Desmarcando "usar", o serviço some da lista de lançamento (os lançamentos antigos continuam).</p>
    <div class="tw"><table class="rec"><thead><tr><th>Serviço</th><th>Cobra por</th><th class="r">Valor R$</th><th>Usar</th></tr></thead>
    <?php foreach ($prC as $id => $pr): ?><tr><td><input name="pr[<?= (int)$id ?>][nome]" value="<?= h($pr['nome']) ?>" maxlength="80" aria-label="Serviço" style="width:100%"></td><td><input name="pr[<?= (int)$id ?>][unidade]" value="<?= h($pr['unidade']) ?>" maxlength="20" size="8" aria-label="Cobra por"></td>
      <td class="r"><input class="val" name="pr[<?= (int)$id ?>][valor]" value="<?= h(brl($pr['valor'])) ?>" inputmode="decimal" placeholder="0,00" aria-label="Valor"></td><td><input type="checkbox" name="pr[<?= (int)$id ?>][ativo]" value="1" <?= $pr['ativo'] ? 'checked' : '' ?> aria-label="Usar"></td></tr><?php endforeach ?>
    <tr><td><input name="novo[nome]" maxlength="80" placeholder="+ serviço novo" aria-label="Serviço novo" style="width:100%"></td><td><input name="novo[unidade]" maxlength="20" size="8" placeholder="metro" aria-label="Cobra por"></td><td class="r"><input class="val" name="novo[valor]" inputmode="decimal" placeholder="0,00" aria-label="Valor"></td><td></td></tr>
    </table></div>
    <div class="row" style="margin-top:10px"><button class="pri">Salvar tabela de preços</button></div>
  </form>

<?php elseif ($p === 'sem'): ?>
  <div class="msg">Você não tem acesso a esta área. Peça ao gestor admin para liberar em Configurações.</div>

<?php elseif ($p === 'senha'): ?>
  <form method="post" class="card login"><?= csrf_campo() ?><input type="hidden" name="acao" value="minha_senha">
    <h2>Trocar minha senha</h2>
    <label for="atual">Senha atual</label><input id="atual" name="atual" type="password" autocomplete="current-password" required>
    <label for="nova">Senha nova (8+ caracteres, letras e números)</label><input id="nova" name="nova" type="password" autocomplete="new-password" minlength="8" required>
    <label for="nova2">Repita a senha nova</label><input id="nova2" name="nova2" type="password" autocomplete="new-password" minlength="8" required>
    <button class="pri">Trocar senha</button></form>

<?php elseif ($p === 'config'):
    $gsC = db()->query('SELECT id, nome, usuario, admin, ativo, permissoes FROM gestores ORDER BY admin DESC, ativo DESC, nome')->fetchAll();
    $gsN = array_values(array_filter($gsC, fn($g) => !$g['admin']));
    $gSel = null;
    foreach ($gsN as $g) if ((int)$g['id'] === (int)($_GET['g'] ?? $_POST['ids'][0] ?? 0)) $gSel = $g;
    $gSel ??= $gsN[0] ?? null;
    $plSel = $gSel ? perm_lista($gSel['permissoes']) : [];
    $nFolhas = count(perm_folhas());
    /** desenha a árvore de caixinhas: o grupo marca/desmarca tudo dentro dele (olho.js); só as folhas vão no formulário */
    $arvore = function (array $ramo, int $nv) use (&$arvore, $gSel, $plSel): string {
        $h = '';
        foreach ($ramo as $k => [$t, $filhos]) {
            $fs = $filhos ? perm_folhas($filhos) : [$k];
            $on = count(array_intersect($fs, $plSel));
            $estado = $on === count($fs) ? ' checked' : ($on ? ' data-meio="1"' : '');
            $nome = $filhos ? '' : ' name="perm[' . (int)$gSel['id'] . '][' . h($k) . ']" value="1"';
            $h .= '<label class="pn pn' . $nv . '"><input type="checkbox" data-k="' . h($k) . '"' . $nome . $estado . '> ' . h($t) . '</label>';
            if ($filhos) $h .= $arvore($filhos, $nv + 1);
        }
        return $h;
    };
?>
  <div class="topo"><div><h1>Permissões</h1><p class="small">Escolha o gestor e marque cada opção e subopção do menu que ele pode abrir. No RH dá para liberar cada tipo de lançamento: o tipo que não estiver marcado some para ele na hora de lançar, na lista, no fechamento e no recibo. O admin sempre vê tudo. As metas da dashboard só o admin define; quem tem Dashboard só vê.</p></div></div>
  <div class="card">
    <h2>Gestor</h2>
    <div class="row perm-gestores"><?php foreach ($gsC as $g): $nP = count(perm_lista($g['permissoes'])); ?>
      <?php if ($g['admin']): ?><span class="pill ok" title="vê e mexe em tudo"><?= h($g['nome']) ?> · admin</span>
      <?php else: ?><a class="bt<?= $gSel && (int)$g['id'] === (int)$gSel['id'] ? ' on' : '' ?>" href="?p=config&amp;g=<?= (int)$g['id'] ?>"><?= h($g['nome']) ?> <span class="small"><?= $nP ?>/<?= $nFolhas ?><?= $g['ativo'] ? '' : ' · bloqueado' ?></span></a><?php endif ?>
    <?php endforeach ?></div>
  </div>
  <?php if ($gSel): ?>
  <form method="post" action="?p=config&amp;g=<?= (int)$gSel['id'] ?>" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="perm_salvar"><input type="hidden" name="ids[]" value="<?= (int)$gSel['id'] ?>">
    <h2>O que <?= h($gSel['nome']) ?> pode abrir <span class="small">(<?= h($gSel['usuario']) ?>)</span></h2>
    <div class="perm-arvore"><?= $arvore(PERM_ARVORE, 0) ?><label class="pn pn0 off"><input type="checkbox" disabled> Configurações → Permissões <span class="small">(só admin)</span></label></div>
    <p class="small">Marcar o item de cima marca tudo dentro dele. Recibo e "Marcar pago" do RH só aparecem para quem tem todos os tipos de lançamento. Criar gestores, gerar senha, bloquear, dar admin e desfazer pagamento do RH continuam só com o admin.</p>
    <div class="row" style="justify-content:flex-end"><button class="pri">Salvar permissões de <?= h($gSel['nome']) ?></button></div>
  </form>
  <?php else: ?><div class="msg">Não tem gestor sem admin para configurar. Crie em Configurações → Gestores.</div><?php endif ?>
  <?php $vsC = db()->query('SELECT id, nome, supervisor FROM vendedores WHERE ativo = 1 OR supervisor = 1 ORDER BY nome')->fetchAll(); ?>
  <form method="post" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="supervisor_salvar">
    <h2>Supervisor da equipe</h2>
    <p class="small" style="margin:0 0 8px">O supervisor não tem meta própria: na Dashboard, a meta e o resultado dele são a soma das vendas e upgrades de todos os outros vendedores. Ele sai do ranking e da lista de metas e aparece na linha de total.</p>
    <div class="row"><select name="supervisor" aria-label="Supervisor"><option value="0">Nenhum</option><?php foreach ($vsC as $v): ?><option value="<?= (int)$v['id'] ?>" <?= $v['supervisor'] ? 'selected' : '' ?>><?= h($v['nome']) ?></option><?php endforeach ?></select>
      <button class="pri">Salvar supervisor</button></div>
    <p class="small">A lista é dos vendedores (aba Usuários). Se o supervisor ainda não está lá, cadastre ele primeiro.</p>
  </form>
  <div class="card"><h2>Recolhedores</h2>
    <p class="small" style="margin:0 0 8px">Quem vai buscar os equipamentos (ex.: vendedor). Só aparece como recolhedor nas O.S e na comissão: não entra no sistema. É a lista do campo "Quem recolheu". Esconder tira da lista, mas as O.S antigas continuam com o nome.</p>
    <div class="row"><?php foreach (rec_tecnicos() as $tc): ?><form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="rec_tec_ativo"><input type="hidden" name="id" value="<?= (int)$tc['id'] ?>"><span class="pill <?= $tc['ativo'] ? 'mu' : 'er' ?>"><?= h($tc['nome']) ?><?= $tc['ativo'] ? '' : ' · escondido' ?></span> <button title="<?= $tc['ativo'] ? 'Esconder da lista' : 'Mostrar de novo' ?>"><?= $tc['ativo'] ? 'Esconder' : 'Mostrar' ?></button></form><?php endforeach ?></div>
    <form method="post" class="row" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="acao" value="rec_tec_novo"><input name="nome" placeholder="NOME DO RECOLHEDOR" required aria-label="Nome do recolhedor"><button class="pri">Adicionar recolhedor</button></form>
  </div>
  <?php foreach (['tec' => ['Técnicos de instalação', 'Quem faz as instalações. É a lista do campo "Técnico" das Instalações concluídas; o técnico que vem na O.S do IXC entra aqui sozinho ao clicar em Buscar O.S.', 'NOME DO TÉCNICO', 'Adicionar técnico'],
      'agenda' => ['Quem agenda as instalações', 'Quem agenda a instalação também recebe comissão. É a lista do campo "Quem agendou".', 'NOME DE QUEM AGENDA', 'Adicionar']] as $tl => [$tt, $td, $tph, $tbt]): ?>
  <div class="card"><h2><?= h($tt) ?></h2>
    <p class="small" style="margin:0 0 8px"><?= h($td) ?> Só aparece pelo nome, não entra no sistema. Esconder tira da lista, mas as O.S antigas continuam com o nome.</p>
    <div class="row"><?php foreach (inst_lista($tl) as $tc): ?><form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="inst_lista_ativo"><input type="hidden" name="tipo" value="<?= $tl ?>"><input type="hidden" name="id" value="<?= (int)$tc['id'] ?>"><span class="pill <?= $tc['ativo'] ? 'mu' : 'er' ?>"><?= h($tc['nome']) ?><?= $tc['ativo'] ? '' : ' · escondido' ?></span> <button title="<?= $tc['ativo'] ? 'Esconder da lista' : 'Mostrar de novo' ?>"><?= $tc['ativo'] ? 'Esconder' : 'Mostrar' ?></button></form><?php endforeach ?></div>
    <form method="post" class="row" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="acao" value="inst_lista_novo"><input type="hidden" name="tipo" value="<?= $tl ?>"><input name="nome" placeholder="<?= h($tph) ?>" required aria-label="<?= h($tph) ?>"><button class="pri"><?= h($tbt) ?></button></form>
  </div>
  <?php endforeach ?>

<?php elseif ($p === 'gestores'): ?>
  <div class="card tw"><table>
    <tr><th>Gestor</th><th>Usuário</th><th>Acesso</th><th>Admin (tudo)</th><th>Último acesso</th><th></th></tr>
    <?php foreach (db()->query('SELECT * FROM gestores ORDER BY ativo DESC, nome') as $g): ?>
    <tr><td><?= h($g['nome']) ?></td><td><?= h($g['usuario']) ?></td>
      <td><?= $g['ativo'] ? '<span class="pill ok">Ativo</span>' : '<span class="pill er">Bloqueado</span>' ?></td>
      <td><?= $g['admin'] ? '<span class="pill ok">Admin</span>' : '<span class="small">não</span>' ?>
        <?php if ($admin && (int)$g['id'] !== $gestor['id']): ?><form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="gest_senha"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button>Gerar senha nova</button></form>
        <form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="gest_admin"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button><?= $g['admin'] ? 'Tirar admin' : 'Tornar admin' ?></button></form><?php endif ?></td>
      <td class="n"><?= h($g['ultimo_acesso'] ?? '—') ?></td>
      <td><?php if ($admin && (int)$g['id'] !== $gestor['id']): ?><form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="gest_ativo"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button class="<?= $g['ativo'] ? 'del' : '' ?>"><?= $g['ativo'] ? 'Bloquear' : 'Liberar' ?></button></form><?php elseif ((int)$g['id'] === $gestor['id']): ?><span class="small">você</span><?php endif ?></td></tr>
    <?php endforeach ?>
  </table></div>
  <?php if ($admin): ?><form method="post" class="card row"><?= csrf_campo() ?><input type="hidden" name="acao" value="gest_novo">
    <label for="gnome">Novo gestor</label><input id="gnome" name="nome" placeholder="NOME" required>
    <label for="gusu">Usuário</label><input id="gusu" name="usuario" autocapitalize="none" size="12" required pattern="[a-z0-9._-]{3,40}">
    <button class="pri">Criar e gerar senha</button></form><?php endif ?>
  <div class="card tw"><h2>Últimas ações</h2><table>
    <tr><th>Quando</th><th>Quem</th><th>O quê</th></tr>
    <?php foreach (db()->query('SELECT * FROM registro ORDER BY id DESC LIMIT 40') as $l): ?>
    <tr><td class="n"><?= h($l['em']) ?></td><td><?= h($l['quem']) ?></td><td><?= h($l['acao'] . ($l['detalhe'] !== '' ? ': ' . $l['detalhe'] : '')) ?></td></tr>
    <?php endforeach ?>
  </table></div>

<?php else:
    // Filtros combináveis: situação (st), tipo da venda (tp) e vendedor (vd).
    $filtro = in_array($_GET['st'] ?? '', ['erro', 'fila', 'crm'], true) ? $_GET['st'] : '';
    $fTipo = isset($NOMES[$_GET['tp'] ?? '']) ? $_GET['tp'] : '';
    $fVend = (int)($_GET['vd'] ?? 0);
    $cond = []; $par = [];
    if ($filtro !== '') $cond[] = match ($filtro) { 'erro' => "v.status='erro'", 'fila' => "v.status IN ('fila','forcar','tentar')", 'crm' => "v.status='crm'" };
    if ($fTipo !== '') { $cond[] = 'v.tipo = ?'; $par[] = $fTipo; }
    if ($fVend > 0) { $cond[] = 'v.vendedor_id = ?'; $par[] = $fVend; }
    $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
    $cont = db()->query("SELECT status, COUNT(*) n FROM vendas GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $contTipo = db()->query("SELECT tipo, COUNT(*) n FROM vendas WHERE status <> 'descartada' GROUP BY tipo")->fetchAll(PDO::FETCH_KEY_PAIR);
    $nFila = ($cont['fila'] ?? 0) + ($cont['forcar'] ?? 0) + ($cont['tentar'] ?? 0);
    $vendLista = db()->query("SELECT d.id, d.nome FROM vendedores d WHERE d.ativo = 1 OR EXISTS (SELECT 1 FROM vendas v WHERE v.vendedor_id = d.id) ORDER BY d.nome")->fetchAll(PDO::FETCH_KEY_PAIR);
    $precosV = comissoes_precos_planos();
    // Monta o link de um filtro mantendo os outros dois.
    $lnk = function (array $muda) use ($filtro, $fTipo, $fVend): string {
        $q = array_merge(['p' => 'vendas', 'st' => $filtro, 'tp' => $fTipo, 'vd' => $fVend ?: ''], $muda);
        return '?' . h(http_build_query(array_filter($q, fn($x) => $x !== '' && $x !== null)));
    };
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
  <div class="row filtros">
    <a href="<?= $lnk(['st' => '', 'tp' => '', 'vd' => '']) ?>" class="<?= $filtro === '' && $fTipo === '' && !$fVend ? 'on' : '' ?>"><span class="pill mu">Todas</span></a>
    <a href="<?= $lnk(['st' => 'fila']) ?>" class="<?= $filtro === 'fila' ? 'on' : '' ?>"><span class="pill q">Na fila <?= $nFila ?></span></a>
    <a href="<?= $lnk(['st' => 'erro']) ?>" class="<?= $filtro === 'erro' ? 'on' : '' ?>"><span class="pill er">Com erro <?= (int)($cont['erro'] ?? 0) ?></span></a>
    <a href="<?= $lnk(['st' => 'crm']) ?>" class="<?= $filtro === 'crm' ? 'on' : '' ?>"><span class="pill ok">No <?= $DEST ?> <?= (int)($cont['crm'] ?? 0) ?></span></a>
    <span class="sep" aria-hidden="true"></span>
    <?php foreach ($NOMES as $tk => $tn): ?><a href="<?= $lnk(['tp' => $fTipo === $tk ? '' : $tk]) ?>" class="<?= $fTipo === $tk ? 'on' : '' ?>"><span class="tp tp-<?= h($tk) ?>"><?= h($tn) ?> <?= (int)($contTipo[$tk] ?? 0) ?></span></a><?php endforeach ?>
    <span class="sep" aria-hidden="true"></span>
    <form method="get" class="in"><input type="hidden" name="p" value="vendas"><?php if ($filtro !== ''): ?><input type="hidden" name="st" value="<?= h($filtro) ?>"><?php endif ?><?php if ($fTipo !== ''): ?><input type="hidden" name="tp" value="<?= h($fTipo) ?>"><?php endif ?>
      <select name="vd" aria-label="Vendedor" data-auto><option value="">Todos os vendedores</option><?php foreach ($vendLista as $vid => $vn): ?><option value="<?= (int)$vid ?>" <?= $fVend === (int)$vid ? 'selected' : '' ?>><?= h($vn) ?></option><?php endforeach ?></select>
      <noscript><button>Filtrar</button></noscript></form>
  </div>
  <div class="card tw"><table>
    <tr><th>#</th><th>Recebido</th><th>Tipo</th><th>Cliente</th><th>Plano</th><th>Vendedor</th><th><?= $DEST ?></th><th></th></tr>
    <?php $qV = db()->prepare("SELECT v.*, d.nome AS vendedor, c.valor_plano FROM vendas v JOIN vendedores d ON d.id=v.vendedor_id LEFT JOIN comissoes c ON c.venda_id = v.id $where ORDER BY v.id DESC LIMIT 300"); $qV->execute($par); $nLinhas = 0;
    foreach ($qV as $v): $nLinhas++;
        [$rot, $cls] = $v['status'] === 'crm' && registro_so_comercial($v['crm_id']) ? ['Registrado', 'ok'] : ($SITUACAO[$v['status']] ?? [$v['status'], 'mu']); $r = json_decode($v['dados'], true) ?: [];
        // Plano e valor da mensalidade (mesma regra da aba "Vendas do mês").
        $planoV = $v['tipo'] === 'upgrade' ? up((string)($r['velNova'] ?? '')) : up((string)($r['plano'] ?? ''));
        $valorV = (string)($v['valor_plano'] ?? '');
        if ($valorV === '') $valorV = $v['tipo'] === 'upgrade' ? dinheiro((string)($r['valNovo'] ?? '')) : (string)($precosV[up(sem_acento((string)($r['plano'] ?? '')))] ?? ''); ?>
    <tr><td class="n"><?= (int)$v['id'] ?></td><td class="n"><?= h($v['recebido_em']) ?></td><td><span class="tp tp-<?= h($v['tipo']) ?>"><?= h($NOMES[$v['tipo']] ?? $v['tipo']) ?></span></td>
      <td><?= h($v['nome']) ?><br><span class="small"><?= h(($r['cpf'] ?? '') . ' · ' . ($r['tel1'] ?? '') . ' · ' . ($r['cidade'] ?? '')) ?></span>
        <details><summary>ver cadastro</summary><pre><?= h(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></details></td>
      <td class="plv"><?= $planoV !== '' ? h($planoV) : '<span class="small">—</span>' ?><?php if ($v['tipo'] === 'upgrade' && ($r['planoAtual'] ?? '') !== ''): ?><br><span class="small">antes: <?= h(up((string)$r['planoAtual'])) ?></span><?php endif ?><?php if ($valorV !== '' && (float)$valorV > 0): ?><br><b>R$ <?= brl($valorV) ?></b><span class="small">/mês</span><?php endif ?></td>
      <td><?= h($v['vendedor']) ?></td>
      <td><span class="pill <?= $cls ?>"><?= h($rot) ?></span> <?= h($v['crm_id']) ?><?php if ($v['erro']): ?><div class="err"><?= h($v['erro']) ?></div><?php endif ?></td>
      <td class="row"><?php if (in_array($v['status'], ['erro', 'tentar'], true)): ?>
        <form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="tentar"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button>Tentar de novo</button></form>
        <?php if (stripos($v['erro'], 'duplicado') !== false): ?><form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="forcar"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button>Enviar mesmo assim</button></form><?php endif ?>
        <form method="post" class="in"><?= csrf_campo() ?><input type="hidden" name="acao" value="descartar"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button>Descartar</button></form>
      <?php endif ?>
        <a class="bt del" href="?p=excluir&amp;id=<?= (int)$v['id'] ?>">Excluir</a></td></tr>
    <?php endforeach ?>
    <?php if (!$nLinhas): ?><tr><td colspan="8" class="small">Nenhuma venda com esses filtros.</td></tr><?php endif ?>
  </table></div>
<?php endif ?>
</main>
</body>
</html>
