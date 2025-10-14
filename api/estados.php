<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

try {
    $stmt = $pdo->query('SELECT uf, COUNT(*) AS total_municipios FROM entes GROUP BY uf ORDER BY uf');
    $estados = $stmt->fetchAll();
    json_response($estados);
} catch (PDOException $e) {
    json_response([
        'success' => false,
        'error' => 'Erro ao consultar estados.',
        'details' => $e->getMessage(),
    ], 500);
}
