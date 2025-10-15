<?php

require_once __DIR__ . '/Database.php';

/**
 * Serviço responsável por interagir com a API do SICONFI e com o banco local.
 */
class SiconfiService
{
    private PDO $pdo;
    private static int $lastRequestTimestamp = 0;
    private array $debugLog = [];

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /**
     * Garante que os dados estejam atualizados no banco considerando o cache.
     */
    public function ensureData(string $idEnte, int $ano, int $periodo, string $tipo, ?string $anexo = null, ?string $esfera = null): void
    {
        $this->addLog('Iniciando sincronização com parâmetros informados.', [
            'id_ente' => $idEnte,
            'ano' => $ano,
            'periodo' => $periodo,
            'tipo' => $tipo,
            'anexo' => $anexo,
            'esfera' => $esfera,
        ]);

        $cacheKey = $this->buildCacheKey($idEnte, $ano, $periodo, $tipo, $anexo, $esfera);
        $stmt = $this->pdo->prepare('SELECT fetched_at FROM api_cache WHERE cache_key = :cache_key');
        $stmt->execute([':cache_key' => $cacheKey]);
        $row = $stmt->fetch();

        $shouldFetch = true;
        if ($row) {
            $fetchedAt = new DateTime($row['fetched_at']);
            $now = new DateTime();
            $diff = $fetchedAt->diff($now);
            $diffHours = ($diff->days * 24) + $diff->h + ($diff->i / 60);
            if ($diffHours < CACHE_TTL_HOURS) {
                $shouldFetch = false;
                $this->addLog('Utilizando dados em cache.', [
                    'ultima_atualizacao' => $fetchedAt->format('Y-m-d H:i:s'),
                    'horas_desde_cache' => round($diffHours, 2),
                ]);
            }
        }

        if ($shouldFetch) {
            $this->addLog('Cache expirado ou inexistente. Buscando dados diretamente na API.');
            $data = $this->callSiconfiRREO($idEnte, $ano, $periodo, $tipo, $anexo, $esfera);
            if ($data !== null) {
                $this->addLog('Dados obtidos da API SICONFI.', [
                    'quantidade_registros' => count($data),
                ]);
                $this->updateDatabaseFromApi($data, $idEnte, $ano, $periodo, $tipo, $anexo, $esfera);
                $this->upsertCache($cacheKey);
                $this->addLog('Atualização concluída e cache renovado.');
            } else {
                $this->addLog('Nenhum dado retornado pela API. Verifique as mensagens anteriores.');
            }
        }
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

        $limit = defined('API_PAGE_LIMIT') ? (int)API_PAGE_LIMIT : 1000;
        if ($limit <= 0) {
            $limit = 1000;
        }

        $maxPages = defined('API_MAX_PAGES') ? (int)API_MAX_PAGES : 50;
        if ($maxPages <= 0) {
            $maxPages = 1;
        }

        $headers = $this->buildApiHeaders();

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
                $queryParams = array_merge($baseQuery, [
                    'limit' => $limit,
                    'offset' => $offset,
                ]);
                $requestUrl = API_BASE_URL . API_RREO_ENDPOINT . '?' . http_build_query($queryParams);
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
            return $response['items'];
        }

        if ($this->isSequentialArray($response)) {
            return $response;
        }

        return [];
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

    /**
     * Persiste os dados recebidos da API nas tabelas locais.
     */
    public function updateDatabaseFromApi(array $items, string $idEnte, int $ano, int $periodo, string $tipo, ?string $anexo, ?string $esfera): void
    {
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM rreo_registros WHERE id_ente = :id AND an_exercicio = :ano AND nr_periodo = :periodo AND co_tipo_demonstrativo = :tipo');
            $delete->execute([
                ':id' => $idEnte,
                ':ano' => $ano,
                ':periodo' => $periodo,
                ':tipo' => $tipo,
            ]);

            $insert = $this->pdo->prepare('INSERT INTO rreo_registros (
                id_ente, no_ente, sg_uf, an_exercicio, nr_periodo, co_tipo_demonstrativo,
                no_anexo, co_esfera, cd_conta, ds_conta, vl_previsto, vl_atualizado, vl_realizado
            ) VALUES (
                :id_ente, :no_ente, :sg_uf, :an_exercicio, :nr_periodo, :co_tipo_demonstrativo,
                :no_anexo, :co_esfera, :cd_conta, :ds_conta, :vl_previsto, :vl_atualizado, :vl_realizado
            )');

            $inserted = 0;
            foreach ($items as $item) {
                $insert->execute([
                    ':id_ente' => $item['id_ente'] ?? $idEnte,
                    ':no_ente' => $item['no_ente'] ?? null,
                    ':sg_uf' => $item['sg_uf'] ?? null,
                    ':an_exercicio' => (int)($item['an_exercicio'] ?? $ano),
                    ':nr_periodo' => (int)($item['nr_periodo'] ?? $periodo),
                    ':co_tipo_demonstrativo' => $item['co_tipo_demonstrativo'] ?? $tipo,
                    ':no_anexo' => $item['no_anexo'] ?? $anexo,
                    ':co_esfera' => $item['co_esfera'] ?? $esfera,
                    ':cd_conta' => $item['cd_conta'] ?? null,
                    ':ds_conta' => $item['ds_conta'] ?? null,
                    ':vl_previsto' => $this->toDecimal($item['vl_previsto'] ?? null),
                    ':vl_atualizado' => $this->toDecimal($item['vl_atualizado'] ?? null),
                    ':vl_realizado' => $this->toDecimal($item['vl_realizado'] ?? ($item['vl_ate_periodo'] ?? null)),
                ]);
                $inserted++;
            }

            $this->pdo->commit();
            $this->addLog('Registros persistidos no banco de dados.', [
                'total_inserido' => $inserted,
            ]);
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            $this->addLog('Erro ao gravar dados no banco.', [
                'mensagem' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getMunicipioNome(string $idEnte): ?string
    {
        $stmt = $this->pdo->prepare('SELECT nome FROM municipios WHERE id_ibge = :id LIMIT 1');
        $stmt->execute([':id' => $idEnte]);
        $row = $stmt->fetch();
        if ($row && isset($row['nome'])) {
            return $row['nome'];
        }

        $stmt = $this->pdo->prepare('SELECT no_ente FROM rreo_registros WHERE id_ente = :id LIMIT 1');
        $stmt->execute([':id' => $idEnte]);
        $fallback = $stmt->fetch();
        return $fallback['no_ente'] ?? null;
    }

    public function getMunicipiosDisponiveis(): array
    {
        $stmt = $this->pdo->query('SELECT id_ibge, nome, uf_sigla FROM municipios ORDER BY uf_sigla, nome');
        return $stmt->fetchAll();
    }

    public function getKpis(string $idEnte, int $ano, int $periodo, string $tipo): array
    {
        $receitas = $this->sumByConta($idEnte, $ano, $periodo, $tipo, [
            'Receitas Correntes',
            'Receitas de Capital',
        ]);
        $despesas = $this->sumByConta($idEnte, $ano, $periodo, $tipo, [
            'Despesas com Pessoal e Encargos',
            'Outras Despesas Correntes',
            'Investimentos',
        ]);

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

    public function getReceitas(string $idEnte, int $ano, int $periodo, string $tipo): array
    {
        $categorias = [
            'Receitas Correntes',
            'Receitas de Capital',
            'Transferências Correntes',
            'Transferências de Capital',
        ];

        $result = [];
        foreach ($categorias as $categoria) {
            $result[$categoria] = $this->sumByConta($idEnte, $ano, $periodo, $tipo, [$categoria])[$categoria] ?? 0.0;
        }

        $impostos = [
            'ISS' => $this->sumContaLike($idEnte, $ano, $periodo, $tipo, 'ISS'),
            'IPTU' => $this->sumContaLike($idEnte, $ano, $periodo, $tipo, 'IPTU'),
            'ITBI' => $this->sumContaLike($idEnte, $ano, $periodo, $tipo, 'ITBI'),
            'IRRF' => $this->sumContaLike($idEnte, $ano, $periodo, $tipo, 'IRRF'),
        ];

        return [
            'categorias' => $result,
            'impostos' => $impostos,
        ];
    }

    public function getDespesas(string $idEnte, int $ano, int $periodo, string $tipo): array
    {
        $pessoal = $this->sumContaLike($idEnte, $ano, $periodo, $tipo, 'Pessoal e Encargos');
        $outrasCorrentes = $this->sumContaLike($idEnte, $ano, $periodo, $tipo, 'Outras Despesas Correntes');
        $investimentos = $this->sumContaLike($idEnte, $ano, $periodo, $tipo, 'Investimentos');
        $reserva = $this->sumContaLike($idEnte, $ano, $periodo, $tipo, 'Reserva de Contingência');

        return [
            'pessoal' => $pessoal,
            'outras_correntes' => $outrasCorrentes,
            'investimentos' => $investimentos,
            'reserva' => $reserva,
        ];
    }

    public function getComparativo(string $idEnte, int $anoAtual, int $periodo, string $tipo): array
    {
        $anoAnterior = $anoAtual - 1;
        $kpiAtual = $this->getKpis($idEnte, $anoAtual, $periodo, $tipo);
        $kpiAnterior = $this->getKpis($idEnte, $anoAnterior, $periodo, $tipo);

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

    public function getDetalhes(string $idEnte, int $ano, int $periodo, string $tipo): array
    {
        $stmt = $this->pdo->prepare('SELECT cd_conta, ds_conta, vl_previsto, vl_atualizado, vl_realizado
            FROM rreo_registros
            WHERE id_ente = :id AND an_exercicio = :ano AND nr_periodo = :periodo AND co_tipo_demonstrativo = :tipo
            ORDER BY cd_conta');
        $stmt->execute([
            ':id' => $idEnte,
            ':ano' => $ano,
            ':periodo' => $periodo,
            ':tipo' => $tipo,
        ]);

        return $stmt->fetchAll();
    }

    private function sumByConta(string $idEnte, int $ano, int $periodo, string $tipo, array $contas): array
    {
        if (empty($contas)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($contas), '?'));
        $sql = 'SELECT ds_conta, SUM(vl_realizado) AS total FROM rreo_registros
            WHERE id_ente = ? AND an_exercicio = ? AND nr_periodo = ? AND co_tipo_demonstrativo = ?
            AND ds_conta IN (' . $placeholders . ')
            GROUP BY ds_conta';

        $params = array_merge([$idEnte, $ano, $periodo, $tipo], $contas);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $result = [];
        while ($row = $stmt->fetch()) {
            $result[$row['ds_conta']] = (float)$row['total'];
        }

        foreach ($contas as $conta) {
            if (!isset($result[$conta])) {
                $result[$conta] = 0.0;
            }
        }

        return $result;
    }

    private function sumContaLike(string $idEnte, int $ano, int $periodo, string $tipo, string $pattern): float
    {
        $stmt = $this->pdo->prepare('SELECT SUM(vl_realizado) AS total FROM rreo_registros
            WHERE id_ente = :id AND an_exercicio = :ano AND nr_periodo = :periodo AND co_tipo_demonstrativo = :tipo
            AND ds_conta LIKE :pattern');
        $stmt->execute([
            ':id' => $idEnte,
            ':ano' => $ano,
            ':periodo' => $periodo,
            ':tipo' => $tipo,
            ':pattern' => '%' . $pattern . '%',
        ]);
        $row = $stmt->fetch();
        return (float)($row['total'] ?? 0.0);
    }

    private function upsertCache(string $cacheKey): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO api_cache (cache_key, fetched_at) VALUES (:key, NOW())
            ON DUPLICATE KEY UPDATE fetched_at = VALUES(fetched_at)');
        $stmt->execute([':key' => $cacheKey]);
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

    private function addLog(string $message, array $context = []): void
    {
        $this->debugLog[] = [
            'timestamp' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'message' => $message,
            'context' => $context,
        ];
    }
}
