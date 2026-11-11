<?php
/** Cron: manda ao CRM o que chegou dos celulares. Roda a cada minuto (ver deploy/cron). */
declare(strict_types=1);
require_once __DIR__ . '/../lib/repasse.php';

foreach (processar_fila_com_trava() as $linha) {
    echo date('Y-m-d H:i:s') . ' ' . $linha . PHP_EOL;
}
