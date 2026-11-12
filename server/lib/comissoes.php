<?php
/**
 * Comissões dos vendedores (só o gestor admin vê).
 * Regra do ALEX: a comissão é paga uma vez só. Ela fica sempre no mês da venda;
 * quando é paga, marca-se o mês do pagamento ("paga em"). Nada passa para o mês seguinte.
 */
declare(strict_types=1);

require_once __DIR__ . '/base.php';

const COMISSAO_TIPOS = ['venda' => 'Venda', 'upgrade' => 'Upgrade', 'segundo' => '2º ponto'];
const MESES_PT = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

/** "2026-11" -> "Novembro/2026" */
function mes_nome(string $ym): string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) return $ym;
    return ucfirst(MESES_PT[(int)$m[2]]) . '/' . $m[1];
}

/** "2026-11" + n meses */
function mes_soma(string $ym, int $n): string
{
    return (new DateTimeImmutable($ym . '-01'))->modify(($n >= 0 ? '+' : '') . $n . ' month')->format('Y-m');
}

/** Valor em reais digitado ("1.234,50", "R$ 50", "50.5") -> "1234.50"; vazio se não for número. */
function dinheiro(string $v): string
{
    $v = preg_replace('/[^\d,.]/', '', $v) ?? '';
    if ($v === '') return '';
    if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
    return is_numeric($v) ? number_format((float)$v, 2, '.', '') : '';
}

function brl(string $v): string
{
    return $v === '' ? '' : number_format((float)$v, 2, ',', '.');
}

/** Meses que têm vendas (mais novo primeiro), com quantas comissões ainda não foram pagas. */
function comissoes_meses(): array
{
    $sql = "SELECT strftime('%Y-%m', v.recebido_em, 'localtime') AS mes, COUNT(*) AS n,
                   SUM(CASE WHEN COALESCE(c.pago_em, '') = '' THEN 1 ELSE 0 END) AS abertas
            FROM vendas v LEFT JOIN comissoes c ON c.venda_id = v.id
            WHERE v.status <> 'descartada' AND v.tipo IN ('venda','upgrade','segundo')
            GROUP BY mes ORDER BY mes DESC";
    $out = [];
    foreach (db()->query($sql) as $r) $out[$r['mes']] = ['n' => (int)$r['n'], 'abertas' => (int)$r['abertas']];
    return $out;
}

/** Vendas do mês com a comissão de cada uma (valores padrão tirados do cadastro quando ainda não há registro). */
function comissoes_do_mes(string $mes, array $filtro = []): array
{
    $sql = "SELECT v.id, v.tipo, v.nome, v.dados, v.status, v.crm_id, v.ixc_passos, v.recebido_em, v.vendedor_id, d.nome AS vendedor,
                   c.venda_id AS tem, c.ixc_cliente, c.vencimento, c.instalacao, c.roteador, c.valor, c.cliente_pagou, c.pago_em, c.obs
            FROM vendas v JOIN vendedores d ON d.id = v.vendedor_id LEFT JOIN comissoes c ON c.venda_id = v.id
            WHERE v.status <> 'descartada' AND v.tipo IN ('venda','upgrade','segundo')
              AND strftime('%Y-%m', v.recebido_em, 'localtime') = ?";
    $args = [$mes];
    if (!empty($filtro['vend'])) { $sql .= ' AND v.vendedor_id = ?'; $args[] = (int)$filtro['vend']; }
    if (!empty($filtro['tipo']) && isset(COMISSAO_TIPOS[$filtro['tipo']])) { $sql .= ' AND v.tipo = ?'; $args[] = $filtro['tipo']; }
    $sql .= ' ORDER BY d.nome, v.recebido_em';
    $q = db()->prepare($sql);
    $q->execute($args);
    $out = [];
    foreach ($q as $r) {
        $dados = json_decode((string)$r['dados'], true) ?: [];
        $passos = json_decode((string)$r['ixc_passos'], true) ?: [];
        if ($r['tem'] === null) {
            $r['ixc_cliente'] = (string)($passos['prospect'] ?? '');
            $r['vencimento'] = '';
            $r['instalacao'] = dinheiro((string)($dados['valInst'] ?? ''));
            $r['roteador'] = dinheiro((string)($dados['valRot'] ?? ''));
            $r['valor'] = $r['cliente_pagou'] = $r['pago_em'] = $r['obs'] = '';
        }
        $r['cpf'] = (string)($dados['cpf'] ?? '');
        $r['plano'] = $r['tipo'] === 'upgrade'
            ? trim(up((string)($dados['velNova'] ?? '')) . (($dados['valNovo'] ?? '') !== '' ? ' · R$ ' . $dados['valNovo'] : ''))
            : up((string)($dados['plano'] ?? ''));
        $r['dia_venc'] = (string)($dados['venc'] ?? '');
        $pagou = $r['cliente_pagou'];
        if (!empty($filtro['pagou']) && !($filtro['pagou'] === 'S' ? $pagou === 'S' : ($filtro['pagou'] === 'N' ? $pagou === 'N' : $pagou === ''))) continue;
        if (!empty($filtro['paga']) && ($filtro['paga'] === 'sim') !== ($r['pago_em'] !== '')) continue;
        $out[] = $r;
    }
    return $out;
}

/** Grava as linhas editadas. $linhas = [venda_id => [campo => valor]] */
function comissoes_salvar(array $linhas, string $quem): int
{
    $up = db()->prepare("INSERT INTO comissoes (venda_id, ixc_cliente, vencimento, instalacao, roteador, valor, cliente_pagou, pago_em, obs, atualizado_em, por)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now','localtime'), ?)
        ON CONFLICT(venda_id) DO UPDATE SET ixc_cliente=excluded.ixc_cliente, vencimento=excluded.vencimento, instalacao=excluded.instalacao,
            roteador=excluded.roteador, valor=excluded.valor, cliente_pagou=excluded.cliente_pagou, pago_em=excluded.pago_em, obs=excluded.obs,
            atualizado_em=excluded.atualizado_em, por=excluded.por");
    $mesVenda = db()->prepare("SELECT strftime('%Y-%m', recebido_em, 'localtime') FROM vendas WHERE id = ?");
    $n = 0;
    foreach ($linhas as $id => $c) {
        if (!is_array($c) || (int)$id <= 0) continue;
        $mesVenda->execute([(int)$id]);
        $mv = (string)$mesVenda->fetchColumn();
        if ($mv === '') continue;
        $venc = trim((string)($c['vencimento'] ?? ''));
        if ($venc !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $venc)) $venc = '';
        $pagou = in_array($c['cliente_pagou'] ?? '', ['S', 'N'], true) ? $c['cliente_pagou'] : '';
        $pago = (string)($c['pago_em'] ?? '');
        if ($pago !== '' && (!preg_match('/^\d{4}-\d{2}$/', $pago) || $pago < $mv)) $pago = '';
        $up->execute([(int)$id, mb_substr(so_digitos((string)($c['ixc_cliente'] ?? '')), 0, 12), $venc,
            dinheiro((string)($c['instalacao'] ?? '')), dinheiro((string)($c['roteador'] ?? '')), dinheiro((string)($c['valor'] ?? '')),
            $pagou, $pago, mb_substr(trim((string)($c['obs'] ?? '')), 0, 300), $quem]);
        $n++;
    }
    return $n;
}

/** Quanto foi pago em um mês, por vendedor e tipo (vale qualquer mês de venda). */
function comissoes_pagas_em(string $mes): array
{
    $q = db()->prepare("SELECT d.nome AS vendedor, v.tipo, COUNT(*) AS n, SUM(CAST(c.valor AS REAL)) AS total,
                               strftime('%Y-%m', v.recebido_em, 'localtime') AS mes_venda
                        FROM comissoes c JOIN vendas v ON v.id = c.venda_id JOIN vendedores d ON d.id = v.vendedor_id
                        WHERE c.pago_em = ? GROUP BY d.nome, v.tipo, mes_venda ORDER BY d.nome");
    $q->execute([$mes]);
    $out = [];
    foreach ($q as $r) {
        $o = &$out[$r['vendedor']];
        $o['tipos'][$r['tipo']] = ($o['tipos'][$r['tipo']] ?? 0) + (float)$r['total'];
        $o['n'] = ($o['n'] ?? 0) + (int)$r['n'];
        $o['total'] = ($o['total'] ?? 0) + (float)$r['total'];
        if ($r['mes_venda'] !== $mes) $o['atrasadas'][] = mes_nome($r['mes_venda']);
        unset($o);
    }
    return $out;
}
