-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: dbaws.automarco.cl
-- Generation Time: Sep 04, 2026 at 09:02 AM
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
-- Database: `automarc_automarco`
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
(1, 'admin', 'adminautomarco2026', 'Administrador Automarco', 'admin@automarco.com', 'admin', 1, NULL, NULL, '2026-08-17 16:03:49', '2026-08-31 17:19:37', '99'),
(2, 'automarco', 'cod1', 'JORGE CERON', 'rari2511@yahoo.es', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '1'),
(3, 'automarco', 'cod3', 'RENATO SALGADO', 'salgado1482@gmail.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '3'),
(4, 'automarco', 'cod4', 'LUIS SEPULVEDA', 'lsepulveda@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', '2026-08-18 10:51:12', '4'),
(5, 'automarco', 'cod6', 'MARCIAL CAMPOS', 'mcampos@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', '2026-08-17 16:13:43', '6'),
(6, 'automarco', 'cod7', 'CLAUDIO GODOY', 'cgodoy@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '7'),
(7, 'automarco', 'cod8', 'SERGIO CASTILLO', 'scastillo@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', '2026-08-19 14:08:47', '8'),
(8, 'automarco', 'cod9', 'RAUL FUENTES', 'rfuentes@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', '2026-08-20 14:28:29', '9'),
(9, 'automarco', 'cod10', 'MAXIMINO CACERES', 'mcaceres@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '10'),
(10, 'automarco', 'cod13', 'CARLOS TRONCOSO', 'carlostroncoso13@hotmail.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '13'),
(11, 'automarco', 'cod15', 'PATRICIO VALENZUELA', 'pvalenzuela@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '15'),
(12, 'automarco', 'cod16', 'ALEJANDRO ROJAS', 'salomonrojascordova@yahoo.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '16'),
(13, 'automarco', 'cod18', 'MARCELINO ARELLANO', 'marellano@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '18'),
(14, 'automarco', 'cod19', 'LAURA CONTRERAS', 'lcontrerasmena@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '19'),
(15, 'automarco', 'cod20', 'PATRICIO OLAVE', 'polave@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '20'),
(16, 'automarco', 'cod21', 'OSCAR AGUIRRE', 'oaguirre@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '21'),
(17, 'automarco', 'cod23', 'NEMROD ZUÑIGA', 'nzuniga@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '23'),
(18, 'automarco', 'cod24', 'CARLOS RUZ', 'cruz@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '24'),
(19, 'automarco', 'cod25', 'ANGEL FEREIRA', 'afereira@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '25'),
(20, 'automarco', 'cod26', 'PEDRO ASTARGO', 'pastargo@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '26'),
(21, 'automarco', 'cod29', 'FELIPE SALGADO', 'fsalgado@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '29'),
(22, 'automarco', 'cod31', 'MIGUEL FUENZALIDA', 'mfuenzalida@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '31'),
(23, 'automarco', 'cod34', 'JAVIER MUÑOZ', 'jmunoz@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '34'),
(24, 'automarco', 'cod38', 'RODRIGO POBLETE', 'rpoblete@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '38'),
(25, 'automarco', 'cod39', 'ANDRES MURA', 'amura@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '39'),
(26, 'automarco', 'cod46', 'PATRICIO TOBAR', 'ptobar@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '46'),
(27, 'automarco', 'cod64', 'MARIO PUGA', 'mpuga@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', '2026-08-17 16:13:03', '64'),
(28, 'automarco', 'cod70', 'PEDRO TORRES', 'ptorres@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '70'),
(29, 'automarco', 'cod71', 'JORGE OLEA', 'jolea@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '71'),
(30, 'automarco', 'cod85', 'GONZALO RODRIGUEZ', 'grodriguez@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '85'),
(31, 'automarco', 'cod86', 'CESAR PIZARRO', 'cpizarro@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', '2026-08-28 09:18:32', '86'),
(32, 'automarco', 'cod87', 'JOSE MUÑOZ', 'jdmunoz@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', '2026-08-21 12:17:51', '87'),
(33, 'automarco', 'cod88', 'PRISCILA MUNIZAGA', 'pmunizaga@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '88'),
(34, 'automarco', 'cod99', 'VENTAS GERENCIA', 'rarenas@automarco.com', 'vendedor', 1, NULL, NULL, '2026-08-17 16:03:49', NULL, '99');

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
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
