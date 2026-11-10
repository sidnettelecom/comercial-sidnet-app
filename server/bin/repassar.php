<?php
/** Cron: manda ao CRM o que chegou dos celulares. Roda a cada minuto (ver deploy/cron). */
declare(strict_types=1);
require_once __DIR__ . '/../lib/repasse.php';

$trava = fopen(dirname(config()['banco']) . '/repassar.lock', 'c');
if (!flock($trava, LOCK_EX | LOCK_NB)) {
    exit(0); // a rodada anterior ainda está enviando
}
foreach (processar_fila() as $linha) {
    echo date('Y-m-d H:i:s') . ' ' . $linha . PHP_EOL;
}
