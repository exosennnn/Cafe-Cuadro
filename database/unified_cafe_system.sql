-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 02, 2026 at 02:41 AM
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
-- Database: `unified_cafe_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `module` varchar(50) DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`log_id`, `user_id`, `module`, `action`, `created_at`) VALUES
(1, 7, 'Procurement', 'Saved supplier: Wil', '2026-10-01 21:10:39'),
(3, 7, 'Procurement', 'Saved supplier: Wil', '2026-10-01 22:32:47'),
(4, 7, 'Procurement', 'Raised low-stock PR PR-2026-0001 for Arabica Coffee Beans', '2026-10-01 23:03:41'),
(5, 7, 'Procurement', 'Created purchase order: PO-2026-0001', '2026-10-01 23:04:41'),
(6, 7, 'Procurement', 'Created purchase order: PO-2026-0002', '2026-10-01 23:10:01'),
(7, 1, 'Procurement', 'Approve PO: PO-2026-0002', '2026-10-01 23:10:42'),
(8, 1, 'Procurement', 'Approve PO: PO-2026-0001', '2026-10-01 23:10:50'),
(9, 7, 'Procurement', 'Converted PR PR-2026-0001 to PO PO-2026-0003', '2026-10-01 23:11:49'),
(10, 7, 'Procurement', 'Order PO: PO-2026-0002', '2026-10-01 23:12:42'),
(11, 7, 'Procurement', 'Order PO: PO-2026-0001', '2026-10-01 23:45:02'),
(12, 7, 'Procurement', 'Received delivery GRN-2026-0001 for PO PO-2026-0001 (payable ₱8,500.00 pending Finance payment)', '2026-10-01 23:45:20'),
(13, 7, 'Inventory', 'Updated item: Arabica Coffee Beans', '2026-10-01 23:51:47'),
(14, 7, 'Inventory', 'Updated item: Fresh Milk', '2026-10-01 23:51:51'),
(15, 7, 'Inventory', 'Updated item: Fresh Milk', '2026-10-01 23:51:58'),
(16, 7, 'Inventory', 'Added item: Coffee beans 18g', '2026-10-01 23:57:51'),
(17, 7, 'Inventory', 'Added item: Chocolate Syrup', '2026-10-01 23:59:12'),
(18, 7, 'Inventory', 'Updated item: Fresh Milk', '2026-10-02 00:00:36'),
(19, 7, 'Procurement', 'Created purchase order: PO-2026-0004', '2026-10-02 00:02:27'),
(20, 1, 'Procurement', 'Approve PO: PO-2026-0004', '2026-10-02 00:04:07'),
(21, 7, 'Procurement', 'Order PO: PO-2026-0004', '2026-10-02 00:04:53'),
(22, 7, 'Procurement', 'Received delivery GRN-2026-0002 for PO PO-2026-0004 (payable ₱440.00 pending Finance payment)', '2026-10-02 00:05:04'),
(23, 7, 'Inventory', 'Added item: Coffee beans', '2026-10-02 00:08:33'),
(24, 7, 'Inventory', 'Updated item: Arabica Coffee Beans', '2026-10-02 03:12:32'),
(25, 7, 'Inventory', 'Set recipe line for product #4: item #5 = 1.2 per unit', '2026-10-02 03:30:54'),
(26, 7, 'Inventory', 'Set recipe line for product #4: item #2 = 0.18 per unit', '2026-10-02 03:31:11');

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `announcement_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `content` text NOT NULL,
  `posted_by` int(11) NOT NULL,
  `target_department` int(11) DEFAULT NULL,
  `posted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `applicants`
--

CREATE TABLE `applicants` (
  `applicant_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `gender` enum('MALE','FEMALE','OTHER') DEFAULT NULL,
  `resume_path` varchar(255) DEFAULT NULL,
  `resume_original_name` varchar(255) DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE','HIRED','REJECTED') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `applicants`
--

INSERT INTO `applicants` (`applicant_id`, `user_id`, `first_name`, `last_name`, `email`, `phone`, `address`, `birthdate`, `gender`, `resume_path`, `resume_original_name`, `status`, `created_at`, `updated_at`) VALUES
(1, NULL, 'Jr', 'Dsantos', 'jennyrosedelossanto73@gmail.com', '09315465321', NULL, NULL, NULL, '45308a8b67cdf69bf902dc75bcb68cab.pdf', 'Final-Project-Integrative-Programming-2.pdf', 'ACTIVE', '2026-09-28 10:48:47', '2026-09-28 10:48:47'),
(2, NULL, 'lala', 'haha', 'lala@cafe.test', '09215646511', NULL, NULL, NULL, 'e3f44974edc0dc47c3aadad83d5af5f6.docx', 'front page.docx', 'ACTIVE', '2026-10-01 13:03:54', '2026-10-01 13:03:54');

-- --------------------------------------------------------

--
-- Table structure for table `application_documents`
--

CREATE TABLE `application_documents` (
  `document_id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `document_type` varchar(100) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `attendance_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `status` enum('PRESENT','LATE','ABSENT','HALF_DAY','ON_LEAVE') NOT NULL DEFAULT 'PRESENT',
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_deductions`
--

CREATE TABLE `attendance_deductions` (
  `deduction_id` int(11) NOT NULL,
  `attendance_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `included_in_payroll_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(150) NOT NULL,
  `module` varchar(100) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`log_id`, `user_id`, `action`, `module`, `details`, `ip_address`, `created_at`) VALUES
(1, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: empmanager@cafe.tes', '::1', '2026-09-07 10:38:48'),
(2, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: empmanager@cafe.tet', '::1', '2026-09-07 10:39:00'),
(3, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: empmanager@cafe.test', '::1', '2026-09-07 10:39:03'),
(4, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: empmanager@cafe.test', '::1', '2026-09-07 10:39:08'),
(5, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: guenn@gmail.com', '::1', '2026-09-07 10:39:15'),
(6, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: joemartin@gmail.com', '::1', '2026-09-07 10:39:27'),
(7, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: hrstaff@cafe.test', '::1', '2026-09-07 10:40:25'),
(8, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-07 10:43:34'),
(9, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-07 10:43:35'),
(10, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-07 10:44:11'),
(11, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: empmanager@cafe.test', '::1', '2026-09-07 10:44:16'),
(12, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: empmanager@cafe.test', '::1', '2026-09-07 10:44:23'),
(13, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: empmanager@cafe.test', '::1', '2026-09-07 10:44:25'),
(14, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: empmanager@cafe.test', '::1', '2026-09-07 10:44:30'),
(15, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: empmanager@cafe.test', '::1', '2026-09-07 10:44:35'),
(16, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: invstaff1@cafe.test', '::1', '2026-09-07 10:45:02'),
(17, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: invstaff1@cafe.test', '::1', '2026-09-07 10:45:05'),
(18, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: invstaff1@cafe.test Admin123!', '::1', '2026-09-07 10:45:15'),
(19, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-07 10:50:37'),
(20, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-07 10:52:24'),
(21, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-07 10:52:39'),
(22, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-07 19:38:18'),
(23, 1, 'LOGOUT', 'Auth', '', '::1', '2026-09-07 20:15:14'),
(24, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: hrmanager@cafe.test', '::1', '2026-09-07 20:15:27'),
(25, 6, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-07 20:17:32'),
(26, 6, 'LOGOUT', 'Auth', '', '::1', '2026-09-07 21:50:48'),
(28, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: carol@gmail.com', '::1', '2026-09-09 01:28:02'),
(29, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-09 01:30:01'),
(30, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-09 01:30:18'),
(31, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-09 01:30:26'),
(32, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-09 01:30:43'),
(33, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-09 01:31:05'),
(34, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-11 23:38:35'),
(35, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-11 23:38:40'),
(36, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-11 23:38:49'),
(37, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-11 23:38:54'),
(38, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-11 23:39:14'),
(39, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-11 23:39:41'),
(40, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-11 23:39:42'),
(41, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-11 23:39:43'),
(42, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-11 23:39:43'),
(43, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-11 23:39:43'),
(44, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-11 23:39:44'),
(45, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-11 23:39:44'),
(46, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-11 23:39:45'),
(47, 2, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-11 23:43:58'),
(48, 2, 'LOGOUT', 'Auth', '', '::1', '2026-09-11 23:53:21'),
(49, 7, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-11 23:53:43'),
(50, 2, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-12 02:11:59'),
(51, 2, 'LOGOUT', 'Auth', '', '::1', '2026-09-12 02:12:08'),
(52, 7, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-12 02:12:18'),
(53, 7, 'LOGOUT', 'Auth', '', '::1', '2026-09-12 02:24:58'),
(54, 6, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-12 02:25:10'),
(55, 6, 'LOGOUT', 'Auth', '', '::1', '2026-09-12 02:35:47'),
(56, 2, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-12 02:36:00'),
(57, 2, 'LOGOUT', 'Auth', '', '::1', '2026-09-12 02:51:43'),
(58, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-12 02:51:54'),
(59, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-12 02:52:00'),
(60, 2, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-12 03:25:42'),
(61, 2, 'LOGOUT', 'Auth', '', '::1', '2026-09-12 03:25:57'),
(62, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-12 03:26:13'),
(63, 1, 'LOGOUT', 'Auth', '', '::1', '2026-09-12 03:50:43'),
(64, 7, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-12 03:50:51'),
(65, 7, 'LOGOUT', 'Auth', '', '::1', '2026-09-12 03:51:17'),
(66, 6, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-12 03:51:45'),
(67, 6, 'LOGOUT', 'Auth', '', '::1', '2026-09-12 03:52:00'),
(68, NULL, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-12 03:52:21'),
(69, NULL, 'LOGOUT', 'Auth', '', '::1', '2026-09-12 03:52:30'),
(70, 2, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-12 03:52:38'),
(71, 2, 'LOGOUT', 'Auth', '', '::1', '2026-09-12 03:53:08'),
(72, 4, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-12 03:53:18'),
(73, 4, 'LOGOUT', 'Auth', '', '::1', '2026-09-12 04:03:16'),
(74, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: hrstaff@cafe.test', '::1', '2026-09-12 04:07:54'),
(75, 3, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-12 04:08:01'),
(76, 3, 'LOGOUT', 'Auth', '', '::1', '2026-09-12 04:17:28'),
(77, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-13 07:14:45'),
(78, 6, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-14 17:13:50'),
(79, 6, 'LOGOUT', 'Auth', '', '::1', '2026-09-14 17:34:55'),
(80, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-09-28 04:18:40'),
(81, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 04:18:46'),
(82, 1, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 04:21:40'),
(83, 3, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 04:22:45'),
(84, 3, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 04:22:57'),
(85, 2, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 04:23:10'),
(86, 2, 'MANAGE_BRANCH', 'Branches', 'Main Branch', '::1', '2026-09-28 04:23:48'),
(87, 2, 'MANAGE_BRANCH', 'Branches', 'Gen Tri', '::1', '2026-09-28 04:25:43'),
(88, 2, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 04:25:48'),
(89, 3, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 04:26:10'),
(90, 3, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 04:49:07'),
(91, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 04:49:17'),
(92, 1, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 04:54:33'),
(93, 3, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 04:56:39'),
(94, 3, 'CREATE_JOB', 'Recruitment', 'gehh', '::1', '2026-09-28 05:00:02'),
(95, 3, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 05:00:11'),
(96, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 05:01:04'),
(97, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 05:11:09'),
(98, 1, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 05:14:04'),
(99, 6, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 05:14:19'),
(100, 6, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 07:04:48'),
(101, 3, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 07:04:57'),
(102, 3, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 07:05:48'),
(103, NULL, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 07:05:56'),
(105, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 07:15:07'),
(106, 1, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 07:21:23'),
(107, 3, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 07:21:32'),
(108, 3, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 09:25:49'),
(109, 3, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 09:26:37'),
(110, 2, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 09:26:47'),
(111, 2, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 09:35:03'),
(112, 3, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 09:35:19'),
(113, 3, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 10:00:14'),
(114, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 10:00:21'),
(115, 1, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 10:18:49'),
(116, 2, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 10:19:03'),
(117, 2, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 10:20:05'),
(118, 3, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 10:20:14'),
(119, 3, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 10:46:45'),
(120, 2, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 10:46:54'),
(121, 2, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 10:47:45'),
(122, 3, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 10:47:55'),
(123, 3, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 10:48:02'),
(124, NULL, 'GUEST_APPLY_JOB', 'Applicant', 'job_id=1 reference_code=APP-20260929-K6G7', '::1', '2026-09-28 10:48:47'),
(125, 3, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 10:49:06'),
(126, 3, 'UPDATE_APP_STATUS', 'Recruitment', 'application_id=1 status=UNDER_REVIEW', '::1', '2026-09-28 10:49:30'),
(127, 3, 'UPDATE_APP_STATUS', 'Recruitment', 'application_id=1 status=SHORTLISTED', '::1', '2026-09-28 10:49:37'),
(128, 3, 'UPDATE_APP_STATUS', 'Recruitment', 'application_id=1 status=INTERVIEW_SCHEDULED schedule=2026-09-29 09:00:00', '::1', '2026-09-28 10:49:42'),
(129, 3, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 10:49:47'),
(130, 2, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-09-28 10:55:48'),
(131, 2, 'LOGOUT', 'Auth', '', '::1', '2026-09-28 10:56:33'),
(132, 6, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 06:46:24'),
(133, 6, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 06:49:40'),
(134, 2, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 06:50:37'),
(135, 1, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:46:21'),
(136, 2, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:46:21'),
(137, 6, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:46:21'),
(138, 7, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:46:21'),
(139, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: employee1@cafe.test', '127.0.0.1', '2026-10-01 09:46:21'),
(140, 1, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:49:21'),
(141, 2, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:49:21'),
(142, 3, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:49:22'),
(143, 4, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:49:22'),
(144, 6, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:49:22'),
(145, 7, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:49:23'),
(146, 1, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:50:14'),
(147, 2, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:50:15'),
(148, 3, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:50:16'),
(149, 4, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:50:16'),
(150, 6, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:50:17'),
(151, 7, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:50:17'),
(152, 1, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:52:10'),
(153, 2, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:52:11'),
(154, 3, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:52:11'),
(155, 4, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:52:12'),
(156, 6, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:52:12'),
(157, 7, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 09:52:13'),
(158, 6, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 09:54:25'),
(159, 6, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 09:54:42'),
(160, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 09:54:54'),
(161, 1, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 10:43:24'),
(162, 2, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 10:43:25'),
(163, 3, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 10:43:25'),
(164, 4, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 10:43:26'),
(165, 6, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 10:43:27'),
(166, 7, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 10:43:27'),
(167, 6, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 10:44:25'),
(168, 6, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 10:44:53'),
(169, 6, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 10:47:31'),
(170, 6, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 10:48:02'),
(171, 1, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 10:51:38'),
(172, 6, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 10:51:55'),
(173, 1, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 11:22:25'),
(174, 2, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 11:22:26'),
(175, 3, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 11:22:26'),
(176, 4, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 11:22:27'),
(177, 6, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 11:22:27'),
(178, 7, 'LOGIN_SUCCESS', 'Auth', '', '127.0.0.1', '2026-10-01 11:22:28'),
(179, 6, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 11:38:27'),
(180, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: owner@cafe.test', '::1', '2026-10-01 11:38:48'),
(181, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 11:38:54'),
(182, 1, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 12:00:23'),
(183, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: cashier1@cafe.test', '::1', '2026-10-01 12:01:02'),
(184, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: cashier1@cafe.test', '::1', '2026-10-01 12:01:10'),
(185, 6, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 12:01:18'),
(186, 6, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 12:02:23'),
(187, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: hrmanager@cafe.test', '::1', '2026-10-01 12:02:35'),
(188, 2, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 12:02:42'),
(189, 2, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 12:47:10'),
(190, 3, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 12:47:19'),
(191, 3, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 12:47:33'),
(192, 4, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 12:47:46'),
(193, 4, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 12:48:29'),
(194, 3, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 13:01:07'),
(195, 3, 'CREATE_JOB', 'Recruitment', 'shhs', '::1', '2026-10-01 13:03:18'),
(196, 3, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 13:03:21'),
(197, NULL, 'GUEST_APPLY_JOB', 'Applicant', 'job_id=2 reference_code=APP-20261001-SYJE', '::1', '2026-10-01 13:03:54'),
(198, 3, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 13:04:08'),
(199, 3, 'UPDATE_APP_STATUS', 'Recruitment', 'application_id=2 status=UNDER_REVIEW', '::1', '2026-10-01 13:04:28'),
(200, 3, 'UPDATE_APP_STATUS', 'Recruitment', 'application_id=2 status=SHORTLISTED', '::1', '2026-10-01 13:04:33'),
(201, 3, 'UPDATE_INTERVIEW_STATUS', 'Recruitment', 'interview_id=1 status=COMPLETED', '::1', '2026-10-01 13:05:14'),
(202, 3, 'UPDATE_INTERVIEW_STATUS', 'Recruitment', 'interview_id=1 status=CANCELLED', '::1', '2026-10-01 13:08:44'),
(203, 3, 'SCHEDULE_INTERVIEW', 'Recruitment', 'application_id=2 schedule=2026-10-02 10:09:00 interviewer_id=3', '::1', '2026-10-01 13:09:24'),
(204, 3, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 13:09:53'),
(205, 7, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 13:10:15'),
(206, 7, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 14:33:22'),
(207, 2, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 14:33:31'),
(208, 2, 'MANAGE_BRANCH', 'Branches', 'Main Branch', '::1', '2026-10-01 14:33:49'),
(209, 2, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 14:34:00'),
(210, 7, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 14:34:11'),
(211, 7, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 15:06:16'),
(212, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 15:06:24'),
(213, 1, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 15:06:45'),
(214, 7, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 15:06:57'),
(215, 7, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 15:10:11'),
(216, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 15:10:24'),
(217, 1, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 15:11:23'),
(218, 7, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 15:11:31'),
(219, 7, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 15:12:55'),
(220, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: jas@cafe.test', '::1', '2026-10-01 15:13:09'),
(221, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: jas@cafe.test', '::1', '2026-10-01 15:13:16'),
(222, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 15:14:05'),
(223, 1, 'OWNER_CREATE_USER', 'User Management', 'email=jas@cafe.test role_id=10', '::1', '2026-10-01 15:22:33'),
(224, 1, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 15:22:41'),
(225, 12, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 15:22:48'),
(226, 12, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 15:33:34'),
(227, 12, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 15:33:52'),
(228, 12, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 15:42:57'),
(229, 6, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 15:43:07'),
(230, 6, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 15:43:41'),
(231, 7, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 15:43:50'),
(232, 7, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 15:45:28'),
(233, 12, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 15:45:37'),
(234, 12, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 15:49:59'),
(235, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 15:50:17'),
(236, 1, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 15:51:14'),
(237, 7, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 15:51:24'),
(238, 7, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 16:02:33'),
(239, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 16:02:42'),
(240, 1, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 16:04:13'),
(241, 7, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 16:04:23'),
(242, 7, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 16:05:07'),
(243, 12, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 16:05:15'),
(244, 12, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 16:05:37'),
(245, 7, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 16:05:48'),
(246, 7, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 16:06:12'),
(247, 12, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 16:06:27'),
(248, 12, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 16:06:42'),
(249, 7, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 16:07:01'),
(250, 7, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 16:09:51'),
(251, 1, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 16:09:59'),
(252, 1, 'LOGOUT', 'Auth', '', '::1', '2026-10-01 16:10:25'),
(253, 7, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 16:10:36'),
(254, 7, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-01 19:07:32'),
(255, NULL, 'LOGIN_FAILED', 'Auth', 'Email attempted: invstaff1@cafe.test', '::1', '2026-10-02 00:05:41'),
(256, 7, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-02 00:05:49'),
(257, 7, 'LOGOUT', 'Auth', '', '::1', '2026-10-02 00:07:17'),
(258, 7, 'LOGIN_SUCCESS', 'Auth', '', '::1', '2026-10-02 00:35:10');

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `branch_id` int(11) NOT NULL,
  `branch_name` varchar(100) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`branch_id`, `branch_name`, `address`, `contact_number`, `latitude`, `longitude`, `status`, `created_at`) VALUES
(1, 'Main Branch', NULL, NULL, 14.6011250, 120.9817890, 'ACTIVE', '2026-09-07 10:22:43'),
(3, 'Gen Tri', 'Bagumbayan, Gen Tri', NULL, 14.3865120, 120.8811510, 'ACTIVE', '2026-09-28 04:25:43');

-- --------------------------------------------------------

--
-- Table structure for table `branch_inventory`
--

CREATE TABLE `branch_inventory` (
  `branch_inventory_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `reorder_level` int(11) NOT NULL DEFAULT 10,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `branch_inventory`
--

INSERT INTO `branch_inventory` (`branch_inventory_id`, `branch_id`, `product_id`, `stock`, `reorder_level`, `updated_at`) VALUES
(1, 1, 1, 100, 10, '2026-09-14 17:15:07'),
(2, 1, 2, 89, 10, '2026-10-01 15:43:35'),
(3, 1, 3, 100, 10, '2026-09-14 17:15:07'),
(4, 1, 4, 100, 10, '2026-09-14 17:15:07'),
(5, 1, 5, 100, 10, '2026-09-14 17:15:07'),
(6, 1, 6, 100, 10, '2026-09-14 17:15:07'),
(7, 1, 7, 100, 10, '2026-09-14 17:15:07'),
(8, 1, 8, 100, 10, '2026-09-14 17:15:07'),
(9, 1, 9, 100, 10, '2026-09-14 17:15:07'),
(10, 1, 10, 100, 10, '2026-09-14 17:15:07'),
(11, 1, 11, 100, 10, '2026-09-14 17:15:07'),
(12, 1, 12, 100, 10, '2026-09-14 17:15:07'),
(13, 1, 13, 100, 10, '2026-09-14 17:15:07'),
(14, 1, 14, 100, 10, '2026-09-14 17:15:07'),
(15, 1, 15, 100, 10, '2026-09-14 17:15:07'),
(16, 1, 16, 100, 10, '2026-09-14 17:15:07'),
(17, 1, 17, 100, 10, '2026-09-14 17:15:07'),
(18, 1, 18, 100, 10, '2026-09-14 17:15:07'),
(19, 1, 19, 100, 10, '2026-09-14 17:15:07'),
(20, 1, 20, 100, 10, '2026-09-14 17:15:07'),
(21, 1, 21, 100, 10, '2026-09-14 17:15:07'),
(22, 1, 22, 100, 10, '2026-09-14 17:15:07'),
(23, 1, 23, 100, 10, '2026-09-14 17:15:07'),
(24, 1, 24, 100, 10, '2026-09-14 17:15:07'),
(25, 1, 25, 100, 10, '2026-09-14 17:15:07'),
(26, 1, 26, 100, 10, '2026-09-14 17:15:07'),
(27, 1, 27, 0, 10, '2026-09-14 17:15:07'),
(28, 1, 28, 100, 10, '2026-09-14 17:15:07'),
(29, 1, 29, 98, 10, '2026-10-01 15:43:35'),
(30, 1, 30, 100, 10, '2026-09-14 17:15:07'),
(31, 1, 31, 200, 10, '2026-09-14 17:15:07'),
(32, 1, 32, 200, 10, '2026-09-14 17:15:07'),
(33, 1, 33, 190, 10, '2026-10-01 15:43:35'),
(34, 1, 34, 200, 10, '2026-09-14 17:15:07'),
(35, 1, 35, 200, 10, '2026-09-14 17:15:07'),
(36, 1, 36, 200, 10, '2026-09-14 17:15:07');

-- --------------------------------------------------------

--
-- Table structure for table `branch_item_inventory`
--

CREATE TABLE `branch_item_inventory` (
  `branch_item_inventory_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `current_stock` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `branch_item_inventory`
--

INSERT INTO `branch_item_inventory` (`branch_item_inventory_id`, `branch_id`, `item_id`, `current_stock`, `updated_at`) VALUES
(1, 1, 1, 2.00, '2026-10-01 13:21:53'),
(2, 1, 2, 0.00, '2026-10-01 13:21:53');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`category_id`, `category_name`, `description`, `created_at`) VALUES
(1, 'Coffee & Beans', 'Coffee beans, ground coffee, coffee-based ingredients', '2026-07-27 17:42:27'),
(2, 'Dairy & Milk', 'Fresh milk, creamer, and dairy alternatives', '2026-07-27 17:42:27'),
(3, 'Syrups & Flavorings', 'Flavored syrups, sauces, and sweeteners', '2026-07-27 17:42:27'),
(4, 'Pastries & Bread', 'Baked goods and pastry items', '2026-07-27 17:42:27'),
(5, 'Packaging & Supplies', 'Cups, lids, straws, napkins, and packaging materials', '2026-07-27 17:42:27'),
(6, 'Others', 'Miscellaneous stock items', '2026-07-27 17:42:27'),
(7, 'Raw Materials', NULL, '2026-10-02 08:32:02');

-- --------------------------------------------------------

--
-- Table structure for table `categories_old`
--

CREATE TABLE `categories_old` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories_old`
--

INSERT INTO `categories_old` (`category_id`, `category_name`, `description`, `created_at`) VALUES
(1, 'Coffee & Beans', 'Coffee beans, ground coffee, coffee-based ingredients', '2026-07-27 17:42:27'),
(2, 'Dairy & Milk', 'Fresh milk, creamer, and dairy alternatives', '2026-07-27 17:42:27'),
(3, 'Syrups & Flavorings', 'Flavored syrups, sauces, and sweeteners', '2026-07-27 17:42:27'),
(4, 'Pastries & Bread', 'Baked goods and pastry items', '2026-07-27 17:42:27'),
(5, 'Packaging & Supplies', 'Cups, lids, straws, napkins, and packaging materials', '2026-07-27 17:42:27'),
(6, 'Others', 'Miscellaneous stock items', '2026-07-27 17:42:27'),
(1, 'Coffee & Beans', 'Coffee beans, ground coffee, coffee-based ingredients', '2026-07-27 17:42:27'),
(2, 'Dairy & Milk', 'Fresh milk, creamer, and dairy alternatives', '2026-07-27 17:42:27'),
(3, 'Syrups & Flavorings', 'Flavored syrups, sauces, and sweeteners', '2026-07-27 17:42:27'),
(4, 'Pastries & Bread', 'Baked goods and pastry items', '2026-07-27 17:42:27'),
(5, 'Packaging & Supplies', 'Cups, lids, straws, napkins, and packaging materials', '2026-07-27 17:42:27'),
(6, 'Others', 'Miscellaneous stock items', '2026-07-27 17:42:27');

-- --------------------------------------------------------

--
-- Table structure for table `deliveries`
--

CREATE TABLE `deliveries` (
  `delivery_id` int(11) NOT NULL,
  `delivery_number` varchar(30) NOT NULL,
  `po_id` int(11) NOT NULL,
  `delivery_date` date NOT NULL,
  `received_by` int(11) NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `deliveries`
--

INSERT INTO `deliveries` (`delivery_id`, `delivery_number`, `po_id`, `delivery_date`, `received_by`, `remarks`, `created_at`) VALUES
(4, 'GRN-2026-0001', 4, '2026-10-01', 7, NULL, '2026-10-01 23:45:20'),
(5, 'GRN-2026-0002', 7, '2026-10-02', 7, NULL, '2026-10-02 00:05:04');

-- --------------------------------------------------------

--
-- Table structure for table `delivery_items`
--

CREATE TABLE `delivery_items` (
  `delivery_item_id` int(11) NOT NULL,
  `delivery_id` int(11) NOT NULL,
  `po_item_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity_delivered` decimal(10,2) NOT NULL,
  `expiry_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `delivery_items`
--

INSERT INTO `delivery_items` (`delivery_item_id`, `delivery_id`, `po_item_id`, `item_id`, `quantity_delivered`, `expiry_date`) VALUES
(4, 4, 4, 1, 10.00, NULL),
(5, 5, 7, 3, 10.00, NULL),
(6, 5, 8, 2, 2.00, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `department_name`, `description`, `manager_id`, `created_at`) VALUES
(1, 'Kitchen', 'Food preparation and cooking staff', NULL, '2026-09-07 10:22:43'),
(2, 'Service', 'Front-of-house service, waiters, and floor staff', NULL, '2026-09-07 10:22:43'),
(3, 'Bar', 'Coffee and beverage preparation (baristas)', NULL, '2026-09-07 10:22:43'),
(4, 'Cashier', 'Point-of-sale, order taking, and payment handling', NULL, '2026-09-07 10:22:43'),
(6, 'General', 'General and cross-functional staff', NULL, '2026-09-28 09:54:45'),
(7, 'Finance', 'Finance operations and accounting staff', NULL, '2026-09-28 09:54:45'),
(8, 'Inventory', 'Inventory, stock, and procurement staff', NULL, '2026-09-28 09:54:45');

-- --------------------------------------------------------

--
-- Table structure for table `document_sequences`
--

CREATE TABLE `document_sequences` (
  `doc_type` varchar(20) NOT NULL,
  `year` int(11) NOT NULL,
  `last_number` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_sequences`
--

INSERT INTO `document_sequences` (`doc_type`, `year`, `last_number`) VALUES
('EC', 2026, 0),
('REQ-DC', 2026, 0),
('REQ-GR', 2026, 0),
('REQ-LV', 2026, 0),
('REQ-OT', 2026, 0);

-- --------------------------------------------------------

--
-- Table structure for table `doc_sequences`
--

CREATE TABLE `doc_sequences` (
  `doc_type` varchar(10) NOT NULL,
  `year` int(11) NOT NULL,
  `last_number` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `doc_sequences`
--

INSERT INTO `doc_sequences` (`doc_type`, `year`, `last_number`) VALUES
('GRN', 2026, 2),
('PO', 2026, 4),
('GRN', 2026, 2),
('PO', 2026, 4),
('PR', 2026, 1);

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `employee_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `employee_code` varchar(20) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `date_hired` date DEFAULT NULL,
  `employment_type` enum('FULL_TIME','PART_TIME','CONTRACTUAL','PROBATIONARY') DEFAULT 'FULL_TIME',
  `probation_end_date` date DEFAULT NULL,
  `contract_end_date` date DEFAULT NULL,
  `employment_end_date` date DEFAULT NULL,
  `shift` enum('MORNING','AFTERNOON','GRAVEYARD') NOT NULL DEFAULT 'MORNING',
  `employment_status` enum('ACTIVE','ON_LEAVE','RESIGNED','TERMINATED') DEFAULT 'ACTIVE',
  `basic_salary` decimal(12,2) DEFAULT 0.00,
  `reports_to` int(11) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `gender` enum('MALE','FEMALE','OTHER') DEFAULT NULL,
  `emergency_contact_name` varchar(150) DEFAULT NULL,
  `emergency_contact_phone` varchar(30) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`employee_id`, `user_id`, `employee_code`, `department_id`, `branch_id`, `position`, `date_hired`, `employment_type`, `probation_end_date`, `contract_end_date`, `employment_end_date`, `shift`, `employment_status`, `basic_salary`, `reports_to`, `address`, `birthdate`, `gender`, `emergency_contact_name`, `emergency_contact_phone`, `created_at`, `updated_at`) VALUES
(4, 1, 'EMP-0001', NULL, 1, NULL, NULL, 'FULL_TIME', NULL, NULL, NULL, 'MORNING', 'ACTIVE', 0.00, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-01 15:02:13', '2026-10-01 15:02:13'),
(5, 2, 'EMP-0002', NULL, 1, NULL, NULL, 'FULL_TIME', NULL, NULL, NULL, 'MORNING', 'ACTIVE', 0.00, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-01 15:02:13', '2026-10-01 15:02:13'),
(6, 3, 'EMP-0003', NULL, 1, NULL, NULL, 'FULL_TIME', NULL, NULL, NULL, 'MORNING', 'ACTIVE', 0.00, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-01 15:02:13', '2026-10-01 15:02:13'),
(7, 4, 'EMP-0004', NULL, 1, NULL, NULL, 'FULL_TIME', NULL, NULL, NULL, 'MORNING', 'ACTIVE', 0.00, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-01 15:02:13', '2026-10-01 15:02:13'),
(8, 6, 'EMP-0005', NULL, 1, NULL, NULL, 'FULL_TIME', NULL, NULL, NULL, 'MORNING', 'ACTIVE', 0.00, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-01 15:02:13', '2026-10-01 15:21:28'),
(9, 7, 'EMP-0006', NULL, 1, NULL, NULL, 'FULL_TIME', NULL, NULL, NULL, 'MORNING', 'ACTIVE', 0.00, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-01 15:02:13', '2026-10-01 15:21:28'),
(11, 12, 'EMP-0007', 7, 1, 'Finance', '2026-10-01', 'FULL_TIME', NULL, NULL, NULL, 'MORNING', 'ACTIVE', 0.00, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-01 15:22:33', '2026-10-01 15:31:03');

-- --------------------------------------------------------

--
-- Table structure for table `employment_contracts`
--

CREATE TABLE `employment_contracts` (
  `contract_id` int(11) NOT NULL,
  `contract_no` varchar(30) NOT NULL,
  `application_id` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `offer_id` int(11) DEFAULT NULL,
  `status` enum('ACTIVE','VOID') NOT NULL DEFAULT 'ACTIVE',
  `generated_by` int(11) DEFAULT NULL,
  `generated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `employee_signed_by` int(11) DEFAULT NULL,
  `employee_signed_name` varchar(150) DEFAULT NULL,
  `employee_signed_role` varchar(60) DEFAULT NULL,
  `employee_signed_at` datetime DEFAULT NULL,
  `employee_signature_hash` varchar(64) DEFAULT NULL,
  `employer_signed_by` int(11) DEFAULT NULL,
  `employer_signed_name` varchar(150) DEFAULT NULL,
  `employer_signed_role` varchar(60) DEFAULT NULL,
  `employer_signed_at` datetime DEFAULT NULL,
  `employer_signature_hash` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `finance_categories`
--

CREATE TABLE `finance_categories` (
  `fin_category_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `type` enum('Income','Expense') NOT NULL,
  `is_system` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `finance_categories`
--

INSERT INTO `finance_categories` (`fin_category_id`, `category_name`, `type`, `is_system`) VALUES
(1, 'Sales', 'Income', 1),
(2, 'Purchases', 'Expense', 1),
(3, 'Utilities', 'Expense', 0),
(4, 'Rent', 'Expense', 0),
(5, 'Salaries', 'Expense', 0),
(6, 'Miscellaneous', 'Expense', 0);

-- --------------------------------------------------------

--
-- Table structure for table `finance_transactions`
--

CREATE TABLE `finance_transactions` (
  `transaction_id` int(11) NOT NULL,
  `transaction_type` enum('Income','Expense') NOT NULL,
  `fin_category_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `transaction_date` date NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `reference_type` enum('Manual','Delivery','Sale','Payroll','StockIn','PurchasePayable') DEFAULT 'Manual',
  `reference_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `finance_transactions`
--

INSERT INTO `finance_transactions` (`transaction_id`, `transaction_type`, `fin_category_id`, `amount`, `transaction_date`, `description`, `reference_type`, `reference_id`, `created_by`, `created_at`, `updated_at`) VALUES
(2, 'Income', 1, 12420.00, '2026-09-12', 'POS sale #5', 'Sale', 5, 6, '2026-09-12 18:35:22', '2026-09-12 18:35:22'),
(3, 'Income', 1, 27.00, '2026-10-01', 'POS sale #6', 'Sale', 6, 6, '2026-10-01 18:47:31', '2026-10-01 18:47:31'),
(4, 'Income', 1, 27.00, '2026-10-01', 'POS sale #7', 'Sale', 7, 6, '2026-10-01 18:48:03', '2026-10-01 18:48:03'),
(5, 'Income', 1, 496.80, '2026-10-01', 'POS sale #8', 'Sale', 8, 6, '2026-10-01 18:53:04', '2026-10-01 18:53:04'),
(11, 'Income', 1, 1360.80, '2026-10-01', 'POS sale #9', 'Sale', 9, 6, '2026-10-01 23:43:35', '2026-10-01 23:43:35');

-- --------------------------------------------------------

--
-- Table structure for table `half_day_requests`
--

CREATE TABLE `half_day_requests` (
  `half_day_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `request_date` date NOT NULL,
  `reason` varchar(255) NOT NULL,
  `status` enum('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_remarks` varchar(255) DEFAULT NULL,
  `filed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `interviews`
--

CREATE TABLE `interviews` (
  `interview_id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `stage` enum('HR_INITIAL','FINAL_DEPARTMENT') NOT NULL DEFAULT 'HR_INITIAL',
  `schedule_date` datetime DEFAULT NULL,
  `interviewer_id` int(11) DEFAULT NULL,
  `interviewer` varchar(150) DEFAULT NULL,
  `mode` enum('ONSITE','ONLINE') NOT NULL DEFAULT 'ONSITE',
  `location` varchar(255) DEFAULT NULL,
  `status` enum('PENDING','PENDING_SCHEDULE','SCHEDULED','COMPLETED','CANCELLED','NO_SHOW') NOT NULL DEFAULT 'PENDING',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `evaluation_notes` text DEFAULT NULL,
  `recommendation` varchar(20) DEFAULT NULL,
  `evaluated_by` int(11) DEFAULT NULL,
  `evaluated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `interviews`
--

INSERT INTO `interviews` (`interview_id`, `application_id`, `stage`, `schedule_date`, `interviewer_id`, `interviewer`, `mode`, `location`, `status`, `notes`, `created_by`, `created_at`, `updated_at`, `evaluation_notes`, `recommendation`, `evaluated_by`, `evaluated_at`) VALUES
(1, 1, 'HR_INITIAL', '2026-09-29 09:00:00', NULL, 'Jasmine Cruz', 'ONSITE', 'Gen Tri', 'CANCELLED', NULL, 3, '2026-09-28 10:49:42', '2026-10-01 13:08:44', NULL, NULL, NULL, NULL),
(2, 2, 'HR_INITIAL', '2026-10-02 10:09:00', NULL, 'Jasmine Cruz', 'ONSITE', 'Gen Tri', 'SCHEDULED', NULL, 3, '2026-10-01 13:09:24', '2026-10-01 13:09:24', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `items`
--

CREATE TABLE `items` (
  `item_id` int(11) NOT NULL,
  `item_code` varchar(30) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `category_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `cost_price` decimal(10,2) DEFAULT 0.00,
  `current_stock` decimal(10,2) DEFAULT 0.00,
  `reorder_level` decimal(10,2) DEFAULT 0.00,
  `is_perishable` tinyint(1) DEFAULT 0,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `items`
--

INSERT INTO `items` (`item_id`, `item_code`, `item_name`, `category_id`, `unit_id`, `cost_price`, `current_stock`, `reorder_level`, `is_perishable`, `status`, `created_at`, `updated_at`) VALUES
(1, 'ITM-0001', 'Arabica Coffee Beans', 1, 1, 850.00, 2.00, 2.00, 1, 'Active', '2026-07-27 18:00:54', '2026-10-02 03:12:32'),
(2, 'ITM-0002', 'Fresh Milk', 2, 3, 120.00, 0.00, 2.00, 1, 'Active', '2026-07-27 18:02:16', '2026-10-02 00:00:36'),
(3, 'ITM-0003', 'Coffee beans 18g', 2, 1, 12.78, 0.00, 2.00, 0, 'Active', '2026-10-01 23:57:51', '2026-10-01 23:57:51'),
(4, 'ITM-0004', 'Chocolate Syrup', 3, 4, 20.00, 0.00, 2.00, 0, 'Active', '2026-10-01 23:59:12', '2026-10-01 23:59:12'),
(5, 'ITM-0005', 'Coffee beans', 1, 2, 60.00, 0.00, 2.00, 1, 'Active', '2026-10-02 00:08:33', '2026-10-02 00:08:33'),
(6, 'ING-002', 'Milk', 7, 3, 0.00, 20.00, 5.00, 0, 'Active', '2026-10-02 08:32:02', '2026-10-02 08:32:02'),
(7, 'ING-003', 'Water', 7, 3, 0.00, 50.00, 10.00, 0, 'Active', '2026-10-02 08:32:02', '2026-10-02 08:32:02'),
(8, 'ING-004', 'Sugar', 7, 1, 0.00, 15.00, 3.00, 0, 'Active', '2026-10-02 08:32:02', '2026-10-02 08:32:02'),
(9, 'ING-005', 'Vanilla Syrup', 7, 3, 0.00, 5.00, 2.00, 0, 'Active', '2026-10-02 08:32:02', '2026-10-02 08:32:02'),
(10, 'ING-007', 'Caramel Syrup', 7, 3, 0.00, 5.00, 2.00, 0, 'Active', '2026-10-02 08:32:02', '2026-10-02 08:32:02'),
(11, 'ING-008', 'Matcha Powder', 7, 1, 0.00, 3.00, 1.00, 0, 'Active', '2026-10-02 08:32:02', '2026-10-02 08:32:02'),
(12, 'ING-009', 'Chai Powder', 7, 1, 0.00, 3.00, 1.00, 0, 'Active', '2026-10-02 08:32:02', '2026-10-02 08:32:02'),
(13, 'ING-010', 'Ice', 7, 1, 0.00, 20.00, 5.00, 0, 'Active', '2026-10-02 08:32:02', '2026-10-02 08:32:02'),
(14, 'ING-011', 'Croissant Dough', 7, 9, 0.00, 50.00, 20.00, 0, 'Active', '2026-10-02 08:32:02', '2026-10-02 08:32:02'),
(15, 'ING-012', 'Chocolate Chips', 7, 1, 0.00, 5.00, 2.00, 0, 'Active', '2026-10-02 08:32:02', '2026-10-02 08:32:02');

-- --------------------------------------------------------

--
-- Table structure for table `items_old`
--

CREATE TABLE `items_old` (
  `item_id` int(11) NOT NULL,
  `item_code` varchar(30) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `category_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `cost_price` decimal(10,2) DEFAULT 0.00,
  `current_stock` decimal(10,2) DEFAULT 0.00,
  `reorder_level` decimal(10,2) DEFAULT 0.00,
  `is_perishable` tinyint(1) DEFAULT 0,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `items_old`
--

INSERT INTO `items_old` (`item_id`, `item_code`, `item_name`, `category_id`, `unit_id`, `cost_price`, `current_stock`, `reorder_level`, `is_perishable`, `status`, `created_at`, `updated_at`) VALUES
(1, 'ITM-0001', 'Arabica Coffee Beans', 1, 1, 850.00, 2.00, 2.00, 0, 'Active', '2026-07-27 18:00:54', '2026-07-27 18:03:15'),
(2, 'ITM-0002', 'Fresh Milk', 2, 3, 120.00, 0.00, 2.00, 1, 'Active', '2026-07-27 18:02:16', '2026-07-27 18:02:16'),
(1, 'ITM-0001', 'Arabica Coffee Beans', 1, 1, 850.00, 2.00, 2.00, 0, 'Active', '2026-07-27 18:00:54', '2026-07-27 18:03:15'),
(2, 'ITM-0002', 'Fresh Milk', 2, 3, 120.00, 0.00, 2.00, 1, 'Active', '2026-07-27 18:02:16', '2026-07-27 18:02:16');

-- --------------------------------------------------------

--
-- Table structure for table `item_pos_mappings`
--

CREATE TABLE `item_pos_mappings` (
  `item_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `pos_units_per_item` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_applications`
--

CREATE TABLE `job_applications` (
  `application_id` int(11) NOT NULL,
  `reference_code` varchar(20) NOT NULL,
  `applicant_id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `cover_letter` text DEFAULT NULL,
  `status` enum('SUBMITTED','UNDER_REVIEW','SHORTLISTED','INTERVIEW_SCHEDULED','INTERVIEWED','HR_INTERVIEW_PASSED','FINAL_INTERVIEW_SCHEDULED','RECOMMENDED_FOR_HIRE','HIRE_APPROVED','OFFERED','ACCEPTED','DECLINED','HIRED','REJECTED','WITHDRAWN') NOT NULL DEFAULT 'SUBMITTED',
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `remarks` text DEFAULT NULL,
  `hiring_approved_by` int(11) DEFAULT NULL,
  `hiring_approved_at` datetime DEFAULT NULL,
  `hiring_approval_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_applications`
--

INSERT INTO `job_applications` (`application_id`, `reference_code`, `applicant_id`, `job_id`, `cover_letter`, `status`, `applied_at`, `updated_at`, `remarks`, `hiring_approved_by`, `hiring_approved_at`, `hiring_approval_notes`) VALUES
(1, 'APP-20260929-K6G7', 1, 1, NULL, 'INTERVIEW_SCHEDULED', '2026-09-28 10:48:47', '2026-09-28 10:49:42', '', NULL, NULL, NULL),
(2, 'APP-20261001-SYJE', 2, 2, NULL, 'INTERVIEW_SCHEDULED', '2026-10-01 13:03:54', '2026-10-01 13:09:24', '', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `job_offers`
--

CREATE TABLE `job_offers` (
  `offer_id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `offered_salary` decimal(12,2) DEFAULT NULL,
  `employment_type` enum('FULL_TIME','PART_TIME','CONTRACTUAL','PROBATIONARY') NOT NULL DEFAULT 'PROBATIONARY',
  `shift` enum('MORNING','AFTERNOON','GRAVEYARD') NOT NULL DEFAULT 'MORNING',
  `position` varchar(100) DEFAULT NULL,
  `offer_date` date DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `status` enum('PENDING','SENT','ACCEPTED','DECLINED','EXPIRED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_vacancies`
--

CREATE TABLE `job_vacancies` (
  `job_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `target_role_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `description` text NOT NULL,
  `requirements` text DEFAULT NULL,
  `employment_type` enum('FULL_TIME','PART_TIME','CONTRACTUAL','PROBATIONARY') DEFAULT 'FULL_TIME',
  `slots` int(11) DEFAULT 1,
  `status` enum('OPEN','CLOSED') DEFAULT 'OPEN',
  `posted_by` int(11) NOT NULL,
  `posted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `closing_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_vacancies`
--

INSERT INTO `job_vacancies` (`job_id`, `title`, `target_role_id`, `department_id`, `branch_id`, `description`, `requirements`, `employment_type`, `slots`, `status`, `posted_by`, `posted_at`, `closing_date`) VALUES
(1, 'gehh', 7, 3, 3, 'kk', '', 'PROBATIONARY', 4, 'OPEN', 3, '2026-09-28 05:00:02', '2026-09-30'),
(2, 'shhs', 7, 4, 3, 'haha', '', 'FULL_TIME', 4, 'OPEN', 3, '2026-10-01 13:03:18', '2026-10-15');

-- --------------------------------------------------------

--
-- Table structure for table `leave_requests`
--

CREATE TABLE `leave_requests` (
  `leave_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `total_days` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('PENDING_MANAGER','PENDING_HR','PENDING_APPROVAL','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING_MANAGER',
  `manager_reviewed_by` int(11) DEFAULT NULL,
  `manager_reviewed_at` datetime DEFAULT NULL,
  `manager_remarks` varchar(255) DEFAULT NULL,
  `hr_processed_by` int(11) DEFAULT NULL,
  `hr_processed_at` datetime DEFAULT NULL,
  `hr_remarks` varchar(255) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_remarks` varchar(255) DEFAULT NULL,
  `filed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_types`
--

CREATE TABLE `leave_types` (
  `leave_type_id` int(11) NOT NULL,
  `type_name` varchar(50) NOT NULL,
  `default_days` int(11) DEFAULT 0,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leave_types`
--

INSERT INTO `leave_types` (`leave_type_id`, `type_name`, `default_days`, `description`) VALUES
(1, 'Vacation Leave', 15, 'Planned time off'),
(2, 'Sick Leave', 15, 'Health related absence'),
(3, 'Emergency Leave', 5, 'Urgent unplanned absence'),
(4, 'Maternity/Paternity Leave', 105, 'Childbirth related leave'),
(5, 'Unpaid Leave', 0, 'Leave without pay');

-- --------------------------------------------------------

--
-- Table structure for table `low_stock_notifications`
--

CREATE TABLE `low_stock_notifications` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `current_stock` int(11) NOT NULL,
  `threshold` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `low_stock_notifications`
--

INSERT INTO `low_stock_notifications` (`id`, `product_id`, `current_stock`, `threshold`, `created_at`, `resolved_at`) VALUES
(1, 27, 0, 10, '2026-09-12 18:35:22', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `menu_categories`
--

CREATE TABLE `menu_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `icon` varchar(10) DEFAULT '?',
  `sort_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `menu_categories`
--

INSERT INTO `menu_categories` (`id`, `name`, `icon`, `sort_order`) VALUES
(1, 'Hot Coffee', '☕', 1),
(2, 'Iced Coffee', '🧊', 2),
(3, 'Frappe', '🥤', 3),
(4, 'Non-Coffee', '🍵', 4),
(5, 'Pastries', '🥐', 5),
(6, 'Add-ons', '➕', 6);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` varchar(255) NOT NULL,
  `related_type` varchar(50) DEFAULT NULL,
  `related_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `related_type`, `related_id`, `is_read`, `created_at`) VALUES
(1, 2, 'HR Interview Scheduled', 'Jr Dsantos (gehh) is scheduled for HR Initial Interview on Sep 29, 2026 9:00 AM.', 'INTERVIEW', 1, 0, '2026-09-28 10:49:42'),
(2, 2, 'HR Interview Scheduled', 'lala haha (shhs) is scheduled for HR Initial Interview on Oct 02, 2026 10:09 AM.', 'INTERVIEW', 2, 0, '2026-10-01 13:09:24'),
(3, 1, 'Purchase Request Needs Approval', 'PR PR-2026-0001 (Arabica Coffee Beans, qty 2) is waiting for your approval.', 'purchase_request', 4, 0, '2026-10-01 15:03:41');

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_requests`
--

CREATE TABLE `password_reset_requests` (
  `request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `unlock_at` datetime NOT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll`
--

CREATE TABLE `payroll` (
  `payroll_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `pay_period_start` date NOT NULL,
  `pay_period_end` date NOT NULL,
  `basic_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `regular_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `overtime_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `overtime_pay` decimal(12,2) DEFAULT 0.00,
  `allowances` decimal(12,2) DEFAULT 0.00,
  `deductions` decimal(12,2) DEFAULT 0.00,
  `attendance_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `absence_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(12,2) DEFAULT 0.00,
  `net_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('DRAFT','PREPARED','SUBMITTED','PROCESSED','PAID') DEFAULT 'DRAFT',
  `generated_by` int(11) NOT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approval_remarks` varchar(500) DEFAULT NULL,
  `adjustment_remarks` varchar(500) DEFAULT NULL,
  `released_by` int(11) DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `finance_transaction_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `performance_evaluations`
--

CREATE TABLE `performance_evaluations` (
  `evaluation_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `evaluator_id` int(11) NOT NULL,
  `evaluation_period` varchar(50) NOT NULL,
  `quality_score` decimal(4,2) DEFAULT 0.00,
  `productivity_score` decimal(4,2) DEFAULT 0.00,
  `attendance_score` decimal(4,2) DEFAULT 0.00,
  `teamwork_score` decimal(4,2) DEFAULT 0.00,
  `overall_score` decimal(4,2) DEFAULT 0.00,
  `comments` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(11) DEFAULT 999,
  `image_url` varchar(255) DEFAULT '',
  `is_active` tinyint(1) DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `category_id`, `sku`, `name`, `price`, `stock`, `image_url`, `is_active`, `updated_at`) VALUES
(1, 1, 'HC-001', 'Espresso', 90.00, 100, '/unified/assets/products/espresso.jpg', 1, '2026-09-28 06:34:00'),
(2, 1, 'HC-002', 'Americano', 120.00, 100, '/unified/assets/products/americano.jpg', 1, '2026-09-28 06:34:00'),
(3, 1, 'HC-003', 'Cappuccino', 140.00, 100, '/unified/assets/products/cappucino.jpg', 1, '2026-09-28 06:34:00'),
(4, 1, 'HC-004', 'Caffe Latte', 150.00, 100, '/unified/assets/products/cafe latte.jpg', 1, '2026-09-28 06:34:00'),
(5, 1, 'HC-005', 'Flat White', 145.00, 100, '/unified/assets/products/flat white.jpg', 1, '2026-09-28 06:34:00'),
(6, 1, 'HC-006', 'Caffe Mocha', 160.00, 100, '/unified/assets/products/caffe mocha.jpg', 1, '2026-09-28 06:34:00'),
(7, 1, 'HC-007', 'Spanish Latte', 155.00, 100, '/unified/assets/products/spanish latte.jpg', 1, '2026-09-28 06:34:00'),
(8, 1, 'HC-008', 'Caramel Macchiato', 165.00, 100, '/unified/assets/products/caramel macchiato.jpg', 1, '2026-09-28 06:34:00'),
(9, 2, 'IC-001', 'Iced Americano', 130.00, 100, '/unified/assets/products/iced americano.jpg', 1, '2026-09-28 06:34:00'),
(10, 2, 'IC-002', 'Iced Latte', 150.00, 100, '/unified/assets/products/iced latte.jpg', 1, '2026-09-28 06:34:00'),
(11, 2, 'IC-003', 'Iced Caramel Macchiato', 175.00, 100, '/unified/assets/products/iced caramel macchiato.jpg', 1, '2026-09-28 06:34:00'),
(12, 2, 'IC-004', 'Iced Mocha', 170.00, 100, '/unified/assets/products/iced mocha.jpg', 1, '2026-09-28 06:34:00'),
(13, 2, 'IC-005', 'Iced Spanish Latte', 165.00, 100, '/unified/assets/products/iced spanish latte.jpg', 1, '2026-09-28 06:34:00'),
(14, 2, 'IC-006', 'Cold Brew', 150.00, 100, '/unified/assets/products/cold brew.jpg', 1, '2026-09-28 06:34:00'),
(15, 2, 'IC-007', 'Vanilla Sweet Cream Cold Brew', 180.00, 100, '/unified/assets/products/vanilla sweet cream cold brew.jpg', 1, '2026-09-28 06:34:00'),
(16, 3, 'FR-001', 'Caramel Frappe', 165.00, 100, '/unified/assets/products/caramel frappe.jpg', 1, '2026-09-28 06:34:00'),
(17, 3, 'FR-002', 'Mocha Frappe', 170.00, 100, '/unified/assets/products/mocha frappe.jpg', 1, '2026-09-28 06:34:00'),
(18, 3, 'FR-003', 'Java Chip Frappe', 175.00, 100, '/unified/assets/products/java chip frappe.jpg', 1, '2026-09-28 06:34:00'),
(19, 3, 'FR-004', 'Cookies and Cream Frappe', 175.00, 100, '/unified/assets/products/cookies and cream frappe.jpg', 1, '2026-09-28 06:34:00'),
(20, 4, 'NC-001', 'Matcha Latte', 160.00, 100, '/unified/assets/products/matcha latte.jpg', 1, '2026-09-28 06:34:00'),
(21, 4, 'NC-002', 'Chai Latte', 150.00, 100, '/unified/assets/products/chai latte.jpg', 1, '2026-09-28 06:34:00'),
(22, 4, 'NC-003', 'Hot Chocolate', 130.00, 100, '/unified/assets/products/hot chocolate.jpg', 1, '2026-09-28 06:34:00'),
(23, 4, 'NC-004', 'Strawberry Milk', 140.00, 100, '/unified/assets/products/strawberry milk.jpg', 1, '2026-09-28 06:34:00'),
(24, 4, 'NC-005', 'Iced Tea', 90.00, 100, '/unified/assets/products/iced tea.jpg', 1, '2026-09-28 06:34:00'),
(25, 5, 'PS-001', 'Butter Croissant', 95.00, 100, '/unified/assets/products/butter croissant.jpg', 1, '2026-09-28 06:34:00'),
(26, 5, 'PS-002', 'Chocolate Croissant', 110.00, 100, '/unified/assets/products/chocolate croissant.jpg', 1, '2026-09-28 06:34:00'),
(27, 5, 'PS-003', 'Blueberry Muffin', 115.00, 0, '/unified/assets/products/blueberry muffin.jpg', 1, '2026-09-28 06:34:00'),
(28, 5, 'PS-004', 'Chocolate Chip Muffin', 110.00, 100, '/unified/assets/products/chocolate chip muffin.jpg', 1, '2026-09-28 06:34:00'),
(29, 5, 'PS-005', 'Banana Bread Slice', 100.00, 100, '/unified/assets/products/banana bread slice.jpg', 1, '2026-09-28 06:34:00'),
(30, 5, 'PS-006', 'Cinnamon Roll', 120.00, 100, '/unified/assets/products/cinnamon roll.jpg', 1, '2026-09-28 06:34:00'),
(31, 6, 'AD-001', 'Extra Espresso Shot', 30.00, 200, '/unified/assets/products/extra espresso shot.jpg', 1, '2026-09-28 06:34:00'),
(32, 6, 'AD-002', 'Oat Milk Upgrade', 25.00, 200, '/unified/assets/products/oat milk upgrade.jpg', 1, '2026-09-28 06:34:00'),
(33, 6, 'AD-003', 'Almond Milk Upgrade', 25.00, 200, '/unified/assets/products/almond milk.jpg', 1, '2026-09-28 06:34:00'),
(34, 6, 'AD-004', 'Whipped Cream', 20.00, 200, '/unified/assets/products/whipped cream.jpg', 1, '2026-09-28 06:34:00'),
(35, 6, 'AD-005', 'Vanilla Syrup', 20.00, 200, '/unified/assets/products/vanilla syrup.jpg', 1, '2026-09-28 06:34:00'),
(36, 6, 'AD-006', 'Caramel Drizzle', 20.00, 200, '/unified/assets/products/caramel drizzle.jpg', 1, '2026-09-28 06:34:00');

-- --------------------------------------------------------

--
-- Table structure for table `product_ingredients`
--

CREATE TABLE `product_ingredients` (
  `product_ingredient_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL COMMENT 'FK to products.id (POS menu item)',
  `item_id` int(11) NOT NULL COMMENT 'FK to items.item_id (Inventory ingredient)',
  `qty_per_unit` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_ingredients`
--

INSERT INTO `product_ingredients` (`product_ingredient_id`, `product_id`, `item_id`, `qty_per_unit`, `created_at`) VALUES
(3, 1, 7, 0.0300, '2026-10-02 00:32:02'),
(4, 2, 7, 0.2500, '2026-10-02 00:32:02'),
(5, 3, 6, 0.1500, '2026-10-02 00:32:02'),
(6, 4, 6, 0.2000, '2026-10-02 00:32:02'),
(7, 6, 6, 0.1500, '2026-10-02 00:32:02'),
(8, 6, 4, 0.0300, '2026-10-02 00:32:02'),
(9, 8, 6, 0.1500, '2026-10-02 00:32:02'),
(10, 8, 10, 0.0300, '2026-10-02 00:32:02'),
(11, 20, 11, 0.0100, '2026-10-02 00:32:02'),
(12, 20, 6, 0.2000, '2026-10-02 00:32:02'),
(13, 20, 7, 0.0300, '2026-10-02 00:32:02'),
(14, 10, 6, 0.2000, '2026-10-02 00:32:02'),
(15, 10, 13, 0.2000, '2026-10-02 00:32:02'),
(16, 9, 7, 0.2500, '2026-10-02 00:32:02'),
(17, 9, 13, 0.2000, '2026-10-02 00:32:02'),
(18, 25, 14, 1.0000, '2026-10-02 00:32:02');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `po_id` int(11) NOT NULL,
  `po_number` varchar(30) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `order_date` date NOT NULL,
  `expected_date` date DEFAULT NULL,
  `status` enum('Pending','Approved','Ordered','Partially Received','Received','Cancelled') DEFAULT 'Pending',
  `total_amount` decimal(10,2) DEFAULT 0.00,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_orders`
--

INSERT INTO `purchase_orders` (`po_id`, `po_number`, `supplier_id`, `branch_id`, `order_date`, `expected_date`, `status`, `total_amount`, `remarks`, `created_by`, `created_at`, `updated_at`) VALUES
(4, 'PO-2026-0001', 2, 1, '2026-10-01', '2026-10-01', 'Received', 8500.00, '', 7, '2026-10-01 23:04:41', '2026-10-01 23:45:20'),
(5, 'PO-2026-0002', 2, 1, '2026-10-01', '2026-10-01', 'Ordered', 1700.00, '', 7, '2026-10-01 23:10:01', '2026-10-01 23:12:42'),
(6, 'PO-2026-0003', 2, 1, '2026-10-01', NULL, 'Pending', 1700.00, 'Converted from purchase request PR-2026-0001', 7, '2026-10-01 23:11:49', '2026-10-01 23:11:49'),
(7, 'PO-2026-0004', 2, 1, '2026-10-02', '2026-10-02', 'Received', 440.00, '', 7, '2026-10-02 00:02:27', '2026-10-02 00:05:04');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_items`
--

CREATE TABLE `purchase_order_items` (
  `po_item_id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity_ordered` decimal(10,2) NOT NULL,
  `quantity_received` decimal(10,2) DEFAULT 0.00,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_order_items`
--

INSERT INTO `purchase_order_items` (`po_item_id`, `po_id`, `item_id`, `quantity_ordered`, `quantity_received`, `unit_price`, `subtotal`) VALUES
(4, 4, 1, 10.00, 10.00, 850.00, 8500.00),
(5, 5, 2, 2.00, 0.00, 850.00, 1700.00),
(6, 6, 1, 2.00, 0.00, 850.00, 1700.00),
(7, 7, 3, 10.00, 10.00, 20.00, 200.00),
(8, 7, 2, 2.00, 2.00, 120.00, 240.00);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_payables`
--

CREATE TABLE `purchase_payables` (
  `payable_id` int(11) NOT NULL,
  `delivery_id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('UNPAID','PAID') NOT NULL DEFAULT 'UNPAID',
  `finance_transaction_id` int(11) DEFAULT NULL,
  `received_by` int(11) NOT NULL,
  `paid_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_payables`
--

INSERT INTO `purchase_payables` (`payable_id`, `delivery_id`, `po_id`, `supplier_id`, `branch_id`, `amount`, `status`, `finance_transaction_id`, `received_by`, `paid_by`, `created_at`, `paid_at`) VALUES
(4, 4, 4, 2, 1, 8500.00, 'UNPAID', NULL, 7, NULL, '2026-10-01 23:45:20', NULL),
(5, 5, 7, 2, 1, 440.00, 'UNPAID', NULL, 7, NULL, '2026-10-02 00:05:04', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_requests`
--

CREATE TABLE `purchase_requests` (
  `pr_id` int(11) NOT NULL,
  `pr_number` varchar(30) NOT NULL,
  `item_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `requested_qty` decimal(10,2) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `source` enum('AUTO_LOW_STOCK','MANUAL') NOT NULL DEFAULT 'MANUAL',
  `status` enum('Pending','Approved','Rejected','Converted') NOT NULL DEFAULT 'Pending',
  `requested_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `po_id` int(11) DEFAULT NULL COMMENT 'Filled in once converted to a Purchase Order',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_requests`
--

INSERT INTO `purchase_requests` (`pr_id`, `pr_number`, `item_id`, `branch_id`, `requested_qty`, `reason`, `source`, `status`, `requested_by`, `approved_by`, `po_id`, `created_at`, `updated_at`) VALUES
(4, 'PR-2026-0001', 1, 1, 2.00, 'Auto-generated: stock (2.00) at/under reorder level (2.00)', 'AUTO_LOW_STOCK', 'Converted', 7, 1, 6, '2026-10-01 15:03:41', '2026-10-01 15:11:49');

-- --------------------------------------------------------

--
-- Table structure for table `request_approvals`
--

CREATE TABLE `request_approvals` (
  `approval_id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `step_no` tinyint(4) NOT NULL,
  `step_label` varchar(60) NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `actor_name` varchar(150) NOT NULL,
  `actor_role` varchar(60) NOT NULL,
  `action` enum('SUBMITTED','FORWARDED','APPROVED','REJECTED','CANCELLED') NOT NULL,
  `remarks` varchar(2000) DEFAULT NULL,
  `signature_hash` varchar(64) DEFAULT NULL,
  `signature_data` mediumtext DEFAULT NULL,
  `acted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `request_forms`
--

CREATE TABLE `request_forms` (
  `request_id` int(11) NOT NULL,
  `request_no` varchar(30) NOT NULL,
  `request_type` enum('LEAVE','OVERTIME','GENERAL','DOCUMENT') NOT NULL,
  `employee_id` int(11) NOT NULL,
  `subject` varchar(200) NOT NULL,
  `details` text DEFAULT NULL,
  `status` enum('PENDING_MANAGER','PENDING_HR','PENDING_APPROVAL','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING_MANAGER',
  `leave_type_id` int(11) DEFAULT NULL,
  `leave_id` int(11) DEFAULT NULL,
  `date_from` date DEFAULT NULL,
  `date_to` date DEFAULT NULL,
  `total_days` int(11) DEFAULT NULL,
  `ot_date` date DEFAULT NULL,
  `ot_time_from` time DEFAULT NULL,
  `ot_time_to` time DEFAULT NULL,
  `ot_hours` decimal(5,2) DEFAULT NULL,
  `document_type` varchar(100) DEFAULT NULL,
  `copies` int(11) DEFAULT NULL,
  `needed_by` date DEFAULT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `filed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL,
  `role_code` varchar(30) NOT NULL,
  `role_name` varchar(60) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`role_id`, `role_code`, `role_name`, `description`, `created_at`) VALUES
(1, 'OWNER', 'Owner', 'Full access to every module in the system', '2026-09-07 10:22:42'),
(2, 'HR_MANAGER', 'HR Manager', 'Full HRMS management, approvals, payroll release', '2026-09-07 10:22:42'),
(3, 'HR_STAFF', 'HR Staff', 'Recruitment, records, attendance, leave, payroll preparation', '2026-09-07 10:22:42'),
(4, 'EMPLOYEE_MANAGER', 'Employee Manager', 'Department employee & attendance monitoring', '2026-09-07 10:22:42'),
(5, 'EMPLOYEE', 'Employee', 'Personal attendance, leave, and payslip self-service', '2026-09-07 10:22:42'),
(6, 'APPLICANT', 'Applicant', 'Job applicant / candidate portal access only', '2026-09-07 10:22:42'),
(7, 'CASHIER', 'Cashier', 'Point-of-sale operations', '2026-09-07 10:22:42'),
(8, 'INVENTORY_STAFF', 'Inventory Staff', 'Inventory and procurement management', '2026-09-07 10:22:42'),
(9, 'CUSTOMER', 'Customer', 'Self-order kiosk customer account - no staff/dashboard access of any kind', '2026-09-07 10:22:42'),
(10, 'FINANCE_STAFF', 'Finance Staff', 'Finance transactions, categories and financial reports', '2026-09-28 10:35:20');

-- --------------------------------------------------------

--
-- Table structure for table `stock_movements`
--

CREATE TABLE `stock_movements` (
  `movement_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `movement_type` enum('IN','OUT','ADJUSTMENT') NOT NULL,
  `reason` enum('Purchase Delivery','Usage','Wastage','Spoilage','Manual Stock In','Correction','Expired','POS Sale') NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `reference_type` enum('Delivery','Manual','Sale') DEFAULT 'Manual',
  `reference_id` int(11) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `supplier_id` int(11) NOT NULL,
  `supplier_name` varchar(150) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`supplier_id`, `supplier_name`, `contact_person`, `phone`, `email`, `address`, `status`, `created_at`) VALUES
(2, 'Wil', 'fred', '09326461651', 'wilfredo@gmail.com', 'basta', 'Active', '2026-10-01 22:32:47');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('allowed_resume_types', 'pdf,docx', '2026-09-07 10:22:43'),
('company_address', '123 Rizal Avenue, Poblacion, Morong, Rizal', '2026-09-14 17:34:26'),
('company_contact', '(02) 8123 4567 / 0917 123 4567', '2026-09-14 17:34:26'),
('company_name', 'Café Cuadro', '2026-09-14 17:34:26'),
('leave_approval_workflow', 'EMPLOYEE_MANAGER', '2026-09-07 10:22:43'),
('max_resume_size_mb', '5', '2026-09-07 10:22:43'),
('request_document_types', 'Certificate of Employment,Certificate of Employment with Compensation,Payslip Copy,Service Record,Company ID Replacement,Clearance Certificate,Other', '2026-09-28 07:25:20');

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE `tasks` (
  `task_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `priority` enum('LOW','MEDIUM','HIGH') DEFAULT 'MEDIUM',
  `status` enum('PENDING','IN_PROGRESS','COMPLETED','OVERDUE') DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `transaction_code` varchar(20) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `subtotal` decimal(10,2) DEFAULT 0.00,
  `discount` decimal(10,2) DEFAULT 0.00,
  `tax` decimal(10,2) DEFAULT 0.00,
  `total` decimal(10,2) DEFAULT 0.00,
  `payment_method` enum('cash','credit','debit') DEFAULT 'cash',
  `amount_paid` decimal(10,2) DEFAULT 0.00,
  `change_due` decimal(10,2) DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'completed',
  `source` varchar(10) NOT NULL DEFAULT 'pos',
  `order_type` varchar(10) DEFAULT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `guest_token` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `transaction_code`, `user_id`, `branch_id`, `subtotal`, `discount`, `tax`, `total`, `payment_method`, `amount_paid`, `change_due`, `status`, `source`, `order_type`, `customer_name`, `guest_token`, `created_at`) VALUES
(5, 'TXN26091218352250', 6, NULL, 11500.00, 0.00, 920.00, 12420.00, 'cash', 20000.00, 7580.00, 'completed', 'pos', NULL, NULL, NULL, '2026-09-12 02:35:22'),
(6, 'TXN26100118473127', 6, 1, 25.00, 0.00, 2.00, 27.00, 'cash', 1000.00, 973.00, 'completed', 'pos', NULL, NULL, NULL, '2026-10-01 10:47:31'),
(7, 'TXN26100118480334', 6, 1, 25.00, 0.00, 2.00, 27.00, 'cash', 1000.00, 973.00, 'completed', 'pos', NULL, NULL, NULL, '2026-10-01 10:48:03'),
(8, 'TXN26100118530499', 6, 1, 460.00, 0.00, 36.80, 496.80, 'cash', 500.15, 3.35, 'completed', 'pos', NULL, NULL, NULL, '2026-10-01 10:53:04'),
(9, 'TXN26100123433529', 6, 1, 1260.00, 0.00, 100.80, 1360.80, 'cash', 2000.33, 639.53, 'completed', 'pos', NULL, NULL, NULL, '2026-10-01 15:43:35');

-- --------------------------------------------------------

--
-- Table structure for table `transaction_items`
--

CREATE TABLE `transaction_items` (
  `id` int(11) NOT NULL,
  `transaction_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(150) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `subtotal` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transaction_items`
--

INSERT INTO `transaction_items` (`id`, `transaction_id`, `product_id`, `product_name`, `price`, `quantity`, `subtotal`) VALUES
(9, 5, 27, 'Blueberry Muffin', 115.00, 100, 11500.00),
(10, 6, 33, 'Almond Milk Upgrade', 25.00, 1, 25.00),
(11, 7, 33, 'Almond Milk Upgrade', 25.00, 1, 25.00),
(12, 8, 33, 'Almond Milk Upgrade', 25.00, 4, 100.00),
(13, 8, 2, 'Americano', 120.00, 3, 360.00),
(14, 9, 2, 'Americano', 120.00, 8, 960.00),
(15, 9, 33, 'Almond Milk Upgrade', 25.00, 4, 100.00),
(16, 9, 29, 'Banana Bread Slice', 100.00, 2, 200.00);

-- --------------------------------------------------------

--
-- Table structure for table `units`
--

CREATE TABLE `units` (
  `unit_id` int(11) NOT NULL,
  `unit_name` varchar(50) NOT NULL,
  `unit_symbol` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `units`
--

INSERT INTO `units` (`unit_id`, `unit_name`, `unit_symbol`) VALUES
(1, 'Kilogram', 'kg'),
(2, 'Gram', 'g'),
(3, 'Liter', 'L'),
(4, 'Milliliter', 'mL'),
(5, 'Piece', 'pcs'),
(6, 'Box', 'box'),
(7, 'Pack', 'pack'),
(8, 'Bottle', 'bottle'),
(9, 'Pieces', 'pi');

-- --------------------------------------------------------

--
-- Table structure for table `units_old`
--

CREATE TABLE `units_old` (
  `unit_id` int(11) NOT NULL,
  `unit_name` varchar(50) NOT NULL,
  `unit_symbol` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `units_old`
--

INSERT INTO `units_old` (`unit_id`, `unit_name`, `unit_symbol`) VALUES
(1, 'Kilogram', 'kg'),
(2, 'Gram', 'g'),
(3, 'Liter', 'L'),
(4, 'Milliliter', 'mL'),
(5, 'Piece', 'pcs'),
(6, 'Box', 'box'),
(7, 'Pack', 'pack'),
(8, 'Bottle', 'bottle'),
(1, 'Kilogram', 'kg'),
(2, 'Gram', 'g'),
(3, 'Liter', 'L'),
(4, 'Milliliter', 'mL'),
(5, 'Piece', 'pcs'),
(6, 'Box', 'box'),
(7, 'Pack', 'pack'),
(8, 'Bottle', 'bottle');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL DEFAULT '',
  `phone` varchar(30) DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE','SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
  `profile_photo` varchar(255) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `token_expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `role_id`, `username`, `email`, `password_hash`, `first_name`, `last_name`, `phone`, `status`, `profile_photo`, `last_login`, `reset_token`, `token_expires_at`, `created_at`, `updated_at`) VALUES
(1, 1, 'owner', 'owner@cafe.test', '$2y$10$CfLKzjLfxW532S5tpHmaxOfD7ejKG2iHxiZTKVvqSC4U9C75BkfhW', 'Alex', 'Santos', NULL, 'ACTIVE', NULL, '2026-10-02 00:09:59', NULL, NULL, '2026-09-07 10:22:42', '2026-10-01 16:09:59'),
(2, 2, 'hrmanager', 'hrmanager@cafe.test', '$2y$10$CfLKzjLfxW532S5tpHmaxOfD7ejKG2iHxiZTKVvqSC4U9C75BkfhW', 'Maria', 'Reyes', NULL, 'ACTIVE', NULL, '2026-10-01 22:33:31', NULL, NULL, '2026-09-07 10:22:42', '2026-10-01 14:33:31'),
(3, 3, 'hrstaff', 'hrstaff@cafe.test', '$2y$10$CfLKzjLfxW532S5tpHmaxOfD7ejKG2iHxiZTKVvqSC4U9C75BkfhW', 'Jasmine', 'Cruz', NULL, 'ACTIVE', NULL, '2026-10-01 21:04:08', NULL, NULL, '2026-09-07 10:22:42', '2026-10-01 13:04:08'),
(4, 4, 'empmanager', 'empmanager@cafe.test', '$2y$10$CfLKzjLfxW532S5tpHmaxOfD7ejKG2iHxiZTKVvqSC4U9C75BkfhW', 'Carlo', 'Dizon', NULL, 'ACTIVE', NULL, '2026-10-01 20:47:46', NULL, NULL, '2026-09-07 10:22:42', '2026-10-01 12:47:46'),
(6, 7, 'cashier1', 'cashier1@cafe.test', '$2y$10$CfLKzjLfxW532S5tpHmaxOfD7ejKG2iHxiZTKVvqSC4U9C75BkfhW', 'Nico', 'Ramos', NULL, 'ACTIVE', NULL, '2026-10-01 23:43:07', NULL, NULL, '2026-09-07 10:22:42', '2026-10-01 15:43:07'),
(7, 8, 'invstaff1', 'invstaff1@cafe.test', '$2y$10$CfLKzjLfxW532S5tpHmaxOfD7ejKG2iHxiZTKVvqSC4U9C75BkfhW', 'Grace', 'Manalo', NULL, 'ACTIVE', NULL, '2026-10-02 08:35:10', NULL, NULL, '2026-09-07 10:22:42', '2026-10-02 00:35:10'),
(12, 10, NULL, 'jas@cafe.test', '$2y$10$TI8yQniOPCCKF8NMlsxqceRSTVqivFHBkP66OEY/0UU58SOe0xl.O', 'jas', 'mine', '09325164287', 'ACTIVE', NULL, '2026-10-02 00:06:27', NULL, NULL, '2026-10-01 15:22:33', '2026-10-01 16:06:27');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`log_id`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`announcement_id`),
  ADD KEY `fk_announcement_poster` (`posted_by`),
  ADD KEY `fk_announcement_dept` (`target_department`);

--
-- Indexes for table `applicants`
--
ALTER TABLE `applicants`
  ADD PRIMARY KEY (`applicant_id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `idx_applicant_email` (`email`),
  ADD KEY `idx_applicant_status` (`status`);

--
-- Indexes for table `application_documents`
--
ALTER TABLE `application_documents`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `fk_appdoc_application` (`application_id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`attendance_id`),
  ADD UNIQUE KEY `uq_emp_date` (`employee_id`,`attendance_date`),
  ADD KEY `idx_attendance_date` (`attendance_date`);

--
-- Indexes for table `attendance_deductions`
--
ALTER TABLE `attendance_deductions`
  ADD PRIMARY KEY (`deduction_id`),
  ADD KEY `fk_deduction_attendance` (`attendance_id`),
  ADD KEY `fk_deduction_employee` (`employee_id`),
  ADD KEY `idx_deduction_payroll` (`included_in_payroll_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `fk_audit_user` (`user_id`),
  ADD KEY `idx_audit_created` (`created_at`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`branch_id`),
  ADD UNIQUE KEY `branch_name` (`branch_name`);

--
-- Indexes for table `branch_inventory`
--
ALTER TABLE `branch_inventory`
  ADD PRIMARY KEY (`branch_inventory_id`),
  ADD UNIQUE KEY `uq_branch_product` (`branch_id`,`product_id`),
  ADD KEY `fk_bi_product` (`product_id`);

--
-- Indexes for table `branch_item_inventory`
--
ALTER TABLE `branch_item_inventory`
  ADD PRIMARY KEY (`branch_item_inventory_id`),
  ADD UNIQUE KEY `uq_branch_item` (`branch_id`,`item_id`),
  ADD KEY `idx_branch_item_item` (`item_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`category_id`);

--
-- Indexes for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD PRIMARY KEY (`delivery_id`),
  ADD UNIQUE KEY `uq_deliveries_number` (`delivery_number`),
  ADD KEY `idx_deliveries_po` (`po_id`);

--
-- Indexes for table `delivery_items`
--
ALTER TABLE `delivery_items`
  ADD PRIMARY KEY (`delivery_item_id`),
  ADD KEY `idx_delivery_items_delivery` (`delivery_id`),
  ADD KEY `idx_delivery_items_po_item` (`po_item_id`),
  ADD KEY `idx_delivery_items_item` (`item_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD UNIQUE KEY `department_name` (`department_name`);

--
-- Indexes for table `document_sequences`
--
ALTER TABLE `document_sequences`
  ADD PRIMARY KEY (`doc_type`,`year`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`employee_id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `employee_code` (`employee_code`),
  ADD KEY `fk_emp_manager` (`reports_to`),
  ADD KEY `idx_emp_department` (`department_id`),
  ADD KEY `idx_emp_branch` (`branch_id`);

--
-- Indexes for table `employment_contracts`
--
ALTER TABLE `employment_contracts`
  ADD PRIMARY KEY (`contract_id`),
  ADD UNIQUE KEY `uq_contract_no` (`contract_no`),
  ADD UNIQUE KEY `uq_contract_application` (`application_id`),
  ADD KEY `idx_contract_employee` (`employee_id`);

--
-- Indexes for table `finance_categories`
--
ALTER TABLE `finance_categories`
  ADD PRIMARY KEY (`fin_category_id`);

--
-- Indexes for table `finance_transactions`
--
ALTER TABLE `finance_transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `fin_category_id` (`fin_category_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `half_day_requests`
--
ALTER TABLE `half_day_requests`
  ADD PRIMARY KEY (`half_day_id`),
  ADD UNIQUE KEY `uq_emp_halfday_date` (`employee_id`,`request_date`),
  ADD KEY `fk_halfday_reviewer` (`reviewed_by`),
  ADD KEY `idx_halfday_status` (`status`);

--
-- Indexes for table `interviews`
--
ALTER TABLE `interviews`
  ADD PRIMARY KEY (`interview_id`),
  ADD KEY `fk_interview_application` (`application_id`),
  ADD KEY `fk_interview_interviewer` (`interviewer_id`),
  ADD KEY `fk_interview_creator` (`created_by`),
  ADD KEY `idx_interview_schedule` (`schedule_date`),
  ADD KEY `idx_interview_status` (`status`),
  ADD KEY `fk_interviews_evaluated_by` (`evaluated_by`);

--
-- Indexes for table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`item_id`),
  ADD UNIQUE KEY `item_code` (`item_code`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `unit_id` (`unit_id`);

--
-- Indexes for table `item_pos_mappings`
--
ALTER TABLE `item_pos_mappings`
  ADD PRIMARY KEY (`item_id`),
  ADD UNIQUE KEY `uq_item_pos_product` (`product_id`),
  ADD KEY `fk_item_pos_created_by` (`created_by`);

--
-- Indexes for table `job_applications`
--
ALTER TABLE `job_applications`
  ADD PRIMARY KEY (`application_id`),
  ADD UNIQUE KEY `reference_code` (`reference_code`),
  ADD UNIQUE KEY `uq_applicant_job` (`applicant_id`,`job_id`),
  ADD KEY `idx_application_status` (`status`),
  ADD KEY `idx_application_job` (`job_id`),
  ADD KEY `fk_ja_hiring_approved_by` (`hiring_approved_by`);

--
-- Indexes for table `job_offers`
--
ALTER TABLE `job_offers`
  ADD PRIMARY KEY (`offer_id`),
  ADD UNIQUE KEY `application_id` (`application_id`),
  ADD KEY `fk_offer_creator` (`created_by`),
  ADD KEY `idx_offer_status` (`status`);

--
-- Indexes for table `job_vacancies`
--
ALTER TABLE `job_vacancies`
  ADD PRIMARY KEY (`job_id`),
  ADD KEY `fk_job_department` (`department_id`),
  ADD KEY `fk_job_branch` (`branch_id`),
  ADD KEY `fk_job_poster` (`posted_by`),
  ADD KEY `idx_job_status` (`status`),
  ADD KEY `fk_job_target_role` (`target_role_id`);

--
-- Indexes for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD PRIMARY KEY (`leave_id`),
  ADD KEY `fk_leave_employee` (`employee_id`),
  ADD KEY `fk_leave_type` (`leave_type_id`),
  ADD KEY `fk_leave_manager_reviewer` (`manager_reviewed_by`),
  ADD KEY `fk_leave_hr_processor` (`hr_processed_by`),
  ADD KEY `fk_leave_reviewer` (`reviewed_by`),
  ADD KEY `idx_leave_status` (`status`);

--
-- Indexes for table `leave_types`
--
ALTER TABLE `leave_types`
  ADD PRIMARY KEY (`leave_type_id`),
  ADD UNIQUE KEY `type_name` (`type_name`);

--
-- Indexes for table `low_stock_notifications`
--
ALTER TABLE `low_stock_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `resolved_at` (`resolved_at`);

--
-- Indexes for table `menu_categories`
--
ALTER TABLE `menu_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_notif_user_read` (`user_id`,`is_read`);

--
-- Indexes for table `password_reset_requests`
--
ALTER TABLE `password_reset_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `fk_password_reset_user` (`user_id`),
  ADD KEY `fk_password_reset_reviewer` (`reviewed_by`),
  ADD KEY `idx_reset_status` (`status`),
  ADD KEY `idx_reset_unlock` (`unlock_at`);

--
-- Indexes for table `payroll`
--
ALTER TABLE `payroll`
  ADD PRIMARY KEY (`payroll_id`),
  ADD UNIQUE KEY `uq_payroll_employee_period` (`employee_id`,`pay_period_start`,`pay_period_end`),
  ADD UNIQUE KEY `uq_payroll_finance_transaction` (`finance_transaction_id`),
  ADD KEY `fk_payroll_employee` (`employee_id`),
  ADD KEY `fk_payroll_generator` (`generated_by`),
  ADD KEY `fk_payroll_approver` (`approved_by`),
  ADD KEY `fk_payroll_releaser` (`released_by`),
  ADD KEY `idx_payroll_period` (`pay_period_start`,`pay_period_end`),
  ADD KEY `idx_payroll_status` (`status`),
  ADD KEY `idx_payroll_branch_period` (`branch_id`,`pay_period_start`,`pay_period_end`,`status`);

--
-- Indexes for table `performance_evaluations`
--
ALTER TABLE `performance_evaluations`
  ADD PRIMARY KEY (`evaluation_id`),
  ADD KEY `fk_eval_employee` (`employee_id`),
  ADD KEY `fk_eval_evaluator` (`evaluator_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `product_ingredients`
--
ALTER TABLE `product_ingredients`
  ADD PRIMARY KEY (`product_ingredient_id`),
  ADD UNIQUE KEY `uq_product_item` (`product_id`,`item_id`),
  ADD KEY `fk_pi_item` (`item_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`po_id`),
  ADD UNIQUE KEY `uq_purchase_orders_number` (`po_number`),
  ADD KEY `idx_purchase_orders_branch` (`branch_id`);

--
-- Indexes for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD PRIMARY KEY (`po_item_id`),
  ADD KEY `idx_purchase_order_items_po` (`po_id`),
  ADD KEY `idx_purchase_order_items_item` (`item_id`);

--
-- Indexes for table `purchase_payables`
--
ALTER TABLE `purchase_payables`
  ADD PRIMARY KEY (`payable_id`),
  ADD UNIQUE KEY `uq_payable_delivery` (`delivery_id`),
  ADD UNIQUE KEY `uq_payable_finance_transaction` (`finance_transaction_id`),
  ADD KEY `idx_payables_branch_status` (`branch_id`,`status`),
  ADD KEY `idx_payables_po` (`po_id`),
  ADD KEY `idx_payables_supplier` (`supplier_id`),
  ADD KEY `fk_payable_received_by` (`received_by`),
  ADD KEY `fk_payable_paid_by` (`paid_by`);

--
-- Indexes for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  ADD PRIMARY KEY (`pr_id`),
  ADD UNIQUE KEY `uq_pr_number` (`pr_number`),
  ADD KEY `fk_pr_item` (`item_id`),
  ADD KEY `fk_pr_requested_by` (`requested_by`),
  ADD KEY `fk_pr_approved_by` (`approved_by`),
  ADD KEY `fk_pr_po` (`po_id`),
  ADD KEY `idx_purchase_requests_branch` (`branch_id`);

--
-- Indexes for table `request_approvals`
--
ALTER TABLE `request_approvals`
  ADD PRIMARY KEY (`approval_id`),
  ADD KEY `idx_approval_request` (`request_id`),
  ADD KEY `idx_approval_step` (`request_id`,`step_no`);

--
-- Indexes for table `request_forms`
--
ALTER TABLE `request_forms`
  ADD PRIMARY KEY (`request_id`),
  ADD UNIQUE KEY `uq_request_no` (`request_no`),
  ADD KEY `idx_request_employee` (`employee_id`),
  ADD KEY `idx_request_status` (`status`),
  ADD KEY `idx_request_type` (`request_type`),
  ADD KEY `idx_request_leave` (`leave_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_id`),
  ADD UNIQUE KEY `role_code` (`role_code`);

--
-- Indexes for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD PRIMARY KEY (`movement_id`),
  ADD KEY `idx_stock_movements_branch` (`branch_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplier_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`task_id`),
  ADD KEY `fk_task_employee` (`employee_id`),
  ADD KEY `fk_task_assigner` (`assigned_by`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transaction_code` (`transaction_code`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_guest_token` (`guest_token`),
  ADD KEY `fk_txn_branch` (`branch_id`);

--
-- Indexes for table `transaction_items`
--
ALTER TABLE `transaction_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transaction_id` (`transaction_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `units`
--
ALTER TABLE `units`
  ADD PRIMARY KEY (`unit_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD KEY `idx_users_role` (`role_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `announcement_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `applicants`
--
ALTER TABLE `applicants`
  MODIFY `applicant_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `application_documents`
--
ALTER TABLE `application_documents`
  MODIFY `document_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `attendance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `attendance_deductions`
--
ALTER TABLE `attendance_deductions`
  MODIFY `deduction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=259;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `branch_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `branch_inventory`
--
ALTER TABLE `branch_inventory`
  MODIFY `branch_inventory_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT for table `branch_item_inventory`
--
ALTER TABLE `branch_item_inventory`
  MODIFY `branch_item_inventory_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `delivery_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `delivery_items`
--
ALTER TABLE `delivery_items`
  MODIFY `delivery_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `employee_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `employment_contracts`
--
ALTER TABLE `employment_contracts`
  MODIFY `contract_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finance_categories`
--
ALTER TABLE `finance_categories`
  MODIFY `fin_category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `finance_transactions`
--
ALTER TABLE `finance_transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `half_day_requests`
--
ALTER TABLE `half_day_requests`
  MODIFY `half_day_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `interviews`
--
ALTER TABLE `interviews`
  MODIFY `interview_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `items`
--
ALTER TABLE `items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `job_applications`
--
ALTER TABLE `job_applications`
  MODIFY `application_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `job_offers`
--
ALTER TABLE `job_offers`
  MODIFY `offer_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `job_vacancies`
--
ALTER TABLE `job_vacancies`
  MODIFY `job_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `leave_requests`
--
ALTER TABLE `leave_requests`
  MODIFY `leave_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_types`
--
ALTER TABLE `leave_types`
  MODIFY `leave_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `low_stock_notifications`
--
ALTER TABLE `low_stock_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `menu_categories`
--
ALTER TABLE `menu_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `password_reset_requests`
--
ALTER TABLE `password_reset_requests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll`
--
ALTER TABLE `payroll`
  MODIFY `payroll_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `performance_evaluations`
--
ALTER TABLE `performance_evaluations`
  MODIFY `evaluation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `product_ingredients`
--
ALTER TABLE `product_ingredients`
  MODIFY `product_ingredient_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `po_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  MODIFY `po_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `purchase_payables`
--
ALTER TABLE `purchase_payables`
  MODIFY `payable_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  MODIFY `pr_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `request_approvals`
--
ALTER TABLE `request_approvals`
  MODIFY `approval_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `request_forms`
--
ALTER TABLE `request_forms`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `stock_movements`
--
ALTER TABLE `stock_movements`
  MODIFY `movement_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `task_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `transaction_items`
--
ALTER TABLE `transaction_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
  MODIFY `unit_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `fk_announcement_dept` FOREIGN KEY (`target_department`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_announcement_poster` FOREIGN KEY (`posted_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `applicants`
--
ALTER TABLE `applicants`
  ADD CONSTRAINT `fk_applicant_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `application_documents`
--
ALTER TABLE `application_documents`
  ADD CONSTRAINT `fk_appdoc_application` FOREIGN KEY (`application_id`) REFERENCES `job_applications` (`application_id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_attendance_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance_deductions`
--
ALTER TABLE `attendance_deductions`
  ADD CONSTRAINT `fk_deduction_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`attendance_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_deduction_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `branch_inventory`
--
ALTER TABLE `branch_inventory`
  ADD CONSTRAINT `fk_bi_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_bi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `branch_item_inventory`
--
ALTER TABLE `branch_item_inventory`
  ADD CONSTRAINT `fk_branch_item_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_branch_item_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON DELETE CASCADE;

--
-- Constraints for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD CONSTRAINT `fk_delivery_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`);

--
-- Constraints for table `delivery_items`
--
ALTER TABLE `delivery_items`
  ADD CONSTRAINT `fk_delivery_item_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`delivery_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_delivery_item_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`),
  ADD CONSTRAINT `fk_delivery_item_po_item` FOREIGN KEY (`po_item_id`) REFERENCES `purchase_order_items` (`po_item_id`);

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `fk_emp_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_emp_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_emp_manager` FOREIGN KEY (`reports_to`) REFERENCES `employees` (`employee_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_emp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `half_day_requests`
--
ALTER TABLE `half_day_requests`
  ADD CONSTRAINT `fk_halfday_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_halfday_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `interviews`
--
ALTER TABLE `interviews`
  ADD CONSTRAINT `fk_interview_application` FOREIGN KEY (`application_id`) REFERENCES `job_applications` (`application_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_interview_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_interview_interviewer` FOREIGN KEY (`interviewer_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_interviews_evaluated_by` FOREIGN KEY (`evaluated_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `item_pos_mappings`
--
ALTER TABLE `item_pos_mappings`
  ADD CONSTRAINT `fk_item_pos_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_item_pos_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_item_pos_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `job_applications`
--
ALTER TABLE `job_applications`
  ADD CONSTRAINT `fk_application_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`applicant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_application_job` FOREIGN KEY (`job_id`) REFERENCES `job_vacancies` (`job_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ja_hiring_approved_by` FOREIGN KEY (`hiring_approved_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `job_offers`
--
ALTER TABLE `job_offers`
  ADD CONSTRAINT `fk_offer_application` FOREIGN KEY (`application_id`) REFERENCES `job_applications` (`application_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_offer_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `job_vacancies`
--
ALTER TABLE `job_vacancies`
  ADD CONSTRAINT `fk_job_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_job_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_job_poster` FOREIGN KEY (`posted_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_job_target_role` FOREIGN KEY (`target_role_id`) REFERENCES `roles` (`role_id`) ON DELETE SET NULL;

--
-- Constraints for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD CONSTRAINT `fk_leave_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_leave_hr_processor` FOREIGN KEY (`hr_processed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_leave_manager_reviewer` FOREIGN KEY (`manager_reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_leave_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_leave_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`leave_type_id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `password_reset_requests`
--
ALTER TABLE `password_reset_requests`
  ADD CONSTRAINT `fk_password_reset_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_password_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `payroll`
--
ALTER TABLE `payroll`
  ADD CONSTRAINT `fk_payroll_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_payroll_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `fk_payroll_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_finance_transaction` FOREIGN KEY (`finance_transaction_id`) REFERENCES `finance_transactions` (`transaction_id`),
  ADD CONSTRAINT `fk_payroll_generator` FOREIGN KEY (`generated_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_payroll_releaser` FOREIGN KEY (`released_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `performance_evaluations`
--
ALTER TABLE `performance_evaluations`
  ADD CONSTRAINT `fk_eval_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_eval_evaluator` FOREIGN KEY (`evaluator_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_menu_cat_fk` FOREIGN KEY (`category_id`) REFERENCES `menu_categories` (`id`);

--
-- Constraints for table `product_ingredients`
--
ALTER TABLE `product_ingredients`
  ADD CONSTRAINT `fk_pi_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `fk_purchase_order_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD CONSTRAINT `fk_poi_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`),
  ADD CONSTRAINT `fk_poi_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`);

--
-- Constraints for table `purchase_payables`
--
ALTER TABLE `purchase_payables`
  ADD CONSTRAINT `fk_payable_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `fk_payable_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`delivery_id`),
  ADD CONSTRAINT `fk_payable_paid_by` FOREIGN KEY (`paid_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_payable_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`),
  ADD CONSTRAINT `fk_payable_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_payable_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`),
  ADD CONSTRAINT `fk_payable_transaction` FOREIGN KEY (`finance_transaction_id`) REFERENCES `finance_transactions` (`transaction_id`);

--
-- Constraints for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  ADD CONSTRAINT `fk_pr_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_pr_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`),
  ADD CONSTRAINT `fk_pr_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`),
  ADD CONSTRAINT `fk_pr_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_purchase_request_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD CONSTRAINT `fk_stock_movement_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `fk_task_assigner` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_task_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `fk_txn_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `transaction_items`
--
ALTER TABLE `transaction_items`
  ADD CONSTRAINT `transaction_items_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`),
  ADD CONSTRAINT `transaction_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
