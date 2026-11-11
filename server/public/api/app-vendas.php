<?php
/**
 * API que o app "Comercial + Sidnet" chama.
 *
 *   POST /api/app-vendas.php?acao=enviar   (Authorization: Bearer <chave do vendedor>)
 *        {id_app, tipo: venda|segundo|upgrade, registro:{...campos do app...}}
 *        -> {ok:true, id:"Recebido #12"}  (o repasse ao CRM acontece logo depois, pelo cron)
 *   GET  /api/app-vendas.php?acao=status&ids=a,b,c   -> situação no CRM de cada cadastro
 *   GET  /api/app-vendas.php?acao=eu                  -> confere a chave, devolve o nome do vendedor
 *
 * Reenviar o mesmo id_app não duplica: devolve o que já foi recebido.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/repasse.php';

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

function vendedor_da_chave(): array
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+([A-Za-z0-9]{20,64})$/', $h, $m)) {
        responder(401, ['ok' => false, 'erro' => 'Coloque sua chave de acesso em Ajustes.']);
    }
    $q = db()->prepare('SELECT * FROM vendedores WHERE chave_hash = ?');
    $q->execute([hash('sha256', $m[1])]);
    $v = $q->fetch();
    if (!$v) {
        responder(401, ['ok' => false, 'erro' => 'Chave de acesso inválida. Peça uma nova ao supervisor.']);
    }
    if (!$v['ativo']) {
        responder(403, ['ok' => false, 'erro' => 'Seu acesso está bloqueado. Fale com o supervisor.']);
    }
    db()->prepare("UPDATE vendedores SET ultimo_uso = datetime('now','localtime') WHERE id = ?")->execute([$v['id']]);
    return $v;
}

function situacao(array $v): array
{
    return match ($v['status']) {
        'crm' => ['st' => 'ok', 'crmId' => $v['crm_id']],
        'erro' => ['st' => 'er', 'msg' => $v['erro']],
        default => ['st' => 'srv', 'msg' => 'Recebido. Entrando no CRM.'],
    };
}

try {
    $acao = $_GET['acao'] ?? '';
    $vend = vendedor_da_chave();

    if ($acao === 'eu') {
        responder(200, ['ok' => true, 'nome' => $vend['nome']]);
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
        $reg = is_array($in['registro'] ?? null) ? $in['registro'] : null;
        if ($idApp === '' || strlen($idApp) > 40 || !in_array($tipo, ['venda', 'segundo', 'upgrade'], true) || !$reg || up($reg['nome'] ?? '') === '') {
            responder(422, ['ok' => false, 'erro' => 'Cadastro incompleto.']);
        }
        unset($reg['midias']); // fotos e vídeos ainda vão pelo WhatsApp
        $reg['tipo'] = $tipo;
        $dados = json_encode($reg, JSON_UNESCAPED_UNICODE);
        if (strlen($dados) > 64000) {
            responder(413, ['ok' => false, 'erro' => 'Cadastro grande demais.']);
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
        responder_e_repassar(['ok' => true, 'id' => 'Recebido #' . $id, 'st' => 'srv', 'msg' => 'Recebido. Entrando no CRM.']);
    }

    responder(404, ['ok' => false, 'erro' => 'Ação desconhecida.']);
} catch (Throwable $e) {
    error_log('app-vendas: ' . $e->getMessage());
    responder(500, ['ok' => false, 'erro' => 'O servidor não conseguiu gravar agora. O app tenta de novo.']);
}
