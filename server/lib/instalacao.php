<?php
/**
 * Instalações concluídas: o gestor lança as O.S de instalação que já foram feitas no IXC
 * (só para registro, o sistema não abre nem fecha O.S e não grava nada no IXC), com os técnicos
 * que fizeram e os equipamentos recolhidos na instalação. O admin põe o valor de cada técnico
 * em cada O.S (dois técnicos: um valor para cada; um só: um valor) e vê o total de cada técnico.
 * Regra do ALEX: comissão paga uma vez só; quando paga, marca-se o mês do pagamento.
 * O mês de cada O.S é o mês da data de fechamento.
 */
declare(strict_types=1);

require_once __DIR__ . '/recolhimento.php'; // rec_macs(), rec_eq_form(), rec_data(), rec_dt(), rec_os_ixc(), rec_equipamentos()

const INST_MAX_TEC = 6; // técnicos por O.S

function inst_tabela(): void
{
    db()->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS inst_tecnicos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL UNIQUE,
        ativo INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS instalacoes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cliente TEXT NOT NULL,
        contrato TEXT NOT NULL,
        os TEXT NOT NULL DEFAULT '',
        assunto TEXT NOT NULL DEFAULT '',
        abertura TEXT NOT NULL DEFAULT '',
        fechamento TEXT NOT NULL,
        macs TEXT NOT NULL DEFAULT '',
        modelos TEXT NOT NULL DEFAULT '',
        qtd INTEGER NOT NULL DEFAULT 0,
        obs TEXT NOT NULL DEFAULT '',
        lancado_por TEXT NOT NULL,
        lancado_em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        conferido INTEGER NOT NULL DEFAULT 0,
        pago_em TEXT NOT NULL DEFAULT '',
        alterado_por TEXT NOT NULL DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS inst_fech ON instalacoes (fechamento);
    CREATE TABLE IF NOT EXISTS inst_tec (
        inst_id INTEGER NOT NULL REFERENCES instalacoes(id) ON DELETE CASCADE,
        tecnico_id INTEGER NOT NULL REFERENCES inst_tecnicos(id),
        valor TEXT NOT NULL DEFAULT '',
        PRIMARY KEY (inst_id, tecnico_id)
    );
    CREATE TABLE IF NOT EXISTS inst_regra (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        valor_um TEXT NOT NULL DEFAULT '',
        valor_dupla TEXT NOT NULL DEFAULT ''
    );
    INSERT OR IGNORE INTO inst_regra (id) VALUES (1);
    SQL);
}

function inst_tecnicos(bool $soAtivos = false): array
{
    return db()->query('SELECT id, nome, ativo FROM inst_tecnicos' . ($soAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY nome')->fetchAll();
}

/** Id do técnico com esse nome (igual, sem acento); com $criar, põe na lista se não tiver. 0 se vazio. */
function inst_tecnico_por_nome(string $nome, bool $criar = false): int
{
    $nome = up(preg_replace('/\s+/', ' ', trim($nome)) ?? '');
    if ($nome === '') return 0;
    require_once __DIR__ . '/ixc.php'; // sem_acento()
    $alvo = up(sem_acento($nome));
    foreach (inst_tecnicos() as $t) if (up(sem_acento($t['nome'])) === $alvo) return (int)$t['id'];
    if (!$criar) return 0;
    db()->prepare('INSERT OR IGNORE INTO inst_tecnicos (nome) VALUES (?)')->execute([$nome]);
    return (int)db()->lastInsertId();
}

function inst_regra(): array
{
    return db()->query('SELECT * FROM inst_regra WHERE id = 1')->fetch() ?: ['valor_um' => '', 'valor_dupla' => ''];
}

/** Valor sugerido para cada técnico de uma O.S com $n técnicos ("0.00"). */
function inst_sugestao(int $n, array $regra): string
{
    $v = $n <= 1 ? $regra['valor_um'] : ($regra['valor_dupla'] !== '' ? $regra['valor_dupla'] : $regra['valor_um']);
    return number_format((float)($v ?: 0), 2, '.', '');
}

/** Técnicos do formulário (r[tec][n]) -> [id, ...] sem vazios e sem repetir */
function inst_tec_form(array $in): array
{
    $out = [];
    foreach ((array)($in['tec'] ?? []) as $t) {
        $t = (int)$t;
        if ($t > 0 && !in_array($t, $out, true)) $out[] = $t;
    }
    return $out;
}

/** Lê e confere o formulário de uma O.S. Lança RuntimeException com o motivo se faltar algo. */
function inst_validar(array $in): array
{
    $eqs = rec_eq_form($in);
    $macs = array_column($eqs, 0);
    $tecs = inst_tec_form($in);
    $d = [
        'cliente' => up(preg_replace('/\s+/', ' ', trim((string)($in['cliente'] ?? ''))) ?? ''),
        'contrato' => so_digitos((string)($in['contrato'] ?? '')),
        'os' => so_digitos((string)($in['os'] ?? '')),
        'assunto' => mb_substr(up(trim((string)($in['assunto'] ?? ''))), 0, 80),
        'abertura' => rec_data((string)($in['abertura'] ?? '')),
        'fechamento' => rec_data((string)($in['fechamento'] ?? '')),
        'macs' => implode("\n", $macs),
        'modelos' => implode("\n", array_column($eqs, 1)),
        'qtd' => count($macs),
        'obs' => mb_substr(trim((string)($in['obs'] ?? '')), 0, 300),
        'tecs' => $tecs,
    ];
    if ($d['cliente'] === '') throw new RuntimeException('Preencha o nome do cliente.');
    if ($d['contrato'] === '') throw new RuntimeException('Preencha o ID do contrato.');
    if (in_array('', $macs, true)) throw new RuntimeException('Tem equipamento recolhido com modelo e sem MAC. Preencha o MAC ou apague a linha.');
    if (in_array('', array_column($eqs, 1), true)) throw new RuntimeException('Preencha o modelo de cada equipamento recolhido.');
    if ($d['fechamento'] === '') throw new RuntimeException('Preencha a data de fechamento da O.S.');
    if ($d['abertura'] !== '' && $d['abertura'] > $d['fechamento']) throw new RuntimeException('A abertura da O.S está depois do fechamento.');
    if ($d['fechamento'] > date('Y-m-d')) throw new RuntimeException('A data de fechamento está no futuro.');
    if (!$tecs) throw new RuntimeException('Escolha pelo menos um técnico que fez a instalação.');
    if (count($tecs) > INST_MAX_TEC) throw new RuntimeException('No máximo ' . INST_MAX_TEC . ' técnicos por O.S.');
    $q = db()->prepare('SELECT COUNT(*) FROM inst_tecnicos WHERE id IN (' . implode(',', array_fill(0, count($tecs), '?')) . ')');
    $q->execute($tecs);
    if ((int)$q->fetchColumn() !== count($tecs)) throw new RuntimeException('Técnico não encontrado. Escolha de novo.');
    return $d;
}

/**
 * Grava uma O.S nova ($id = 0) ou corrige uma existente que ainda não foi paga.
 * Os valores dos técnicos que continuam na O.S ficam; técnico tirado perde o valor.
 * Devolve [id, aviso] (aviso quando algum MAC já estava em outra O.S).
 */
function inst_salvar(array $in, int $id, string $quem): array
{
    $d = inst_validar($in);
    $db = db();
    $antes = [];
    if ($id) {
        $q = $db->prepare('SELECT pago_em FROM instalacoes WHERE id = ?');
        $q->execute([$id]);
        $pg = $q->fetchColumn();
        if ($pg === false) throw new RuntimeException('Essa O.S não existe mais.');
        if ($pg !== '') throw new RuntimeException('Essa O.S já teve a comissão paga e não pode mais ser mudada.');
        $q = $db->prepare('SELECT tecnico_id, valor FROM inst_tec WHERE inst_id = ?');
        $q->execute([$id]);
        $antes = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    if ($d['os'] !== '') {
        $q = $db->prepare('SELECT id FROM instalacoes WHERE os = ? AND id <> ?');
        $q->execute([$d['os'], $id]);
        if ($q->fetchColumn()) throw new RuntimeException("A O.S nº {$d['os']} já foi lançada.");
    }
    $repetidos = [];
    if ($d['macs'] !== '') {
        $q = $db->prepare("SELECT cliente FROM instalacoes WHERE id <> ? AND ('\n' || macs || '\n') LIKE ?");
        foreach (explode("\n", $d['macs']) as $m) {
            $q->execute([$id, "%\n$m\n%"]);
            if ($c = $q->fetchColumn()) $repetidos[] = "$m (já em $c)";
        }
    }
    $db->beginTransaction();
    try {
        if ($id) {
            // mudou quem fez: volta a ser conferida
            $mudouTec = array_map('intval', array_keys($antes));
            sort($mudouTec);
            $novos = $d['tecs'];
            sort($novos);
            $db->prepare('UPDATE instalacoes SET cliente=?, contrato=?, os=?, assunto=?, abertura=?, fechamento=?, macs=?, modelos=?, qtd=?, obs=?, alterado_por=?,
                conferido = CASE WHEN ? THEN conferido ELSE 0 END WHERE id=?')
                ->execute([$d['cliente'], $d['contrato'], $d['os'], $d['assunto'], $d['abertura'], $d['fechamento'], $d['macs'], $d['modelos'], $d['qtd'], $d['obs'], $quem,
                    $mudouTec === $novos ? 1 : 0, $id]);
            $db->prepare('DELETE FROM inst_tec WHERE inst_id = ?')->execute([$id]);
        } else {
            $db->prepare('INSERT INTO instalacoes (cliente, contrato, os, assunto, abertura, fechamento, macs, modelos, qtd, obs, lancado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$d['cliente'], $d['contrato'], $d['os'], $d['assunto'], $d['abertura'], $d['fechamento'], $d['macs'], $d['modelos'], $d['qtd'], $d['obs'], $quem]);
            $id = (int)$db->lastInsertId();
        }
        $ins = $db->prepare('INSERT INTO inst_tec (inst_id, tecnico_id, valor) VALUES (?, ?, ?)');
        foreach ($d['tecs'] as $t) $ins->execute([$id, $t, (string)($antes[$t] ?? '')]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return [$id, $repetidos ? 'Atenção: MAC já lançado em outra O.S de instalação: ' . implode(', ', $repetidos) . '.' : ''];
}

function inst_excluir(int $id): void
{
    $q = db()->prepare('SELECT pago_em FROM instalacoes WHERE id = ?');
    $q->execute([$id]);
    $pg = $q->fetchColumn();
    if ($pg === false) return;
    if ($pg !== '') throw new RuntimeException('Essa O.S já teve a comissão paga e não pode ser excluída.');
    db()->prepare('DELETE FROM inst_tec WHERE inst_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM instalacoes WHERE id = ?')->execute([$id]);
}

/** Uma O.S com a lista de técnicos em 'tecs' = [['id', 'nome', 'valor'], ...] */
function inst_um(int $id): ?array
{
    $q = db()->prepare('SELECT * FROM instalacoes WHERE id = ?');
    $q->execute([$id]);
    $r = $q->fetch();
    if (!$r) return null;
    return inst_com_tecs([$r])[0];
}

/** Põe em cada O.S a lista 'tecs' = [['id' => , 'nome' => , 'valor' => ], ...] */
function inst_com_tecs(array $linhas): array
{
    if (!$linhas) return [];
    $ids = array_map(fn($r) => (int)$r['id'], $linhas);
    $q = db()->prepare('SELECT it.inst_id, t.id, t.nome, it.valor FROM inst_tec it JOIN inst_tecnicos t ON t.id = it.tecnico_id
        WHERE it.inst_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY t.nome');
    $q->execute($ids);
    $por = [];
    foreach ($q->fetchAll() as $t) $por[(int)$t['inst_id']][] = ['id' => (int)$t['id'], 'nome' => $t['nome'], 'valor' => $t['valor']];
    foreach ($linhas as &$r) $r['tecs'] = $por[(int)$r['id']] ?? [];
    unset($r);
    return $linhas;
}

/** O.S fechadas no mês (aaaa-mm), com técnicos e o nome de quem lançou. */
function inst_do_mes(string $mes, string $busca = ''): array
{
    $sql = "SELECT i.*, COALESCE(g.nome, i.lancado_por) AS lancado_nome
        FROM instalacoes i LEFT JOIN gestores g ON g.usuario = i.lancado_por
        WHERE substr(i.fechamento, 1, 7) = ?";
    $par = [$mes];
    $b = up(trim($busca));
    if ($b !== '') {
        $sql .= " AND (i.cliente LIKE ? OR i.contrato = ? OR i.os = ? OR i.macs LIKE ? OR i.modelos LIKE ?
            OR i.id IN (SELECT it.inst_id FROM inst_tec it JOIN inst_tecnicos t ON t.id = it.tecnico_id WHERE t.nome LIKE ?))";
        $mac = preg_replace('/[^A-Z0-9]/', '', $b);
        array_push($par, "%$b%", so_digitos($b) ?: '-', so_digitos($b) ?: '-', '%' . ($mac ?: '-') . '%', "%$b%", "%$b%");
    }
    $q = db()->prepare($sql . ' ORDER BY i.fechamento, i.cliente, i.id');
    $q->execute($par);
    return inst_com_tecs($q->fetchAll());
}

/** Meses que têm O.S, mais o atual, do mais novo para o mais velho. */
function inst_meses(): array
{
    $m = db()->query("SELECT DISTINCT substr(fechamento, 1, 7) FROM instalacoes ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array(date('Y-m'), $m, true)) $m[] = date('Y-m');
    rsort($m);
    return $m;
}

/**
 * Admin: grava os valores de cada técnico e a conferência das O.S ainda não pagas.
 * $linhas = [inst_id => ['ok' => '1', 'v' => [tecnico_id => '50,00', ...]]]
 */
function inst_comissao_salvar(array $linhas, string $quem): int
{
    $db = db();
    $pg = $db->prepare("SELECT pago_em FROM instalacoes WHERE id = ?");
    $upT = $db->prepare("UPDATE inst_tec SET valor = ? WHERE inst_id = ? AND tecnico_id = ? AND valor <> ?");
    $upO = $db->prepare("UPDATE instalacoes SET conferido = ?, alterado_por = ? WHERE id = ? AND conferido <> ?");
    $toca = $db->prepare("UPDATE instalacoes SET alterado_por = ? WHERE id = ?");
    $n = 0;
    $db->beginTransaction();
    foreach ($linhas as $id => $l) {
        $id = (int)$id;
        $pg->execute([$id]);
        if ($pg->fetchColumn() !== '') continue; // paga ou não existe
        $mudou = false;
        foreach ((array)($l['v'] ?? []) as $tid => $v) {
            $v = dinheiro((string)$v);
            $upT->execute([$v, $id, (int)$tid, $v]);
            $mudou = $mudou || $upT->rowCount() > 0;
        }
        $ok = !empty($l['ok']) ? 1 : 0;
        $upO->execute([$ok, $quem, $id, $ok]);
        $mudou = $mudou || $upO->rowCount() > 0;
        if ($mudou) { $toca->execute([$quem, $id]); $n++; }
    }
    $db->commit();
    return $n;
}

/** Admin: preenche com o valor padrão os técnicos sem valor das O.S não conferidas e não pagas do mês. */
function inst_aplicar_regra(string $mes, bool $sobrescrever): int
{
    $regra = inst_regra();
    $up = db()->prepare('UPDATE inst_tec SET valor = ? WHERE inst_id = ? AND tecnico_id = ?');
    $n = 0;
    foreach (inst_do_mes($mes) as $r) {
        if ($r['pago_em'] !== '' || $r['conferido']) continue;
        $sug = inst_sugestao(count($r['tecs']), $regra);
        $mexeu = false;
        foreach ($r['tecs'] as $t) {
            if (!$sobrescrever && $t['valor'] !== '') continue;
            $up->execute([$sug, $r['id'], $t['id']]);
            $mexeu = true;
        }
        if ($mexeu) $n++;
    }
    return $n;
}

/** Admin: marca como pagas, no mês $pagoEm, as O.S conferidas do mês $mes que ainda não foram pagas. */
function inst_pagar(string $mes, string $pagoEm, string $quem): int
{
    if (!preg_match('/^\d{4}-\d{2}$/', $pagoEm)) throw new RuntimeException('Escolha o mês do pagamento.');
    $q = db()->prepare("UPDATE instalacoes SET pago_em = ?, alterado_por = ? WHERE substr(fechamento, 1, 7) = ? AND conferido = 1 AND pago_em = ''");
    $q->execute([$pagoEm, $quem, $mes]);
    return $q->rowCount();
}

/** Total de cada técnico: [['nome', 'os', 'sozinho', 'valor', 'falta', 'sem_valor'], ...] do maior para o menor */
function inst_por_tecnico(array $linhas): array
{
    $out = [];
    foreach ($linhas as $r) {
        foreach ($r['tecs'] as $t) {
            $o = &$out[$t['id']];
            $o ??= ['nome' => $t['nome'], 'os' => 0, 'sozinho' => 0, 'valor' => 0.0, 'falta' => 0.0, 'sem_valor' => 0];
            $o['os']++;
            if (count($r['tecs']) === 1) $o['sozinho']++;
            $o['valor'] += (float)($t['valor'] ?: 0);
            if ($r['pago_em'] === '') $o['falta'] += (float)($t['valor'] ?: 0);
            if ($t['valor'] === '') $o['sem_valor']++;
            unset($o);
        }
    }
    usort($out, fn($a, $b) => [$b['valor'], $a['nome']] <=> [$a['valor'], $b['nome']]);
    return $out;
}
