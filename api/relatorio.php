<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

$codIbge   = get_query_param('cod_ibge');
$exercicio = get_query_param('exercicio');
$periodo   = get_query_param('periodo');

if (!$codIbge || !$exercicio || !$periodo) {
    json_response([
        'success' => false,
        'message' => 'Parâmetros obrigatórios: cod_ibge, exercicio, periodo.',
    ], 400);
    exit;
}

$exercicio = (int) $exercicio;
$periodo = (int) $periodo;

if ($exercicio <= 0 || $periodo < 1 || $periodo > 6) {
    json_response([
        'success' => false,
        'message' => 'Exercício ou período inválido.',
    ], 400);
    exit;
}

$municipioNome = 'Consolidado';
try {
    if ($codIbge === 'BR') {
        $municipioNome = 'Brasil';
        $sql = 'SELECT conta, coluna, SUM(valor) AS valor
                FROM rreo_dados
                WHERE exercicio = :exercicio AND periodo = :periodo
                GROUP BY conta, coluna';
        $params = [
            ':exercicio' => $exercicio,
            ':periodo'   => $periodo,
        ];
    } elseif (strpos($codIbge, 'UF-') === 0) {
        $uf = substr($codIbge, 3);
        $municipioNome = "Estado {$uf}";
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
    } else {
        $stmtNome = $pdo->prepare('SELECT ente FROM entes WHERE cod_ibge = :cod_ibge');
        $stmtNome->bindValue(':cod_ibge', $codIbge, PDO::PARAM_STR);
        $stmtNome->execute();
        $nome = $stmtNome->fetchColumn();
        if ($nome) {
            $municipioNome = $nome;
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
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $registros = $stmt->fetchAll();

    if (!$registros) {
        json_response([
            'success' => false,
            'message' => 'Nenhum dado encontrado para os parâmetros informados.',
        ], 404);
        exit;
    }

    $dadosBrutos = [];
    foreach ($registros as $linha) {
        $conta = $linha['conta'];
        $coluna = $linha['coluna'];
        $valor = (float) $linha['valor'];

        if (!isset($dadosBrutos[$conta])) {
            $dadosBrutos[$conta] = [];
        }
        $dadosBrutos[$conta][$coluna] = $valor;
    }

    $getValor = static function (array $dados, string $conta, string $coluna): float {
        return isset($dados[$conta][$coluna]) ? (float) $dados[$conta][$coluna] : 0.0;
    };

    $kpis = [
        'receita_bimestre'   => $getValor($dadosBrutos, 'RECEITAS (EXCETO INTRA-ORÇAMENTÁRIAS) (I)', 'Até o Bimestre (c)'),
        'receita_corrente'   => $getValor($dadosBrutos, 'RECEITAS CORRENTES', 'Até o Bimestre (c)'),
        'receita_capital'    => $getValor($dadosBrutos, 'RECEITAS DE CAPITAL', 'Até o Bimestre (c)'),
        'transferencias'     => $getValor($dadosBrutos, 'TRANSFERÊNCIAS CORRENTES', 'Até o Bimestre (c)'),
        'despesa_bimestre'   => $getValor($dadosBrutos, 'SUBTOTAL DAS DESPESAS (X) = (VIII + IX)', 'DESPESAS LIQUIDADAS ATÉ O BIMESTRE (h)'),
        'despesa_corrente'   => $getValor($dadosBrutos, 'DESPESAS CORRENTES', 'DESPESAS LIQUIDADAS ATÉ O BIMESTRE (h)'),
        'despesa_capital'    => $getValor($dadosBrutos, 'DESPESAS DE CAPITAL', 'DESPESAS LIQUIDADAS ATÉ O BIMESTRE (h)'),
        'pessoal'            => $getValor($dadosBrutos, 'PESSOAL E ENCARGOS SOCIAIS', 'DESPESAS LIQUIDADAS ATÉ O BIMESTRE (h)'),
        'investimentos'      => $getValor($dadosBrutos, 'INVESTIMENTOS', 'DESPESAS LIQUIDADAS ATÉ O BIMESTRE (h)'),
        'outras_despesas'    => $getValor($dadosBrutos, 'OUTRAS DESPESAS CORRENTES', 'DESPESAS LIQUIDADAS ATÉ O BIMESTRE (h)'),
    ];
    $kpis['resultado_bimestre'] = $kpis['receita_bimestre'] - $kpis['despesa_bimestre'];

    json_response([
        'success'       => true,
        'municipioNome' => $municipioNome,
        'periodo'       => sprintf('%dº Bimestre/%d', $periodo, $exercicio),
        'kpis'          => $kpis,
        'dados_brutos'  => $dadosBrutos,
    ]);
} catch (PDOException $e) {
    json_response([
        'success' => false,
        'error'   => 'Erro ao gerar relatório.',
        'details' => $e->getMessage(),
    ], 500);
}
