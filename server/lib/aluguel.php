<?php
/**
 * Controle de aluguel: imóveis alugados pela Sidnet (por enquanto a loja), com o contrato, o reajuste anual
 * pelo IPCA e o gasto com reforma. Mostra ano a ano quanto já foi pago e quanto falta até o fim do contrato,
 * andando sozinho conforme os meses passam (mês com vencimento já passado conta como pago).
 *
 * Reajuste: no aniversário do contrato o aluguel sobe o IPCA acumulado dos 12 meses anteriores
 * (ex.: abril/2027 usa abril/2026 a março/2027). O IPCA é digitado ou buscado no Banco Central (série 433);
 * ano ainda sem IPCA usa o IPCA estimado do cadastro e aparece como "estimado".
 *
 * Reforma, dois jeitos:
 *  - diluir: a Sidnet pagou a reforma; o valor é dividido pelos meses do contrato (custo da loja por mês).
 *  - abate: o dono do imóvel desconta a reforma do aluguel até somar o valor gasto. Sem valor por mês, o desconto
 *    é o aluguel inteiro (já corrigido pelo IPCA): a loja não paga aluguel até a reforma acabar.
 * Só o admin vê, até ele liberar em Configurações → Permissões.
 */
declare(strict_types=1);

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/comissoes.php'; // dinheiro(), brl(), mes_nome(), mes_soma()
require_once __DIR__ . '/rh.php';        // rh_rs()

const ALUGUEL_REFORMA_MODOS = [
    'diluir' => 'A Sidnet pagou: dividir pelos meses do contrato',
    'abate' => 'O dono desconta do aluguel até acabar o valor',
];

function aluguel_tabela(): void
{
    db()->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS aluguel_contratos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL,
        inicio TEXT NOT NULL,                  -- "2026-04" (primeiro mês de aluguel)
        meses INTEGER NOT NULL DEFAULT 60,
        dia_venc INTEGER NOT NULL DEFAULT 10,
        valor TEXT NOT NULL DEFAULT '',        -- aluguel do primeiro ano
        reforma TEXT NOT NULL DEFAULT '',
        reforma_modo TEXT NOT NULL DEFAULT 'diluir',
        reforma_abate TEXT NOT NULL DEFAULT '',-- abate por mês (vazio = o aluguel inteiro do mês)
        ipca_est TEXT NOT NULL DEFAULT '4.50', -- % usado nos anos que ainda não têm IPCA
        obs TEXT NOT NULL DEFAULT ''
    );
    CREATE TABLE IF NOT EXISTS aluguel_ipca (
        contrato_id INTEGER NOT NULL REFERENCES aluguel_contratos(id),
        ano INTEGER NOT NULL,                  -- 2 = primeiro reajuste
        pct TEXT NOT NULL,
        origem TEXT NOT NULL DEFAULT 'digitado',
        PRIMARY KEY (contrato_id, ano)
    );
    SQL);
}

function aluguel_contratos(): array
{
    $r = [];
    foreach (db()->query('SELECT * FROM aluguel_contratos ORDER BY id') as $c) $r[(int)$c['id']] = $c;
    return $r;
}

/** IPCA de cada reajuste: ano => ['pct' => '4.83', 'origem' => 'bc'] */
function aluguel_ipcas(int $cid): array
{
    $q = db()->prepare('SELECT ano, pct, origem FROM aluguel_ipca WHERE contrato_id = ?');
    $q->execute([$cid]);
    $r = [];
    foreach ($q as $l) $r[(int)$l['ano']] = $l;
    return $r;
}

/** "4,83" / "4.83%" -> "4.83"; vazio se não for número */
function aluguel_pct(string $v): string
{
    $v = str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', $v) ?? '');
    return is_numeric($v) ? number_format((float)$v, 2, '.', '') : '';
}

function aluguel_salvar(array $d, int $id): int
{
    $nome = mb_substr(trim((string)($d['nome'] ?? '')), 0, 80);
    if ($nome === '') throw new RuntimeException('Dê um nome ao imóvel (ex.: Loja do centro).');
    $inicio = (string)($d['inicio'] ?? '');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $inicio)) throw new RuntimeException('Mês de início inválido.');
    $meses = (int)($d['meses'] ?? 0);
    if ($meses < 1 || $meses > 240) throw new RuntimeException('Prazo do contrato inválido (de 1 a 240 meses).');
    $dia = max(1, min(28, (int)($d['dia_venc'] ?? 10)));
    $valor = dinheiro((string)($d['valor'] ?? ''));
    if ($valor === '' || (float)$valor <= 0) throw new RuntimeException('Coloque o valor do aluguel do primeiro ano.');
    $reforma = dinheiro((string)($d['reforma'] ?? ''));
    $modo = isset(ALUGUEL_REFORMA_MODOS[$d['reforma_modo'] ?? '']) ? $d['reforma_modo'] : 'diluir';
    $abate = $modo === 'abate' ? dinheiro((string)($d['reforma_abate'] ?? '')) : '';
    $est = aluguel_pct((string)($d['ipca_est'] ?? '')) ?: '4.50';
    $obs = mb_substr(trim((string)($d['obs'] ?? '')), 0, 300);
    $v = [$nome, $inicio, $meses, $dia, $valor, $reforma, $modo, $abate, $est, $obs];
    if ($id) {
        db()->prepare('UPDATE aluguel_contratos SET nome=?, inicio=?, meses=?, dia_venc=?, valor=?, reforma=?, reforma_modo=?, reforma_abate=?, ipca_est=?, obs=? WHERE id=?')->execute([...$v, $id]);
        return $id;
    }
    db()->prepare('INSERT INTO aluguel_contratos (nome, inicio, meses, dia_venc, valor, reforma, reforma_modo, reforma_abate, ipca_est, obs) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute($v);
    return (int)db()->lastInsertId();
}

/** Grava o IPCA digitado de cada reajuste (vazio apaga e volta a usar o estimado). Devolve quantos ficaram. */
function aluguel_ipca_salvar(int $cid, array $pcts): int
{
    $n = 0;
    foreach ($pcts as $ano => $v) {
        $ano = (int)$ano;
        if ($ano < 2) continue;
        $p = aluguel_pct((string)$v);
        if ($p === '') {
            db()->prepare('DELETE FROM aluguel_ipca WHERE contrato_id = ? AND ano = ?')->execute([$cid, $ano]);
            continue;
        }
        $ant = aluguel_ipcas($cid)[$ano] ?? null;
        $origem = $ant && $ant['pct'] === $p ? $ant['origem'] : 'digitado'; // mexeu no número: passa a ser digitado
        db()->prepare('INSERT OR REPLACE INTO aluguel_ipca (contrato_id, ano, pct, origem) VALUES (?,?,?,?)')->execute([$cid, $ano, $p, $origem]);
        $n++;
    }
    return $n;
}

/** Meses (YYYY-MM) que entram no reajuste do ano $ano: os 12 meses antes do aniversário. */
function aluguel_janela_ipca(array $c, int $ano): array
{
    $fim = mes_soma($c['inicio'], 12 * ($ano - 1) - 1);
    return [mes_soma($fim, -11), $fim];
}

/**
 * Busca no Banco Central (SGS série 433, IPCA mensal) o IPCA acumulado de cada reajuste que já tem os 12 meses
 * publicados e ainda não foi digitado à mão. Devolve [ano => pct] do que gravou.
 */
function aluguel_ipca_bc(array $c): array
{
    $cid = (int)$c['id'];
    $tem = aluguel_ipcas($cid);
    $anos = (int)ceil((int)$c['meses'] / 12);
    $feito = [];
    for ($ano = 2; $ano <= $anos; $ano++) {
        if (($tem[$ano]['origem'] ?? '') === 'digitado') continue;
        [$de, $ate] = aluguel_janela_ipca($c, $ano);
        if ($ate >= date('Y-m')) break; // o mês ainda não acabou: o IPCA dele não saiu
        $url = 'https://api.bcb.gov.br/dados/serie/bcdata.sgs.433/dados?formato=json&dataInicial=01/' . substr($de, 5, 2) . '/' . substr($de, 0, 4)
            . '&dataFinal=28/' . substr($ate, 5, 2) . '/' . substr($ate, 0, 4);
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'header' => "Accept: application/json\r\n"]]);
        $js = @file_get_contents($url, false, $ctx);
        if ($js === false) throw new RuntimeException('Não consegui falar com o Banco Central. Tente de novo mais tarde ou digite o IPCA.');
        $dados = json_decode($js, true);
        if (!is_array($dados) || count($dados) < 12) continue; // ainda não publicou os 12 meses
        $f = 1.0;
        foreach (array_slice($dados, -12) as $m) $f *= 1 + (float)str_replace(',', '.', (string)$m['valor']) / 100;
        $p = number_format(($f - 1) * 100, 2, '.', '');
        db()->prepare('INSERT OR REPLACE INTO aluguel_ipca (contrato_id, ano, pct, origem) VALUES (?,?,?,?)')->execute([$cid, $ano, $p, 'bc']);
        $feito[$ano] = $p;
    }
    return $feito;
}

/**
 * Conta tudo do contrato até $hoje (Y-m-d).
 * meses: cada mês com aluguel, abate da reforma, quanto sai do caixa e se já venceu.
 * anos: um por ano do contrato, com o IPCA usado e os totais.
 */
function aluguel_calculo(array $c, ?string $hoje = null): array
{
    $hoje ??= date('Y-m-d');
    $ipcas = aluguel_ipcas((int)$c['id']);
    $meses = (int)$c['meses'];
    $reforma = (float)($c['reforma'] ?: 0);
    $abate = $c['reforma_modo'] === 'abate';
    $abMes = $abate ? (float)($c['reforma_abate'] ?: 0) : 0.0; // 0 = desconta o aluguel inteiro
    $dilui = $reforma / $meses; // no modo diluir: quanto da reforma "vale" cada mês
    $dia = str_pad((string)(int)$c['dia_venc'], 2, '0', STR_PAD_LEFT);
    $valor = (float)$c['valor'];
    $anos = [];
    $lista = [];
    $abatido = 0.0;
    for ($n = 0; $n < $meses; $n++) {
        $ano = intdiv($n, 12) + 1;
        if (!isset($anos[$ano])) {
            $pct = null;
            $origem = '';
            if ($ano > 1) {
                if (isset($ipcas[$ano])) { $pct = (float)$ipcas[$ano]['pct']; $origem = $ipcas[$ano]['origem']; }
                else { $pct = (float)$c['ipca_est']; $origem = 'estimado'; }
                $valor = round($valor * (1 + $pct / 100), 2);
            }
            $anos[$ano] = ['ano' => $ano, 'de' => mes_soma($c['inicio'], $n), 'ate' => mes_soma($c['inicio'], min($n + 11, $meses - 1)),
                'pct' => $pct, 'origem' => $origem, 'valor' => $valor, 'meses' => 0, 'pagos' => 0,
                'aluguel' => 0.0, 'abate' => 0.0, 'caixa' => 0.0, 'pago' => 0.0, 'falta' => 0.0, 'reforma' => 0.0, 'reforma_ok' => 0.0];
        }
        $ym = mes_soma($c['inicio'], $n);
        $venc = $ym . '-' . $dia;
        $passou = $venc <= $hoje;
        $ab = $abate ? min($abMes ?: $valor, $valor, max(0, $reforma - $abatido)) : 0.0;
        $abatido += $ab;
        $caixa = $valor - $ab;
        $ref = $abate ? $ab : $dilui;
        $a = &$anos[$ano];
        $a['meses']++;
        $a['aluguel'] += $valor;
        $a['abate'] += $ab;
        $a['caixa'] += $caixa;
        $a['reforma'] += $ref;
        if ($passou) { $a['pagos']++; $a['pago'] += $caixa; $a['reforma_ok'] += $ref; } else $a['falta'] += $caixa;
        unset($a);
        $lista[] = ['n' => $n + 1, 'ym' => $ym, 'venc' => $venc, 'ano' => $ano, 'valor' => $valor, 'abate' => $ab, 'caixa' => $caixa, 'reforma' => $ref, 'passou' => $passou,
            'saldo_reforma' => $abate ? max(0, $reforma - $abatido) : null];
    }
    foreach ($anos as &$a) {
        $a['situacao'] = $a['pagos'] === $a['meses'] ? 'pago' : ($a['pagos'] > 0 ? 'andamento' : 'futuro');
        $a['estimado'] = $a['origem'] === 'estimado';
    }
    unset($a);
    $tot = fn(string $k) => array_sum(array_column($anos, $k));
    $pagos = count(array_filter($lista, fn($m) => $m['passou']));
    $atual = $lista[max(0, min($meses - 1, $pagos))] ?? null; // próximo mês a vencer (ou o último, se acabou)
    $comAbate = array_values(array_filter($lista, fn($m) => $m['abate'] > 0.004));
    $ultAbate = $comAbate ? end($comAbate) : null;           // último mês com desconto da reforma
    $primPago = null;                                         // primeiro mês em que a loja paga aluguel de verdade
    foreach ($lista as $m) if ($m['caixa'] > 0.004) { $primPago = $m; break; }
    $proxIpca = null; // próximo reajuste ainda sem IPCA de verdade
    foreach ($anos as $a) if ($a['estimado']) { $proxIpca = $a; break; }
    return [
        'meses' => $lista, 'anos' => $anos, 'pagos' => $pagos, 'total' => $meses,
        'pago' => $tot('pago'), 'falta' => $tot('falta'), 'contrato' => $tot('caixa'), 'aluguel' => $tot('aluguel'),
        'reforma' => $reforma, 'reforma_ok' => $tot('reforma_ok'), 'abate_mes' => $abMes, 'dilui_mes' => $dilui,
        'agora' => $atual, 'fim' => mes_soma($c['inicio'], $meses - 1), 'prox_ipca' => $proxIpca,
        'tem_estimado' => (bool)$proxIpca,
        'reforma_meses' => count($comAbate), 'reforma_ult' => $ultAbate, 'prim_pago' => $primPago,
        'reforma_sobra' => $abate ? max(0, $reforma - array_sum(array_column($lista, 'abate'))) : 0.0,
    ];
}

/** "2026-04" -> "abr/2026" */
function aluguel_mes_curto(string $ym): string
{
    return mb_substr(MESES_PT[(int)substr($ym, 5, 2)], 0, 3) . '/' . substr($ym, 0, 4);
}

function aluguel_pct_txt(?float $p): string
{
    return $p === null ? '—' : number_format($p, 2, ',', '.') . '%';
}
