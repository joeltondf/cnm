<?php
// Script para popular tabelas de estados e municípios com dados da API do IBGE.
// Observação: ative a exibição de erros apenas em ambiente de desenvolvimento.
ini_set('display_errors', 1);
error_reporting(E_ALL);

set_time_limit(300);

require __DIR__ . '/config/config.php';

/**
 * Faz o download do conteúdo de uma URL, usando file_get_contents ou cURL como fallback.
 *
 * @param string $url
 * @return string
 * @throws RuntimeException
 */
function fetchUrl(string $url): string
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException("URL inválida: {$url}");
    }

    if (ini_get('allow_url_fopen')) {
        $data = @file_get_contents($url);
        if ($data === false) {
            $error = error_get_last();
            throw new RuntimeException('Falha ao baixar dados via file_get_contents: ' . ($error['message'] ?? 'erro desconhecido'));
        }
        return $data;
    }

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
        throw new RuntimeException('Falha ao baixar dados via cURL: ' . $error);
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("A requisição retornou status HTTP {$status} para {$url}");
    }

    return $data;
}

try {
    // Importar estados
    $estadosJson = fetchUrl('https://servicodados.ibge.gov.br/api/v1/localidades/estados');
    $estados = json_decode($estadosJson, true);

    if (!is_array($estados)) {
        throw new RuntimeException('Resposta inesperada ao decodificar estados: ' . json_last_error_msg());
    }

    $stmtEstado = $pdo->prepare('INSERT INTO estados (uf, nome) VALUES (?, ?) ON DUPLICATE KEY UPDATE nome = VALUES(nome)');
    $estadoCount = 0;

    foreach ($estados as $estado) {
        if (!isset($estado['sigla'], $estado['nome'])) {
            continue;
        }
        $stmtEstado->execute([$estado['sigla'], $estado['nome']]);
        $estadoCount++;
    }

    echo "Estados importados/atualizados: {$estadoCount}\n";

    // Importar municípios
    $municipiosJson = fetchUrl('https://servicodados.ibge.gov.br/api/v1/localidades/municipios');
    $municipios = json_decode($municipiosJson, true);

    if (!is_array($municipios)) {
        throw new RuntimeException('Resposta inesperada ao decodificar municípios: ' . json_last_error_msg());
    }

    $stmtMunicipio = $pdo->prepare('INSERT INTO entes (cod_ibge, ente, uf) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE ente = VALUES(ente), uf = VALUES(uf)');
    $municipioCount = 0;

    foreach ($municipios as $municipio) {
        if (!isset($municipio['id'], $municipio['nome'], $municipio['microrregiao']['mesorregiao']['UF']['sigla'])) {
            continue;
        }

        $ufSigla = $municipio['microrregiao']['mesorregiao']['UF']['sigla'];
        $stmtMunicipio->execute([
            $municipio['id'],
            $municipio['nome'],
            $ufSigla,
        ]);
        $municipioCount++;
    }

    echo "Municípios importados/atualizados: {$municipioCount}\n";
    echo "Importação concluída.\n";
    echo "Obs.: desative a exibição de erros em produção e remova este script após o uso.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Ocorreu um erro durante a importação: ' . $e->getMessage();
}
