<?php
/**
 * Repasse das vendas recebidas para o CRM.
 *
 * Venda nova e 2º plano  -> tela Comercial → Novo Lead      (novo-lead.php)
 * Upgrade                -> Upgrades e Renegociações → Lançar à mão (servicos-vendedor.php, acao=manual)
 *
 * O mapeamento dos campos fica aqui no servidor, e não no celular: se o CRM mudar,
 * corrige-se aqui uma vez, sem atualizar o app de cada vendedor.
 */
declare(strict_types=1);

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/crm.php';

const UF_POR_DDD = ['31' => 'MG', '32' => 'MG', '33' => 'MG', '34' => 'MG', '35' => 'MG', '37' => 'MG', '38' => 'MG', '27' => 'ES', '28' => 'ES'];

function crm(): Crm
{
    static $c = null;
    $cfg = config();
    return $c ??= new Crm($cfg['crm_usuario'], $cfg['crm_senha'], $cfg['crm_url'], dirname($cfg['banco']));
}

/** Serviços extras do upgrade, no mesmo texto que o app manda no WhatsApp. */
function extras_upgrade(array $r): array
{
    $ex = [];
    $d = fn(string $k) => up($r[$k . 'Obs'] ?? '') !== '' ? ' (' . up($r[$k . 'Obs']) . ')' : '';
    if (!empty($r['exRoteador'])) $ex[] = 'SERA COLOCADO UM SEGUNDO ROTEADOR' . $d('exRoteador');
    if (!empty($r['exCabo'])) $ex[] = 'FAZER PASSAGEM DO CABO' . $d('exCabo');
    if (!empty($r['exTroca'])) $ex[] = 'TROCA DE EQUIPAMENTO DO CLIENTE' . $d('exTroca');
    return $ex;
}

function observacao(array $r, string $idApp, array $vendedor): string
{
    $o = [];
    $tipo = $r['tipo'] ?? 'venda';
    if ($tipo === 'upgrade') {
        $o = extras_upgrade($r);
        $o[] = 'TEL: ' . ($r['tel1'] ?? '') . ' / ' . ($r['tel2'] ?? '');
        $o[] = 'E-MAIL: ' . strtolower(trim((string)($r['email'] ?? '')));
    } else {
        if ($tipo === 'venda' && up($r['apelido'] ?? '') !== '') $o[] = 'APELIDO: ' . up($r['apelido']);
        if ($tipo === 'segundo') $o[] = 'SEGUNDO PLANO NO MESMO CLIENTE';
        $o[] = 'PROVEDOR ATUAL: ' . up($r['provedor'] ?? '');
        if (trim((string)($r['local'] ?? '')) !== '') $o[] = 'LOCAL (GPS): ' . trim($r['local']);
    }
    if (so_digitos($r['valInst'] ?? '') !== '' && (int)so_digitos($r['valInst']) > 0) $o[] = 'INSTALAÇÃO R$ ' . valor_brl($r['valInst']);
    if (so_digitos($r['valRot'] ?? '') !== '' && (int)so_digitos($r['valRot']) > 0) $o[] = 'ROTEADOR VENDIDO R$ ' . valor_brl($r['valRot']);
    if (trim((string)($r['obs'] ?? '')) !== '') $o[] = up($r['obs']);
    $o[] = 'VENDEDOR NO APP: ' . $vendedor['nome'];
    $o[] = 'VIA APP · ' . $idApp;
    return implode(' · ', $o);
}

/** Campos do Novo Lead a partir do cadastro do app (sobre os valores padrão da tela). */
function campos_lead(array $padrao, array $r, array $venda, array $vendedor, string $htmlForm): array
{
    $cfg = config();
    $tipo = $r['tipo'] ?? 'venda';
    $f = $padrao;
    $f['id'] = '0';
    $f['confirma_duplicado'] = !empty($venda['forcar_duplicado']) ? '1' : '0';
    $f['empresa_id'] = (string)$cfg['empresa_id'];
    $f['data_lead'] = data_iso($r['criado'] ?? '') ?: date('Y-m-d');
    $f['nome'] = up($r['nome'] ?? '');
    $f['tipo_pessoa'] = 'F';
    $f['cpf'] = (string)($r['cpf'] ?? '');
    $f['whatsapp'] = (string)($r['tel1'] ?? '');
    $f['telefone_residencial'] = (string)($r['tel2'] ?? '');
    $f['email'] = strtolower(trim((string)($r['email'] ?? '')));
    if ($tipo === 'venda') $f['data_nascimento'] = data_iso($r['nasc'] ?? '');
    $f['logradouro'] = up($r['endereco'] ?? '');
    $f['cidade'] = up($r['cidade'] ?? '');
    $f['uf'] = UF_POR_DDD[substr(so_digitos($r['tel1'] ?? ''), 0, 2)] ?? ($cfg['uf_padrao'] ?? 'MG');
    $f['referencia'] = up($r['referencia'] ?? '');
    $f['vendedor_id'] = $vendedor['crm_vendedor_id'];
    $f['origem'] = $cfg['origem'] ?? 'Outros';
    $f['plano_interesse'] = (string)($r['plano'] ?? '');
    foreach (Crm::opcoes($htmlForm, 'plano_interesse') as $p) {
        if (up($p['valor']) === up($r['plano'] ?? '')) {
            $f['plano_interesse'] = $p['valor'];
            $f['valor'] = $p['data_valor'];
        }
    }
    $f['status'] = $cfg['status_venda'] ?? 'Venda Fechada';
    $f['dia_vencimento'] = (string)($r['venc'] ?? '');
    $f['perfil_cliente'] = $tipo === 'segundo' ? 'Cliente atual' : 'Novo cliente';
    $f['tipo_servico'] = 'Instalação';
    $f['observacao'] = observacao($r, $venda['id_app'], $vendedor);
    return $f;
}

function campos_upgrade(array $padrao, array $r, array $venda, array $vendedor): array
{
    $f = $padrao;
    $f['cliente'] = up($r['nome'] ?? '');
    $f['doc'] = (string)($r['cpf'] ?? '');
    $f['data'] = data_iso($r['criado'] ?? '') ?: date('Y-m-d');
    $f['vendedor_id'] = $vendedor['crm_vendedor_id'];
    $f['plano_de'] = '';
    $f['plano_para'] = up($r['velNova'] ?? '');
    $f['valor_de'] = valor_ponto($r['valAtual'] ?? '');
    $f['valor_para'] = valor_ponto($r['valNovo'] ?? '');
    $f['observacao'] = observacao($r, $venda['id_app'], $vendedor);
    return $f;
}

/** Envia uma venda ao CRM. Devolve o identificador no CRM ou lança ErroCrm. */
function repassar(array $venda, array $vendedor): string
{
    if ($vendedor['crm_vendedor_id'] === '') {
        throw new ErroCrm('O vendedor ' . $vendedor['nome'] . ' não está ligado a um vendedor do CRM. Ajuste no painel.');
    }
    $r = json_decode($venda['dados'], true) ?: [];
    $c = crm();

    if ($venda['tipo'] === 'upgrade') {
        $html = $c->abrir('/servicos-vendedor.php');
        $padrao = Crm::formulario($html, 'sv-form');
        $resp = $c->enviar('/servicos-vendedor.php', campos_upgrade($padrao, $r, $venda, $vendedor));
        if ($resp['status'] === 302 && !str_contains($resp['local'], 'login')) {
            return 'Upgrade lançado';
        }
        throw new ErroCrm(Crm::aviso($resp['corpo']) ?: 'O CRM não aceitou o upgrade (HTTP ' . $resp['status'] . ').');
    }

    $html = $c->abrir('/novo-lead.php');
    $padrao = Crm::formulario($html, 'lead-form-grid');
    $resp = $c->enviar('/novo-lead.php', campos_lead($padrao, $r, $venda, $vendedor, $html));
    if ($resp['status'] === 302 && !str_contains($resp['local'], 'login')) {
        return preg_match('/[?&]id=(\d+)/', $resp['local'], $m) ? 'Lead #' . $m[1] : 'Lead gravado';
    }
    if ($resp['status'] === 302) {
        throw new ErroCrm('A sessão do CRM caiu no meio do envio.', true);
    }
    $aviso = Crm::aviso($resp['corpo']);
    if (stripos($resp['corpo'], 'dupModal') !== false || stripos($aviso, 'duplic') !== false || stripos($aviso, 'já cadastrado') !== false) {
        throw new ErroCrm('Possível cliente duplicado no CRM. Confira e use "Enviar mesmo assim" no painel. ' . $aviso);
    }
    throw new ErroCrm($aviso ?: 'O CRM não aceitou o cadastro (HTTP ' . $resp['status'] . ').');
}

/** Processa a fila. Chamado pelo cron a cada minuto. */
function processar_fila(int $limite = 20): array
{
    $pdo = db();
    $q = $pdo->query("SELECT * FROM vendas WHERE status IN ('fila','forcar') OR (status = 'tentar' AND tentativas < 10) ORDER BY id LIMIT " . $limite);
    $feitos = [];
    foreach ($q->fetchAll() as $v) {
        $vend = $pdo->query('SELECT * FROM vendedores WHERE id = ' . (int)$v['vendedor_id'])->fetch();
        $v['forcar_duplicado'] = $v['status'] === 'forcar';
        try {
            $crmId = repassar($v, $vend);
            $pdo->prepare("UPDATE vendas SET status='crm', crm_id=?, erro='', enviado_crm_em=datetime('now','localtime'), atualizado_em=datetime('now','localtime') WHERE id=?")
                ->execute([$crmId, $v['id']]);
            $feitos[] = "#{$v['id']} {$v['nome']}: $crmId";
        } catch (ErroCrm $e) {
            $st = $e->temporario ? 'tentar' : 'erro';
            $pdo->prepare("UPDATE vendas SET status=?, erro=?, tentativas=tentativas+1, atualizado_em=datetime('now','localtime') WHERE id=?")
                ->execute([$st, $e->getMessage(), $v['id']]);
            $feitos[] = "#{$v['id']} {$v['nome']}: ERRO " . $e->getMessage();
            if ($e->temporario) {
                break; // CRM fora do ar: não insiste com o resto agora
            }
        }
    }
    return $feitos;
}
