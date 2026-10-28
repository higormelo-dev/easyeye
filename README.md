# EasyEye

SaaS multi-clínica para oftalmologia, com operação clínica, prontuário, imagens e laudos, agenda, cirurgia, recall, financeiro, TISS e integrações. Escopo e requisitos: [PRODUCT.md](PRODUCT.md) e [PRD.md](PRD.md).

A aplicação usa PHP 8.4, Laravel 12, PostgreSQL/Eloquent e Vue 3 com Inertia.js 3 e Vite. Bootstrap 5 e Tailwind fazem parte da interface existente. [SPECS.md](SPECS.md) descreve a arquitetura e [DESIGN.md](DESIGN.md) concentra as referências de design.

Comece pelo [onboarding](docs/onboarding.md) para preparar o ambiente e iniciar somente os processos necessários. `composer dev` também inicia worker e Reverb; Docker pode aplicar migrations automaticamente. Confira destino e efeitos antes de executar.

Comandos e dívida de lint/formatação estão em [qualidade](docs/quality.md). Para agentes, [AGENTS.md](AGENTS.md) é a entrada compartilhada; [CLAUDE.md](CLAUDE.md) importa as mesmas instruções. Todo comando shell utiliza [RTK](RTK.md).

Fontes de trabalho:

- [Invariantes de domínio](docs/internal/engineering/domain-invariants.md): isolamento, assinaturas, concorrência e contratos clínicos/financeiros.
- [ADRs](docs/internal/auditorias/adr/), [runbooks](docs/internal/runbooks/) e [glossário](docs/internal/glossario.md): decisões e operação.
- [Setup de ferramentas](docs/setup.md): versões, origem, verificações e intervenções pendentes.
- [Docker](DOCKER.md): operação do ambiente existente, após o preflight do onboarding.

O setup aprovado trabalha localmente, sem conectar novos serviços ou enviar código/dados do projeto a terceiros. Downloads públicos não autorizam upload, OAuth, telemetria adicional, publicação ou acesso à produção.
