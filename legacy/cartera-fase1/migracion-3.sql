-- Migración 3: conciliación bancaria.
-- SOLO si ya habías importado un setup.sql anterior (sin tabla bank_movs).
CREATE TABLE IF NOT EXISTS bank_movs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa ENUM('SGX','SDC') NOT NULL,
  banco VARCHAR(80) DEFAULT '',
  fecha DATE NULL,
  descripcion VARCHAR(300) DEFAULT '',
  referencia VARCHAR(120) DEFAULT '',
  valor DECIMAL(18,2) DEFAULT 0,
  hash CHAR(32) NOT NULL,
  estado ENUM('pendiente','conciliado','ignorado') NOT NULL DEFAULT 'pendiente',
  payment_id INT NULL,
  user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hash (empresa, hash),
  KEY k_emp_est (empresa, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
