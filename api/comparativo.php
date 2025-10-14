<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/siconfi_client.php';

$codIbge    = get_query_param('cod_ibge');
$exercicioA = get_query_param('exercicioA');
$periodoA   = get_query_param('periodoA');
$exercicioB = get_query_param('exercicioB');
$periodoB   = get_query_param('periodoB');

if (!$codIbge || !$exercicioA || !$periodoA || !$exercicioB || !$periodoB) {
    json_response([
        'success' => false,
        'message' => 'Parâmetros obrigatórios: cod_ibge, exercicioA, periodoA, exercicioB, periodoB.',
    ], 400);
    exit;
}

$exercicioA = (int) $exercicioA;
$periodoA   = (int) $periodoA;
$exercicioB = (int) $exercicioB;
$periodoB   = (int) $periodoB;

$periodos = [
    'A' => ['exercicio' => $exercicioA, 'periodo' => $periodoA],
    'B' => ['exercicio' => $exercicioB, 'periodo' => $periodoB],
];

$datasets = [];

try {
    foreach ($periodos as $label => $info) {
        ensure_rreo_data($pdo, $codIbge, $info['exercicio'], $info['periodo']);
        [$sql, $params] = buildBaseQuery($pdo, $codIbge, $info['exercicio'], $info['periodo']);
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $registros = $stmt->fetchAll();

        $dados = [];
        foreach ($registros as $linha) {
            $conta = $linha['conta'];
            $coluna = $linha['coluna'];
            $valor = (float) $linha['valor'];

            if (!isset($dados[$conta])) {
                $dados[$conta] = [];
            }
            $dados[$conta][$coluna] = $valor;
        }
        $datasets[$label] = $dados;
    }

    if (!$datasets['A'] && !$datasets['B']) {
        $mensagem = 'Nenhum dado encontrado para os parâmetros informados.';

        if ($codIbge === 'BR') {
            $mensagem = 'Ainda não há dados consolidados do Brasil disponíveis para os períodos selecionados.';
        } elseif (strpos($codIbge, 'UF-') === 0) {
            $mensagem = 'Ainda não há dados consolidados para esta UF nos períodos informados.';
        }

        json_response([
            'success' => false,
            'message' => $mensagem,
        ], 404);
        exit;
    }

    $getValor = static function (array $dados, string $conta, string $coluna): float {
        return isset($dados[$conta][$coluna]) ? (float) $dados[$conta][$coluna] : 0.0;
    };

    $categorias = [
        'receitas_totais' => ['RECEITAS (EXCETO INTRA-ORÇAMENTÁRIAS) (I)', 'Até o Bimestre (c)'],
        'despesas_totais' => ['SUBTOTAL DAS DESPESAS (X) = (VIII + IX)', 'DESPESAS LIQUIDADAS ATÉ O BIMESTRE (h)'],
        'pessoal'         => ['PESSOAL E ENCARGOS SOCIAIS', 'DESPESAS LIQUIDADAS ATÉ O BIMESTRE (h)'],
        'investimentos'   => ['INVESTIMENTOS', 'DESPESAS LIQUIDADAS ATÉ O BIMESTRE (h)'],
        'outras_despesas' => ['OUTRAS DESPESAS CORRENTES', 'DESPESAS LIQUIDADAS ATÉ O BIMESTRE (h)'],
    ];

    $resultado = [];
    foreach ($categorias as $chave => [$conta, $coluna]) {
        $valorA = $getValor($datasets['A'], $conta, $coluna);
        $valorB = $getValor($datasets['B'], $conta, $coluna);
        $resultado[$chave] = [
            'valorA'     => $valorA,
            'valorB'     => $valorB,
            'variacao'   => calculate_variation($valorA, $valorB),
        ];
    }

    json_response([
        'success'     => true,
        'comparativo' => $resultado,
    ]);
} catch (RuntimeException $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 502);
} catch (PDOException $e) {
    json_response([
        'success' => false,
        'error'   => 'Erro ao gerar comparativo.',
        'details' => $e->getMessage(),
    ], 500);
}

function buildBaseQuery(PDO $pdo, string $codIbge, int $exercicio, int $periodo): array
{
    if ($codIbge === 'BR') {
        $sql = 'SELECT conta, coluna, SUM(valor) AS valor
                FROM rreo_dados
                WHERE exercicio = :exercicio AND periodo = :periodo
                GROUP BY conta, coluna';
        $params = [
            ':exercicio' => $exercicio,
            ':periodo'   => $periodo,
        ];
        return [$sql, $params];
    }

    if (strpos($codIbge, 'UF-') === 0) {
        $uf = substr($codIbge, 3);
        $sql = 'SELECT r.conta, r.coluna, SUM(r.valor) AS valor
                FROM rreo_dados r
                INNER JOIN entes e ON r.cod_ibge = e.cod_ibge
                WHERE r.exercicio = :exercicio
                  AND r.periodo = :periodo
                  AND e.uf = :uf
                GROUP BY r.conta, r.coluna';
        $params = [
            ':exercicio' => $exercicio,
            ':periodo'   => $periodo,
            ':uf'        => $uf,
        ];
        return [$sql, $params];
    }

    $sql = 'SELECT conta, coluna, valor
            FROM rreo_dados
            WHERE exercicio = :exercicio
              AND periodo = :periodo
              AND cod_ibge = :cod_ibge';
    $params = [
        ':exercicio' => $exercicio,
        ':periodo'   => $periodo,
        ':cod_ibge'  => $codIbge,
    ];

    return [$sql, $params];
}
