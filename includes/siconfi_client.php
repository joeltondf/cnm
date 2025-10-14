<?php
/**
 * Helper functions for interacting with the SICONFI open-data API
 * and ensuring the local database has the necessary RREO snapshots.
 */

const SICONFI_API_BASE = 'https://apidatalake.tesouro.gov.br/ords/siconfi/tt';

/**
 * Ensure that the local database has RREO data for the given ente/exercise/period.
 *
 * @throws RuntimeException when the remote API does not return data for the filters provided.
 */
function ensure_rreo_data(PDO $pdo, string $codIbge, int $exercicio, int $periodo): void
{
    if ($codIbge === 'BR' || strpos($codIbge, 'UF-') === 0) {
        // Consolidados dependem dos dados municipais já existentes.
        return;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rreo_dados WHERE cod_ibge = :cod_ibge AND exercicio = :exercicio AND periodo = :periodo');
    $stmt->execute([
        ':cod_ibge' => $codIbge,
        ':exercicio' => $exercicio,
        ':periodo' => $periodo,
    ]);

    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $items = download_rreo_items($codIbge, $exercicio, $periodo);
    if (!$items) {
        throw new RuntimeException('Nenhum dado retornado pela API SICONFI para o município informado.');
    }

    store_rreo_items($pdo, $codIbge, $exercicio, $periodo, $items);
}

/**
 * Fetch RREO items from the SICONFI API trying both demonstrative flavours.
 *
 * @return array<int, array<string, mixed>>
 */
function download_rreo_items(string $codIbge, int $exercicio, int $periodo): array
{
    $tiposDemonstrativo = ['RREO', 'RREO Simplificado'];

    foreach ($tiposDemonstrativo as $tipo) {
        $params = [
            'an_exercicio' => $exercicio,
            'nr_periodo' => $periodo,
            'co_tipo_demonstrativo' => $tipo,
            'id_ente' => $codIbge,
        ];

        try {
            $response = siconfi_fetch_json('rreo', $params);
        } catch (RuntimeException $e) {
            // Tente o próximo tipo quando houver falha no request.
            continue;
        }

        $items = extract_rreo_items($response);
        if ($items) {
            return $items;
        }
    }

    return [];
}

/**
 * Persist the downloaded RREO entries into the local database.
 */
function store_rreo_items(PDO $pdo, string $codIbge, int $exercicio, int $periodo, array $items): void
{
    $pdo->beginTransaction();

    $delete = $pdo->prepare('DELETE FROM rreo_dados WHERE cod_ibge = :cod_ibge AND exercicio = :exercicio AND periodo = :periodo');
    $delete->execute([
        ':cod_ibge' => $codIbge,
        ':exercicio' => $exercicio,
        ':periodo' => $periodo,
    ]);

    $insert = $pdo->prepare('INSERT INTO rreo_dados (cod_ibge, exercicio, periodo, conta, coluna, valor) VALUES (:cod_ibge, :exercicio, :periodo, :conta, :coluna, :valor)');

    foreach ($items as $item) {
        $conta = $item['conta'] ?? null;
        $coluna = $item['coluna'] ?? null;
        $valor = $item['valor'] ?? null;

        if (!$conta || !$coluna || !is_numeric($valor)) {
            continue;
        }

        $insert->execute([
            ':cod_ibge' => $codIbge,
            ':exercicio' => $exercicio,
            ':periodo' => $periodo,
            ':conta' => $conta,
            ':coluna' => $coluna,
            ':valor' => number_format((float) $valor, 2, '.', ''),
        ]);
    }

    $pdo->commit();
}

/**
 * Extract the list of items from different API payload shapes.
 *
 * @param array<string, mixed> $payload
 * @return array<int, array<string, mixed>>
 */
function extract_rreo_items(array $payload): array
{
    if (isset($payload['items']) && is_array($payload['items'])) {
        return $payload['items'];
    }

    if (isset($payload['value']) && is_array($payload['value'])) {
        return $payload['value'];
    }

    if (isset($payload[0]) && is_array($payload)) {
        return $payload;
    }

    return [];
}

/**
 * Perform a GET request to the SICONFI API and decode its JSON body.
 *
 * @param array<string, scalar> $params
 */
function siconfi_fetch_json(string $endpoint, array $params): array
{
    $url = sprintf('%s/%s', rtrim(SICONFI_API_BASE, '/'), ltrim($endpoint, '/'));
    $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $fullUrl = $queryString ? $url . '?' . $queryString : $url;

    $responseBody = http_get_with_fallback($fullUrl);

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Resposta inválida da API SICONFI: ' . json_last_error_msg());
    }

    return $decoded;
}

function http_get_with_fallback(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Não foi possível inicializar o cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $data = curl_exec($ch);
        if ($data === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Falha ao consumir a API SICONFI: ' . $error);
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('A API SICONFI retornou o status HTTP %d.', $status));
        }

        return $data;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 60,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $data = @file_get_contents($url, false, $context);
    if ($data === false) {
        $error = error_get_last();
        throw new RuntimeException('Falha ao consumir a API SICONFI: ' . ($error['message'] ?? 'erro desconhecido'));
    }

    return $data;
}
