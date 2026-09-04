-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: dbaws.automarco.cl
-- Generation Time: Sep 04, 2026 at 09:03 AM
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
-- Database: `autotec_ecom`
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
  `vend_cod` varchar(20) DEFAULT NULL COMMENT 'Código de vendedor (cli_vencod en tbl_clientes)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `web_usuarios`
--

INSERT INTO `web_usuarios` (`id`, `usuario`, `password`, `nombre`, `email`, `rol`, `activo`, `cli_rut`, `cli_sec`, `creado_en`, `ultimo_login`, `vend_cod`) VALUES
(37, 'autotec', 'cod2', 'JUAN CARLOS QUIROZ', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:00', '2026-07-22 15:27:40', '2'),
(38, 'autotec', 'cod3', 'JORGE CERON', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:00', '2026-07-14 16:29:23', '3'),
(39, 'autotec', 'cod6', 'CLAUDIA SAAVEDRA', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:00', '2026-07-22 18:26:17', '6'),
(40, 'autotec', 'cod7', 'PATRICIO VALENZUELA', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:00', NULL, '7'),
(41, 'autotec', 'cod8', 'RENATO SALGADO', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:00', NULL, '8'),
(42, 'autotec', 'cod9', 'PATRICIO OLAVE', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:00', '2026-07-23 12:48:21', '9'),
(43, 'autotec', 'cod10', 'IGNACIO CISTERNAS', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:00', NULL, '10'),
(44, 'autotec', 'cod15', 'RODRIGO ORELLANA', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:00', NULL, '15'),
(45, 'autotec', 'cod18', 'CLAUDIA OLEA', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:00', NULL, '18'),
(46, 'autotec', 'cod19', 'LAURA CONTRERAS', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:00', NULL, '19'),
(47, 'autotec', 'cod21', 'OSCAR AGUIRRE', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:00', NULL, '21'),
(48, 'autotec', 'cod24', 'CARLOS RUZ', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', NULL, '24'),
(49, 'autotec', 'cod25', 'ANGEL FEREIRA', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', '2026-09-04 08:51:03', '25'),
(50, 'autotec', 'cod26', 'PEDRO ASTARGO', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', NULL, '26'),
(51, 'autotec', 'cod32', 'CLAUDIO PARRAGUEZ', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', NULL, '32'),
(52, 'autotec', 'cod33', 'LUIS PATIÑO', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', NULL, '33'),
(53, 'autotec', 'cod34', 'OSCAR OLIVARES', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', '2026-08-24 12:26:43', '34'),
(54, 'autotec', 'cod44', 'JACOB CARTES', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', NULL, '44'),
(55, 'autotec', 'cod45', 'LUIS GONZALEZ', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', NULL, '45'),
(56, 'autotec', 'cod46', 'PATRICIO TOBAR', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', NULL, '46'),
(57, 'autotec', 'cod51', 'CALL CENTER', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', NULL, '51'),
(58, 'autotec', 'cod64', 'MARIO PUGA', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', NULL, '64'),
(59, 'autotec', 'cod67', 'JUAN GOMES', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', '2026-09-03 21:55:09', '67'),
(60, 'autotec', 'cod69', 'PEDRO TORRES', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', NULL, '69'),
(61, 'autotec', 'cod71', 'JORGE OLEA', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', '2026-08-31 11:08:11', '71'),
(62, 'autotec', 'cod77', 'RICHARD SEPULVEDA', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', NULL, '77'),
(63, 'autotec', 'cod85', 'GONZALO RODRIGUEZ', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', '2026-09-03 16:26:22', '85'),
(64, 'autotec', 'cod86', 'CESAR PIZARRO', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', '2026-09-03 10:24:20', '86'),
(65, 'autotec', 'cod87', 'JOSE MUÑOZ', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', '2026-07-14 17:03:28', '87'),
(66, 'autotec', 'cod99', 'VENTAS GERENCIA', NULL, 'vendedor', 1, NULL, NULL, '2026-07-14 16:24:01', NULL, '99'),
(67, 'admin', 'autotecadmin2024', 'Administrador', NULL, 'admin', 1, NULL, NULL, '2026-07-14 16:24:01', '2026-08-28 11:14:46', '99'),
(68, 'autotec', 'cod91', 'Katerine Parra', NULL, 'vendedor', 1, NULL, NULL, '2026-08-27 15:34:53', '2026-09-03 16:39:19', '91');

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
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
