<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

/*
 * Este endpoint aceita o parâmetro GET opcional `q` para buscar municípios por nome.
 * Quando informado, o termo é envolvido por `%` em ambos os lados e utilizado em
 * uma cláusula `WHERE ente LIKE :q`, permitindo localizar o padrão em qualquer
 * posição do campo. Os dados são carregados para MySQL por meio de `LOAD DATA
 * INFILE '/caminho/arquivo.csv' INTO TABLE ... FIELDS TERMINATED BY ','
 * ENCLOSED BY '"' LINES TERMINATED BY '\n' IGNORE 1 ROWS;`, após exportação dos
 * CSVs a partir do PostgreSQL com `COPY tabela (...) TO 'arquivo.csv' DELIMITER
 * ',' CSV HEADER;`.
 */

$uf = get_query_param('uf', static function (string $value): string {
    return strtoupper(substr($value, 0, 2));
});

$q = get_query_param('q', static function (string $value): string {
    return substr($value, 0, 100);
});

if ($q === '') {
    $q = null;
}

try {
    $sql = 'SELECT cod_ibge, ente, uf FROM entes';
    $conditions = [];
    $params = [];

    if ($uf) {
        $conditions[] = 'uf = :uf';
        $params[':uf'] = $uf;
    }

    if ($q !== null) {
        $conditions[] = 'ente LIKE :q';
        $params[':q'] = sprintf('%%%s%%', $q);
    }

    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY ente LIMIT 50';

    $stmt = $pdo->prepare($sql);

    foreach ($params as $placeholder => $value) {
        $stmt->bindValue($placeholder, $value, PDO::PARAM_STR);
    }

    $stmt->execute();

    $municipios = $stmt->fetchAll();
    json_response($municipios);
} catch (PDOException $e) {
    json_response([
        'success' => false,
        'error' => 'Erro ao consultar municípios.',
        'details' => $e->getMessage(),
    ], 500);
}
