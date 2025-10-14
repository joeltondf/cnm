<?php
// Configurações globais do projeto
const DB_HOST = 'localhost';
const DB_NAME = 'siconfi_dashboard';
const DB_USER = 'root';
const DB_PASS = '';

const API_BASE_URL = 'https://apidatalake.tesouro.gov.br/ords/siconfi/tt/';
const API_RREO_ENDPOINT = 'rreo';

// Cache em horas (dados mais antigos do que este limite serão atualizados)
const CACHE_TTL_HOURS = 24;
// Intervalo mínimo entre chamadas (em microssegundos) para respeitar 1 req/s
const API_RATE_LIMIT_USEC = 1_100_000;
