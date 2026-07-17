-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jul 14, 2026 at 04:06 AM
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
-- Table structure for table `okr_cards`
--

CREATE TABLE `okr_cards` (
  `id` int NOT NULL,
  `objective` text NOT NULL,
  `key_results` text NOT NULL,
  `okr_type` varchar(50) NOT NULL,
  `difficulty_level` tinyint NOT NULL,
  `owner_staff_id` int NOT NULL,
  `owner2_staff_id` int DEFAULT NULL,
  `owner2_purpose` varchar(255) DEFAULT NULL,
  `incentive_rule` tinyint NOT NULL DEFAULT '1',
  `incentivised_owner_staff_id` int DEFAULT NULL,
  `remarks` text,
  `issuer_staff_id` int NOT NULL,
  `dept_scope` varchar(255) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `extended` tinyint(1) NOT NULL DEFAULT '0',
  `extended_date` date DEFAULT NULL,
  `result_status` tinyint NOT NULL DEFAULT '1',
  `incentive_locked` tinyint(1) NOT NULL DEFAULT '0',
  `payout_remark` text,
  `locked_by` int DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `unlocked_by` int DEFAULT NULL,
  `unlocked_at` datetime DEFAULT NULL,
  `closed_by` int DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `okr_cards`
--
ALTER TABLE `okr_cards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_owner` (`owner_staff_id`),
  ADD KEY `idx_owner2` (`owner2_staff_id`),
  ADD KEY `idx_issuer` (`issuer_staff_id`),
  ADD KEY `idx_status` (`result_status`),
  ADD KEY `fk_okr_cards_level` (`difficulty_level`),
  ADD KEY `fk_okr_cards_incentive_rule` (`incentive_rule`),
  ADD KEY `fk_okr_cards_type` (`okr_type`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `okr_cards`
--
ALTER TABLE `okr_cards`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `okr_cards`
--
ALTER TABLE `okr_cards`
  ADD CONSTRAINT `fk_okr_cards_incentive_rule` FOREIGN KEY (`incentive_rule`) REFERENCES `okr_incentive_rules` (`id`),
  ADD CONSTRAINT `fk_okr_cards_level` FOREIGN KEY (`difficulty_level`) REFERENCES `okr_levels` (`level`),
  ADD CONSTRAINT `fk_okr_cards_status` FOREIGN KEY (`result_status`) REFERENCES `okr_statuses` (`id`),
  ADD CONSTRAINT `fk_okr_cards_type` FOREIGN KEY (`okr_type`) REFERENCES `okr_types` (`value`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
