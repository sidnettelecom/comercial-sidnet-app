<?php
/**
 * Serviços em execução: serviços de rede feitos por equipes (terceirizadas ou próprias), como lançamento
 * de fibra em área rural, rede em poste da CEMIG e rede em postes de eucalipto próprios.
 * Cada serviço feito soma no saldo da equipe; cada pagamento (parcial ou não) desconta, até zerar.
 * O pagamento abate primeiro o serviço mais antigo: assim cada serviço fica em aberto, pago em parte ou quitado.
 * Só o admin vê, até ele liberar em Configurações → Permissões.
 */
declare(strict_types=1);

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/comissoes.php'; // dinheiro(), brl(), mes_nome()
require_once __DIR__ . '/rh.php';        // rh_rs(), rh_dt(), rh_data_ok()

const SERV_REDES = ['rural' => 'Fibra rural', 'cemig' => 'Rede em poste CEMIG', 'eucalipto' => 'Postes de eucalipto próprios', 'outro' => 'Outro'];
const SERV_EQUIPE_TIPOS = ['terc' => 'Terceirizada', 'propria' => 'Equipe própria'];
const SERV_FORMAS = ['PIX', 'Dinheiro', 'Transferência', 'Cheque', 'Outro'];
/** serviços que já vêm na tabela de preços (sem valor: o admin preenche) */
const SERV_PRECOS_INICIAIS = [
    ['Lançamento de cabo óptico', 'metro'],
    ['Cabo em poste CEMIG (com fixação)', 'metro'],
    ['Poste de eucalipto instalado', 'poste'],
    ['Fusão', 'fusão'],
    ['CTO / caixa de emenda instalada', 'caixa'],
    ['Diária', 'dia'],
];

function serv_tabela(): void
{
    $novo = !db()->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'serv_precos'")->fetchColumn();
    db()->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS serv_equipes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL UNIQUE,
        tipo TEXT NOT NULL DEFAULT 'terc',
        contato TEXT NOT NULL DEFAULT '',
        ativo INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS serv_obras (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL UNIQUE,
        rede TEXT NOT NULL DEFAULT 'rural',
        concluida INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS serv_precos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL UNIQUE,
        unidade TEXT NOT NULL,
        valor TEXT NOT NULL DEFAULT '',
        ativo INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS serv_lanc (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        equipe_id INTEGER NOT NULL REFERENCES serv_equipes(id),
        k TEXT NOT NULL,                       -- s = serviço feito, p = pagamento
        data TEXT NOT NULL,
        obra_id INTEGER NOT NULL DEFAULT 0,
        preco_id INTEGER NOT NULL DEFAULT 0,   -- 0 = valor fechado
        qtd REAL NOT NULL DEFAULT 1,
        unitario TEXT NOT NULL DEFAULT '',
        valor TEXT NOT NULL,
        forma TEXT NOT NULL DEFAULT '',
        descricao TEXT NOT NULL DEFAULT '',
        lancado_por TEXT NOT NULL,
        lancado_em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE INDEX IF NOT EXISTS serv_lanc_eq ON serv_lanc (equipe_id, data);
    SQL);
    // auditoria: AS200/AS80 em metros e tocos/postes de eucalipto em unidades (NULL = ainda não separado à mão: vale a sugestão pelo texto)
    $cols = array_column(db()->query('PRAGMA table_info(serv_lanc)')->fetchAll(), 'name');
    foreach (['as200' => 'REAL', 'as80' => 'REAL', 'tocos' => 'REAL', 'audit' => "TEXT NOT NULL DEFAULT ''", 'audit_obs' => "TEXT NOT NULL DEFAULT ''",
        'audit_por' => "TEXT NOT NULL DEFAULT ''", 'audit_em' => "TEXT NOT NULL DEFAULT ''"] as $c => $t)
        if (!in_array($c, $cols, true)) db()->exec("ALTER TABLE serv_lanc ADD COLUMN $c $t");
    // tipos de cabo (o admin cadastra) e o cabo de cada serviço; split = separação gravada na auditoria (JSON), NULL = vale a sugestão
    $novoCabo = !db()->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'serv_cabos'")->fetchColumn();
    db()->exec('CREATE TABLE IF NOT EXISTS serv_cabos (id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT NOT NULL UNIQUE, ativo INTEGER NOT NULL DEFAULT 1)');
    if ($novoCabo) foreach (['AS200', 'AS80'] as $c) db()->prepare('INSERT OR IGNORE INTO serv_cabos (nome) VALUES (?)')->execute([$c]);
    $cols = array_column(db()->query('PRAGMA table_info(serv_lanc)')->fetchAll(), 'name');
    if (!in_array('cabo_id', $cols, true)) db()->exec('ALTER TABLE serv_lanc ADD COLUMN cabo_id INTEGER NOT NULL DEFAULT 0');
    if (!in_array('split', $cols, true)) {
        db()->exec('ALTER TABLE serv_lanc ADD COLUMN split TEXT');
        // auditoria antiga (colunas as200/as80/tocos) passa para o split
        $ids = array_flip(array_map(fn($c) => $c['nome'], serv_cabos()));
        $up = db()->prepare('UPDATE serv_lanc SET split = ? WHERE id = ?');
        foreach (db()->query('SELECT id, as200, as80, tocos FROM serv_lanc WHERE as200 IS NOT NULL OR as80 IS NOT NULL OR tocos IS NOT NULL') as $r) {
            $cb = array_filter([($ids['AS200'] ?? 0) => (float)$r['as200'], ($ids['AS80'] ?? 0) => (float)$r['as80']], fn($m, $k) => $k && $m > 0, ARRAY_FILTER_USE_BOTH);
            $up->execute([json_encode(['cabos' => (object)$cb, 'tocos' => (float)$r['tocos']]), $r['id']]);
        }
    }
    if ($novo) {
        $q = db()->prepare('INSERT OR IGNORE INTO serv_precos (nome, unidade) VALUES (?,?)');
        foreach (SERV_PRECOS_INICIAIS as $p) $q->execute($p);
    }
}

function serv_texto(string $s, int $max): string
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $s) ?? ''), 0, $max);
}

/** 1500 -> "1.500"; 2.5 -> "2,5" */
function serv_num(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
}

/** "1.500" e "1500" -> 1500; "2,5" e "2.5" -> 2.5 */
function serv_qtd_ler(string $s): float
{
    $s = preg_replace('/[^\d,.]/', '', $s) ?? '';
    if (str_contains($s, ',')) $s = str_replace(['.', ','], ['', '.'], $s);
    elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);
    return is_numeric($s) ? max(0.0, (float)$s) : 0.0;
}

// ---------- cadastros ----------

function serv_equipes(bool $soAtivas = false): array
{
    return db()->query('SELECT * FROM serv_equipes' . ($soAtivas ? ' WHERE ativo = 1' : '') . ' ORDER BY nome')->fetchAll(PDO::FETCH_UNIQUE);
}

function serv_equipe(int $id): ?array
{
    $q = db()->prepare('SELECT * FROM serv_equipes WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

function serv_equipe_salvar(array $in, int $id): int
{
    $nome = mb_substr(up(serv_texto((string)($in['nome'] ?? ''), 80)), 0, 80);
    if ($nome === '') throw new RuntimeException('Preencha o nome da equipe.');
    $tipo = isset(SERV_EQUIPE_TIPOS[$in['tipo'] ?? '']) ? (string)$in['tipo'] : 'terc';
    $contato = serv_texto((string)($in['contato'] ?? ''), 120);
    $q = db()->prepare('SELECT id FROM serv_equipes WHERE nome = ? AND id <> ?');
    $q->execute([$nome, $id]);
    if ($q->fetchColumn()) throw new RuntimeException("Já tem uma equipe chamada $nome.");
    if ($id) {
        if (!serv_equipe($id)) throw new RuntimeException('Essa equipe não existe mais.');
        db()->prepare('UPDATE serv_equipes SET nome = ?, tipo = ?, contato = ? WHERE id = ?')->execute([$nome, $tipo, $contato, $id]);
        return $id;
    }
    db()->prepare('INSERT INTO serv_equipes (nome, tipo, contato) VALUES (?,?,?)')->execute([$nome, $tipo, $contato]);
    return (int)db()->lastInsertId();
}

function serv_obras(bool $soAbertas = false): array
{
    return db()->query('SELECT * FROM serv_obras' . ($soAbertas ? ' WHERE concluida = 0' : '') . ' ORDER BY concluida, nome')->fetchAll(PDO::FETCH_UNIQUE);
}

function serv_obra_salvar(array $in, int $id): int
{
    $nome = serv_texto((string)($in['nome'] ?? ''), 80);
    if ($nome === '') throw new RuntimeException('Preencha o nome da obra ou local.');
    $rede = isset(SERV_REDES[$in['rede'] ?? '']) ? (string)$in['rede'] : 'outro';
    $q = db()->prepare('SELECT id FROM serv_obras WHERE nome = ? AND id <> ?');
    $q->execute([$nome, $id]);
    if ($q->fetchColumn()) throw new RuntimeException("Já tem uma obra chamada $nome.");
    if ($id) {
        db()->prepare('UPDATE serv_obras SET nome = ?, rede = ? WHERE id = ?')->execute([$nome, $rede, $id]);
        return $id;
    }
    db()->prepare('INSERT INTO serv_obras (nome, rede) VALUES (?,?)')->execute([$nome, $rede]);
    return (int)db()->lastInsertId();
}

function serv_precos(bool $soAtivos = false): array
{
    return db()->query('SELECT * FROM serv_precos' . ($soAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY id')->fetchAll(PDO::FETCH_UNIQUE);
}

/** Grava a tabela de preços: $lista = [id => ['nome', 'unidade', 'valor', 'ativo']], mais $novo = ['nome', 'unidade', 'valor']. */
function serv_precos_salvar(array $lista, array $novo): int
{
    $n = 0;
    $up = db()->prepare('UPDATE serv_precos SET nome = ?, unidade = ?, valor = ?, ativo = ? WHERE id = ?');
    foreach ($lista as $id => $p) {
        $nome = serv_texto((string)($p['nome'] ?? ''), 80);
        $un = mb_strtolower(serv_texto((string)($p['unidade'] ?? ''), 20));
        if ($nome === '' || $un === '') throw new RuntimeException('Cada serviço da tabela precisa de nome e de "cobra por".');
        $up->execute([$nome, $un, dinheiro((string)($p['valor'] ?? '')), empty($p['ativo']) ? 0 : 1, (int)$id]);
        $n += $up->rowCount();
    }
    $nome = serv_texto((string)($novo['nome'] ?? ''), 80);
    if ($nome !== '') {
        $un = mb_strtolower(serv_texto((string)($novo['unidade'] ?? ''), 20));
        if ($un === '') throw new RuntimeException("Diga por que unidade se cobra \"$nome\" (metro, poste, dia...).");
        $q = db()->prepare('SELECT 1 FROM serv_precos WHERE nome = ?');
        $q->execute([$nome]);
        if ($q->fetchColumn()) throw new RuntimeException("\"$nome\" já está na tabela.");
        db()->prepare('INSERT INTO serv_precos (nome, unidade, valor) VALUES (?,?,?)')->execute([$nome, $un, dinheiro((string)($novo['valor'] ?? ''))]);
        $n++;
    }
    return $n;
}

function serv_cabos(bool $soAtivos = false): array
{
    return db()->query('SELECT * FROM serv_cabos' . ($soAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY id')->fetchAll(PDO::FETCH_UNIQUE);
}

/** Grava os tipos de cabo: $lista = [id => ['nome', 'ativo']], mais $novo (nome). */
function serv_cabos_salvar(array $lista, string $novo): int
{
    $n = 0;
    $up = db()->prepare('UPDATE serv_cabos SET nome = ?, ativo = ? WHERE id = ?');
    $nomes = [];
    foreach ($lista as $id => $c) {
        $nome = mb_strtoupper(serv_texto((string)($c['nome'] ?? ''), 40));
        if ($nome === '') throw new RuntimeException('Todo cabo precisa de nome.');
        if (isset($nomes[$nome])) throw new RuntimeException("O cabo $nome está repetido.");
        $nomes[$nome] = 1;
        $up->execute([$nome, empty($c['ativo']) ? 0 : 1, (int)$id]);
        $n += $up->rowCount();
    }
    $nome = mb_strtoupper(serv_texto($novo, 40));
    if ($nome !== '') {
        $q = db()->prepare('SELECT 1 FROM serv_cabos WHERE nome = ?');
        $q->execute([$nome]);
        if ($q->fetchColumn()) throw new RuntimeException("O cabo $nome já está cadastrado.");
        db()->prepare('INSERT INTO serv_cabos (nome) VALUES (?)')->execute([$nome]);
        $n++;
    }
    return $n;
}

// ---------- lançamentos ----------

/**
 * Lança um serviço feito. $in: equipe_id, data, obra_id, preco_id (0 = valor fechado), qtd, unitario, valor, descricao.
 * Com preço da tabela: valor = qtd × unitário (unitário em branco = o da tabela).
 */
function serv_servico_salvar(array $in, string $quem, int $id = 0): int
{
    $antes = $id ? serv_lanc_um($id) : null;
    if ($id && (!$antes || $antes['k'] !== 's')) throw new RuntimeException('Esse serviço não existe mais.');
    $e = serv_equipe((int)($in['equipe_id'] ?? 0));
    if (!$e || (!$e['ativo'] && (int)($antes['equipe_id'] ?? 0) !== (int)$e['id'])) throw new RuntimeException('Escolha a equipe.');
    $data = rh_data_ok((string)($in['data'] ?? ''));
    if ($data === '') throw new RuntimeException('Preencha a data.');
    $obra = (int)($in['obra_id'] ?? 0);
    if ($obra && !isset(serv_obras()[$obra])) throw new RuntimeException('Essa obra não existe mais.');
    $desc = serv_texto((string)($in['descricao'] ?? ''), 200);
    $cabo = (int)($in['cabo_id'] ?? 0);
    if ($cabo && !isset(serv_cabos()[$cabo])) throw new RuntimeException('Esse tipo de cabo não existe mais.');
    $pid = (int)($in['preco_id'] ?? 0);
    if ($pid) {
        $pr = serv_precos()[$pid] ?? null;
        if (!$pr) throw new RuntimeException('Escolha o serviço.');
        $qtd = serv_qtd_ler((string)($in['qtd'] ?? ''));
        if ($qtd <= 0) throw new RuntimeException("Preencha a quantidade ({$pr['unidade']}).");
        if ($qtd > 1000000) throw new RuntimeException('Quantidade grande demais. Confira o que foi digitado.');
        $unit = dinheiro((string)($in['unitario'] ?? ''));
        if ($unit === '') $unit = $pr['valor'];
        if ($unit === '' || (float)$unit <= 0) throw new RuntimeException("Preencha o valor por {$pr['unidade']} (ou cadastre na tabela de preços).");
        $valor = number_format(round($qtd * (float)$unit, 2), 2, '.', '');
    } else {
        $qtd = 1.0;
        $unit = '';
        $valor = dinheiro((string)($in['valor'] ?? ''));
        if ($valor === '' || (float)$valor <= 0) throw new RuntimeException('Preencha o valor do serviço.');
        if ($desc === '') throw new RuntimeException('Escreva o que foi feito (valor fechado precisa de descrição).');
    }
    if (!$pid || !str_starts_with(mb_strtolower((string)serv_precos()[$pid]['unidade']), 'metro')) $cabo = 0; // cabo só em serviço por metro
    if ($id) {
        // mudou cabo, serviço ou metros: a separação da auditoria volta a ser a sugerida
        $mudou = (int)$antes['cabo_id'] !== $cabo || (int)$antes['preco_id'] !== $pid || abs((float)$antes['qtd'] - $qtd) > 0.001;
        db()->prepare('UPDATE serv_lanc SET equipe_id = ?, data = ?, obra_id = ?, preco_id = ?, qtd = ?, unitario = ?, valor = ?, descricao = ?, cabo_id = ?' . ($mudou ? ', split = NULL' : '') . ' WHERE id = ?')
            ->execute([$e['id'], $data, $obra, $pid, $qtd, $unit, $valor, $desc, $cabo, $id]);
        return $id;
    }
    db()->prepare("INSERT INTO serv_lanc (equipe_id, k, data, obra_id, preco_id, qtd, unitario, valor, descricao, cabo_id, lancado_por) VALUES (?,'s',?,?,?,?,?,?,?,?,?)")
        ->execute([$e['id'], $data, $obra, $pid, $qtd, $unit, $valor, $desc, $cabo, $quem]);
    return (int)db()->lastInsertId();
}

/** Lança um pagamento. $in: equipe_id, data, valor, forma, descricao. Pode ser maior que o saldo (vira crédito/adiantamento). */
function serv_pagamento_salvar(array $in, string $quem, int $id = 0): int
{
    if ($id && (serv_lanc_um($id)['k'] ?? '') !== 'p') throw new RuntimeException('Esse pagamento não existe mais.');
    $e = serv_equipe((int)($in['equipe_id'] ?? 0));
    if (!$e) throw new RuntimeException('Escolha a equipe.');
    $data = rh_data_ok((string)($in['data'] ?? ''));
    if ($data === '') throw new RuntimeException('Preencha a data do pagamento.');
    $valor = dinheiro((string)($in['valor'] ?? ''));
    if ($valor === '' || (float)$valor <= 0) throw new RuntimeException('Preencha o valor pago.');
    $forma = in_array($in['forma'] ?? '', SERV_FORMAS, true) ? (string)$in['forma'] : 'Outro';
    if ($id) {
        db()->prepare('UPDATE serv_lanc SET equipe_id = ?, data = ?, valor = ?, forma = ?, descricao = ? WHERE id = ?')
            ->execute([$e['id'], $data, $valor, $forma, serv_texto((string)($in['descricao'] ?? ''), 200), $id]);
        return $id;
    }
    db()->prepare("INSERT INTO serv_lanc (equipe_id, k, data, valor, forma, descricao, lancado_por) VALUES (?,'p',?,?,?,?,?)")
        ->execute([$e['id'], $data, $valor, $forma, serv_texto((string)($in['descricao'] ?? ''), 200), $quem]);
    return (int)db()->lastInsertId();
}

function serv_lanc_um(int $id): ?array
{
    $q = db()->prepare('SELECT l.*, e.nome AS equipe FROM serv_lanc l JOIN serv_equipes e ON e.id = l.equipe_id WHERE l.id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

function serv_excluir(int $id): ?array
{
    $l = serv_lanc_um($id);
    if ($l) db()->prepare('DELETE FROM serv_lanc WHERE id = ?')->execute([$id]);
    return $l;
}

/**
 * Lançamentos em ordem de data (mais velho primeiro), com obra, serviço da tabela, quem lançou,
 * saldo correndo da equipe ('saldo') e, nos serviços, quanto o pagamento já abateu ('abatido') e a situação.
 * $eid = 0: todas as equipes.
 */
function serv_lancamentos(int $eid = 0): array
{
    $sql = "SELECT l.*, e.nome AS equipe, COALESCE(o.nome, '') AS obra, COALESCE(o.rede, '') AS rede,
            COALESCE(p.nome, '') AS servico, COALESCE(p.unidade, '') AS unidade, COALESCE(g.nome, l.lancado_por) AS lancado_nome, COALESCE(c.nome, '') AS cabo
        FROM serv_lanc l JOIN serv_equipes e ON e.id = l.equipe_id LEFT JOIN serv_cabos c ON c.id = l.cabo_id
        LEFT JOIN serv_obras o ON o.id = l.obra_id LEFT JOIN serv_precos p ON p.id = l.preco_id
        LEFT JOIN gestores g ON g.usuario = l.lancado_por" . ($eid ? ' WHERE l.equipe_id = ?' : '') . ' ORDER BY l.data, l.id';
    $q = db()->prepare($sql);
    $q->execute($eid ? [$eid] : []);
    $ls = $q->fetchAll();
    $pago = $saldo = [];
    foreach ($ls as $l) if ($l['k'] === 'p') $pago[$l['equipe_id']] = ($pago[$l['equipe_id']] ?? 0) + (float)$l['valor'];
    foreach ($ls as &$l) {
        $v = (float)$l['valor'];
        $e = $l['equipe_id'];
        $saldo[$e] = round(($saldo[$e] ?? 0) + ($l['k'] === 's' ? $v : -$v), 2);
        $l['saldo'] = $saldo[$e];
        if ($l['k'] === 's') {
            $ab = min($v, $pago[$e] ?? 0);
            $pago[$e] = ($pago[$e] ?? 0) - $ab;
            $l['abatido'] = $ab;
            $l['falta'] = round($v - $ab, 2);
            $l['sit'] = $l['falta'] < 0.005 ? 'pago' : ($ab > 0.005 ? 'parte' : 'aberto');
        }
    }
    unset($l);
    return $ls;
}

/** [equipe_id => ['servicos', 'pago', 'falta', 'ultimo']] de todas as equipes (as sem lançamento também) */
function serv_saldos(?array $ls = null): array
{
    $out = [];
    foreach (serv_equipes() as $id => $e) $out[$id] = ['servicos' => 0.0, 'pago' => 0.0, 'falta' => 0.0, 'ultimo' => ''];
    foreach ($ls ?? serv_lancamentos() as $l) {
        $r = &$out[$l['equipe_id']];
        $r[$l['k'] === 's' ? 'servicos' : 'pago'] += (float)$l['valor'];
        $r['falta'] = round($r['servicos'] - $r['pago'], 2);
        $r['ultimo'] = max($r['ultimo'], $l['data']);
        unset($r);
    }
    return $out;
}

/** [obra_id => ['servicos', 'pago', 'falta']]: o pago é o que os pagamentos já abateram dos serviços daquela obra */
function serv_por_obra(array $ls): array
{
    $out = [];
    foreach ($ls as $l) {
        if ($l['k'] !== 's') continue;
        $r = &$out[(int)$l['obra_id']];
        $r ??= ['servicos' => 0.0, 'pago' => 0.0, 'falta' => 0.0];
        $r['servicos'] += (float)$l['valor'];
        $r['pago'] += $l['abatido'];
        $r['falta'] = round($r['servicos'] - $r['pago'], 2);
        unset($r);
    }
    return $out;
}

/** "Lançamento de cabo óptico · trecho X" e "1.500 metros × R$ 1,20" */
function serv_oque(array $l): string
{
    if ($l['k'] === 'p') return 'Pagamento (' . $l['forma'] . ')' . ($l['descricao'] !== '' ? ' · ' . $l['descricao'] : '');
    return ($l['servico'] !== '' ? $l['servico'] : 'Serviço (valor fechado)') . ($l['descricao'] !== '' ? ' · ' . $l['descricao'] : '');
}

function serv_qtd(array $l): string
{
    if ($l['k'] !== 's') return '';
    if (!(int)$l['preco_id']) return 'valor fechado';
    $q = (float)$l['qtd'];
    $u = $l['unidade'];
    if ($q != 1 && $u !== '' && !preg_match('/[sç]$|ão$/u', $u)) $u .= 's';
    elseif ($q != 1 && str_ends_with($u, 'ão')) $u = mb_substr($u, 0, -2) . 'ões';
    return serv_num($q) . ' ' . $u . (($l['cabo'] ?? '') !== '' ? ' de ' . $l['cabo'] : '') . ' × ' . rh_rs((float)$l['unitario']);
}

/** Pagamentos feitos no mês (todas as equipes), para a tela Recibos */
function serv_pagamentos_do_mes(string $mes): array
{
    $q = db()->prepare("SELECT l.*, e.nome FROM serv_lanc l JOIN serv_equipes e ON e.id = l.equipe_id WHERE l.k = 'p' AND substr(l.data, 1, 7) = ? ORDER BY l.data, l.id");
    $q->execute([$mes]);
    return $q->fetchAll();
}

// ---------- auditoria (conferir com a medição da terceirizada) ----------

const SERV_AUDIT = ['' => ['Falta conferir', 'q'], 'ok' => ['Conferido', 'ok'], 'div' => ['Diverge', 'er']];

function serv_un_metro(array $l): bool
{
    return str_starts_with(mb_strtolower((string)($l['unidade'] ?? '')), 'metro');
}

/** "AS200" -> regex que aceita "AS200", "AS 200", "AS-200" */
function serv_cabo_regex(string $nome): string
{
    $partes = preg_split('/(?<=\pL)(?=\d)|(?<=\d)(?=\pL)|[\s-]+/u', mb_strtoupper(trim($nome)), -1, PREG_SPLIT_NO_EMPTY);
    return implode('\s*-?\s*', array_map(fn($x) => preg_quote($x, '/'), $partes));
}

/**
 * Sugere a separação: cabo escolhido no lançamento -> todos os metros nele; sem cabo escolhido, pelo texto
 * ("300 METROS AS200 + 400 MT AS80"; só "AS200" no texto -> tudo em AS200); serviço cobrado por poste -> tocos.
 * @return array{cabos: array<int, float>, tocos: float}
 */
function serv_tipo_sugerir(array $l): array
{
    $r = ['cabos' => [], 'tocos' => 0.0];
    if (($l['k'] ?? 's') !== 's') return $r;
    $qtd = (float)$l['qtd'];
    $un = mb_strtolower((string)($l['unidade'] ?? ''));
    if (str_starts_with($un, 'poste') || str_starts_with($un, 'toco')) {
        $r['tocos'] = $qtd;
        return $r;
    }
    if (!str_starts_with($un, 'metro')) return $r;
    if ((int)($l['cabo_id'] ?? 0)) {
        $r['cabos'][(int)$l['cabo_id']] = $qtd;
        return $r;
    }
    $txt = mb_strtoupper(($l['servico'] ?? '') . ' ' . ($l['descricao'] ?? ''));
    $tem = [];
    foreach (serv_cabos() as $id => $c) {
        $rx = serv_cabo_regex($c['nome']);
        if ($rx === '' || !preg_match('/(?<![\pL\d])' . $rx . '(?![\pL\d])/u', $txt)) continue;
        $tem[] = $id;
        // número colado no cabo: "400MT AS200", "300 METROS DE AS80"
        if (preg_match_all('/(\d{1,3}(?:\.\d{3})+|\d+)\s*(?:M|MT|MTS|METRO|METROS)?\s*(?:DE\s+)?(?:CABO\s+)?' . $rx . '(?![\pL\d])/u', $txt, $m))
            foreach ($m[1] as $n) $r['cabos'][$id] = ($r['cabos'][$id] ?? 0) + serv_qtd_ler($n);
    }
    if (!$r['cabos'] && count($tem) === 1) $r['cabos'][$tem[0]] = $qtd;
    return $r;
}

/** Separação valendo: a gravada na auditoria ou a sugerida. Mais 'sem' (metros sem cabo) e 'manual'. */
function serv_tipos(array $l): array
{
    $sp = ($l['split'] ?? null) !== null ? json_decode((string)$l['split'], true) : null;
    $manual = is_array($sp);
    $r = $manual ? ['cabos' => array_map('floatval', (array)($sp['cabos'] ?? [])), 'tocos' => (float)($sp['tocos'] ?? 0)] : serv_tipo_sugerir($l);
    $r['cabos'] = array_filter($r['cabos'], fn($m) => $m > 0);
    $r['sem'] = serv_un_metro($l) ? max(0.0, round((float)$l['qtd'] - array_sum($r['cabos']), 2)) : 0.0;
    $r['manual'] = $manual;
    return $r;
}

/** Totais da lista: ['c<id>' / 'tocos' / 'sem' => ['qtd', 'valor', 'conf', 'n']] (valor rateado pelos metros quando o serviço mistura cabos) */
function serv_tipos_totais(array $ls): array
{
    $t = [];
    $add = function (string $k, float $q, float $v, bool $ok) use (&$t) {
        $t[$k] ??= ['qtd' => 0.0, 'valor' => 0.0, 'conf' => 0.0, 'n' => 0];
        $t[$k]['qtd'] += $q;
        $t[$k]['valor'] += $v;
        if ($ok) $t[$k]['conf'] += $q;
        $t[$k]['n']++;
    };
    foreach ($ls as $l) {
        if ($l['k'] !== 's') continue;
        $tp = $l['tp'] ?? serv_tipos($l);
        $base = (float)$l['qtd'] > 0 ? (float)$l['valor'] / (float)$l['qtd'] : 0.0;
        $ok = $l['audit'] === 'ok';
        $metro = serv_un_metro($l);
        foreach ($tp['cabos'] as $cid => $m) $add('c' . $cid, $m, $metro ? $m * $base : 0, $ok);
        if ($tp['tocos'] > 0) $add('tocos', $tp['tocos'], $metro ? 0 : $tp['tocos'] * $base, $ok);
        if ($tp['sem'] > 0) $add('sem', $tp['sem'], $tp['sem'] * $base, $ok);
    }
    return $t;
}

/** Grava a auditoria de um serviço. $in: cabo[id] (metros), tocos (tudo em branco = volta para a sugestão), audit ('', ok, div), obs. */
function serv_audit_salvar(int $id, array $in, string $quem): array
{
    $l = serv_lanc_um($id);
    if (!$l || $l['k'] !== 's') throw new RuntimeException('Serviço não encontrado.');
    $cb = [];
    $algum = false;
    foreach ((array)($in['cabo'] ?? []) as $cid => $v) {
        if (trim((string)$v) === '') continue;
        $algum = true;
        if (($m = serv_qtd_ler((string)$v)) > 0) $cb[(int)$cid] = $m;
    }
    $tocos = trim((string)($in['tocos'] ?? ''));
    $algum = $algum || $tocos !== '';
    $split = $algum ? json_encode(['cabos' => (object)$cb, 'tocos' => serv_qtd_ler($tocos)]) : null;
    $a = array_key_exists($in['audit'] ?? '', SERV_AUDIT) ? (string)$in['audit'] : '';
    $obs = serv_texto((string)($in['obs'] ?? ''), 300);
    if ($a === 'div' && $obs === '') throw new RuntimeException('Escreva o que diverge da medição da terceirizada.');
    db()->prepare('UPDATE serv_lanc SET split = ?, audit = ?, audit_obs = ?, audit_por = ?, audit_em = ? WHERE id = ?')
        ->execute([$split, $a, $obs, $a === '' ? '' : $quem, $a === '' ? '' : date('Y-m-d H:i:s'), $id]);
    return serv_lanc_um($id);
}

/** Marca/desmarca conferido com um clique (ao conferir, guarda a separação que estava valendo, para não mudar depois) */
function serv_audit_ok(int $id, string $quem): array
{
    $l = array_values(array_filter(serv_lancamentos(), fn($x) => (int)$x['id'] === $id))[0] ?? null;
    if (!$l || $l['k'] !== 's') throw new RuntimeException('Serviço não encontrado.');
    $novo = $l['audit'] === 'ok' ? '' : 'ok';
    $split = $l['split'];
    if ($novo === 'ok') {
        $tp = serv_tipos($l);
        $split = json_encode(['cabos' => (object)$tp['cabos'], 'tocos' => $tp['tocos']]);
    }
    db()->prepare('UPDATE serv_lanc SET split = ?, audit = ?, audit_por = ?, audit_em = ? WHERE id = ?')
        ->execute([$split, $novo, $novo === '' ? '' : $quem, $novo === '' ? '' : date('Y-m-d H:i:s'), $id]);
    return serv_lanc_um($id);
}
