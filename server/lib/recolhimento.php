<?php
/**
 * Recolhimento de equipamentos: o financeiro lança as O.S de recolhimento que já fez no IXC
 * (só para registro, o sistema não abre nem fecha O.S e não grava nada no IXC) e o admin
 * confere e paga a comissão de quem lançou (financeiro) e de quem recolheu (recolhedor, ex.: vendedor; só aparece pelo nome).
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
    $cols = array_column(db()->query('PRAGMA table_info(recolhimentos)')->fetchAll(), 'name');
    // modelo de cada equipamento, na mesma ordem dos MACs (um por linha)
    if (!in_array('modelos', $cols, true)) db()->exec("ALTER TABLE recolhimentos ADD COLUMN modelos TEXT NOT NULL DEFAULT ''");
    // situação (pedido do ALEX, 2026-11-24): 'ok' recolhido e preenchido; 'pendente' puxada do IXC, falta o financeiro preencher;
    // 'nao' O.S fechada sem recolher o equipamento. Só 'ok' entra na comissão.
    if (!in_array('situacao', $cols, true)) db()->exec("ALTER TABLE recolhimentos ADD COLUMN situacao TEXT NOT NULL DEFAULT 'ok'");
    if (!in_array('diagnostico', $cols, true)) db()->exec("ALTER TABLE recolhimentos ADD COLUMN diagnostico TEXT NOT NULL DEFAULT ''");
    if (!in_array('ixc_cliente', $cols, true)) db()->exec("ALTER TABLE recolhimentos ADD COLUMN ixc_cliente TEXT NOT NULL DEFAULT ''");
    $colsR = array_column(db()->query('PRAGMA table_info(rec_regra)')->fetchAll(), 'name');
    // assuntos de O.S do IXC que são recolhimento, separados por vírgula (NULL = padrão REC_IMP_PADRAO)
    if (!in_array('assuntos', $colsR, true)) db()->exec('ALTER TABLE rec_regra ADD COLUMN assuntos TEXT');
    db()->exec('CREATE INDEX IF NOT EXISTS rec_os ON recolhimentos (os)');
}

/** Rótulos das situações */
const REC_SIT = ['ok' => 'Recolhidos', 'pendente' => 'A preencher', 'nao' => 'Fechadas sem recolher'];

/** Equipamentos da O.S: [['mac' => ..., 'modelo' => ...], ...] */
function rec_equipamentos(array $r): array
{
    $macs = $r['macs'] === '' ? [] : explode("\n", (string)$r['macs']);
    $mods = explode("\n", (string)($r['modelos'] ?? ''));
    $out = [];
    foreach ($macs as $i => $m) $out[] = ['mac' => $m, 'modelo' => $mods[$i] ?? ''];
    return $out;
}

/** Linhas de equipamento do formulário (r[eq][n][mac|modelo]) -> [[mac, modelo], ...] sem linhas vazias e sem MAC repetido */
function rec_eq_form(array $in): array
{
    $out = [];
    $vistos = [];
    $linhas = (array)($in['eq'] ?? []);
    if (!$linhas && isset($in['macs'])) foreach (rec_macs((string)$in['macs']) as $m) $linhas[] = ['mac' => $m, 'modelo' => ''];
    foreach ($linhas as $l) {
        $mac = rec_macs((string)($l['mac'] ?? ''))[0] ?? '';
        $mod = up(preg_replace('/\s+/', ' ', trim(str_replace("\n", ' ', (string)($l['modelo'] ?? '')))) ?? '');
        if ($mac === '' && $mod === '') continue;
        if ($mac !== '' && isset($vistos[$mac])) continue;
        $vistos[$mac] = 1;
        $out[] = [$mac, mb_substr($mod, 0, 60)];
    }
    return $out;
}

/** Modelos já usados (para sugerir no campo). */
function rec_modelos_usados(): array
{
    $m = [];
    foreach (db()->query("SELECT modelos FROM recolhimentos WHERE modelos <> '' ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) as $s) {
        foreach (explode("\n", $s) as $x) if ($x !== '') $m[$x] = 1;
    }
    ksort($m);
    return array_keys($m);
}

/** Quantos equipamentos de cada modelo nas O.S: ['ZTE F670L' => 3, ...] (mais recolhidos primeiro) */
function rec_por_modelo(array $linhas): array
{
    $c = [];
    foreach ($linhas as $r) foreach (rec_equipamentos($r) as $e) {
        $k = $e['modelo'] !== '' ? $e['modelo'] : 'SEM MODELO';
        $c[$k] = ($c[$k] ?? 0) + 1;
    }
    arsort($c);
    return $c;
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
    $eqs = rec_eq_form($in);
    $macs = array_column($eqs, 0);
    $d = [
        'cliente' => up(preg_replace('/\s+/', ' ', trim((string)($in['cliente'] ?? ''))) ?? ''),
        'contrato' => so_digitos((string)($in['contrato'] ?? '')),
        'os' => so_digitos((string)($in['os'] ?? '')),
        'macs' => implode("\n", $macs),
        'modelos' => implode("\n", array_column($eqs, 1)),
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
    if (in_array('', $macs, true)) throw new RuntimeException('Tem equipamento com modelo e sem MAC. Preencha o MAC ou apague a linha.');
    if (in_array('', array_column($eqs, 1), true)) throw new RuntimeException('Preencha o modelo de cada equipamento.');
    if ($d['fechamento'] === '') throw new RuntimeException('Preencha a data de fechamento da O.S.');
    if ($d['abertura'] !== '' && $d['abertura'] > $d['fechamento']) throw new RuntimeException('A abertura da O.S está depois do fechamento.');
    if ($d['fechamento'] > date('Y-m-d')) throw new RuntimeException('A data de fechamento está no futuro.');
    $q = db()->prepare('SELECT 1 FROM rec_tecnicos WHERE id = ?');
    $q->execute([$d['tecnico_id']]);
    if (!$q->fetchColumn()) throw new RuntimeException('Escolha quem recolheu.');
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
        // O.S puxada do IXC (ou fechada sem recolher) sendo preenchida: vira recolhida e quem preencheu é quem lançou
        db()->prepare("UPDATE recolhimentos SET lancado_por = CASE WHEN situacao <> 'ok' THEN ? ELSE lancado_por END, situacao = 'ok' WHERE id = ?")->execute([$quem, $id]);
    }
    if ($d['os'] !== '') {
        $q = db()->prepare('SELECT situacao FROM recolhimentos WHERE os = ? AND id <> ?');
        $q->execute([$d['os'], $id]);
        $s = $q->fetchColumn();
        if ($s !== false) throw new RuntimeException($s === 'ok' ? "A O.S nº {$d['os']} já foi lançada."
            : "A O.S nº {$d['os']} já veio do IXC e está em \"" . REC_SIT[$s] . "\": use Preencher nela.");
    }
    $repetidos = [];
    $q = db()->prepare("SELECT cliente FROM recolhimentos WHERE id <> ? AND ('\n' || macs || '\n') LIKE ?");
    foreach (explode("\n", $d['macs']) as $m) {
        $q->execute([$id, "%\n$m\n%"]);
        if ($c = $q->fetchColumn()) $repetidos[] = "$m (já em $c)";
    }
    if ($id) {
        // mudou o que muda o valor: volta a ser conferida
        db()->prepare('UPDATE recolhimentos SET cliente=?, contrato=?, os=?, macs=?, modelos=?, qtd=?, abertura=?, fechamento=?, funcionando=?, tecnico_id=?, obs=?, alterado_por=?,
            conferido = CASE WHEN qtd = ? AND funcionando = ? AND tecnico_id = ? THEN conferido ELSE 0 END WHERE id=?')
            ->execute([$d['cliente'], $d['contrato'], $d['os'], $d['macs'], $d['modelos'], $d['qtd'], $d['abertura'], $d['fechamento'], $d['funcionando'], $d['tecnico_id'], $d['obs'], $quem,
                $d['qtd'], $d['funcionando'], $d['tecnico_id'], $id]);
    } else {
        db()->prepare('INSERT INTO recolhimentos (cliente, contrato, os, macs, modelos, qtd, abertura, fechamento, funcionando, tecnico_id, obs, lancado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$d['cliente'], $d['contrato'], $d['os'], $d['macs'], $d['modelos'], $d['qtd'], $d['abertura'], $d['fechamento'], $d['funcionando'], $d['tecnico_id'], $d['obs'], $quem]);
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
function rec_do_mes(string $mes, string $busca = '', string $sit = 'ok'): array
{
    $sql = "SELECT r.*, t.nome AS tecnico, COALESCE(g.nome, r.lancado_por) AS lancado_nome
        FROM recolhimentos r JOIN rec_tecnicos t ON t.id = r.tecnico_id LEFT JOIN gestores g ON g.usuario = r.lancado_por
        WHERE r.situacao = ?" . ($mes !== '' ? ' AND substr(r.fechamento, 1, 7) = ?' : '');
    $par = $mes !== '' ? [$sit, $mes] : [$sit];
    $b = up(trim($busca));
    if ($b !== '') {
        $sql .= " AND (r.cliente LIKE ? OR r.contrato = ? OR r.os = ? OR r.macs LIKE ? OR r.modelos LIKE ?)";
        $mac = preg_replace('/[^A-Z0-9]/', '', $b);
        array_push($par, "%$b%", so_digitos($b) ?: '-', so_digitos($b) ?: '-', '%' . ($mac ?: '-') . '%', "%$b%");
    }
    $q = db()->prepare($sql . ($mes !== '' ? ' ORDER BY r.fechamento, r.cliente, r.id' : ' ORDER BY r.fechamento DESC, r.cliente, r.id'));
    $q->execute($par);
    return $q->fetchAll();
}

/** Meses que têm O.S, mais o atual, do mais novo para o mais velho. */
function rec_meses(): array
{
    $m = db()->query("SELECT DISTINCT substr(fechamento, 1, 7) FROM recolhimentos WHERE situacao = 'ok' ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN);
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
    $up = db()->prepare("UPDATE recolhimentos SET valor_fin = ?, valor_tec = ?, conferido = ?, alterado_por = ? WHERE id = ? AND pago_em = '' AND situacao = 'ok'
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
    $q = db()->prepare("UPDATE recolhimentos SET pago_em = ?, alterado_por = ? WHERE substr(fechamento, 1, 7) = ? AND conferido = 1 AND pago_em = '' AND situacao = 'ok'");
    $q->execute([$pagoEm, $quem, $mes]);
    return $q->rowCount();
}

/** Total por pessoa: [nome => ['papel' => ..., 'os' => n, 'eq' => n, 'valor' => float, 'falta' => float]] */
function rec_por_pessoa(array $linhas): array
{
    $out = [];
    foreach ($linhas as $r) {
        foreach ([['Financeiro', $r['lancado_nome'], 'valor_fin'], ['Recolhedor', $r['tecnico'], 'valor_tec']] as [$papel, $nome, $col]) {
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

/** "2026-03-25 14:10:00" -> "2026-03-25" ('' se vazia ou zerada) */
function rec_data_ixc(mixed $v): string
{
    return preg_match('/^(\d{4}-\d{2}-\d{2})/', (string)$v, $m) && $m[1] !== '0000-00-00' ? $m[1] : '';
}

/**
 * Busca só de leitura no IXC: a O.S pelo número (su_oss_chamado) e o cliente dela.
 * Devolve cliente, contrato, abertura, fechamento, se está finalizada e o técnico; null se não achar.
 */
function rec_os_ixc(string $os): ?array
{
    $id = so_digitos($os);
    if ($id === '' || destino() !== 'ixc') return null;
    require_once __DIR__ . '/ixc.php';
    $o = ixc()->um('su_oss_chamado', 'id', $id);
    if (!$o) return null;
    $cli = ($o['id_cliente'] ?? '') !== '' ? ixc()->um('cliente', 'id', (string)$o['id_cliente']) : null;
    $tec = '';
    if ((string)($o['id_tecnico'] ?? '0') !== '' && (string)($o['id_tecnico'] ?? '0') !== '0') {
        try { $f = ixc()->um('funcionarios', 'id', (string)$o['id_tecnico']); $tec = up((string)($f['funcionario'] ?? ($f['nome'] ?? ''))); } catch (Throwable) {}
    }
    $assunto = '';
    if (($o['id_assunto'] ?? '') !== '') {
        try { $assunto = up((string)(ixc()->um('su_oss_assunto', 'id', (string)$o['id_assunto'])['assunto'] ?? '')); } catch (Throwable) {}
    }
    return [
        'cliente' => up((string)($cli['razao'] ?? '')),
        'contrato' => so_digitos((string)($o['id_contrato_kit'] ?? ($o['id_contrato'] ?? ''))),
        'abertura' => rec_data_ixc($o['data_abertura'] ?? ''),
        'fechamento' => rec_data_ixc($o['data_fechamento'] ?? ''),
        'finalizada' => ($o['status'] ?? '') === 'F',
        'tecnico' => $tec,
        'assunto' => $assunto,
    ];
}

/** Id do técnico de campo com esse nome (igual, sem acento), ou 0. */
function rec_tecnico_por_nome(string $nome): int
{
    if ($nome === '') return 0;
    require_once __DIR__ . '/ixc.php';
    $alvo = up(sem_acento($nome));
    foreach (rec_tecnicos(true) as $t) if (up(sem_acento($t['nome'])) === $alvo) return (int)$t['id'];
    return 0;
}

/*
 * Puxar do IXC as O.S de recolhimento (pedido do ALEX, 2026-11-24): toda O.S finalizada no mês com um assunto de recolhimento
 * entra sozinha em "A preencher" com cliente, contrato, O.S, abertura e fechamento. O financeiro só preenche os equipamentos
 * (MAC, modelo, funcionando, recolhedor) ou marca "fechada sem recolher". Só leitura no IXC: nada é aberto, fechado ou gravado lá.
 */

/** Assunto da O.S de recolhimento no IXC: 22 RETIRADA DE EQUIPAMENTOS (informado pelo ALEX em 2026-11-24) */
const REC_IMP_PADRAO = ['22'];
/** Diagnóstico do fechamento que quer dizer "recolhido": 9 EQUIPAMENTO RECOLHIDO (ALEX, 2026-11-24) */
const REC_DIAG_RECOLHIDO = '9';

/** Ids dos assuntos de recolhimento: os escolhidos pelo admin ou, se nunca escolheu, o 22. */
function rec_imp_assuntos(): array
{
    $s = db()->query('SELECT assuntos FROM rec_regra WHERE id = 1')->fetchColumn();
    if ($s !== null && $s !== false) return array_values(array_filter(explode(',', (string)$s), fn($x) => $x !== ''));
    return REC_IMP_PADRAO;
}

/**
 * Diagnóstico do fechamento da O.S no IXC (só leitura): ['id' => '9', 'nome' => 'EQUIPAMENTO RECOLHIDO', 'sit' => 'pendente'|'nao'].
 * Procura o campo de diagnóstico na própria O.S e, se não tiver, nas mensagens da O.S (a de fechamento leva o diagnóstico).
 * "Não recolhido" (nome com NÃO + RECOLH) vai para Fechadas sem recolher; o resto vai para A preencher.
 */
function rec_diag_ixc(array $o, array &$nomes = []): array
{
    $acha = function (array $l): string {
        foreach ($l as $k => $v) {
            if (!preg_match('/diagnostico/i', (string)$k) || preg_match('/detalh/i', (string)$k)) continue;
            $v = so_digitos((string)$v);
            if ($v !== '' && $v !== '0') return $v;
        }
        return '';
    };
    $id = $acha($o);
    if ($id === '' && ($os = so_digitos((string)($o['id'] ?? ''))) !== '') {
        try {
            foreach (ixc()->listar('su_oss_chamado_mensagem', 'id_chamado', $os, '=', 50, 'desc') as $m) if (($id = $acha($m)) !== '') break;
        } catch (Throwable) {}
    }
    if ($id === '') return ['id' => '', 'nome' => '', 'sit' => 'pendente'];
    if (!isset($nomes[$id])) {
        $nomes[$id] = '';
        foreach (['su_diagnostico', 'su_oss_diagnostico'] as $tab) {
            try {
                $d = ixc()->um($tab, 'id', $id);
                $n = up((string)($d['descricao'] ?? ($d['diagnostico'] ?? ($d['nome'] ?? ''))));
                if ($n !== '') { $nomes[$id] = $n; break; }
            } catch (Throwable) {}
        }
    }
    $n = up(sem_acento($nomes[$id]));
    $nao = $id !== REC_DIAG_RECOLHIDO && str_contains($n, 'RECOLH') && preg_match('/\bNAO\b/', $n);
    return ['id' => $id, 'nome' => $nomes[$id] ?: 'diagnóstico ' . $id, 'sit' => $nao ? 'nao' : 'pendente'];
}

/** Admin: grava os assuntos marcados (ids). */
function rec_imp_config_salvar(array $ids): int
{
    $ids = array_values(array_unique(array_filter(array_map(fn($x) => so_digitos((string)$x), $ids), fn($x) => $x !== '')));
    db()->prepare('UPDATE rec_regra SET assuntos = ? WHERE id = 1')->execute([implode(',', $ids)]);
    return count($ids);
}

/**
 * Puxa do IXC as O.S finalizadas no mês (aaaa-mm) com os assuntos de recolhimento e põe em "A preencher" as que ainda não estão aqui.
 * Devolve ['novas' => n, 'ja' => n].
 */
function rec_imp_puxar(string $mes): array
{
    if (destino() !== 'ixc') throw new RuntimeException('Puxar recolhimentos só funciona com o IXC.');
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) throw new RuntimeException('Escolha o mês.');
    require_once __DIR__ . '/inst_analise.php';
    require_once __DIR__ . '/ixc.php';
    $assuntos = rec_imp_assuntos();
    if (!$assuntos) throw new RuntimeException('Nenhum assunto de O.S marcado como recolhimento. Escolha em "Puxar do IXC" (só admin).');
    // recolhedor já escolhido: o único ativo (hoje o LUCAS); o financeiro confere ao preencher
    $tecs = rec_tecnicos(true);
    if (!$tecs) throw new RuntimeException('Cadastre o recolhedor antes (Configurações → Permissões, no fim da página).');
    $tecPadrao = (int)$tecs[0]['id'];
    $ini = $mes . '-01';
    $fim = (new DateTimeImmutable($ini))->modify('last day of this month')->format('Y-m-d');
    $parada = (new DateTimeImmutable($ini))->modify('-120 day')->format('Y-m-d'); // O.S abertas bem antes do mês não fecham nele
    $jaOs = db()->prepare('SELECT 1 FROM recolhimentos WHERE os = ?');
    $jaSemOs = db()->prepare("SELECT 1 FROM recolhimentos WHERE os = '' AND contrato = ? AND fechamento = ?"); // lançadas à mão sem nº de O.S
    $ins = db()->prepare("INSERT INTO recolhimentos (cliente, contrato, os, macs, modelos, qtd, abertura, fechamento, funcionando, tecnico_id, obs, lancado_por, situacao, ixc_cliente, diagnostico)
        VALUES (?, ?, ?, '', '', 0, ?, ?, 'SIM', ?, ?, 'IXC (puxado)', ?, ?, ?)");
    $clientes = $diags = [];
    $out = ['novas' => 0, 'ja' => 0, 'nao' => 0];
    foreach ($assuntos as $aid) {
        for ($pg = 1; $pg <= 20; $pg++) {
            $lista = ixc()->listar('su_oss_chamado', 'id_assunto', (string)$aid, '=', 500, 'desc', $pg);
            if (!$lista) break;
            $velhas = 0;
            foreach ($lista as $o) {
                $ab = rec_data_ixc($o['data_abertura'] ?? '');
                if ($ab !== '' && $ab < $parada) { $velhas++; continue; }
                $fe = rec_data_ixc($o['data_fechamento'] ?? '');
                if (($o['status'] ?? '') !== 'F' || $fe < $ini || $fe > $fim) continue;
                $os = so_digitos((string)($o['id'] ?? ''));
                if ($os === '') continue;
                $ct = so_digitos((string)($o['id_contrato_kit'] ?? ''));
                $ct = $ct === '0' ? '' : $ct;
                $jaOs->execute([$os]);
                $jaSemOs->execute([$ct, $fe]);
                if ($jaOs->fetchColumn() || ($ct !== '' && $jaSemOs->fetchColumn())) { $out['ja']++; continue; }
                $cid = so_digitos((string)($o['id_cliente'] ?? ''));
                if ($cid !== '' && !isset($clientes[$cid])) {
                    try { $clientes[$cid] = up((string)(ixc()->um('cliente', 'id', $cid)['razao'] ?? '')); } catch (Throwable) { $clientes[$cid] = ''; }
                }
                $dg = rec_diag_ixc($o, $diags);
                $ins->execute([($clientes[$cid] ?? '') ?: 'CLIENTE ' . $cid, $ct, $os, $ab, $fe, $tecPadrao,
                    $dg['sit'] === 'nao' ? 'IXC: ' . $dg['nome'] : '', $dg['sit'], $cid, $dg['nome']]);
                $out['novas']++;
                if ($dg['sit'] === 'nao') $out['nao']++;
            }
            if ($velhas === count($lista) || count($lista) < 500) break;
        }
    }
    return $out;
}

/** Financeiro: marca uma O.S puxada como fechada sem recolher o equipamento (motivo vai na observação) ou volta para "A preencher". */
function rec_marcar(int $id, string $sit, string $motivo, string $quem): array
{
    $r = rec_um($id);
    if (!$r) throw new RuntimeException('Essa O.S não existe mais.');
    if ($r['situacao'] === 'ok') throw new RuntimeException('Essa O.S já está com os equipamentos preenchidos.');
    if (!in_array($sit, ['nao', 'pendente'], true)) throw new RuntimeException('Situação inválida.');
    $motivo = mb_substr(trim($motivo), 0, 300);
    db()->prepare('UPDATE recolhimentos SET situacao = ?, obs = ?, alterado_por = ? WHERE id = ?')->execute([$sit, $sit === 'nao' ? $motivo : $r['obs'], $quem, $id]);
    return $r;
}

/** Quantas O.S em cada situação que não é 'ok' (para as abas) */
function rec_contagem(): array
{
    $c = ['pendente' => 0, 'nao' => 0];
    foreach (db()->query("SELECT situacao, COUNT(*) n FROM recolhimentos WHERE situacao <> 'ok' GROUP BY situacao") as $l) $c[$l['situacao']] = (int)$l['n'];
    return $c;
}
