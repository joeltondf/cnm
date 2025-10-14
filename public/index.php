<?php
require_once __DIR__ . '/../templates/header.php';
?>
<section class="mb-5">
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <form id="formBusca" class="row g-3 align-items-end">
                <div class="col-12 col-md-3">
                    <label for="ufSelect" class="form-label">Filtrar por UF</label>
                    <select id="ufSelect" class="form-select"></select>
                </div>
                <div class="col-12 col-md-4">
                    <label for="municipioSearch" class="form-label">Buscar município</label>
                    <input type="text" id="municipioSearch" class="form-control mb-2" placeholder="Digite para buscar" autocomplete="off" list="municipioSuggestions">
                    <datalist id="municipioSuggestions"></datalist>
                    <label for="municipioSelect" class="form-label mt-2">Município / Consolidados</label>
                    <select id="municipioSelect" class="form-select" required></select>
                </div>
                <div class="col-12 col-md-2">
                    <label for="exercicioInput" class="form-label">Exercício</label>
                    <input type="number" id="exercicioInput" class="form-control" min="2000" max="2100" required>
                </div>
                <div class="col-12 col-md-1">
                    <label for="periodoInput" class="form-label">Período</label>
                    <input type="number" id="periodoInput" class="form-control" min="1" max="6" required>
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button class="btn btn-primary" type="submit">
                        <i class="fa-solid fa-magnifying-glass me-2"></i>Buscar
                    </button>
                </div>
            </form>
            <div id="mensagens" class="mt-3"></div>
        </div>
    </div>
</section>

<section class="mb-5">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between mb-3">
        <div>
            <h2 class="h4 mb-1" id="tituloMunicipio">Selecione um município</h2>
            <p class="text-muted mb-0" id="tituloPeriodo">Período não selecionado</p>
        </div>
        <button id="exportarBtn" class="btn btn-outline-primary">
            <i class="fa-solid fa-file-export me-2"></i>Exportar JSON
        </button>
    </div>
    <div class="row" id="kpiContainer">
        <div class="col-12">
            <div class="alert alert-info" role="alert">
                Informe os filtros para visualizar os indicadores.
            </div>
        </div>
    </div>
</section>

<section class="mb-5">
    <div class="row g-4">
        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h3 class="h6 mb-0">Resumo</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="resumoChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h3 class="h6 mb-0">Receitas</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="receitasDetalheChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h3 class="h6 mb-0">Despesas</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="despesasDetalheChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="mb-5">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
            <div>
                <h3 class="h6 mb-1">Histórico por Conta</h3>
                <p class="mb-0 text-muted small">Valores acumulados até o bimestre por categoria</p>
            </div>
        </div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="historicoChart"></canvas>
            </div>
        </div>
    </div>
</section>

<section class="mb-5">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form id="formComparativo" class="row g-3 align-items-end">
                <div class="col-12 col-md-3">
                    <label for="exercicioA" class="form-label">Exercício A</label>
                    <input type="number" id="exercicioA" class="form-control" min="2000" max="2100" required>
                </div>
                <div class="col-12 col-md-3">
                    <label for="periodoA" class="form-label">Período A</label>
                    <input type="number" id="periodoA" class="form-control" min="1" max="6" required>
                </div>
                <div class="col-12 col-md-3">
                    <label for="exercicioB" class="form-label">Exercício B</label>
                    <input type="number" id="exercicioB" class="form-control" min="2000" max="2100" required>
                </div>
                <div class="col-12 col-md-3">
                    <label for="periodoB" class="form-label">Período B</label>
                    <input type="number" id="periodoB" class="form-control" min="1" max="6" required>
                </div>
                <div class="col-12 d-grid">
                    <button class="btn btn-outline-secondary" type="submit">
                        <i class="fa-solid fa-code-compare me-2"></i>Comparar
                    </button>
                </div>
            </form>
            <div id="mensagensComparativo" class="mt-3"></div>
            <div class="table-responsive mt-3">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Categoria</th>
                            <th>Período A</th>
                            <th>Período B</th>
                            <th>Variação (%)</th>
                        </tr>
                    </thead>
                    <tbody id="comparativoResultado">
                        <tr>
                            <td colspan="4" class="text-center text-muted">Selecione os períodos para visualizar o comparativo.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<?php
require_once __DIR__ . '/../templates/footer.php';
