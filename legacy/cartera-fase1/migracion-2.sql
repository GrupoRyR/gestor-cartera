-- Migración 2: pagos + descuento financiero.
-- SOLO si ya habías importado un setup.sql anterior (sin tabla payments).
ALTER TABLE actions MODIFY tipo ENUM('llamada','correo','whatsapp','nota','documento','compromiso','pago','sistema') NOT NULL;
ALTER TABLE actions ADD COLUMN descuento DECIMAL(18,2) NULL AFTER compromiso_valor;

CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  empresa ENUM('SGX','SDC') NOT NULL,
  user_id INT NULL,
  fecha DATE NOT NULL,
  banco VARCHAR(80) DEFAULT '',
  referencia VARCHAR(80) DEFAULT '',
  total DECIMAL(18,2) DEFAULT 0,
  retefuente DECIMAL(18,2) DEFAULT 0,
  reteiva DECIMAL(18,2) DEFAULT 0,
  reteica DECIMAL(18,2) DEFAULT 0,
  descuento DECIMAL(18,2) DEFAULT 0,
  facturas TEXT,
  obs VARCHAR(500) DEFAULT '',
  exportado TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY k_cli (client_id),
  KEY k_emp_exp (empresa, exportado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
