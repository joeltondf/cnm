<?php

require_once __DIR__ . '/Database.php';

/**
 * Serviço responsável por interagir com a API do SICONFI e com o banco local.
 */
class SiconfiService
{
    private ?PDO $pdo = null;
    private static int $lastRequestTimestamp = 0;
    private array $debugLog = [];
    private array $dataCache = [];
    private array $metadataCache = [];

    public function __construct()
    {
        try {
            $this->pdo = Database::getConnection();
        } catch (Throwable $e) {
            $this->pdo = null;
            $this->addLog('Falha ao conectar ao banco de dados local.', [
                'erro' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mantido por compatibilidade: força o pré-carregamento dos dados em memória.
     */
    public function ensureData(string $idEnte, int $ano, int $periodo, string $tipo, ?string $anexo = null, ?string $esfera = null): void
    {
        $this->addLog('Pré-carregando dados diretamente da API (sem persistência local).', [
            'id_ente' => $idEnte,
            'ano' => $ano,
            'periodo' => $periodo,
            'tipo' => $tipo,
            'anexo' => $anexo,
            'esfera' => $esfera,
        ]);

        $this->getDataFromApi($idEnte, $ano, $periodo, $tipo, $anexo, $esfera);
    }

    /**
     * Consulta a API respeitando o limite de 1 requisição por segundo.
     */
    public function callSiconfiRREO(string $idEnte, int $ano, int $periodo, string $tipo, ?string $anexo = null, ?string $esfera = null): ?array
    {
        $baseQuery = array_filter([
            'an_exercicio' => $ano,
            'nr_periodo' => $periodo,
            'co_tipo_demonstrativo' => $tipo,
            'no_anexo' => $anexo,
            'co_esfera' => $esfera,
            'id_ente' => $idEnte,
        ], fn($value) => $value !== null && $value !== '');

        if (defined('API_ADDITIONAL_QUERY') && is_array(API_ADDITIONAL_QUERY)) {
            $baseQuery = array_merge($baseQuery, array_filter(API_ADDITIONAL_QUERY, fn($value) => $value !== null && $value !== ''));
        }

        $queriesToTry = $this->buildQueryAttempts($baseQuery, $periodo);

        $limit = defined('API_PAGE_LIMIT') ? (int)API_PAGE_LIMIT : 1000;
        if ($limit <= 0) {
            $limit = 1000;
        }

        $maxPages = defined('API_MAX_PAGES') ? (int)API_MAX_PAGES : 50;
        if ($maxPages <= 0) {
            $maxPages = 1;
        }

        $headers = $this->buildApiHeaders();

        foreach ($queriesToTry as $index => $queryParams) {
            $this->addLog('Executando tentativa de consulta à API SICONFI.', [
                'tentativa' => $index + 1,
                'parametros' => $queryParams,
            ]);

            $items = $this->fetchItemsWithPagination($queryParams, $headers, $limit, $maxPages);
            if ($items === null) {
                return null;
            }

            if (!empty($items)) {
                if ($index > 0) {
                    $this->addLog('Dados encontrados após aplicar tentativas alternativas.', [
                        'tentativa_sucesso' => $index + 1,
                        'total_registros' => count($items),
                    ]);
                }

                return $items;
            }

            $this->addLog('Tentativa concluída sem retorno de registros.', [
                'tentativa' => $index + 1,
            ]);
        }

        return [];
    }

    private function getDataFromApi(string $idEnte, int $ano, int $periodo, string $tipo, ?string $anexo, ?string $esfera): array
    {
        $cacheKey = $this->buildCacheKey($idEnte, $ano, $periodo, $tipo, $anexo, $esfera);

        if (!array_key_exists($cacheKey, $this->dataCache)) {
            $items = $this->callSiconfiRREO($idEnte, $ano, $periodo, $tipo, $anexo, $esfera);

            if ($items === null) {
                throw new RuntimeException('Não foi possível consultar a API do SICONFI.');
            }

            $normalized = array_map(fn(array $item) => $this->normalizeFinancialFields($item), $items);

            if (!empty($normalized)) {
                $primeiro = $normalized[0];
                $this->metadataCache[$idEnte] = [
                    'no_ente' => $primeiro['no_ente'] ?? null,
                    'sg_uf' => $primeiro['sg_uf'] ?? null,
                ];
            }

            $this->dataCache[$cacheKey] = $normalized;
        }

        return $this->dataCache[$cacheKey];
    }

    private function normalizeFinancialFields(array $item): array
    {
        $camposNumericos = ['vl_previsto', 'vl_atualizado', 'vl_realizado', 'vl_ate_periodo', 'valor'];

        foreach ($camposNumericos as $campo) {
            if (array_key_exists($campo, $item)) {
                $item[$campo] = $this->toDecimal($item[$campo]) ?? 0.0;
            }
        }

        return $item;
    }

    private function fetchItemsWithPagination(array $queryParams, array $headers, int $limit, int $maxPages): ?array
    {
        $offset = 0;
        $page = 0;
        $allItems = [];
        $nextUrl = null;

        while (true) {
            if ($page >= $maxPages) {
                $message = sprintf('Limite de páginas (%d) atingido ao consultar API SICONFI.', $maxPages);
                error_log($message);
                $this->addLog($message);
                break;
            }

            if ($nextUrl !== null) {
                $requestUrl = $nextUrl;
            } else {
                $query = array_merge($queryParams, [
                    'limit' => $limit,
                    'offset' => $offset,
                ]);
                $requestUrl = API_BASE_URL . API_RREO_ENDPOINT . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            }

            $this->addLog('Consultando API SICONFI.', [
                'url' => $requestUrl,
                'pagina' => $page + 1,
            ]);
            $response = $this->performApiRequest($requestUrl, $headers);
            if ($response === null) {
                return null;
            }

            $items = $this->extractItemsFromResponse($response);
            $this->addLog('Resposta recebida da API.', [
                'pagina' => $page + 1,
                'quantidade_registros' => (is_array($items) || $items instanceof \Countable) ? count($items) : 0,
            ]);

            if (!empty($items)) {
                $allItems = array_merge($allItems, $items);
            }

            $hasMore = isset($response['hasMore']) ? (bool)$response['hasMore'] : false;
            $nextUrl = $this->extractNextLink($response);
            $page++;

            if ($nextUrl !== null) {
                continue;
            }

            if ($hasMore) {
                $offset += $limit;
                continue;
            }

            break;
        }

        return $allItems;
    }

    private function buildQueryAttempts(array $baseQuery, int $periodo): array
    {
        $attempts = [];

        $candidates = [];

        $withPeriodicidade = $this->appendPeriodicidade($baseQuery, $periodo);
        if ($withPeriodicidade !== null) {
            $candidates[] = $withPeriodicidade;
        }

        $candidates[] = $baseQuery;

        foreach ($candidates as $candidate) {
            $attempts[] = $candidate;

            if (isset($candidate['co_esfera'])) {
                $withoutEsfera = $candidate;
                unset($withoutEsfera['co_esfera']);
                $attempts[] = $withoutEsfera;
            }
        }

        $unique = [];
        $result = [];
        foreach ($attempts as $attempt) {
            ksort($attempt);
            $key = md5(json_encode($attempt));
            if (!isset($unique[$key])) {
                $unique[$key] = true;
                $result[] = $attempt;
            }
        }

        return $result;
    }

    private function appendPeriodicidade(array $query, int $periodo): ?array
    {
        if (isset($query['co_periodicidade'])) {
            return null;
        }

        $periodicidade = null;

        if (defined('API_DEFAULT_PERIODICIDADE') && API_DEFAULT_PERIODICIDADE !== '') {
            $periodicidade = API_DEFAULT_PERIODICIDADE;
        } elseif ($periodo >= 1 && $periodo <= 6) {
            $periodicidade = 'B';
        }

        if ($periodicidade === null) {
            return null;
        }

        $query['co_periodicidade'] = $periodicidade;
        return $query;
    }

    private function performApiRequest(string $url, array $headers): ?array
    {
        $this->respectRateLimit();

        $this->addLog('Enviando requisição HTTP.', [
            'url' => $url,
            'cabecalhos' => $headers,
        ]);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FAILONERROR => false,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if (defined('API_USER_AGENT') && API_USER_AGENT !== '') {
            $options[CURLOPT_USERAGENT] = API_USER_AGENT;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = 'Erro ao consultar API SICONFI: ' . curl_error($ch);
            error_log($error);
            $this->addLog($error);
            curl_close($ch);
            return null;
        }

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            $message = sprintf('Erro HTTP %d ao consultar API SICONFI em %s', $httpCode, $url);
            error_log($message);
            $this->addLog($message);
            return null;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            $message = 'Resposta inválida da API SICONFI';
            error_log($message);
            $this->addLog($message);
            return null;
        }

        $this->addLog('Requisição HTTP concluída com sucesso.', [
            'codigo_http' => $httpCode,
        ]);

        return $decoded;
    }

    private function extractItemsFromResponse(array $response): array
    {
        if (isset($response['items']) && is_array($response['items'])) {
            return array_map([$this, 'normalizeItemKeys'], $response['items']);
        }

        if ($this->isSequentialArray($response)) {
            return array_map([$this, 'normalizeItemKeys'], $response);
        }

        return [];
    }

    private function normalizeItemKeys($item): array
    {
        if (!is_array($item)) {
            return [];
        }

        $item = array_change_key_case($item, CASE_LOWER);

        if (isset($item['cod_conta']) && !isset($item['cd_conta'])) {
            $item['cd_conta'] = $item['cod_conta'];
        }

        if (isset($item['conta']) && !isset($item['ds_conta'])) {
            $item['ds_conta'] = $item['conta'];
        }

        if (isset($item['valor'])) {
            $item['valor'] = $this->toDecimal($item['valor']);
        }

        return $item;
    }

    private function extractNextLink(array $response): ?string
    {
        if (!isset($response['links']) || !is_array($response['links'])) {
            return null;
        }

        foreach ($response['links'] as $link) {
            if (!is_array($link)) {
                continue;
            }

            $rel = $link['rel'] ?? null;
            $href = $link['href'] ?? null;

            if ($rel === 'next' && is_string($href) && $href !== '') {
                return $this->normalizeLink($href);
            }
        }

        return null;
    }

    private function normalizeLink(string $link): string
    {
        if (preg_match('/^https?:/i', $link) === 1) {
            return $link;
        }

        return rtrim(API_BASE_URL, '/') . '/' . ltrim($link, '/');
    }

    private function buildApiHeaders(): array
    {
        $headers = ['Accept: application/json'];

        if (defined('API_HEADERS') && is_array(API_HEADERS) && !empty(API_HEADERS)) {
            $headers = API_HEADERS;
        }

        if (defined('API_AUTHORIZATION') && API_AUTHORIZATION !== '') {
            $headers[] = 'Authorization: ' . API_AUTHORIZATION;
        }

        return $headers;
    }

    private function isSequentialArray(array $data): bool
    {
        if ($data === []) {
            return true;
        }

        return array_keys($data) === range(0, count($data) - 1);
    }

    public function getMunicipioNome(string $idEnte, ?int $ano = null, ?int $periodo = null, ?string $tipo = null, ?string $anexo = null, ?string $esfera = null): ?string
    {
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->prepare('SELECT nome FROM municipios WHERE id_ibge = :id LIMIT 1');
            $stmt->execute([':id' => $idEnte]);
            $row = $stmt->fetch();
            if ($row && isset($row['nome'])) {
                return $row['nome'];
            }
        }

        if (!isset($this->metadataCache[$idEnte]) && $ano !== null && $periodo !== null && $tipo !== null) {
            $this->getDataFromApi($idEnte, $ano, $periodo, $tipo, $anexo, $esfera);
        }

        if (isset($this->metadataCache[$idEnte]['no_ente'])) {
            return $this->metadataCache[$idEnte]['no_ente'];
        }

        return null;
    }

    public function getMunicipiosDisponiveis(): array
    {
        if (!($this->pdo instanceof PDO)) {
            return [];
        }

        $stmt = $this->pdo->query('SELECT id_ibge, nome, uf_sigla FROM municipios ORDER BY uf_sigla, nome');
        return $stmt->fetchAll();
    }

    public function getAnexosDisponiveis(): array
    {
        return [
            'RREO-Anexo 01' => 'Anexo 01 - Balanço Orçamentário',
            'RREO-Anexo 02' => 'Anexo 02 - Demonstrativo da Execução das Despesas por Função/Subfunção',
            'RREO-Anexo 03' => 'Anexo 03 - Demonstrativo da Receita Corrente Líquida',
            'RREO-Anexo 04' => 'Anexo 04 - Demonstrativo das Receitas e Despesas Previdenciárias do RPPS',
            'RREO-Anexo 05' => 'Anexo 05 - Demonstrativo das Despesas com Manutenção e Desenvolvimento do Ensino',
            'RREO-Anexo 06' => 'Anexo 06 - Demonstrativo do Resultado Primário e Nominal',
            'RREO-Anexo 07' => 'Anexo 07 - Demonstrativo dos Restos a Pagar por Poder e Órgão',
        ];
    }

    public function getAnexoLabel(?string $anexo): ?string
    {
        if ($anexo === null || $anexo === '') {
            return null;
        }

        $anexos = $this->getAnexosDisponiveis();
        return $anexos[$anexo] ?? $anexo;
    }

    public function getKpis(string $idEnte, int $ano, int $periodo, string $tipo, ?string $anexo = null, ?string $esfera = null): array
    {
        $items = $this->getDataFromApi($idEnte, $ano, $periodo, $tipo, $anexo, $esfera);
        return $this->calculateKpisFromItems($items);
    }

    public function getReceitas(string $idEnte, int $ano, int $periodo, string $tipo, ?string $anexo = null, ?string $esfera = null): array
    {
        $items = $this->getDataFromApi($idEnte, $ano, $periodo, $tipo, $anexo, $esfera);

        $categorias = $this->sumByContaFromItems($items, [
            'Receitas Correntes',
            'Receitas de Capital',
            'Transferências Correntes',
            'Transferências de Capital',
        ]);

        $impostos = [
            'ISS' => $this->sumContaLikeFromItems($items, 'ISS'),
            'IPTU' => $this->sumContaLikeFromItems($items, 'IPTU'),
            'ITBI' => $this->sumContaLikeFromItems($items, 'ITBI'),
            'IRRF' => $this->sumContaLikeFromItems($items, 'IRRF'),
        ];

        return [
            'categorias' => $categorias,
            'impostos' => $impostos,
            'por_coluna' => $this->summarizeByColumn($items, 'receita'),
        ];
    }

    public function getDespesas(string $idEnte, int $ano, int $periodo, string $tipo, ?string $anexo = null, ?string $esfera = null): array
    {
        $items = $this->getDataFromApi($idEnte, $ano, $periodo, $tipo, $anexo, $esfera);

        return [
            'pessoal' => $this->sumContaLikeFromItems($items, 'Pessoal e Encargos'),
            'outras_correntes' => $this->sumContaLikeFromItems($items, 'Outras Despesas Correntes'),
            'investimentos' => $this->sumContaLikeFromItems($items, 'Investimentos'),
            'reserva' => $this->sumContaLikeFromItems($items, 'Reserva de Contingência'),
            'por_coluna' => $this->summarizeByColumn($items, 'despesa'),
        ];
    }

    public function getComparativo(string $idEnte, int $anoAtual, int $periodo, string $tipo, ?string $anexo = null, ?string $esfera = null): array
    {
        $anoAnterior = $anoAtual - 1;

        try {
            $kpiAtual = $this->calculateKpisFromItems($this->getDataFromApi($idEnte, $anoAtual, $periodo, $tipo, $anexo, $esfera));
        } catch (Throwable $e) {
            $this->addLog('Falha ao carregar dados do ano atual para o comparativo.', [
                'ano' => $anoAtual,
                'erro' => $e->getMessage(),
            ]);
            $kpiAtual = $this->calculateKpisFromItems([]);
        }

        try {
            $kpiAnterior = $this->calculateKpisFromItems($this->getDataFromApi($idEnte, $anoAnterior, $periodo, $tipo, $anexo, $esfera));
        } catch (Throwable $e) {
            $this->addLog('Falha ao carregar dados do ano anterior para o comparativo.', [
                'ano' => $anoAnterior,
                'erro' => $e->getMessage(),
            ]);
            $kpiAnterior = $this->calculateKpisFromItems([]);
        }

        $comparativo = [];
        foreach (['receita_total', 'despesa_total', 'resultado_orcamentario'] as $campo) {
            $atual = $kpiAtual[$campo] ?? 0;
            $anterior = $kpiAnterior[$campo] ?? 0;
            $variacao = $anterior > 0 ? (($atual - $anterior) / $anterior) * 100 : null;
            $comparativo[$campo] = [
                'atual' => $atual,
                'anterior' => $anterior,
                'variacao' => $variacao,
            ];
        }

        return $comparativo;
    }

    public function getDetalhes(string $idEnte, int $ano, int $periodo, string $tipo, ?string $anexo = null, ?string $esfera = null): array
    {
        $items = $this->getDataFromApi($idEnte, $ano, $periodo, $tipo, $anexo, $esfera);

        $colunas = [];
        $agrupados = [];

        foreach ($items as $item) {
            $codigo = (string)($item['cd_conta'] ?? $item['cod_conta'] ?? '');
            $descricao = (string)($item['ds_conta'] ?? $item['conta'] ?? '');
            $coluna = trim((string)($item['coluna'] ?? 'Valor'));
            if ($coluna === '') {
                $coluna = 'Valor';
            }

            $colunas[$coluna] = true;
            $chave = $codigo . '|' . $descricao;

            if (!isset($agrupados[$chave])) {
                $agrupados[$chave] = [
                    'codigo' => $codigo,
                    'descricao' => $descricao,
                    'valores' => [],
                ];
            }

            $valor = $this->getValorFromItem($item);
            $prioridade = $this->getColumnPriority($coluna);

            if (!isset($agrupados[$chave]['valores'][$coluna])) {
                $agrupados[$chave]['valores'][$coluna] = [
                    'valor' => $valor,
                    'prioridade' => $prioridade,
                ];
                continue;
            }

            $existente = $agrupados[$chave]['valores'][$coluna];
            if ($prioridade > $existente['prioridade']) {
                $agrupados[$chave]['valores'][$coluna] = [
                    'valor' => $valor,
                    'prioridade' => $prioridade,
                ];
            } elseif ($prioridade === $existente['prioridade']) {
                $agrupados[$chave]['valores'][$coluna]['valor'] += $valor;
            }
        }

        $colunasOrdenadas = array_keys($this->sortColumnsByPriority(array_fill_keys(array_keys($colunas), 0.0)));

        $linhas = array_values(array_map(function (array $linha) {
            $linha['valores'] = array_map(static fn(array $entry) => $entry['valor'], $linha['valores']);
            return $linha;
        }, $agrupados));

        usort($linhas, function (array $a, array $b): int {
            $codigoA = (string)($a['codigo'] ?? '');
            $codigoB = (string)($b['codigo'] ?? '');
            $comparacao = strcmp($codigoA, $codigoB);
            if ($comparacao !== 0) {
                return $comparacao;
            }

            return strcmp((string)($a['descricao'] ?? ''), (string)($b['descricao'] ?? ''));
        });

        return [
            'colunas' => $colunasOrdenadas,
            'linhas' => $linhas,
        ];
    }

    private function sumByContaFromItems(array $items, array $contas): array
    {
        $resultado = [];
        $contasBusca = [];
        $acumulados = [];

        foreach ($contas as $conta) {
            $resultado[$conta] = 0.0;
            $contasBusca[$conta] = $this->normalizeDescricao($conta);
            $acumulados[$conta] = [];
        }

        foreach ($items as $item) {
            $descricao = $this->normalizeDescricao($item['ds_conta'] ?? $item['conta'] ?? null);
            if ($descricao === '') {
                continue;
            }

            foreach ($contasBusca as $contaOriginal => $contaNormalizada) {
                if ($contaNormalizada === '') {
                    continue;
                }

                if ($descricao === $contaNormalizada || str_contains($descricao, $contaNormalizada)) {
                    $chave = (string)($item['cd_conta'] ?? $item['conta'] ?? md5($descricao));
                    $valor = $this->getValorFromItem($item);
                    $prioridade = $this->getColumnPriority($item['coluna'] ?? null);
                    $this->addValueWithPriority($acumulados[$contaOriginal], $chave, $valor, $prioridade);
                    break;
                }
            }
        }

        foreach ($resultado as $contaOriginal => $_) {
            $resultado[$contaOriginal] = array_sum(array_map(static fn(array $entry) => $entry['valor'], $acumulados[$contaOriginal]));
        }

        return $resultado;
    }

    private function sumContaLikeFromItems(array $items, string $pattern): float
    {
        $patternNormalizado = $this->normalizeDescricao($pattern);

        if ($patternNormalizado === '') {
            return 0.0;
        }

        $acumulado = [];

        foreach ($items as $item) {
            $descricao = $this->normalizeDescricao($item['ds_conta'] ?? $item['conta'] ?? null);
            if ($descricao !== '' && str_contains($descricao, $patternNormalizado)) {
                $chave = (string)($item['cd_conta'] ?? $item['conta'] ?? md5($descricao));
                $valor = $this->getValorFromItem($item);
                $prioridade = $this->getColumnPriority($item['coluna'] ?? null);
                $this->addValueWithPriority($acumulado, $chave, $valor, $prioridade);
            }
        }

        return array_sum(array_map(static fn(array $entry) => $entry['valor'], $acumulado));
    }

    private function summarizeByColumn(array $items, string $tipo): array
    {
        $totais = [];

        foreach ($items as $item) {
            $descricaoNormalizada = $this->normalizeDescricao($item['ds_conta'] ?? $item['conta'] ?? null);
            if ($descricaoNormalizada === '') {
                continue;
            }

            if ($tipo === 'receita' && !str_contains($descricaoNormalizada, 'receita')) {
                continue;
            }

            if ($tipo === 'despesa' && !str_contains($descricaoNormalizada, 'despesa')) {
                continue;
            }

            $coluna = trim((string)($item['coluna'] ?? 'Valor'));
            if ($coluna === '') {
                $coluna = 'Valor';
            }

            $valor = $this->getValorFromItem($item);
            if (!isset($totais[$coluna])) {
                $totais[$coluna] = 0.0;
            }

            $totais[$coluna] += $valor;
        }

        if ($totais === []) {
            return [];
        }

        return $this->sortColumnsByPriority($totais);
    }

    private function calculateKpisFromItems(array $items): array
    {
        $receitas = $this->sumByContaFromItems($items, [
            'Receitas Correntes',
            'Receitas de Capital',
        ]);
        $despesas = [
            'Despesas com Pessoal e Encargos' => $this->sumContaLikeFromItems($items, 'Pessoal e Encargos'),
            'Outras Despesas Correntes' => $this->sumContaLikeFromItems($items, 'Outras Despesas Correntes'),
            'Investimentos' => $this->sumContaLikeFromItems($items, 'Investimentos'),
        ];

        $receitaTotal = array_sum($receitas);
        $despesaTotal = array_sum($despesas);
        $resultado = $receitaTotal - $despesaTotal;

        return [
            'receita_total' => $receitaTotal,
            'despesa_total' => $despesaTotal,
            'resultado_orcamentario' => $resultado,
            'receitas_composicao' => $receitas,
            'despesas_composicao' => $despesas,
        ];
    }

    private function sortColumnsByPriority(array $totais): array
    {
        uksort($totais, function (string $a, string $b): int {
            $prioridadeA = $this->getColumnPriority($a);
            $prioridadeB = $this->getColumnPriority($b);

            if ($prioridadeA === $prioridadeB) {
                return strcasecmp($a, $b);
            }

            return $prioridadeB <=> $prioridadeA;
        });

        return $totais;
    }

    private function getColumnPriority(?string $coluna): int
    {
        if ($coluna === null) {
            return 0;
        }

        $colunaNormalizada = strtoupper(trim($coluna));

        $prioridades = [
            4 => ['REALIZ', 'EXECUT', 'LIQUID', 'ARREC', 'PAGO', 'EMPENH'],
            3 => ['ATUALIZ', 'ATUAL'],
            2 => ['PREVISÃO', 'PREVISAO', 'PREVIST', 'PREV'],
            1 => ['VALOR'],
        ];

        foreach ($prioridades as $valorPrioridade => $tokens) {
            foreach ($tokens as $token) {
                if ($token !== '' && str_contains($colunaNormalizada, $token)) {
                    return $valorPrioridade;
                }
            }
        }

        return 0;
    }

    private function addValueWithPriority(array &$storage, string $key, float $valor, int $prioridade): void
    {
        if (!isset($storage[$key])) {
            $storage[$key] = [
                'prioridade' => $prioridade,
                'valor' => $valor,
            ];

            return;
        }

        if ($prioridade > $storage[$key]['prioridade']) {
            $storage[$key] = [
                'prioridade' => $prioridade,
                'valor' => $valor,
            ];

            return;
        }

        if ($prioridade === $storage[$key]['prioridade']) {
            $storage[$key]['valor'] += $valor;
        }
    }

    private function getValorFromItem(array $item): float
    {
        if (isset($item['valor']) && $item['valor'] !== '') {
            return (float)$item['valor'];
        }

        foreach (['vl_ate_periodo', 'vl_realizado', 'vl_atualizado', 'vl_previsto'] as $campo) {
            if (isset($item[$campo]) && $item[$campo] !== '') {
                return (float)$item[$campo];
            }
        }

        return 0.0;
    }

    public function getDebugLog(): array
    {
        return $this->debugLog;
    }

    private function respectRateLimit(): void
    {
        $now = (int)(microtime(true) * 1_000_000);
        $elapsed = $now - self::$lastRequestTimestamp;
        if ($elapsed < API_RATE_LIMIT_USEC) {
            usleep(API_RATE_LIMIT_USEC - $elapsed);
        }
        self::$lastRequestTimestamp = (int)(microtime(true) * 1_000_000);
    }

    private function buildCacheKey(string $idEnte, int $ano, int $periodo, string $tipo, ?string $anexo, ?string $esfera): string
    {
        return sha1(implode('|', [$idEnte, $ano, $periodo, $tipo, $anexo, $esfera]));
    }

    private function toDecimal($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        }

        return (float)$value;
    }

    private function normalizeDescricao(?string $descricao): string
    {
        if ($descricao === null) {
            return '';
        }

        $descricao = trim($descricao);
        if ($descricao === '') {
            return '';
        }

        $descricao = preg_replace('/\s+/u', ' ', $descricao);
        if ($descricao === null) {
            $descricao = '';
        }

        if ($descricao === '') {
            return '';
        }

        $descricao = strtr($descricao, [
            'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A', 'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ó' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ç' => 'C', 'ç' => 'c',
        ]);

        if ($descricao === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            $descricao = mb_strtolower($descricao, 'UTF-8');
        } else {
            $descricao = strtolower($descricao);
        }

        return $descricao;
    }

    private function addLog(string $message, array $context = []): void
    {
        $this->debugLog[] = [
            'timestamp' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'message' => $message,
            'context' => $context,
        ];
    }
}
