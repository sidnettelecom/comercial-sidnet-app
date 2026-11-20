<?php
/**
 * Recibos -> Contas a pagar no IXC (pedido do ALEX em 20/11/2026).
 *
 * O portal gera um recibo só por pessoa, com tudo somado. No IXC cada conta de despesa vira uma
 * conta a pagar separada: comissão numa, custos de salário em outra, serviços de terceiros em outra.
 * Quem liquida é o financeiro, à mão, no IXC.
 *
 * Liga no config.php: 'ixc' => ['enviar_contas_pagar' => true]. O resto se ajusta na tela
 * Configurações -> Contas a pagar no IXC (só o admin):
 *   - contas de despesa (ex.: Comissões, Salário técnico, Salário administrativo), cada uma com uma conta a
 *     pagar MODELO já lançada no IXC: dela saem plano de contas, filial, conta e o que mais o IXC pedir.
 *     O portal copia o modelo e troca fornecedor, valor, datas e descrição;
 *   - grupos de pessoas (ex.: TÉCNICO, ADMINISTRATIVO) e o grupo de cada pessoa;
 *   - para cada tipo de lançamento, a conta de despesa padrão e, se for diferente, a de cada grupo
 *     (ex.: hora extra de técnico -> Salário técnico; de administrativo -> Salário administrativo);
 *   - fornecedor: procurado pelo CPF nos fornecedores do IXC; quem não estiver vai no fornecedor padrão;
 *   - vencimento: dias depois do lançamento.
 *
 * Nunca lança duas vezes: cada conta criada fica em recibos_ixc (mês + pessoa + conta de despesa). Se o
 * recibo crescer depois (lançamento novo no mês), só a diferença vai como conta nova. Se diminuir, avisa
 * para corrigir à mão no IXC (o portal não mexe numa conta que já existe lá).
 */
declare(strict_types=1);

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/ixc.php';
require_once __DIR__ . '/recibos.php';

/** tipos de lançamento que aparecem no recibo => nome na tela */
const RIXC_TIPOS = [
    'vendas' => 'Comissão de vendas',
    'rec' => 'Comissão de recolhimento',
    'inst' => 'Comissão de instalação',
    'rh.horas' => 'RH: horas informais',
    'rh.sobre' => 'RH: sobreaviso',
    'rh.compl' => 'RH: complemento de salário',
    'rh.pag' => 'RH: pagamento extra',
    'rh.dsr' => 'RH: DSR sobre horas informais',
    'serv' => 'Serviços em execução (equipes de rede)',
];

/**
 * Padrão de fábrica: três contas de despesa (sem conta modelo ainda) e o caminho de cada tipo.
 * despesas: [id => ['nome', 'conta' => id da conta contábil analítica no IXC, 'modelo' => id de uma conta a pagar modelo (jeito antigo)]]
 * grupos:   grupos de pessoas (ex.: TÉCNICO, ADMINISTRATIVO), na ordem da tela
 * regras:   [tipo => [grupo => id da despesa]]; grupo '' = padrão (todos). Na coluna de um grupo, '' = usa o padrão.
 *           No padrão, '' = esse tipo não vai ao IXC.
 */
const RIXC_PADRAO = [
    'despesas' => [
        '1' => ['nome' => 'Comissões', 'conta' => '', 'modelo' => ''],
        '2' => ['nome' => 'Custos de salário', 'conta' => '', 'modelo' => ''],
        '3' => ['nome' => 'Serviços de terceiros', 'conta' => '', 'modelo' => ''],
    ],
    'grupos' => [],
    'regras' => [
        'vendas' => ['' => '1'], 'rec' => ['' => '1'], 'inst' => ['' => '1'],
        'rh.horas' => ['' => '2'], 'rh.sobre' => ['' => '2'], 'rh.compl' => ['' => '2'], 'rh.pag' => ['' => '2'], 'rh.dsr' => ['' => '2'],
        'serv' => ['' => '3'],
    ],
    'forn_padrao' => '',
    'so_padrao' => 0,   // 1 = tudo no fornecedor padrão (não procura pelo CPF)
    'venc_dias' => 0,   // vencimento = dia do lançamento + N dias
];

/**
 * Nomes dos campos da conta a pagar (tabela fn_apagar) que o portal preenche.
 * Se o IXC usar outro nome, troque em config.php: 'ixc' => ['contas_pagar_campos' => ['valor' => '...']].
 * Confira com:  php server/bin/ixc.php campos fn_apagar
 */
function rixc_campos(): array
{
    return array_merge([
        'tabela' => 'fn_apagar',
        'fornecedor' => 'id_fornecedor',
        'valor' => 'valor',
        'aberto' => 'valor_aberto',
        'emissao' => 'data_emissao',
        'vencimento' => 'data_vencimento',
        'documento' => 'documento',
        'obs' => 'obs',
        'status' => 'status',
        'tabela_forn' => 'fornecedor',
        'forn_cpf' => 'cnpj_cpf',
        'conta_contabil' => '',  // campo da "Conta contábil analítica" em fn_apagar ('' = o portal descobre sozinho)
        'tabela_contabil' => '', // tabela das contas contábeis analíticas ('' = tenta as prováveis)
    ], (array)(config()['ixc']['contas_pagar_campos'] ?? []));
}

/** Campos do modelo que NÃO são copiados (pagamento, baixa, datas, valores, ids e registro de quem mexeu). */
const RIXC_NAO_COPIAR = '/^id$|pag|baix|liquid|juro|multa|desc|aberto|valor|data|status|documento|obs|fornecedor|parcela|nosso|ultima|log|cancel|usuario|operador|criad|alterad|hora|boleto|remessa|retorno|lote|cheque|conciliad|nfe|nota|chave/i';

function rixc_ligado(): bool
{
    return destino() === 'ixc' && !empty(config()['ixc']['enviar_contas_pagar']);
}

function rixc_tabela(): void
{
    db()->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS recibos_ixc (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        mes TEXT NOT NULL,
        chave TEXT NOT NULL,
        nome TEXT NOT NULL,
        despesa TEXT NOT NULL,
        despesa_nome TEXT NOT NULL DEFAULT '',
        valor TEXT NOT NULL,
        ixc_id TEXT NOT NULL,
        fornecedor TEXT NOT NULL DEFAULT '',
        lancado_em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        lancado_por TEXT NOT NULL DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS recibos_ixc_mes ON recibos_ixc (mes, chave);
    CREATE TABLE IF NOT EXISTS recibos_ixc_cfg (id INTEGER PRIMARY KEY CHECK (id = 1), dados TEXT NOT NULL DEFAULT '{}');
    INSERT OR IGNORE INTO recibos_ixc_cfg (id) VALUES (1);
    CREATE TABLE IF NOT EXISTS recibos_ixc_forn (chave TEXT PRIMARY KEY, ixc_id TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS recibos_ixc_pessoa (chave TEXT PRIMARY KEY, nome TEXT NOT NULL, grupo TEXT NOT NULL DEFAULT '');
    SQL);
}

function rixc_config(): array
{
    $j = json_decode((string)db()->query('SELECT dados FROM recibos_ixc_cfg WHERE id = 1')->fetchColumn(), true) ?: [];
    $c = array_merge(RIXC_PADRAO, $j);
    if (!isset($j['regras'])) $c['regras'] = RIXC_PADRAO['regras'];
    foreach (RIXC_TIPOS as $t => $_) $c['regras'][$t] = (array)($c['regras'][$t] ?? []) + ['' => ''];
    return $c;
}

/**
 * Salva a tela de configuração:
 * despesas [id => [nome, modelo]] (+ 'novo' => [nome, modelo]), grupos (texto, um por linha ou vírgula),
 * regras [tipo => [grupo => despesa]], forn_padrao, so_padrao, venc_dias.
 */
function rixc_config_salvar(array $in): void
{
    $c = rixc_config();
    // contas de despesa
    $desp = [];
    foreach ((array)($in['despesas'] ?? []) as $id => $d) {
        $nome = mb_substr(trim((string)($d['nome'] ?? '')), 0, 60);
        if ($id === 'novo') {
            if ($nome === '') continue;
            $id = (string)(max(array_map('intval', array_keys($c['despesas'] + $desp)) ?: [0]) + 1);
        }
        if ($nome === '') continue; // nome apagado = tira a conta de despesa
        $desp[(string)$id] = ['nome' => $nome, 'conta' => so_digitos((string)($d['conta'] ?? ($c['despesas'][$id]['conta'] ?? ''))), 'modelo' => so_digitos((string)($d['modelo'] ?? ($c['despesas'][$id]['modelo'] ?? '')))];
    }
    $c['despesas'] = $desp;
    // grupos de pessoas
    if (isset($in['grupos'])) {
        $g = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(up(trim($x)), 0, 30), preg_split('/[\n,;]+/', (string)$in['grupos'])))));
        $c['grupos'] = $g;
    }
    // regras: só valem despesas e grupos que existem
    $regras = [];
    foreach (RIXC_TIPOS as $t => $_) {
        foreach (array_merge([''], $c['grupos']) as $g) {
            $v = (string)($in['regras'][$t][$g === '' ? '_' : $g] ?? ($c['regras'][$t][$g] ?? '')); // o padrão vem do formulário como "_"
            if ($v !== '' && isset($desp[$v])) $regras[$t][$g] = $v;
        }
        $regras[$t] = ($regras[$t] ?? []) + ['' => ''];
    }
    $c['regras'] = $regras;
    $fp = trim((string)($in['forn_padrao'] ?? ''));
    $c['forn_padrao'] = preg_match('/#(\d+)\)?\s*$/', $fp, $m) ? $m[1] : so_digitos($fp);
    $c['so_padrao'] = !empty($in['so_padrao']) ? 1 : 0;
    $d = (int)($in['venc_dias'] ?? 0);
    if ($d < 0 || $d > 90) throw new RuntimeException('Vencimento: de 0 a 90 dias depois do lançamento.');
    $c['venc_dias'] = $d;
    if ($c['so_padrao'] && $c['forn_padrao'] === '') throw new RuntimeException('Para lançar tudo num fornecedor só, preencha o fornecedor padrão.');
    db()->prepare('UPDATE recibos_ixc_cfg SET dados = ? WHERE id = 1')->execute([json_encode($c, JSON_UNESCAPED_UNICODE)]);
}

/** [chave => grupo] das pessoas */
function rixc_grupos_pessoas(): array
{
    return array_column(db()->query('SELECT chave, grupo FROM recibos_ixc_pessoa')->fetchAll(), 'grupo', 'chave');
}

/** Grava o grupo de cada pessoa: [chave => ['nome' => ..., 'grupo' => ...]]. Devolve quantos mudaram. */
function rixc_pessoas_salvar(array $lista): int
{
    $cfg = rixc_config();
    $atual = rixc_grupos_pessoas();
    $fAtual = rixc_forn_pessoas();
    $up = db()->prepare('INSERT INTO recibos_ixc_pessoa (chave, nome, grupo) VALUES (?,?,?) ON CONFLICT(chave) DO UPDATE SET nome = excluded.nome, grupo = excluded.grupo');
    $upF = db()->prepare('INSERT OR REPLACE INTO recibos_ixc_forn (chave, ixc_id) VALUES (?,?)');
    $delF = db()->prepare('DELETE FROM recibos_ixc_forn WHERE chave = ?');
    $n = 0;
    foreach ($lista as $k => $v) {
        $k = recibo_chave((string)$k);
        if ($k === '') continue;
        $mudou = false;
        $g = (string)($v['grupo'] ?? ($atual[$k] ?? ''));
        if (($g === '' || in_array($g, $cfg['grupos'], true)) && $g !== ($atual[$k] ?? '')) {
            $up->execute([$k, mb_substr(up((string)($v['nome'] ?? $k)), 0, 80), $g]);
            $mudou = true;
        }
        if (array_key_exists('forn', $v)) {
            // vem da lista como "NOME DO FORNECEDOR (#123)", ou só o número
            $t = trim((string)$v['forn']);
            $f = preg_match('/#(\d+)\)?\s*$/', $t, $m) ? $m[1] : (ctype_digit($t) ? $t : '');
            if ($t !== '' && $f === '') throw new RuntimeException('Fornecedor de ' . ($v['nome'] ?? $k) . ': escolha um nome da lista.');
            if ($f !== ($fAtual[$k] ?? '')) {
                $f === '' ? $delF->execute([$k]) : $upF->execute([$k, $f]);
                $mudou = true;
            }
        }
        if ($mudou) $n++;
    }
    return $n;
}

/** [chave => id do fornecedor no IXC] já ligado a cada pessoa */
function rixc_forn_pessoas(): array
{
    return array_column(db()->query('SELECT chave, ixc_id FROM recibos_ixc_forn')->fetchAll(), 'ixc_id', 'chave');
}

/** Sugestão de fornecedor para quem ainda não tem: pelo CPF, senão pelo mesmo nome. [id, 'CPF'|'nome'] ou ['', ''] */
function rixc_forn_sugerido(string $chave, string $cpf, array $forns): array
{
    if (strlen($cpf) === 11) foreach ($forns as $f) if ($f['cpf'] === $cpf) return [$f['id'], 'CPF'];
    foreach ($forns as $f) if (recibo_chave($f['nome']) === $chave) return [$f['id'], 'nome'];
    return ['', ''];
}

/** "NOME (#123)" para a lista de escolha */
function rixc_forn_rotulo(string $id, array $forns): string
{
    foreach ($forns as $f) if ($f['id'] === $id) return $f['nome'] . ' (#' . $id . ')';
    return $id !== '' ? 'fornecedor (#' . $id . ')' : '';
}

/** Pessoas conhecidas (para ligar ao fornecedor e pôr no grupo): RH, técnicos, recolhedores, vendedores, equipes de serviços, quem já teve recibo. [chave => nome] */
function rixc_pessoas_conhecidas(): array
{
    $out = [];
    $add = function (string $nome) use (&$out) { if (trim($nome) !== '') $out[recibo_chave($nome)] ??= up($nome); };
    foreach (['rh_funcionarios', 'inst_tecnicos', 'inst_agendadores', 'rec_tecnicos', 'vendedores', 'serv_equipes'] as $t) {
        try {
            foreach (db()->query("SELECT nome FROM $t WHERE ativo = 1") as $r) $add((string)$r['nome']);
        } catch (PDOException) {
        }
    }
    foreach (db()->query('SELECT nome FROM recibos_cpf') as $r) $add((string)$r['nome']);
    foreach (db()->query('SELECT nome FROM recibos_ixc_pessoa') as $r) $add((string)$r['nome']);
    asort($out);
    return $out;
}

/** Id da conta de despesa de um tipo de lançamento para uma pessoa do grupo $g ('' se não vai ao IXC) */
function rixc_despesa_de(string $tipo, string $g, array $cfg): string
{
    $r = $cfg['regras'][$tipo] ?? [];
    $d = ($g !== '' ? (string)($r[$g] ?? '') : '') ?: (string)($r[''] ?? '');
    return isset($cfg['despesas'][$d]) ? $d : '';
}

/** Lê do IXC a conta a pagar modelo e devolve só o que será copiado. */
function rixc_modelo(string $id): array
{
    $cp = rixc_campos();
    $m = ixc()->um($cp['tabela'], 'id', $id);
    if (!$m) throw new ErroCrm('A conta a pagar modelo #' . $id . ' não existe no IXC.');
    $out = [];
    foreach ($m as $k => $v) {
        if (!is_scalar($v) || preg_match(RIXC_NAO_COPIAR, (string)$k)) continue;
        $out[$k] = (string)$v;
    }
    return $out;
}

function rixc_arquivo_despesas(): string
{
    return dirname((string)config()['banco']) . '/ixc-despesas.json';
}

/**
 * Lê do IXC (só leitura):
 *  - a lista de contas contábeis analíticas (o campo "Conta contábil analítica" da conta a pagar);
 *  - as últimas contas a pagar, para descobrir em que campo fica a conta contábil e, para cada conta contábil,
 *    a conta a pagar mais nova que a usou (dela o portal copia filial, conta, forma de pagamento...).
 * Guarda tudo em ixc-despesas.json. Devolve quantas contas contábeis achou.
 */
function rixc_buscar_despesas(int $quantas = 1500): int
{
    $cp = rixc_campos();
    $api = ixc();
    // 1) contas contábeis analíticas: a tabela do config, ou a primeira das prováveis que responder
    $tabs = $cp['tabela_contabil'] !== '' ? [$cp['tabela_contabil']] : ['planejamento_analitico', 'fn_planejamento_analitico', 'contabil_conta_analitica', 'planejamento', 'fn_planejamento', 'plano_contas'];
    $contas = [];
    $tabela = '';
    foreach ($tabs as $t) {
        try {
            $rs = $api->listar($t, 'id', '0', '>', 5000, 'asc');
        } catch (Throwable) {
            $rs = [];
        }
        foreach ($rs as $r) {
            $nome = '';
            foreach (['descricao', 'planejamento', 'conta', 'nome', 'titulo'] as $c) {
                if (!empty($r[$c]) && is_string($r[$c]) && !ctype_digit($r[$c])) { $nome = trim($r[$c]); break; }
            }
            if ($nome === '') continue;
            $cod = '';
            foreach (['codigo', 'classificacao', 'cod_conta', 'conta_codigo', 'estrutura', 'numero'] as $c) {
                if (!empty($r[$c]) && is_scalar($r[$c])) { $cod = trim((string)$r[$c]); break; }
            }
            if (isset($r['ativo']) && in_array($r['ativo'], ['N', '0'], true)) continue;
            if (isset($r['tipo']) && in_array(up((string)$r['tipo']), ['S', 'SINTETICA', 'SINTÉTICA'], true)) continue;
            $contas[(string)$r['id']] = ['id' => (string)$r['id'], 'nome' => mb_substr(($cod !== '' ? $cod . ' ' : '') . $nome, 0, 90), 'n' => 0, 'modelo' => ''];
        }
        if ($contas) { $tabela = $t; break; }
    }
    // 2) últimas contas a pagar: qual campo guarda a conta contábil e o modelo mais novo de cada uma
    $regs = $api->listar($cp['tabela'], 'id', '0', '>', $quantas, 'desc');
    $campo = $cp['conta_contabil'];
    if ($campo === '' && $contas && $regs) {
        $pontos = [];
        foreach ($regs as $r) foreach ($r as $k => $v) {
            if (!is_scalar($v) || !preg_match('/planej|contab|conta/i', (string)$k) || (string)$v === '' || (string)$v === '0') continue;
            $pontos[$k] = ($pontos[$k] ?? 0) + (isset($contas[(string)$v]) ? 1 : -1);
        }
        arsort($pontos);
        $campo = (string)(array_key_first(array_filter($pontos, fn($p) => $p > 0)) ?? '');
    }
    $base = '';
    foreach ($regs as $r) {
        if ((int)$r['id'] > (int)$base) $base = (string)$r['id']; // a mais nova (maior número)
        $v = $campo !== '' ? (string)($r[$campo] ?? '') : '';
        if ($v !== '' && isset($contas[$v])) {
            $contas[$v]['n']++;
            if ((int)$r['id'] > (int)$contas[$v]['modelo']) $contas[$v]['modelo'] = (string)$r['id'];
        }
    }
    $lista = array_values($contas);
    usort($lista, fn($a, $b) => [$b['n'] > 0, $a['nome']] <=> [$a['n'] > 0, $b['nome']]);
    // 3) fornecedores (para ligar cada pessoa ao fornecedor dela no IXC)
    $forns = [];
    foreach ($api->listar($cp['tabela_forn'], 'id', '0', '>', 10000, 'asc') as $f) {
        $nome = trim((string)(($f['razao'] ?? '') ?: (($f['fantasia'] ?? '') ?: ($f['nome'] ?? ''))));
        if ($nome === '' || (isset($f['ativo']) && $f['ativo'] === 'N')) continue;
        $forns[] = ['id' => (string)$f['id'], 'nome' => mb_substr(preg_replace('/\s+/', ' ', up($nome)), 0, 80), 'cpf' => so_digitos((string)($f[$cp['forn_cpf']] ?? ''))];
    }
    usort($forns, fn($a, $b) => strcmp($a['nome'], $b['nome']));
    file_put_contents(rixc_arquivo_despesas(), json_encode(['em' => date('d/m/Y H:i'), 'tabela' => $tabela, 'campo' => $campo, 'base' => $base, 'contas' => $lista, 'forns' => $forns], JSON_UNESCAPED_UNICODE));
    return count($lista);
}

/** O que a última busca achou: ['em', 'tabela', 'campo', 'base', 'contas' => [['id', 'nome', 'n', 'modelo'], ...]] */
function rixc_despesas_ixc(): array
{
    $f = rixc_arquivo_despesas();
    $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return (is_array($j) ? $j : []) + ['em' => '', 'tabela' => '', 'campo' => '', 'base' => '', 'contas' => [], 'forns' => []];
}

/** Texto da opção na tela */
function rixc_conta_rotulo(array $o): string
{
    return $o['nome'] . ($o['n'] ? ' (já usada em ' . $o['n'] . ' conta(s) a pagar)' : '');
}

/**
 * De onde sai a conta a pagar de uma despesa: [id da conta a pagar que serve de modelo, campos por cima].
 * Com a conta contábil escolhida: copia a conta a pagar mais nova com essa conta contábil (ou a mais nova de todas)
 * e põe a conta contábil escolhida por cima. Sem ela: o modelo digitado à mão (jeito antigo).
 */
function rixc_modelo_da_despesa(array $desp, string $nomeD): array
{
    if (($desp['conta'] ?? '') !== '') {
        $x = rixc_despesas_ixc();
        $campo = rixc_campos()['conta_contabil'] ?: $x['campo'];
        if ($campo === '') throw new RuntimeException('não descobri em que campo o IXC guarda a conta contábil. Clique em "Buscar no IXC" de novo; se continuar, peça para conferir com  php server/bin/ixc.php campos fn_apagar.');
        $mod = '';
        foreach ($x['contas'] as $c) if ($c['id'] === $desp['conta']) $mod = $c['modelo'];
        $mod = $mod ?: $x['base'];
        if ($mod === '') throw new RuntimeException('não achei nenhuma conta a pagar no IXC para usar de base. Clique em "Buscar no IXC".');
        return [$mod, [$campo => $desp['conta']]];
    }
    if (($desp['modelo'] ?? '') !== '') return [$desp['modelo'], []];
    throw new RuntimeException('falta escolher a conta contábil de "' . $nomeD . '" em Configurações → Contas a pagar no IXC.');
}

/** Fornecedor do IXC da pessoa: pelo CPF (guarda o que achou), senão o padrão. Devolve [id, como achou] ou lança. */
function rixc_fornecedor(string $chave, string $nome, string $cpf, array $cfg): array
{
    if (!$cfg['so_padrao']) {
        $q = db()->prepare('SELECT ixc_id FROM recibos_ixc_forn WHERE chave = ?');
        $q->execute([$chave]);
        if ($id = (string)$q->fetchColumn()) return [$id, 'pelo CPF'];
        if (strlen($cpf) === 11) {
            $cp = rixc_campos();
            $f = ixc()->um($cp['tabela_forn'], $cp['forn_cpf'], cpf_mascara($cpf)) ?? ixc()->um($cp['tabela_forn'], $cp['forn_cpf'], $cpf);
            if ($f) {
                db()->prepare('INSERT OR REPLACE INTO recibos_ixc_forn (chave, ixc_id) VALUES (?,?)')->execute([$chave, (string)$f['id']]);
                return [(string)$f['id'], 'pelo CPF'];
            }
        }
        [$sug] = rixc_forn_sugerido($chave, '', rixc_despesas_ixc()['forns']);
        if ($sug !== '') {
            db()->prepare('INSERT OR REPLACE INTO recibos_ixc_forn (chave, ixc_id) VALUES (?,?)')->execute([$chave, $sug]);
            return [$sug, 'pelo nome'];
        }
    }
    if ($cfg['forn_padrao'] !== '') return [$cfg['forn_padrao'], 'padrão'];
    throw new RuntimeException('não achei o fornecedor no IXC (nem pelo CPF nem pelo nome) e não há fornecedor padrão. Escolha o fornecedor dela em Configurações → Contas a pagar no IXC.');
}

/** [despesa => ['valor' => float, 'ls' => [[texto, valor]]]] de um recibo (pessoa), pelo tipo de cada linha e o grupo da pessoa */
function rixc_por_despesa(array $recibo, array $cfg, array $grupos): array
{
    $g = $grupos[$recibo['chave']] ?? '';
    $out = [];
    foreach ($recibo['ls'] as $l) {
        $d = rixc_despesa_de((string)($l[2] ?? ''), $g, $cfg);
        if ($d === '') continue; // tipo que não vai ao IXC
        $out[$d] ??= ['valor' => 0.0, 'ls' => []];
        $out[$d]['valor'] += (float)$l[1];
        $out[$d]['ls'][] = [$l[0], (float)$l[1]];
    }
    return $out;
}

/** [chave => [despesa => ['valor' => soma lançada, 'ids' => [ixc_id...]]]] do mês */
function rixc_lancados(string $mes): array
{
    $out = [];
    $q = db()->prepare('SELECT chave, despesa, valor, ixc_id FROM recibos_ixc WHERE mes = ? ORDER BY id');
    $q->execute([$mes]);
    foreach ($q as $r) {
        $x = &$out[$r['chave']][$r['despesa']];
        $x ??= ['valor' => 0.0, 'ids' => []];
        $x['valor'] += (float)$r['valor'];
        $x['ids'][] = $r['ixc_id'];
        unset($x);
    }
    return $out;
}

/**
 * Situação de um recibo no IXC: ['nao'|'sim'|'dif', [ids das contas]].
 * 'dif' = já lançado, mas o valor mudou depois (lançar de novo manda só a diferença).
 */
function rixc_situacao(array $recibo, array $lanc, array $cfg, array $grupos): array
{
    $ja = $lanc[$recibo['chave']] ?? [];
    if (!$ja) return ['nao', []];
    $ids = [];
    foreach ($ja as $x) array_push($ids, ...$x['ids']);
    $agora = rixc_por_despesa($recibo, $cfg, $grupos);
    foreach ($agora + $ja as $d => $_) {
        if (abs(($agora[$d]['valor'] ?? 0) - ($ja[$d]['valor'] ?? 0)) > 0.009) return ['dif', $ids];
    }
    return ['sim', $ids];
}

/**
 * Lança no IXC as contas a pagar dos recibos (já somados por pessoa) do mês.
 * Devolve ['ok' => [linhas], 'ja' => [nomes], 'erro' => [linhas]]. Cada conta criada é gravada na hora.
 */
function rixc_lancar(string $mes, array $recibos, string $quem): array
{
    if (!rixc_ligado()) throw new RuntimeException('O lançamento de contas a pagar no IXC está desligado (ixc.enviar_contas_pagar no config.php do servidor).');
    $cfg = rixc_config();
    $cp = rixc_campos();
    $grupos = rixc_grupos_pessoas();
    $modelos = [];
    $lanc = rixc_lancados($mes);
    $hoje = date('d/m/Y');
    $venc = date('d/m/Y', strtotime('+' . (int)$cfg['venc_dias'] . ' days'));
    $ref = mes_nome($mes);
    $res = ['ok' => [], 'ja' => [], 'erro' => []];
    $grava = db()->prepare('INSERT INTO recibos_ixc (mes, chave, nome, despesa, despesa_nome, valor, ixc_id, fornecedor, lancado_por) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($recibos as $r) {
        $feito = $erro = false;
        $agora = rixc_por_despesa($r, $cfg, $grupos);
        if (!$agora) {
            $res['erro'][] = $r['nome'] . ': nenhum tipo deste recibo está ligado a uma conta de despesa.';
            continue;
        }
        $forn = null;
        foreach ($agora as $d => $x) {
            $nomeD = $cfg['despesas'][$d]['nome'];
            try {
                $antes = $lanc[$r['chave']][$d]['valor'] ?? 0.0;
                $dif = round($x['valor'] - $antes, 2);
                if ($dif < -0.009) {
                    throw new RuntimeException('o valor diminuiu ' . rh_rs(-$dif) . ' depois de lançado. Corrija à mão no IXC (conta ' . implode(', ', array_map(fn($i) => '#' . $i, $lanc[$r['chave']][$d]['ids'])) . ').');
                }
                if ($dif < 0.01) continue;
                [$mod, $extra] = rixc_modelo_da_despesa($cfg['despesas'][$d], $nomeD);
                $modelos[$mod] ??= rixc_modelo($mod);
                $forn ??= rixc_fornecedor($r['chave'], $r['nome'], (string)$r['cpf'], $cfg);
                $doc = 'REC ' . substr($mes, 5, 2) . '/' . substr($mes, 0, 4) . ' ' . mb_substr($r['nome'], 0, 30);
                $obs = up($nomeD) . ' - RECIBO DE ' . up($ref) . ' (PORTAL SIDNET)' . "\n" . 'PESSOA: ' . $r['nome'] . ($r['cpf'] !== '' ? ' - CPF ' . cpf_mascara((string)$r['cpf']) : '')
                    . ($antes > 0 ? "\nCOMPLEMENTO: o recibo cresceu depois do primeiro lançamento (já lançado " . rh_rs($antes) . ').' : '')
                    . "\n" . implode("\n", array_map(fn($l) => $l[0] . ': ' . rh_rs($l[1]), $x['ls']));
                $v = number_format($dif, 2, '.', '');
                $dados = array_merge($modelos[$mod], $extra, [
                    $cp['fornecedor'] => $forn[0], $cp['valor'] => $v, $cp['aberto'] => $v,
                    $cp['emissao'] => $hoje, $cp['vencimento'] => $venc,
                    $cp['documento'] => $doc, $cp['obs'] => $obs, $cp['status'] => 'A',
                ]);
                $idIxc = ixc()->inserir($cp['tabela'], $dados);
                $grava->execute([$mes, $r['chave'], $r['nome'], $d, $nomeD, $v, $idIxc, $forn[0], $quem]);
                $feito = true;
                $res['ok'][] = $r['nome'] . ': ' . $nomeD . ' ' . rh_rs($dif) . ' → conta a pagar #' . $idIxc . ($forn[1] === 'padrão' ? ' (fornecedor padrão)' : '');
            } catch (Throwable $e) {
                $erro = true;
                $res['erro'][] = $r['nome'] . ' (' . $nomeD . '): ' . $e->getMessage();
            }
        }
        if (!$feito && !$erro) $res['ja'][] = $r['nome'];
    }
    return $res;
}
