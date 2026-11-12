<?php
/**
 * Base do servidor do app Comercial + Sidnet: configuração, banco SQLite e utilidades.
 */
declare(strict_types=1);

const RAIZ = __DIR__ . '/..';

function config(): array
{
    static $c = null;
    if ($c === null) {
        $arq = getenv('COMERCIAL_CONFIG') ?: '/etc/comercial-sidnet/config.php';
        if (!is_file($arq)) {
            $arq = RAIZ . '/config.php';
        }
        if (!is_file($arq)) {
            throw new RuntimeException('Configuração não encontrada. Copie config.exemplo.php para /etc/comercial-sidnet/config.php');
        }
        $c = require $arq;
    }
    return $c;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . config()['banco'], null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        criar_tabelas($pdo);
    }
    return $pdo;
}

function criar_tabelas(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS vendedores (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL,
        crm_vendedor_id TEXT NOT NULL DEFAULT '',
        chave_hash TEXT UNIQUE,
        ativo INTEGER NOT NULL DEFAULT 1,
        criado_em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        ultimo_uso TEXT
    );
    CREATE TABLE IF NOT EXISTS vendas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        id_app TEXT NOT NULL UNIQUE,
        vendedor_id INTEGER NOT NULL REFERENCES vendedores(id),
        tipo TEXT NOT NULL,
        nome TEXT NOT NULL,
        dados TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'fila',
        crm_id TEXT NOT NULL DEFAULT '',
        erro TEXT NOT NULL DEFAULT '',
        tentativas INTEGER NOT NULL DEFAULT 0,
        recebido_em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        atualizado_em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        enviado_crm_em TEXT
    );
    CREATE INDEX IF NOT EXISTS vendas_status ON vendas(status);
    -- sessões do app: cada login no celular gera um token; guardamos só o hash
    CREATE TABLE IF NOT EXISTS sessoes (
        token_hash TEXT PRIMARY KEY,
        vendedor_id INTEGER NOT NULL REFERENCES vendedores(id) ON DELETE CASCADE,
        aparelho TEXT NOT NULL DEFAULT '',
        criado_em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        ultimo_uso TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    -- quem entra no painel
    CREATE TABLE IF NOT EXISTS gestores (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario TEXT NOT NULL UNIQUE,
        nome TEXT NOT NULL,
        senha_hash TEXT NOT NULL,
        ativo INTEGER NOT NULL DEFAULT 1,
        criado_em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        ultimo_acesso TEXT
    );
    -- tentativas de senha erradas, para travar quem fica chutando
    CREATE TABLE IF NOT EXISTS tentativas (
        chave TEXT PRIMARY KEY,
        falhas INTEGER NOT NULL DEFAULT 0,
        primeira TEXT NOT NULL,
        travado_ate TEXT
    );
    -- vendas excluídas pelo gestor: o celular não consegue mandar de novo
    CREATE TABLE IF NOT EXISTS excluidas (
        id_app TEXT PRIMARY KEY,
        nome TEXT NOT NULL,
        por TEXT NOT NULL,
        em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    -- registro do que os gestores fazem no painel
    CREATE TABLE IF NOT EXISTS registro (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        quem TEXT NOT NULL,
        acao TEXT NOT NULL,
        detalhe TEXT NOT NULL DEFAULT ''
    );
    -- comissão de cada venda (fica no mês da venda; pago_em = mês em que foi paga, AAAA-MM)
    CREATE TABLE IF NOT EXISTS comissoes (
        venda_id INTEGER PRIMARY KEY,
        ixc_cliente TEXT NOT NULL DEFAULT '',
        vencimento TEXT NOT NULL DEFAULT '',
        instalacao TEXT NOT NULL DEFAULT '',
        roteador TEXT NOT NULL DEFAULT '',
        valor TEXT NOT NULL DEFAULT '',
        cliente_pagou TEXT NOT NULL DEFAULT '',
        pago_em TEXT NOT NULL DEFAULT '',
        obs TEXT NOT NULL DEFAULT '',
        atualizado_em TEXT,
        por TEXT NOT NULL DEFAULT ''
    );
    -- planos que aparecem no app para todos os vendedores (definidos no painel)
    CREATE TABLE IF NOT EXISTS planos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL UNIQUE,
        valor TEXT NOT NULL DEFAULT '',
        ordem INTEGER NOT NULL DEFAULT 0,
        ativo INTEGER NOT NULL DEFAULT 1
    );
    SQL);
    // envio ao IXC: o que já foi criado lá para cada venda (contrato, login, atendimento)
    $colsV = array_column($pdo->query('PRAGMA table_info(vendas)')->fetchAll(), 'name');
    if (!in_array('ixc_passos', $colsV, true)) {
        $pdo->exec("ALTER TABLE vendas ADD COLUMN ixc_passos TEXT NOT NULL DEFAULT ''");
    }
    $colsD = array_column($pdo->query('PRAGMA table_info(vendedores)')->fetchAll(), 'name');
    if (!in_array('ixc_vendedor_id', $colsD, true)) {
        $pdo->exec("ALTER TABLE vendedores ADD COLUMN ixc_vendedor_id TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('ixc_colaborador_id', $colsD, true)) {
        // colaborador do IXC (funcionarios) que aparece como Responsável no lead, prospect e negociação
        $pdo->exec("ALTER TABLE vendedores ADD COLUMN ixc_colaborador_id TEXT NOT NULL DEFAULT ''");
    }
    // vendedores ganham usuário e senha (antes era só a chave)
    $cols = array_column($pdo->query('PRAGMA table_info(vendedores)')->fetchAll(), 'name');
    if (!in_array('usuario', $cols, true)) {
        $pdo->exec("ALTER TABLE vendedores ADD COLUMN usuario TEXT");
        $pdo->exec("ALTER TABLE vendedores ADD COLUMN senha_hash TEXT NOT NULL DEFAULT ''");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS vendedores_usuario ON vendedores(usuario)");
    }
    // gestor admin: só ele vê as comissões. O primeiro gestor já nasce admin.
    $colsG = array_column($pdo->query('PRAGMA table_info(gestores)')->fetchAll(), 'name');
    if (!in_array('admin', $colsG, true)) {
        $pdo->exec('ALTER TABLE gestores ADD COLUMN admin INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('UPDATE gestores SET admin = 1 WHERE id = (SELECT MIN(id) FROM gestores)');
    }
    // primeiro gestor: "admin" com a senha do painel que já estava na configuração
    if ((int)$pdo->query('SELECT COUNT(*) FROM gestores')->fetchColumn() === 0) {
        $hash = (string)(config()['painel_senha_hash'] ?? '');
        if ($hash !== '') {
            $pdo->prepare("INSERT INTO gestores (usuario, nome, senha_hash, admin) VALUES ('admin', 'ADMINISTRADOR', ?, 1)")->execute([$hash]);
        }
    }
}

/** Para onde as vendas vão: 'ixc' (IXC Provedor, pela API) ou 'crm' (crm.sidnet.com.br, pelas telas). */
/** Planos ativos, na ordem do painel: [['nome' => ..., 'valor' => 109.9|null], ...] */
function planos_ativos(): array
{
    $out = [];
    foreach (db()->query('SELECT nome, valor FROM planos WHERE ativo = 1 ORDER BY ordem, nome') as $p) {
        $out[] = ['nome' => $p['nome'], 'valor' => $p['valor'] !== '' ? (float)$p['valor'] : null];
    }
    return $out;
}

function destino(): string
{
    return (config()['destino'] ?? 'crm') === 'ixc' ? 'ixc' : 'crm';
}

function nome_destino(): string
{
    return destino() === 'ixc' ? 'IXC' : 'CRM';
}

/** IP de quem acessa. Atrás do Cloudflare Tunnel o nginx vê 127.0.0.1; o IP real vem no cabeçalho do Cloudflare. */
function ip_cliente(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (in_array($ip, ['127.0.0.1', '::1'], true) && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    return substr($ip, 0, 64);
}

/** true se a conexão do usuário é https (direto ou pelo Cloudflare) */
function conexao_https(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || str_contains((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https');
}

/**
 * Trava contra quem fica chutando senha: 5 erros em 15 minutos travam por 15 minutos,
 * por usuário e por IP (20 erros no mesmo IP).
 * Devolve quantos minutos faltam se estiver travado, ou 0.
 */
function travado(string ...$chaves): int
{
    $q = db()->prepare("SELECT travado_ate FROM tentativas WHERE chave = ? AND travado_ate > datetime('now','localtime')");
    foreach ($chaves as $c) {
        $q->execute([$c]);
        if ($ate = $q->fetchColumn()) {
            return max(1, (int)ceil((strtotime((string)$ate) - time()) / 60));
        }
    }
    return 0;
}

function anotar_falha(string $chave, int $limite): void
{
    $pdo = db();
    $pdo->prepare("DELETE FROM tentativas WHERE chave = ? AND primeira < datetime('now','localtime','-15 minutes') AND (travado_ate IS NULL OR travado_ate < datetime('now','localtime'))")->execute([$chave]);
    $pdo->prepare("INSERT INTO tentativas (chave, falhas, primeira) VALUES (?, 1, datetime('now','localtime')) ON CONFLICT(chave) DO UPDATE SET falhas = falhas + 1")->execute([$chave]);
    $pdo->prepare("UPDATE tentativas SET travado_ate = datetime('now','localtime','+15 minutes') WHERE chave = ? AND falhas >= ?")->execute([$chave, $limite]);
}

function limpar_falhas(string $chave): void
{
    db()->prepare('DELETE FROM tentativas WHERE chave = ?')->execute([$chave]);
}

function registrar(string $quem, string $acao, string $detalhe = ''): void
{
    db()->prepare('INSERT INTO registro (quem, acao, detalhe) VALUES (?, ?, ?)')->execute([$quem, $acao, $detalhe]);
}

/** Senha boa o bastante: 8+ caracteres, com letra e número. Devolve o problema ou ''. */
function problema_senha(string $s): string
{
    if (mb_strlen($s) < 8) return 'A senha precisa ter pelo menos 8 caracteres.';
    if (!preg_match('/[A-Za-z]/', $s) || !preg_match('/\d/', $s)) return 'A senha precisa ter letras e números.';
    return '';
}

/** Usuário: letras minúsculas, números, ponto, hífen ou sublinhado. */
function usuario_valido(string $u): string
{
    $u = strtolower(trim($u));
    return preg_match('/^[a-z0-9._-]{3,40}$/', $u) ? $u : '';
}

/** Texto em maiúsculas, sem espaços duplicados (o mesmo padrão do app). */
function up(?string $s): string
{
    return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string)$s)), 'UTF-8');
}

function so_digitos(?string $s): string
{
    return preg_replace('/\D/', '', (string)$s);
}

/** "10/11/2026 14:30" ou "10/11/2026" -> "2026-11-10" */
function data_iso(?string $dmy): string
{
    return preg_match('#^(\d{2})/(\d{2})/(\d{4})#', (string)$dmy, $m) ? "$m[3]-$m[2]-$m[1]" : '';
}

/** "1.234,56" -> "1234.56" (valor como o CRM recebe em campos numéricos) */
function valor_ponto(?string $v): string
{
    $d = so_digitos($v);
    if ($d === '') {
        return '';
    }
    return number_format(((int)$d) / 100, 2, '.', '');
}

function valor_brl(?string $v): string
{
    $d = so_digitos($v);
    return $d === '' ? '0,00' : number_format(((int)$d) / 100, 2, ',', '.');
}

/**
 * Texto que vai para o CRM: tira caracteres de controle e os sinais < > " ` que permitiriam
 * colocar HTML ou script dentro do CRM pelos campos do app. Limita o tamanho.
 */
function texto_crm(string $s, int $max = 1000): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    $s = str_replace(['<', '>', '"', '`'], '', $s);
    return mb_substr($s, 0, $max);
}

/** Cadastro que veio do celular: só chaves simples e valores de texto, cada um com tamanho limitado. */
function registro_limpo(array $in): array
{
    $out = [];
    foreach ($in as $k => $v) {
        if (!is_string($k) || !preg_match('/^[A-Za-z0-9_]{1,40}$/', $k) || count($out) >= 120) {
            continue;
        }
        if (is_bool($v)) {
            $out[$k] = $v ? '1' : '';
        } elseif (is_int($v) || is_float($v) || is_string($v)) {
            $out[$k] = mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? '', 0, 2000);
        }
    }
    return $out;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
