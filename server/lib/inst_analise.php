<?php
/**
 * Análise da instalação (qualidade do serviço) antes de pagar a comissão.
 * Regra do ALEX (2026-11-23): cada instalação fica em observação por 30 dias depois do fechamento da O.S.
 * O sistema consulta o IXC (só leitura, su_oss_chamado) e procura O.S novas do mesmo cliente nesse período.
 * - Sem O.S que conta no período: instalação aprovada, a comissão pode ser conferida e paga.
 * - Com O.S que conta: a comissão fica segurada até o gestor decidir "pagar" ou "não pagar" (com motivo).
 *   "Não pagar" zera o valor dos técnicos daquela O.S (quem agendou continua recebendo).
 * - Ainda dentro dos 30 dias: em observação, a comissão não pode ser conferida.
 * O admin escolhe quais assuntos de O.S contam (ex.: "sem conexão" conta, "financeiro" não), o número de dias
 * e a partir de qual data de fechamento a regra vale.
 */
declare(strict_types=1);

require_once __DIR__ . '/recolhimento.php';

const INST_AN_PADRAO = ['dias' => 30, 'desde' => '', 'ignorar' => [], 'assuntos' => []];

const INST_AN_SIT = [
    'observacao' => ['Em observação', 'q'],
    'verificar' => ['Falta consultar o IXC', 'q'],
    'aprovada' => ['Aprovada · sem O.S', 'ok'],
    'analisar' => ['Teve O.S · analisar', 'er'],
    'pagar' => ['Teve O.S · pagar', 'ok'],
    'nao_pagar' => ['Não pagar', 'er'],
    'fora' => ['Fora da regra', 'mu'],
];

function inst_an_tabela(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS inst_an_cfg (id INTEGER PRIMARY KEY CHECK (id = 1), dados TEXT NOT NULL DEFAULT '{}')");
    if (!db()->query('SELECT COUNT(*) FROM inst_an_cfg')->fetchColumn()) {
        // a regra vale para as instalações fechadas a partir do mês em que foi ligada
        db()->prepare('INSERT INTO inst_an_cfg (id, dados) VALUES (1, ?)')->execute([json_encode(['desde' => date('Y-m-01')])]);
    }
    $cols = array_column(db()->query('PRAGMA table_info(instalacoes)')->fetchAll(), 'name');
    foreach (['ixc_cliente' => "TEXT NOT NULL DEFAULT ''", 'an_os' => "TEXT NOT NULL DEFAULT '[]'", 'an_verif' => "TEXT NOT NULL DEFAULT ''",
        'an_final' => 'INTEGER NOT NULL DEFAULT 0', 'an_erro' => "TEXT NOT NULL DEFAULT ''", 'an_decisao' => "TEXT NOT NULL DEFAULT ''",
        'an_motivo' => "TEXT NOT NULL DEFAULT ''", 'an_por' => "TEXT NOT NULL DEFAULT ''", 'an_em' => "TEXT NOT NULL DEFAULT ''",
        'ajudante' => "TEXT NOT NULL DEFAULT ''", 'ajudante_id' => 'INTEGER NOT NULL DEFAULT 0', 'ixc_tecnico' => "TEXT NOT NULL DEFAULT ''"] as $c => $t)
        if (!in_array($c, $cols, true)) db()->exec("ALTER TABLE instalacoes ADD COLUMN $c $t");
}

function inst_an_config(bool $recarregar = false): array
{
    static $c = null;
    if ($c === null || $recarregar) {
        $j = json_decode((string)db()->query('SELECT dados FROM inst_an_cfg WHERE id = 1')->fetchColumn(), true) ?: [];
        $c = array_merge(INST_AN_PADRAO, $j);
        $c['dias'] = max(1, min(180, (int)$c['dias']));
        $c['ignorar'] = array_map('strval', (array)$c['ignorar']);
        $c['assuntos'] = (array)$c['assuntos'];
    }
    return $c;
}

function inst_an_config_gravar(array $c): void
{
    db()->prepare('UPDATE inst_an_cfg SET dados = ? WHERE id = 1')->execute([json_encode($c, JSON_UNESCAPED_UNICODE)]);
    inst_an_config(true);
}

/** Salva a configuração da tela: dias, desde (aaaa-mm-dd) e os assuntos marcados como "conta" (conta[id] = 1). */
function inst_an_config_salvar(array $in): void
{
    $c = inst_an_config();
    $d = (int)($in['dias'] ?? 30);
    if ($d < 1 || $d > 180) throw new RuntimeException('Dias de observação: de 1 a 180.');
    $desde = rec_data((string)($in['desde'] ?? ''));
    if ($desde === '') throw new RuntimeException('Escolha a partir de qual data de fechamento a análise vale.');
    $conta = array_map('strval', array_keys(array_filter((array)($in['conta'] ?? []))));
    $c['dias'] = $d;
    $c['desde'] = $desde;
    // só os assuntos marcados contam (pedido do ALEX, 2026-11-24); assunto novo no IXC não conta até ser marcado
    $c['contam'] = !empty($in['limpar']) ? [] : array_values(array_intersect($conta, array_map('strval', array_keys($c['assuntos']))));
    $c['ignorar'] = array_values(array_diff(array_map('strval', array_keys($c['assuntos'])), $c['contam']));
    inst_an_config_gravar($c);
}

/** Carrega do IXC (só leitura) a lista de assuntos de O.S para escolher quais contam. Devolve quantos. */
function inst_an_assuntos_ixc(): int
{
    require_once __DIR__ . '/ixc.php';
    $c = inst_an_config();
    $lista = [];
    foreach (ixc()->listar('su_oss_assunto', 'id', '0', '>', 1000, 'asc') as $a) {
        $id = (string)($a['id'] ?? '');
        if ($id !== '') $lista[$id] = up((string)($a['assunto'] ?? ''));
    }
    if (!$lista) throw new RuntimeException('O IXC não devolveu nenhum assunto de O.S.');
    asort($lista);
    $c['assuntos'] = $lista;
    $c['ignorar'] = array_values(array_intersect($c['ignorar'], array_map('strval', array_keys($lista))));
    inst_an_config_gravar($c);
    return count($lista);
}

/** Data final da observação (aaaa-mm-dd) */
function inst_an_fim(array $r, ?array $cfg = null): string
{
    $cfg ??= inst_an_config();
    $f = substr((string)$r['fechamento'], 0, 10);
    return rec_data($f) === '' ? '' : (new DateTimeImmutable($f))->modify('+' . $cfg['dias'] . ' day')->format('Y-m-d');
}

/** O.S encontradas que contam (sem os assuntos ignorados). */
function inst_an_os_contam(array $r, ?array $cfg = null): array
{
    $cfg ??= inst_an_config();
    $todas = json_decode((string)($r['an_os'] ?? '[]'), true) ?: [];
    return array_values(array_filter($todas, fn($o) => inst_an_assunto_conta((string)($o['assunto_id'] ?? ''), $cfg)));
}

/** O assunto de O.S segura a comissão? Com a lista 'contam' gravada, só os marcados; antes dela, todos menos os ignorados. */
function inst_an_assunto_conta(string $aid, ?array $cfg = null): bool
{
    $cfg ??= inst_an_config();
    if (isset($cfg['contam'])) return in_array($aid, array_map('strval', (array)$cfg['contam']), true);
    return !in_array($aid, $cfg['ignorar'], true);
}

/**
 * Situação da análise de uma instalação: ['sit', 'rotulo', 'classe', 'libera' (pode conferir/pagar), 'zera' (técnicos não recebem),
 * 'fim', 'faltam' (dias), 'os' (O.S que contam), 'todas' (todas as O.S achadas)].
 */
function inst_an_situacao(array $r, ?array $cfg = null): array
{
    $cfg ??= inst_an_config();
    $fim = inst_an_fim($r, $cfg);
    $os = inst_an_os_contam($r, $cfg);
    $todas = json_decode((string)($r['an_os'] ?? '[]'), true) ?: [];
    $hoje = date('Y-m-d');
    if (substr((string)$r['fechamento'], 0, 10) < $cfg['desde'] || $fim === '') $sit = 'fora';
    elseif ($os) $sit = in_array($r['an_decisao'] ?? '', ['pagar', 'nao_pagar'], true) ? $r['an_decisao'] : 'analisar';
    elseif ($hoje <= $fim) $sit = 'observacao';
    elseif (!empty($r['an_final'])) $sit = 'aprovada';
    else $sit = 'verificar';
    $faltam = $fim !== '' && $hoje <= $fim ? (int)(new DateTimeImmutable($hoje))->diff(new DateTimeImmutable($fim))->days + 1 : 0;
    [$rot, $cl] = INST_AN_SIT[$sit];
    if ($sit === 'observacao') $rot .= ' · ' . $faltam . ($faltam === 1 ? ' dia' : ' dias');
    return ['sit' => $sit, 'rotulo' => $rot, 'classe' => $cl, 'libera' => in_array($sit, ['fora', 'aprovada', 'pagar', 'nao_pagar'], true),
        'zera' => $sit === 'nao_pagar', 'fim' => $fim, 'faltam' => $faltam, 'os' => $os, 'todas' => $todas];
}

/**
 * Aplica a análise nas linhas de instalacoes (chamado por inst_com_tecs): põe 'an' e, quando a comissão está segurada,
 * mostra como não conferida; quando é "não pagar", os técnicos ficam com valor 0 (o digitado fica em 'valor_digitado').
 */
function inst_an_aplicar(array $linhas): array
{
    $cfg = inst_an_config();
    foreach ($linhas as &$r) {
        if (!array_key_exists('an_os', $r)) continue;
        $r['an'] = inst_an_situacao($r, $cfg);
        if ($r['pago_em'] !== '') continue;
        if (!$r['an']['libera']) $r['conferido'] = 0;
        if ($r['an']['zera']) foreach ($r['tecs'] as &$t) { $t['valor_digitado'] = $t['valor']; $t['valor'] = '0.00'; }
        unset($t);
    }
    unset($r);
    return $linhas;
}

/** Id do cliente no IXC da instalação: o da O.S de instalação, ou o do contrato. */
function inst_an_cliente(array $r): string
{
    if ($r['ixc_cliente'] !== '') return $r['ixc_cliente'];
    $id = '';
    if (($os = so_digitos($r['os'])) !== '') $id = (string)(ixc()->um('su_oss_chamado', 'id', $os)['id_cliente'] ?? '');
    if ($id === '' && ($ct = so_digitos($r['contrato'])) !== '') $id = (string)(ixc()->um('cliente_contrato', 'id', $ct)['id_cliente'] ?? '');
    return so_digitos($id);
}

/**
 * Consulta no IXC as O.S do cliente abertas do fechamento da instalação até o fim da observação
 * (menos a própria O.S de instalação e O.S de outro contrato do mesmo cliente). Grava o que achou.
 */
function inst_an_consultar(array $r): void
{
    require_once __DIR__ . '/ixc.php';
    $cfg = inst_an_config();
    $fech = substr((string)$r['fechamento'], 0, 10);
    $fim = inst_an_fim($r, $cfg);
    $up = db()->prepare("UPDATE instalacoes SET ixc_cliente = ?, an_os = ?, an_verif = datetime('now','localtime'), an_final = ?, an_erro = ? WHERE id = ?");
    try {
        $cli = inst_an_cliente($r);
        if ($cli === '') throw new RuntimeException('cliente não encontrado no IXC (confira o nº da O.S ou do contrato)');
        $achou = [];
        $nomes = $cfg['assuntos'];
        $novoAssunto = false;
        foreach (ixc()->listar('su_oss_chamado', 'id_cliente', $cli, '=', 200) as $o) {
            $id = (string)($o['id'] ?? '');
            if ($id === '' || $id === so_digitos($r['os'])) continue;
            $ab = rec_data_ixc($o['data_abertura'] ?? '');
            if ($ab === '' || $ab < $fech || $ab > $fim) continue;
            $ct = so_digitos((string)($o['id_contrato_kit'] ?? ''));
            if ($ct !== '' && $ct !== '0' && so_digitos($r['contrato']) !== '' && $ct !== so_digitos($r['contrato'])) continue; // outro ponto do cliente
            $aid = (string)($o['id_assunto'] ?? '');
            if ($aid !== '' && !isset($nomes[$aid])) {
                try { $nomes[$aid] = up((string)(ixc()->um('su_oss_assunto', 'id', $aid)['assunto'] ?? '')); $novoAssunto = true; } catch (Throwable) {}
            }
            $achou[] = ['id' => $id, 'assunto_id' => $aid, 'assunto' => $nomes[$aid] ?? '', 'abertura' => $ab,
                'fechamento' => rec_data_ixc($o['data_fechamento'] ?? ''), 'status' => (string)($o['status'] ?? ''),
                'msg' => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)($o['mensagem'] ?? '')))), 0, 160)];
        }
        if ($novoAssunto) { $c = inst_an_config(); $c['assuntos'] = $nomes; asort($c['assuntos']); inst_an_config_gravar($c); }
        usort($achou, fn($a, $b) => $a['abertura'] <=> $b['abertura']);
        $up->execute([$cli, json_encode($achou, JSON_UNESCAPED_UNICODE), date('Y-m-d') > $fim ? 1 : 0, '', $r['id']]);
    } catch (Throwable $e) {
        $up->execute([$r['ixc_cliente'], $r['an_os'], 0, mb_substr($e->getMessage(), 0, 200), $r['id']]);
    }
}

/**
 * Consulta o IXC para as instalações ainda abertas na análise (não pagas, sem resultado final, dentro da regra).
 * $mes = só as fechadas nesse mês. Devolve quantas consultou.
 */
function inst_an_atualizar(int $limite = 200, string $mes = ''): int
{
    if (destino() !== 'ixc') return 0;
    $cfg = inst_an_config();
    $sql = "SELECT * FROM instalacoes WHERE pago_em = '' AND an_final = 0 AND an_decisao = '' AND substr(fechamento, 1, 10) >= ?";
    $par = [$cfg['desde']];
    if ($mes !== '') { $sql .= ' AND substr(fechamento, 1, 7) = ?'; $par[] = $mes; }
    $q = db()->prepare($sql . ' ORDER BY an_verif, fechamento LIMIT ' . max(1, $limite));
    $q->execute($par);
    $n = 0;
    foreach ($q->fetchAll() as $r) { inst_an_consultar($r); $n++; }
    return $n;
}

/** O gestor decide uma instalação que teve O.S: 'pagar', 'nao_pagar' ou '' (volta para analisar). */
function inst_an_decidir(int $id, string $decisao, string $motivo, string $quem): void
{
    if (!in_array($decisao, ['pagar', 'nao_pagar', ''], true)) throw new RuntimeException('Decisão inválida.');
    $q = db()->prepare('SELECT pago_em FROM instalacoes WHERE id = ?');
    $q->execute([$id]);
    $pg = $q->fetchColumn();
    if ($pg === false) throw new RuntimeException('Essa instalação não existe mais.');
    if ($pg !== '') throw new RuntimeException('A comissão dessa instalação já foi paga.');
    $motivo = mb_substr(trim((string)$motivo), 0, 300);
    if ($decisao === 'nao_pagar' && $motivo === '') throw new RuntimeException('Escreva o motivo de não pagar (ex.: conector mal feito, retorno por sinal alto).');
    db()->prepare("UPDATE instalacoes SET an_decisao = ?, an_motivo = ?, an_por = ?, an_em = CASE WHEN ? = '' THEN '' ELSE datetime('now','localtime') END,
        conferido = CASE WHEN ? = '' THEN 0 ELSE conferido END WHERE id = ?")
        ->execute([$decisao, $decisao === '' ? '' : $motivo, $decisao === '' ? '' : $quem, $decisao, $decisao, $id]);
}

/** Qualidade por técnico nas linhas (já com 'an'): [['nome', 'inst', 'aprovada', 'com_os', 'nao_pagar', 'obs'], ...] */
function inst_an_por_tecnico(array $linhas): array
{
    $out = [];
    foreach ($linhas as $r) {
        if (!isset($r['an']) || $r['an']['sit'] === 'fora') continue;
        foreach ($r['tecs'] as $t) {
            $o = &$out[$t['id']];
            $o ??= ['nome' => $t['nome'], 'inst' => 0, 'aprovada' => 0, 'com_os' => 0, 'nao_pagar' => 0, 'obs' => 0];
            $o['inst']++;
            $s = $r['an']['sit'];
            if ($s === 'aprovada') $o['aprovada']++;
            elseif (in_array($s, ['analisar', 'pagar', 'nao_pagar'], true)) $o['com_os']++;
            else $o['obs']++;
            if ($s === 'nao_pagar') $o['nao_pagar']++;
            unset($o);
        }
    }
    $out = array_values($out);
    usort($out, fn($a, $b) => [$b['com_os'], $a['nome']] <=> [$a['com_os'], $b['nome']]);
    return $out;
}

/*
 * Puxar do IXC as instalações concluídas (pedido do ALEX, 2026-11-24): todas as O.S finalizadas no mês com os assuntos
 * escolhidos (ex.: instalação, mudança de endereço) entram em Instalações concluídas e na análise de 30 dias.
 * Só leitura no IXC. Cada assunto escolhido vira um tipo de serviço do portal (config 'importar' => [assunto_id => servico_id]).
 * O técnico vem do IXC (id_tecnico); quem agendou e o 2º técnico o gestor completa em "Corrigir".
 */

/** Assuntos do IXC que são instalação, informados pelo ALEX em 2026-11-24 (padrão até alguém mudar na tela) */
const INST_IMP_PADRAO = ['11' => 0, '31' => 0];

/** Assuntos escolhidos para puxar: [assunto_id => servico_id] (0 = escolhe pelo nome do assunto) */
function inst_imp_assuntos(): array
{
    $c = inst_an_config();
    return array_map('intval', array_key_exists('importar', $c) ? (array)$c['importar'] : INST_IMP_PADRAO);
}

/** Serviço do portal para um assunto sem serviço escolhido: "mudança" -> MUDANÇA DE ENDEREÇO, senão INSTALAÇÃO NOVA (ou um com o nome do assunto). */
function inst_imp_servico_padrao(string $assunto): int
{
    $a = up(sem_acento($assunto));
    $alvo = str_contains($a, 'MUDANCA') ? 'MUDANÇA DE ENDEREÇO' : (str_contains($a, 'PONTO') ? '2º PONTO' : 'INSTALAÇÃO NOVA');
    return inst_lista_por_nome('servico', $alvo) ?: inst_lista_por_nome('servico', $assunto ?: $alvo, true);
}

/** Salva os assuntos marcados para puxar: $in = [assunto_id => ['on' => 1, 'servico' => id]] */
function inst_imp_config_salvar(array $in): int
{
    $c = inst_an_config();
    $imp = [];
    foreach ($in as $aid => $l) {
        if (empty($l['on']) || !isset($c['assuntos'][(string)$aid])) continue;
        $sid = (int)($l['servico'] ?? 0);
        if (!$sid) { require_once __DIR__ . '/ixc.php'; $sid = inst_imp_servico_padrao((string)$c['assuntos'][(string)$aid]); }
        $imp[(string)$aid] = $sid;
    }
    $c['importar'] = $imp;
    inst_an_config_gravar($c);
    return count($imp);
}

/**
 * Puxa do IXC as O.S finalizadas no mês (aaaa-mm) com os assuntos escolhidos e lança as que ainda não estão no portal.
 * Devolve ['novas' => n, 'ja' => n, 'sem_tec' => n].
 */
function inst_imp_puxar(string $mes): array
{
    if (destino() !== 'ixc') throw new RuntimeException('Puxar instalações só funciona com o IXC.');
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) throw new RuntimeException('Escolha o mês.');
    $imp = inst_imp_assuntos();
    if (!$imp) throw new RuntimeException('Escolha antes quais assuntos de O.S são instalação (ex.: instalação, mudança de endereço).');
    require_once __DIR__ . '/ixc.php';
    $ini = $mes . '-01';
    $fim = (new DateTimeImmutable($ini))->modify('last day of this month')->format('Y-m-d');
    $parada = (new DateTimeImmutable($ini))->modify('-120 day')->format('Y-m-d'); // O.S abertas bem antes do mês não fecham nele
    $nomesA = inst_an_config()['assuntos'];
    $ja = db()->prepare("SELECT 1 FROM instalacoes WHERE os = ?");
    $ins = db()->prepare("INSERT INTO instalacoes (cliente, contrato, os, assunto, abertura, fechamento, servico_id, lancado_por, ixc_cliente, ixc_tecnico, obs) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $insT = db()->prepare('INSERT OR IGNORE INTO inst_tec (inst_id, tecnico_id, valor) VALUES (?, ?, \'\')');
    $clientes = $tecnicos = [];
    $out = ['novas' => 0, 'ja' => 0, 'sem_tec' => 0, 'ajudantes' => 0];
    $semAj = db()->prepare("SELECT id FROM instalacoes WHERE os = ? AND ajudante = '' AND pago_em = ''");
    foreach ($imp as $aid => $sid) {
        $aid = (string)$aid;
        if (!isset($nomesA[$aid])) {
            try { $nomesA[$aid] = up((string)(ixc()->um('su_oss_assunto', 'id', $aid)['assunto'] ?? '')); } catch (Throwable) { $nomesA[$aid] = ''; }
        }
        if (!$sid) $sid = inst_imp_servico_padrao($nomesA[$aid]);
        for ($pg = 1; $pg <= 20; $pg++) {
            $lista = ixc()->listar('su_oss_chamado', 'id_assunto', (string)$aid, '=', 500, 'desc', $pg);
            if (!$lista) break;
            $velhas = 0;
            foreach ($lista as $o) {
                $ab = rec_data_ixc($o['data_abertura'] ?? '');
                if ($ab !== '' && $ab < $parada) { $velhas++; continue; }
                $fe = rec_data_ixc($o['data_fechamento'] ?? '');
                if (($o['status'] ?? '') !== 'F' || $fe < $ini || $fe > $fim) continue;
                $os = so_digitos((string)($o['id'] ?? ''));
                if ($os === '') continue;
                $ja->execute([$os]);
                if ($ja->fetchColumn()) {
                    $out['ja']++;
                    // já estava: se o ajudante ainda não foi informado, tenta pela linha "AJUDANTE:" da O.S
                    $semAj->execute([$os]);
                    if (($iid = (int)$semAj->fetchColumn()) && inst_imp_ajudante_aplicar($iid, $o)) $out['ajudantes']++;
                    continue;
                }
                $cid = so_digitos((string)($o['id_cliente'] ?? ''));
                if ($cid !== '' && !isset($clientes[$cid])) {
                    try { $clientes[$cid] = up((string)(ixc()->um('cliente', 'id', $cid)['razao'] ?? '')); } catch (Throwable) { $clientes[$cid] = ''; }
                }
                // técnico da O.S no IXC: só entra se for um técnico de instalação ativo no portal (lista de técnicos /
                // Colaboradores do IXC). Funcionário do escritório que fechou a O.S não vira técnico.
                $tid = so_digitos((string)($o['id_tecnico'] ?? ''));
                if ($tid !== '' && $tid !== '0' && !isset($tecnicos[$tid])) {
                    try { $f = ixc()->um('funcionarios', 'id', $tid); $nf = up((string)($f['funcionario'] ?? ($f['nome'] ?? ''))); } catch (Throwable) { $nf = ''; }
                    $tecnicos[$tid] = [$nf, inst_imp_tecnico_ativo($nf)];
                }
                [$nomeTec, $tec] = $tecnicos[$tid] ?? ['', 0];
                $ct = so_digitos((string)($o['id_contrato_kit'] ?? ''));
                db()->beginTransaction();
                $ins->execute([($clientes[$cid] ?? '') ?: 'CLIENTE ' . $cid, $ct === '0' ? '' : $ct, $os, $nomesA[$aid], $ab, $fe, $sid, 'IXC (puxado)', $cid, $nomeTec,
                    $tec ? '' : inst_imp_aviso_tec($nomeTec)]);
                $iid = (int)db()->lastInsertId();
                if ($tec) $insT->execute([$iid, $tec]);
                db()->commit();
                if (inst_imp_ajudante_aplicar($iid, $o)) $out['ajudantes']++;
                $out['novas']++;
                if (!$tec) $out['sem_tec']++;
            }
            if ($velhas === count($lista) || count($lista) < 500) break;
        }
    }
    $out['limpas'] = inst_imp_limpar();
    return $out;
}

/**
 * Ajudante escrito na O.S do IXC (pedido do ALEX, 2026-11-24): uma linha "AJUDANTE: NOME" ou "AJUDANTE: NÃO"
 * na observação/mensagem da O.S ou na mensagem de fechamento. Devolve null (sem a linha), 'N' (sem ajudante) ou o nome.
 */
function inst_imp_ajudante_texto(array $o): ?string
{
    $txt = '';
    foreach (['mensagem_resposta', 'mensagem', 'obs', 'observacao'] as $c) $txt .= "\n" . (string)($o[$c] ?? '');
    $txt = html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>'], "\n", $txt)), ENT_QUOTES, 'UTF-8');
    if (!preg_match_all('/AJUDANTE\s*[:=\-]\s*([^\r\n;|]+)/iu', $txt, $m)) return null;
    $v = up(trim(end($m[1]), " .,-\t")); // vale a última linha escrita
    if ($v === '') return null;
    return preg_match('/^(N[AÃ]O|NENHUM|SEM|SOZINHO|-)\b/u', $v) ? 'N' : $v;
}

/** Aplica o ajudante escrito na O.S do IXC na instalação $id (só se ainda não informado). Devolve true se gravou. */
function inst_imp_ajudante_aplicar(int $id, array $o): bool
{
    $v = inst_imp_ajudante_texto($o);
    if ($v === null) return false;
    $r = inst_um($id);
    if (!$r || $r['ajudante'] !== '' || $r['pago_em'] !== '') return false;
    if ($v === 'N') { db()->prepare("UPDATE instalacoes SET ajudante = 'N', ajudante_id = 0 WHERE id = ?")->execute([$id]); return true; }
    $aj = inst_imp_tecnico_ativo($v);
    if (!$aj) { // só o primeiro nome ou parte do nome: vale se um técnico só começa assim
        $alvo = up(sem_acento($v));
        $acha = array_values(array_filter(inst_tecnicos(true), fn($t) => str_starts_with(up(sem_acento($t['nome'])) . ' ', $alvo . ' ')));
        if (count($acha) === 1) $aj = (int)$acha[0]['id'];
    }
    $aviso = '';
    if (!$aj) $aviso = "Ajudante no IXC: $v (não está na lista de técnicos): escolha o ajudante.";
    elseif (!$r['tecs']) $aviso = "Ajudante no IXC: $v. Escolha o técnico que fez para gravar o ajudante.";
    elseif (in_array($aj, array_column($r['tecs'], 'id'), true)) $aviso = "No IXC o ajudante ($v) é o mesmo técnico da O.S: confira.";
    if ($aviso !== '') {
        if (!str_contains($r['obs'], $aviso)) db()->prepare("UPDATE instalacoes SET obs = TRIM(obs || ' ' || ?) WHERE id = ?")->execute([$aviso, $id]);
        return false;
    }
    try { inst_ajudante_salvar($id, (string)$aj, 'IXC (puxado)'); } catch (Throwable) { return false; }
    return true;
}

/** Id do técnico de instalação ATIVO com esse nome (sem acento), ou 0. Não cria. */
function inst_imp_tecnico_ativo(string $nome): int
{
    if ($nome === '') return 0;
    $alvo = up(sem_acento($nome));
    foreach (inst_tecnicos(true) as $t) if (up(sem_acento($t['nome'])) === $alvo) return (int)$t['id'];
    return 0;
}

function inst_imp_aviso_tec(string $nomeIxc): string
{
    return $nomeIxc === '' ? 'Sem técnico no IXC: escolha quem fez.'
        : "No IXC a O.S está com $nomeIxc, que não é técnico de instalação no portal: escolha quem fez.";
}

/**
 * Instalações puxadas do IXC, não pagas, com técnico que foi escondido da lista (ex.: funcionário do escritório que
 * fechou a O.S e entrou como técnico antes desta regra): tira esse técnico e deixa o aviso para escolher quem fez.
 */
function inst_imp_limpar(): int
{
    $q = db()->query("SELECT i.id, i.ixc_tecnico, it.tecnico_id, t.nome FROM instalacoes i JOIN inst_tec it ON it.inst_id = i.id
        JOIN inst_tecnicos t ON t.id = it.tecnico_id WHERE i.lancado_por = 'IXC (puxado)' AND i.pago_em = '' AND t.ativo = 0");
    $del = db()->prepare('DELETE FROM inst_tec WHERE inst_id = ? AND tecnico_id = ?');
    $obs = db()->prepare("UPDATE instalacoes SET conferido = 0, ixc_tecnico = CASE WHEN ixc_tecnico = '' THEN ? ELSE ixc_tecnico END,
        obs = CASE WHEN (SELECT COUNT(*) FROM inst_tec WHERE inst_id = instalacoes.id) = 0 THEN ? ELSE obs END WHERE id = ?");
    $n = 0;
    foreach ($q->fetchAll() as $r) {
        $del->execute([$r['id'], $r['tecnico_id']]);
        $obs->execute([$r['nome'], inst_imp_aviso_tec($r['ixc_tecnico'] ?: $r['nome']), $r['id']]);
        $n++;
    }
    return $n;
}

/**
 * Ajudante da instalação (pedido do ALEX, 2026-11-24): 'N' = sem ajudante, ou o id de um técnico da lista.
 * O ajudante entra como mais um técnico da O.S (fica em dupla na comissão); trocar o ajudante tira o anterior.
 * Mudou a equipe: a O.S volta sem ✔ na comissão.
 */
function inst_ajudante_salvar(int $id, string $valor, string $quem, int $tecnico = 0): array
{
    $r = inst_um($id);
    if (!$r) throw new RuntimeException('Essa instalação não existe mais.');
    if ($r['pago_em'] !== '') throw new RuntimeException('A comissão dessa instalação já foi paga.');
    if (!$r['tecs']) {
        // O.S puxada sem técnico: escolhe aqui mesmo quem fez
        if (!$tecnico) throw new RuntimeException('Escolha primeiro o técnico que fez a instalação.');
        $q = db()->prepare('SELECT 1 FROM inst_tecnicos WHERE id = ?');
        $q->execute([$tecnico]);
        if (!$q->fetchColumn()) throw new RuntimeException('Técnico não encontrado. Escolha de novo.');
        if ($tecnico === (int)$valor) throw new RuntimeException('O ajudante não pode ser o próprio técnico.');
        db()->prepare("INSERT INTO inst_tec (inst_id, tecnico_id, valor) VALUES (?, ?, '')")->execute([$id, $tecnico]);
        db()->prepare("UPDATE instalacoes SET obs = CASE WHEN lancado_por = 'IXC (puxado)' THEN '' ELSE obs END, conferido = 0 WHERE id = ?")->execute([$id]);
        $r = inst_um($id);
    }
    $novo = $valor === 'N' ? 0 : (int)$valor;
    if ($valor !== 'N' && !$novo) throw new RuntimeException('Escolha o ajudante ou "Sem ajudante".');
    $tecIds = array_column($r['tecs'], 'id');
    if ($novo) {
        $q = db()->prepare('SELECT nome FROM inst_tecnicos WHERE id = ?');
        $q->execute([$novo]);
        if ($q->fetchColumn() === false) throw new RuntimeException('Ajudante não encontrado. Escolha de novo.');
        if (in_array($novo, $tecIds, true) && $novo !== (int)$r['ajudante_id']) throw new RuntimeException('Essa pessoa já é o técnico da O.S.');
    }
    $db = db();
    $db->beginTransaction();
    $velho = (int)$r['ajudante_id'];
    if ($velho && $velho !== $novo && count($tecIds) > 1) $db->prepare('DELETE FROM inst_tec WHERE inst_id = ? AND tecnico_id = ?')->execute([$id, $velho]);
    if ($novo && !in_array($novo, $tecIds, true)) {
        if (count($tecIds) >= INST_MAX_TEC) { $db->rollBack(); throw new RuntimeException('No máximo ' . INST_MAX_TEC . ' técnicos por O.S.'); }
        $db->prepare("INSERT INTO inst_tec (inst_id, tecnico_id, valor) VALUES (?, ?, '')")->execute([$id, $novo]);
    }
    $mudou = $velho !== $novo;
    $db->prepare("UPDATE instalacoes SET ajudante = ?, ajudante_id = ?, alterado_por = ?, conferido = CASE WHEN ? THEN 0 ELSE conferido END WHERE id = ?")
        ->execute([$novo ? 'S' : 'N', $novo, $quem, $mudou ? 1 : 0, $id]);
    $db->commit();
    return inst_um($id);
}
