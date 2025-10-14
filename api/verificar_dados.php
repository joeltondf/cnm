<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

$codIbge = get_query_param('cod_ibge');

if (!$codIbge) {
    json_response([
        'success' => false,
        'message' => 'Parâmetro obrigatório: cod_ibge.',
    ], 400);
    exit;
}

try {
    if ($codIbge === 'BR') {
        $sql = 'SELECT exercicio, periodo, COUNT(*) AS total
                FROM rreo_dados
                GROUP BY exercicio, periodo
                ORDER BY exercicio DESC, periodo DESC';
        $stmt = $pdo->query($sql);
    } elseif (strpos($codIbge, 'UF-') === 0) {
        $uf = substr($codIbge, 3);
        $sql = 'SELECT r.exercicio, r.periodo, COUNT(*) AS total
                FROM rreo_dados r
                INNER JOIN entes e ON r.cod_ibge = e.cod_ibge
                WHERE e.uf = :uf
                GROUP BY r.exercicio, r.periodo
                ORDER BY r.exercicio DESC, r.periodo DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':uf', $uf, PDO::PARAM_STR);
        $stmt->execute();
    } else {
        $sql = 'SELECT exercicio, periodo, COUNT(*) AS total
                FROM rreo_dados
                WHERE cod_ibge = :cod_ibge
                GROUP BY exercicio, periodo
                ORDER BY exercicio DESC, periodo DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':cod_ibge', $codIbge, PDO::PARAM_STR);
        $stmt->execute();
    }

    $dados = $stmt->fetchAll();
    json_response([
        'success' => true,
        'disponiveis' => $dados,
    ]);
} catch (PDOException $e) {
    json_response([
        'success' => false,
        'error'   => 'Erro ao verificar disponibilidade de dados.',
        'details' => $e->getMessage(),
    ], 500);
}
