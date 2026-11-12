<?php
/**
 * API que o app "Comercial + Sidnet" chama.
 *
 *   POST /api/app-vendas.php?acao=login    {usuario, senha, aparelho} -> {ok, token, nome}
 *   POST /api/app-vendas.php?acao=sair     encerra o login deste celular
 *   POST /api/app-vendas.php?acao=enviar   (Authorization: Bearer <token do login>)
 *        {id_app, tipo: venda|segundo|upgrade, registro:{...campos do app...}}
 *        -> {ok:true, id:"Recebido #12"}  e logo em seguida o servidor manda ao CRM
 *   GET  /api/app-vendas.php?acao=status&ids=a,b,c   -> situação no CRM de cada cadastro
 *   GET  /api/app-vendas.php?acao=eu                  -> confere o login, devolve o nome do vendedor
 *   POST /api/app-vendas.php?acao=senha               {atual, nova} troca a senha provisória pela do vendedor
 *   GET  /api/app-vendas.php?acao=boletos&ids=a,b     -> 1º boleto de cada venda: pagou S/N/'' e vencimento
 *   GET  /api/app-vendas.php?acao=planos              -> planos definidos no painel (vazio = o celular usa a lista dele)
 *
 * Reenviar o mesmo id_app não duplica: devolve o que já foi recebido.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/repasse.php';
require_once __DIR__ . '/../../lib/vendedores.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // o app roda num WebView, com origem própria
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function responder(int $status, array $corpo): never
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Responde ao celular na hora e, com a conexão já fechada, manda a fila ao CRM.
 * Assim a venda entra no CRM em segundos, sem esperar o cron nem o painel.
 */
function responder_e_repassar(array $corpo): never
{
    ignore_user_abort(true);
    http_response_code(200);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    set_time_limit(120);
    try {
        foreach (processar_fila_com_trava(true) as $linha) {
            error_log('app-vendas repasse: ' . $linha);
        }
    } catch (Throwable $e) {
        error_log('app-vendas repasse: ' . $e->getMessage()); // o cron tenta de novo
    }
    exit;
}

/** Vendedor dono do token de login. A chave antiga (antes do login com senha) não vale mais. */
function vendedor_da_chave(): array
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+([A-Za-z0-9]{20,64})$/', $h, $m)) {
        responder(401, ['ok' => false, 'erro' => 'Entre com seu usuário e senha.', 'login' => true]);
    }
    $hash = hash('sha256', $m[1]);
    sessoes_vencidas_apagar();
    $q = db()->prepare('SELECT v.* FROM sessoes s JOIN vendedores v ON v.id = s.vendedor_id WHERE s.token_hash = ?');
    $q->execute([$hash]);
    $v = $q->fetch();
    if ($v) {
        db()->prepare("UPDATE sessoes SET ultimo_uso = datetime('now','localtime') WHERE token_hash = ?")->execute([$hash]);
    }
    if (!$v) {
        responder(401, ['ok' => false, 'erro' => 'Sua sessão terminou. Entre de novo com usuário e senha.', 'login' => true]);
    }
    if (!$v['ativo']) {
        responder(403, ['ok' => false, 'erro' => 'Seu acesso está bloqueado. Fale com o gestor.', 'login' => true]);
    }
    db()->prepare("UPDATE vendedores SET ultimo_uso = datetime('now','localtime') WHERE id = ?")->execute([$v['id']]);
    return $v;
}

function situacao(array $v): array
{
    return match ($v['status']) {
        'crm' => ['st' => 'ok', 'crmId' => $v['crm_id']],
        'erro' => ['st' => 'er', 'msg' => $v['erro']],
        default => ['st' => 'srv', 'msg' => 'Recebido. Entrando no ' . nome_destino() . '.'],
    };
}

try {
    $acao = $_GET['acao'] ?? '';

    if ($acao === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input') ?: '', true);
        try {
            [$v, $token] = vendedor_login((string)($in['usuario'] ?? ''), (string)($in['senha'] ?? ''), (string)($in['aparelho'] ?? ''));
        } catch (RuntimeException $e) {
            usleep(400000);
            responder(401, ['ok' => false, 'erro' => $e->getMessage()]);
        }
        responder(200, ['ok' => true, 'token' => $token, 'nome' => $v['nome'], 'usuario' => $v['usuario'], 'planos' => planos_ativos(), 'trocar' => (bool)($v['trocar_senha'] ?? 0)]);
    }

    $vend = vendedor_da_chave();

    if ($acao === 'sair' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        db()->prepare('DELETE FROM sessoes WHERE token_hash = ?')->execute([hash('sha256', trim(substr($h, 7)))]);
        responder(200, ['ok' => true]);
    }

    if ($acao === 'eu') {
        responder(200, ['ok' => true, 'nome' => $vend['nome'], 'trocar' => (bool)($vend['trocar_senha'] ?? 0)]);
    }

    if ($acao === 'planos') {
        responder(200, ['ok' => true, 'planos' => planos_ativos(), 'trocar' => (bool)($vend['trocar_senha'] ?? 0)]);
    }

    if ($acao === 'senha' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        try {
            vendedor_trocar_senha($vend, (string)($in['atual'] ?? ''), (string)($in['nova'] ?? ''), trim(substr($h, 7)));
        } catch (RuntimeException $e) {
            usleep(300000);
            responder(422, ['ok' => false, 'erro' => $e->getMessage()]);
        }
        responder(200, ['ok' => true]);
    }

    if ($acao === 'boletos') {
        // situação do 1º boleto das vendas deste vendedor (vem do IXC pela tela de comissões)
        $ids = array_slice(array_filter(explode(',', (string)($_GET['ids'] ?? ''))), 0, 300);
        $res = [];
        if ($ids) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $q = db()->prepare("SELECT v.id_app, c.cliente_pagou, c.vencimento FROM vendas v JOIN comissoes c ON c.venda_id = v.id
                                WHERE v.vendedor_id = ? AND v.id_app IN ($marcas)");
            $q->execute(array_merge([$vend['id']], $ids));
            foreach ($q as $r) {
                if ($r['vencimento'] === '' && $r['cliente_pagou'] === '') continue;
                $pagou = $r['cliente_pagou'];
                // "não pagou" só vale para o vendedor depois do vencimento; antes disso o boleto está a vencer
                if ($pagou === 'N' && $r['vencimento'] !== '' && $r['vencimento'] >= date('Y-m-d')) $pagou = '';
                $res[$r['id_app']] = ['pagou' => $pagou, 'venc' => $r['vencimento']];
            }
        }
        responder(200, ['ok' => true, 'boletos' => $res]);
    }

    if ($acao === 'valores' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // venda que já está no IXC: o vendedor só pode corrigir instalação e roteador (vão para as comissões)
        $in = json_decode(file_get_contents('php://input') ?: '', true);
        $idApp = preg_replace('/[^A-Za-z0-9]/', '', (string)($in['id_app'] ?? ''));
        $q = db()->prepare('SELECT id, vendedor_id, dados FROM vendas WHERE id_app = ?');
        $q->execute([$idApp]);
        $v = $idApp !== '' ? $q->fetch() : false;
        if (!$v || (int)$v['vendedor_id'] !== (int)$vend['id']) {
            responder(404, ['ok' => false, 'fim' => true, 'erro' => 'Venda não encontrada no servidor.']);
        }
        require_once __DIR__ . '/../../lib/comissoes.php';
        $inst = dinheiro((string)($in['valInst'] ?? ''));
        $rot = dinheiro((string)($in['valRot'] ?? ''));
        $d = json_decode((string)$v['dados'], true) ?: [];
        $d['valInst'] = mb_substr((string)($in['valInst'] ?? ''), 0, 20);
        $d['valRot'] = mb_substr((string)($in['valRot'] ?? ''), 0, 20);
        db()->prepare("UPDATE vendas SET dados = ?, atualizado_em = datetime('now','localtime') WHERE id = ?")
            ->execute([json_encode($d, JSON_UNESCAPED_UNICODE), $v['id']]);
        // a comissão já paga não muda
        db()->prepare("UPDATE comissoes SET instalacao = ?, roteador = ?, atualizado_em = datetime('now','localtime'), por = 'vendedor' WHERE venda_id = ? AND pago_em = ''")
            ->execute([$inst, $rot, $v['id']]);
        responder(200, ['ok' => true]);
    }

    if ($acao === 'status') {
        $ids = array_slice(array_filter(explode(',', (string)($_GET['ids'] ?? ''))), 0, 200);
        $res = [];
        if ($ids) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $q = db()->prepare("SELECT id_app, status, crm_id, erro FROM vendas WHERE vendedor_id = ? AND id_app IN ($marcas)");
            $q->execute(array_merge([$vend['id']], $ids));
            foreach ($q->fetchAll() as $v) {
                $res[$v['id_app']] = situacao($v);
            }
            $q = db()->prepare("SELECT id_app FROM excluidas WHERE id_app IN ($marcas)");
            $q->execute($ids);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $idx) {
                $res[$idx] = ['st' => 'er', 'msg' => 'Excluída pelo gestor no painel.', 'excluida' => true];
            }
        }
        responder(200, ['ok' => true, 'status' => $res]);
    }

    if ($acao === 'enviar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($in)) {
            responder(400, ['ok' => false, 'erro' => 'Envio inválido.']);
        }
        $idApp = preg_replace('/[^A-Za-z0-9]/', '', (string)($in['id_app'] ?? ''));
        $tipo = (string)($in['tipo'] ?? '');
        $reg = is_array($in['registro'] ?? null) ? registro_limpo($in['registro']) : null;
        if ($idApp === '' || strlen($idApp) > 40 || !in_array($tipo, ['venda', 'segundo', 'upgrade'], true) || !$reg || up($reg['nome'] ?? '') === '') {
            responder(422, ['ok' => false, 'erro' => 'Cadastro incompleto.']);
        }
        unset($reg['midias']); // fotos e vídeos ainda vão pelo WhatsApp

        // limite por vendedor: um login roubado não consegue encher o CRM de cadastros falsos
        $q = db()->prepare("SELECT COUNT(*) FROM vendas WHERE vendedor_id = ? AND recebido_em > datetime('now','localtime','-1 hour')");
        $q->execute([$vend['id']]);
        if ((int)$q->fetchColumn() >= 40) {
            error_log('app-vendas: limite por hora atingido pelo vendedor #' . $vend['id']);
            responder(429, ['ok' => false, 'erro' => 'Muitos cadastros em pouco tempo. O app tenta de novo mais tarde.']);
        }
        $reg['tipo'] = $tipo;
        $dados = json_encode($reg, JSON_UNESCAPED_UNICODE);
        if (strlen($dados) > 64000) {
            responder(413, ['ok' => false, 'erro' => 'Cadastro grande demais.']);
        }

        $q = db()->prepare('SELECT 1 FROM excluidas WHERE id_app = ?');
        $q->execute([$idApp]);
        if ($q->fetchColumn()) {
            responder(410, ['ok' => false, 'erro' => 'Esta venda foi excluída pelo gestor no painel.']);
        }

        $q = db()->prepare('SELECT * FROM vendas WHERE id_app = ?');
        $q->execute([$idApp]);
        $ja = $q->fetch();
        if ($ja) {
            if ((int)$ja['vendedor_id'] !== (int)$vend['id']) {
                responder(409, ['ok' => false, 'erro' => 'Cadastro de outro vendedor.']);
            }
            // o vendedor editou e salvou de novo: atualiza enquanto ainda não entrou no CRM
            if ($ja['status'] !== 'crm' && !empty($in['atualizar'])) {
                db()->prepare("UPDATE vendas SET dados=?, nome=?, tipo=?, status='fila', erro='', tentativas=0, atualizado_em=datetime('now','localtime') WHERE id=?")
                    ->execute([$dados, up($reg['nome']), $tipo, $ja['id']]);
                $ja['status'] = 'fila';
            }
            $corpo = ['ok' => true, 'id' => 'Recebido #' . $ja['id'], 'repetido' => true] + situacao($ja);
            if ($ja['status'] === 'fila') {
                responder_e_repassar($corpo);
            }
            responder(200, $corpo);
        }

        db()->prepare('INSERT INTO vendas (id_app, vendedor_id, tipo, nome, dados) VALUES (?, ?, ?, ?, ?)')
            ->execute([$idApp, $vend['id'], $tipo, up($reg['nome']), $dados]);
        $id = (int)db()->lastInsertId();
        responder_e_repassar(['ok' => true, 'id' => 'Recebido #' . $id, 'st' => 'srv', 'msg' => 'Recebido. Entrando no ' . nome_destino() . '.']);
    }

    responder(404, ['ok' => false, 'erro' => 'Ação desconhecida.']);
} catch (Throwable $e) {
    error_log('app-vendas: ' . $e->getMessage());
    responder(500, ['ok' => false, 'erro' => 'O servidor não conseguiu gravar agora. O app tenta de novo.']);
}
