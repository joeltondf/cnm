CREATE TABLE IF NOT EXISTS api_cache (
    cache_key CHAR(40) NOT NULL PRIMARY KEY,
    fetched_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS municipios (
    id_ibge INT NOT NULL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    uf_sigla CHAR(2) NOT NULL,
    uf_nome VARCHAR(255) NOT NULL,
    regiao_sigla CHAR(2) NOT NULL,
    regiao_nome VARCHAR(255) NOT NULL,
    microrregiao VARCHAR(255) NULL,
    mesorregiao VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_municipios_uf (uf_sigla, nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rreo_registros (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_ente VARCHAR(10) NOT NULL,
    no_ente VARCHAR(255) NULL,
    sg_uf CHAR(2) NULL,
    an_exercicio INT NOT NULL,
    nr_periodo INT NOT NULL,
    co_tipo_demonstrativo VARCHAR(5) NOT NULL,
    no_anexo VARCHAR(255) NULL,
    co_esfera CHAR(1) NULL,
    cd_conta VARCHAR(50) NULL,
    ds_conta VARCHAR(255) NULL,
    vl_previsto DECIMAL(18,2) NULL,
    vl_atualizado DECIMAL(18,2) NULL,
    vl_realizado DECIMAL(18,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_registro (id_ente, an_exercicio, nr_periodo, co_tipo_demonstrativo, cd_conta, no_anexo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_rreo_periodo ON rreo_registros (id_ente, an_exercicio, nr_periodo, co_tipo_demonstrativo);
