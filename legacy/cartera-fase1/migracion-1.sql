-- Migración 1: tabla kv para la sincronización OneDrive.
-- SOLO si ya habías importado el setup.sql anterior (sin tabla kv).
CREATE TABLE IF NOT EXISTS kv (
  k VARCHAR(100) PRIMARY KEY,
  v TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
