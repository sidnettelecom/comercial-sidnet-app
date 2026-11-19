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

/** [chave do nome => [papel => ativo 0/1]] de quem já está em cada área */
function colab_cadastrados(): array
{
    $out = [];
    foreach (COLAB_PAPEIS as $p => [$tab]) {
        foreach (db()->query("SELECT nome, ativo FROM $tab") as $r) $out[recibo_chave($r['nome'])][$p] = (int)$r['ativo'];
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
            $k = recibo_chave($o['nome']);
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
                        if (recibo_chave($r['nome']) !== $k) continue;
                        $pdo->prepare("UPDATE $tab SET ativo = ? WHERE id = ?")->execute([(int)$quer, $r['id']]);
                        $quer ? $n['novos']++ : $n['escondidos']++;
                    }
                }
            }
            if ($cpf !== '' && ($marcou || !empty($cad[$k]))) {
                $cpfs[$k] = ['nome' => $o['nome'], 'cpf' => $cpf];
                foreach ($pdo->query('SELECT id, nome, cpf FROM rh_funcionarios')->fetchAll() as $r) {
                    if (recibo_chave($r['nome']) === $k && $r['cpf'] === '') $pdo->prepare('UPDATE rh_funcionarios SET cpf = ? WHERE id = ?')->execute([$cpf, $r['id']]);
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
