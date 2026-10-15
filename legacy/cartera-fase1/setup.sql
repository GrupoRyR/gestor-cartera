-- Cartera R&R — esquema inicial (MySQL / MariaDB, cPanel phpMyAdmin)
-- Importar en la base de datos vacía creada para la app.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  pass_hash VARCHAR(255) NOT NULL,
  rol ENUM('admin','gestor') NOT NULL DEFAULT 'gestor',
  empresa ENUM('SGX','SDC','ALL') NOT NULL DEFAULT 'ALL',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa ENUM('SGX','SDC') NOT NULL,
  nit VARCHAR(30) NOT NULL,
  nombre VARCHAR(200) NOT NULL,
  grupo VARCHAR(150) DEFAULT '',
  municipio VARCHAR(120) DEFAULT '',
  limite_credito DECIMAL(18,2) DEFAULT 0,
  email VARCHAR(200) DEFAULT '',
  telefono VARCHAR(60) DEFAULT '',
  contacto VARCHAR(150) DEFAULT '',
  gestor_id INT NULL,
  modo_correo ENUM('manual','auto') NOT NULL DEFAULT 'manual',
  estado VARCHAR(20) NOT NULL DEFAULT 'al_dia',
  estado_manual TINYINT(1) NOT NULL DEFAULT 0,
  notas TEXT,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cli (empresa, nit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS uploads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa ENUM('SGX','SDC') NOT NULL,
  fecha_corte DATE NOT NULL,
  filename VARCHAR(255) DEFAULT '',
  user_id INT NULL,
  num_facturas INT DEFAULT 0,
  total_saldo DECIMAL(18,2) DEFAULT 0,
  total_vencido DECIMAL(18,2) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa ENUM('SGX','SDC') NOT NULL,
  client_id INT NOT NULL,
  upload_id INT NOT NULL,
  codigo_sn VARCHAR(30) DEFAULT '',
  documento VARCHAR(40) NOT NULL,
  referencia VARCHAR(100) DEFAULT '',
  vendedor VARCHAR(150) DEFAULT '',
  condicion_pago VARCHAR(60) DEFAULT '',
  fecha_doc DATE NULL,
  fecha_venc DATE NULL,
  importe_original DECIMAL(18,2) DEFAULT 0,
  saldo DECIMAL(18,2) DEFAULT 0,
  dias INT DEFAULT 0,
  activa TINYINT(1) NOT NULL DEFAULT 1,
  KEY k_cli (client_id),
  KEY k_up (upload_id),
  KEY k_emp_act (empresa, activa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS kv (
  k VARCHAR(100) PRIMARY KEY,
  v TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS actions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  empresa ENUM('SGX','SDC') NOT NULL,
  user_id INT NULL,
  tipo ENUM('llamada','correo','whatsapp','nota','documento','compromiso','pago','sistema') NOT NULL,
  resultado VARCHAR(150) DEFAULT '',
  detalle TEXT,
  compromiso_fecha DATE NULL,
  compromiso_valor DECIMAL(18,2) NULL,
  descuento DECIMAL(18,2) NULL,
  adjunto VARCHAR(255) DEFAULT '',
  adjunto_nombre VARCHAR(255) DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY k_cli (client_id),
  KEY k_emp (empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
