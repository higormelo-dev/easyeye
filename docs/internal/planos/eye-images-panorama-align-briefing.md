# Briefing — Eye Images: Panorama automático e Auto-alinhamento (18/10/2026)

Origem: spec de outra sessão (comparação com o gerenciador de exames de referência, aba "Montage" e aba "Align"). Tudo abaixo foi conferido no código em 18/10/2026 na `desenv` (HEAD `ca23221f`).

## Já existe (não refazer)

- `scripts/exam-panorama-stitch.py` (commit `3053c41b`): worker standalone, `cv2.Stitcher_create(PANORAMA)`, argv `<out.png> <img1> <img2> [...]`, 1 linha JSON em stdout (`{"ok":true}` / `{"ok":false,"reason":...}`), exit 1 em falha. Cita `App\Services\EyeImages\BoundedPanoramaProcess`, que **não existe**. Zero controller, rota, UI, job, teste, i18n.
- `Dockerfile:32-37`: `python3`, `python3-opencv`, `python3-numpy` já instalados na imagem. Máquina de dev local **não tem cv2** (venv com `opencv-python-headless` no scratchpad do orquestrador só para smoke test manual).
- `app/Services/BoundedPdfProcess.php` (`final`): `hasIsolation()` (Linux + `/usr/bin/setsid` + posix_kill/posix_setrlimit + `POSIX_RLIMIT_*`) e `execute(array $command, int $maxOutputBytes, float $timeout): string` com `setsid`, `Process::setTimeout`, kill do grupo (`posix_kill(-$pid, SIGKILL)`) no `finally`. Erros `pdf_output_limit` / `pdf_worker_failed` / `pdf_process_isolation_unavailable` **não têm chamador fora da classe** (grep em app/ e tests/). Os rlimits (AS 512 MB, CPU 12 s, FSIZE 16 MB) são aplicados **dentro** do worker PHP (`scripts/exam-pdf-worker.php`), não pelo pai. `/usr/bin/prlimit` existe (util-linux, mesmo pacote do `setsid`).
- `app/Services/BoundedExamArchive::read(path, limit=10 MB)` lê do S3 com teto de bytes/tempo; `GenerateExamDerivatives::imageFromPdf` mostra o padrão `tempnam` → `file_put_contents` → `finally unlink`.
- `patient_exams.display_archive` (JPEG, lado maior ≤ 2560 px) e `derivative_status` (migration `2026_09_01_120000`); `MontageService` já usa `$exam->display_archive ?? $exam->archive`.
- `EyeImageMontageController` + `MontageService` (grid Imagick, cap 50, `isAvailable()`), rota `POST /eye-images/montage` com `entity.role:admin,doctor,secretary` (`routes/web.php:319`), trait `AuthorizesEyeImageExams::ownedExamIds()` (403 cross-tenant), `PatientExam::isVideo()`. Front: `downloadMontage()` em `Index.vue:1231-1296` (axios blob → download, erro vem como Blob), botão em `Index.vue:~2809`, `props.urls.montage` montado em `EyeImagesController:208`. Teste `tests/Feature/EyeImages/EyeImageMontageTest.php` (caminho feliz só com Imagick; 422 sem).
- `EyeImageCompareModal.vue` (229 linhas): alinhamento **manual** (drag → `translate(offset.x, offset.y)` em px de tela, `overlayStyle` linhas 89-94; as duas `<img>` do modo Sobrepor têm `width:100%` do mesmo container ≤ 820 px); botão "Redefinir posição" na linha 153; props `images: [{id, url, label}]` (id = exam id, `compareImages` em `Index.vue:2020`); `compare_*` em `lang/*/eye_images.php`.

## Gaps (escopo deste lote)

| # | Feature | Entregável mínimo |
|---|---|---|
| 1 | Panorama (stitching de retinografias) | Processo isolado + controller + rota + botão ao lado de Montage + aviso "composição aproximada" + i18n + testes |
| 2 | Auto-alinhar (só translação, Fase 1) | Script `exam-align-detect.py` + processo isolado + controller + rota + botão no modo Sobrepor com conversão de escala + i18n + testes |

## Decisões do orquestrador

1. **Uma só classe de processo** para os dois scripts Python (ex.: `App\Services\EyeImages\BoundedVisionProcess`), reaproveitando o isolamento de `BoundedPdfProcess` por **trait** extraído (`hasIsolation()` + `execute()`), sem 3ª cópia. `BoundedPdfProcess` mantém comportamento e códigos de erro; só passa a usar o trait. O JSON de `reason` dos scripts **tem** que chegar ao PHP mesmo em falha (escolher: scripts saem 0 com `ok:false`, ou `execute()` devolve stdout junto do exit code — o plano decide a forma mais simples).
2. Python roda como `setsid -- prlimit --as=<bytes> --cpu=<s> --fsize=<bytes> -- python3 -I <script> ...` (rlimit pelo pai, já que não controlamos o import do cv2). Limites, timeouts (`Process::setTimeout`) e caps de imagem ficam em constantes **num único lugar**; panorama ≤ 20 s, align ≤ 10 s, ambos síncronos no request (sem fila nesta fase).
3. Entrada = `display_archive` quando `derivative_status === 'ready'`, senão `archive`; download via `BoundedExamArchive::read()` para `tempnam`, `unlink` no `finally`; vídeo rejeitado (422) antes de baixar.
4. Panorama: `exam_ids` 2..6, mesma ordem da seleção, PNG `attachment; filename="panorama.png"`, nunca persistido; rota `POST /eye-images/panorama`, `entity.role:admin,doctor,secretary` (igual Montage). Aviso obrigatório na UI: texto `panorama_approximate_notice` exibido a cada geração (toast de sucesso) e no `title` do botão.
5. Align Fase 1 = translação. Script redimensiona B para as dimensões de A antes de calcular (as duas `<img>` são esticadas para a mesma largura na tela, então `dx,dy` em px de A convertem com um só fator). Saída `{"ok":true,"dx","dy","confidence"}`; `confidence < 0.3` → `low_confidence` **no script**. ORB+RANSAC (translação do `estimateAffinePartial2D`, confiança = inliers/matches, mínimo de inliers) é a 1ª opção; `phaseCorrelate` como fallback só se a Forja mostrar no smoke test que ORB falha com imagens sintéticas deslocadas. Rota `POST /eye-images/align` `{exam_id_a, exam_id_b}` (ids distintos, mesmo middleware do Montage).
6. Front align: `offset = d * (baseImg.clientWidth / baseImg.naturalWidth)` lido via `ref` na `<img>` base; falha mantém offset atual e mostra toast; botão com estado de carregamento; Vitest cobre o fator de escala.
7. Disponibilidade: `urls.panorama`/`urls.align` só quando `hasIsolation()`; cv2 ausente → 422 `*_unavailable` (nunca 500). Sem checagem de cv2 por request.
8. Testes PHP fingem o processo pelo container (um teste por `reason`), 403 cross-tenant, 422 vídeo/min/max/ids iguais; teste "de verdade" só roda com `hasIsolation()` **e** cv2 (`markTestSkipped` senão), igual ao Montage. Scripts Python ganham self-check `unittest` em `tests/Python/` (pulado sem cv2; fora da CI PHP), rodado localmente pela Forja no venv `scratchpad/cv-venv`.
9. i18n pt_BR + en para todo `reason` (`panorama_*`, `align_*`).
10. Ninguém roda Pest em paralelo (banco `easyeye_test` único); revisores não rodam Pest. Pint só nos arquivos tocados. Sem commit sem "commit autorizado". `.claude/settings.json` fora.
11. Fora de escopo: fila/polling, rotação/escala no align (Fase 2), persistir panorama, calculadora IOL, tela nova.

## Restrições

Ver `fluxo de trabalho` (nota Maestri) e `docs/internal/planos/eye-images-gaps-plano.md` §restrições. Não tocar arquivos de outras sessões. Nenhum dado real de paciente em fixture: imagens sintéticas (numpy) nos testes Python; bytes fake nos testes PHP.
