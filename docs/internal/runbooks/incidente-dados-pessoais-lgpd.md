# Incidente com dados pessoais: primeiros passos

Resumo para a equipe. Procedimento completo, prazos e templates: [runbook canônico](../../../.lgpd/incidents/runbook.md).

1. **Não apague nada.** Contenha (revogar acesso, isolar, rotacionar segredo) sem destruir logs, arquivos ou registros.
2. **Anote a hora** em que se soube que dados pessoais foram afetados. É o marco do prazo de 3 dias úteis.
3. **Registre** uma entrada `INC-AAAA-NNN` em [`.lgpd/incidents/log.md`](../../../.lgpd/incidents/log.md), mesmo que pareça pequeno.
4. **Avise** a direção e o plantão técnico. Encarregado: [pendente designação — preencher]. Canal de privacidade: contato@easyeye.app.
5. **Identifique o fluxo** (podem ser os dois):
   - dado de paciente (prontuário, exames, imagens, agenda, WhatsApp clínico, portal, TISS): o EasyEye é **operador** e avisa as clínicas afetadas sem demora;
   - contas, cobrança, NFS-e, site ou leads: o EasyEye é **controlador** e decide a comunicação à ANPD e aos titulares.
6. Continue pelo [runbook](../../../.lgpd/incidents/runbook.md), a partir da contenção.
