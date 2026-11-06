-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 05, 2026 at 11:23 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ala_eh`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `email` varchar(254) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `email`, `password_hash`, `created_at`) VALUES
(1, 'luvschuett@gmail.com', '$2y$10$irkPRQkYvRUufSZA5cooZOZc/tHLxGcaQtLqcRs8wd5N9Qph4jfYS', '2026-11-01 06:56:48');

-- --------------------------------------------------------

--
-- Table structure for table `admin_registration_lock`
--

CREATE TABLE `admin_registration_lock` (
  `id` tinyint(3) UNSIGNED NOT NULL
) ;

--
-- Dumping data for table `admin_registration_lock`
--

INSERT INTO `admin_registration_lock` (`id`) VALUES
(1);

-- --------------------------------------------------------

--
-- Table structure for table `drivers`
--

CREATE TABLE `drivers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(254) NOT NULL,
  `phone` varchar(32) NOT NULL,
  `license_number` varchar(64) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `verification_status` enum('pending_verification','verified','rejected') NOT NULL DEFAULT 'pending_verification',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `drivers`
--

INSERT INTO `drivers` (`id`, `full_name`, `email`, `phone`, `license_number`, `password_hash`, `verification_status`, `created_at`) VALUES
(2, 'luvschuett', 'luvschuett@gmail.com', '09910089263', 'D01-26-000123', '$2y$10$5vgAizyO93GvwNY00wzkKeI7SchQYIG7WbRCLO811t3u2kYznNtM.', 'verified', '2026-11-01 06:48:10');

-- --------------------------------------------------------

--
-- Table structure for table `login_rate_limits`
--

CREATE TABLE `login_rate_limits` (
  `bucket_key` char(64) NOT NULL,
  `attempts` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `window_started_at` datetime NOT NULL,
  `blocked_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `login_rate_limits`
--

INSERT INTO `login_rate_limits` (`bucket_key`, `attempts`, `window_started_at`, `blocked_until`) VALUES
('9167c53a73585c85291753ba5ee92409ff073635f4f3375cd7cc293382527085', 5, '2026-11-01 06:08:52', NULL),
('b4c992d1534cecf1563735e678171fa2827c1b2e1fe4fcf406b3a08dd4d302f9', 5, '2026-11-01 06:08:52', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_admins_email` (`email`);

--
-- Indexes for table `admin_registration_lock`
--
ALTER TABLE `admin_registration_lock`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `drivers`
--
ALTER TABLE `drivers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_drivers_email` (`email`),
  ADD UNIQUE KEY `uq_drivers_license_number` (`license_number`);

--
-- Indexes for table `login_rate_limits`
--
ALTER TABLE `login_rate_limits`
  ADD PRIMARY KEY (`bucket_key`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `drivers`
--
ALTER TABLE `drivers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
