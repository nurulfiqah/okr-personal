-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jul 14, 2026 at 04:07 AM
-- Server version: 8.4.3
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `odb`
--

-- --------------------------------------------------------

--
-- Table structure for table `okr_levels`
--

CREATE TABLE `okr_levels` (
  `level` tinyint NOT NULL,
  `label` varchar(50) NOT NULL,
  `rubric_text` text,
  `base_rm` decimal(10,2) NOT NULL DEFAULT '0.00',
  `recycle` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `okr_levels`
--

INSERT INTO `okr_levels` (`level`, `label`, `rubric_text`, `base_rm`, `recycle`) VALUES
(1, 'Level 1 - Easiest', 'Low difficulty & complexity.', 0.00, 0),
(2, 'Level 2 - Medium-low', 'Medium-low difficulty & complexity.', 500.00, 0),
(3, 'Level 3 - Medium-high', 'Medium-high difficulty & complexity.', 1000.00, 0),
(4, 'Level 4 - Highest', 'High difficulty & complexity.', 2000.00, 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `okr_levels`
--
ALTER TABLE `okr_levels`
  ADD PRIMARY KEY (`level`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
