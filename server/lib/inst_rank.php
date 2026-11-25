<?php
/**
 * Melhores técnicos (pedido do ALEX, 2026-11-25): ranking dos técnicos com os dados que o portal já tem.
 * - Instalações concluídas (lançadas ou puxadas do IXC, assuntos 11 e 31): quantas cada técnico fez, por tipo de serviço,
 *   em dupla e como ajudante (dupla conta para os dois, como na comissão).
 * - Análise da instalação: O.S do cliente nos dias de observação. "Retorno" = teve O.S que conta e o gestor não decidiu
 *   "pagar" (falta decidir ou "não pagar"); quando o gestor decide "pagar", a O.S não foi culpa da instalação e não pesa.
 * - Comissão da instalação de cada técnico no período.
 * - Recolhimentos feitos (O.S de recolhimento, mesmo nome do técnico), só para mostrar.
 * Pontos = 1 por instalação − 2 por retorno (o retorno devolve o ponto da instalação e perde mais 1).
 */
declare(strict_types=1);

require_once __DIR__ . '/instalacao.php';

const RANK_PESO_RETORNO = 2;

/** Meses do período: 'aaaa-mm' (um mês), '3m' / '6m' (até o mês atual). */
function rank_meses(string $periodo): array
{
    if (preg_match('/^(\d)m$/', $periodo, $m)) return array_map(fn($i) => date('Y-m', strtotime(date('Y-m-01') . " -$i month")), range((int)$m[1] - 1, 0, -1));
    return [$periodo];
}

/**
 * Números de cada técnico nas instalações das linhas (já com 'tecs' e 'an'):
 * [tecnico_id => ['id', 'nome', 'ativo', 'inst', 'serv' => [nome => n], 'dupla', 'ajudante', 'aprovada', 'com_os', 'pagar', 'retorno',
 *  'nao_pagar', 'obs', 'fora', 'valor', 'meses' => [aaaa-mm => n], 'rec', 'finais', 'sem_ret' (0..1 ou null), 'pontos']]
 */
function rank_tecnicos(array $linhas): array
{
    $ativos = array_column(inst_tecnicos(), 'ativo', 'id');
    $out = [];
    foreach ($linhas as $r) {
        $sit = $r['an']['sit'] ?? 'fora';
        $ajId = ($r['ajudante'] ?? '') === 'S' ? (int)$r['ajudante_id'] : 0;
        $mes = substr((string)$r['fechamento'], 0, 7);
        foreach ($r['tecs'] as $t) {
            $o = &$out[$t['id']];
            $o ??= ['id' => $t['id'], 'nome' => $t['nome'], 'ativo' => (int)($ativos[$t['id']] ?? 1), 'inst' => 0, 'serv' => [], 'dupla' => 0, 'ajudante' => 0,
                'aprovada' => 0, 'com_os' => 0, 'pagar' => 0, 'retorno' => 0, 'nao_pagar' => 0, 'obs' => 0, 'fora' => 0, 'valor' => 0.0, 'meses' => [], 'rec' => 0];
            $o['inst']++;
            $sv = $r['servico'] !== '' ? $r['servico'] : 'SEM TIPO';
            $o['serv'][$sv] = ($o['serv'][$sv] ?? 0) + 1;
            $o['meses'][$mes] = ($o['meses'][$mes] ?? 0) + 1;
            if (count($r['tecs']) > 1) $o['dupla']++;
            if ($ajId === (int)$t['id']) $o['ajudante']++;
            if ($sit === 'aprovada') $o['aprovada']++;
            elseif (in_array($sit, ['analisar', 'pagar', 'nao_pagar'], true)) {
                $o['com_os']++;
                if ($sit === 'pagar') $o['pagar']++; else $o['retorno']++;
                if ($sit === 'nao_pagar') $o['nao_pagar']++;
            } elseif ($sit === 'fora') $o['fora']++;
            else $o['obs']++;
            $o['valor'] += (float)($t['valor'] ?: 0);
            unset($o);
        }
    }
    foreach ($out as &$o) {
        $o['finais'] = $o['aprovada'] + $o['com_os'];
        $o['sem_ret'] = $o['finais'] ? ($o['finais'] - $o['retorno']) / $o['finais'] : null;
        $o['pontos'] = $o['inst'] - RANK_PESO_RETORNO * $o['retorno'];
        arsort($o['serv']);
    }
    unset($o);
    return $out;
}

/** Recolhimentos feitos no período por técnico do ranking (pelo nome, sem acento): [tecnico_id => n] */
function rank_recolhimentos(array $meses, array $tecs): array
{
    $chave = [];
    foreach ($tecs as $id => $t) $chave[rec_nome_chave($t['nome'])] = $id;
    $out = [];
    foreach ($meses as $m) foreach (rec_do_mes($m) as $r) {
        $id = $chave[rec_nome_chave((string)$r['tecnico'])] ?? null;
        if ($id !== null) $out[$id] = ($out[$id] ?? 0) + 1;
    }
    return $out;
}

/** Ordena: 'pontos' (geral), 'inst' (mais instalações), 'qual' (menos retorno), 'valor' (comissão). */
function rank_ordenar(array $tecs, string $por): array
{
    $tecs = array_values($tecs);
    usort($tecs, match ($por) {
        'inst' => fn($a, $b) => [$b['inst'], $a['retorno'], $a['nome']] <=> [$a['inst'], $b['retorno'], $b['nome']],
        'qual' => fn($a, $b) => [$a['sem_ret'] === null, $b['sem_ret'] ?? 0, $b['finais'], $a['nome']] <=> [$b['sem_ret'] === null, $a['sem_ret'] ?? 0, $a['finais'], $b['nome']],
        'valor' => fn($a, $b) => [$b['valor'], $b['inst'], $a['nome']] <=> [$a['valor'], $a['inst'], $b['nome']],
        default => fn($a, $b) => [$b['pontos'], $b['inst'], $a['retorno'], $a['nome']] <=> [$a['pontos'], $a['inst'], $b['retorno'], $b['nome']],
    });
    return $tecs;
}
