<?php
/**
 * Permissões dos gestores do painel, por área. O admin tem tudo; os outros só o que o admin
 * liberar em Configurações. As metas da dashboard e o próprio Configurações são só do admin.
 */
declare(strict_types=1);

require_once __DIR__ . '/base.php';

const PERM_AREAS = [
    'dash' => 'Dashboard',
    'vendas' => 'Vendas e Vendas do mês',
    'vendedores' => 'Usuários',
    'planos' => 'Planos',
    'comissoes' => 'Comissões',
    'gestores' => 'Gestores',
    'recolhimentos' => 'Recolhimentos',
];
/** o que um gestor novo já recebe ao ser criado */
const PERM_PADRAO_NOVO = 'vendas';

function perm_tabela(): void
{
    $cols = array_column(db()->query('PRAGMA table_info(gestores)')->fetchAll(), 'name');
    if (!in_array('permissoes', $cols, true)) {
        db()->exec("ALTER TABLE gestores ADD COLUMN permissoes TEXT NOT NULL DEFAULT ''");
        // quem já existia continua vendo o que via antes (tudo menos comissões; dashboard se tinha sido liberada)
        $dash = in_array('ver_dash', $cols, true) ? "|| CASE WHEN ver_dash = 1 THEN ',dash' ELSE '' END" : '';
        db()->exec("UPDATE gestores SET permissoes = 'vendas,vendedores,planos,gestores' $dash WHERE admin = 0");
    }
}

/** "vendas,planos" -> ['vendas', 'planos'] (só áreas conhecidas) */
function perm_lista(?string $s): array
{
    return array_values(array_intersect(array_keys(PERM_AREAS), explode(',', (string)$s)));
}

/** Grava as permissões marcadas: $marcadas = [gestor_id => ['vendas' => '1', ...]]. Admins não mudam. */
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
