<?php
/**
 * Recibos num lugar só: comissão de vendas, recolhimento, instalação e RH.
 * Um recibo por pessoa no mês, com tudo somado. Uma via, 4 por folha A4.
 * A pessoa é a mesma quando o nome é igual (sem acento, maiúsculo) em mais de uma origem.
 */
declare(strict_types=1);

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/ixc.php'; // sem_acento()
require_once __DIR__ . '/comissoes.php';
require_once __DIR__ . '/recolhimento.php';
require_once __DIR__ . '/instalacao.php';
require_once __DIR__ . '/rh.php';
require_once __DIR__ . '/servicos.php';

const RECIBO_ORIGENS = ['vendas' => 'Comissão de vendas', 'rec' => 'Recolhimento', 'inst' => 'Instalação', 'rh' => 'RH', 'serv' => 'Serviços em execução'];
/** como cada origem aparece no texto "referente a ..." do recibo */
const RECIBO_REFERENTE = ['vendas' => 'comissão de vendas', 'rec' => 'comissão de recolhimento', 'inst' => 'comissão de instalação', 'rh' => 'lançamentos do RH', 'serv' => 'pagamento de serviços de rede'];

/** CPF de quem recebe (vendedor, técnico, recolhedor, financeiro), pelo nome. Funcionário do RH usa o CPF do cadastro do RH. */
function recibos_tabela(): void
{
    $novo = !db()->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'recibos_cpf'")->fetchColumn();
    db()->exec("CREATE TABLE IF NOT EXISTS recibos_cpf (chave TEXT PRIMARY KEY, nome TEXT NOT NULL, cpf TEXT NOT NULL DEFAULT '')");
    // o recibo do RH saía do Fechamento: quem tinha esse acesso passa a ter Recibos → RH (uma vez só)
    if ($novo) db()->exec("UPDATE gestores SET permissoes = permissoes || ',recibos.rh' WHERE admin = 0 AND ',' || permissoes || ',' LIKE '%,rh.fecha,%'");
}

function recibo_chave(string $nome): string
{
    return preg_replace('/\s+/', ' ', up(sem_acento(trim($nome))));
}

/** [chave => cpf só dígitos]: primeiro o do RH, por cima o que foi digitado na tela Recibos */
function recibos_cpfs(): array
{
    $out = [];
    foreach (db()->query("SELECT nome, cpf FROM rh_funcionarios WHERE cpf <> ''") as $r) $out[recibo_chave($r['nome'])] = $r['cpf'];
    foreach (db()->query("SELECT chave, cpf FROM recibos_cpf WHERE cpf <> ''") as $r) $out[$r['chave']] = $r['cpf'];
    return $out;
}

/** Grava os CPFs digitados: [chave => ['nome' => ..., 'cpf' => ...]]. Devolve quantos mudaram. */
function recibos_cpf_salvar(array $lista): int
{
    $atual = recibos_cpfs();
    $up = db()->prepare('INSERT INTO recibos_cpf (chave, nome, cpf) VALUES (?,?,?) ON CONFLICT(chave) DO UPDATE SET nome = excluded.nome, cpf = excluded.cpf');
    $n = 0;
    foreach ($lista as $k => $v) {
        $k = recibo_chave((string)$k);
        $cpf = so_digitos((string)($v['cpf'] ?? ''));
        if ($k === '' || $cpf === ($atual[$k] ?? '')) continue;
        if ($cpf !== '' && strlen($cpf) !== 11) throw new RuntimeException('CPF de ' . ($v['nome'] ?? $k) . ' precisa ter 11 números.');
        $up->execute([$k, mb_substr(up((string)($v['nome'] ?? $k)), 0, 80), $cpf]);
        $n++;
    }
    return $n;
}

/**
 * Partes a receber no mês de referência, uma por pessoa/origem/situação:
 * [['chave', 'nome', 'o' => origem, 'pago' => bool, 'ls' => [[texto, valor, tipo], ...], 'tot' => float], ...]
 * Vendas: comissões com valor, já pagas ou com o cliente tendo pago o 1º boleto.
 * Recolhimento e instalação: O.S conferidas (ou pagas). RH: todos os lançamentos do mês.
 * Serviços em execução: os pagamentos feitos à equipe no mês (cada um numa linha, já pagos).
 */
function recibos_partes(string $mes, array $origens): array
{
    $p = [];
    $add = function (string $nome, string $o, bool $pago, string $texto, float $v) use (&$p) {
        if ($v <= 0 || trim($nome) === '') return;
        $k = recibo_chave($nome);
        $x = &$p[$k . '|' . $o . '|' . (int)$pago];
        $x ??= ['chave' => $k, 'nome' => up($nome), 'o' => $o, 'pago' => $pago, 'ls' => [], 'tot' => 0.0];
        $x['ls'][$texto] = ($x['ls'][$texto] ?? [0, 0.0]);
        $x['ls'][$texto][0]++;
        $x['ls'][$texto][1] += $v;
        $x['tot'] += $v;
        unset($x);
    };
    if (in_array('vendas', $origens, true)) {
        foreach (comissoes_do_mes($mes) as $r) {
            if ($r['valor'] === '' || ($r['pago_em'] === '' && $r['cliente_pagou'] !== 'S')) continue;
            $add((string)$r['vendedor'], 'vendas', $r['pago_em'] !== '', COMISSAO_TIPOS[$r['tipo']] ?? $r['tipo'], (float)$r['valor']);
        }
    }
    if (in_array('rec', $origens, true)) {
        foreach (rec_do_mes($mes) as $r) {
            if (!$r['conferido'] && $r['pago_em'] === '') continue;
            $add((string)$r['tecnico'], 'rec', $r['pago_em'] !== '', 'Recolheu (O.S)', (float)($r['valor_tec'] ?: 0));
            $add((string)$r['lancado_nome'], 'rec', $r['pago_em'] !== '', 'Lançou (O.S)', (float)($r['valor_fin'] ?: 0));
        }
    }
    if (in_array('inst', $origens, true)) {
        foreach (inst_do_mes($mes) as $r) {
            if (!$r['conferido'] && $r['pago_em'] === '') continue;
            foreach ($r['tecs'] as $t) $add((string)$t['nome'], 'inst', $r['pago_em'] !== '', count($r['tecs']) > 1 ? 'Instalou em dupla (O.S)' : 'Instalou (O.S)', (float)($t['valor'] ?: 0));
            if ($r['agendador_id'] && $r['agendador'] !== '') $add((string)$r['agendador'], 'inst', $r['pago_em'] !== '', 'Agendou (O.S)', (float)($r['valor_agenda'] ?: 0));
        }
    }
    if (in_array('rh', $origens, true)) {
        foreach (rh_totais($mes) as $r) {
            foreach ($r['ls'] as $l) $add((string)$r['f']['nome'], 'rh', $r['pago_em'] !== '', RH_TIPOS[$l['tipo']], (float)$l['valor']);
            if ($r['dsr'] > 0) $add((string)$r['f']['nome'], 'rh', $r['pago_em'] !== '', 'DSR sobre horas informais', (float)$r['dsr']);
        }
    }
    if (in_array('serv', $origens, true)) {
        foreach (serv_pagamentos_do_mes($mes) as $r) {
            $add((string)$r['nome'], 'serv', true, 'Pagamento de ' . rh_dt($r['data']) . ' (' . $r['forma'] . ')' . ($r['descricao'] !== '' ? ' · ' . $r['descricao'] : '') . '#' . $r['id'], (float)$r['valor']);
        }
    }
    // texto final de cada linha: "Venda (14)", "Horas informais 5:30 h", "Sobreaviso 2 dias"
    $horas = $dias = [];
    if (in_array('rh', $origens, true)) {
        foreach (rh_totais($mes) as $r) { $k = recibo_chave((string)$r['f']['nome']); $horas[$k] = $r['h']; $dias[$k] = $r['dias']; }
    }
    foreach ($p as &$x) {
        $ls = [];
        foreach ($x['ls'] as $t => [$n, $v]) {
            // tipo da linha (para a conta a pagar no IXC): vendas, rec, inst, serv ou rh.horas, rh.sobre, rh.compl, rh.pag, rh.dsr
            $tipo = $x['o'] === 'rh' ? 'rh.' . (array_search($t, RH_TIPOS, true) ?: 'dsr') : $x['o'];
            if ($x['o'] === 'serv') {
                $t = 'Serviços: ' . preg_replace('/#\d+$/', '', $t); // o #id só separa dois pagamentos iguais no mesmo dia
            } elseif ($x['o'] === 'rh') {
                $t .= $t === RH_TIPOS['horas'] && !empty($horas[$x['chave']]) ? ' ' . rh_hm($horas[$x['chave']]) . ' h'
                    : ($t === RH_TIPOS['sobre'] && !empty($dias[$x['chave']]) ? ' ' . rtrim(rtrim(number_format($dias[$x['chave']], 1, ',', ''), '0'), ',') . ($dias[$x['chave']] == 1 ? ' dia' : ' dias') : '');
            } else {
                $t = RECIBO_ORIGENS[$x['o']] . ': ' . str_replace('(O.S)', "($n O.S)", $x['o'] === 'vendas' ? "$t ($n)" : $t);
            }
            $ls[] = [$x['o'] === 'rh' ? 'RH: ' . $t : $t, $v, $tipo];
        }
        $x['ls'] = $ls;
    }
    unset($x);
    return array_values($p);
}

/**
 * Junta as partes por pessoa (um recibo por pessoa com tudo somado):
 * [chave => ['chave', 'nome', 'cpf', 'origens' => [o...], 'ls' => [[texto, valor]], 'tot', 'pago' => 'sim'|'nao'|'parte']]
 */
function recibos_por_pessoa(array $partes, array $cpfs): array
{
    $out = [];
    foreach ($partes as $x) {
        $r = &$out[$x['chave']];
        $r ??= ['chave' => $x['chave'], 'nome' => $x['nome'], 'cpf' => $cpfs[$x['chave']] ?? '', 'origens' => [], 'ls' => [], 'tot' => 0.0, 'npago' => 0, 'n' => 0];
        if (!in_array($x['o'], $r['origens'], true)) $r['origens'][] = $x['o'];
        array_push($r['ls'], ...$x['ls']);
        $r['tot'] += $x['tot'];
        $r['n']++;
        if ($x['pago']) $r['npago']++;
        unset($r);
    }
    foreach ($out as &$r) {
        $r['pago'] = $r['npago'] === 0 ? 'nao' : ($r['npago'] === $r['n'] ? 'sim' : 'parte');
        $ordem = array_flip(array_keys(RECIBO_ORIGENS));
        usort($r['origens'], fn($a, $b) => $ordem[$a] <=> $ordem[$b]);
    }
    unset($r);
    uasort($out, fn($a, $b) => strcmp($a['nome'], $b['nome']));
    return $out;
}
