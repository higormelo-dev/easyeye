<?php

/*
 * Envio de lote TISS (SendTissBatchService) — mensagens que chegam ao usuário
 * (BillingService::submitViaTissWorkflow repassa a mensagem da exceção em
 * "Falha no envio TISS: :reason").
 */
return [
    'xml_rejected'             => 'Envio bloqueado: o XML do lote foi rejeitado na validação e não pode ser transmitido à operadora.',
    'xml_rejected_with_errors' => 'Envio bloqueado: o XML do lote foi rejeitado na validação e não pode ser transmitido à operadora. Erros: :errors',

    'transmission_in_progress' => 'Já existe uma transmissão deste lote em andamento. Aguarde a conclusão — se ela tiver sido interrompida, o reenvio é liberado automaticamente em alguns minutos.',

    'xml_changed_during_send' => 'O XML do lote foi regerado por outra operação enquanto o envio era preparado. Nada foi enviado — tente enviar de novo.',

    'batch_cancelled' => 'Este lote foi cancelado e não pode ser enviado à operadora.',

    'batch_cancelled_xml' => 'Este lote foi cancelado e não é possível gerar o XML dele.',

    'delivery_unknown' => 'A operadora não deu uma resposta conclusiva (sem resposta ou erro do servidor) e não sabemos se ela recebeu o lote (:reason). O lote ficou bloqueado para alterações até a operadora confirmar o recebimento: envie de novo — o mesmo XML e a mesma chave de envio são reutilizados, o que evita a operadora receber o lote duas vezes.',

    'forced_resend_failed' => 'O reenvio falhou (:reason). A confirmação da operadora foi preservada: o lote continua enviado e nada nele foi alterado.',

    'delivery_still_unknown' => 'Esta tentativa falhou (:reason), mas o envio anterior ficou sem resposta e continua em dúvida: não sabemos se a operadora recebeu o lote, e esta falha não prova que ela não recebeu. O lote segue bloqueado para alterações até a operadora confirmar o recebimento: corrija a causa (por exemplo, a credencial ou o endereço do serviço) e envie de novo — o mesmo XML e a mesma chave de envio são reutilizados, o que evita a operadora receber o lote duas vezes.',
];
