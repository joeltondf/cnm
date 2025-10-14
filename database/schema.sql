-- Schema for dashboard_financeiro database.
CREATE TABLE IF NOT EXISTS entes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cod_ibge VARCHAR(10) NOT NULL UNIQUE,
    ente VARCHAR(255) NOT NULL,
    uf CHAR(2) NOT NULL,
    INDEX idx_entes_uf (uf)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rreo_dados (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cod_ibge VARCHAR(10) NOT NULL,
    exercicio INT NOT NULL,
    periodo TINYINT NOT NULL,
    conta VARCHAR(255) NOT NULL,
    coluna VARCHAR(255) NOT NULL,
    valor DECIMAL(20,2) NOT NULL,
    CONSTRAINT fk_rreo_entes FOREIGN KEY (cod_ibge) REFERENCES entes(cod_ibge),
    INDEX idx_consulta (cod_ibge, exercicio, periodo),
    INDEX idx_conta_coluna (conta, coluna)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS estados (
    uf CHAR(2) PRIMARY KEY,
    nome VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
