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
-- Table structure for table `okr_incentive_rules`
--

CREATE TABLE `okr_incentive_rules` (
  `id` tinyint NOT NULL,
  `code` varchar(20) NOT NULL,
  `label` varchar(255) NOT NULL,
  `payout_logic` varchar(255) NOT NULL,
  `recycle` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `okr_incentive_rules`
--

INSERT INTO `okr_incentive_rules` (`id`, `code`, `label`, `payout_logic`, `recycle`) VALUES
(1, 'RULE1', 'Rule 1 - A1 100% incentivised', 'One owner is incentivised at 100%. With two owners, the issuer picks which one is incentivised.', 0),
(2, 'RULE2', 'Rule 2 - A1 50%, A2 50% incentivised', 'Both owners are incentivised, 50% each. Requires two owners.', 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `okr_incentive_rules`
--
ALTER TABLE `okr_incentive_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_okr_incentive_rules_code` (`code`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `okr_incentive_rules`
--
ALTER TABLE `okr_incentive_rules`
  MODIFY `id` tinyint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
