<?php
/** Cron: manda ao CRM o que chegou dos celulares. Roda a cada minuto (ver deploy/cron). */
declare(strict_types=1);
require_once __DIR__ . '/../lib/repasse.php';
require_once __DIR__ . '/../lib/comissoes.php';

foreach (processar_fila_com_trava() as $linha) {
    echo date('Y-m-d H:i:s') . ' ' . $linha . PHP_EOL;
}

// a cada 30 minutos: ID do cliente, vencimento do boleto e "cliente pagou" vindos do IXC
$marca = dirname(config()['banco']) . '/comissoes-ixc.ultima';
if (destino() === 'ixc' && (!is_file($marca) || time() - (int)filemtime($marca) >= 1800)) {
    touch($marca);
    try {
        if ($n = comissoes_atualizar_ixc()) echo date('Y-m-d H:i:s') . " comissões: $n venda(s) atualizadas do IXC" . PHP_EOL;
    } catch (Throwable $e) {
        echo date('Y-m-d H:i:s') . ' comissões: ' . $e->getMessage() . PHP_EOL;
    }
}
