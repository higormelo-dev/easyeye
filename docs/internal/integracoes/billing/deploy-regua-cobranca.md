# Deploy da régua de cobrança e conciliação das assinaturas antigas

Passo a passo para subir em produção a régua de cobrança (`billing:dunning`), o bloqueio por assinatura
(`check.subscription`) e a conciliação das assinaturas de cobrança automática criadas pelo código
anterior (`billing:reconcile-legacy`).

Faça tudo no mesmo dia, longe dos horários do agendador:

| Horário | Tarefa |
|---|---|
| 00:05 | `trials:expire` |
| 00:10 | expiração diária (cortesias e atraso da cobrança automática) |
| 01:00 | `subscriptions:renew` (renovação local) |
| 09:00 | `billing:dunning` (régua) |

## O que muda para as clínicas

- **Bloqueio na hora (D3).** Trial e cortesia vencidos e assinatura cancelada passam a bloquear o painel
  assim que o código sobe. No código anterior, o `check.subscription` não estava em nenhuma rota.
- **Régua do pagante (D4).** Lembrete no D-5. Depois do vencimento, aviso no D+1, acesso limitado no D+3
  (IA e financeiro) e bloqueio total no D+7, com o encerramento e o cancelamento da recorrência no gateway.
- **Contratação nova (D5).** O acesso vai até o fim do dia do 1º vencimento.
- **Assinaturas do código anterior.** As linhas de cobrança automática ficam marcadas para conciliação
  (`needs_billing_reconciliation`). Enquanto marcadas:
  - ficam fora da régua, da expiração e da renovação local;
  - só a **vigente** da empresa mantém acesso total;
  - a linha antiga, já substituída, nunca libera acesso, nunca gera e-mail para a clínica e nunca é
    cancelada no gateway pela régua.

## Antes de subir o código

1. **Desligue a régua no `.env` do servidor, antes de qualquer outra coisa:**

   ```
   BILLING_DUNNING_ENABLED=false
   ```

   Assim, se o script de deploy rodar `config:cache` ou `up` sozinho, ou se o deploy passar das 09:00,
   a régua não roda ligada sobre dados ainda não conciliados. O padrão do `config/billing.php` é `true`.
   Deixe `BILLING_ENFORCE_SUBSCRIPTION_ACCESS` como está (padrão `true`); ela é conferida no passo 9.

2. **Faça um backup do banco:**

   ```
   pg_dump -Fc easyeye > easyeye-antes-regua.dump
   ```

   A migração `2026_10_04_200100` só mexe em dados e o `down()` dela não desfaz nada.

## Janela de manutenção

3. **Entre em manutenção, com um segredo para o time:**

   ```
   php artisan down --secret="<token>"
   ```

   - O time entra no manager por `https://<host>/<token>`.
   - Os webhooks dos gateways recebem 503 durante a janela e serão reenviados. Mantenha a janela curta.

4. **Suba o código:**

   ```
   git pull
   composer install --no-dev --optimize-autoloader
   ```

5. **Rode as migrações:**

   ```
   php artisan migrate --force
   ```

   - A `200000` cria `needs_billing_reconciliation`.
   - A `200100` marca a vigente de cada empresa e as linhas antigas que ainda podem ter recorrência viva:
     ativas, em atraso e expiradas com recorrência no gateway. Ela também devolve ao trial os trials que
     a `230100` tinha promovido a cobrança automática.

   Confira o resultado:

   ```sql
   SELECT status, gateway, count(*)
     FROM subscriptions
    WHERE needs_billing_reconciliation
    GROUP BY 1, 2
    ORDER BY 1, 2;
   ```

6. **Gere os caches antes de reiniciar a fila:**

   ```
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

   Confira que a régua está desligada:

   ```
   php artisan tinker --execute="var_dump(config('billing.dunning.enabled'));"
   ```

   O resultado esperado é `bool(false)`.

7. **Reinicie a fila ainda em manutenção:**

   ```
   php artisan queue:restart
   ```

   - Rode depois do `config:cache`, para os workers novos já subirem com o código e a configuração novos.
   - Mudaram os jobs de cobrança, o processamento de webhook e os e-mails da régua.

8. **Gere o build do front:**

   ```
   npm ci
   npm run build
   ```

   Se o servidor SSR estiver ativo, reinicie-o.

9. **Confira no manager quem perde o acesso, pelo link do `--secret`.** Em **Assinaturas**, veja os cards:

   - **Sem acesso:** pelo D3, trials e cortesias vencidos e assinaturas canceladas passam a ser bloqueados
     na hora. Use o filtro "Sem acesso" para ver a lista. Para quem não deve ser bloqueado, resolva antes
     do `up`, com "Adicionar período" ou "Nova assinatura".
   - **Sem assinatura:** empresas clientes sem nenhuma assinatura também ficam bloqueadas.
   - **Revisar cobrança:** os pagantes do código anterior. A vigente de cada um segue com acesso total até
     a conciliação.

   Se a lista for grande demais para tratar agora, libere o painel de todas as empresas com
   `BILLING_ENFORCE_SUBSCRIPTION_ACCESS=false`, depois `php artisan config:cache` e
   `php artisan queue:restart`. Volte para `true` assim que tratar a lista.

10. **Saia da manutenção:**

    ```
    php artisan up
    ```

    No painel do Asaas (e dos outros gateways em uso), confira a fila de webhooks. Se ela foi pausada pelas
    falhas da janela, reative-a.

## Conciliação (no mesmo dia)

11. **Rode a conciliação em simulação** (não grava nada):

    ```
    php artisan billing:reconcile-legacy
    ```

    A chave do Asaas precisa ter permissão de leitura em `GET /v3/subscriptions`. Resultados possíveis:

    - **Ativa (em dia):** termina e renova no vencimento da próxima cobrança. Grava o ciclo, o valor e o
      último pagamento que o gateway cobra.
    - **Em atraso (régua a partir de hoje):** a régua conta a partir da conciliação, não do vencimento real.
      - Fica com acesso total e o aviso no painel.
      - Recebe o aviso por e-mail com a régua ligada.
      - Fica limitada no D+3 e é encerrada no D+7.
      - O vencimento real aparece na coluna "Vencimento não pago" e fica no histórico
        (`metadata.original_due_date`).
    - **Aguardando 1º pagamento:** a contratação nunca foi paga. Se a cobrança já tinha vencido, o prazo
      recomeça como numa contratação feita hoje (`BILLING_FIRST_CHARGE_DUE_DAYS`, padrão 3 dias). O acesso
      segue até o fim desse dia, com o aviso de pagamento no painel.
    - **Revisar manualmente:** a linha não é alterada e segue marcada. Os casos são:
      - recorrência inativa, removida ou inexistente;
      - gateway sem consulta (Mercado Pago, PagBank, Pagar.me, InfinitePay, Stripe);
      - cobrança nunca emitida;
      - cobrança vencida com pagamento posterior;
      - **"Recorrência duplicada · vigente: <id>":** linha antiga com a recorrência ativa no gateway. O
        cliente pode estar pagando duas vezes.
      - **"Recorrência órfã":** recorrência achada no gateway pela referência da linha, sem o id registrado.
    - **Erro na consulta:** nada foi alterado. Rode de novo.

12. **Grave a conciliação:**

    ```
    php artisan billing:reconcile-legacy --apply
    ```

    - É idempotente: só processa o que continua marcado. Se aparecer "Erro na consulta", rode de novo.
    - Cada conciliação grava um `SubscriptionChange` `legacy_reconciled`, com antes e depois.
    - O acesso limitado (D+3) e o bloqueio (D+7) são calculados pela data, mesmo com a régua desligada; só
      os e-mails dependem dela. Faça os passos 12 a 15 no mesmo dia.

13. **Faça a revisão manual.** Cada linha "Revisar manualmente" precisa de uma saída explícita. Sem
    `--action`, o comando recusa e nada muda.

    - Cancelar localmente e parar a recorrência no gateway (cliente que saiu, recorrência duplicada,
      recorrência morta):

      ```
      php artisan billing:reconcile-legacy --close=<ID> --action=cancel --reason="motivo (ticket)"
      ```

    - Marcar como pago até uma data (pagamento comprovado):

      ```
      php artisan billing:reconcile-legacy --close=<ID> --action=paid-until --until=AAAA-MM-DD --reason="motivo (ticket)"
      ```

      A linha fica ativa até o fim do dia informado, que vira o próximo vencimento, e segue o fluxo novo
      (renovação, lembrete e régua). A data precisa ser hoje ou depois. Só vale para a vigente da empresa.

    - Pelo manager:
      - **Alterar assinatura:** vira cortesia e cancela a recorrência no gateway.
      - **Nova assinatura:** substitui a vigente e para a recorrência de todas as linhas marcadas da empresa,
        inclusive as expiradas.
      - **Cancelar:** cancela a vigente e todas as linhas marcadas da empresa, em qualquer situação não
        final, parando a recorrência de cada uma.

    - **Recorrência órfã:** cancele-a no painel do gateway antes. O `--close` recusa enquanto ela existir.

    Todas as saídas gravam `SubscriptionChange` com antes e depois, a saída e o motivo.

14. **Simule a régua:**

    ```
    php artisan billing:dunning --dry-run
    ```

    - Confira a etapa, os dias de atraso e se ela cancelaria a recorrência no gateway.
    - Só aparecem assinaturas vigentes: linha antiga nunca entra na régua.

15. **Ligue a régua:**

    ```
    BILLING_DUNNING_ENABLED=true
    php artisan config:cache
    php artisan queue:restart
    ```

    O 1º aviso sai na execução das 09:00. Para mandar os avisos de hoje na hora, rode `php artisan billing:dunning`.

## Emergência

- **`BILLING_DUNNING_ENABLED=false`**, depois `config:cache`: para os avisos, o encerramento e o
  cancelamento no gateway. Não desfaz o bloqueio do painel.
- **`BILLING_ENFORCE_SUBSCRIPTION_ACCESS=false`**, depois `config:cache`: libera o painel de todas as
  empresas.

Depois de mudar o `.env`, rode sempre `php artisan queue:restart`.

## Extras

- **`billing:retry` saiu do agendador.** A nova tentativa agora é do `subscriptions:renew`, na mesma fatura
  do período. Os agendamentos pendentes do código anterior ficam parados. Se forem disparados à mão, viram
  `skipped`. Limpeza opcional:

  ```sql
  UPDATE billing_retry_schedules SET status = 'skipped' WHERE status = 'pending';
  ```

- **Linhas canceladas do código anterior não são marcadas** (estado final), mas a recorrência delas pode
  continuar viva no gateway. O código anterior não parava a recorrência na troca. O pagamento delas entra
  como alerta ("pagamento recebido para assinatura que não está sendo cobrada"), sem mudar acesso. Liste e
  confira no Asaas:

  ```sql
  SELECT id, entity_id, gateway, gateway_subscription_id, cancelled_at
    FROM subscriptions
   WHERE billing_mode = 'gateway'
     AND status = 'cancelled'
     AND next_billing_at IS NULL
     AND gateway_subscription_id IS NOT NULL
     AND gateway_subscription_id <> '';
  ```

- **Ambiente de teste.** O `DataFakersSeeder` gera pagantes do Asaas com ids falsos (`sub_fake_*`). Com a
  régua ligada, o encerramento no D+7 tenta cancelar esses ids e gera log de falha. Lá, deixe
  `BILLING_DUNNING_ENABLED=false` ou aceite esses logs.
