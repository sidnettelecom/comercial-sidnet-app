<?php
/**
 * Permissões dos gestores do painel, por opção e subopção do menu (e, no RH, por tipo de lançamento).
 * O admin tem tudo; os outros só o que o admin liberar em Configurações → Permissões.
 * As metas da dashboard, Permissões, criar/bloquear gestores e desfazer pagamento do RH são só do admin.
 * Gravado em gestores.permissoes como lista de chaves separadas por vírgula ("vendas.lista,rh.lanc.horas").
 */
declare(strict_types=1);

require_once __DIR__ . '/base.php';

/** Árvore do que pode ser liberado: chave => [rótulo, filhos]. Só as folhas são gravadas. */
const PERM_ARVORE = [
    'dash' => ['Dashboard', []],
    'vendas' => ['Vendas', ['vendas.lista' => ['Vendas', []], 'vendas.mes' => ['Vendas do mês', []]]],
    'com' => ['Comissões', ['com.lista' => ['Comissões', []], 'com.planilha' => ['Comissões planilha', []]]],
    'rec' => ['Recolhimento', ['rec.lista' => ['Recolhimentos (lançar O.S)', []], 'rec.com' => ['Comissão do recolhimento', []]]],
    'inst' => ['Instalação', ['inst.lista' => ['Instalações concluídas (lançar O.S)', []], 'inst.com' => ['Comissões da instalação', []]]],
    'rh' => ['RH', [
        'rh.lanc' => ['Lançamentos', [
            'rh.lanc.horas' => ['Horas informais', []],
            'rh.lanc.sobre' => ['Sobreaviso', []],
            'rh.lanc.compl' => ['Complemento de salário', []],
            'rh.lanc.pag' => ['Pagamento extra', []],
        ]],
        'rh.fecha' => ['Fechamento do mês', []],
        'rh.func' => ['Funcionários', []],
    ]],
    'recibos' => ['Recibos', [
        'recibos.vendas' => ['Comissão de vendas', []],
        'recibos.rec' => ['Recolhimento', []],
        'recibos.inst' => ['Instalação', []],
        'recibos.rh' => ['RH (com todos os tipos, até complemento de salário)', []],
    ]],
    'cfg' => ['Configurações', ['cfg.gest' => ['Gestores', []], 'cfg.users' => ['Usuários', []], 'cfg.planos' => ['Planos', []]]],
];

/** Chaves antigas (uma por área, até 2026-11-19) -> chaves novas. Quem já tinha acesso continua vendo o mesmo. */
const PERM_ANTIGAS = [
    'vendas' => ['vendas.lista', 'vendas.mes'],
    'vendedores' => ['cfg.users'],
    'planos' => ['cfg.planos'],
    'comissoes' => ['com.lista', 'com.planilha'],
    'gestores' => ['cfg.gest'],
    'recolhimentos' => ['rec.lista'],
    'instalacoes' => ['inst.lista'],
    'rh' => ['rh.lanc.horas', 'rh.lanc.sobre', 'rh.lanc.compl', 'rh.lanc.pag', 'rh.fecha', 'rh.func'],
];
/** o que um gestor novo já recebe ao ser criado */
const PERM_PADRAO_NOVO = 'vendas.lista,vendas.mes';

/** Todas as folhas da árvore (ou de um ramo), na ordem do menu. */
function perm_folhas(array $ramo = PERM_ARVORE): array
{
    $out = [];
    foreach ($ramo as $k => [, $filhos]) {
        if ($filhos) array_push($out, ...perm_folhas($filhos));
        else $out[] = $k;
    }
    return $out;
}

function perm_tabela(): void
{
    $cols = array_column(db()->query('PRAGMA table_info(gestores)')->fetchAll(), 'name');
    if (!in_array('permissoes', $cols, true)) {
        db()->exec("ALTER TABLE gestores ADD COLUMN permissoes TEXT NOT NULL DEFAULT ''");
        // quem já existia continua vendo o que via antes (tudo menos comissões; dashboard se tinha sido liberada)
        $dash = in_array('ver_dash', $cols, true) ? "|| CASE WHEN ver_dash = 1 THEN ',dash' ELSE '' END" : '';
        db()->exec("UPDATE gestores SET permissoes = 'vendas,vendedores,planos,gestores' $dash WHERE admin = 0");
    }
    // passa as chaves antigas (por área) para as novas (por subopção), uma vez só
    $q = db()->query("SELECT id, permissoes FROM gestores WHERE permissoes <> ''");
    $up = db()->prepare('UPDATE gestores SET permissoes = ? WHERE id = ?');
    foreach ($q->fetchAll() as $g) {
        $velhas = explode(',', (string)$g['permissoes']);
        if (!array_intersect($velhas, array_keys(PERM_ANTIGAS))) continue;
        $novas = [];
        foreach ($velhas as $k) array_push($novas, ...(PERM_ANTIGAS[$k] ?? [$k]));
        $up->execute([implode(',', perm_lista(implode(',', $novas))), $g['id']]);
    }
}

/** "vendas.lista,rh.fecha" -> ['vendas.lista', 'rh.fecha'] (só folhas conhecidas, na ordem do menu) */
function perm_lista(?string $s): array
{
    return array_values(array_intersect(perm_folhas(), explode(',', (string)$s)));
}

/**
 * Pode abrir/mexer? $k pode ser uma folha ("rh.lanc.compl") ou um ramo ("rh.lanc", "rh"):
 * o ramo vale se pelo menos uma folha dele estiver liberada.
 */
function perm_tem(array $perms, string $k): bool
{
    foreach ($perms as $p) if ($p === $k || str_starts_with($p, $k . '.')) return true;
    return false;
}

/** Grava as permissões marcadas: $marcadas = [gestor_id => ['vendas.lista' => '1', ...]]. Admins não mudam. */
function perm_salvar(array $marcadas, array $ids): int
{
    $up = db()->prepare('UPDATE gestores SET permissoes = ? WHERE id = ? AND admin = 0');
    $n = 0;
    foreach ($ids as $id) {
        $sel = array_keys(array_filter((array)($marcadas[$id] ?? [])));
        $up->execute([implode(',', perm_lista(implode(',', $sel))), (int)$id]);
        $n += $up->rowCount();
    }
    return $n;
}

/** Rótulo de uma chave ("rh.lanc.compl" -> "Complemento de salário"). */
function perm_rotulo(string $k, array $ramo = PERM_ARVORE): string
{
    foreach ($ramo as $c => [$t, $f]) {
        if ($c === $k) return $t;
        if ($f && ($r = perm_rotulo($k, $f)) !== '') return $r;
    }
    return '';
}
