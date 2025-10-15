<?php
require_once __DIR__ . '/lib/SiconfiService.php';

// Valores padrão de consulta para carregar o dashboard inicial
$defaultParams = [
    'an_exercicio' => 2024,
    'nr_periodo' => 6,
    'co_tipo_demonstrativo' => 'RREO',
    'id_ente' => '3550308', // São Paulo - exemplo real
    'no_anexo' => null,
    'co_esfera' => 'M',
];

$params = [
    'an_exercicio' => isset($_GET['an_exercicio']) ? (int)$_GET['an_exercicio'] : $defaultParams['an_exercicio'],
    'nr_periodo' => isset($_GET['nr_periodo']) ? (int)$_GET['nr_periodo'] : $defaultParams['nr_periodo'],
    'co_tipo_demonstrativo' => $_GET['co_tipo_demonstrativo'] ?? $defaultParams['co_tipo_demonstrativo'],
    'id_ente' => $_GET['id_ente'] ?? $defaultParams['id_ente'],
    'no_anexo' => $_GET['no_anexo'] ?? $defaultParams['no_anexo'],
    'co_esfera' => $_GET['co_esfera'] ?? $defaultParams['co_esfera'],
];

$service = new SiconfiService();
$errorMessage = null;
$municipiosDisponiveis = $service->getMunicipiosDisponiveis();

try {
    // Consulta/cached da API e persistência
    $service->ensureData(
        $params['id_ente'],
        $params['an_exercicio'],
        $params['nr_periodo'],
        $params['co_tipo_demonstrativo'],
        $params['no_anexo'],
        $params['co_esfera']
    );
} catch (Throwable $e) {
    $errorMessage = 'Não foi possível atualizar os dados do SICONFI: ' . $e->getMessage();
}

$municipio = $service->getMunicipioNome($params['id_ente']) ?? 'Município não identificado';
$kpis = $service->getKpis($params['id_ente'], $params['an_exercicio'], $params['nr_periodo'], $params['co_tipo_demonstrativo']);
$kpis = array_merge([
    'receita_total' => 0.0,
    'despesa_total' => 0.0,
    'resultado_orcamentario' => 0.0,
    'receitas_composicao' => [],
    'despesas_composicao' => [],
], $kpis);

$receitas = $service->getReceitas($params['id_ente'], $params['an_exercicio'], $params['nr_periodo'], $params['co_tipo_demonstrativo']);
$receitas = array_merge([
    'categorias' => [],
    'impostos' => [],
], $receitas);

$despesas = $service->getDespesas($params['id_ente'], $params['an_exercicio'], $params['nr_periodo'], $params['co_tipo_demonstrativo']);
$despesas = array_merge([
    'pessoal' => 0.0,
    'outras_correntes' => 0.0,
    'investimentos' => 0.0,
    'reserva' => 0.0,
], $despesas);

$comparativo = $service->getComparativo($params['id_ente'], $params['an_exercicio'], $params['nr_periodo'], $params['co_tipo_demonstrativo']);
$detalhes = $service->getDetalhes($params['id_ente'], $params['an_exercicio'], $params['nr_periodo'], $params['co_tipo_demonstrativo']);

$dashboardData = [
    'municipio' => $municipio,
    'params' => $params,
    'kpis' => $kpis,
    'receitas' => $receitas,
    'despesas' => $despesas,
    'comparativo' => $comparativo,
    'detalhes' => $detalhes,
    'debug_log' => $service->getDebugLog(),
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório Municipalista - Execução Orçamentária</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .animate-spin { animation: spin 1s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .tab-button.active {
            background: linear-gradient(to right, var(--tw-gradient-stops));
            --tw-gradient-from: #3B82F6;
            --tw-gradient-to: #8B5CF6;
            color: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">
    <div class="max-w-7xl mx-auto px-4 py-10">
        <header class="mb-10">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                <div>
                    <p class="text-sm text-indigo-500 font-semibold uppercase tracking-wide">Relatório Resumido de Execução Orçamentária</p>
                    <h1 class="text-4xl font-bold text-gray-900 mt-2">Painel Municipalista</h1>
                    <p class="text-gray-500 mt-2">Dados oficiais do Tesouro Nacional / SICONFI atualizados automaticamente.</p>
                    <p class="text-gray-700 mt-2 font-medium">
                        <?php echo htmlspecialchars($municipio); ?> · Exercício <?php echo htmlspecialchars((string)$params['an_exercicio']); ?> · <?php echo htmlspecialchars($params['nr_periodo']); ?>º bimestre
                    </p>
                </div>
                <form method="get" class="bg-white/80 backdrop-blur rounded-2xl shadow-lg border border-white/50 p-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="text-sm font-semibold text-gray-600">Ano do Exercício</label>
                        <select name="an_exercicio" class="mt-1 w-full rounded-xl border-gray-200 focus:border-indigo-400 focus:ring-indigo-300">
                            <?php for ($ano = 2020; $ano <= (int)date('Y'); $ano++): ?>
                                <option value="<?php echo $ano; ?>" <?php echo $ano === $params['an_exercicio'] ? 'selected' : ''; ?>><?php echo $ano; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-gray-600">Bimestre</label>
                        <select name="nr_periodo" class="mt-1 w-full rounded-xl border-gray-200 focus:border-indigo-400 focus:ring-indigo-300">
                            <?php for ($bimestre = 1; $bimestre <= 6; $bimestre++): ?>
                                <option value="<?php echo $bimestre; ?>" <?php echo $bimestre === $params['nr_periodo'] ? 'selected' : ''; ?>><?php echo $bimestre; ?>º</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-gray-600">Tipo do Demonstrativo</label>
                        <select name="co_tipo_demonstrativo" class="mt-1 w-full rounded-xl border-gray-200 focus:border-indigo-400 focus:ring-indigo-300">
                            <option value="RREO" <?php echo $params['co_tipo_demonstrativo'] === 'RREO' ? 'selected' : ''; ?>>RREO Completo</option>
                            <option value="RREO Simplificado" <?php echo $params['co_tipo_demonstrativo'] === 'RREO Simplificado' ? 'selected' : ''; ?>>RREO Simplificado</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-sm font-semibold text-gray-600">Município (IBGE)</label>
                        <select name="id_ente" class="mt-1 w-full rounded-xl border-gray-200 focus:border-indigo-400 focus:ring-indigo-300" required>
                            <option value="">Selecione um município</option>
                            <?php foreach ($municipiosDisponiveis as $municipioOption): ?>
                                <?php $selected = $municipioOption['id_ibge'] == $params['id_ente'] ? 'selected' : ''; ?>
                                <option value="<?php echo htmlspecialchars((string)$municipioOption['id_ibge']); ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($municipioOption['nome'] . ' - ' . $municipioOption['uf_sigla']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($municipiosDisponiveis)): ?>
                            <p class="text-sm text-red-500 mt-2">Nenhum município cadastrado. <a href="sync_municipios.php" class="underline">Sincronize a lista com o IBGE</a>.</p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-gray-600">Esfera</label>
                        <select name="co_esfera" class="mt-1 w-full rounded-xl border-gray-200 focus:border-indigo-400 focus:ring-indigo-300">
                            <option value="">Todas</option>
                            <option value="M" <?php echo $params['co_esfera'] === 'M' ? 'selected' : ''; ?>>Municípios</option>
                            <option value="E" <?php echo $params['co_esfera'] === 'E' ? 'selected' : ''; ?>>Estados/DF</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-gray-600">Anexo (opcional)</label>
                        <input type="text" name="no_anexo" value="<?php echo htmlspecialchars((string)$params['no_anexo']); ?>" class="mt-1 w-full rounded-xl border-gray-200 focus:border-indigo-400 focus:ring-indigo-300" placeholder="Nome do anexo">
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3 flex justify-end items-end">
                        <button type="submit" class="inline-flex items-center px-6 py-3 rounded-xl font-semibold bg-gradient-to-r from-indigo-500 to-purple-500 text-white shadow-lg shadow-indigo-500/30 hover:opacity-90 transition">
                            Atualizar Dados
                        </button>
                    </div>
                </form>
            </div>
            <?php if ($errorMessage): ?>
                <div class="mt-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl">
                    <?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php endif; ?>
        </header>

        <nav class="flex flex-wrap gap-4 mb-8">
            <button data-tab="dashboard" class="tab-button active px-6 py-3 rounded-2xl bg-white text-gray-600 font-semibold shadow-sm">Dashboard</button>
            <button data-tab="receitas" class="tab-button px-6 py-3 rounded-2xl bg-white text-gray-600 font-semibold shadow-sm">Receitas</button>
            <button data-tab="despesas" class="tab-button px-6 py-3 rounded-2xl bg-white text-gray-600 font-semibold shadow-sm">Despesas</button>
            <button data-tab="comparativo" class="tab-button px-6 py-3 rounded-2xl bg-white text-gray-600 font-semibold shadow-sm">Comparativo</button>
            <button data-tab="detalhes" class="tab-button px-6 py-3 rounded-2xl bg-white text-gray-600 font-semibold shadow-sm">Detalhes</button>
            <button data-tab="logs" class="tab-button px-6 py-3 rounded-2xl bg-white text-gray-600 font-semibold shadow-sm">Logs</button>
        </nav>

        <section id="tab-dashboard" class="tab-content grid gap-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                    <p class="text-sm text-gray-500">Receita Total</p>
                    <p class="text-3xl font-bold text-indigo-600" id="kpi-receita-total">-</p>
                    <p class="text-xs text-gray-400 mt-2">Soma das receitas correntes e de capital.</p>
                </div>
                <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                    <p class="text-sm text-gray-500">Despesa Total</p>
                    <p class="text-3xl font-bold text-purple-600" id="kpi-despesa-total">-</p>
                    <p class="text-xs text-gray-400 mt-2">Inclui despesas com pessoal, correntes e investimentos.</p>
                </div>
                <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                    <p class="text-sm text-gray-500">Resultado Orçamentário</p>
                    <p class="text-3xl font-bold" id="kpi-resultado">-</p>
                    <p class="text-xs text-gray-400 mt-2">Diferença entre receitas totais e despesas totais.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-semibold text-gray-800">Composição das Receitas</h2>
                        <span class="text-sm text-gray-400">Valores realizados</span>
                    </div>
                    <canvas id="chart-receitas"></canvas>
                </div>
                <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-semibold text-gray-800">Principais Despesas</h2>
                        <span class="text-sm text-gray-400">Valores realizados</span>
                    </div>
                    <canvas id="chart-despesas"></canvas>
                </div>
            </div>
        </section>

        <section id="tab-receitas" class="tab-content hidden">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                    <p class="text-sm text-gray-500">Receitas Correntes</p>
                    <p class="text-2xl font-bold text-indigo-600" id="receita-corrente">-</p>
                </div>
                <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                    <p class="text-sm text-gray-500">Receitas de Capital</p>
                    <p class="text-2xl font-bold text-purple-600" id="receita-capital">-</p>
                </div>
                <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                    <p class="text-sm text-gray-500">Transferências</p>
                    <p class="text-2xl font-bold text-sky-600" id="receita-transferencias">-</p>
                </div>
            </div>
            <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60 mb-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Principais Impostos</h2>
                <ul id="lista-impostos" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-gray-600"></ul>
            </div>
            <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Distribuição das Receitas Próprias</h2>
                <canvas id="chart-impostos"></canvas>
            </div>
        </section>

        <section id="tab-despesas" class="tab-content hidden">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                    <p class="text-sm text-gray-500">Pessoal e Encargos</p>
                    <p class="text-xl font-bold text-indigo-600" id="despesa-pessoal">-</p>
                </div>
                <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                    <p class="text-sm text-gray-500">Outras Correntes</p>
                    <p class="text-xl font-bold text-purple-600" id="despesa-outras">-</p>
                </div>
                <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                    <p class="text-sm text-gray-500">Investimentos</p>
                    <p class="text-xl font-bold text-sky-600" id="despesa-investimentos">-</p>
                </div>
                <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                    <p class="text-sm text-gray-500">Reserva</p>
                    <p class="text-xl font-bold text-emerald-600" id="despesa-reserva">-</p>
                </div>
            </div>
            <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Composição da Despesa</h2>
                <canvas id="chart-despesa-rosca"></canvas>
            </div>
        </section>

        <section id="tab-comparativo" class="tab-content hidden">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                    <p class="text-sm text-gray-500">Receita Total</p>
                    <p class="text-xl font-bold text-indigo-600" id="comparativo-receita">-</p>
                    <p class="text-xs text-gray-500" id="comparativo-receita-var">-</p>
                </div>
                <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                    <p class="text-sm text-gray-500">Despesa Total</p>
                    <p class="text-xl font-bold text-purple-600" id="comparativo-despesa">-</p>
                    <p class="text-xs text-gray-500" id="comparativo-despesa-var">-</p>
                </div>
                <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                    <p class="text-sm text-gray-500">Resultado</p>
                    <p class="text-xl font-bold" id="comparativo-resultado">-</p>
                    <p class="text-xs text-gray-500" id="comparativo-resultado-var">-</p>
                </div>
            </div>
            <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60 mb-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Comparativo Anual</h2>
                <canvas id="chart-comparativo"></canvas>
            </div>
            <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Variação Percentual</h2>
                <table class="w-full text-left text-sm text-gray-600">
                    <thead>
                        <tr class="text-xs uppercase text-gray-400">
                            <th class="py-2">Indicador</th>
                            <th class="py-2">Ano Atual</th>
                            <th class="py-2">Ano Anterior</th>
                            <th class="py-2">Variação %</th>
                        </tr>
                    </thead>
                    <tbody id="comparativo-tabela"></tbody>
                </table>
            </div>
        </section>

        <section id="tab-detalhes" class="tab-content hidden">
            <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Detalhamento por Conta</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm text-gray-600">
                        <thead class="bg-gray-50">
                            <tr class="text-xs uppercase tracking-wide text-gray-500">
                                <th class="px-4 py-3 text-left">Código</th>
                                <th class="px-4 py-3 text-left">Descrição</th>
                                <th class="px-4 py-3 text-right">Previsto</th>
                                <th class="px-4 py-3 text-right">Atualizado</th>
                                <th class="px-4 py-3 text-right">Realizado</th>
                            </tr>
                        </thead>
                        <tbody id="detalhes-tabela" class="divide-y divide-gray-100"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <section id="tab-logs" class="tab-content hidden">
            <div class="bg-white rounded-3xl p-6 shadow-lg border border-white/60">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">Logs da API</h2>
                        <p class="text-sm text-gray-500">Acompanhe cada etapa da consulta para entender o que aconteceu com o carregamento.</p>
                    </div>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700">
                        <?php echo count($dashboardData['debug_log'] ?? []); ?> eventos
                    </span>
                </div>
                <div class="space-y-4">
                    <?php if (!empty($dashboardData['debug_log'])): ?>
                        <?php foreach ($dashboardData['debug_log'] as $logEntry): ?>
                            <div class="border border-gray-200 rounded-2xl p-4 bg-gray-50">
                                <p class="text-xs uppercase tracking-wide text-gray-500 font-semibold">
                                    <?php echo htmlspecialchars($logEntry['timestamp'] ?? ''); ?>
                                </p>
                                <p class="text-sm text-gray-800 font-medium mt-1">
                                    <?php echo htmlspecialchars($logEntry['message'] ?? ''); ?>
                                </p>
                                <?php if (!empty($logEntry['context'])): ?>
                                    <pre class="mt-3 text-xs text-gray-600 bg-white border border-gray-200 rounded-xl p-3 overflow-x-auto"><?php echo htmlspecialchars(json_encode($logEntry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-sm text-gray-500">Nenhuma informação de log disponível. Tente atualizar os dados para registrar novas entradas.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

    <script>
        const dashboardData = <?php echo json_encode($dashboardData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        function formatCurrency(value) {
            if (value === null || value === undefined) return 'R$ 0,00';
            return value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        }

        function formatPercent(value) {
            if (value === null || value === undefined) return '—';
            return `${value.toFixed(2)}%`;
        }

        function formatCompact(value) {
            if (!value) return 'R$ 0';
            const formatter = Intl.NumberFormat('pt-BR', { notation: 'compact', maximumFractionDigits: 1, style: 'currency', currency: 'BRL' });
            return formatter.format(value);
        }

        function updateKpis() {
            const { kpis } = dashboardData;
            document.getElementById('kpi-receita-total').textContent = formatCurrency(kpis.receita_total || 0);
            document.getElementById('kpi-despesa-total').textContent = formatCurrency(kpis.despesa_total || 0);
            const resultadoElem = document.getElementById('kpi-resultado');
            const resultado = kpis.resultado_orcamentario || 0;
            resultadoElem.textContent = formatCurrency(resultado);
            resultadoElem.classList.remove('text-emerald-600', 'text-rose-600');
            resultadoElem.classList.add(resultado >= 0 ? 'text-emerald-600' : 'text-rose-600');
        }

        function updateReceitas() {
            const { receitas } = dashboardData;
            const categorias = receitas.categorias || {};
            const totalTransferencias = (categorias['Transferências Correntes'] || 0) + (categorias['Transferências de Capital'] || 0);
            document.getElementById('receita-corrente').textContent = formatCompact(categorias['Receitas Correntes']);
            document.getElementById('receita-capital').textContent = formatCompact(categorias['Receitas de Capital']);
            document.getElementById('receita-transferencias').textContent = formatCompact(totalTransferencias);

            const lista = document.getElementById('lista-impostos');
            lista.innerHTML = '';
            Object.entries(receitas.impostos || {}).forEach(([nome, valor]) => {
                const li = document.createElement('li');
                li.className = 'flex justify-between items-center bg-gradient-to-r from-indigo-50 to-purple-50 rounded-2xl px-4 py-3 shadow-sm';
                li.innerHTML = `<span class="font-medium text-gray-600">${nome}</span><span class="text-indigo-600 font-semibold">${formatCurrency(valor)}</span>`;
                lista.appendChild(li);
            });
        }

        function updateDespesas() {
            const { despesas } = dashboardData;
            document.getElementById('despesa-pessoal').textContent = formatCompact(despesas.pessoal);
            document.getElementById('despesa-outras').textContent = formatCompact(despesas.outras_correntes);
            document.getElementById('despesa-investimentos').textContent = formatCompact(despesas.investimentos);
            document.getElementById('despesa-reserva').textContent = formatCompact(despesas.reserva);
        }

        function updateComparativo() {
            const { comparativo } = dashboardData;
            const map = {
                receita_total: ['comparativo-receita', 'comparativo-receita-var', 'Receita Total'],
                despesa_total: ['comparativo-despesa', 'comparativo-despesa-var', 'Despesa Total'],
                resultado_orcamentario: ['comparativo-resultado', 'comparativo-resultado-var', 'Resultado Orçamentário'],
            };

            const tabela = document.getElementById('comparativo-tabela');
            tabela.innerHTML = '';

            Object.entries(map).forEach(([campo, [idValor, idVar, label]]) => {
                const dados = comparativo[campo] || { atual: 0, anterior: 0, variacao: null };
                document.getElementById(idValor).textContent = `${formatCurrency(dados.atual)} · ${formatCurrency(dados.anterior)}`;
                document.getElementById(idVar).textContent = dados.variacao === null ? 'Sem histórico' : `Variação ${formatPercent(dados.variacao)}`;

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="px-4 py-3 font-medium text-gray-700">${label}</td>
                    <td class="px-4 py-3 text-right">${formatCurrency(dados.atual)}</td>
                    <td class="px-4 py-3 text-right">${formatCurrency(dados.anterior)}</td>
                    <td class="px-4 py-3 text-right">${dados.variacao === null ? '—' : formatPercent(dados.variacao)}</td>
                `;
                tabela.appendChild(tr);
            });
        }

        function updateDetalhes() {
            const corpo = document.getElementById('detalhes-tabela');
            corpo.innerHTML = '';
            (dashboardData.detalhes || []).forEach((linha) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="px-4 py-3 whitespace-nowrap">${linha.cd_conta || '—'}</td>
                    <td class="px-4 py-3">${linha.ds_conta || '—'}</td>
                    <td class="px-4 py-3 text-right">${formatCurrency(Number(linha.vl_previsto) || 0)}</td>
                    <td class="px-4 py-3 text-right">${formatCurrency(Number(linha.vl_atualizado) || 0)}</td>
                    <td class="px-4 py-3 text-right">${formatCurrency(Number(linha.vl_realizado) || 0)}</td>
                `;
                corpo.appendChild(tr);
            });
        }

        function buildCharts() {
            const receitasLabels = Object.keys(dashboardData.kpis.receitas_composicao || {});
            const receitasValores = Object.values(dashboardData.kpis.receitas_composicao || {});
            new Chart(document.getElementById('chart-receitas'), {
                type: 'pie',
                data: {
                    labels: receitasLabels,
                    datasets: [{
                        data: receitasValores,
                        backgroundColor: ['#6366F1', '#A855F7', '#06B6D4', '#10B981'],
                    }],
                },
                options: { plugins: { legend: { position: 'bottom' } } }
            });

            const despesasLabels = Object.keys(dashboardData.kpis.despesas_composicao || {});
            const despesasValores = Object.values(dashboardData.kpis.despesas_composicao || {});
            new Chart(document.getElementById('chart-despesas'), {
                type: 'bar',
                data: {
                    labels: despesasLabels,
                    datasets: [{
                        data: despesasValores,
                        backgroundColor: '#8B5CF6',
                        borderRadius: 12,
                    }],
                },
                options: {
                    plugins: { legend: { display: false } },
                    scales: { y: { ticks: { callback: (value) => formatCompact(value) } } },
                }
            });

            const impostosLabels = Object.keys(dashboardData.receitas.impostos || {});
            const impostosValores = Object.values(dashboardData.receitas.impostos || {});
            new Chart(document.getElementById('chart-impostos'), {
                type: 'bar',
                data: {
                    labels: impostosLabels,
                    datasets: [{
                        label: 'Receitas Próprias',
                        data: impostosValores,
                        backgroundColor: '#6366F1',
                        borderRadius: 12,
                    }],
                },
                options: {
                    plugins: { legend: { display: false } },
                    scales: { y: { ticks: { callback: (value) => formatCompact(value) } } },
                }
            });

            new Chart(document.getElementById('chart-despesa-rosca'), {
                type: 'doughnut',
                data: {
                    labels: despesasLabels,
                    datasets: [{
                        data: despesasValores,
                        backgroundColor: ['#6366F1', '#A855F7', '#0EA5E9', '#10B981'],
                    }],
                },
                options: { plugins: { legend: { position: 'bottom' } } }
            });

            const comparativo = dashboardData.comparativo || {};
            const compLabels = ['Receita', 'Despesa', 'Resultado'];
            const atual = [
                comparativo.receita_total?.atual || 0,
                comparativo.despesa_total?.atual || 0,
                comparativo.resultado_orcamentario?.atual || 0,
            ];
            const anterior = [
                comparativo.receita_total?.anterior || 0,
                comparativo.despesa_total?.anterior || 0,
                comparativo.resultado_orcamentario?.anterior || 0,
            ];

            new Chart(document.getElementById('chart-comparativo'), {
                type: 'bar',
                data: {
                    labels: compLabels,
                    datasets: [
                        {
                            label: `${dashboardData.params.an_exercicio}`,
                            data: atual,
                            backgroundColor: '#6366F1',
                            borderRadius: 12,
                        },
                        {
                            label: `${dashboardData.params.an_exercicio - 1}`,
                            data: anterior,
                            backgroundColor: '#94A3B8',
                            borderRadius: 12,
                        }
                    ],
                },
                options: {
                    scales: {
                        y: {
                            ticks: {
                                callback: (value) => formatCompact(value)
                            }
                        }
                    }
                }
            });
        }

        function setupTabs() {
            const buttons = document.querySelectorAll('.tab-button');
            const contents = document.querySelectorAll('.tab-content');
            buttons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    buttons.forEach((b) => b.classList.remove('active'));
                    btn.classList.add('active');
                    const tab = btn.dataset.tab;
                    contents.forEach((section) => {
                        section.classList.toggle('hidden', section.id !== `tab-${tab}`);
                    });
                });
            });
        }

        updateKpis();
        updateReceitas();
        updateDespesas();
        updateComparativo();
        updateDetalhes();
        buildCharts();
        setupTabs();
    </script>
</body>
</html>
