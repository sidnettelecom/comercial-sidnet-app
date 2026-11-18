<?php
/**
 * Dashboard comercial do painel (só o gestor admin): ranking de vendas e upgrades por vendedor,
 * comparação com o mês anterior, evolução mês a mês e metas por vendedor e mês.
 *
 * "Vendas" = venda nova + 2º ponto. Upgrades contam à parte. Cadastros descartados não contam.
 * O mês de um cadastro é o mês em que chegou ao servidor (recebido_em, já em hora local).
 */
declare(strict_types=1);

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/comissoes.php';

function dash_tabela(): void
{
    db()->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS metas (
        vendedor_id INTEGER NOT NULL,
        mes TEXT NOT NULL,
        meta_vendas INTEGER,
        meta_upgrades INTEGER,
        atualizado_em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        por TEXT NOT NULL DEFAULT '',
        PRIMARY KEY (vendedor_id, mes)
    )
    SQL);
    // gestor que não é admin, mas vê a dashboard e mexe nas metas (sem ver comissões)
    $cols = array_column(db()->query('PRAGMA table_info(gestores)')->fetchAll(), 'name');
    if (!in_array('ver_dash', $cols, true)) {
        db()->exec('ALTER TABLE gestores ADD COLUMN ver_dash INTEGER NOT NULL DEFAULT 0');
    }
}

/**
 * Números por vendedor e mês, de $de até $ate (AAAA-MM, inclusive).
 * Com $ateDia, conta só do dia 1 até esse dia de cada mês (para comparar o mês corrente com o mesmo período do anterior).
 * Volta [vendedor_id => [mes => ['nova'=>, 'seg'=>, 'v'=>, 'u'=>, 'r'=> mensalidades]]] e os nomes [vendedor_id => nome].
 */
function dash_numeros(string $de, string $ate, ?int $ateDia = null): array
{
    $precos = comissoes_precos_planos();
    $sql = "SELECT v.vendedor_id, v.tipo, v.dados, substr(v.recebido_em, 1, 7) AS mes, c.valor_plano
        FROM vendas v LEFT JOIN comissoes c ON c.venda_id = v.id
        WHERE v.status <> 'descartada' AND v.tipo IN ('venda','upgrade','segundo')
          AND substr(v.recebido_em, 1, 7) BETWEEN ? AND ?";
    $par = [$de, $ate];
    if ($ateDia !== null) {
        $sql .= ' AND CAST(substr(v.recebido_em, 9, 2) AS INTEGER) <= ?';
        $par[] = $ateDia;
    }
    $q = db()->prepare($sql);
    $q->execute($par);
    $out = [];
    foreach ($q as $r) {
        $g = &$out[(int)$r['vendedor_id']][$r['mes']];
        $g ??= ['nova' => 0, 'seg' => 0, 'v' => 0, 'u' => 0, 'r' => 0.0];
        if ($r['tipo'] === 'upgrade') {
            $g['u']++;
            continue; // mensalidades novas: só vendas e 2º ponto
        }
        $r['tipo'] === 'venda' ? $g['nova']++ : $g['seg']++;
        $g['v']++;
        // mesma regra de valor da aba "Vendas do mês": valor da comissão, senão o preço do plano
        $vp = (string)($r['valor_plano'] ?? '');
        if ($vp === '') {
            $dd = json_decode((string)$r['dados'], true) ?: [];
            $vp = (string)($precos[up(sem_acento((string)($dd['plano'] ?? '')))] ?? '');
        }
        $g['r'] += (float)($vp ?: 0);
        unset($g);
    }
    return $out;
}

/** Metas de um mês: [vendedor_id => ['v' => int|null, 'u' => int|null]] */
function dash_metas(string $mes): array
{
    $q = db()->prepare('SELECT vendedor_id, meta_vendas, meta_upgrades FROM metas WHERE mes = ?');
    $q->execute([$mes]);
    $out = [];
    foreach ($q as $r) {
        $out[(int)$r['vendedor_id']] = [
            'v' => $r['meta_vendas'] === null ? null : (int)$r['meta_vendas'],
            'u' => $r['meta_upgrades'] === null ? null : (int)$r['meta_upgrades'],
        ];
    }
    return $out;
}

/** Metas de vários meses (para a linha de meta no gráfico): [mes => [vendedor_id => ['v'=>, 'u'=>]]] */
function dash_metas_periodo(string $de, string $ate): array
{
    $q = db()->prepare('SELECT vendedor_id, mes, meta_vendas, meta_upgrades FROM metas WHERE mes BETWEEN ? AND ?');
    $q->execute([$de, $ate]);
    $out = [];
    foreach ($q as $r) {
        $out[$r['mes']][(int)$r['vendedor_id']] = [
            'v' => $r['meta_vendas'] === null ? null : (int)$r['meta_vendas'],
            'u' => $r['meta_upgrades'] === null ? null : (int)$r['meta_upgrades'],
        ];
    }
    return $out;
}

/** Grava as metas do mês. $linhas = [vendedor_id => ['v' => '12', 'u' => '']]; vazio = sem meta. */
function dash_metas_salvar(string $mes, array $linhas, string $por): int
{
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) throw new RuntimeException('Mês inválido.');
    $n = 0;
    $ins = db()->prepare("INSERT INTO metas (vendedor_id, mes, meta_vendas, meta_upgrades, por) VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(vendedor_id, mes) DO UPDATE SET meta_vendas = excluded.meta_vendas, meta_upgrades = excluded.meta_upgrades,
        por = excluded.por, atualizado_em = datetime('now','localtime')");
    $num = function ($x): ?int {
        $x = trim((string)$x);
        if ($x === '') return null;
        if (!ctype_digit($x) || (int)$x > 100000) throw new RuntimeException('Meta precisa ser um número inteiro.');
        return (int)$x;
    };
    $existe = db()->prepare('SELECT 1 FROM vendedores WHERE id = ?');
    $del = db()->prepare('DELETE FROM metas WHERE vendedor_id = ? AND mes = ?');
    foreach ($linhas as $vid => $l) {
        $existe->execute([(int)$vid]);
        if (!$existe->fetchColumn() || !is_array($l)) continue;
        [$mv, $mu] = [$num($l['v'] ?? ''), $num($l['u'] ?? '')];
        if ($mv === null && $mu === null) {
            $del->execute([(int)$vid, $mes]); // sem meta: não guarda linha vazia (assim "copiar" ainda funciona)
            continue;
        }
        $ins->execute([(int)$vid, $mes, $mv, $mu, $por]);
        $n++;
    }
    return $n;
}

/** Copia as metas do mês anterior para o mês (só onde o mês ainda não tem meta). Volta quantas copiou. */
function dash_metas_copiar(string $mes, string $por): int
{
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) throw new RuntimeException('Mês inválido.');
    $q = db()->prepare("INSERT OR IGNORE INTO metas (vendedor_id, mes, meta_vendas, meta_upgrades, por)
        SELECT vendedor_id, ?, meta_vendas, meta_upgrades, ? FROM metas WHERE mes = ?");
    $q->execute([$mes, $por, mes_soma($mes, -1)]);
    return $q->rowCount();
}

/** "▲ 12%" verde, "▼ 5%" vermelho, "=" cinza; "novo" quando antes era zero. */
function dash_delta(float $agora, float $antes): string
{
    if ($antes == 0.0) return $agora > 0 ? '<span class="dh-eq">novo</span>' : '<span class="dh-eq">—</span>';
    $p = (int)round(($agora - $antes) / $antes * 100);
    if ($p === 0) return '<span class="dh-eq">= 0%</span>';
    return $p > 0 ? '<span class="dh-up">▲ ' . $p . '%</span>' : '<span class="dh-dn">▼ ' . abs($p) . '%</span>';
}

/** Barra de progresso da meta. $esperado = fração do mês que já passou (1 = mês fechado). */
function dash_barra(int $feito, ?int $meta, float $esperado): string
{
    if (!$meta) return '<span class="small">sem meta</span>';
    $p = (int)round($feito / $meta * 100);
    $r = $feito / $meta / max(0.01, $esperado);
    $cls = $r >= 1 ? 'ok' : ($r >= .8 ? 'mid' : 'low');
    $ritmo = $esperado < 1 ? '<span class="ritmo" style="left:' . round($esperado * 100, 1) . '%"></span>' : '';
    return '<div class="dh-prog"><div class="dh-bar ' . $cls . '"><i style="width:' . min(100, $p) . '%"></i>' . $ritmo . '</div><b>' . $p . '%</b></div>';
}
