-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jul 14, 2026 at 04:08 AM
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
-- Table structure for table `okr_statuses`
--

CREATE TABLE `okr_statuses` (
  `id` tinyint NOT NULL,
  `value` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `pays_incentive` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` tinyint NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `recycle` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `okr_statuses`
--

INSERT INTO `okr_statuses` (`id`, `value`, `description`, `pays_incentive`, `sort_order`, `created_at`, `updated_at`, `recycle`) VALUES
(1, 'Active', 'Not yet closed.', 0, 1, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(2, 'Complete', 'Delivered as expected.', 1, 2, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(3, 'Complete with Excellence', 'Delivered beyond expectation.', 1, 3, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(4, 'Extend', 'Timeline extended / still ongoing.', 0, 4, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(5, 'Suspended', 'Halted - CEO only.', 0, 5, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(6, 'Fail', 'Not delivered.', 0, 6, '2026-07-06 12:32:50', '2026-07-06 12:32:50', 0),
(7, 'Draft', 'Not yet started.', 0, 0, '2026-07-07 11:42:50', '2026-07-07 11:42:50', 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `okr_statuses`
--
ALTER TABLE `okr_statuses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_okr_statuses_value` (`value`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `okr_statuses`
--
ALTER TABLE `okr_statuses`
  MODIFY `id` tinyint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
