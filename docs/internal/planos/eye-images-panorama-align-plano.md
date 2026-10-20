# Eye Images — Panorama automático + Auto-alinhamento: plano executável

> Etapa PLANO (Bússola). Pedido do orquestrador: 2 features na MENOR versão completa (Panorama e Align Fase 1/translação), infra única de processo isolado, testes que fingem o processo no container.
> Verificado no checkout em 18/10/2026 na branch `feature/eye-images-panorama-align` (HEAD `ca23221f`, criada de `desenv`; árvore limpa exceto o briefing untracked). Nenhum dado de paciente foi lido.

## 0. Estado do working tree e arquivos compartilhados

- `git status`: limpo exceto `?? docs/internal/planos/eye-images-panorama-align-briefing.md` (do orquestrador; sugerido entrar no commit 1).
- Arquivos compartilhados entre os itens (por isso os commits são sequenciais):
  - `app/Services/BoundedPdfProcess.php` — item 1 só extrai o trait (comportamento e códigos de erro preservados; `P2BoundariesTest` continua passando sem tocar).
  - `routes/web.php` — itens 2 e 3 (2 rotas novas no bloco `eye-images`, junto do `montage` ~linha 318).
  - `resources/js/Pages/Panel/EyeImages/Index.vue` — item 2 (botão + `downloadPanorama`) e item 3 (passa `align-url` ao modal).
  - `lang/pt_BR/eye_images.php` + `lang/en/eye_images.php` — itens 2 e 3.
  - `app/Http/Controllers/EyeImagesController.php` — itens 2 e 3 (`urls.panorama`/`urls.align`).
- Sem migration e sem mudança de banco neste lote.
- `tests/Python/` **não existe** — os self-checks Python criam o diretório (fora da CI PHP).

## 1. Decisões fechadas nesta etapa (vetáveis pelo orquestrador)

1. **Trait**: `App\Services\Concerns\HasIsolatedProcess` com `hasIsolation(): bool` (estático) e `execute(array $command, int $maxOutputBytes, float $timeout): string` — assinatura pública **inalterada**; os literais `pdf_output_limit`/`pdf_worker_failed` passam a sair de dois ganchos protegidos (`outputLimitFailureReason()`, `workerFailureReason()`) sobrescritos por `BoundedPdfProcess` com os valores atuais (comportamento idêntico, zero mudança de mensagem).
2. **JSON de reason em falha**: os scripts Python passam a **sair sempre 0** com `{"ok":false,"reason":...}` para falhas de aplicação (cv2 ausente, imagem ilegível, stitch falhou, baixa confiança). Exit ≠ 0 fica reservado a crash real (rlimit, segfault, interpretador ausente) → `execute()` lança `vision_worker_failed`. Timeout do `Process` → `vision_timeout`. Assim o reason chega pelo stdout que `execute()` já devolve hoje, sem mudar o trait. `exam-panorama-stitch.py` é ajustado (hoje sai 1) — não tem consumidor em produção ainda.
3. **Processo único**: `App\Services\EyeImages\BoundedVisionProcess` (não-`final` de propósito — os testes o trocam por mock no container) com `stitch(array $imagePaths, string $outPath): void` e `align(string $imageA, string $imageB): array{dx,dy,confidence}`. Comando: `setsid -- prlimit --as=… --cpu=… --fsize=… -- python3 -I <script> …`; limites/timeouts/caps em constantes da classe (§2.2).
4. **Disponibilidade**: `urls.panorama`/`urls.align` só vêm preenchidas com `BoundedVisionProcess::hasIsolation()` (decisão 7 do briefing — sem checagem de cv2 por request; cv2 ausente → 422 `*_unavailable`, nunca 500); `runScript()` exige também `/usr/bin/prlimit` executável e, se faltar, devolve 422 `*_unavailable`.
5. **Entrada de imagem**: `display_archive` quando `derivative_status === 'ready' && display_archive !== null`, senão `archive`; download por `BoundedExamArchive::read(path, MAX_IMAGE_BYTES)` para `tempnam`; `unlink` no `finally` (padrão de `GenerateExamDerivatives::imageFromPdf`). Vídeo → 422 antes de baixar (`PatientExam::isVideo()`).
6. **Panorama**: `exam_ids` 2..6 (`distinct`), ordem da seleção; PNG `attachment; filename="panorama.png"`, nunca persistido; rota `POST /eye-images/panorama` com `entity.role:admin,doctor,secretary` (igual Montage — não é ato clínico). Aviso `panorama_approximate_notice` no `title` do botão e no toast de sucesso, a cada geração.
7. **Align Fase 1 = translação**: script redimensiona B para as dimensões de A, `estimateAffinePartial2D` (RANSAC) sobre matches ORB, `dx/dy` = translação da matriz em px de A, `confidence = inliers/matches` com `0.3` e mínimo de inliers dentro do script. Só translação no front (rotação/escala ficam para a Fase 2).
8. **Front do align**: `offset = d * (baseImg.clientWidth / baseImg.naturalWidth)` da `<img>` base (a de baixo, `images[0]`); falha mantém o offset atual e mostra toast; botão com loading. Vitest cobre o fator de escala.
9. **Testes**: Pest finge o processo no container (`$this->mock(BoundedVisionProcess::class)`), um caso por `reason` + 403 cross-tenant + 422 vídeo/min/max/ids iguais; o teste "de verdade" só roda com `hasIsolation()` **e** cv2 (`markTestSkipped` senão). Scripts Python ganham self-check `unittest` em `tests/Python/` (pulado sem cv2, rodado localmente no venv do scratchpad — fora da CI PHP).

## 2. Item 1 — Infra: trait de isolamento + processo de visão único + scripts

### 2.1 Trait `app/Services/Concerns/HasIsolatedProcess.php` (novo)

- Corpo movido **como está** de `BoundedPdfProcess`:
  - `public static function hasIsolation(): bool` — Linux + `/usr/bin/setsid` + `posix_kill`/`posix_setrlimit` + `POSIX_RLIMIT_*` (mesmo corpo; move literal).
  - `public function execute(array $command, int $maxOutputBytes, float $timeout): string` — corpo idêntico (start, cap de stdout, `setTimeout`, `isSuccessful`, `finally` com `posix_kill(-$pid, SIGKILL)` + `stop(0, SIGKILL)`), com os dois literais trocados por `$this->outputLimitFailureReason()` / `$this->workerFailureReason()`.
- Ganchos com defaults genéricos: `protected function outputLimitFailureReason(): string { return 'process_output_limit'; }` e `protected function workerFailureReason(): string { return 'process_worker_failed'; }`.
- `BoundedPdfProcess` (`final`, continua em `App\Services`): `use HasIsolatedProcess;` + os dois overrides devolvendo `'pdf_output_limit'` / `'pdf_worker_failed'`. `run()` não muda. Critério: `tests/Feature/Api/Integrators/P2BoundariesTest.php` (timeout em `execute()`) e os fluxos de PDF continuam verdes sem qualquer ajuste.

### 2.2 `app/Services/EyeImages/BoundedVisionProcess.php` (novo)

- **Não-final** (documentado: não-finalidade existe para o mock de teste no container); métodos públicos `stitch`/`align` mockáveis.
- Constantes (único lugar para limites/timeouts/caps):
  ```php
  public const PYTHON              = 'python3';
  public const PANORAMA_TIMEOUT    = 20.0;          // parede (Process::setTimeout)
  public const ALIGN_TIMEOUT       = 10.0;
  public const PANORAMA_CPU_SECONDS = 25;           // prlimit --cpu (folga sobre o timeout de parede)
  public const ALIGN_CPU_SECONDS    = 15;
  public const RLIMIT_AS_BYTES     = 805306368;     // 768 MB
  public const RLIMIT_FSIZE_BYTES  = 67108864;      // 64 MB (PNG de saída)
  public const MAX_OUTPUT_BYTES    = 8192;          // stdout = 1 linha JSON
  public const MAX_IMAGE_BYTES     = 16777216;      // 16 MB por imagem de entrada
  public const MAX_PANORAMA_INPUTS = 6;
  ```
- Gating público = `hasIsolation()` (do trait); a checagem extra de `/usr/bin/prlimit` executável fica no guarda do `runScript()` (abaixo).
- `public function stitch(array $imagePaths, string $outPath): void` → `runScript('scripts/exam-panorama-stitch.py', [$outPath, ...$imagePaths], self::PANORAMA_TIMEOUT, self::PANORAMA_CPU_SECONDS)`; `expectOk()` do JSON.
- `public function align(string $imageA, string $imageB): array` → `runScript('scripts/exam-align-detect.py', [$imageA, $imageB], self::ALIGN_TIMEOUT, self::ALIGN_CPU_SECONDS)`; devolve `['dx' => (float), 'dy' => (float), 'confidence' => (float)]`; `ok:false` → `RuntimeException(reason)`.
- `runScript()` (privado) monta o comando **só com argumentos internos**:
  ```php
  ['/usr/bin/setsid', '--', '/usr/bin/prlimit', '--as=' . self::RLIMIT_AS_BYTES,
   '--cpu=' . $cpuSeconds, '--fsize=' . self::RLIMIT_FSIZE_BYTES, '--',
   self::PYTHON, '-I', base_path($script), ...$args]
  ```
  checa `self::hasIsolation()` e `is_executable('/usr/bin/prlimit')` antes (senão `RuntimeException('vision_process_isolation_unavailable')`), chama `execute(...)`, captura `Symfony\...\ProcessTimedOutException` → `RuntimeException('vision_timeout')`; `json_decode` inválido/vazio → `vision_invalid_output`. Nenhum caminho aceita entrada de request no comando.
- Ganchos: `workerFailureReason()` → `'vision_worker_failed'`; `outputLimitFailureReason()` → `'vision_output_limit'`.

### 2.3 Scripts

- `scripts/exam-panorama-stitch.py` (ajuste): `fail()` passa a **imprimir o JSON e `sys.exit(0)`**; docstring atualizada (cita `App\Services\EyeImages\BoundedVisionProcess`; exit 0 sempre, exit ≠ 0 = crash real). Reasons mantidos: `usage`, `cv2_unavailable`, `unreadable_image:<path>`, `stitch_failed:<status>`, `write_failed`; sucesso `{"ok":true}`.
- `scripts/exam-align-detect.py` (novo, mesmo contrato de stdout/exit):
  - argv `<image_a> <image_b>`; import tardio de `cv2`/`numpy` → `cv2_unavailable`.
  - Constantes no topo: `ORB_FEATURES = 2000`, `RATIO_TEST = 0.75`, `MIN_MATCHES = 12`, `MIN_INLIERS = 10`, `MIN_CONFIDENCE = 0.3`, `RANSAC_REPROJ = 3.0`.
  - Fluxo: lê as duas em grayscale (unreadable → `unreadable_image`); **redimensiona B para as dimensões de A** (`INTER_AREA`); ORB detect/descreve; BFMatcher Hamming + ratio test; poucos matches → `no_matches`; `cv2.estimateAffinePartial2D(src=B, dst=A, method=RANSAC, ransacReprojThreshold=3.0)`; `None`/`inliers < MIN_INLIERS` → `low_confidence`; `confidence = inliers/matches < MIN_CONFIDENCE` → `low_confidence`; sucesso `{"ok":true,"dx":m[0,2],"dy":m[1,2],"confidence":round(...,4)}` (só translação usada; rotação/escala ficam para a Fase 2).
- Fallback (decisão 5 do briefing): se o smoke test da Forja com imagens sintéticas deslocadas mostrar ORB falhando, troca-se o cálculo por `cv2.phaseCorrelate` **dentro do mesmo script**, mantendo o contrato de saída (`ok`, `dx`, `dy`, `confidence`) — muda só o script.
- Nota de segurança: `unreadable_image:<path>` carrega caminho de arquivo temp — o PHP mapeia por **prefixo** e nunca devolve o reason cru ao cliente.

### 2.4 Staging de imagens — `app/Services/EyeImages/Concerns/StagesExamImages.php` (novo)

- Usado pelos dois serviços (Panorama/Align) — mesma regra `display_archive`/`derivative_status` num lugar só:
  ```php
  private function stageExamImage(PatientExam $exam): string
  {
      $path = ($exam->derivative_status === 'ready' && $exam->display_archive !== null)
          ? $exam->display_archive
          : $exam->archive;

      if ($path === null) {
          throw new RuntimeException('vision_image_missing');
      }

      $bytes = app(BoundedExamArchive::class)->read($path, BoundedVisionProcess::MAX_IMAGE_BYTES);
      $tmp   = tempnam(sys_get_temp_dir(), 'vision-');

      if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
          throw new RuntimeException('vision_temp_failed');
      }

      return $tmp;
  }
  ```
- Panoramas/align apagam os temp no `finally` do serviço (nunca deixam arquivo órfão).

## 3. Item 2 — Panorama (serviço + controller + rota + UI + i18n)

### 3.1 `app/Services/EyeImages/PanoramaService.php` (novo)

- Construtor: `private readonly BoundedVisionProcess $process`.
- `build(array $exams): string` (bytes PNG): `$cleanup = []`; `try` — para cada exame `$cleanup[] = $this->stageExamImage($exam)`; `$out = tempnam(...,'panorama-')` (entra no cleanup); `$this->process->stitch($paths, $out)`; `file_get_contents($out)` vazio/false → `RuntimeException('panorama_failed')`; devolve os bytes; `finally` — `@unlink` de tudo. Nenhum `RuntimeException` é engolido (controller traduz).

### 3.2 `app/Http/Controllers/EyeImagePanoramaController.php` (novo)

- `use AuthorizesEyeImageExams;` + construtor com `PanoramaService` (padrão `EyeImageMontageController`); docblock: não é ato clínico (admin/doctor/secretary).
- `store(Request $request)`:
  ```php
  $validated = $request->validate([
      'exam_ids'   => ['required', 'array', 'min:2', 'max:' . BoundedVisionProcess::MAX_PANORAMA_INPUTS],
      'exam_ids.*' => ['uuid', 'distinct'],
  ]);
  $examIds = $this->ownedExamIds($validated['exam_ids'], $entityId);   // 403 cross-tenant
  $ordered = /* mesma ordenação por seleção do EyeImageMontageController */;
  foreach ($ordered as $exam) { abort_if($exam->isVideo(), 422, __('eye_images.panorama_video_not_allowed')); }
  try { $png = $this->panoramaService->build($ordered); }
  catch (RuntimeException $e) { return response()->json(['message' => $this->message($e->getMessage())], 422); }
  return response($png, 200, ['Content-Type' => 'image/png', 'Content-Disposition' => 'attachment; filename="panorama.png"']);
  ```
- `message(string $reason)` (privado, `match(true)`):
  - `cv2_unavailable` / `vision_process_isolation_unavailable` → `panorama_unavailable`;
  - prefixo `unreadable_image` / `archive_` / `vision_image_missing` → `panorama_unreadable_image`;
  - prefixo `stitch_failed` → `panorama_stitch_failed`;
  - `vision_timeout` → `panorama_timeout`;
  - default → `panorama_failed`.
- `routes/web.php` (logo após o bloco do Montage, ~linha 318):
  ```php
  Route::post('/eye-images/panorama', [EyeImagePanoramaController::class, 'store'])
      ->middleware('entity.role:admin,doctor,secretary')
      ->name('eye-images.panorama.store');
  ```

### 3.3 `EyeImagesController::index` — `urls`

- Após `'montage' => …` no array `urls` (~linha 208), com `$vision = BoundedVisionProcess::hasIsolation();`:
  ```php
  'panorama' => $vision ? route('panel.eye-images.panorama.store') : null,
  'align'    => $vision ? route('panel.eye-images.align.store') : null,
  ```

### 3.4 Front (`Index.vue`)

1. Refatorar `downloadMontage()` extraindo o corpo para `downloadGeneratedImage({ url, filename, minCount, maxCount, busy, onSuccessExtra })` (mesmo tratamento de blob/erro e filtro de vídeo já existente); `downloadMontage()` vira wrapper com `min 2`/`max 50`/`montage.png`/`montageBusy`.
2. `downloadPanorama()`: wrapper com `props.urls.panorama`, `min 2`, `max 6`, `panorama.png`, `panoramaBusy`; no sucesso, toast com `panorama_approximate_notice`; seleção > 6 → botão desabilitado com dica (não chama API).
3. Botão **ao lado do Montage** (~linha 2809): `v-if="urls.panorama"`, `:disabled="count < 2 || count > 6 || panoramaBusy"`, `:title="tt('panorama_select_range', …) + ' ' + tt('panorama_approximate_notice', …)"`, spinner quando `panoramaBusy`, `@click="downloadPanorama"`.
4. Contagem = selecionados menos vídeos (mesma lógica do Montage, reaproveitada do helper extraído).

### 3.5 Testes do panorama

- Pest `tests/Feature/EyeImages/EyeImagePanoramaTest.php`:
  - beforeEach: `Storage::fake('s3')`, entidade/paciente/secretária, exames com `archive` no fake.
  - **Um caso por reason com o processo fingido**: `$this->mock(BoundedVisionProcess::class)` configurado para lançar `RuntimeException('cv2_unavailable' | 'unreadable_image:/tmp/x' | 'stitch_failed:1' | 'write_failed' | 'vision_timeout' | 'vision_worker_failed')` → 422 + `message` traduzida certa (assert no `message`, não no reason).
  - Sucesso: mock de `stitch()` grava bytes sentinela no `$outPath` → 200 `image/png`, `Content-Disposition: attachment; filename="panorama.png"`, corpo = sentinela.
  - `display_archive` preferido: exame com `derivative_status = 'ready'` + `display_archive` apontando para bytes diferentes do `archive`; o mock copia o conteúdo dos paths antes de retornar (o serviço apaga no `finally`) → assert dos bytes do display.
  - 403 cross-tenant (um exame de outra clínica); 422 com 1, 7+ ou ids repetidos; 422 vídeo (exame `archive = 'x.mp4'`).
  - Teste real: `markTestSkipped` sem `BoundedVisionProcess::hasIsolation()` ou sem cv2 (helper de teste que roda `python3 -c 'import cv2'` via `Process`, timeout 5); com tudo presente, 2 PNGs sintéticos (GD) no fake storage → 200 PNG.
- Vitest `tests/JavaScript/Panel/EyeImages/EyeImagePanoramaButton.test.js` (helper de `PrintModalReorder.test.js`): botão ausente sem `urls.panorama`; presente com url; 2..6 selecionados → POST com `exam_ids` na ordem; > 6 → desabilitado; sucesso → toast com `panorama_approximate_notice`; erro blob → `showErrorToast` com a `message` do servidor.

## 4. Item 3 — Align Fase 1 (translação)

### 4.1 `app/Services/EyeImages/ExamAlignmentService.php` (novo)

- Construtor: `private readonly BoundedVisionProcess $process`; `use StagesExamImages;`.
- `detect(PatientExam $a, PatientExam $b): array{dx:float,dy:float,confidence:float}` — stage dos dois (cleanup no `finally`), `$this->process->align($pathA, $pathB)`.

### 4.2 `app/Http/Controllers/EyeImageAlignController.php` (novo)

- Validação: `exam_id_a`/`exam_id_b` = `['required', 'uuid', 'different:exam_id_b']` (o `different` no a; symétrico no b se quiser).
- `ownedExamIds([a, b], $entityId)` (403 cross-tenant); vídeo → 422 `align_video_not_allowed`; chama `detect`; sucesso `response()->json(['dx' => …, 'dy' => …, 'confidence' => …])`.
- `message()` análogo: `cv2_unavailable`/`vision_process_isolation_unavailable` → `align_unavailable`; prefixo `unreadable_image`/`archive_`/`vision_image_missing` → `align_unreadable_image`; `no_matches` → `align_no_matches`; `low_confidence` → `align_low_confidence`; `vision_timeout` → `align_timeout`; default → `align_failed`.
- Rota: `POST /eye-images/align` com `entity.role:admin,doctor,secretary` → `name('eye-images.align.store')`.

### 4.3 Front (`EyeImageCompareModal.vue` + `Index.vue`)

- `EyeImageCompareModal.vue`: prop nova `alignUrl: { type: String, default: null }`; `ref="baseImg"` na `<img>` de baixo (images[0]); `alignBusy = ref(false)`.
  - Botão **"Auto-alinhar"** no toolbar do modo Sobrepor, ao lado de "Redefinir posição": `v-if="alignUrl"`, spinner quando `alignBusy`, `@click="autoAlign"`.
  - `autoAlign()`: guarda `alignBusy`/2 imagens; `window.axios.post(alignUrl, { exam_id_a: images[0].id, exam_id_b: images[1].id })`; no sucesso, `scale = baseImg.clientWidth / baseImg.naturalWidth` (natural 0 → não aplica e toast de falha) e `offset.x = dx * scale; offset.y = dy * scale`; no erro mantém o offset atual e `window.showErrorToast(e?.response?.data?.message ?? tt('align_failed', …))`; `finally` libera `alignBusy`.
- `Index.vue`: passar `:align-url="urls.align"` ao `<EyeImageCompareModal>` (~linha 4421).

### 4.4 Testes do align

- Pest `tests/Feature/EyeImages/EyeImageAlignTest.php` (mesmo padrão do panorama):
  - sucesso com mock devolvendo `dx/dy/confidence` → 200 JSON `{dx,dy,confidence}`;
  - um caso por reason (`align_unavailable`, `align_unreadable_image`, `align_no_matches`, `align_low_confidence`, `align_timeout`, `align_failed`) → 422 + mensagem i18n;
  - 403 quando um dos exames é de outra clínica; 422 ids iguais/payload inválido; 422 vídeo;
  - teste real (`markTestSkipped` sem `hasIsolation()`/cv2): dois PNGs sintéticos deslocados → 200 com `dx/dy` ≈ deslocamento (tolerância 2 px).
- Vitest `tests/JavaScript/Panel/EyeImages/EyeImageCompareAlign.test.js` (montar o modal direto com props):
  - para o ref base: `Object.defineProperty(img.element, 'clientWidth', { value: 410 })` / `'naturalWidth'` 820 → clique chama o endpoint e aplica `offset = dx * 0.5` (fator de escala coberto);
  - erro → offset inalterado + `showErrorToast`;
  - `alignUrl` nulo → botão ausente; `alignBusy` desabilita durante a chamada.

## 5. Testes — visão consolidada por arquivo

| Arquivo | Tipo | Roda quando |
|---|---|---|
| `tests/Python/test_exam_align_detect.py` | unittest do script (sintético: deslocamento conhecido → dx/dy ±2 px; sem overlap → `no_matches`/`low_confidence`) | local, venv do scratchpad (`<venv>/bin/python -m unittest discover -s tests/Python`); `skipUnless(cv2)` |
| `tests/Python/test_exam_panorama_stitch.py` | unittest do script (2 sintéticas com overlap → `ok:true` + PNG; sem overlap → `ok:false`) | idem |
| `tests/Feature/EyeImages/EyeImagePanoramaTest.php` | Pest (processo fingido por reason; 403/422; real skipped) | CI PHP (real: `hasIsolation()` + cv2) |
| `tests/Feature/EyeImages/EyeImageAlignTest.php` | Pest idem | idem |
| `tests/JavaScript/Panel/EyeImages/EyeImagePanoramaButton.test.js` | Vitest | CI JS |
| `tests/JavaScript/Panel/EyeImages/EyeImageCompareAlign.test.js` | Vitest (fator de escala) | CI JS |
| (sem ajuste) `tests/Feature/Api/Integrators/P2BoundariesTest.php` | regressão do trait | CI PHP |

## 6. Contrato backend ↔ front (Forja → Prisma)

- Inertia `eye-images` (`urls`, apenas acréscimos): `panorama: string|null`, `align: string|null` — `null` quando `!BoundedVisionProcess::hasIsolation()`.
- `POST /panel/eye-images/panorama` body `{ "exam_ids": ["uuid", …] }` (2..6, ordem da seleção)
  → `200` `image/png`, `Content-Disposition: attachment; filename="panorama.png"` (nunca persistido);
  → `422 { "message": "…" }` (cv2/isolamento, ilegível, stitch falhou, timeout, vídeo);
  → `403` exame de outra clínica.
- `POST /panel/eye-images/align` body `{ "exam_id_a": "uuid", "exam_id_b": "uuid" }`
  → `200 { "dx": 12.5, "dy": -3.0, "confidence": 0.83 }` (`dx/dy` em px do **natural** da imagem A);
  → `422 { "message": "…" }` (unavailable, no_matches, low_confidence, timeout, vídeo, ids iguais);
  → `403` exame de outra clínica.
- `EyeImageCompareModal` props: `open`, `images`, `t` (existentes) + `alignUrl: string|null` (item 3).
- Reasons internos do processo (não i18n, nunca devolvidos crus): `vision_process_isolation_unavailable`, `vision_worker_failed`, `vision_output_limit`, `vision_timeout`, `vision_invalid_output`, `vision_image_missing`, `vision_temp_failed` + reasons dos scripts.

## 7. Ordem de commits (só com "commit autorizado")

1. `docs: briefing + plano Panorama/auto-alinhamento` — briefing (untracked) + este arquivo.
2. `refactor: trait HasIsolatedProcess + processo de visão para os scripts Python` — trait novo, `BoundedPdfProcess` com overrides (comportamento igual), `BoundedVisionProcess`, scripts (`exam-panorama-stitch.py` ajustado + `exam-align-detect.py` novo), `StagesExamImages`, `tests/Python/`.
3. `feat(eye-images): panorama automático` — `PanoramaService`, `EyeImagePanoramaController`, rota, `Index.vue` (helper + botão), `EyeImagesController` (`urls.panorama`), i18n, Pest + Vitest.
4. `feat(eye-images): auto-alinhamento (translação)` — `ExamAlignmentService`, `EyeImageAlignController`, rota, `EyeImageCompareModal.vue`, `Index.vue` (`urls.align`/prop), `EyeImagesController` (`urls.align`), i18n, Pest + Vitest.
   (Alternativa "ou menos": 3+4 num único commit se o orquestrador preferir um só marco de feature.)

## 8. Verificação

- Pint só nos PHP tocados: `rtk proxy php vendor/bin/pint --test <arquivos>`.
- Prettier/eslint nos JS/Vue tocados (arquivos de tarefa; sem varrer a árvore).
- Vitest: `rtk npm test -- tests/JavaScript/Panel/EyeImages/EyeImagePanoramaButton.test.js tests/JavaScript/Panel/EyeImages/EyeImageCompareAlign.test.js`.
- Pest (com autorização e banco `easyeye_test`, nunca `--parallel`): `rtk proxy php vendor/bin/pest --filter='EyeImagePanorama|EyeImageAlign'`; regressão do trait: `--filter='P2Boundaries'`.
- Python (local, fora da CI): `<venv-scratchpad>/bin/python -m unittest discover -s tests/Python -v`.
- Smoke manual (se o orquestrador pedir): Docker já traz `python3-opencv`; gerar panorama de 2 retinografias sintéticas pelos endpoints.

## 9. i18n completa (pt_BR + en, `eye_images.php`)

- Panorama: `panorama_action` ("Panorama"), `panorama_select_range` ("Selecione de 2 a 6 imagens para montar o panorama."), `panorama_approximate_notice` ("Composição aproximada gerada automaticamente — não é uma medida; confira sobreposição e bordas."), `panorama_video_not_allowed`, `panorama_unavailable`, `panorama_unreadable_image`, `panorama_stitch_failed` ("Não foi possível combinar as imagens (sobreposição insuficiente ou baixo contraste) — tente exames do mesmo olho com áreas em comum."), `panorama_timeout` ("O panorama demorou demais para ser gerado. Tente com menos imagens."), `panorama_failed`.
- Align: `align_auto_button` ("Auto-alinhar"), `align_video_not_allowed`, `align_unavailable`, `align_unreadable_image`, `align_no_matches` ("Não foram encontrados pontos em comum suficientes entre as imagens."), `align_low_confidence` ("O alinhamento automático não foi confiável — ajuste manualmente."), `align_timeout`, `align_failed`.
- Nomes de reason **não** viram chave i18n; o match fica nos controllers.

## 10. Riscos

1. **Timeout × PHP-FPM**: geração síncrona de até ~20 s (panorama) + download/upload S3; `max_execution_time`/proxy podem cortar antes do fim. Mitigação: timeouts de parede constantes, rlimit de CPU coerente, mensagens de timeout claras; se a clínica tiver S3 lento, o 422 `*_timeout` é o pior caso (nunca 500). Fila/polling está fora de escopo por decisão do orquestrador.
2. **Memória do Stitcher**: AS 768 MB com até 6 imagens (display ≤ 2560 px, ≤ 16 MB cada) — margem boa, mas imagens fora do padrão (display ausente → archive grande) podem encostar no rlimit; o erro vira 422, não derruba o FPM.
3. **cv2 ausente em dev/CI**: UI esconde os botões (`urls` nulas) e os endpoints degradam com 422 `*_unavailable`; self-check Python roda só localmente no venv. Docker de produção já instala `python3-opencv`.
4. **Distorção nas bordas**: panorama de retinografia pode emendar com artefato — mitigado pelo aviso obrigatório `panorama_approximate_notice` (toast a cada geração + title); não é medida.
5. **Falso positivo do RANSAC**: poucos matches com inliers altos podem dar translação errada — mitigado por `MIN_MATCHES`/`MIN_INLIERS`/`MIN_CONFIDENCE` no script e pelo offset sempre ajustável a mão (falha mantém posição).
6. **`reason` com caminho de arquivo**: `unreadable_image:<path>` é mapeado por prefixo; nenhum path cru chega ao cliente.

## 11. Critérios de aceite

- Trait: `BoundedPdfProcess` com os mesmos códigos (`pdf_output_limit`/`pdf_worker_failed`) e `P2BoundariesTest` verde sem edição.
- Panorama: 2..6 exames da mesma clínica geram PNG nunca persistido; ordem da seleção; vídeo/limites 422; `stitch_failed`/`cv2_unavailable`/timeout com mensagens próprias; aviso de composição a cada geração; botão só com `urls.panorama`.
- Align: 2 ids distintos geram `{dx,dy,confidence}` em px do natural de A; front aplica o fator de escala e mantém o offset em falha; botão só no modo Sobrepor e com `urls.align`; `low_confidence`/`no_matches` com mensagens próprias.
- Segurança/tenant: 403 para exame de outra clínica nos dois endpoints; nada de entrada de request no comando do processo; temp sempre apagado.
- Testes: Pest com processo fingido (um por reason) + Vitest dos dois fronts + self-checks Python verdes no venv; suíte Pest completa sem `--parallel`.

## 12. Fora de escopo

- Fila/polling e persistência do panorama; rotação/escala no align (Fase 2); validação de lateralidade no Comparar (item 9 do lote anterior); calculadora IOL; tela nova; checagem de cv2 por request; mudanças em Docker/infra.

## 13. Decisões do orquestrador (Claude Code, 18/10/2026)

1. §1 aceito integralmente. Respostas às dúvidas da Bússola: (a) **3 commits**: docs → infra (trait + `BoundedVisionProcess` + scripts + `StagesExamImages` + `tests/Python/`) → panorama + align juntos (compartilham `routes/web.php`, `EyeImagesController`, `Index.vue` e os dois `eye_images.php`; dividir exigiria quebrar hunks sem ganho); (b) rlimits/timeouts de §2.2 aceitos — a Forja confirma no smoke local que o stitch de 6 imagens sintéticas 2560 px cabe em AS 768 MB rodando o script **sob o mesmo `prlimit`** do comando real (venv do scratchpad); se estourar, sobe para 1 GB e registra aqui; (c) `exam-panorama-stitch.py` sai 0 com `ok:false` — aceito.
2. Ordem: Forja (item 1 → backend do 2 → backend do 3, Pest só ela) e Prisma (front do 2 → front do 3, só Vitest dos arquivos tocados) em paralelo, contrato §6 fixo. Prisma não roda Pest; Forja não roda a suíte Vitest inteira.
3. `downloadMontage()` pode virar wrapper do helper (§3.4) desde que `VideoExamExclusion.test.js` e `PrintModalReorder.test.js` sigam verdes sem edição.
4. Mensagem de `unreadable_image` nunca inclui o path (já em §10.6); além disso o `reason` cru nunca vai para log em nível acima de `debug` com o path do temp.
5. Teste real (com cv2) fica `skipped` na CI; o critério "confirmado em staging com retinografias reais" (DoD do spec de origem) é do dono, após o deploy — registrado como nota de deploy, não como gate deste lote.
6. Desvios da implementação (Forja, 19/10/2026), aceitos pelo orquestrador; smoke sob o mesmo `setsid prlimit env python -I` do comando real (venv cv2 5.0.0, 8 CPUs, imagens sintéticas 2560 px):
   1. **AS por script**: `RLIMIT_AS_BYTES` (768 MB) virou `PANORAMA_AS_BYTES` = 1,5 GB e `ALIGN_AS_BYTES` = 768 MB — o stitch de 6 imagens falha com AS ≤ 900 MB (850/950 oscilaram), passa 6/6 a 1 GB, ~2,1 s e pico RSS ~415 MB (PNG 11,9 MB); 1,5 GB por margem. O align cabe em 640 MB (0,38 s, pico ~107 MB).
   2. **Prefixo `/usr/bin/env MALLOC_ARENA_MAX=2 OPENBLAS_NUM_THREADS=1 OMP_NUM_THREADS=1`** (`BoundedVisionProcess::WORKER_ENV`) antes do `python3`: sem ele o cv2 morre (SIGSEGV/abort) já no import sob `prlimit --as` (arenas do glibc e threads do BLAS contam no espaço de endereçamento). Argumentos seguem 100% internos.
   3. **`cv2.ocl.setUseOpenCL(False)`** no `exam-panorama-stitch.py` (worker de servidor, sem runtime OpenCL reservando endereçamento).
   4. **`cv2.imwrite` → `cv2.imencode('.png')` + escrita**: o PHP passa `tempnam` sem extensão e o `imwrite` não acha o encoder (exit 1 → `panorama_failed`); bug achado pelo teste real, coberto no `unittest`.
   5. **`dx`/`dy` do align = deslocamento do centro** (`M·centro − centro`), não o termo de translação cru da matriz: igual numa translação pura (smoke: esperado −41/30, obtido −40,98/29,96, confiança 0,98), mas com 3° de rotação o termo cru erra ~23 px. ORB bastou — `phaseCorrelate` não foi necessário.
   6. **`BoundedVisionProcess::isAvailable()`** (instância: `hasIsolation()` e `/usr/bin/prlimit` executável) decide `urls.panorama`/`urls.align` e o guard do `runScript()`; permite testar os dois ramos.
   7. **Log só com o código do reason** (`BoundedVisionProcess::reasonCode()` corta o detalhe depois de `:`), `Log::warning`, nunca o caminho do temporário (decisão 4).
   Registrados sem alteração (fora de escopo): (c) o Montage ordena pela ordem do banco (`ownedExamIds()`), não pela da seleção — o Panorama usa a do request; (d) o PNG inteiro passa pela memória do PHP (~12 MB típico; teto do `--fsize` = 64 MB).
