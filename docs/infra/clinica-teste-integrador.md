# Clínica Teste Integrador (testes da API, treinamento e demonstração)

Seeder próprio: `database/seeders/IntegratorTestClinicSeeder.php` (antes ficava
dentro do `DataFakersSeeder`, que só roda em `local`/`testing`).

## O que cria

- Clínica `Clínica Teste Integrador` (subdomínio `clinica-teste-integrador`).
- Assinatura **cortesia de 1 ano** no plano ativo com API de integradores
  (substitui o teste grátis automático da clínica nova). Cortesia não tem
  franquia de IA: para demonstrar a IA, conceda créditos de cortesia no manager.
- Equipe: admin, Dra. Ana Lima, Dr. Carlos Souza (médicos), secretária e
  financeiro (`*@clinicateste.com`).
- Usuário de integrador `integrador@teste.com` e 2 equipamentos lógicos
  (MAC `AA:BB:CC:DD:EE:01/02`). A clínica fica sempre **sem** equipamentos
  físicos (regra da API de integradores).

## Senhas por ambiente (decisão do dono)

Senha fixa do repositório **só** em ambiente local e nos testes
automatizados. A chave é explícita — `SEED_FIXED_CREDENTIALS` — porque a
homologação (`teste.easyeye.app`) roda com `APP_ENV=testing`, igual aos
testes: o nome do ambiente não serve para decidir.

| | local (`APP_ENV=local`) e testes automatizados (`SEED_FIXED_CREDENTIALS=true`, ligado no `phpunit.xml`) | homologação (`APP_ENV=testing`, chave `false`) e produção |
|---|---|---|
| Senhas | fixas (`Admin@123`, `Medico@123`, `Secretaria@123`, `Financeiro@123`, `Integrador@123`) — Cypress e manuais dependem delas | **aleatórias** (24 letras e números), mostradas **uma única vez** no terminal |
| Usuário que já existe | volta para a senha fixa | senha trocada pelo painel: **nunca** é mexida; ainda com a senha fixa de um seed antigo: **substituída** por uma aleatória (mostrada uma vez, marcada "senha fixa antiga substituída") e os acessos abertos caem (remember token, sessões no banco, tokens da API do integrador) |
| Pacientes / agenda | 20 pacientes fictícios; agenda gerada pelo `DataFakersSeeder` | nenhum paciente de exemplo — a clínica nasce só com a equipe |

Em produção a chave é ignorada (nunca senha fixa). Implementação:
`App\Support\SeedCredentials`. O mesmo vale para os admins do SaaS
(`EntityAndUserAdministratorSeeder`) com uma diferença: admin que **já existe**
nunca tem a senha trocada (é o admin real) — o seeder só avisa no terminal se
ele ainda usa a senha do repositório, para trocar pelo painel.

> **A senha aparece no terminal**: ela fica no histórico do terminal, no log
> do CI/deploy e em qualquer gravação da sessão. Rode o seeder num terminal
> seu (não num pipeline com log público), anote e repasse por um canal
> privado. Com `SESSION_DRIVER=redis` (padrão de produção) as sessões web já
> abertas não são apagadas pelo seeder — elas caem quando expiram ou com
> "Sair" do usuário; com `SESSION_DRIVER=database` são apagadas.

## Rodar de novo é seguro

- Nunca troca senha definida pelo painel (só a fixa antiga do repositório, fora do modo fixo).
- Nunca apaga nem estende assinatura em vigor (ex.: período adicionado pelo manager).
- Não volta nome/dados alterados pelo painel.
- Não duplica clínica, usuários nem equipamentos.

## Homologação e produção

```bash
php artisan db:seed --class=IntegratorTestClinicSeeder --force
```

Anote as senhas mostradas (não aparecem de novo). Se perder, troque pelo
painel/“esqueci a senha” — rodar o seeder de novo **não** gera outra.

**Homologação (`teste.easyeye.app`) — ação do dono depois deste deploy:** o
banco de homologação foi seedado com as senhas fixas. Confirme que o `.env`
**não** tem `SEED_FIXED_CREDENTIALS=true`, rode o comando acima uma vez e
**repasse as novas senhas aos sócios** (as antigas `*@123` deixam de
funcionar e as sessões abertas no banco caem).

`php artisan db:seed` (`DatabaseSeeder`) também chama este seeder e o
`EntityAndUserAdministratorSeeder` — os dois são idempotentes e seguem a mesma
regra de senhas. Mas o `DatabaseSeeder` inteiro **não** é para banco de
produção já populado: em `APP_ENV=local`/`testing` ele também roda o
`DataFakersSeeder` (empresas fake a cada execução). Em produção use só o
`--class=` deste seeder.

`clinics:ensure-financial-profiles` (backfill de perfil Financeiro):
**recusado em produção** (promoveria uma pessoa real de uma clínica real a
Financeiro); em dev/homologação promove um usuário não admin/médico das
clínicas sem Financeiro e, fora do modo fixo, **não** cria o
`financeiro@clinicateste.com` com a senha fixa — indica este seeder.

## Atenção

- Os e-mails `@clinicateste.com` e `integrador@teste.com` são domínios de
  terceiros: e-mails do sistema para esses usuários (ex.: recuperação de
  senha) não chegam a você. Para usar a recuperação de senha, troque o e-mail
  do usuário pelo painel.
- Em apresentações, cadastre pacientes com dados fictícios e **sem** WhatsApp
  marcado (ou com o seu próprio número), para nenhuma mensagem sair para
  pessoa real.
