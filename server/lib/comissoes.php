<?php
/**
 * Comissões dos vendedores (só o gestor admin vê).
 * Regra do ALEX: a comissão é paga uma vez só. Ela fica sempre no mês da venda;
 * quando é paga, marca-se o mês do pagamento ("paga em"). Nada passa para o mês seguinte.
 */
declare(strict_types=1);

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/ixc.php'; // sem_acento

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
                   c.venda_id AS tem, c.ixc_cliente, c.vencimento, c.instalacao, c.roteador, c.valor, c.valor_plano, c.venc_previsto, c.cliente_pagou, c.pago_em, c.obs
            FROM vendas v JOIN vendedores d ON d.id = v.vendedor_id LEFT JOIN comissoes c ON c.venda_id = v.id
            WHERE v.status <> 'descartada' AND v.tipo IN ('venda','upgrade','segundo')
              AND strftime('%Y-%m', v.recebido_em, 'localtime') = ?";
    $args = [$mes];
    if (!empty($filtro['vend'])) { $sql .= ' AND v.vendedor_id = ?'; $args[] = (int)$filtro['vend']; }
    if (!empty($filtro['tipo']) && isset(COMISSAO_TIPOS[$filtro['tipo']])) { $sql .= ' AND v.tipo = ?'; $args[] = $filtro['tipo']; }
    $sql .= ' ORDER BY d.nome, v.recebido_em';
    $q = db()->prepare($sql);
    $q->execute($args);
    $precos = comissoes_precos_planos();
    $out = [];
    foreach ($q as $r) {
        $dados = json_decode((string)$r['dados'], true) ?: [];
        $passos = json_decode((string)$r['ixc_passos'], true) ?: [];
        if ($r['tem'] === null) {
            $r['ixc_cliente'] = (string)($passos['prospect'] ?? '') ?: so_digitos((string)($dados['ixcCliente'] ?? ''));
            $r['vencimento'] = '';
            $r['instalacao'] = dinheiro((string)($dados['valInst'] ?? ''));
            $r['roteador'] = dinheiro((string)($dados['valRot'] ?? ''));
            $r['valor'] = $r['cliente_pagou'] = $r['pago_em'] = $r['obs'] = '';
        }
        $r['cpf'] = (string)($dados['cpf'] ?? '');
        $r['plano'] = $r['tipo'] === 'upgrade'
            ? trim(up((string)($dados['velNova'] ?? '')) . (($dados['valNovo'] ?? '') !== '' ? ' · R$ ' . $dados['valNovo'] : ''))
            : up((string)($dados['plano'] ?? ''));
        if ((string)($r['valor_plano'] ?? '') === '') {
            $r['valor_plano'] = $r['tipo'] === 'upgrade'
                ? dinheiro((string)($dados['valNovo'] ?? ''))
                : (string)($precos[up(sem_acento((string)($dados['plano'] ?? '')))] ?? '');
        }
        $r['dia_venc'] = (string)($dados['venc'] ?? '');
        $r['venc_previsto'] = (string)($r['venc_previsto'] ?? '');
        if ($r['venc_previsto'] === '' && $r['vencimento'] === '') {
            $r['venc_previsto'] = venc_previsto(date('Y-m-d', strtotime((string)$r['recebido_em'])), (int)$r['dia_venc']);
        }
        $pagou = $r['cliente_pagou'];
        if (!empty($filtro['pagou']) && !($filtro['pagou'] === 'S' ? $pagou === 'S' : ($filtro['pagou'] === 'N' ? $pagou === 'N' : $pagou === ''))) continue;
        if (!empty($filtro['paga']) && ($filtro['paga'] === 'sim') !== ($r['pago_em'] !== '')) continue;
        $out[] = $r;
    }
    return $out;
}

/** Somas de uma lista de comissões: total, já paga, a pagar (cliente pagou), esperando o cliente. */
function comissoes_totais(array $linhas): array
{
    $t = ['n' => count($linhas), 'total' => 0.0, 'paga' => 0.0, 'apagar' => 0.0, 'espera' => 0.0, 'semvalor' => 0, 'abertas' => 0];
    foreach ($linhas as $l) {
        $v = (float)$l['valor'];
        $t['total'] += $v;
        if ($l['valor'] === '') $t['semvalor']++;
        if ($l['pago_em'] !== '') $t['paga'] += $v;
        else {
            $t['abertas']++;
            if ($l['cliente_pagou'] === 'S') $t['apagar'] += $v;
            else $t['espera'] += $v;
        }
    }
    return $t;
}

/** Comissões do mês separadas por vendedor (na ordem do nome), com quantas de cada tipo e as somas. */
function comissoes_por_vendedor(array $linhas): array
{
    $g = [];
    foreach ($linhas as $l) {
        $id = (int)$l['vendedor_id'];
        $g[$id]['nome'] = $l['vendedor'];
        $g[$id]['linhas'][] = $l;
        $g[$id]['tipos'][$l['tipo']] = ($g[$id]['tipos'][$l['tipo']] ?? 0) + 1;
    }
    foreach ($g as &$v) { $v['tot'] = comissoes_totais($v['linhas']); unset($v['linhas']); }
    return $g;
}

/** Próximo dia $diaMes depois de $depoisDe (AAAA-MM-DD), para o vencimento previsto; '' se o dia não serve. */
function venc_previsto(string $depoisDe, int $diaMes): string
{
    if ($diaMes < 1 || $diaMes > 31 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $depoisDe)) return '';
    $base = new DateTimeImmutable(substr($depoisDe, 0, 7) . '-01');
    for ($i = 0; $i < 3; $i++) {
        $m = $base->modify("+$i month");
        $d = $m->setDate((int)$m->format('Y'), (int)$m->format('m'), min($diaMes, (int)$m->format('t')))->format('Y-m-d');
        if ($d > $depoisDe) return $d;
    }
    return '';
}

/** Valor mensal de cada plano pelo nome: o da lista lida do IXC vale mais que o da aba Planos. */
function comissoes_precos_planos(): array
{
    $out = [];
    foreach (db()->query('SELECT nome, valor FROM planos') as $p) {
        $v = dinheiro((string)$p['valor']);
        if ($v !== '' && (float)$v > 0) $out[up(sem_acento((string)$p['nome']))] = $v;
    }
    $arq = dirname((string)config()['banco']) . '/ixc-planos.json';
    if (is_file($arq)) {
        foreach (json_decode((string)file_get_contents($arq), true) ?: [] as $o) {
            $v = dinheiro((string)($o['valor'] ?? ''));
            if ($v !== '' && (float)$v > 0) $out[up(sem_acento((string)($o['nome'] ?? '')))] = $v;
        }
    }
    return $out;
}

/** Grava as linhas editadas. $linhas = [venda_id => [campo => valor]] */
function comissoes_salvar(array $linhas, string $quem): int
{
    $up = db()->prepare("INSERT INTO comissoes (venda_id, ixc_cliente, vencimento, instalacao, roteador, valor, valor_plano, cliente_pagou, pago_em, obs, atualizado_em, por)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now','localtime'), ?)
        ON CONFLICT(venda_id) DO UPDATE SET ixc_cliente=CASE WHEN excluded.ixc_cliente = '' THEN comissoes.ixc_cliente ELSE excluded.ixc_cliente END,
            vencimento=CASE WHEN excluded.vencimento = '' THEN comissoes.vencimento ELSE excluded.vencimento END, instalacao=excluded.instalacao,
            roteador=excluded.roteador, valor=excluded.valor, valor_plano=excluded.valor_plano, cliente_pagou=excluded.cliente_pagou, pago_em=excluded.pago_em, obs=excluded.obs,
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
            dinheiro((string)($c['valor_plano'] ?? '')), $pagou, $pago, mb_substr(trim((string)($c['obs'] ?? '')), 0, 300), $quem]);
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

/**
 * Busca no IXC o ID do cliente e o primeiro boleto depois da venda, e marca "cliente pagou"
 * quando esse boleto foi recebido (ou "não" quando venceu em aberto). Não mexe no valor da
 * comissão nem no mês em que ela foi paga. Também preenche o valor do plano (valor_contrato do
 * plano de venda no IXC, pelo nome; no upgrade, o novo valor informado) quando ainda está vazio.
 * Devolve quantas vendas mudaram.
 */
function comissoes_atualizar_ixc(int $limite = 40, int $id = 0): int
{
    if (destino() !== 'ixc') return 0;
    require_once __DIR__ . '/ixc.php';
    $sql = "SELECT v.id, v.tipo, v.dados, v.ixc_passos, v.recebido_em, c.venda_id AS tem, c.ixc_cliente, c.vencimento, c.cliente_pagou, c.valor_plano, c.venc_previsto, c.boleto_n
            FROM vendas v LEFT JOIN comissoes c ON c.venda_id = v.id
            WHERE v.status = 'crm' AND v.tipo IN ('venda','upgrade','segundo')"
        . ($id ? ' AND v.id = ' . $id : " AND v.recebido_em >= datetime('now','-150 days')
            AND (c.venda_id IS NULL OR c.ixc_cliente = '' OR c.vencimento = '' OR c.cliente_pagou <> 'S' OR c.valor_plano = '')")
        . ' ORDER BY v.id DESC LIMIT ' . max(1, $limite);
    $api = ixc();
    $hoje = date('Y-m-d');
    $mudou = 0;
    $ins = db()->prepare("INSERT OR IGNORE INTO comissoes (venda_id, instalacao, roteador) VALUES (?, ?, ?)");
    $upPrev = db()->prepare("UPDATE comissoes SET venc_previsto = ? WHERE venda_id = ?");
    $upPlano = db()->prepare("UPDATE comissoes SET valor_plano = ? WHERE venda_id = ? AND valor_plano = ''");
    $precos = null; // valor de cada plano de venda no IXC, lido uma vez só
    $up = db()->prepare("UPDATE comissoes SET ixc_cliente = ?, vencimento = ?, cliente_pagou = ?, atualizado_em = datetime('now','localtime'), por = 'IXC' WHERE venda_id = ?");
    foreach (db()->query($sql)->fetchAll() as $v) {
        $dados = json_decode((string)$v['dados'], true) ?: [];
        $passos = json_decode((string)$v['ixc_passos'], true) ?: [];
        if ($v['tem'] === null) $ins->execute([(int)$v['id'], dinheiro((string)($dados['valInst'] ?? '')), dinheiro((string)($dados['valRot'] ?? ''))]);
        if ((string)($v['valor_plano'] ?? '') === '') {
            if ($v['tipo'] === 'upgrade') {
                $vp = dinheiro((string)($dados['valNovo'] ?? ''));
            } else {
                if ($precos === null) {
                    $precos = [];
                    foreach ($api->listar('vd_contratos', 'id', '0', '>', 1000, 'asc') as $o) {
                        $pv = dinheiro((string)($o['valor_contrato'] ?? ''));
                        if ($pv !== '' && (float)$pv > 0) $precos[up(sem_acento((string)($o['nome'] ?? '')))] = $pv;
                    }
                }
                $vp = (string)($precos[up(sem_acento((string)($dados['plano'] ?? '')))] ?? '');
            }
            if ($vp !== '') { $upPlano->execute([$vp, (int)$v['id']]); $mudou++; }
        }
        $cli = (string)($v['ixc_cliente'] ?? '');
        if ($cli === '') $cli = so_digitos((string)($dados['ixcCliente'] ?? ''));
        if ($cli === '') $cli = (string)($passos['prospect'] ?? '');
        if ($cli === '') $cli = (string)(ixc_cliente_por_cpf((string)($dados['cpf'] ?? ''))['id'] ?? '');
        if ($cli === '') continue;
        // boletos que vencem a partir do dia da venda (no 2º ponto, os do contrato novo). Conta o 1º,
        // ou o 2º quando o vendedor marcou no app (o 1º veio proporcional e a comissão é sobre o valor cheio).
        $n = max(1, min(3, (int)($v['boleto_n'] ?? 1)));
        $dia = date('Y-m-d', strtotime((string)$v['recebido_em']));
        $contrato = (string)($passos['contrato'] ?? '');
        $lista = [];
        $ultimoAntes = '';
        // os mais novos primeiro: cliente antigo (upgrade, 2º ponto) pode ter mais de 100 boletos
        foreach ($api->listar('fn_areceber', 'id_cliente', $cli, '=', 100, 'desc') as $b) {
            $venc = substr((string)($b['data_vencimento'] ?? ''), 0, 10);
            if (($b['status'] ?? '') === 'C') continue;
            if ($venc < $dia) { if ($venc > $ultimoAntes) $ultimoAntes = $venc; continue; }
            if ($contrato !== '' && (string)($b['id_contrato'] ?? '') !== '' && (string)$b['id_contrato'] !== $contrato) continue;
            $lista[] = $b;
        }
        usort($lista, fn($a, $b) => strcmp(substr((string)$a['data_vencimento'], 0, 10), substr((string)$b['data_vencimento'], 0, 10)));
        $bol = $lista[$n - 1] ?? null;
        $venc = $bol ? substr((string)$bol['data_vencimento'], 0, 10) : (string)($v['vencimento'] ?? '');
        // sem boleto ainda: data prevista pelo dia de vencimento do cliente (último boleto no IXC ou o dia escolhido no app)
        $diaMes = $ultimoAntes !== '' ? (int)substr($ultimoAntes, 8, 2) : (int)($dados['venc'] ?? 0);
        $prev = '';
        if (!$bol) {
            // o enésimo boleto previsto: o último que já existe (ou o dia da venda) mais os meses que faltam
            $prev = $lista ? substr((string)end($lista)['data_vencimento'], 0, 10) : $dia;
            $d = $diaMes ?: ($lista ? (int)substr($prev, 8, 2) : 0);
            for ($i = count($lista); $i < $n && $prev !== ''; $i++) $prev = venc_previsto($prev, $d);
        }
        if ($prev !== (string)($v['venc_previsto'] ?? '')) { $upPrev->execute([$prev, (int)$v['id']]); }
        $pagou = (string)($v['cliente_pagou'] ?? '');
        if ($bol) {
            if (($bol['status'] ?? '') === 'R') $pagou = 'S';
            elseif ($pagou === '' && $venc !== '' && $venc < $hoje) $pagou = 'N';
        }
        if ($cli !== (string)$v['ixc_cliente'] || $venc !== (string)$v['vencimento'] || $pagou !== (string)$v['cliente_pagou']) {
            $up->execute([$cli, $venc, $pagou, (int)$v['id']]);
            $mudou++;
        }
    }
    return $mudou;
}
