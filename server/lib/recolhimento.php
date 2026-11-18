<?php
/**
 * Recolhimento de equipamentos: o financeiro lança as O.S de recolhimento que já fez no IXC
 * (só para registro, o sistema não abre nem fecha O.S e não grava nada no IXC) e o admin
 * confere e paga a comissão de quem lançou (financeiro) e de quem recolheu (técnico de campo).
 * Regra do ALEX: comissão paga uma vez só; quando paga, marca-se o mês do pagamento.
 * O mês de cada O.S é o mês da data de fechamento.
 */
declare(strict_types=1);

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/comissoes.php'; // dinheiro(), brl(), mes_nome()

function rec_tabela(): void
{
    db()->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS rec_tecnicos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL UNIQUE,
        ativo INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS recolhimentos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cliente TEXT NOT NULL,
        contrato TEXT NOT NULL,
        os TEXT NOT NULL DEFAULT '',
        macs TEXT NOT NULL,
        qtd INTEGER NOT NULL,
        abertura TEXT NOT NULL DEFAULT '',
        fechamento TEXT NOT NULL,
        funcionando TEXT NOT NULL DEFAULT 'SIM',
        tecnico_id INTEGER NOT NULL REFERENCES rec_tecnicos(id),
        obs TEXT NOT NULL DEFAULT '',
        lancado_por TEXT NOT NULL,
        lancado_em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        valor_fin TEXT NOT NULL DEFAULT '',
        valor_tec TEXT NOT NULL DEFAULT '',
        conferido INTEGER NOT NULL DEFAULT 0,
        pago_em TEXT NOT NULL DEFAULT '',
        alterado_por TEXT NOT NULL DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS rec_fech ON recolhimentos (fechamento);
    CREATE TABLE IF NOT EXISTS rec_regra (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        valor_fin TEXT NOT NULL DEFAULT '',
        valor_tec TEXT NOT NULL DEFAULT '',
        base TEXT NOT NULL DEFAULT 'eq',
        so_funcionando INTEGER NOT NULL DEFAULT 0
    );
    INSERT OR IGNORE INTO rec_regra (id) VALUES (1);
    SQL);
}

/** "CCB1822D48C7 / A8:29:48:A0:DB:D3" -> ['CCB1822D48C7', 'A82948A0DBD3'] (sem repetir) */
function rec_macs(string $s): array
{
    $out = [];
    foreach (preg_split('/[\s\/,;|]+/', up($s)) ?: [] as $m) {
        $m = preg_replace('/[^A-Z0-9]/', '', $m) ?? '';
        if ($m !== '' && !in_array($m, $out, true)) $out[] = $m;
    }
    return $out;
}

function rec_data(string $s): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && checkdate((int)substr($s, 5, 2), (int)substr($s, 8, 2), (int)substr($s, 0, 4)) ? $s : '';
}

/** "2026-03-25" -> "25/03/2026" ('' se vazia) */
function rec_dt(string $s): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) ? "$m[3]/$m[2]/$m[1]" : '';
}

function rec_tecnicos(bool $soAtivos = false): array
{
    return db()->query('SELECT id, nome, ativo FROM rec_tecnicos' . ($soAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY nome')->fetchAll();
}

function rec_regra(): array
{
    return db()->query('SELECT * FROM rec_regra WHERE id = 1')->fetch() ?: ['valor_fin' => '', 'valor_tec' => '', 'base' => 'eq', 'so_funcionando' => 0];
}

/** Valores sugeridos pela regra padrão para uma O.S: [financeiro, técnico] em "0.00". */
function rec_sugestao(array $r, array $regra): array
{
    $porEq = ($regra['base'] ?? 'eq') === 'eq';
    $qtd = max(1, (int)$r['qtd']);
    $semFunc = !empty($regra['so_funcionando']) && $r['funcionando'] === 'NÃO';
    $fin = (float)($regra['valor_fin'] ?: 0) * ($porEq ? $qtd : 1);
    $tec = $semFunc ? 0.0 : (float)($regra['valor_tec'] ?: 0) * ($porEq ? $qtd : 1);
    return [number_format($fin, 2, '.', ''), number_format($tec, 2, '.', '')];
}

/** Lê e confere o formulário de uma O.S. Lança RuntimeException com o motivo se faltar algo. */
function rec_validar(array $in): array
{
    $macs = rec_macs((string)($in['macs'] ?? ''));
    $d = [
        'cliente' => up(preg_replace('/\s+/', ' ', trim((string)($in['cliente'] ?? ''))) ?? ''),
        'contrato' => so_digitos((string)($in['contrato'] ?? '')),
        'os' => so_digitos((string)($in['os'] ?? '')),
        'macs' => implode("\n", $macs),
        'qtd' => count($macs),
        'abertura' => rec_data((string)($in['abertura'] ?? '')),
        'fechamento' => rec_data((string)($in['fechamento'] ?? '')),
        'funcionando' => ($in['funcionando'] ?? 'SIM') === 'NÃO' ? 'NÃO' : 'SIM',
        'tecnico_id' => (int)($in['tecnico_id'] ?? 0),
        'obs' => mb_substr(trim((string)($in['obs'] ?? '')), 0, 300),
    ];
    if ($d['cliente'] === '') throw new RuntimeException('Preencha o nome do cliente.');
    if ($d['contrato'] === '') throw new RuntimeException('Preencha o ID do contrato.');
    if (!$macs) throw new RuntimeException('Preencha o MAC de pelo menos um equipamento.');
    if ($d['fechamento'] === '') throw new RuntimeException('Preencha a data de fechamento da O.S.');
    if ($d['abertura'] !== '' && $d['abertura'] > $d['fechamento']) throw new RuntimeException('A abertura da O.S está depois do fechamento.');
    if ($d['fechamento'] > date('Y-m-d')) throw new RuntimeException('A data de fechamento está no futuro.');
    $q = db()->prepare('SELECT 1 FROM rec_tecnicos WHERE id = ?');
    $q->execute([$d['tecnico_id']]);
    if (!$q->fetchColumn()) throw new RuntimeException('Escolha o técnico que recolheu.');
    return $d;
}

/**
 * Grava uma O.S nova ($id = 0) ou corrige uma existente que ainda não foi paga.
 * Devolve [id, aviso] (aviso quando algum MAC já estava em outra O.S).
 */
function rec_salvar(array $in, int $id, string $quem): array
{
    $d = rec_validar($in);
    if ($id) {
        $q = db()->prepare('SELECT pago_em FROM recolhimentos WHERE id = ?');
        $q->execute([$id]);
        $pg = $q->fetchColumn();
        if ($pg === false) throw new RuntimeException('Essa O.S não existe mais.');
        if ($pg !== '') throw new RuntimeException('Essa O.S já teve a comissão paga e não pode mais ser mudada.');
    }
    if ($d['os'] !== '') {
        $q = db()->prepare('SELECT id FROM recolhimentos WHERE os = ? AND id <> ?');
        $q->execute([$d['os'], $id]);
        if ($q->fetchColumn()) throw new RuntimeException("A O.S nº {$d['os']} já foi lançada.");
    }
    $repetidos = [];
    $q = db()->prepare("SELECT cliente FROM recolhimentos WHERE id <> ? AND ('\n' || macs || '\n') LIKE ?");
    foreach (explode("\n", $d['macs']) as $m) {
        $q->execute([$id, "%\n$m\n%"]);
        if ($c = $q->fetchColumn()) $repetidos[] = "$m (já em $c)";
    }
    if ($id) {
        // mudou o que muda o valor: volta a ser conferida
        db()->prepare('UPDATE recolhimentos SET cliente=?, contrato=?, os=?, macs=?, qtd=?, abertura=?, fechamento=?, funcionando=?, tecnico_id=?, obs=?, alterado_por=?,
            conferido = CASE WHEN qtd = ? AND funcionando = ? AND tecnico_id = ? THEN conferido ELSE 0 END WHERE id=?')
            ->execute([$d['cliente'], $d['contrato'], $d['os'], $d['macs'], $d['qtd'], $d['abertura'], $d['fechamento'], $d['funcionando'], $d['tecnico_id'], $d['obs'], $quem,
                $d['qtd'], $d['funcionando'], $d['tecnico_id'], $id]);
    } else {
        db()->prepare('INSERT INTO recolhimentos (cliente, contrato, os, macs, qtd, abertura, fechamento, funcionando, tecnico_id, obs, lancado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$d['cliente'], $d['contrato'], $d['os'], $d['macs'], $d['qtd'], $d['abertura'], $d['fechamento'], $d['funcionando'], $d['tecnico_id'], $d['obs'], $quem]);
        $id = (int)db()->lastInsertId();
    }
    return [$id, $repetidos ? 'Atenção: MAC já lançado em outra O.S: ' . implode(', ', $repetidos) . '.' : ''];
}

function rec_excluir(int $id): void
{
    $q = db()->prepare('SELECT pago_em FROM recolhimentos WHERE id = ?');
    $q->execute([$id]);
    $pg = $q->fetchColumn();
    if ($pg === false) return;
    if ($pg !== '') throw new RuntimeException('Essa O.S já teve a comissão paga e não pode ser excluída.');
    db()->prepare('DELETE FROM recolhimentos WHERE id = ?')->execute([$id]);
}

function rec_um(int $id): ?array
{
    $q = db()->prepare('SELECT * FROM recolhimentos WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

/** O.S fechadas no mês (aaaa-mm), com o nome do técnico e de quem lançou. */
function rec_do_mes(string $mes, string $busca = ''): array
{
    $sql = "SELECT r.*, t.nome AS tecnico, COALESCE(g.nome, r.lancado_por) AS lancado_nome
        FROM recolhimentos r JOIN rec_tecnicos t ON t.id = r.tecnico_id LEFT JOIN gestores g ON g.usuario = r.lancado_por
        WHERE substr(r.fechamento, 1, 7) = ?";
    $par = [$mes];
    $b = up(trim($busca));
    if ($b !== '') {
        $sql .= " AND (r.cliente LIKE ? OR r.contrato = ? OR r.os = ? OR r.macs LIKE ?)";
        $mac = preg_replace('/[^A-Z0-9]/', '', $b);
        array_push($par, "%$b%", so_digitos($b) ?: '-', so_digitos($b) ?: '-', '%' . ($mac ?: '-') . '%');
    }
    $q = db()->prepare($sql . ' ORDER BY r.fechamento, r.cliente, r.id');
    $q->execute($par);
    return $q->fetchAll();
}

/** Meses que têm O.S, mais o atual, do mais novo para o mais velho. */
function rec_meses(): array
{
    $m = db()->query("SELECT DISTINCT substr(fechamento, 1, 7) FROM recolhimentos ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array(date('Y-m'), $m, true)) array_unshift($m, date('Y-m'));
    rsort($m);
    return $m;
}

/**
 * Admin: grava valores e conferência das linhas ainda não pagas.
 * $linhas = [id => ['vf' => '5,00', 'vt' => '10,00', 'ok' => '1']]
 */
function rec_comissao_salvar(array $linhas, string $quem): int
{
    $up = db()->prepare("UPDATE recolhimentos SET valor_fin = ?, valor_tec = ?, conferido = ?, alterado_por = ? WHERE id = ? AND pago_em = ''
        AND (valor_fin <> ? OR valor_tec <> ? OR conferido <> ?)");
    $n = 0;
    foreach ($linhas as $id => $l) {
        $vf = dinheiro((string)($l['vf'] ?? ''));
        $vt = dinheiro((string)($l['vt'] ?? ''));
        $ok = !empty($l['ok']) ? 1 : 0;
        $up->execute([$vf, $vt, $ok, $quem, (int)$id, $vf, $vt, $ok]);
        $n += $up->rowCount();
    }
    return $n;
}

/** Admin: preenche com a regra padrão os valores vazios das linhas não conferidas e não pagas do mês. */
function rec_aplicar_regra(string $mes, bool $sobrescrever): int
{
    $regra = rec_regra();
    $up = db()->prepare("UPDATE recolhimentos SET valor_fin = ?, valor_tec = ? WHERE id = ?");
    $n = 0;
    foreach (rec_do_mes($mes) as $r) {
        if ($r['pago_em'] !== '' || $r['conferido']) continue;
        if (!$sobrescrever && $r['valor_fin'] !== '' && $r['valor_tec'] !== '') continue;
        [$vf, $vt] = rec_sugestao($r, $regra);
        $up->execute([$sobrescrever || $r['valor_fin'] === '' ? $vf : $r['valor_fin'], $sobrescrever || $r['valor_tec'] === '' ? $vt : $r['valor_tec'], $r['id']]);
        $n++;
    }
    return $n;
}

/** Admin: marca como pagas, no mês $pagoEm, as O.S conferidas do mês $mes que ainda não foram pagas. */
function rec_pagar(string $mes, string $pagoEm, string $quem): int
{
    if (!preg_match('/^\d{4}-\d{2}$/', $pagoEm)) throw new RuntimeException('Escolha o mês do pagamento.');
    $q = db()->prepare("UPDATE recolhimentos SET pago_em = ?, alterado_por = ? WHERE substr(fechamento, 1, 7) = ? AND conferido = 1 AND pago_em = ''");
    $q->execute([$pagoEm, $quem, $mes]);
    return $q->rowCount();
}

/** Total por pessoa: [nome => ['papel' => ..., 'os' => n, 'eq' => n, 'valor' => float, 'falta' => float]] */
function rec_por_pessoa(array $linhas): array
{
    $out = [];
    foreach ($linhas as $r) {
        foreach ([['Financeiro', $r['lancado_nome'], 'valor_fin'], ['Técnico de campo', $r['tecnico'], 'valor_tec']] as [$papel, $nome, $col]) {
            $k = $papel . '|' . $nome;
            $o = &$out[$k];
            $o ??= ['papel' => $papel, 'nome' => $nome, 'os' => 0, 'eq' => 0, 'valor' => 0.0, 'falta' => 0.0];
            $o['os']++;
            $o['eq'] += (int)$r['qtd'];
            $o['valor'] += (float)($r[$col] ?: 0);
            if ($r['pago_em'] === '') $o['falta'] += (float)($r[$col] ?: 0);
            unset($o);
        }
    }
    return array_values($out);
}

/** Busca só de leitura no IXC: nome do cliente pelo ID do contrato. */
function rec_nome_ixc(string $contrato): string
{
    $id = so_digitos($contrato);
    if ($id === '' || destino() !== 'ixc') return '';
    require_once __DIR__ . '/ixc.php';
    $ct = ixc()->um('cliente_contrato', 'id', $id);
    if (!$ct || ($ct['id_cliente'] ?? '') === '') return '';
    $cli = ixc()->um('cliente', 'id', (string)$ct['id_cliente']);
    return up((string)($cli['razao'] ?? ''));
}
