<?php
require_once __DIR__ . '/lib/Database.php';

$pdo = Database::getConnection();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $url = 'https://servicodados.ibge.gov.br/api/v1/localidades/municipios';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FAILONERROR => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            throw new RuntimeException('Falha ao consultar a API do IBGE: ' . curl_error($ch));
        }
        curl_close($ch);

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Resposta inválida da API do IBGE.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare('REPLACE INTO municipios (
            id_ibge, nome, uf_sigla, uf_nome, regiao_sigla, regiao_nome, microrregiao, mesorregiao
        ) VALUES (
            :id_ibge, :nome, :uf_sigla, :uf_nome, :regiao_sigla, :regiao_nome, :microrregiao, :mesorregiao
        )');

        foreach ($data as $municipio) {
            $uf = $municipio['microrregiao']['mesorregiao']['UF'] ?? [];
            $regiao = $uf['regiao'] ?? [];
            $stmt->execute([
                ':id_ibge' => $municipio['id'],
                ':nome' => $municipio['nome'],
                ':uf_sigla' => $uf['sigla'] ?? '',
                ':uf_nome' => $uf['nome'] ?? '',
                ':regiao_sigla' => $regiao['sigla'] ?? '',
                ':regiao_nome' => $regiao['nome'] ?? '',
                ':microrregiao' => $municipio['microrregiao']['nome'] ?? null,
                ':mesorregiao' => $municipio['microrregiao']['mesorregiao']['nome'] ?? null,
            ]);
        }

        $pdo->commit();
        $message = sprintf('Sincronização concluída com %d municípios.', count($data));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$totalMunicipios = (int)$pdo->query('SELECT COUNT(*) FROM municipios')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sincronizar Municípios IBGE</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="max-w-3xl mx-auto py-10 px-4">
        <h1 class="text-3xl font-bold text-gray-900 mb-6">Sincronização de Municípios (IBGE)</h1>
        <p class="text-gray-600 mb-8">
            Esta página consulta a API oficial do IBGE e atualiza a tabela <code>municipios</code> com todos os municípios brasileiros.
        </p>

        <?php if ($message): ?>
            <div class="mb-6 rounded-lg bg-green-50 border border-green-200 text-green-700 px-4 py-3">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-6 rounded-lg bg-red-50 border border-red-200 text-red-700 px-4 py-3">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="bg-white shadow rounded-2xl p-6 mb-6">
            <p class="text-lg text-gray-700 mb-4">
                Municípios atualmente cadastrados: <span class="font-semibold"><?php echo number_format($totalMunicipios, 0, ',', '.'); ?></span>
            </p>
            <form method="post" onsubmit="return confirm('Deseja realmente sincronizar todos os municípios do IBGE? Este processo pode levar alguns segundos.');">
                <button type="submit" class="inline-flex items-center px-6 py-3 rounded-xl font-semibold bg-blue-600 text-white shadow hover:bg-blue-700 transition">
                    Sincronizar Municípios
                </button>
            </form>
        </div>

        <a href="index.php" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 font-semibold">&larr; Voltar para o dashboard</a>
    </div>
</body>
</html>
