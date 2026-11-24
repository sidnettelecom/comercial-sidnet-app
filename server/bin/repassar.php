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

// a cada 6 horas: análise da instalação (O.S do cliente nos dias depois da instalação), só leitura no IXC
$marca = dirname(config()['banco']) . '/inst-analise.ultima';
if (destino() === 'ixc' && (!is_file($marca) || time() - (int)filemtime($marca) >= 6 * 3600)) {
    touch($marca);
    try {
        require_once __DIR__ . '/../lib/instalacao.php';
        inst_tabela();
        // recolhimentos: O.S de recolhimento fechadas no IXC vão para "A preencher" (pedido do ALEX, 2026-11-24)
        try {
            rec_tabela();
            foreach (array_unique([date('Y-m'), date('Y-m', strtotime('-5 day'))]) as $mR) {
                $r = rec_imp_puxar($mR);
                if ($r['novas']) echo date('Y-m-d H:i:s') . " recolhimentos: {$r['novas']} O.S de $mR puxadas do IXC" . PHP_EOL;
            }
        } catch (Throwable $e) {
            echo date('Y-m-d H:i:s') . ' recolhimentos: ' . $e->getMessage() . PHP_EOL;
        }
        // instalações concluídas: puxa do IXC as O.S finalizadas do mês (e do mês passado nos primeiros dias)
        foreach (array_unique([date('Y-m'), date('Y-m', strtotime('-5 day'))]) as $mI) {
            $r = inst_imp_puxar($mI);
            if ($r['novas']) echo date('Y-m-d H:i:s') . " instalações: {$r['novas']} O.S de $mI puxadas do IXC" . PHP_EOL;
        }
        if ($n = inst_an_atualizar(300)) echo date('Y-m-d H:i:s') . " análise da instalação: $n instalação(ões) consultadas no IXC" . PHP_EOL;
    } catch (Throwable $e) {
        echo date('Y-m-d H:i:s') . ' análise da instalação: ' . $e->getMessage() . PHP_EOL;
    }
}
