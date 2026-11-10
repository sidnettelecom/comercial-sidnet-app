<?php
declare(strict_types=1);
require_once __DIR__ . '/base.php';

/** Chave legível (sem 0/O/1/l), 24 caracteres. Guardamos só o hash. */
function gerar_chave(): string
{
    $alf = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < 24; $i++) {
        $s .= $alf[random_int(0, strlen($alf) - 1)];
    }
    return $s;
}

function vendedor_novo(string $nome, string $crmId): array
{
    $chave = gerar_chave();
    db()->prepare('INSERT INTO vendedores (nome, crm_vendedor_id, chave_hash) VALUES (?, ?, ?)')
        ->execute([up($nome), trim($crmId), hash('sha256', $chave)]);
    return [(int)db()->lastInsertId(), $chave];
}

function vendedor_nova_chave(int $id): string
{
    $chave = gerar_chave();
    db()->prepare('UPDATE vendedores SET chave_hash = ? WHERE id = ?')->execute([hash('sha256', $chave), $id]);
    return $chave;
}
