<?php
/**
 * RH: o RH lança, para cada funcionário cadastrado, horas extras informais, sobreaviso,
 * complemento de salário e pagamentos extras. No fechamento do mês soma tudo por funcionário
 * e gera o recibo (2 vias) para assinatura. Paga uma vez só: depois de "Marcar pago" o mês
 * daquele funcionário fica travado; só o admin desfaz.
 * Funcionário do RH é só uma ficha (nome, CPF, função, valores): não é usuário do portal.
 */
declare(strict_types=1);

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/comissoes.php'; // dinheiro(), brl(), mes_nome()

const RH_TIPOS = [
    'horas' => 'Horas informais',
    'sobre' => 'Sobreaviso',
    'compl' => 'Complemento de salário',
    'pag' => 'Pagamento extra',
];

function rh_tabela(): void
{
    db()->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS rh_funcionarios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL UNIQUE,
        cpf TEXT NOT NULL DEFAULT '',
        funcao TEXT NOT NULL DEFAULT '',
        valor_hora TEXT NOT NULL DEFAULT '',
        valor_sobreaviso TEXT NOT NULL DEFAULT '',
        ativo INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS rh_lancamentos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        funcionario_id INTEGER NOT NULL REFERENCES rh_funcionarios(id),
        mes TEXT NOT NULL,
        data TEXT NOT NULL,
        tipo TEXT NOT NULL,
        qtd REAL NOT NULL DEFAULT 1,
        unitario TEXT NOT NULL DEFAULT '',
        valor TEXT NOT NULL,
        descricao TEXT NOT NULL DEFAULT '',
        lancado_por TEXT NOT NULL,
        lancado_em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE INDEX IF NOT EXISTS rh_lanc_mes ON rh_lancamentos (mes, funcionario_id);
    CREATE TABLE IF NOT EXISTS rh_pagamentos (
        funcionario_id INTEGER NOT NULL REFERENCES rh_funcionarios(id),
        mes TEXT NOT NULL,
        pago_em TEXT NOT NULL,
        pago_por TEXT NOT NULL,
        PRIMARY KEY (funcionario_id, mes)
    );
    CREATE TABLE IF NOT EXISTS rh_empresa (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        nome TEXT NOT NULL DEFAULT 'SIDNET TELECOM',
        cnpj TEXT NOT NULL DEFAULT '',
        cidade TEXT NOT NULL DEFAULT ''
    );
    INSERT OR IGNORE INTO rh_empresa (id) VALUES (1);
    CREATE TABLE IF NOT EXISTS rh_config (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        sobre_dia TEXT NOT NULL DEFAULT '50.00',
        jornada TEXT NOT NULL DEFAULT '220',
        adic_util INTEGER NOT NULL DEFAULT 100,
        adic_desc INTEGER NOT NULL DEFAULT 100,
        dsr INTEGER NOT NULL DEFAULT 0
    );
    INSERT OR IGNORE INTO rh_config (id) VALUES (1);
    CREATE TABLE IF NOT EXISTS rh_feriados (data TEXT PRIMARY KEY, nome TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS rh_feriados_anos (ano TEXT PRIMARY KEY);
    CREATE TABLE IF NOT EXISTS rh_compl_feito (
        funcionario_id INTEGER NOT NULL,
        mes TEXT NOT NULL,
        PRIMARY KEY (funcionario_id, mes)
    );
    SQL);
    $cols = array_column(db()->query('PRAGMA table_info(rh_funcionarios)')->fetchAll(), 'name');
    foreach (['salario', 'jornada', 'compl_fixo'] as $c)
        if (!in_array($c, $cols, true)) db()->exec("ALTER TABLE rh_funcionarios ADD COLUMN $c TEXT NOT NULL DEFAULT ''");
    $cols = array_column(db()->query('PRAGMA table_info(rh_lancamentos)')->fetchAll(), 'name');
    // adicional da hora extra (100 = 100%) e de onde veio: '' digitado, 'cal' calendário do sobreaviso, 'auto' complemento fixo
    if (!in_array('adicional', $cols, true)) db()->exec('ALTER TABLE rh_lancamentos ADD COLUMN adicional INTEGER NOT NULL DEFAULT 0');
    if (!in_array('origem', $cols, true)) db()->exec("ALTER TABLE rh_lancamentos ADD COLUMN origem TEXT NOT NULL DEFAULT ''");
}

// ---------- valores e feriados (só o admin muda) ----------

/** sobre_dia (R$ por dia), jornada (h/mês), adic_util e adic_desc (% da hora extra), dsr (0/1) */
function rh_config(): array
{
    return db()->query('SELECT * FROM rh_config WHERE id = 1')->fetch() ?: ['sobre_dia' => '50.00', 'jornada' => '220', 'adic_util' => 100, 'adic_desc' => 100, 'dsr' => 0];
}

function rh_config_salvar(array $in): void
{
    $sd = dinheiro((string)($in['sobre_dia'] ?? ''));
    if ($sd === '' || (float)$sd <= 0) throw new RuntimeException('Preencha o valor do sobreaviso por dia.');
    $j = (int)preg_replace('/\D/', '', (string)($in['jornada'] ?? ''));
    if ($j < 1 || $j > 400) throw new RuntimeException('A jornada precisa ser em horas por mês (ex.: 220).');
    $au = (int)($in['adic_util'] ?? 100);
    $ad = (int)($in['adic_desc'] ?? 100);
    if ($au < 0 || $au > 300 || $ad < 0 || $ad > 300) throw new RuntimeException('Adicional da hora extra entre 0% e 300%.');
    db()->prepare('UPDATE rh_config SET sobre_dia = ?, jornada = ?, adic_util = ?, adic_desc = ?, dsr = ? WHERE id = 1')
        ->execute([$sd, (string)$j, $au, $ad, !empty($in['dsr']) ? 1 : 0]);
}

/** Domingo de Páscoa (algoritmo de Meeus) */
function rh_pascoa(int $a): string
{
    $b = intdiv($a, 100); $c = $a % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
    $h = (19 * ($a % 19) + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv(($a % 19) + 11 * $h + 22 * $l, 451); $mes = intdiv($h + $l - 7 * $m + 114, 31); $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $a, $mes, $dia);
}

/** Feriados do ano [data => nome]. Na primeira vez que o ano aparece, já entram os nacionais. */
function rh_feriados(string $ano): array
{
    if (!preg_match('/^\d{4}$/', $ano)) return [];
    $q = db()->prepare('INSERT OR IGNORE INTO rh_feriados_anos (ano) VALUES (?)');
    $q->execute([$ano]);
    if ($q->rowCount()) {
        $sexta = (new DateTimeImmutable(rh_pascoa((int)$ano)))->modify('-2 day')->format('Y-m-d');
        $ins = db()->prepare('INSERT OR IGNORE INTO rh_feriados (data, nome) VALUES (?,?)');
        foreach (['01-01' => 'Confraternização Universal', '04-21' => 'Tiradentes', '05-01' => 'Dia do Trabalho', '09-07' => 'Independência',
            '10-12' => 'Nossa Senhora Aparecida', '11-02' => 'Finados', '11-15' => 'Proclamação da República', '11-20' => 'Consciência Negra', '12-25' => 'Natal'] as $md => $n)
            $ins->execute(["$ano-$md", $n]);
        $ins->execute([$sexta, 'Sexta-feira Santa']);
    }
    $q = db()->prepare('SELECT data, nome FROM rh_feriados WHERE substr(data, 1, 4) = ? ORDER BY data');
    $q->execute([$ano]);
    return $q->fetchAll(PDO::FETCH_KEY_PAIR);
}

function rh_feriado_salvar(string $data, string $nome): void
{
    if (!rh_data_ok($data)) throw new RuntimeException('Escolha a data do feriado.');
    $nome = mb_substr(trim($nome), 0, 60);
    if ($nome === '') throw new RuntimeException('Escreva o nome do feriado.');
    rh_feriados(substr($data, 0, 4)); // garante os nacionais do ano antes
    db()->prepare('INSERT INTO rh_feriados (data, nome) VALUES (?,?) ON CONFLICT(data) DO UPDATE SET nome = excluded.nome')->execute([$data, $nome]);
}

function rh_feriado_excluir(string $data): void
{
    db()->prepare('DELETE FROM rh_feriados WHERE data = ?')->execute([$data]);
}

/** 'fer' (feriado), 'dom', 'sab' ou 'util' */
function rh_dia_tipo(string $data): string
{
    if (isset(rh_feriados(substr($data, 0, 4))[$data])) return 'fer';
    $w = (int)date('w', strtotime($data));
    return $w === 0 ? 'dom' : ($w === 6 ? 'sab' : 'util');
}

/** % da hora extra no dia: domingo e feriado usam um, os outros dias outro (os dois começam em 100%) */
function rh_adicional(string $data): int
{
    $c = rh_config();
    return (int)(in_array(rh_dia_tipo($data), ['dom', 'fer'], true) ? $c['adic_desc'] : $c['adic_util']);
}

/** Hora normal do funcionário: salário ÷ jornada (a dele ou a padrão). Sem salário, usa o valor da hora antigo do cadastro. */
function rh_hora_normal(array $f): float
{
    $sal = (float)($f['salario'] ?? 0);
    if ($sal > 0) {
        $j = (float)(($f['jornada'] ?? '') !== '' ? $f['jornada'] : rh_config()['jornada']);
        return $j > 0 ? $sal / $j : 0.0;
    }
    return (float)($f['valor_hora'] ?? 0);
}

/** Valor da hora extra num dia: hora normal × (1 + adicional) */
function rh_hora_extra(array $f, string $data): array
{
    $ad = rh_adicional($data);
    return [round(rh_hora_normal($f) * (1 + $ad / 100), 2), $ad];
}

/** DSR sobre as horas informais do mês (só se ligado na configuração): valor ÷ dias úteis × (domingos + feriados). */
function rh_dsr(string $mes, float $valorHoras): float
{
    if ($valorHoras <= 0 || !rh_config()['dsr']) return 0.0;
    $uteis = $desc = 0;
    $d = new DateTimeImmutable("$mes-01");
    for ($x = $d; $x->format('Y-m') === $mes; $x = $x->modify('+1 day')) {
        in_array(rh_dia_tipo($x->format('Y-m-d')), ['dom', 'fer'], true) ? $desc++ : $uteis++;
    }
    return $uteis ? round($valorHoras / $uteis * $desc, 2) : 0.0;
}

/** "2:30", "2h30", "2,5" -> 2.5 (horas); 0 se não entender */
function rh_horas(string $s): float
{
    $s = trim(str_ireplace(['h', 'min'], [':', ''], $s));
    if (preg_match('/^(\d{1,3}):(\d{0,2})$/', $s, $m)) {
        $min = (int)($m[2] ?: 0);
        return $min < 60 ? (int)$m[1] + $min / 60 : 0.0;
    }
    $v = str_replace(',', '.', $s);
    return is_numeric($v) ? max(0.0, (float)$v) : 0.0;
}

/** 2.5 -> "2:30" */
function rh_hm(float $h): string
{
    $t = (int)round($h * 60);
    return intdiv($t, 60) . ':' . str_pad((string)($t % 60), 2, '0', STR_PAD_LEFT);
}

/** float -> "R$ 1.234,50" */
function rh_rs(float $v): string
{
    return 'R$ ' . number_format($v, 2, ',', '.');
}

/** Texto do detalhe de um lançamento: "2:30 h × R$ 25,00" */
function rh_detalhe(array $l): string
{
    if ($l['tipo'] === 'horas') return rh_hm((float)$l['qtd']) . ' h × ' . rh_rs((float)$l['unitario']) . (!empty($l['adicional']) ? ' (' . (int)$l['adicional'] . '%)' : '');
    if ($l['tipo'] === 'sobre') {
        $q = (float)$l['qtd'];
        return rtrim(rtrim(number_format($q, 1, ',', ''), '0'), ',') . ($q == 1 ? ' dia' : ' dias') . ' × ' . rh_rs((float)$l['unitario']);
    }
    return '—';
}

function rh_mes_ok(string $m): string
{
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : '';
}

function rh_data_ok(string $s): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && checkdate((int)substr($s, 5, 2), (int)substr($s, 8, 2), (int)substr($s, 0, 4)) ? $s : '';
}

// ---------- funcionários ----------

function rh_funcionarios(bool $soAtivos = false): array
{
    return db()->query('SELECT * FROM rh_funcionarios' . ($soAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY nome')->fetchAll();
}

function rh_funcionario(int $id): ?array
{
    $q = db()->prepare('SELECT * FROM rh_funcionarios WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

/** "12345678900" -> "123.456.789-00" (outro tamanho: como veio, só dígitos) */
function rh_cpf(string $s): string
{
    $d = so_digitos($s);
    return strlen($d) === 11 ? substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9) : $d;
}

/** Cadastra ($id = 0) ou corrige um funcionário. Devolve o id. */
function rh_func_salvar(array $in, int $id, bool $sal = true): int
{
    $nome = up((string)($in['nome'] ?? ''));
    $cpf = so_digitos((string)($in['cpf'] ?? ''));
    if ($nome === '') throw new RuntimeException('Preencha o nome do funcionário.');
    if ($cpf !== '' && strlen($cpf) !== 11) throw new RuntimeException('O CPF precisa ter 11 números.');
    $d = [mb_substr($nome, 0, 80), $cpf, mb_substr(trim((string)($in['funcao'] ?? '')), 0, 60)];
    $q = db()->prepare('SELECT id FROM rh_funcionarios WHERE nome = ? AND id <> ?');
    $q->execute([$d[0], $id]);
    if ($q->fetchColumn()) throw new RuntimeException("Já tem um funcionário chamado {$d[0]}.");
    if ($id) {
        if (!rh_funcionario($id)) throw new RuntimeException('Esse funcionário não existe mais.');
        db()->prepare('UPDATE rh_funcionarios SET nome = ?, cpf = ?, funcao = ? WHERE id = ?')->execute([...$d, $id]);
    } else {
        db()->prepare('INSERT INTO rh_funcionarios (nome, cpf, funcao) VALUES (?,?,?)')->execute($d);
        $id = (int)db()->lastInsertId();
    }
    // salário, jornada e complemento fixo: só vêm no formulário de quem pode ver
    if ($sal) {
        $j = preg_replace('/\D/', '', (string)($in['jornada'] ?? ''));
        if ($j !== '' && ((int)$j < 1 || (int)$j > 400)) throw new RuntimeException('A jornada é em horas por mês (ex.: 220).');
        db()->prepare('UPDATE rh_funcionarios SET salario = ?, jornada = ?, compl_fixo = ? WHERE id = ?')
            ->execute([dinheiro((string)($in['salario'] ?? '')), $j, dinheiro((string)($in['compl_fixo'] ?? '')), $id]);
    }
    return $id;
}

// ---------- lançamentos ----------

function rh_pago_em(int $fid, string $mes): string
{
    $q = db()->prepare('SELECT pago_em FROM rh_pagamentos WHERE funcionario_id = ? AND mes = ?');
    $q->execute([$fid, $mes]);
    return (string)($q->fetchColumn() ?: '');
}

/**
 * Lê e grava um lançamento. $in: funcionario_id, mes, data, tipo, qtd (horas "2:30" ou dias),
 * unitario (vazio = valor do cadastro), valor (complemento e pagamento extra), descricao.
 */
function rh_lanc_salvar(array $in, string $quem): int
{
    $f = rh_funcionario((int)($in['funcionario_id'] ?? 0));
    if (!$f || !$f['ativo']) throw new RuntimeException('Escolha o funcionário.');
    $tipo = (string)($in['tipo'] ?? '');
    if (!isset(RH_TIPOS[$tipo])) throw new RuntimeException('Escolha o tipo do lançamento.');
    $mes = rh_mes_ok((string)($in['mes'] ?? ''));
    if ($mes === '') throw new RuntimeException('Escolha o mês de referência.');
    $data = rh_data_ok((string)($in['data'] ?? ''));
    if ($data === '') throw new RuntimeException('Preencha a data.');
    if ($pg = rh_pago_em((int)$f['id'], $mes)) throw new RuntimeException("{$f['nome']} já foi pago em " . mes_nome($mes) . ' (' . rh_dt($pg) . '). Lance no mês seguinte ou peça ao admin para desfazer o pagamento.');
    $desc = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['descricao'] ?? '')) ?? ''), 0, 200);
    if ($tipo === 'horas' || $tipo === 'sobre') {
        $qtd = $tipo === 'horas' ? rh_horas((string)($in['qtd'] ?? '')) : (float)str_replace(',', '.', preg_replace('/[^\d,.]/', '', (string)($in['qtd'] ?? '')) ?: '0');
        if ($qtd <= 0) throw new RuntimeException($tipo === 'horas' ? 'Preencha as horas (ex.: 2:30).' : 'Preencha quantos dias de sobreaviso.');
        if ($qtd > ($tipo === 'horas' ? 300 : 31)) throw new RuntimeException('Quantidade grande demais. Confira o que foi digitado.');
        $unit = dinheiro((string)($in['unitario'] ?? ''));
        $adic = 0;
        if ($unit === '') {
            if ($tipo === 'horas') {
                [$u, $adic] = rh_hora_extra($f, $data);
                $unit = $u > 0 ? number_format($u, 2, '.', '') : '';
            } else {
                $unit = (string)rh_config()['sobre_dia'];
            }
        }
        if ($unit === '' || (float)$unit <= 0) throw new RuntimeException($tipo === 'horas' ? 'Cadastre o salário do funcionário (RH → Funcionários) ou digite o valor da hora.' : 'Preencha o valor por dia.');
        $valor = number_format(round($qtd * (float)$unit, 2), 2, '.', '');
    } else {
        $qtd = 1.0;
        $unit = '';
        $valor = dinheiro((string)($in['valor'] ?? ''));
        if ($valor === '' || (float)$valor <= 0) throw new RuntimeException('Preencha o valor.');
        if ($desc === '') throw new RuntimeException('Escreva a descrição (vai no recibo).');
    }
    db()->prepare('INSERT INTO rh_lancamentos (funcionario_id, mes, data, tipo, qtd, unitario, valor, descricao, lancado_por, adicional) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$f['id'], $mes, $data, $tipo, $qtd, $unit, $valor, $desc, $quem, $adic ?? 0]);
    return (int)db()->lastInsertId();
}

function rh_dt(string $s): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) ? "$m[3]/$m[2]/$m[1]" : '';
}

function rh_lanc_um(int $id): ?array
{
    $q = db()->prepare('SELECT l.*, f.nome FROM rh_lancamentos l JOIN rh_funcionarios f ON f.id = l.funcionario_id WHERE l.id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

function rh_lanc_excluir(int $id): ?array
{
    $l = rh_lanc_um($id);
    if (!$l) return null;
    if (rh_pago_em((int)$l['funcionario_id'], $l['mes'])) throw new RuntimeException('Esse lançamento já foi pago e não pode ser excluído.');
    db()->prepare('DELETE FROM rh_lancamentos WHERE id = ?')->execute([$id]);
    return $l;
}

/** Lançamentos do mês, com nome do funcionário, quem lançou e se já foi pago. $tipos: só esses tipos ([] = todos). */
function rh_do_mes(string $mes, int $fid = 0, string $tipo = '', array $tipos = []): array
{
    $sql = "SELECT l.*, f.nome, f.cpf, f.funcao, COALESCE(g.nome, l.lancado_por) AS lancado_nome, COALESCE(p.pago_em, '') AS pago_em
        FROM rh_lancamentos l JOIN rh_funcionarios f ON f.id = l.funcionario_id
        LEFT JOIN gestores g ON g.usuario = l.lancado_por
        LEFT JOIN rh_pagamentos p ON p.funcionario_id = l.funcionario_id AND p.mes = l.mes
        WHERE l.mes = ?";
    $par = [$mes];
    if ($fid) { $sql .= ' AND l.funcionario_id = ?'; $par[] = $fid; }
    if (isset(RH_TIPOS[$tipo])) { $sql .= ' AND l.tipo = ?'; $par[] = $tipo; }
    if ($tipos) { $sql .= ' AND l.tipo IN (' . implode(',', array_fill(0, count($tipos), '?')) . ')'; array_push($par, ...array_values($tipos)); }
    $q = db()->prepare($sql . ' ORDER BY l.data, f.nome, l.id');
    $q->execute($par);
    return $q->fetchAll();
}

/** Meses com lançamento, mais o atual e o anterior, do mais novo para o mais velho. */
function rh_meses(): array
{
    $m = db()->query('SELECT DISTINCT mes FROM rh_lancamentos')->fetchAll(PDO::FETCH_COLUMN);
    foreach ([date('Y-m'), mes_soma(date('Y-m'), -1)] as $x) if (!in_array($x, $m, true)) $m[] = $x;
    rsort($m);
    return $m;
}

/**
 * Total de cada funcionário no mês:
 * [fid => ['f' => funcionário, 'ls' => lançamentos, 'horas' => R$, 'h' => horas, 'sobre' => R$, 'dias' => n, 'compl' => R$, 'pag' => R$, 'tot' => R$, 'pago_em' => '']]
 */
function rh_totais(string $mes, array $soIds = [], array $tipos = []): array
{
    $out = [];
    foreach (rh_do_mes($mes, 0, '', $tipos) as $l) {
        $fid = (int)$l['funcionario_id'];
        if ($soIds && !in_array($fid, $soIds, true)) continue;
        $o = &$out[$fid];
        $o ??= ['f' => ['id' => $fid, 'nome' => $l['nome'], 'cpf' => $l['cpf'], 'funcao' => $l['funcao']], 'ls' => [],
            'horas' => 0.0, 'h' => 0.0, 'sobre' => 0.0, 'dias' => 0.0, 'compl' => 0.0, 'pag' => 0.0, 'tot' => 0.0, 'pago_em' => $l['pago_em']];
        $o['ls'][] = $l;
        $o[$l['tipo']] += (float)$l['valor'];
        $o['tot'] += (float)$l['valor'];
        if ($l['tipo'] === 'horas') $o['h'] += (float)$l['qtd'];
        if ($l['tipo'] === 'sobre') $o['dias'] += (float)$l['qtd'];
        unset($o);
    }
    foreach ($out as &$o) {
        $o['dsr'] = rh_dsr($mes, $o['horas']);
        $o['horas'] += $o['dsr'];
        $o['tot'] += $o['dsr'];
    }
    unset($o);
    uasort($out, fn($a, $b) => strcmp($a['f']['nome'], $b['f']['nome']));
    return $out;
}

/** Marca pago o mês do funcionário (trava os lançamentos). */
function rh_pagar(int $fid, string $mes, string $pagoEm, string $quem): void
{
    if (!rh_mes_ok($mes)) throw new RuntimeException('Mês inválido.');
    if (!rh_data_ok($pagoEm)) throw new RuntimeException('Preencha a data do pagamento.');
    if (!rh_totais($mes, [$fid])) throw new RuntimeException('Esse funcionário não tem lançamento no mês.');
    db()->prepare('INSERT OR IGNORE INTO rh_pagamentos (funcionario_id, mes, pago_em, pago_por) VALUES (?,?,?,?)')->execute([$fid, $mes, $pagoEm, $quem]);
}

function rh_desfazer(int $fid, string $mes): void
{
    db()->prepare('DELETE FROM rh_pagamentos WHERE funcionario_id = ? AND mes = ?')->execute([$fid, $mes]);
}

function rh_empresa(): array
{
    return db()->query('SELECT * FROM rh_empresa WHERE id = 1')->fetch() ?: ['nome' => 'SIDNET TELECOM', 'cnpj' => '', 'cidade' => ''];
}

/** "12345678000190" -> "12.345.678/0001-90" */
function rh_cnpj(string $s): string
{
    $d = so_digitos($s);
    return strlen($d) === 14 ? substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12) : $d;
}

/** 375.5 -> "trezentos e setenta e cinco reais e cinquenta centavos" (até 999.999,99) */
function rh_extenso(float $v): string
{
    $u = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez', 'onze', 'doze', 'treze', 'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];
    $d = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
    $c = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];
    $ate999 = function (int $n) use ($u, $d, $c): string {
        if ($n === 100) return 'cem';
        $p = [];
        if ($n >= 100) { $p[] = $c[intdiv($n, 100)]; $n %= 100; }
        if ($n >= 20) { $p[] = $d[intdiv($n, 10)]; $n %= 10; }
        if ($n > 0) $p[] = $u[$n];
        return implode(' e ', $p);
    };
    $cent = (int)round($v * 100);
    $r = intdiv($cent, 100);
    $cs = $cent % 100;
    $s = '';
    if ($r > 0) {
        $mil = intdiv($r, 1000);
        $resto = $r % 1000;
        $partes = [];
        if ($mil) $partes[] = $mil === 1 ? 'mil' : $ate999($mil) . ' mil';
        if ($resto) $partes[] = $ate999($resto);
        $s = implode(($mil && $resto && ($resto < 100 || $resto % 100 === 0)) ? ' e ' : ' ', $partes) . ($r === 1 ? ' real' : ' reais');
    }
    if ($cs) $s .= ($s !== '' ? ' e ' : '') . $ate999($cs) . ($cs === 1 ? ' centavo' : ' centavos');
    return $s !== '' ? $s : 'zero real';
}

// ---------- sobreaviso no calendário ----------

/** O que já está marcado no calendário do mês: [data => ['sobre' => bool, 'h' => horas trabalhadas]] */
function rh_sobre_mes(int $fid, string $mes): array
{
    $q = db()->prepare("SELECT data, tipo, qtd FROM rh_lancamentos WHERE funcionario_id = ? AND mes = ? AND origem = 'cal'");
    $q->execute([$fid, $mes]);
    $out = [];
    foreach ($q as $l) {
        $o = &$out[$l['data']];
        $o ??= ['sobre' => false, 'h' => 0.0];
        if ($l['tipo'] === 'sobre') $o['sobre'] = true; else $o['h'] += (float)$l['qtd'];
        unset($o);
    }
    return $out;
}

/**
 * Grava o calendário do sobreaviso do funcionário no mês (troca o que estava).
 * $dias = [data => '1'], $horas = [data => '3:00']. Só sábado, domingo e feriado.
 * Cada dia vira um lançamento de sobreaviso; as horas trabalhadas viram horas informais desse dia.
 */
function rh_sobre_salvar(int $fid, string $mes, array $dias, array $horas, string $quem, bool $podeHoras): array
{
    $f = rh_funcionario($fid);
    if (!$f || !$f['ativo']) throw new RuntimeException('Escolha o funcionário.');
    if (!rh_mes_ok($mes)) throw new RuntimeException('Mês inválido.');
    if ($pg = rh_pago_em($fid, $mes)) throw new RuntimeException("{$f['nome']} já foi pago em " . mes_nome($mes) . ' (' . rh_dt($pg) . '). Peça ao admin para desfazer o pagamento.');
    $c = rh_config();
    $novos = [];
    foreach (array_unique(array_merge(array_keys(array_filter($dias)), array_keys(array_filter($horas, fn($v) => trim((string)$v) !== '')))) as $d) {
        $d = (string)$d;
        if (!rh_data_ok($d) || substr($d, 0, 7) !== $mes) continue;
        $tp = rh_dia_tipo($d);
        if ($tp === 'util') throw new RuntimeException(rh_dt($d) . ' é dia útil: sobreaviso só em sábado, domingo e feriado.');
        $h = rh_horas((string)($horas[$d] ?? ''));
        if (trim((string)($horas[$d] ?? '')) !== '' && $h <= 0) throw new RuntimeException('Horas de ' . rh_dt($d) . ' não entendidas. Use 3:00 ou 2:30.');
        if ($h > 24) throw new RuntimeException('Mais de 24 horas em ' . rh_dt($d) . '. Confira.');
        if ($h > 0 && !$podeHoras) throw new RuntimeException('Você não tem permissão para lançar horas informais.');
        $novos[$d] = [$tp, $h];
    }
    ksort($novos);
    $nomeDia = ['sab' => 'sábado', 'dom' => 'domingo', 'fer' => 'feriado'];
    $fer = rh_feriados(substr($mes, 0, 4));
    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM rh_lancamentos WHERE funcionario_id = ? AND mes = ? AND origem = 'cal'" . ($podeHoras ? '' : " AND tipo = 'sobre'"))->execute([$fid, $mes]);
        $ins = $db->prepare("INSERT INTO rh_lancamentos (funcionario_id, mes, data, tipo, qtd, unitario, valor, descricao, lancado_por, adicional, origem) VALUES (?,?,?,?,?,?,?,?,?,?,'cal')");
        $nh = 0.0;
        foreach ($novos as $d => [$tp, $h]) {
            $qual = $tp === 'fer' ? ($fer[$d] ?? 'feriado') : $nomeDia[$tp];
            $ins->execute([$fid, $mes, $d, 'sobre', 1, $c['sobre_dia'], $c['sobre_dia'], 'Sobreaviso ' . rh_dt($d) . " ($qual)", $quem, 0]);
            if ($h > 0) {
                [$u, $ad] = rh_hora_extra($f, $d);
                if ($u <= 0) throw new RuntimeException("Cadastre o salário de {$f['nome']} (RH → Funcionários) para calcular as horas.");
                $ins->execute([$fid, $mes, $d, 'horas', $h, number_format($u, 2, '.', ''), number_format(round($h * $u, 2), 2, '.', ''), 'Trabalhou no sobreaviso ' . rh_dt($d) . " ($qual)", $quem, $ad]);
                $nh += $h;
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['nome' => $f['nome'], 'dias' => count($novos), 'h' => $nh];
}

// ---------- complemento fixo ----------

/**
 * Lança sozinho o complemento fixo do mês de quem tem valor no cadastro (uma vez por mês e funcionário:
 * se o RH excluir a linha, ela não volta). Roda ao abrir o painel, para o mês atual.
 */
function rh_compl_auto(string $mes): int
{
    $q = db()->prepare("SELECT f.* FROM rh_funcionarios f WHERE f.ativo = 1 AND CAST(f.compl_fixo AS REAL) > 0
        AND NOT EXISTS (SELECT 1 FROM rh_compl_feito c WHERE c.funcionario_id = f.id AND c.mes = ?)
        AND NOT EXISTS (SELECT 1 FROM rh_pagamentos p WHERE p.funcionario_id = f.id AND p.mes = ?)");
    $q->execute([$mes, $mes]);
    $n = 0;
    foreach ($q->fetchAll() as $f) {
        db()->prepare('INSERT OR IGNORE INTO rh_compl_feito (funcionario_id, mes) VALUES (?,?)')->execute([$f['id'], $mes]);
        db()->prepare("INSERT INTO rh_lancamentos (funcionario_id, mes, data, tipo, qtd, unitario, valor, descricao, lancado_por, origem) VALUES (?,?,?,'compl',1,'',?,?,'automático','auto')")
            ->execute([$f['id'], $mes, "$mes-01", $f['compl_fixo'], 'Complemento fixo de ' . mb_strtolower(mes_nome($mes))]);
        $n++;
    }
    return $n;
}
