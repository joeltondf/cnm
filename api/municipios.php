<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

$uf = get_query_param('uf', static function (string $value): string {
    return strtoupper(substr($value, 0, 2));
});

try {
    if ($uf) {
        $stmt = $pdo->prepare('SELECT cod_ibge, ente, uf FROM entes WHERE uf = :uf ORDER BY ente');
        $stmt->bindValue(':uf', $uf, PDO::PARAM_STR);
        $stmt->execute();
    } else {
        $stmt = $pdo->query('SELECT cod_ibge, ente, uf FROM entes ORDER BY ente');
    }

    $municipios = $stmt->fetchAll();
    json_response($municipios);
} catch (PDOException $e) {
    json_response([
        'success' => false,
        'error' => 'Erro ao consultar municípios.',
        'details' => $e->getMessage(),
    ], 500);
}
