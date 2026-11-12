<?php
/**
 * Envio das vendas para o IXC Provedor (API oficial: https://SEU-IXC/webservice/v1/<tabela>).
 *
 *   Venda nova    -> CRM do IXC → Leads (tabela contato, lead = S)
 *   Upgrade       -> Atendimento (su_ticket) no cliente, com o assunto de upgrade; o processo do assunto abre a O.S.
 *   2º ponto      -> Contrato novo (pré-contrato) + login novo ligado a ele + atendimento com o assunto de 2º ponto
 *
 * Autenticação: token do usuário do IXC ("id:hash"), em Basic. O token fica num arquivo próprio
 * (/etc/comercial-sidnet/ixc-token), gravado por "php server/bin/ixc.php token", nunca no git.
 */
declare(strict_types=1);

require_once __DIR__ . '/base.php';
require_once __DIR__ . '/vendedores.php'; // gerar_chave
require_once __DIR__ . '/crm.php'; // ErroCrm (erro de envio, temporário ou não)

final class Ixc
{
    private string $base;

    public function __construct(string $url, private string $token)
    {
        // usa só https://host, seja qual for a tela colada (/adm.php, /app/login, /webservice/v1...)
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $this->base = (preg_match('#^(https?://[^/?\#\s]+)#i', $url, $m) ? $m[1] : rtrim($url, '/')) . '/webservice/v1';
    }

    /** Chamada à API. $listar = true usa o cabeçalho "ixcsoft: listar" (consulta). */
    private function chamar(string $tabela, array $corpo, bool $listar): array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $tabela)) {
            throw new ErroCrm('Tabela do IXC inválida.');
        }
        $cab = ['Content-Type: application/json', 'Authorization: Basic ' . base64_encode($this->token)];
        if ($listar) {
            $cab[] = 'ixcsoft: listar';
        }
        $ch = curl_init($this->base . '/' . $tabela);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($corpo, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $cab,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_USERAGENT => 'ComercialSidnetApp/2.2',
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $e = curl_error($ch);
            curl_close($ch);
            throw new ErroCrm('Sem conexão com o IXC: ' . $e, true);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status === 401 || $status === 403) {
            throw new ErroCrm('O IXC recusou o token (HTTP ' . $status . '). Confira o token e as permissões do usuário do app no IXC.');
        }
        if ($status >= 500) {
            throw new ErroCrm('O IXC respondeu erro ' . $status . '.', true);
        }
        $j = json_decode($resp, true);
        if (!is_array($j)) {
            throw new ErroCrm('Resposta inesperada do IXC (HTTP ' . $status . ').');
        }
        return $j;
    }

    /** Consulta: devolve a lista de registros. */
    public function listar(string $tabela, string $campo, string $valor, string $oper = '=', int $quantos = 50, string $ordem = 'desc'): array
    {
        $j = $this->chamar($tabela, [
            'qtype' => $tabela . '.' . $campo, 'query' => $valor, 'oper' => $oper,
            'page' => '1', 'rp' => (string)$quantos, 'sortname' => $tabela . '.id', 'sortorder' => $ordem,
        ], true);
        if (($j['type'] ?? '') === 'error') {
            throw new ErroCrm('IXC (' . $tabela . '): ' . self::texto($j['message'] ?? 'erro na consulta'));
        }
        return is_array($j['registros'] ?? null) ? $j['registros'] : [];
    }

    public function um(string $tabela, string $campo, string $valor): ?array
    {
        return $this->listar($tabela, $campo, $valor, '=', 1)[0] ?? null;
    }

    /** Inclui um registro e devolve o id criado. */
    public function inserir(string $tabela, array $dados): string
    {
        $j = $this->chamar($tabela, $dados, false);
        if (($j['type'] ?? '') !== 'success' || empty($j['id'])) {
            throw new ErroCrm('O IXC não aceitou (' . $tabela . '): ' . self::texto($j['message'] ?? 'sem mensagem'));
        }
        return (string)$j['id'];
    }

    private static function texto(mixed $m): string
    {
        return mb_substr(trim(strip_tags(is_string($m) ? $m : json_encode($m, JSON_UNESCAPED_UNICODE))), 0, 300);
    }
}

function ixc_config(): array
{
    $c = config()['ixc'] ?? [];
    if (empty($c['url'])) {
        throw new ErroCrm('O IXC não está configurado: falta ixc.url no config.php.');
    }
    return $c;
}

function ixc_token_arquivo(): string
{
    return (string)(config()['ixc']['token_arquivo'] ?? '/etc/comercial-sidnet/ixc-token');
}

function ixc(): Ixc
{
    static $c = null;
    if ($c === null) {
        $cfg = ixc_config();
        $tok = trim((string)@file_get_contents(ixc_token_arquivo()));
        if (!preg_match('/^\d+:[A-Za-z0-9]{20,}$/', $tok)) {
            throw new ErroCrm('Token do IXC não encontrado. No servidor, rode: php server/bin/ixc.php token');
        }
        $c = new Ixc((string)$cfg['url'], $tok);
    }
    return $c;
}

/** "12345678901" -> "123.456.789-01" (o IXC guarda o CPF com máscara) */
function cpf_mascara(string $cpf): string
{
    $d = so_digitos($cpf);
    return strlen($d) === 11 ? substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9) : $d;
}

/** Cliente do IXC pelo CPF (com ou sem máscara). */
function ixc_cliente_por_cpf(string $cpf): ?array
{
    $d = so_digitos($cpf);
    if (strlen($d) !== 11) {
        return null;
    }
    $api = ixc();
    return $api->um('cliente', 'cnpj_cpf', cpf_mascara($d)) ?? $api->um('cliente', 'cnpj_cpf', $d);
}

/**
 * Valores padrão do cadastro de cliente no IXC (os mesmos da documentação da API e da tela).
 * Pela API, campo de seleção que não vai fica vazio e o IXC recusa ("valor ... inválido").
 */
const IXC_CLIENTE_PADRAO = [
    'tipo_cliente_scm' => '01', 'pais' => 'Brasil', 'cadunico' => 'N', 'filtra_filial' => 'S', 'convert_cliente_forn' => 'N',
    'acesso_automatico_central' => 'P', 'alterar_senha_primeiro_acesso' => 'P', 'senha_hotsite_md5' => 'N', 'hotsite_acesso' => '2',
    'cadastrado_via_viabilidade' => 'N', 'participa_cobranca' => 'S', 'participa_pre_cobranca' => 'S',
    'cob_envia_email' => 'S', 'cob_envia_sms' => 'S', 'tipo_pessoa_titular_conta' => 'F',
    'regua_cobranca_considera' => 'P', 'regua_cobranca_wpp' => 'S', 'regua_cobranca_notificacao' => 'S', 'orgao_publico' => 'N',
    'pis_retem' => 'S', 'cofins_retem' => 'S', 'csll_retem' => 'S', 'irrf_retem' => 'S', 'desconto_irrf_valor_inferior' => 'N',
    'inss_retem' => 'S', 'cli_desconta_iss_retido_total' => 'S', 'percentual_reducao' => '0.00',
];

/** Plano de venda (vd_contratos) pelo nome que o app usa. */
function ixc_plano(string $nome): ?array
{
    $nome = up($nome);
    if ($nome === '') {
        return null;
    }
    foreach (ixc()->listar('vd_contratos', 'nome', $nome, 'L', 50) as $p) {
        if (up($p['nome'] ?? '') === $nome) {
            return $p;
        }
    }
    return null;
}

/** Id da cidade no IXC pelo nome (e UF, se houver mais de uma). */
function ixc_cidade(string $nome, string $uf): string
{
    $nome = up($nome);
    if ($nome === '') {
        return '';
    }
    $achou = '';
    foreach (ixc()->listar('cidade', 'nome', $nome, 'L', 30) as $c) {
        if (up(sem_acento($c['nome'] ?? '')) === up(sem_acento($nome))) {
            $achou = $achou ?: (string)$c['id'];
            if ($uf !== '' && in_array(up((string)($c['uf'] ?? '')), [up($uf)], true)) {
                return (string)$c['id'];
            }
        }
    }
    return $achou;
}

function sem_acento(string $s): string
{
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return $t === false ? $s : $t;
}

/** "10/11/2026 14:30" -> "10/11/2026"; vazio vira hoje. */
function data_br(?string $s): string
{
    return preg_match('#^(\d{2}/\d{2}/\d{4})#', (string)$s, $m) ? $m[1] : date('d/m/Y');
}

/** Texto completo do cadastro, para a observação do lead e a mensagem do atendimento. */
function ixc_descricao(array $r, array $venda, array $vendedor): string
{
    $tipo = $r['tipo'] ?? 'venda';
    $L = [];
    $add = function (string $rot, mixed $v) use (&$L): void {
        $v = trim((string)$v);
        if ($v !== '') $L[] = $rot . ': ' . $v;
    };
    $L[] = ['venda' => 'VENDA NOVA', 'segundo' => 'SEGUNDO PONTO', 'upgrade' => 'UPGRADE'][$tipo] . ' PELO APP COMERCIAL + SIDNET';
    $add('CLIENTE', up($r['nome'] ?? ''));
    $add('CPF', $r['cpf'] ?? '');
    $add('TELEFONE', $r['tel1'] ?? '');
    $add('TELEFONE 2', $r['tel2'] ?? '');
    $add('E-MAIL', strtolower(trim((string)($r['email'] ?? ''))));
    if ($tipo === 'upgrade') {
        $add('VELOCIDADE ATUAL', up($r['velAtual'] ?? ''));
        $add('VALOR ATUAL', ($r['valAtual'] ?? '') !== '' ? 'R$ ' . valor_brl($r['valAtual']) : '');
        $add('NOVA VELOCIDADE', up($r['velNova'] ?? ''));
        $add('NOVO VALOR', ($r['valNovo'] ?? '') !== '' ? 'R$ ' . valor_brl($r['valNovo']) : '');
        foreach (extras_upgrade($r) as $e) $L[] = $e;
    } else {
        $add('PLANO', up($r['plano'] ?? ''));
        $add('VENCIMENTO', ($r['venc'] ?? '') !== '' ? 'DIA ' . $r['venc'] : '');
        $end = trim(implode(', ', array_filter([up($r['rua'] ?? ($r['endereco'] ?? '')), up($r['numero'] ?? ''), up($r['complemento'] ?? ''), up($r['bairro'] ?? ''), up($r['cidade'] ?? ''), up($r['uf'] ?? '')])));
        $add('ENDEREÇO', $end);
        $add('CEP', $r['cep'] ?? '');
        $add('REFERÊNCIA', up($r['referencia'] ?? ''));
        $add('PROVEDOR ATUAL', up($r['provedor'] ?? ''));
        $add('CANAL DE VENDA', up($r['canal'] ?? ''));
        if (up($r['zona'] ?? '') === 'R') $L[] = 'ZONA RURAL';
        $add('LOCAL (GPS)', $r['local'] ?? '');
        if ($tipo === 'venda') $add('APELIDO', up($r['apelido'] ?? ''));
    }
    if ((int)so_digitos($r['valInst'] ?? '') > 0) $L[] = 'INSTALAÇÃO: R$ ' . valor_brl($r['valInst']);
    if ((int)so_digitos($r['valRot'] ?? '') > 0) $L[] = 'ROTEADOR VENDIDO: R$ ' . valor_brl($r['valRot']);
    $add('OBSERVAÇÕES', up($r['obs'] ?? ''));
    $L[] = 'VENDEDOR: ' . $vendedor['nome'];
    $L[] = 'CADASTRO NO APP: ' . $venda['id_app'];
    return texto_crm(implode("\n", $L), 6000);
}

/** Lê o que já foi criado no IXC para esta venda (para não duplicar se precisar tentar de novo). */
function ixc_passos(array $venda): array
{
    return json_decode((string)($venda['ixc_passos'] ?? ''), true) ?: [];
}

function ixc_salvar_passos(array $venda, array $p): void
{
    db()->prepare('UPDATE vendas SET ixc_passos = ? WHERE id = ?')->execute([json_encode($p), $venda['id']]);
}

/** Envia uma venda ao IXC. Devolve o que foi criado (texto para o painel) ou lança ErroCrm. */
function repassar_ixc(array $venda, array $vendedor): string
{
    $r = registro_limpo(json_decode($venda['dados'], true) ?: []);
    $r['tipo'] = $venda['tipo'];
    return match ($venda['tipo']) {
        'upgrade' => ixc_upgrade($r, $venda, $vendedor),
        'segundo' => ixc_segundo($r, $venda, $vendedor),
        default => ixc_venda($r, $venda, $vendedor),
    };
}

/** Ids do IXC da Sidnet (vídeo do cadastro, 11/11/2026). O config.php pode trocar: ixc.canais / ixc.concorrentes. */
const IXC_CANAIS = [
    'WHATSAPP COMERCIAL' => '11', 'TELEFONE' => '13', 'SONDAGEM' => '6', 'SOLICITADO PELO CLIENTE' => '1',
    'PRESENCIAL' => '12', 'OPA! SUITE' => '10', 'INDICACAO DE OUTROS CLIENTES' => '4', 'INDICACAO DE CLIENTE' => '9',
    'GOOGLE' => '2', 'FEIRAS' => '7', 'FACEBOOK' => '3',
];
const IXC_CONCORRENTES = [
    'FIBRON TELECOM' => '7', 'IGAP NET TELECOM' => '2', 'JL TELECON' => '10', 'MAIS TELECOM' => '6', 'MG NET TELECOM' => '4',
    'NAO POSSUI' => '9', 'NENHUM' => '9', 'OUTROS' => '8', 'STATUS TELECOM' => '1', 'SUPERNET TELECOM' => '3', 'WAVE TELECOM' => '5',
];

/** Acha o id pelo nome numa lista nome => id (sem acento; aceita nome parcial como "IGAP"). */
function ixc_id_por_nome(array $mapa, string $nome, string $padrao = ''): string
{
    $n = up(sem_acento(trim($nome)));
    if ($n === '') return $padrao;
    $m = [];
    foreach ($mapa as $k => $v) $m[up(sem_acento((string)$k))] = (string)$v;
    if (isset($m[$n])) return $m[$n];
    foreach ($m as $k => $v) {
        if (str_starts_with($k, $n) || str_starts_with($n, explode(' ', $k)[0] . ' ') || $n === explode(' ', $k)[0]) return $v;
    }
    return $padrao;
}

/** Plano de negociação do CRM (crm_planos_negociacoes) ligado ao plano de venda. */
function ixc_plano_negociacao(array $plano): ?array
{
    $api = ixc();
    try {
        foreach ($api->listar('crm_planos_negociacoes', 'id_vd_contrato', (string)$plano['id'], '=', 5) as $p) return $p;
    } catch (ErroCrm) {
    }
    foreach ($api->listar('crm_planos_negociacoes', 'descricao', up($plano['nome']), 'L', 20) as $p) {
        if (up($p['descricao'] ?? '') === up($plano['nome'])) return $p;
    }
    return null;
}

/**
 * Venda nova, como no processo da Sidnet no IXC:
 *   1) Lead (CRM › Leads)  2) Prospecção (cliente em negociação)  3) Negociação com o plano.
 * O "Vencemos" (contrato, login e O.S.) fica para o escritório conferir no IXC.
 * Com ixc.venda_ate = 'lead' no config.php, faz só o passo 1.
 */
function ixc_venda(array $r, array $venda, array $vendedor): string
{
    $cfg = ixc_config();
    $api = ixc();
    $p = ixc_passos($venda);
    if (empty($p['lead'])) {
        $p['lead'] = so_digitos(ixc_lead($r, $venda, $vendedor));
        ixc_salvar_passos($venda, $p);
    }
    if (($cfg['venda_ate'] ?? 'negociacao') === 'lead') {
        return 'Lead IXC #' . $p['lead'];
    }
    $plano = ixc_plano((string)($r['plano'] ?? ''));
    $canal = ixc_id_por_nome(($cfg['canais'] ?? []) + IXC_CANAIS, (string)($r['canal'] ?? ''), (string)($cfg['canal_padrao'] ?? '12'));
    $conc = ixc_id_por_nome(($cfg['concorrentes'] ?? []) + IXC_CONCORRENTES, (string)($r['provedor'] ?? ''), '8');
    $resp = ($vendedor['ixc_colaborador_id'] ?? '') !== '' ? (string)$vendedor['ixc_colaborador_id'] : (string)($cfg['responsavel_id'] ?? '');
    $filial = (string)($cfg['filial_id'] ?? '1');

    // 2) prospecção: o cadastro do cliente "em negociação" (crm = S), igual ao "Converter em Prospect"
    if (empty($p['prospect'])) {
        $uf = up($r['uf'] ?? '');
        if (!preg_match('/^[A-Z]{2}$/', $uf)) $uf = UF_POR_DDD[substr(so_digitos($r['tel1'] ?? ''), 0, 2)] ?? (string)($cfg['uf_padrao'] ?? 'MG');
        $cid = ixc_cidade((string)($r['cidade'] ?? ''), $uf);
        $cidReg = $cid !== '' ? $api->um('cidade', 'id', $cid) : null;
        $cep = so_digitos($r['cep'] ?? '');
        $cpf = so_digitos($r['cpf'] ?? '');
        if ($cid === '') {
            throw new ErroCrm('A cidade "' . up($r['cidade'] ?? '') . '" não foi achada no IXC. Corrija o nome da cidade no cadastro e envie de novo. (O lead #' . $p['lead'] . ' já foi criado e não será repetido.)');
        }
        if (strlen($cep) !== 8) {
            throw new ErroCrm('O IXC exige o CEP com 8 números. Preencha o CEP no cadastro e envie de novo. (O lead #' . $p['lead'] . ' já foi criado e não será repetido.)');
        }
        $tel1 = (string)($r['tel1'] ?? '');
        $tel2 = (string)($r['tel2'] ?? '') !== '' ? (string)$r['tel2'] : $tel1;
        $d = [
            'ativo' => 'S',
            'id_tipo_cliente' => (string)($cfg['tipo_cliente_id'] ?? '1'),
            'tipo_assinante' => '3',
            'iss_classificacao_padrao' => '99',
            'contribuinte_icms' => 'N',
            'crm' => 'S',
            'status_prospeccao' => 'N',
            'tipo_pessoa' => 'F',
            'razao' => up($r['nome'] ?? ''),
            'cnpj_cpf' => cpf_mascara($cpf),
            'ie_identidade' => up($r['rg'] ?? ''),
            'data_nascimento' => preg_match('#^\d{2}/\d{2}/\d{4}$#', (string)($r['nasc'] ?? '')) ? $r['nasc'] : '',
            'filial_id' => $filial,
            'id_candato_tipo' => $canal,
            'id_concorrente' => $conc,
            'responsavel' => $resp,
            'data_cadastro' => date('d/m/Y'),
            'cep' => strlen($cep) === 8 ? substr($cep, 0, 5) . '-' . substr($cep, 5) : '',
            'endereco' => up(($r['rua'] ?? '') !== '' ? $r['rua'] : ($r['endereco'] ?? '')),
            'numero' => up($r['numero'] ?? '') ?: 'SN',
            'bairro' => up($r['bairro'] ?? ''),
            'complemento' => up($r['complemento'] ?? ''),
            'bloco' => up($r['bloco'] ?? ''),
            'apartamento' => up($r['apartamento'] ?? ''),
            'cidade' => $cid,
            'uf' => (string)($cidReg['uf'] ?? ''),
            'referencia' => up($r['referencia'] ?? ''),
            'tipo_localidade' => up($r['zona'] ?? '') === 'R' ? 'R' : 'U',
            'telefone_celular' => $tel1,
            'whatsapp' => $tel1,
            'fone' => $tel2,
            'email' => strtolower(trim((string)($r['email'] ?? ''))),
            'obs' => ixc_descricao($r, $venda, $vendedor),
        ];
        $mor = up($r['moradia'] ?? '');
        if (in_array($mor, ['P', 'A'], true)) $d['moradia'] = $mor;
        $d += IXC_CLIENTE_PADRAO; // o que a tela do IXC já marca sozinha; pela API vai vazio e ele recusa
        $p['prospect'] = $api->inserir('cliente', limpar_campos($d));
        ixc_salvar_passos($venda, $p);
    }

    // 3) negociação com o plano, em "Negociando"
    if (empty($p['negociacao'])) {
        $pn = $plano ? ixc_plano_negociacao($plano) : null;
        $nome = $plano ? up($plano['nome']) : up($r['plano'] ?? 'VENDA PELO APP');
        $valor = (string)($pn['valor_mensal'] ?? '') !== '' && (float)($pn['valor_mensal'] ?? 0) > 0 ? (string)$pn['valor_mensal'] : (string)($plano['valor_contrato'] ?? '');
        $d = [
            'id_cliente' => $p['prospect'],
            'id_plano_negocio' => $pn ? (string)$pn['id'] : '',
            'descricao' => $nome,
            'id_contato' => $p['lead'],
            'id_canal_origem' => $canal,
            'id_concorrente' => $conc,
            'id_responsavel' => $resp,
            'id_filial' => $filial,
            'valor_mensal' => $valor,
            'previsao_fechamento' => date('d/m/Y'),
            'status' => 'N',
            'obs' => ixc_descricao($r, $venda, $vendedor),
        ];
        $p['negociacao'] = $api->inserir('crm_negociacoes', limpar_campos($d));
        ixc_salvar_passos($venda, $p);
    }
    return 'Lead IXC #' . $p['lead'] . ' · Prospect #' . $p['prospect'] . ' · Negociação #' . $p['negociacao']
        . ($plano ? '' : ' (plano não achado no IXC: confira)');
}

function ixc_lead(array $r, array $venda, array $vendedor): string
{
    $cfg = ixc_config();
    $api = ixc();
    $cpf = so_digitos($r['cpf'] ?? '');
    if ($cpf !== '' && empty($venda['forcar_duplicado'])) {
        $ja = $api->um('contato', 'cnpj_cpf', cpf_mascara($cpf)) ?? $api->um('contato', 'cnpj_cpf', $cpf);
        if ($ja) {
            throw new ErroCrm('Possível duplicado: já existe lead com este CPF no IXC (#' . $ja['id'] . '). Confira e use "Enviar mesmo assim" no painel.');
        }
        if ($cli = ixc_cliente_por_cpf($cpf)) {
            throw new ErroCrm('Possível duplicado: este CPF já é cliente no IXC (#' . $cli['id'] . ' ' . ($cli['razao'] ?? '') . '). Se for outro ponto, lance como 2º ponto; ou use "Enviar mesmo assim".');
        }
    }
    $uf = up($r['uf'] ?? '');
    if (!preg_match('/^[A-Z]{2}$/', $uf)) {
        $uf = UF_POR_DDD[substr(so_digitos($r['tel1'] ?? ''), 0, 2)] ?? (string)($cfg['uf_padrao'] ?? 'MG');
    }
    $plano = ixc_plano((string)($r['plano'] ?? ''));
    $cep = so_digitos($r['cep'] ?? '');
    $d = [
        'ativo' => 'S',
        'lead' => 'S',
        'nome' => up($r['nome'] ?? ''),
        'tipo_pessoa' => 'F',
        'cnpj_cpf' => $cpf !== '' ? cpf_mascara($cpf) : '',
        'data_nascimento' => preg_match('#^\d{2}/\d{2}/\d{4}$#', (string)($r['nasc'] ?? '')) ? $r['nasc'] : '',
        'id_filial' => (string)($cfg['filial_id'] ?? '1'),
        'data_cadastro' => date('d/m/Y H:i:s'),
        'origem' => (string)($cfg['origem_lead'] ?? 'outros'),
        'id_vd_contrato' => $plano ? (string)$plano['id'] : '',
        'fone_celular' => (string)($r['tel1'] ?? ''),
        'fone_whatsapp' => (string)($r['tel1'] ?? ''),
        'fone_residencial' => (string)($r['tel2'] ?? ''),
        'email' => strtolower(trim((string)($r['email'] ?? ''))),
        'cep' => strlen($cep) === 8 ? substr($cep, 0, 5) . '-' . substr($cep, 5) : '',
        'endereco' => up(($r['rua'] ?? '') !== '' ? $r['rua'] : ($r['endereco'] ?? '')),
        'numero' => up($r['numero'] ?? ''),
        'bairro' => up($r['bairro'] ?? ''),
        'complemento' => up($r['complemento'] ?? ''),
        'bloco' => up($r['bloco'] ?? ''),
        'apartamento' => up($r['apartamento'] ?? ''),
        'cidade' => ixc_cidade((string)($r['cidade'] ?? ''), $uf),
        'uf' => $uf,
        'referencia' => up($r['referencia'] ?? ''),
        'obs' => ixc_descricao($r, $venda, $vendedor),
    ];
    $mor = up($r['moradia'] ?? '');
    if (in_array($mor, ['P', 'A'], true)) $d['moradia'] = $mor;
    if (!empty($cfg['estagio_lead'])) $d['id_estagio'] = (string)$cfg['estagio_lead'];
    $d['principal'] = 'N';
    $d['id_candidato_tipo'] = ixc_id_por_nome(($cfg['canais'] ?? []) + IXC_CANAIS, (string)($r['canal'] ?? ''), (string)($cfg['canal_padrao'] ?? '12'));
    $d['id_concorrente'] = ixc_id_por_nome(($cfg['concorrentes'] ?? []) + IXC_CONCORRENTES, (string)($r['provedor'] ?? ''), '8');
    $resp = ($vendedor['ixc_colaborador_id'] ?? '') !== '' ? (string)$vendedor['ixc_colaborador_id'] : (string)($cfg['responsavel_id'] ?? '');
    if ($resp !== '') $d['id_responsavel'] = $resp;
    if (!empty($cfg['regiao_cobertura_id'])) $d['id_tipo_elemento'] = (string)$cfg['regiao_cobertura_id'];
    $d['tipo_localidade'] = up($r['zona'] ?? '') === 'R' ? 'R' : 'U';
    $id = $api->inserir('contato', limpar_campos($d));
    return 'Lead IXC #' . $id . ($plano ? '' : ' (plano não achado no IXC: confira)');
}

/** Abre o atendimento com o assunto; o processo ligado ao assunto dispara a O.S. */
function ixc_atendimento(array $cli, string $assunto, string $titulo, string $msg, ?array $contrato, string $idLogin): string
{
    $cfg = ixc_config();
    $api = ixc();
    if (empty($cfg['setor_id'])) {
        throw new ErroCrm('Falta ixc.setor_id no config.php (setor dos atendimentos no IXC).');
    }
    $a = $api->um('su_oss_assunto', 'id', $assunto);
    if (!$a) {
        throw new ErroCrm('Assunto #' . $assunto . ' não existe no IXC. Confira ixc.assunto_* no config.php.');
    }
    $d = [
        'tipo' => 'C',
        'id_cliente' => (string)$cli['id'],
        'id_contrato' => $contrato ? (string)$contrato['id'] : '',
        'id_login' => $idLogin,
        'id_filial' => (string)($contrato['id_filial'] ?? ($cfg['filial_id'] ?? '1')),
        'id_assunto' => $assunto,
        'id_wfl_processo' => (string)($a['id_processo'] ?? ''),
        'id_ticket_setor' => (string)($cfg['setor_id'] ?? ''),
        'titulo' => $titulo,
        'origem_endereco' => $contrato ? 'CC' : 'C',
        'id_ticket_origem' => 'I',
        'prioridade' => 'M',
        'su_status' => 'N',
        'menssagem' => $msg,
    ];
    if (!empty($cfg['canal_atendimento_id'])) $d['id_canal_atendimento'] = (string)$cfg['canal_atendimento_id'];
    return $api->inserir('su_ticket', limpar_campos($d));
}

/** Contratos do cliente, os ativos primeiro. */
function ixc_contratos(string $idCliente): array
{
    $l = ixc()->listar('cliente_contrato', 'id_cliente', $idCliente, '=', 50, 'asc');
    usort($l, fn($a, $b) => (($a['status'] ?? '') === 'A' ? 0 : 1) <=> (($b['status'] ?? '') === 'A' ? 0 : 1));
    return $l;
}

function ixc_upgrade(array $r, array $venda, array $vendedor): string
{
    $cfg = ixc_config();
    if (empty($cfg['assunto_upgrade'])) {
        throw new ErroCrm('Falta ixc.assunto_upgrade no config.php (id do assunto de upgrade no IXC).');
    }
    $cli = ixc_cliente_por_cpf((string)($r['cpf'] ?? ''));
    if (!$cli) {
        throw new ErroCrm('Cliente com CPF ' . ($r['cpf'] ?? '(vazio)') . ' não encontrado no IXC.');
    }
    $contratos = ixc_contratos((string)$cli['id']);
    $ct = $contratos[0] ?? null;
    $msg = ixc_descricao($r, $venda, $vendedor);
    if (count($contratos) > 1) {
        $msg .= "\nATENÇÃO: O CLIENTE TEM " . count($contratos) . ' CONTRATOS. ATENDIMENTO ABERTO NO CONTRATO #' . $ct['id'] . '. CONFIRA SE É ESTE.';
    }
    $titulo = 'UPGRADE PELO APP: ' . up($r['velAtual'] ?? '') . ' PARA ' . up($r['velNova'] ?? '');
    $id = ixc_atendimento($cli, (string)$cfg['assunto_upgrade'], mb_substr($titulo, 0, 200), $msg, $ct, '');
    return 'Atendimento IXC #' . $id;
}

function ixc_segundo(array $r, array $venda, array $vendedor): string
{
    $cfg = ixc_config();
    $api = ixc();
    if (empty($cfg['assunto_segundo'])) {
        throw new ErroCrm('Falta ixc.assunto_segundo no config.php (id do assunto de 2º ponto no IXC).');
    }
    $cli = ixc_cliente_por_cpf((string)($r['cpf'] ?? ''));
    if (!$cli) {
        throw new ErroCrm('Cliente com CPF ' . ($r['cpf'] ?? '(vazio)') . ' não encontrado no IXC. Para quem ainda não é cliente, lance como venda nova.');
    }
    $plano = ixc_plano((string)($r['plano'] ?? ''));
    if (!$plano) {
        throw new ErroCrm('Plano "' . ($r['plano'] ?? '') . '" não encontrado nos planos de venda do IXC. Os nomes precisam ser iguais.');
    }
    $p = ixc_passos($venda);
    $base = ixc_contratos((string)$cli['id'])[0] ?? [];
    $msg = ixc_descricao($r, $venda, $vendedor);

    // 1) contrato novo, como pré-contrato (não gera cobrança até alguém ativar)
    if (empty($p['contrato'])) {
        $pega = fn(string $k) => (string)(($plano[$k] ?? '') !== '' && ($plano[$k] ?? '') !== '0' ? $plano[$k] : ($base[$k] ?? ''));
        $cep = so_digitos($r['cep'] ?? '');
        $temEnd = up($r['rua'] ?? '') !== '';
        $d = [
            'tipo' => 'I',
            'id_cliente' => (string)$cli['id'],
            'id_vd_contrato' => (string)$plano['id'],
            'contrato' => up($plano['nome']),
            'id_tipo_contrato' => !empty($cfg['tipo_contrato_id']) ? (string)$cfg['tipo_contrato_id'] : (string)($base['id_tipo_contrato'] ?? ''),
            'id_modelo' => $pega('id_modelo'),
            'id_filial' => $pega('id_filial') ?: (string)($cfg['filial_id'] ?? '1'),
            'id_tipo_documento' => $pega('id_tipo_documento'),
            'id_carteira_cobranca' => $pega('id_carteira_cobranca'),
            'id_vendedor' => ($vendedor['ixc_vendedor_id'] ?? '') !== '' ? (string)$vendedor['ixc_vendedor_id'] : $pega('id_vendedor'),
            'data' => date('d/m/Y'),
            'status' => 'P',
            'cc_previsao' => 'P',
            'tipo_cobranca' => 'P',
            'renovacao_automatica' => 'S',
            'base_geracao_tipo_doc' => 'P',
            'bloqueio_automatico' => 'S',
            'aviso_atraso' => 'S',
            'endereco_padrao_cliente' => $temEnd ? 'N' : 'S',
            'obs' => $msg,
        ];
        if ($temEnd) {
            $uf = up($r['uf'] ?? '');
            $d += [
                'cep' => strlen($cep) === 8 ? substr($cep, 0, 5) . '-' . substr($cep, 5) : '',
                'endereco' => up($r['rua'] ?? ''), 'numero' => up($r['numero'] ?? ''), 'bairro' => up($r['bairro'] ?? ''),
                'complemento' => up($r['complemento'] ?? ''), 'bloco' => up($r['bloco'] ?? ''), 'apartamento' => up($r['apartamento'] ?? ''),
                'cidade' => ixc_cidade((string)($r['cidade'] ?? ''), $uf), 'referencia' => up($r['referencia'] ?? ''),
            ];
        }
        $p['contrato'] = $api->inserir('cliente_contrato', limpar_campos($d));
        ixc_salvar_passos($venda, $p);
    }
    $contrato = ['id' => $p['contrato'], 'id_filial' => (string)(($plano['id_filial'] ?? '') ?: ($cfg['filial_id'] ?? '1'))];

    // 2) login novo ligado ao contrato
    if (empty($p['login'])) {
        $grupo = $api->um('view_radgrupos_vd_contratos_produtos', 'vd_contratos_produtos_id_vd_contrato', (string)$plano['id']);
        if (!$grupo) {
            throw new ErroCrm('O plano "' . $plano['nome'] . '" não tem plano de velocidade (radgrupo) no IXC. Contrato #' . $p['contrato'] . ' já foi criado; ajuste o plano e use "Tentar de novo".');
        }
        $nomeLogin = strtr((string)($cfg['login_padrao'] ?? '{cpf}-{contrato}'), [
            '{cpf}' => so_digitos($r['cpf'] ?? ''),
            '{contrato}' => $p['contrato'],
            '{cliente}' => (string)$cli['id'],
        ]);
        $d = [
            'id_cliente' => (string)$cli['id'],
            'id_contrato' => $p['contrato'],
            'id_grupo' => (string)$grupo['id'],
            'login' => mb_substr(strtolower($nomeLogin), 0, 50),
            'senha' => gerar_chave(10),
            'senha_md5' => 'N',
            'autenticacao' => (string)($cfg['login_autenticacao'] ?? 'L'),
            'tipo_conexao_mapa' => (string)($cfg['login_tipo_conexao'] ?? 'F'),
            'ativo' => 'S',
            'login_simultaneo' => '1',
            'auto_preencher_ip' => 'H',
            'fixar_ip' => 'H',
            'relacionar_ip_ao_login' => 'H',
            'autenticacao_por_mac' => 'P',
            'auto_preencher_mac' => 'H',
            'relacionar_mac_ao_login' => 'H',
            'tipo_vinculo_plano' => 'D',
            'endereco_padrao_cliente' => 'C',
        ];
        $p['login'] = $api->inserir('radusuarios', limpar_campos($d));
        ixc_salvar_passos($venda, $p);
    }

    // 3) atendimento do 2º ponto (o processo do assunto abre a O.S. de instalação)
    if (empty($p['atendimento'])) {
        $titulo = 'SEGUNDO PONTO PELO APP: ' . up($plano['nome']);
        $p['atendimento'] = ixc_atendimento($cli, (string)$cfg['assunto_segundo'], mb_substr($titulo, 0, 200), $msg, $contrato, (string)$p['login']);
        ixc_salvar_passos($venda, $p);
    }
    return 'Contrato #' . $p['contrato'] . ', login #' . $p['login'] . ', atendimento #' . $p['atendimento'];
}
