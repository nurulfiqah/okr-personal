-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
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
-- Table structure for table `okr_key_results`
--

CREATE TABLE `okr_key_results` (
  `id` int NOT NULL,
  `card_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `description` text NOT NULL,
  `assignee_staff_id` int DEFAULT NULL,
  `atem_id` int DEFAULT NULL,
  `status_id` tinyint NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `okr_key_results`
--
ALTER TABLE `okr_key_results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card` (`card_id`),
  ADD KEY `idx_parent` (`parent_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `okr_key_results`
--
ALTER TABLE `okr_key_results`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `okr_key_results`
--
ALTER TABLE `okr_key_results`
  ADD CONSTRAINT `fk_okr_kr_card` FOREIGN KEY (`card_id`) REFERENCES `okr_cards` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_okr_kr_parent` FOREIGN KEY (`parent_id`) REFERENCES `okr_key_results` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_okr_kr_status` FOREIGN KEY (`status_id`) REFERENCES `okr_statuses` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
