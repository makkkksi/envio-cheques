-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: dbaws.automarco.cl
-- Generation Time: Sep 04, 2026 at 09:04 AM
-- Server version: 8.0.32
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `gabteccl_sitbdd1978`
--

-- --------------------------------------------------------

--
-- Table structure for table `web_usuarios`
--

CREATE TABLE `web_usuarios` (
  `id` int NOT NULL,
  `usuario` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nombre` varchar(200) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `rol` enum('admin','vendedor','cliente') DEFAULT 'cliente',
  `activo` tinyint(1) DEFAULT '1',
  `cli_rut` varchar(20) DEFAULT NULL,
  `cli_sec` varchar(10) DEFAULT NULL,
  `creado_en` datetime DEFAULT CURRENT_TIMESTAMP,
  `ultimo_login` datetime DEFAULT NULL,
  `vend_cod` varchar(20) DEFAULT NULL COMMENT 'Código de vendedor (cli_vencod en tbl_clientes)',
  `cla_ids_bloqueados` varchar(255) DEFAULT NULL COMMENT 'cla_id separados por coma que este usuario NO puede ver (ej: 12,13,14,15). NULL = sin bloqueos',
  `cla_ids_permitidos` varchar(255) DEFAULT NULL COMMENT 'si no es NULL, el usuario SOLO puede ver estos cla_id (tiene prioridad sobre bloqueados). NULL = ve todas',
  `filtro_cliente_campo` varchar(20) NOT NULL DEFAULT 'CLIVENCOD' COMMENT 'columna de bd_automarco.tbl_clientes usada para filtrar "mis clientes": CLIVENCOD o CLIFAX'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `web_usuarios`
--

INSERT INTO `web_usuarios` (`id`, `usuario`, `password`, `nombre`, `email`, `rol`, `activo`, `cli_rut`, `cli_sec`, `creado_en`, `ultimo_login`, `vend_cod`, `cla_ids_bloqueados`, `cla_ids_permitidos`, `filtro_cliente_campo`) VALUES
(1, 'gabtec', 'cod1', 'ANGEL FEREIRA', 'afereira@gabtec.cl', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', NULL, '1', NULL, NULL, 'CLIVENCOD'),
(2, 'gabtec', 'cod5', 'NICOLE RAMOS', 'nramos@automarco.com', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', '2026-07-29 16:42:13', '5', NULL, NULL, 'CLIVENCOD'),
(3, 'gabtec', 'cod7', 'JUAN VALDES', 'jvaldes@gabtec.cl', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', NULL, '7', NULL, NULL, 'CLIVENCOD'),
(4, 'gabtec', 'cod14', 'RODRIGO POBLETE', 'rpoblete@automarco.com', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', NULL, '14', NULL, NULL, 'CLIVENCOD'),
(5, 'gabtec', 'cod15', 'PATRICIO VALENZUELA', 'pvalenzuela@automarco.com', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', NULL, '15', NULL, NULL, 'CLIVENCOD'),
(6, 'gabtec', 'cod32', 'CLAUDIO PARRAGUEZ', 'cparraguez@automarco.com', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', NULL, '32', '12,13,14,15', NULL, 'CLIVENCOD'),
(7, 'gabtec', 'cod70', 'ROSA SILVA', 'rsilva@automarco.com', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', '2026-08-27 08:54:02', '70', NULL, NULL, 'CLIVENCOD'),
(8, 'gabtec', 'cod71', 'JORGE OLEA', 'jolea@automarco.com', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', NULL, '71', NULL, NULL, 'CLIVENCOD'),
(9, 'gabtec', 'cod72', 'RICARDO GUTIERREZ', 'rgutierrez@gabtec.cl', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', '2026-08-17 08:39:17', '72', NULL, NULL, 'CLIVENCOD'),
(10, 'gabtec', 'cod76', 'JOSE LUIS AGUILERA', 'jlaguilera@gabtec.cl', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', NULL, '76', NULL, NULL, 'CLIVENCOD'),
(11, 'gabtec', 'cod78', 'JACOB CARTES', 'jcbgabtec@gmail.com', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', '2026-08-17 11:17:54', '78', '12,13,14,15', NULL, 'CLIVENCOD'),
(12, 'gabtec', 'cod79', 'CLAUDIA OLEA', 'colea@gabtec.cl', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', NULL, '79', NULL, NULL, 'CLIVENCOD'),
(13, 'gabtec', 'cod81', 'CRISTIAN GUIÑEZ', 'comercialpuren@gmail.com', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', NULL, '81', NULL, NULL, 'CLIVENCOD'),
(14, 'gabtec', 'cod83', 'DAVID CARTES', 'D_cartes@hotmail.com', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', '2026-09-02 09:34:10', '83', '12,13,14,15', NULL, 'CLIVENCOD'),
(15, 'gabtec', 'cod85', 'GONZALO RODRIGUEZ', 'grodriguez@automarco.com', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', '2026-08-12 13:44:15', '85', NULL, NULL, 'CLIVENCOD'),
(16, 'gabtec', 'cod86', 'CESAR PIZARRO', 'cpizarro@automarco.com', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', '2026-08-19 11:00:22', '86', NULL, NULL, 'CLIVENCOD'),
(17, 'gabtec', 'cod87', 'JOSE MUÑOZ', 'jdmunoz@automarco.com', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', NULL, '87', NULL, NULL, 'CLIVENCOD'),
(18, 'gabtec', 'cod88', 'PRISCILA MUNIZAGA', 'pmunizaga@automarco.com', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', NULL, '88', NULL, NULL, 'CLIVENCOD'),
(19, 'gabtec', 'cod90', 'VENTAS GERENCIA', 'afereira@gabtec.cl', 'vendedor', 1, NULL, NULL, '2026-07-28 16:34:13', NULL, '90', NULL, NULL, 'CLIVENCOD'),
(20, 'admin', 'gabtecadmin2026', 'Administrador Gabtec', NULL, 'admin', 1, NULL, NULL, '2026-08-13 17:17:49', '2026-09-02 13:42:38', '99', NULL, NULL, 'CLIVENCOD'),
(21, 'gabtec', 'cod4', 'JUAN GOMES', 'jgomes@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 11:12:20', '2026-08-17 14:20:29', '4', NULL, '12,13,14,15,16', 'CLIFAX');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `web_usuarios`
--
ALTER TABLE `web_usuarios`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `web_usuarios`
--
ALTER TABLE `web_usuarios`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
