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
    SQL);
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

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
