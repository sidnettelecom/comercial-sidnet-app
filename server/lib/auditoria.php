<?php
/**
 * Auditoria dos recibos: o sistema confere, mês a mês e pessoa por pessoa, se o que vai no recibo
 * já foi analisado (O.S conferidas, comissões com valor), fechado (marcado como pago na área),
 * se o recibo foi gerado com o valor de hoje e se a conta a pagar foi lançada no IXC.
 * O que falta fica como pendência, com o link da tela onde se resolve.
 * Quando o mês não tem mais pendência, quem audita marca o mês como auditado.
 */
declare(strict_types=1);

require_once __DIR__ . '/recibos.php';
require_once __DIR__ . '/recibos_ixc.php';

/** etapas na ordem em que acontecem */
const AUDIT_ETAPAS = ['analisar' => 'Analisar', 'fechar' => 'Fechar (pagar)', 'gerar' => 'Gerar recibo', 'ixc' => 'Lançar no IXC', 'cpf' => 'CPF'];
/** quantos meses para trás a auditoria olha (além do mês atual) */
const AUDIT_MESES = 12;

function audit_tabela(): void
{
    db()->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS recibos_gerados (
        mes TEXT NOT NULL,
        chave TEXT NOT NULL,
        nome TEXT NOT NULL,
        valor TEXT NOT NULL,
        origens TEXT NOT NULL DEFAULT '',
        gerado_em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        gerado_por TEXT NOT NULL DEFAULT '',
        PRIMARY KEY (mes, chave)
    );
    CREATE TABLE IF NOT EXISTS recibos_auditoria (
        mes TEXT PRIMARY KEY,
        valor TEXT NOT NULL,
        pessoas INTEGER NOT NULL,
        obs TEXT NOT NULL DEFAULT '',
        feito_em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        feito_por TEXT NOT NULL DEFAULT ''
    );
    SQL);
}

/** Anota os recibos que acabaram de ser gerados (tela Recibos → Gerar). O último gerado vale. */
function audit_gerados_gravar(string $mes, array $recibos, string $quem): void
{
    $q = db()->prepare("INSERT INTO recibos_gerados (mes, chave, nome, valor, origens, gerado_por) VALUES (?,?,?,?,?,?)
        ON CONFLICT(mes, chave) DO UPDATE SET nome = excluded.nome, valor = excluded.valor, origens = excluded.origens,
        gerado_em = datetime('now','localtime'), gerado_por = excluded.gerado_por");
    foreach ($recibos as $r) $q->execute([$mes, $r['chave'], $r['nome'], number_format((float)$r['tot'], 2, '.', ''), implode(',', $r['origens']), $quem]);
}

/** [chave => linha de recibos_gerados] do mês */
function audit_gerados(string $mes): array
{
    $q = db()->prepare('SELECT * FROM recibos_gerados WHERE mes = ?');
    $q->execute([$mes]);
    $out = [];
    foreach ($q as $r) $out[$r['chave']] = $r;
    return $out;
}

function audit_assinado(string $mes): ?array
{
    $q = db()->prepare('SELECT * FROM recibos_auditoria WHERE mes = ?');
    $q->execute([$mes]);
    return $q->fetch() ?: null;
}

/**
 * O que ainda não foi analisado no mês, por pessoa: [chave => ['nome' => ..., 'itens' => [[origem, texto], ...]]]
 * Vendas: comissão sem valor (se o cliente não deixou de pagar) e venda esperando o cliente pagar o 1º boleto.
 * Recolhimento e instalação: O.S lançadas e ainda não conferidas (vale para o técnico e para quem lançou/agendou).
 */
function audit_analisar(string $mes, array $origens): array
{
    $cont = [];
    $add = function (string $nome, string $o, string $texto) use (&$cont) {
        if (trim($nome) === '') return;
        $k = recibo_chave($nome);
        $cont[$k]['nome'] ??= up($nome);
        $cont[$k]['n'][$o . '|' . $texto] = ($cont[$k]['n'][$o . '|' . $texto] ?? 0) + 1;
    };
    if (in_array('vendas', $origens, true)) {
        foreach (comissoes_do_mes($mes) as $r) {
            if ($r['pago_em'] !== '' || $r['cliente_pagou'] === 'N') continue;
            if ($r['valor'] === '') $add((string)$r['vendedor'], 'vendas', 'venda(s) sem valor de comissão');
            elseif ($r['cliente_pagou'] !== 'S') $add((string)$r['vendedor'], 'vendas', 'venda(s) esperando o cliente pagar o 1º boleto');
        }
    }
    if (in_array('rec', $origens, true)) {
        foreach (rec_do_mes($mes) as $r) {
            if ($r['conferido'] || $r['pago_em'] !== '') continue;
            $add((string)$r['tecnico'], 'rec', 'O.S de recolhimento sem conferir');
            $add((string)$r['lancado_nome'], 'rec', 'O.S de recolhimento sem conferir');
        }
    }
    if (in_array('inst', $origens, true)) {
        foreach (inst_do_mes($mes) as $r) {
            if ($r['conferido'] || $r['pago_em'] !== '') continue;
            foreach ($r['tecs'] as $t) $add((string)$t['nome'], 'inst', 'O.S de instalação sem conferir');
            if ($r['agendador_id'] && $r['agendador'] !== '') $add((string)$r['agendador'], 'inst', 'O.S de instalação sem conferir');
        }
    }
    $out = [];
    foreach ($cont as $k => $c) {
        foreach ($c['n'] as $ot => $n) {
            [$o, $t] = explode('|', $ot, 2);
            $out[$k]['nome'] = $c['nome'];
            $out[$k]['itens'][] = [$o, $n . ' ' . $t];
        }
    }
    return $out;
}

/**
 * Auditoria de um mês: uma linha por pessoa com a situação de cada etapa.
 * ['mes', 'pessoas' => [chave => [..., 'et' => [etapa => ['ok' => bool|null, 'txt' => ..., 'link' => [[rótulo, url]]]], 'pend' => n]],
 *  'cont' => [etapa => pessoas pendentes], 'pend' => total de pendências, 'tot', 'apagar', 'assinado', 'mudou']
 * 'ok' => null quer dizer que a etapa não se aplica (nada a gerar, IXC desligado...).
 */
function audit_mes(string $mes, array $origens, bool $ixc): array
{
    $partes = recibos_partes($mes, $origens);
    $cpfs = recibos_cpfs();
    $pessoas = recibos_por_pessoa($partes, $cpfs);
    $analisar = audit_analisar($mes, $origens);
    $gerados = audit_gerados($mes);
    if ($ixc) { $cfgX = rixc_config(); $lancX = rixc_lancados($mes); $grX = rixc_grupos_pessoas(); }
    // quanto falta pagar por pessoa e em quais áreas
    $aberto = [];
    foreach ($partes as $x) {
        if ($x['pago']) continue;
        $aberto[$x['chave']]['v'] = ($aberto[$x['chave']]['v'] ?? 0) + $x['tot'];
        $aberto[$x['chave']]['o'][$x['o']] = true;
    }
    foreach ($analisar as $k => $a) {
        $pessoas[$k] ??= ['chave' => $k, 'nome' => $a['nome'], 'cpf' => $cpfs[$k] ?? '', 'origens' => [], 'ls' => [], 'tot' => 0.0, 'pago' => 'nao'];
        foreach ($a['itens'] as [$o]) if (!in_array($o, $pessoas[$k]['origens'], true)) $pessoas[$k]['origens'][] = $o;
    }
    $pagAnalisar = ['vendas' => 'comissoes', 'rec' => 'rec_comissao', 'inst' => 'inst_comissao'];
    $pagFechar = ['vendas' => 'comissoes', 'rec' => 'rec_comissao', 'inst' => 'inst_comissao', 'rh' => 'rh_fecha'];
    $url = fn(string $p) => '?p=' . $p . '&mes=' . $mes;
    $cont = array_fill_keys(array_keys(AUDIT_ETAPAS), 0);
    $tot = $apagar = 0.0;
    foreach ($pessoas as $k => &$r) {
        $et = [];
        $its = $analisar[$k]['itens'] ?? [];
        $et['analisar'] = $its
            ? ['ok' => false, 'txt' => implode(' · ', array_column($its, 1)), 'link' => array_values(array_unique(array_map(fn($i) => [RECIBO_ORIGENS[$i[0]], $url($pagAnalisar[$i[0]])], $its), SORT_REGULAR))]
            : ['ok' => true, 'txt' => 'tudo conferido', 'link' => []];
        $ab = $aberto[$k] ?? null;
        $et['fechar'] = $r['tot'] <= 0 ? ['ok' => null, 'txt' => 'nada a pagar ainda', 'link' => []]
            : ($ab ? ['ok' => false, 'txt' => 'falta marcar pago ' . rh_rs($ab['v']), 'link' => array_map(fn($o) => [RECIBO_ORIGENS[$o], $url($pagFechar[$o])], array_keys(array_intersect_key($ab['o'], $pagFechar)))]
                : ['ok' => true, 'txt' => 'pago', 'link' => []]);
        $g = $gerados[$k] ?? null;
        $et['gerar'] = $r['tot'] <= 0 ? ['ok' => null, 'txt' => '—', 'link' => []]
            : (!$g ? ['ok' => false, 'txt' => 'recibo não gerado', 'link' => [['Recibos', $url('recibos')]]]
                : (abs((float)$g['valor'] - $r['tot']) > 0.009
                    ? ['ok' => false, 'txt' => 'gerado com ' . rh_rs((float)$g['valor']) . ', hoje é ' . rh_rs($r['tot']) . ': gerar de novo', 'link' => [['Recibos', $url('recibos')]]]
                    : ['ok' => true, 'txt' => 'gerado ' . audit_quando($g['gerado_em']) . ($g['gerado_por'] !== '' ? ' por ' . $g['gerado_por'] : ''), 'link' => []]));
        if (!$ixc || $r['tot'] <= 0) {
            $et['ixc'] = ['ok' => null, 'txt' => $ixc ? '—' : 'desligado', 'link' => []];
        } else {
            [$sx, $ids] = rixc_situacao($r, $lancX, $cfgX, $grX);
            $et['ixc'] = $sx === 'sim' ? ['ok' => true, 'txt' => 'lançado ' . implode(', ', array_map(fn($i) => '#' . $i, $ids)), 'link' => []]
                : ['ok' => false, 'txt' => $sx === 'dif' ? 'o recibo mudou depois de lançado' : 'não lançado', 'link' => [['Recibos', $url('recibos')]]];
        }
        $et['cpf'] = $r['tot'] <= 0 ? ['ok' => null, 'txt' => '—', 'link' => []]
            : ($r['cpf'] !== '' ? ['ok' => true, 'txt' => rh_cpf($r['cpf']), 'link' => []] : ['ok' => false, 'txt' => 'sem CPF', 'link' => [['Recibos', $url('recibos')]]]);
        $r['et'] = $et;
        $r['pend'] = count(array_filter($et, fn($e) => $e['ok'] === false));
        foreach ($et as $e => $x) if ($x['ok'] === false) $cont[$e]++;
        $tot += $r['tot'];
        $apagar += $ab['v'] ?? 0;
    }
    unset($r);
    // quem tem pendência primeiro, depois pelo nome
    uasort($pessoas, fn($a, $b) => [$b['pend'] > 0, $a['nome']] <=> [$a['pend'] > 0, $b['nome']]);
    $ass = audit_assinado($mes);
    return ['mes' => $mes, 'pessoas' => $pessoas, 'cont' => $cont, 'pend' => array_sum($cont), 'npend' => count(array_filter($pessoas, fn($r) => $r['pend'] > 0)),
        'tot' => $tot, 'apagar' => $apagar, 'assinado' => $ass,
        'mudou' => $ass && abs((float)$ass['valor'] - $tot) > 0.009];
}

/** Situação do mês: ['pend'|'livre'|'ok'|'mudou'|'andamento', texto] */
function audit_situacao(array $a): array
{
    if ($a['assinado'] && !$a['mudou']) return ['ok', 'auditado'];
    if ($a['assinado']) return ['mudou', 'mudou depois da auditoria'];
    if ($a['mes'] >= date('Y-m')) return ['andamento', 'mês em andamento'];
    if ($a['pend']) return ['pend', 'pendente'];
    return ['livre', 'pronto para auditar'];
}

/** Auditoria dos últimos meses (mais novo primeiro), só os meses que têm alguém a receber ou algo a analisar. */
function audit_meses(array $origens, bool $ixc): array
{
    $out = [];
    for ($i = 0; $i <= AUDIT_MESES; $i++) {
        $m = mes_soma(date('Y-m'), -$i);
        $a = audit_mes($m, $origens, $ixc);
        if ($a['pessoas'] || $a['assinado']) $out[$m] = $a;
    }
    return $out;
}

/** Marca o mês como auditado (só sem pendência). */
function audit_assinar(string $mes, array $origens, bool $ixc, string $obs, string $quem): void
{
    $a = audit_mes($mes, $origens, $ixc);
    if ($a['pend']) throw new RuntimeException(mes_nome($mes) . ' ainda tem ' . $a['pend'] . ' pendência(s). Resolva antes de marcar como auditado.');
    if ($mes >= date('Y-m')) throw new RuntimeException('O mês ainda não terminou. Audite a partir do dia 1º do mês que vem.');
    db()->prepare("INSERT INTO recibos_auditoria (mes, valor, pessoas, obs, feito_por) VALUES (?,?,?,?,?)
        ON CONFLICT(mes) DO UPDATE SET valor = excluded.valor, pessoas = excluded.pessoas, obs = excluded.obs, feito_em = datetime('now','localtime'), feito_por = excluded.feito_por")
        ->execute([$mes, number_format($a['tot'], 2, '.', ''), count($a['pessoas']), mb_substr(trim($obs), 0, 300), $quem]);
}

function audit_desfazer(string $mes): void
{
    db()->prepare('DELETE FROM recibos_auditoria WHERE mes = ?')->execute([$mes]);
}

/** "2026-11-21 10:05:00" -> "21/11 10:05" */
function audit_quando(string $s): string
{
    $t = strtotime($s);
    return $t ? date('d/m/Y H:i', $t) : $s;
}
