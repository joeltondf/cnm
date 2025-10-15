<?php
// Configurações globais do projeto
const DB_HOST = 'localhost';
const DB_NAME = 'u371107598_cnm';
const DB_USER = 'u371107598_usercnm';
const DB_PASS = '@Amora051307';

const API_BASE_URL = 'https://apidatalake.tesouro.gov.br/ords/siconfi/tt/';
const API_RREO_ENDPOINT = 'rreo';
const API_PAGE_LIMIT = 1000; // Quantidade de registros por página em cada chamada
const API_MAX_PAGES = 50; // Limite de páginas a serem percorridas por consulta
const API_DEFAULT_PERIODICIDADE = 'B'; // Periodicidade padrão (B = bimestral)

// Cabeçalhos padrão enviados para a API do SICONFI
const API_HEADERS = [
    'Accept: application/json',
];

// User-Agent identificado junto à API. Pode ser alterado conforme necessário.
const API_USER_AGENT = 'CNM-Dashboard/1.0';

// Caso a API exija autorização (Bearer, Basic etc.), informe o valor completo aqui.
const API_AUTHORIZATION = '';

// Parâmetros extras para todas as requisições (por exemplo, appkey, formato, etc.)
const API_ADDITIONAL_QUERY = [];

// Cache em horas (dados mais antigos do que este limite serão atualizados)
const CACHE_TTL_HOURS = 24;
// Intervalo mínimo entre chamadas (em microssegundos) para respeitar 1 req/s
const API_RATE_LIMIT_USEC = 1_100_000;
