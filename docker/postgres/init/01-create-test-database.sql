-- Banco isolado para a suíte Pest (as migrations usam recursos específicos do PostgreSQL:
-- jsonb, índices parciais e triggers de append-only).
CREATE DATABASE homologa_test OWNER homologa;
