CREATE DATABASE IF NOT EXISTS ollama_demo
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ollama_demo;

DROP TABLE IF EXISTS ventas;

CREATE TABLE ventas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  region VARCHAR(80) NOT NULL,
  producto VARCHAR(120) NOT NULL,
  canal VARCHAR(80) NOT NULL,
  unidades INT NOT NULL,
  ingreso DECIMAL(12, 2) NOT NULL,
  satisfaccion TINYINT NOT NULL
);

INSERT INTO ventas (fecha, region, producto, canal, unidades, ingreso, satisfaccion) VALUES
('2026-01-04', 'San Salvador', 'Laptop Pro 14', 'Tienda', 4, 4800.00, 9),
('2026-01-08', 'Santa Ana', 'Monitor 27', 'Web', 7, 1890.00, 8),
('2026-01-12', 'San Miguel', 'Teclado Mecanico', 'Web', 18, 1440.00, 7),
('2026-01-18', 'San Salvador', 'Mouse Inalambrico', 'Distribuidor', 32, 960.00, 8),
('2026-02-03', 'La Libertad', 'Laptop Pro 14', 'Web', 3, 3600.00, 10),
('2026-02-10', 'Santa Ana', 'Dock USB-C', 'Tienda', 9, 1350.00, 8),
('2026-02-16', 'San Miguel', 'Monitor 27', 'Distribuidor', 5, 1350.00, 6),
('2026-03-02', 'San Salvador', 'Dock USB-C', 'Web', 11, 1650.00, 9),
('2026-03-08', 'La Libertad', 'Teclado Mecanico', 'Tienda', 14, 1120.00, 8),
('2026-03-15', 'Santa Ana', 'Mouse Inalambrico', 'Web', 27, 810.00, 7),
('2026-04-01', 'San Miguel', 'Laptop Pro 14', 'Tienda', 2, 2400.00, 9),
('2026-04-09', 'San Salvador', 'Monitor 27', 'Web', 9, 2430.00, 9);
