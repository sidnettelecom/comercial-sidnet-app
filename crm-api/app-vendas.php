<?php
/**
 * API do app "Comercial + Sidnet" — RASCUNHO.
 *
 * Recebe os cadastros que o vendedor fez no celular (mesmo offline) e grava no CRM.
 * Vai em /api/app-vendas.php do CRM.
 *
 * O que falta: ligar salvar_lead() e salvar_upgrade() ao mesmo código que
 * novo-lead.php e servicos-vendedor.php (acao=manual, tipo=upgrade) já usam
 * para gravar. Isso depende do código do CRM, que ainda não vimos.
 *
 * Rotas (POST, JSON):
 *   ?acao=entrar   {login, senha}            -> {ok, chave, vendedor_id, nome}
 *   ?acao=enviar   {id_app, tipo, empresa_id, campos{...}}   (Authorization: Bearer <chave>)
 *                  -> {ok, id:"Lead #123"} ou {ok:false, erro:"..."}
 *   ?acao=planos   -> {ok, planos:[{nome, valor}]}   (catálogo para o app)
 *
 * Regras:
 *  - a chave é por vendedor, guardada só como hash; bloquear o vendedor no CRM bloqueia a chave
 *  - id_app é único: se o app reenviar o mesmo cadastro, devolve o mesmo id (não duplica)
 *  - tipo: venda | segundo (vão para o Novo Lead) | upgrade (vai para Upgrades → lançar à mão)
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
// o app roda num WebView (origem file:// ou https://appassets...), então liberamos CORS só para esta rota
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { responder(405, ['ok' => false, 'erro' => 'Use POST']); }

require_once __DIR__ . '/../config.php'; // TODO: o arquivo de conexão que o CRM já usa ($pdo)

/*
 * Tabelas novas (rodar uma vez):
 *
 * CREATE TABLE app_chaves (
 *   id INT AUTO_INCREMENT PRIMARY KEY,
 *   usuario_id INT NOT NULL,
 *   chave_hash CHAR(64) NOT NULL UNIQUE,
 *   criada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   ultimo_uso DATETIME NULL,
 *   revogada TINYINT(1) NOT NULL DEFAULT 0
 * );
 * CREATE TABLE app_envios (
 *   id_app VARCHAR(40) PRIMARY KEY,
 *   usuario_id INT NOT NULL,
 *   tipo VARCHAR(10) NOT NULL,
 *   destino_id VARCHAR(40) NOT NULL,
 *   recebido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 */

$entrada = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$acao = $_GET['acao'] ?? '';

if ($acao === 'entrar') {
    $login = trim((string)($entrada['login'] ?? ''));
    $senha = (string)($entrada['senha'] ?? '');
    $u = buscar_usuario($pdo, $login); // TODO: mesma consulta do login.php
    if (!$u || !$u['ativo'] || !password_verify($senha, $u['senha_hash'])) {
        responder(401, ['ok' => false, 'erro' => 'Usuário ou senha incorretos.']);
    }
    $chave = bin2hex(random_bytes(24));
    $pdo->prepare('INSERT INTO app_chaves (usuario_id, chave_hash) VALUES (?, ?)')
        ->execute([$u['id'], hash('sha256', $chave)]);
    responder(200, ['ok' => true, 'chave' => $chave, 'vendedor_id' => $u['vendedor_id'], 'nome' => $u['nome']]);
}

$u = usuario_da_chave($pdo);

if ($acao === 'planos') {
    responder(200, ['ok' => true, 'planos' => listar_planos($pdo, (int)($_GET['empresa_id'] ?? 3))]); // TODO
}

if ($acao === 'enviar') {
    $idApp = preg_replace('/[^a-z0-9]/i', '', (string)($entrada['id_app'] ?? ''));
    $tipo = (string)($entrada['tipo'] ?? '');
    $c = is_array($entrada['campos'] ?? null) ? $entrada['campos'] : [];
    if ($idApp === '' || !in_array($tipo, ['venda', 'segundo', 'upgrade'], true)) {
        responder(422, ['ok' => false, 'erro' => 'Cadastro incompleto.']);
    }

    // já recebido? devolve o mesmo id, sem duplicar
    $q = $pdo->prepare('SELECT destino_id FROM app_envios WHERE id_app = ?');
    $q->execute([$idApp]);
    if ($ja = $q->fetchColumn()) { responder(200, ['ok' => true, 'id' => $ja, 'repetido' => true]); }

    $c['vendedor_id'] = $u['vendedor_id']; // o vendedor é quem está logado, não o que o app mandou
    $c['empresa_id'] = (int)($entrada['empresa_id'] ?? 3);

    try {
        $pdo->beginTransaction();
        $id = $tipo === 'upgrade' ? 'Upgrade #' . salvar_upgrade($pdo, $c) : 'Lead #' . salvar_lead($pdo, $c, $tipo);
        $pdo->prepare('INSERT INTO app_envios (id_app, usuario_id, tipo, destino_id) VALUES (?, ?, ?, ?)')
            ->execute([$idApp, $u['id'], $tipo, $id]);
        $pdo->commit();
    } catch (ErroValidacao $e) {
        $pdo->rollBack();
        responder(422, ['ok' => false, 'erro' => $e->getMessage()]); // ex.: "CPF já cadastrado neste provedor"
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('app-vendas: ' . $e->getMessage());
        responder(500, ['ok' => false, 'erro' => 'O CRM não conseguiu gravar agora. O app tenta de novo.']);
    }
    responder(200, ['ok' => true, 'id' => $id]);
}

responder(404, ['ok' => false, 'erro' => 'Ação desconhecida.']);

/* ---------------- funções ---------------- */
final class ErroValidacao extends RuntimeException {}

function responder(int $status, array $corpo): never {
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

function usuario_da_chave(PDO $pdo): array {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+([a-f0-9]{48})$/i', $h, $m)) {
        responder(401, ['ok' => false, 'erro' => 'Entre de novo no app (Ajustes).']);
    }
    $q = $pdo->prepare('SELECT k.id AS chave_id, k.usuario_id AS id FROM app_chaves k WHERE k.chave_hash = ? AND k.revogada = 0');
    $q->execute([hash('sha256', $m[1])]);
    $k = $q->fetch(PDO::FETCH_ASSOC);
    if (!$k) { responder(401, ['ok' => false, 'erro' => 'Chave inválida ou bloqueada. Entre de novo no app.']); }
    $pdo->prepare('UPDATE app_chaves SET ultimo_uso = NOW() WHERE id = ?')->execute([$k['chave_id']]);
    $u = buscar_usuario_por_id($pdo, (int)$k['id']); // TODO
    if (!$u || !$u['ativo']) { responder(403, ['ok' => false, 'erro' => 'Usuário inativo no CRM.']); }
    return $u;
}

/** TODO: mesma gravação do novo-lead.php (validação, checagem de duplicado, integração com o outro sistema). */
function salvar_lead(PDO $pdo, array $c, string $tipo): int { throw new LogicException('ligar ao código do novo-lead.php'); }

/** TODO: mesma gravação do servicos-vendedor.php (acao=manual, tipo=upgrade). */
function salvar_upgrade(PDO $pdo, array $c): int { throw new LogicException('ligar ao código do servicos-vendedor.php'); }

function buscar_usuario(PDO $pdo, string $login): ?array { throw new LogicException('TODO'); }
function buscar_usuario_por_id(PDO $pdo, int $id): ?array { throw new LogicException('TODO'); }
function listar_planos(PDO $pdo, int $empresaId): array { throw new LogicException('TODO'); }
