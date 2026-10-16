<?php

return [
    /*
    | Nível de transação do banco em que uma requisição COMEÇA. É 0 em produção.
    |
    | A trilha que precisa existir ANTES de uma transferência irreversível (ex.: o áudio
    | do ditado saindo para um provedor de IA — AuditLogger::recordAdminActionOrFail())
    | só vale COMITADA: o chamador abre UMA transação, grava e fecha antes de enviar.
    | Com uma transação externa aberta, a gravação vira SAVEPOINT e o commit real só
    | acontece depois do envio — então ela é recusada (nível diferente de base + 1).
    |
    | Só os testes com RefreshDatabase mexem aqui: eles rodam dentro de uma transação
    | própria e informam o nível dela como base.
    */
    'durable_transaction_baseline' => 0,
];
