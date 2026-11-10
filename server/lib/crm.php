<?php
/**
 * Cliente do CRM (crm.sidnet.com.br).
 *
 * O CRM não tem API: ele grava pelos formulários das telas, com sessão (cookie PHPSESSID)
 * e um csrf_token em cada formulário. Este cliente faz o mesmo que uma pessoa no navegador:
 * entra com um usuário do sistema, abre a tela, lê o formulário com os valores padrão e envia.
 *
 * Por que ler o formulário antes de enviar: se o CRM ganhar um campo novo, ele vem com o valor
 * padrão da tela e o envio continua funcionando.
 */
declare(strict_types=1);

final class ErroCrm extends RuntimeException
{
    /** true quando adianta tentar de novo mais tarde (rede, CRM fora do ar, sessão caiu) */
    public bool $temporario;

    public function __construct(string $msg, bool $temporario = false)
    {
        parent::__construct($msg);
        $this->temporario = $temporario;
    }
}

final class Crm
{
    private string $base;
    private string $cookies;
    private bool $logado = false;

    public function __construct(private string $usuario, private string $senha, string $base, string $dirDados)
    {
        $this->base = rtrim($base, '/');
        $this->cookies = rtrim($dirDados, '/') . '/crm-cookies.txt';
    }

    /** Requisição HTTP. Não segue redirecionamento: o Location diz se gravou ou se pediu login. */
    private function http(string $metodo, string $caminho, array $campos = []): array
    {
        $ch = curl_init($this->base . $caminho);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookies,
            CURLOPT_COOKIEFILE => $this->cookies,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_USERAGENT => 'ComercialSidnetApp/2.0',
        ];
        if ($metodo === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($campos);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $e = curl_error($ch);
            curl_close($ch);
            throw new ErroCrm('Sem conexão com o CRM: ' . $e, true);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $tamCab = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $cab = substr($resp, 0, $tamCab);
        $corpo = substr($resp, $tamCab);
        $local = preg_match('/^Location:\s*(\S+)/mi', $cab, $m) ? $m[1] : '';
        if ($status >= 500) {
            throw new ErroCrm("O CRM respondeu erro $status.", true);
        }
        return ['status' => $status, 'local' => $local, 'corpo' => $corpo];
    }

    public function entrar(): void
    {
        @unlink($this->cookies);
        $r = $this->http('GET', '/login.php');
        $f = self::formulario($r['corpo'], 'login');
        $f['login'] = $this->usuario;
        $f['senha'] = $this->senha;
        $r = $this->http('POST', '/login.php', $f);
        if ($r['status'] !== 302 || str_contains($r['local'], 'login')) {
            throw new ErroCrm('O CRM não aceitou o usuário do sistema. Confira crm_usuario e crm_senha na configuração.');
        }
        $this->logado = true;
    }

    /** Abre uma tela logado; se a sessão caiu, entra de novo uma vez. */
    public function abrir(string $caminho): string
    {
        if (!$this->logado) {
            $this->entrar();
        }
        $r = $this->http('GET', $caminho);
        if ($r['status'] === 302 && str_contains($r['local'], 'login')) {
            $this->entrar();
            $r = $this->http('GET', $caminho);
        }
        if ($r['status'] !== 200) {
            throw new ErroCrm("Não consegui abrir $caminho no CRM (HTTP {$r['status']}).", true);
        }
        return $r['corpo'];
    }

    public function enviar(string $caminho, array $campos): array
    {
        return $this->http('POST', $caminho, $campos);
    }

    /**
     * Lê os campos de um formulário com os valores padrão da tela.
     * $marca: texto que identifica o <form> (classe, ou um campo que só ele tem).
     * Campos com [] voltam como lista.
     */
    public static function formulario(string $html, string $marca): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $alvo = null;
        foreach ($dom->getElementsByTagName('form') as $form) {
            /** @var DOMElement $form */
            if (str_contains($form->getAttribute('class'), $marca) || str_contains($dom->saveHTML($form), 'name="' . $marca . '"')) {
                $alvo = $form;
                break;
            }
        }
        if (!$alvo) {
            throw new ErroCrm("A tela do CRM mudou: não achei o formulário \"$marca\".");
        }
        $campos = [];
        $por = function (string $nome, string $valor) use (&$campos): void {
            if (str_ends_with($nome, '[]')) {
                $campos[substr($nome, 0, -2)][] = $valor;
            } else {
                $campos[$nome] = $valor;
            }
        };
        $xp = new DOMXPath($dom);
        foreach ($xp->query('.//input|.//select|.//textarea', $alvo) as $el) {
            /** @var DOMElement $el */
            $nome = $el->getAttribute('name');
            if ($nome === '' || $el->hasAttribute('disabled')) {
                continue;
            }
            $tag = strtolower($el->tagName);
            if ($tag === 'input') {
                $tipo = strtolower($el->getAttribute('type') ?: 'text');
                if (in_array($tipo, ['submit', 'button', 'file', 'image', 'reset'], true)) {
                    continue;
                }
                if (in_array($tipo, ['checkbox', 'radio'], true) && !$el->hasAttribute('checked')) {
                    continue;
                }
                $por($nome, $el->getAttribute('value'));
            } elseif ($tag === 'textarea') {
                $por($nome, $el->textContent);
            } else {
                $valor = '';
                $primeiro = null;
                foreach ($xp->query('.//option', $el) as $op) {
                    /** @var DOMElement $op */
                    $v = $op->hasAttribute('value') ? $op->getAttribute('value') : trim($op->textContent);
                    $primeiro ??= $v;
                    if ($op->hasAttribute('selected')) {
                        $valor = $v;
                    }
                }
                $por($nome, $valor !== '' ? $valor : (string)$primeiro);
            }
        }
        return $campos;
    }

    /** Opções de um <select>: [valor => texto, ...] e os data-* de cada uma. */
    public static function opcoes(string $html, string $nome): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp = new DOMXPath($dom);
        $lista = [];
        $sel = $xp->query('//select[@name="' . $nome . '"]')->item(0);
        if (!$sel) {
            return $lista;
        }
        foreach ($xp->query('.//option', $sel) as $op) {
            /** @var DOMElement $op */
            $v = $op->getAttribute('value');
            if ($v === '') {
                continue;
            }
            $lista[] = ['valor' => $v, 'texto' => trim($op->textContent), 'data_valor' => $op->getAttribute('data-valor')];
        }
        return $lista;
    }

    /** Primeira mensagem de erro/alerta visível na página (para mostrar no painel). */
    public static function aviso(string $html): string
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp = new DOMXPath($dom);
        foreach (['alert', 'erro', 'error', 'flash', 'aviso'] as $c) {
            $n = $xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," ' . $c . '") or contains(@class,"' . $c . '-")]')->item(0);
            if ($n && trim($n->textContent) !== '') {
                return mb_substr(trim(preg_replace('/\s+/u', ' ', $n->textContent)), 0, 300);
            }
        }
        return '';
    }
}
