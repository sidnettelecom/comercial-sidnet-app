<?php
/**
 * Colaboradores do IXC: lê a lista de funcionários do IXC (só leitura), o admin marca quem é
 * colaborador de verdade e em que área recebe, e o portal cadastra nas listas de cada área:
 * técnico de instalação, quem agenda a instalação, recolhedor e funcionário do RH.
 * O CPF vai para o cadastro de CPF dos recibos (e para a ficha do RH, se for do RH).
 * Vendedor não é criado aqui (precisa de usuário e senha do app): a tela só mostra quem já é.
 * Nada é gravado no IXC.
 */
declare(strict_types=1);

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/ixc.php';
require_once __DIR__ . '/recolhimento.php';
require_once __DIR__ . '/instalacao.php';
require_once __DIR__ . '/rh.php';
require_once __DIR__ . '/recibos.php';

/** papel => [tabela, nome na tela] (tabelas com nome único e ativo 0/1) */
const COLAB_PAPEIS = [
    'inst' => ['inst_tecnicos', 'Técnico de instalação'],
    'agenda' => ['inst_agendadores', 'Agenda instalação'],
    'rec' => ['rec_tecnicos', 'Recolhimento'],
    'rh' => ['rh_funcionarios', 'Funcionário do RH'],
];

function colab_tabela(): void
{
    // quem o admin disse que não é colaborador: some da lista (dá para mostrar de novo)
    db()->exec('CREATE TABLE IF NOT EXISTS colab_ignorados (ixc_id TEXT PRIMARY KEY, nome TEXT NOT NULL)');
}

function colab_arquivo(): string
{
    global $cfg;
    return dirname($cfg['banco']) . '/ixc-funcionarios.json';
}

/** Lê os funcionários do IXC e guarda a lista. Devolve quantos vieram. */
function colab_buscar_ixc(): int
{
    $out = [];
    foreach (ixc()->listar('funcionarios', 'id', '0', '>', 2000, 'asc') as $o) {
        $nome = up((string)($o['funcionario'] ?? ($o['nome'] ?? '')));
        if ($nome === '') continue;
        $cpf = '';
        foreach (['cpf', 'cpf_cnpj', 'cnpj_cpf', 'documento'] as $k) {
            $d = so_digitos((string)($o[$k] ?? ''));
            if (strlen($d) === 11) { $cpf = $d; break; }
        }
        $out[] = ['id' => (string)$o['id'], 'nome' => preg_replace('/\s+/', ' ', $nome), 'cpf' => $cpf, 'ativo' => ($o['ativo'] ?? 'S') !== 'N'];
    }
    file_put_contents(colab_arquivo(), json_encode(['em' => date('d/m/Y H:i'), 'lista' => $out], JSON_UNESCAPED_UNICODE));
    return count($out);
}

/** ['em' => 'dd/mm/aaaa hh:mm', 'lista' => [...]] da última busca ('em' vazio se nunca buscou) */
function colab_lista_ixc(): array
{
    $j = is_file(colab_arquivo()) ? json_decode((string)file_get_contents(colab_arquivo()), true) : null;
    return is_array($j) ? $j + ['em' => '', 'lista' => []] : ['em' => '', 'lista' => []];
}

/** Chave para achar a mesma pessoa com grafia um pouco diferente: sem acento, sem letra dobrada (HELLEN = HELEN, PASCOARELLI = PASCOARELI) */
function colab_chave(string $nome): string
{
    return preg_replace('/([A-Z])\1+/', '$1', recibo_chave($nome));
}

/** Onde cada lista é usada: papel => [[tabela, coluna, chave primária junto (ou '')], ...] */
const COLAB_REFS = [
    'inst' => [['inst_tec', 'tecnico_id', 'inst_id'], ['instalacoes', 'ajudante_id', '']],
    'agenda' => [['instalacoes', 'agendador_id', '']],
    'rec' => [['recolhimentos', 'tecnico_id', '']],
    'rh' => [['rh_lancamentos', 'funcionario_id', ''], ['rh_pagamentos', 'funcionario_id', 'mes'], ['rh_compl_feito', 'funcionario_id', 'mes']],
];

/** Parece a mesma pessoa? mesma chave, até 2 letras diferentes, ou mesmo primeiro e último nome */
function colab_parece(string $a, string $b): bool
{
    $a = colab_chave($a);
    $b = colab_chave($b);
    if ($a === $b) return true;
    if (min(strlen($a), strlen($b)) >= 8 && levenshtein($a, $b) <= 2) return true;
    $pa = explode(' ', $a);
    $pb = explode(' ', $b);
    return count($pa) > 1 && count($pb) > 1 && $pa[0] === $pb[0] && end($pa) === end($pb);
}

/** Cadastros que parecem repetidos em cada lista: [[papel, [id, nome, ativo, usos], [id, nome, ativo, usos]], ...] */
function colab_repetidos(): array
{
    $doIxc = [];
    foreach (colab_lista_ixc()['lista'] as $o) $doIxc[up($o['nome'])] = 1;
    $out = [];
    foreach (COLAB_PAPEIS as $p => [$tab]) {
        $l = db()->query("SELECT id, nome, ativo FROM $tab ORDER BY nome")->fetchAll();
        foreach ($l as $r) {
            $n = 0;
            foreach (COLAB_REFS[$p] as [$t, $c]) { $q = db()->prepare("SELECT COUNT(*) FROM $t WHERE $c = ?"); $q->execute([$r['id']]); $n += (int)$q->fetchColumn(); }
            $usos[$p][$r['id']] = $n;
        }
        for ($i = 0; $i < count($l); $i++) for ($j = $i + 1; $j < count($l); $j++) {
            if (!colab_parece($l[$i]['nome'], $l[$j]['nome'])) continue;
            $x = $l[$i] + ['usos' => $usos[$p][$l[$i]['id']], 'ixc' => isset($doIxc[up($l[$i]['nome'])])];
            $y = $l[$j] + ['usos' => $usos[$p][$l[$j]['id']], 'ixc' => isset($doIxc[up($l[$j]['nome'])])];
            $out[] = [$p, $x, $y];
        }
    }
    return $out;
}

/**
 * Junta dois cadastros da mesma pessoa numa lista: tudo que era de $sai passa para $fica e $sai é apagado.
 * No RH, os campos vazios de quem fica (CPF, salário...) recebem os de quem sai. Devolve o nome que ficou.
 */
function colab_unir(string $papel, int $fica, int $sai): string
{
    if (!isset(COLAB_PAPEIS[$papel]) || $fica === $sai) throw new RuntimeException('Escolha dois cadastros diferentes.');
    $tab = COLAB_PAPEIS[$papel][0];
    $q = db()->prepare("SELECT * FROM $tab WHERE id = ?");
    $q->execute([$fica]); $f = $q->fetch();
    $q->execute([$sai]); $s = $q->fetch();
    if (!$f || !$s) throw new RuntimeException('Esse cadastro não existe mais.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach (COLAB_REFS[$papel] as [$t, $c, $pk]) {
            if ($pk !== '') {
                // os dois na mesma O.S / no mesmo mês
                $dup = $pdo->prepare("SELECT $pk FROM $t WHERE $c = ? AND $pk IN (SELECT $pk FROM $t WHERE $c = ?)");
                $dup->execute([$sai, $fica]);
                $iguais = $dup->fetchAll(PDO::FETCH_COLUMN);
                if ($iguais && $t === 'rh_pagamentos') throw new RuntimeException("{$s['nome']} e {$f['nome']} têm pagamento do RH no mesmo mês. Confira no RH antes de juntar.");
                foreach ($iguais as $v) $pdo->prepare("DELETE FROM $t WHERE $c = ? AND $pk = ?")->execute([$sai, $v]);
            }
            $pdo->prepare("UPDATE $t SET $c = ? WHERE $c = ?")->execute([$fica, $sai]);
        }
        $sets = [];
        $vals = [];
        foreach ($f as $col => $v) {
            if (in_array($col, ['id', 'nome'], true)) continue;
            if ($col === 'ativo') { if ($s['ativo'] && !$f['ativo']) { $sets[] = 'ativo = 1'; } continue; }
            if ((string)$v === '' && (string)($s[$col] ?? '') !== '') { $sets[] = "$col = ?"; $vals[] = $s[$col]; }
        }
        if ($sets) $pdo->prepare("UPDATE $tab SET " . implode(', ', $sets) . ' WHERE id = ?')->execute([...$vals, $fica]);
        $pdo->prepare("DELETE FROM $tab WHERE id = ?")->execute([$sai]);
        // CPF dos recibos com o nome antigo: passa para o nome que ficou, se ele ainda não tem
        $kS = recibo_chave($s['nome']);
        $kF = recibo_chave($f['nome']);
        if ($kS !== $kF) {
            $c = $pdo->prepare('SELECT cpf FROM recibos_cpf WHERE chave = ?');
            $c->execute([$kS]);
            $cpf = (string)$c->fetchColumn();
            if ($cpf !== '') $pdo->prepare("INSERT INTO recibos_cpf (chave, nome, cpf) VALUES (?, ?, ?) ON CONFLICT(chave) DO UPDATE SET cpf = CASE WHEN cpf = '' THEN excluded.cpf ELSE cpf END")->execute([$kF, $f['nome'], $cpf]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $f['nome'];
}

/** [chave do nome => [papel => ativo 0/1]] de quem já está em cada área */
function colab_cadastrados(): array
{
    $out = [];
    foreach (COLAB_PAPEIS as $p => [$tab]) {
        foreach (db()->query("SELECT nome, ativo FROM $tab") as $r) $out[colab_chave($r['nome'])][$p] = max((int)$r['ativo'], $out[colab_chave($r['nome'])][$p] ?? 0);
    }
    return $out;
}

/** [chave do nome => nome] dos vendedores ativos, e [colaborador IXC => nome] dos ligados */
function colab_vendedores(): array
{
    $porNome = $porId = [];
    foreach (db()->query('SELECT nome, ixc_colaborador_id FROM vendedores WHERE ativo = 1') as $v) {
        $porNome[recibo_chave($v['nome'])] = $v['nome'];
        if ($v['ixc_colaborador_id'] !== '') $porId[$v['ixc_colaborador_id']] = $v['nome'];
    }
    return [$porNome, $porId];
}

function colab_ignorados(): array
{
    return db()->query('SELECT ixc_id, nome FROM colab_ignorados')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Grava o que o admin marcou. $linhas = [ixc_id => ['p' => [papel => '1'], 'cpf' => ..., 'ign' => '1']].
 * Marcado e não cadastrado: cadastra (ou volta a mostrar, se estava escondido na área).
 * Desmarcado e cadastrado: esconde da lista da área (o histórico continua).
 * Devolve ['novos' => n, 'escondidos' => n, 'cpf' => n, 'ign' => n].
 */
function colab_salvar(array $linhas): array
{
    $ixc = [];
    foreach (colab_lista_ixc()['lista'] as $o) $ixc[$o['id']] = $o;
    $cad = colab_cadastrados();
    $n = ['novos' => 0, 'escondidos' => 0, 'cpf' => 0, 'ign' => 0];
    $cpfs = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($linhas as $id => $l) {
            $o = $ixc[(string)$id] ?? null;
            if (!$o || !is_array($l)) continue;
            $k = colab_chave($o['nome']);
            if (!empty($l['ign'])) {
                $pdo->prepare('INSERT OR IGNORE INTO colab_ignorados (ixc_id, nome) VALUES (?,?)')->execute([$o['id'], $o['nome']]);
                $n['ign']++;
                continue;
            }
            $pdo->prepare('DELETE FROM colab_ignorados WHERE ixc_id = ?')->execute([$o['id']]);
            $cpf = so_digitos((string)($l['cpf'] ?? ''));
            if ($cpf !== '' && strlen($cpf) !== 11) throw new RuntimeException("CPF de {$o['nome']} precisa ter 11 números.");
            $marcou = false;
            foreach (COLAB_PAPEIS as $p => [$tab]) {
                $quer = !empty($l['p'][$p]);
                $tem = $cad[$k][$p] ?? null; // null = não existe; 0 = escondido; 1 = na lista
                $marcou = $marcou || $quer;
                if ($quer && $tem === null) {
                    if ($p === 'rh') $pdo->prepare('INSERT INTO rh_funcionarios (nome, cpf) VALUES (?,?)')->execute([$o['nome'], $cpf]);
                    else $pdo->prepare("INSERT INTO $tab (nome) VALUES (?)")->execute([$o['nome']]);
                    $n['novos']++;
                } elseif ($quer !== (bool)$tem && $tem !== null) {
                    // acha o registro pelo nome sem acento (pode ter sido digitado com outra grafia)
                    foreach ($pdo->query("SELECT id, nome FROM $tab")->fetchAll() as $r) {
                        if (colab_chave($r['nome']) !== $k) continue;
                        $pdo->prepare("UPDATE $tab SET ativo = ? WHERE id = ?")->execute([(int)$quer, $r['id']]);
                        $quer ? $n['novos']++ : $n['escondidos']++;
                    }
                }
            }
            if ($cpf !== '' && ($marcou || !empty($cad[$k]))) {
                $cpfs[recibo_chave($o['nome'])] = ['nome' => $o['nome'], 'cpf' => $cpf];
                foreach ($pdo->query('SELECT id, nome, cpf FROM rh_funcionarios')->fetchAll() as $r) {
                    if (colab_chave($r['nome']) === $k && $r['cpf'] === '') $pdo->prepare('UPDATE rh_funcionarios SET cpf = ? WHERE id = ?')->execute([$cpf, $r['id']]);
                }
            }
        }
        $n['cpf'] = recibos_cpf_salvar($cpfs);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $n;
}

/** Volta a mostrar alguém que tinha sido marcado como "não é colaborador". */
function colab_mostrar(string $ixcId): void
{
    db()->prepare('DELETE FROM colab_ignorados WHERE ixc_id = ?')->execute([$ixcId]);
}
