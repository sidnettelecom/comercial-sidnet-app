<?php
/**
 * TEMPORÁRIO: comissões que vieram da planilha do Excel (CONTROLE_COMERCIAL.xlsm), pagas em 3 parcelas.
 * Fica até o ALEX quitar todas; depois é só apagar este arquivo e os trechos marcados
 * "PLANILHA-TEMP" no painel (server/public/painel/index.php). A tabela comissoes_planilha pode ficar
 * no banco sem atrapalhar nada (ou: sqlite3 <banco> 'DROP TABLE comissoes_planilha').
 *
 * Os dados dos clientes NÃO ficam no GitHub: o admin importa o CSV pelo próprio painel.
 */
declare(strict_types=1);

require_once __DIR__ . '/comissoes.php'; // dinheiro(), brl(), mes_nome()

const PLANILHA_COLUNAS = ['tipo', 'vendedor', 'ixc_cliente', 'nome', 'valor_plano', 'valor_antigo', 'vencimento', 'condicao', 'mensalidade',
    'instalacao', 'roteador', 'obs', 'p1_mes', 'p1', 'p1_paga', 'p2_mes', 'p2', 'p2_paga', 'p3_mes', 'p3', 'p3_paga'];
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
        obs TEXT NOT NULL DEFAULT '',
        p1_mes TEXT NOT NULL DEFAULT '', p1 TEXT NOT NULL DEFAULT '', p1_paga TEXT NOT NULL DEFAULT '',
        p2_mes TEXT NOT NULL DEFAULT '', p2 TEXT NOT NULL DEFAULT '', p2_paga TEXT NOT NULL DEFAULT '',
        p3_mes TEXT NOT NULL DEFAULT '', p3 TEXT NOT NULL DEFAULT '', p3_paga TEXT NOT NULL DEFAULT '',
        atualizado_em TEXT, por TEXT NOT NULL DEFAULT ''
    )");
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
    $falta = array_diff(['tipo', 'vendedor', 'nome', 'p1', 'p1_paga'], $cab);
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
            $vals = [$tipo, mb_substr(up((string)$d['vendedor']), 0, 80), mb_substr(so_digitos((string)($d['ixc_cliente'] ?? '')), 0, 12),
                mb_substr(up((string)$d['nome']), 0, 120), dinheiro((string)($d['valor_plano'] ?? '')), dinheiro((string)($d['valor_antigo'] ?? '')),
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $venc) ? $venc : '', mb_substr(up((string)($d['condicao'] ?? '')), 0, 20),
                in_array($mens, PLANILHA_MENSALIDADE, true) ? $mens : '', dinheiro((string)($d['instalacao'] ?? '')), dinheiro((string)($d['roteador'] ?? '')),
                mb_substr((string)($d['obs'] ?? ''), 0, 300)];
            foreach ([1, 2, 3] as $n) {
                $vals[] = planilha_mes((string)($d["p{$n}_mes"] ?? ''));
                $vals[] = dinheiro((string)($d["p$n"] ?? ''));
                $vals[] = planilha_mes((string)($d["p{$n}_paga"] ?? ''));
            }
            $vals[] = $quem;
            $ins->execute($vals);
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return count($linhas);
}

/** Linhas da planilha (filtro: vendedor e/ou só as que ainda têm parcela em aberto), por vendedor e tipo. */
function planilha_linhas(string $vendedor = '', bool $soAbertas = false): array
{
    planilha_tabela();
    $sql = 'SELECT * FROM comissoes_planilha' . ($vendedor !== '' ? ' WHERE vendedor = ?' : '') . ' ORDER BY vendedor, tipo DESC, id';
    $q = db()->prepare($sql);
    $q->execute($vendedor !== '' ? [$vendedor] : []);
    $out = [];
    foreach ($q as $r) {
        $t = planilha_totais([$r]);
        if ($soAbertas && $t['abertas'] === 0 && $t['semvalor'] === 0) continue;
        $out[] = $r;
    }
    return $out;
}

/** Somas: total das 3 parcelas, já pago, falta pagar, parcelas em aberto e linhas sem valor. */
function planilha_totais(array $linhas): array
{
    $t = ['n' => count($linhas), 'total' => 0.0, 'pago' => 0.0, 'falta' => 0.0, 'abertas' => 0, 'semvalor' => 0];
    foreach ($linhas as $l) {
        if ($l['p1'] === '' && $l['p2'] === '' && $l['p3'] === '') $t['semvalor']++;
        foreach ([1, 2, 3] as $n) {
            $v = (float)$l["p$n"];
            $t['total'] += $v;
            if ($l["p{$n}_paga"] !== '') $t['pago'] += $v;
            elseif ($v > 0) { $t['falta'] += $v; $t['abertas']++; }
        }
    }
    return $t;
}

/** Quanto foi pago em cada mês (pelas parcelas marcadas), por vendedor: [mes => [vendedor => total]]. */
function planilha_pagas_por_mes(): array
{
    planilha_tabela();
    $out = [];
    foreach (db()->query('SELECT vendedor, p1, p1_paga, p2, p2_paga, p3, p3_paga FROM comissoes_planilha') as $r) {
        foreach ([1, 2, 3] as $n) {
            if ($r["p{$n}_paga"] === '') continue;
            $out[$r["p{$n}_paga"]][$r['vendedor']] = ($out[$r["p{$n}_paga"]][$r['vendedor']] ?? 0) + (float)$r["p$n"];
        }
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
    $atual = db()->prepare('SELECT mensalidade, p1, p1_paga, p2, p2_paga, p3, p3_paga, obs FROM comissoes_planilha WHERE id = ?');
    $n = 0;
    foreach ($linhas as $id => $c) {
        if (!is_array($c) || (int)$id <= 0) continue;
        $atual->execute([(int)$id]);
        $velho = $atual->fetch();
        if (!$velho) continue;
        $novo = $velho;
        if (array_key_exists('mensalidade', $c)) $novo['mensalidade'] = in_array((string)$c['mensalidade'], PLANILHA_MENSALIDADE, true) ? (string)$c['mensalidade'] : '';
        foreach ([1, 2, 3] as $p) {
            if (array_key_exists("p$p", $c)) $novo["p$p"] = dinheiro((string)$c["p$p"]);
            if (array_key_exists("p{$p}_paga", $c)) $novo["p{$p}_paga"] = planilha_mes((string)$c["p{$p}_paga"]);
        }
        if (array_key_exists('obs', $c)) $novo['obs'] = mb_substr(trim((string)$c['obs']), 0, 300);
        if ($novo === $velho) continue;
        db()->prepare("UPDATE comissoes_planilha SET mensalidade = ?, p1 = ?, p1_paga = ?, p2 = ?, p2_paga = ?, p3 = ?, p3_paga = ?, obs = ?,
            atualizado_em = datetime('now','localtime'), por = ? WHERE id = ?")->execute([...array_values($novo), $quem, (int)$id]);
        $n++;
    }
    return $n;
}
