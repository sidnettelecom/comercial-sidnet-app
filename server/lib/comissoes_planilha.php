<?php
/**
 * TEMPORÁRIO: comissões que vieram da planilha do Excel (CONTROLE_COMERCIAL.xlsm).
 * Mesma regra do sistema: cada comissão é paga uma vez só; o admin marca o mês em que pagou.
 * Fica até o ALEX quitar todas; depois é só apagar este arquivo e os trechos marcados
 * "PLANILHA-TEMP" no painel (server/public/painel/index.php). A tabela comissoes_planilha pode ficar
 * no banco sem atrapalhar nada (ou: sqlite3 <banco> 'DROP TABLE comissoes_planilha').
 *
 * Os dados dos clientes NÃO ficam no GitHub: o admin importa o CSV pelo próprio painel.
 */
declare(strict_types=1);

require_once __DIR__ . '/comissoes.php'; // dinheiro(), brl(), mes_nome()

const PLANILHA_COLUNAS = ['tipo', 'vendedor', 'ixc_cliente', 'nome', 'valor_plano', 'valor_antigo', 'vencimento', 'condicao', 'mensalidade',
    'instalacao', 'roteador', 'obs', 'valor', 'pago_em'];
const PLANILHA_MENSALIDADE = ['SIM', 'NÃO', 'A VENCER'];

function planilha_tabela(): void
{
    static $feito = false;
    if ($feito) return;
    db()->exec("CREATE TABLE IF NOT EXISTS comissoes_planilha (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tipo TEXT NOT NULL DEFAULT 'venda', vendedor TEXT NOT NULL DEFAULT '', ixc_cliente TEXT NOT NULL DEFAULT '', nome TEXT NOT NULL DEFAULT '',
        valor_plano TEXT NOT NULL DEFAULT '', valor_antigo TEXT NOT NULL DEFAULT '', vencimento TEXT NOT NULL DEFAULT '',
        condicao TEXT NOT NULL DEFAULT '', mensalidade TEXT NOT NULL DEFAULT '', instalacao TEXT NOT NULL DEFAULT '', roteador TEXT NOT NULL DEFAULT '',
        obs TEXT NOT NULL DEFAULT '', valor TEXT NOT NULL DEFAULT '', pago_em TEXT NOT NULL DEFAULT '',
        atualizado_em TEXT, por TEXT NOT NULL DEFAULT ''
    )");
    // a 1ª versão tinha 3 parcelas (p1..p3): passa para um valor só e o mês em que foi paga
    $cols = array_column(db()->query('PRAGMA table_info(comissoes_planilha)')->fetchAll(), 'name');
    if (!in_array('valor', $cols, true)) {
        db()->exec("ALTER TABLE comissoes_planilha ADD COLUMN valor TEXT NOT NULL DEFAULT ''");
        db()->exec("ALTER TABLE comissoes_planilha ADD COLUMN pago_em TEXT NOT NULL DEFAULT ''");
        if (in_array('p1', $cols, true)) {
            db()->exec("UPDATE comissoes_planilha SET valor = CASE WHEN p1 <> '' THEN p1 WHEN p2 <> '' THEN p2 ELSE p3 END,
                pago_em = CASE WHEN p1_paga <> '' THEN p1_paga WHEN p2_paga <> '' THEN p2_paga ELSE p3_paga END");
        }
    }
    $feito = true;
}

function planilha_qtd(): int
{
    planilha_tabela();
    return (int)db()->query('SELECT COUNT(*) FROM comissoes_planilha')->fetchColumn();
}

/** Mês AAAA-MM válido ou ''. */
function planilha_mes(string $v): string
{
    $v = trim($v);
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v) ? $v : '';
}

/**
 * Importa o CSV (separado por ";", com a 1ª linha de cabeçalho) e troca tudo o que havia antes.
 * Devolve quantas linhas entraram.
 */
function planilha_importar(string $arquivo, string $quem): int
{
    planilha_tabela();
    $f = fopen($arquivo, 'r');
    if (!$f) throw new RuntimeException('Não consegui ler o arquivo.');
    $primeira = (string)fgets($f);
    $primeira = preg_replace('/^\xEF\xBB\xBF/', '', $primeira) ?? $primeira; // BOM do Excel
    $sep = substr_count($primeira, ';') >= substr_count($primeira, ',') ? ';' : ',';
    $cab = array_map(fn($c) => strtolower(trim((string)$c)), str_getcsv(trim($primeira), $sep, '"', ''));
    $falta = array_diff(['tipo', 'vendedor', 'nome', 'valor', 'pago_em'], $cab);
    if ($falta) throw new RuntimeException('Esse arquivo não é o CSV da planilha (faltam as colunas: ' . implode(', ', $falta) . ').');
    $linhas = [];
    while (($r = fgetcsv($f, 0, $sep, '"', '')) !== false) {
        if ($r === [null] || count(array_filter($r, fn($c) => trim((string)$c) !== '')) === 0) continue;
        $d = [];
        foreach ($cab as $i => $c) $d[$c] = trim((string)($r[$i] ?? ''));
        if (($d['nome'] ?? '') === '' && ($d['ixc_cliente'] ?? '') === '') continue;
        $linhas[] = $d;
    }
    fclose($f);
    if (!$linhas) throw new RuntimeException('O arquivo não tem nenhuma linha de comissão.');
    if (count($linhas) > 2000) throw new RuntimeException('Linhas demais para a planilha de comissões.');
    $ins = db()->prepare('INSERT INTO comissoes_planilha (' . implode(', ', PLANILHA_COLUNAS) . ", atualizado_em, por)
        VALUES (" . implode(', ', array_fill(0, count(PLANILHA_COLUNAS), '?')) . ", datetime('now','localtime'), ?)");
    db()->beginTransaction();
    try {
        db()->exec('DELETE FROM comissoes_planilha');
        foreach ($linhas as $d) {
            $tipo = ($d['tipo'] ?? '') === 'upgrade' ? 'upgrade' : 'venda';
            $venc = (string)($d['vencimento'] ?? '');
            $mens = up((string)($d['mensalidade'] ?? ''));
            $ins->execute([$tipo, mb_substr(up((string)$d['vendedor']), 0, 80), mb_substr(so_digitos((string)($d['ixc_cliente'] ?? '')), 0, 12),
                mb_substr(up((string)$d['nome']), 0, 120), dinheiro((string)($d['valor_plano'] ?? '')), dinheiro((string)($d['valor_antigo'] ?? '')),
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $venc) ? $venc : '', mb_substr(up((string)($d['condicao'] ?? '')), 0, 20),
                in_array($mens, PLANILHA_MENSALIDADE, true) ? $mens : '', dinheiro((string)($d['instalacao'] ?? '')), dinheiro((string)($d['roteador'] ?? '')),
                mb_substr((string)($d['obs'] ?? ''), 0, 300), dinheiro((string)$d['valor']), planilha_mes((string)$d['pago_em']), $quem]);
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return count($linhas);
}

/** Linhas da planilha (filtro: vendedor e/ou só as que ainda não foram pagas), por vendedor e tipo. */
function planilha_linhas(string $vendedor = '', bool $soAbertas = false): array
{
    planilha_tabela();
    $sql = 'SELECT * FROM comissoes_planilha WHERE 1 = 1' . ($vendedor !== '' ? ' AND vendedor = ?' : '')
        . ($soAbertas ? " AND pago_em = ''" : '') . ' ORDER BY vendedor, tipo DESC, id';
    $q = db()->prepare($sql);
    $q->execute($vendedor !== '' ? [$vendedor] : []);
    return $q->fetchAll();
}

/** Somas: comissão total, já paga, falta pagar, quantas em aberto e quantas sem valor. */
function planilha_totais(array $linhas): array
{
    $t = ['n' => count($linhas), 'total' => 0.0, 'pago' => 0.0, 'falta' => 0.0, 'abertas' => 0, 'semvalor' => 0];
    foreach ($linhas as $l) {
        $v = (float)$l['valor'];
        $t['total'] += $v;
        if ($l['pago_em'] !== '') { $t['pago'] += $v; continue; }
        $t['abertas']++;
        $t['falta'] += $v;
        if ($l['valor'] === '') $t['semvalor']++;
    }
    return $t;
}

/** Quanto foi pago em cada mês, por vendedor: [mes => [vendedor => total]]. */
function planilha_pagas_por_mes(): array
{
    planilha_tabela();
    $out = [];
    foreach (db()->query("SELECT vendedor, valor, pago_em FROM comissoes_planilha WHERE pago_em <> ''") as $r) {
        $out[$r['pago_em']][$r['vendedor']] = ($out[$r['pago_em']][$r['vendedor']] ?? 0) + (float)$r['valor'];
    }
    krsort($out);
    return $out;
}

/**
 * Grava o que o admin mudou. $linhas = [id => [campo => valor]]
 * Só mexe nos campos que vieram no formulário (o PHP corta formulários muito grandes: max_input_vars)
 * e só conta as linhas que mudaram de verdade.
 */
function planilha_salvar(array $linhas, string $quem): int
{
    planilha_tabela();
    $atual = db()->prepare('SELECT mensalidade, valor, pago_em, obs FROM comissoes_planilha WHERE id = ?');
    $up = db()->prepare("UPDATE comissoes_planilha SET mensalidade = ?, valor = ?, pago_em = ?, obs = ?,
        atualizado_em = datetime('now','localtime'), por = ? WHERE id = ?");
    $n = 0;
    foreach ($linhas as $id => $c) {
        if (!is_array($c) || (int)$id <= 0) continue;
        $atual->execute([(int)$id]);
        $velho = $atual->fetch();
        if (!$velho) continue;
        $novo = $velho;
        if (array_key_exists('mensalidade', $c)) $novo['mensalidade'] = in_array((string)$c['mensalidade'], PLANILHA_MENSALIDADE, true) ? (string)$c['mensalidade'] : '';
        if (array_key_exists('valor', $c)) $novo['valor'] = dinheiro((string)$c['valor']);
        if (array_key_exists('pago_em', $c)) $novo['pago_em'] = planilha_mes((string)$c['pago_em']);
        if (array_key_exists('obs', $c)) $novo['obs'] = mb_substr(trim((string)$c['obs']), 0, 300);
        if ($novo === $velho) continue;
        $up->execute([...array_values($novo), $quem, (int)$id]);
        $n++;
    }
    return $n;
}
