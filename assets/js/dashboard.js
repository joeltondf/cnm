const state = {
    kpiData: null,
    comparativoData: null,
    charts: {},
    estados: [],
    municipioOptions: [],
    municipioConsolidadoOptions: [],
    municipioLookup: new Map(),
    municipioValueToLabel: new Map(),
    lastMunicipioQuery: null,
    lastUfFilter: null,
};

let municipioSearchTimeout;

function showAlert(target, message, type = 'danger') {
    const container = document.querySelector(target);
    if (!container) return;
    container.innerHTML = `
        <div class="alert alert-${type} d-flex align-items-center" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i>
            <span>${message}</span>
        </div>
    `;
}

function clearAlert(target) {
    const container = document.querySelector(target);
    if (!container) return;
    container.innerHTML = '';
}

async function fetchJSON(url) {
    const response = await fetch(url);
    if (!response.ok) {
        const text = await response.text();
        throw new Error(text || 'Falha ao carregar dados.');
    }
    return response.json();
}

function populateSelect(select, options, placeholder, selectedValue = '') {
    select.innerHTML = '';
    let hasSelected = false;

    if (placeholder) {
        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = placeholder;
        select.appendChild(placeholderOption);
    }

    const appendOption = (parent, option) => {
        const opt = document.createElement('option');
        opt.value = option.value;
        opt.textContent = option.label;

        if (selectedValue && option.value === selectedValue) {
            opt.selected = true;
            hasSelected = true;
        }

        parent.appendChild(opt);
    };

    options.forEach(option => {
        if (Array.isArray(option.options)) {
            const group = document.createElement('optgroup');
            group.label = option.label;
            option.options.forEach(innerOption => appendOption(group, innerOption));
            select.appendChild(group);
        } else {
            appendOption(select, option);
        }
    });

    if (selectedValue && !hasSelected) {
        select.value = '';
    }
}

function getMunicipioConsolidadoOptions() {
    return [
        { value: 'BR', label: 'Brasil (Consolidado)' },
    ];
}

function normalizeText(value) {
    return value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
}

function cacheMunicipioOptions(municipioOptions, consolidadoOptions) {
    state.municipioOptions = municipioOptions;
    state.municipioConsolidadoOptions = consolidadoOptions;
    state.municipioLookup.clear();
    state.municipioValueToLabel.clear();

    const allOptions = [...consolidadoOptions, ...municipioOptions];

    allOptions.forEach(option => {
        state.municipioLookup.set(normalizeText(option.label), option.value);
        state.municipioValueToLabel.set(option.value, option.label);
    });
}

function updateMunicipioSuggestions(options) {
    const datalist = document.getElementById('municipioSuggestions');
    if (!datalist) {
        return;
    }

    datalist.innerHTML = '';
    options.slice(0, 50).forEach(option => {
        const suggestion = document.createElement('option');
        suggestion.value = option.label;
        datalist.appendChild(suggestion);
    });
}

function syncMunicipioInputWithSelect() {
    const select = document.getElementById('municipioSelect');
    const input = document.getElementById('municipioSearch');

    if (!select || !input) {
        return;
    }

    const label = state.municipioValueToLabel.get(select.value) || '';
    if (input.value !== label) {
        input.value = label;
    }
}

async function loadMunicipios(query = '') {
    const municipioSelect = document.getElementById('municipioSelect');
    if (!municipioSelect) {
        return;
    }

    const previousValue = municipioSelect.value;
    const ufSelect = document.getElementById('ufSelect');
    const ufValue = ufSelect ? ufSelect.value : '';
    const trimmedQuery = query.trim();

    if (state.lastMunicipioQuery === trimmedQuery && state.lastUfFilter === ufValue) {
        return;
    }

    const params = new URLSearchParams();

    if (ufValue && ufValue !== 'BR') {
        params.append('uf', ufValue);
    }

    if (trimmedQuery) {
        params.append('q', trimmedQuery);
    }

    municipioSelect.disabled = true;

    try {
        const url = `/api/municipios.php${params.toString() ? `?${params.toString()}` : ''}`;
        const municipios = await fetchJSON(url);

        const consolidadoOptions = getMunicipioConsolidadoOptions();
        const municipioOptions = municipios.map(municipio => ({
            value: municipio.cod_ibge,
            label: `${municipio.ente} - ${municipio.uf}`,
        }));

        cacheMunicipioOptions(municipioOptions, consolidadoOptions);
        updateMunicipioSuggestions(municipioOptions);

        const availableValues = new Set([
            ...consolidadoOptions.map(option => option.value),
            ...municipioOptions.map(option => option.value),
        ]);
        const selectedValue = availableValues.has(previousValue) ? previousValue : '';

        populateSelect(
            municipioSelect,
            [
                { label: 'Consolidados', options: consolidadoOptions },
                { label: 'Municípios', options: municipioOptions },
            ],
            'Selecione um município ou consolidado',
            selectedValue,
        );

        if (!trimmedQuery) {
            syncMunicipioInputWithSelect();
        }

        state.lastMunicipioQuery = trimmedQuery;
        state.lastUfFilter = ufValue;
    } catch (error) {
        console.error('Erro ao carregar municípios:', error);
    } finally {
        municipioSelect.disabled = false;
    }
}

async function loadEstadosMunicipios() {
    const estados = await fetchJSON('/api/estados.php');
    state.estados = estados;

    const estadoOptions = [
        { value: 'BR', label: 'Brasil (todos os estados)' },
        ...estados.map(estado => ({
            value: estado.uf,
            label: `${estado.uf} (${estado.total_municipios})`,
        })),
    ];

    populateSelect(document.getElementById('ufSelect'), estadoOptions, 'Filtrar por UF (opcional)');

    await loadMunicipios();
}

function renderKPIs(kpis) {
    const container = document.getElementById('kpiContainer');
    const formatter = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const colors = [
        'bg-gradient-primary',
        'bg-gradient-success',
        'bg-gradient-danger',
        'bg-gradient-warning text-dark',
        'bg-gradient-info',
        'bg-gradient-secondary',
        'bg-gradient-dark',
        'bg-gradient-purple',
        'bg-gradient-orange',
        'bg-gradient-teal',
    ];

    const kpiEntries = Object.entries(kpis);
    container.innerHTML = '';

    kpiEntries.forEach(([key, value], index) => {
        const colorClass = colors[index % colors.length];
        const card = document.createElement('div');
        card.className = 'col-12 col-md-6 col-xl-4';
        card.innerHTML = `
            <div class="card card-kpi ${colorClass} mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h2 class="h6 text-uppercase fw-bold mb-2">${formatKpiLabel(key)}</h2>
                            <p class="fs-4 mb-0">${formatter.format(value)}</p>
                        </div>
                        <span class="icon-bg"><i class="fa-solid fa-chart-column"></i></span>
                    </div>
                </div>
            </div>
        `;
        container.appendChild(card);
    });
}

function formatKpiLabel(key) {
    return key.replace(/_/g, ' ').replace(/\b(\w)/g, match => match.toUpperCase());
}

function destroyChart(id) {
    if (state.charts[id]) {
        state.charts[id].destroy();
        delete state.charts[id];
    }
}

function renderCharts(data) {
    const { kpis, dados_brutos } = data;
    const receita = kpis.receita_bimestre || 0;
    const despesa = kpis.despesa_bimestre || 0;

    destroyChart('resumoChart');
    destroyChart('receitasDetalheChart');
    destroyChart('despesasDetalheChart');

    const resumoCtx = document.getElementById('resumoChart');
    state.charts.resumoChart = new Chart(resumoCtx, {
        type: 'doughnut',
        data: {
            labels: ['Receitas', 'Despesas'],
            datasets: [{
                data: [receita, despesa],
                backgroundColor: ['#0d6efd', '#dc3545'],
            }],
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' },
            },
        },
    });

    const receitasDetalhe = [
        { label: 'Receitas Correntes', value: kpis.receita_corrente },
        { label: 'Receitas de Capital', value: kpis.receita_capital },
        { label: 'Transferências', value: kpis.transferencias },
    ];

    const receitaCtx = document.getElementById('receitasDetalheChart');
    state.charts.receitasDetalheChart = new Chart(receitaCtx, {
        type: 'bar',
        data: {
            labels: receitasDetalhe.map(item => item.label),
            datasets: [{
                label: 'Receitas',
                data: receitasDetalhe.map(item => item.value),
                backgroundColor: '#0d6efd',
            }],
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } },
        },
    });

    const despesasDetalhe = [
        { label: 'Despesas Correntes', value: kpis.despesa_corrente },
        { label: 'Despesas de Capital', value: kpis.despesa_capital },
        { label: 'Pessoal', value: kpis.pessoal },
        { label: 'Investimentos', value: kpis.investimentos },
        { label: 'Outras Despesas', value: kpis.outras_despesas },
    ];

    const despesaCtx = document.getElementById('despesasDetalheChart');
    state.charts.despesasDetalheChart = new Chart(despesaCtx, {
        type: 'bar',
        data: {
            labels: despesasDetalhe.map(item => item.label),
            datasets: [{
                label: 'Despesas',
                data: despesasDetalhe.map(item => item.value),
                backgroundColor: '#dc3545',
            }],
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } },
        },
    });

    const historico = extractHistorico(dados_brutos);
    const historicoCtx = document.getElementById('historicoChart');
    destroyChart('historicoChart');
    state.charts.historicoChart = new Chart(historicoCtx, {
        type: 'line',
        data: historico,
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true } },
        },
    });
}

function extractHistorico(dadosBrutos) {
    const labels = Object.keys(dadosBrutos || {});
    return {
        labels,
        datasets: [{
            label: 'Até o Bimestre (c)',
            data: labels.map(label => dadosBrutos[label]?.['Até o Bimestre (c)'] || 0),
            borderColor: '#0d6efd',
            backgroundColor: 'rgba(13, 110, 253, 0.1)',
        }],
    };
}

function renderComparativo(comparativo) {
    const container = document.getElementById('comparativoResultado');
    const formatter = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const rows = Object.entries(comparativo).map(([chave, valores]) => `
        <tr>
            <td>${formatKpiLabel(chave)}</td>
            <td>${formatter.format(valores.valorA)}</td>
            <td>${formatter.format(valores.valorB)}</td>
            <td class="${valores.variacao >= 0 ? 'text-success' : 'text-danger'}">
                ${valores.variacao.toFixed(2)}%
            </td>
        </tr>
    `).join('');
    container.innerHTML = rows;
}

async function handleBuscar(event) {
    event.preventDefault();
    const codIbge = document.getElementById('municipioSelect').value;
    const exercicio = document.getElementById('exercicioInput').value;
    const periodo = document.getElementById('periodoInput').value;

    if (!codIbge || !exercicio || !periodo) {
        showAlert('#mensagens', 'Informe município, exercício e período.');
        return;
    }

    showAlert('#mensagens', 'Buscando dados...', 'info');

    try {
        const data = await fetchJSON(`/api/relatorio.php?cod_ibge=${encodeURIComponent(codIbge)}&exercicio=${encodeURIComponent(exercicio)}&periodo=${encodeURIComponent(periodo)}`);
        clearAlert('#mensagens');
        state.kpiData = data;
        document.getElementById('tituloMunicipio').textContent = data.municipioNome;
        document.getElementById('tituloPeriodo').textContent = data.periodo;
        renderKPIs(data.kpis);
        renderCharts(data);
    } catch (error) {
        showAlert('#mensagens', `Erro: ${error.message}`);
    }
}

async function handleComparar(event) {
    event.preventDefault();
    const codIbge = document.getElementById('municipioSelect').value;
    const exercicioA = document.getElementById('exercicioA').value;
    const periodoA = document.getElementById('periodoA').value;
    const exercicioB = document.getElementById('exercicioB').value;
    const periodoB = document.getElementById('periodoB').value;

    if (!codIbge || !exercicioA || !periodoA || !exercicioB || !periodoB) {
        showAlert('#mensagensComparativo', 'Informe município e os dois períodos para comparação.');
        return;
    }

    showAlert('#mensagensComparativo', 'Carregando comparativo...', 'info');

    try {
        const data = await fetchJSON(`/api/comparativo.php?cod_ibge=${encodeURIComponent(codIbge)}&exercicioA=${encodeURIComponent(exercicioA)}&periodoA=${encodeURIComponent(periodoA)}&exercicioB=${encodeURIComponent(exercicioB)}&periodoB=${encodeURIComponent(periodoB)}`);
        clearAlert('#mensagensComparativo');
        state.comparativoData = data;
        renderComparativo(data.comparativo);
    } catch (error) {
        showAlert('#mensagensComparativo', `Erro: ${error.message}`);
    }
}

function exportarJSON() {
    if (!state.kpiData) {
        showAlert('#mensagens', 'Nenhum dado para exportar.');
        return;
    }

    const blob = new Blob([JSON.stringify(state.kpiData, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'dashboard-dados.json';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function handleMunicipioSearch(event) {
    const rawValue = event.target.value;
    const query = rawValue.trim();

    selectMunicipioFromInputValue(rawValue);

    clearTimeout(municipioSearchTimeout);
    municipioSearchTimeout = setTimeout(() => {
        loadMunicipios(query);
    }, 200);
}

function handleUfChange() {
    const searchInput = document.getElementById('municipioSearch');
    const query = searchInput ? searchInput.value.trim() : '';
    state.lastMunicipioQuery = null;
    loadMunicipios(query);
}

function selectMunicipioFromInputValue(value) {
    const municipioSelect = document.getElementById('municipioSelect');
    if (!municipioSelect) {
        return;
    }

    const normalized = normalizeText(value.trim());
    if (normalized && state.municipioLookup.has(normalized)) {
        municipioSelect.value = state.municipioLookup.get(normalized);
    } else if (municipioSelect.value) {
        municipioSelect.value = '';
    }
}

function handleMunicipioSelectChange() {
    syncMunicipioInputWithSelect();
}

document.addEventListener('DOMContentLoaded', async () => {
    try {
        await loadEstadosMunicipios();
    } catch (error) {
        showAlert('#mensagens', `Erro ao carregar listas: ${error.message}`);
    }

    document.getElementById('formBusca').addEventListener('submit', handleBuscar);
    document.getElementById('formComparativo').addEventListener('submit', handleComparar);
    document.getElementById('exportarBtn').addEventListener('click', exportarJSON);
    document.getElementById('ufSelect').addEventListener('change', handleUfChange);
    document.getElementById('municipioSearch').addEventListener('input', handleMunicipioSearch);
    document.getElementById('municipioSelect').addEventListener('change', handleMunicipioSelectChange);
});
