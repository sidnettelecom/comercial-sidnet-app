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
    SQL);
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
    if ($l['tipo'] === 'horas') return rh_hm((float)$l['qtd']) . ' h × ' . rh_rs((float)$l['unitario']);
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
function rh_func_salvar(array $in, int $id): int
{
    $nome = up((string)($in['nome'] ?? ''));
    $cpf = so_digitos((string)($in['cpf'] ?? ''));
    if ($nome === '') throw new RuntimeException('Preencha o nome do funcionário.');
    if ($cpf !== '' && strlen($cpf) !== 11) throw new RuntimeException('O CPF precisa ter 11 números.');
    $d = [mb_substr($nome, 0, 80), $cpf, mb_substr(trim((string)($in['funcao'] ?? '')), 0, 60),
        dinheiro((string)($in['valor_hora'] ?? '')), dinheiro((string)($in['valor_sobreaviso'] ?? ''))];
    $q = db()->prepare('SELECT id FROM rh_funcionarios WHERE nome = ? AND id <> ?');
    $q->execute([$d[0], $id]);
    if ($q->fetchColumn()) throw new RuntimeException("Já tem um funcionário chamado {$d[0]}.");
    if ($id) {
        if (!rh_funcionario($id)) throw new RuntimeException('Esse funcionário não existe mais.');
        db()->prepare('UPDATE rh_funcionarios SET nome = ?, cpf = ?, funcao = ?, valor_hora = ?, valor_sobreaviso = ? WHERE id = ?')->execute([...$d, $id]);
        return $id;
    }
    db()->prepare('INSERT INTO rh_funcionarios (nome, cpf, funcao, valor_hora, valor_sobreaviso) VALUES (?,?,?,?,?)')->execute($d);
    return (int)db()->lastInsertId();
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
        if ($unit === '') $unit = $tipo === 'horas' ? $f['valor_hora'] : $f['valor_sobreaviso'];
        if ($unit === '' || (float)$unit <= 0) throw new RuntimeException('Preencha o valor ' . ($tipo === 'horas' ? 'da hora' : 'por dia') . ' (ou cadastre no funcionário).');
        $valor = number_format(round($qtd * (float)$unit, 2), 2, '.', '');
    } else {
        $qtd = 1.0;
        $unit = '';
        $valor = dinheiro((string)($in['valor'] ?? ''));
        if ($valor === '' || (float)$valor <= 0) throw new RuntimeException('Preencha o valor.');
        if ($desc === '') throw new RuntimeException('Escreva a descrição (vai no recibo).');
    }
    db()->prepare('INSERT INTO rh_lancamentos (funcionario_id, mes, data, tipo, qtd, unitario, valor, descricao, lancado_por) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$f['id'], $mes, $data, $tipo, $qtd, $unit, $valor, $desc, $quem]);
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

/** Lançamentos do mês, com nome do funcionário, quem lançou e se já foi pago. */
function rh_do_mes(string $mes, int $fid = 0, string $tipo = ''): array
{
    $sql = "SELECT l.*, f.nome, f.cpf, f.funcao, COALESCE(g.nome, l.lancado_por) AS lancado_nome, COALESCE(p.pago_em, '') AS pago_em
        FROM rh_lancamentos l JOIN rh_funcionarios f ON f.id = l.funcionario_id
        LEFT JOIN gestores g ON g.usuario = l.lancado_por
        LEFT JOIN rh_pagamentos p ON p.funcionario_id = l.funcionario_id AND p.mes = l.mes
        WHERE l.mes = ?";
    $par = [$mes];
    if ($fid) { $sql .= ' AND l.funcionario_id = ?'; $par[] = $fid; }
    if (isset(RH_TIPOS[$tipo])) { $sql .= ' AND l.tipo = ?'; $par[] = $tipo; }
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
function rh_totais(string $mes, array $soIds = []): array
{
    $out = [];
    foreach (rh_do_mes($mes) as $l) {
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
