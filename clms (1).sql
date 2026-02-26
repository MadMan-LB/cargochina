-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 26, 2026 at 02:55 PM
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
-- Database: `clms`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(10) UNSIGNED NOT NULL,
  `action` varchar(50) NOT NULL,
  `old_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_value`)),
  `new_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_value`)),
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `containers`
--

CREATE TABLE `containers` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(50) NOT NULL,
  `max_cbm` decimal(10,4) NOT NULL,
  `max_weight` decimal(10,4) NOT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `contacts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`contacts`)),
  `addresses` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`addresses`)),
  `payment_terms` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `code`, `name`, `phone`, `address`, `contacts`, `addresses`, `payment_terms`, `created_at`, `updated_at`) VALUES
(1, 'hsynz', 'housseinalzekra', NULL, NULL, NULL, NULL, 'cash', '2026-02-19 11:37:29', '2026-02-19 11:37:29'),
(2, 'TST', 'Test', NULL, NULL, NULL, NULL, NULL, '2026-02-19 12:47:52', '2026-02-19 12:47:52');

-- --------------------------------------------------------

--
-- Table structure for table `customer_confirmations`
--

CREATE TABLE `customer_confirmations` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `confirmed_by` int(10) UNSIGNED DEFAULT NULL,
  `confirmed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `accepted_actuals` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`accepted_actuals`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_deposits`
--

CREATE TABLE `customer_deposits` (
  `id` int(10) UNSIGNED NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(12,4) NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'USD',
  `payment_method` varchar(50) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `type` varchar(50) NOT NULL,
  `channel` varchar(20) NOT NULL DEFAULT 'dashboard',
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_delivery_log`
--

CREATE TABLE `notification_delivery_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `notification_id` int(10) UNSIGNED NOT NULL,
  `channel` varchar(20) NOT NULL,
  `payload_hash` varchar(64) DEFAULT NULL,
  `status` varchar(20) NOT NULL,
  `attempts` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `last_error` text DEFAULT NULL,
  `external_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(10) UNSIGNED NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `supplier_id` int(10) UNSIGNED NOT NULL,
  `expected_ready_date` date NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'USD',
  `status` varchar(50) NOT NULL DEFAULT 'Draft',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `customer_id`, `supplier_id`, `expected_ready_date`, `currency`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 2, 4, '2026-02-19', 'USD', 'AssignedToContainer', NULL, '2026-02-19 12:47:52', '2026-02-19 12:47:52'),
(2, 1, 1, '2026-02-20', 'USD', 'Approved', 1, '2026-02-20 13:53:02', '2026-02-20 13:53:02'),
(3, 1, 1, '2026-02-20', 'USD', 'Approved', 1, '2026-02-20 13:53:35', '2026-02-20 13:53:35');

-- --------------------------------------------------------

--
-- Table structure for table `order_attachments`
--

CREATE TABLE `order_attachments` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `type` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED DEFAULT NULL,
  `item_no` varchar(100) DEFAULT NULL,
  `shipping_code` varchar(100) DEFAULT NULL,
  `cartons` int(10) UNSIGNED DEFAULT NULL,
  `qty_per_carton` decimal(12,4) DEFAULT NULL,
  `unit_price` decimal(12,4) DEFAULT NULL,
  `total_amount` decimal(12,4) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `image_paths` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`image_paths`)),
  `quantity` decimal(12,4) NOT NULL,
  `unit` varchar(20) NOT NULL,
  `declared_cbm` decimal(10,4) NOT NULL,
  `declared_weight` decimal(10,4) NOT NULL,
  `item_length` decimal(10,4) DEFAULT NULL,
  `item_width` decimal(10,4) DEFAULT NULL,
  `item_height` decimal(10,4) DEFAULT NULL,
  `description_cn` varchar(500) DEFAULT NULL,
  `description_en` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `item_no`, `shipping_code`, `cartons`, `qty_per_carton`, `unit_price`, `total_amount`, `notes`, `image_paths`, `quantity`, `unit`, `declared_cbm`, `declared_weight`, `item_length`, `item_width`, `item_height`, `description_cn`, `description_en`, `created_at`) VALUES
(1, 2, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 10.0000, 'cartons', 2.0000, 50.0000, NULL, NULL, NULL, NULL, 'Item1', '2026-02-20 13:53:02'),
(2, 2, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5.0000, 'cartons', 1.0000, 25.0000, NULL, NULL, NULL, NULL, 'Item2', '2026-02-20 13:53:02'),
(3, 3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 10.0000, 'cartons', 2.0000, 50.0000, NULL, NULL, NULL, NULL, 'Item1', '2026-02-20 13:53:35'),
(4, 3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5.0000, 'cartons', 1.0000, 25.0000, NULL, NULL, NULL, NULL, 'Item2', '2026-02-20 13:53:35');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(10) UNSIGNED NOT NULL,
  `supplier_id` int(10) UNSIGNED DEFAULT NULL,
  `cbm` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `weight` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `length_cm` decimal(10,4) DEFAULT NULL,
  `width_cm` decimal(10,4) DEFAULT NULL,
  `height_cm` decimal(10,4) DEFAULT NULL,
  `packaging` varchar(100) DEFAULT NULL,
  `hs_code` varchar(50) DEFAULT NULL,
  `description_cn` varchar(500) DEFAULT NULL,
  `description_en` varchar(500) DEFAULT NULL,
  `image_paths` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`image_paths`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `supplier_id`, `cbm`, `weight`, `length_cm`, `width_cm`, `height_cm`, `packaging`, `hs_code`, `description_cn`, `description_en`, `image_paths`, `created_at`, `updated_at`) VALUES
(1, 1, 1.5000, 2000.0000, NULL, NULL, NULL, 'wfwefw', '1511', 'fewfwef', 'wfewfwef', NULL, '2026-02-19 11:38:16', '2026-02-19 11:38:16');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `code`, `name`, `created_at`) VALUES
(1, 'ChinaEmployee', 'China Employee', '2026-02-19 11:31:27'),
(2, 'ChinaAdmin', 'China Admin', '2026-02-19 11:31:27'),
(3, 'WarehouseStaff', 'Warehouse Staff', '2026-02-19 11:31:27'),
(4, 'LebanonAdmin', 'Lebanon Admin', '2026-02-19 11:31:27'),
(5, 'SuperAdmin', 'Super Admin', '2026-02-19 11:31:27');

-- --------------------------------------------------------

--
-- Table structure for table `shipment_drafts`
--

CREATE TABLE `shipment_drafts` (
  `id` int(10) UNSIGNED NOT NULL,
  `container_id` int(10) UNSIGNED DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `shipment_drafts`
--

INSERT INTO `shipment_drafts` (`id`, `container_id`, `status`, `created_at`) VALUES
(1, NULL, 'draft', '2026-02-19 11:35:38'),
(13, NULL, 'draft', '2026-02-25 12:49:36');

-- --------------------------------------------------------

--
-- Table structure for table `shipment_draft_orders`
--

CREATE TABLE `shipment_draft_orders` (
  `shipment_draft_id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(50) NOT NULL,
  `store_id` varchar(100) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `contacts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`contacts`)),
  `factory_location` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `additional_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`additional_ids`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `code`, `store_id`, `name`, `contacts`, `factory_location`, `notes`, `phone`, `additional_ids`, `created_at`, `updated_at`) VALUES
(1, 'hs_supplier', NULL, 'hsupplkier', NULL, 'china idid', 'fdewfwef', NULL, NULL, '2026-02-19 11:37:57', '2026-02-19 11:37:57'),
(4, 'TST', NULL, 'Test', NULL, NULL, NULL, NULL, NULL, '2026-02-19 12:47:52', '2026-02-19 12:47:52');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_interactions`
--

CREATE TABLE `supplier_interactions` (
  `id` int(10) UNSIGNED NOT NULL,
  `supplier_id` int(10) UNSIGNED NOT NULL,
  `interaction_type` varchar(50) NOT NULL DEFAULT 'visit',
  `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`content`)),
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_payments`
--

CREATE TABLE `supplier_payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `supplier_id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED DEFAULT NULL,
  `amount` decimal(12,4) NOT NULL,
  `invoice_amount` decimal(12,4) DEFAULT NULL,
  `discount_amount` decimal(12,4) DEFAULT 0.0000,
  `marked_full_payment` tinyint(1) NOT NULL DEFAULT 0,
  `marked_by` int(10) UNSIGNED DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'USD',
  `payment_type` varchar(20) NOT NULL DEFAULT 'partial',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `system_config`
--

CREATE TABLE `system_config` (
  `key_name` varchar(100) NOT NULL,
  `key_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_config`
--

INSERT INTO `system_config` (`key_name`, `key_value`, `updated_at`) VALUES
('CONFIRMATION_REQUIRED', 'always-on-arrival', '2026-02-21 10:31:29'),
('CUSTOMER_PHOTO_VISIBILITY', 'internal-only', '2026-02-19 11:32:11'),
('EMAIL_FROM_ADDRESS', '', '2026-02-21 10:31:29'),
('EMAIL_FROM_NAME', 'CLMS', '2026-02-20 12:10:17'),
('ITEM_LEVEL_RECEIVING_ENABLED', '0', '2026-02-20 12:10:17'),
('MIN_PHOTOS_PER_ITEM', '1', '2026-02-19 12:39:11'),
('NOTIFICATION_CHANNELS', 'dashboard', '2026-02-19 12:41:47'),
('NOTIFICATION_MAX_ATTEMPTS', '5', '2026-02-21 10:31:29'),
('NOTIFICATION_RETRY_SECONDS', '10', '2026-02-21 10:31:29'),
('PHOTO_EVIDENCE_PER_ITEM', '1', '2026-02-21 10:31:29'),
('TRACKING_API_BASE_URL', '', '2026-02-19 12:46:52'),
('TRACKING_API_PATH', '/api/import/clms', '2026-02-19 12:46:52'),
('TRACKING_API_RETRY_BACKOFF_MS', '800', '2026-02-19 12:46:52'),
('TRACKING_API_RETRY_COUNT', '3', '2026-02-19 12:46:52'),
('TRACKING_API_TIMEOUT_SEC', '15', '2026-02-19 12:46:52'),
('TRACKING_API_TOKEN', '', '2026-02-19 12:46:52'),
('TRACKING_PUSH_DRY_RUN', '1', '2026-02-19 12:46:52'),
('TRACKING_PUSH_ENABLED', '0', '2026-02-19 12:46:52'),
('VARIANCE_THRESHOLD_ABS_CBM', '0', '2026-02-21 10:31:29'),
('VARIANCE_THRESHOLD_PERCENT', '0', '2026-02-21 10:31:29'),
('WHATSAPP_API_TOKEN', '', '2026-02-20 13:54:06'),
('WHATSAPP_API_URL', '', '2026-02-20 12:10:17'),
('WHATSAPP_PROVIDER', 'generic', '2026-02-20 12:38:30'),
('WHATSAPP_TWILIO_ACCOUNT_SID', '', '2026-02-20 12:38:30'),
('WHATSAPP_TWILIO_AUTH_TOKEN', '', '2026-02-20 12:38:30'),
('WHATSAPP_TWILIO_FROM', '', '2026-02-20 12:38:30'),
('WHATSAPP_TWILIO_TO', '', '2026-02-20 12:38:30');

-- --------------------------------------------------------

--
-- Table structure for table `tracking_push_log`
--

CREATE TABLE `tracking_push_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `entity_type` varchar(50) NOT NULL DEFAULT 'shipment_draft',
  `entity_id` int(10) UNSIGNED NOT NULL,
  `idempotency_key` varchar(64) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `request_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request_payload`)),
  `response_code` int(11) DEFAULT NULL,
  `response_body` text DEFAULT NULL,
  `external_id` varchar(255) DEFAULT NULL,
  `attempt_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tracking_push_log`
--

INSERT INTO `tracking_push_log` (`id`, `entity_type`, `entity_id`, `idempotency_key`, `status`, `request_payload`, `response_code`, `response_body`, `external_id`, `attempt_count`, `last_error`, `created_at`, `updated_at`) VALUES
(3, 'shipment_draft', 1, 'clms-draft-1', 'dry_run', '{\"header\":{\"shipment_draft_id\":1,\"container_id\":null,\"container_code\":null,\"order_ids\":[]},\"items\":[],\"documents\":[],\"pushed_at\":\"2026-02-20T10:41:29+01:00\"}', NULL, NULL, NULL, 0, 'Dry-run or disabled; payload logged only', '2026-02-20 09:41:29', '2026-02-20 13:21:22');

-- --------------------------------------------------------

--
-- Table structure for table `translations`
--

CREATE TABLE `translations` (
  `id` int(10) UNSIGNED NOT NULL,
  `original_hash` varchar(64) NOT NULL,
  `original_text` text NOT NULL,
  `translated_text` text NOT NULL,
  `source_lang` varchar(10) NOT NULL DEFAULT 'zh',
  `target_lang` varchar(10) NOT NULL DEFAULT 'en',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password_hash`, `full_name`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'admin@salameh.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Super Admin', 1, '2026-02-19 11:32:02', '2026-02-19 11:32:02');

-- --------------------------------------------------------

--
-- Table structure for table `user_notification_preferences`
--

CREATE TABLE `user_notification_preferences` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `channel` varchar(20) NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `user_notification_preferences`
--

INSERT INTO `user_notification_preferences` (`id`, `user_id`, `channel`, `event_type`, `enabled`, `created_at`, `updated_at`) VALUES
(52, 1, 'dashboard', 'order_submitted', 1, '2026-02-21 09:50:04', '2026-02-21 09:50:04'),
(53, 1, 'email', 'order_submitted', 1, '2026-02-21 09:50:04', '2026-02-21 09:50:04'),
(54, 1, 'whatsapp', 'order_submitted', 1, '2026-02-21 09:50:04', '2026-02-21 09:50:04'),
(55, 1, 'dashboard', 'order_approved', 1, '2026-02-21 09:50:04', '2026-02-21 09:50:04'),
(56, 1, 'email', 'order_approved', 1, '2026-02-21 09:50:04', '2026-02-21 09:50:04'),
(57, 1, 'whatsapp', 'order_approved', 1, '2026-02-21 09:50:04', '2026-02-21 09:50:04'),
(58, 1, 'dashboard', 'order_received', 1, '2026-02-21 09:50:04', '2026-02-21 09:50:04'),
(59, 1, 'email', 'order_received', 1, '2026-02-21 09:50:04', '2026-02-21 09:50:04'),
(60, 1, 'whatsapp', 'order_received', 1, '2026-02-21 09:50:04', '2026-02-21 09:50:04'),
(61, 1, 'dashboard', 'variance_confirmation', 1, '2026-02-21 09:50:04', '2026-02-21 09:50:04'),
(62, 1, 'email', 'variance_confirmation', 1, '2026-02-21 09:50:04', '2026-02-21 09:50:04'),
(63, 1, 'whatsapp', 'variance_confirmation', 1, '2026-02-21 09:50:04', '2026-02-21 09:50:04'),
(64, 1, 'dashboard', 'shipment_finalized', 1, '2026-02-21 09:50:04', '2026-02-21 09:50:04'),
(65, 1, 'email', 'shipment_finalized', 1, '2026-02-21 09:50:04', '2026-02-21 09:50:04'),
(66, 1, 'whatsapp', 'shipment_finalized', 1, '2026-02-21 09:50:04', '2026-02-21 09:50:04');

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `role_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES
(1, 5);

-- --------------------------------------------------------

--
-- Table structure for table `warehouse_receipts`
--

CREATE TABLE `warehouse_receipts` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `actual_cartons` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `actual_cbm` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `actual_weight` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `condition` varchar(20) NOT NULL DEFAULT 'good',
  `notes` text DEFAULT NULL,
  `received_by` int(10) UNSIGNED DEFAULT NULL,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `warehouse_receipt_items`
--

CREATE TABLE `warehouse_receipt_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `receipt_id` int(10) UNSIGNED NOT NULL,
  `order_item_id` int(10) UNSIGNED NOT NULL,
  `actual_cartons` int(10) UNSIGNED DEFAULT NULL,
  `actual_cbm` decimal(10,4) DEFAULT NULL,
  `actual_weight` decimal(10,4) DEFAULT NULL,
  `receipt_condition` varchar(20) NOT NULL DEFAULT 'good',
  `variance_detected` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `warehouse_receipt_item_photos`
--

CREATE TABLE `warehouse_receipt_item_photos` (
  `id` int(10) UNSIGNED NOT NULL,
  `receipt_item_id` int(10) UNSIGNED NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `warehouse_receipt_photos`
--

CREATE TABLE `warehouse_receipt_photos` (
  `id` int(10) UNSIGNED NOT NULL,
  `receipt_id` int(10) UNSIGNED NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `_migrations`
--

CREATE TABLE `_migrations` (
  `name` varchar(255) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `_migrations`
--

INSERT INTO `_migrations` (`name`, `applied_at`) VALUES
('001_create_master_tables.sql', '2026-02-19 11:35:04'),
('002_create_orders.sql', '2026-02-19 11:35:04'),
('003_warehouse_receiving.sql', '2026-02-19 11:35:27'),
('004_notifications_confirmations.sql', '2026-02-19 11:35:27'),
('005_consolidation.sql', '2026-02-19 11:35:27'),
('006_seed_admin.sql', '2026-02-19 11:35:27'),
('007_system_config.sql', '2026-02-19 11:35:27'),
('008_suppliers_contact.sql', '2026-02-19 11:54:03'),
('009_item_capture.sql', '2026-02-19 12:39:11'),
('010_supplier_store_payments.sql', '2026-02-19 12:39:11'),
('011_notification_channels.sql', '2026-02-19 12:41:47'),
('012_tracking_config.sql', '2026-02-19 12:46:52'),
('013_tracking_push_log.sql', '2026-02-19 12:46:52'),
('014_search_indexes.sql', '2026-02-19 13:26:52'),
('015_item_level_receiving.sql', '2026-02-20 12:10:17'),
('016_notification_preferences.sql', '2026-02-20 12:10:17'),
('017_notification_delivery_log.sql', '2026-02-20 12:10:17'),
('018_production_hardening_config.sql', '2026-02-20 12:38:30');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_audit_entity` (`entity_type`,`entity_id`);

--
-- Indexes for table `containers`
--
ALTER TABLE `containers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_customers_name` (`name`(100)),
  ADD KEY `idx_customers_phone` (`phone`);

--
-- Indexes for table `customer_confirmations`
--
ALTER TABLE `customer_confirmations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `confirmed_by` (`confirmed_by`);

--
-- Indexes for table `customer_deposits`
--
ALTER TABLE `customer_deposits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_deposits_customer` (`customer_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_user_read` (`user_id`,`read_at`);

--
-- Indexes for table `notification_delivery_log`
--
ALTER TABLE `notification_delivery_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ndl_notification` (`notification_id`),
  ADD KEY `idx_ndl_status` (`status`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_orders_status` (`status`),
  ADD KEY `idx_orders_customer` (`customer_id`),
  ADD KEY `idx_orders_expected_date` (`expected_ready_date`);

--
-- Indexes for table `order_attachments`
--
ALTER TABLE `order_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `idx_products_desc_cn` (`description_cn`(200)),
  ADD KEY `idx_products_desc_en` (`description_en`(200)),
  ADD KEY `idx_products_hs_code` (`hs_code`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `shipment_drafts`
--
ALTER TABLE `shipment_drafts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `container_id` (`container_id`);

--
-- Indexes for table `shipment_draft_orders`
--
ALTER TABLE `shipment_draft_orders`
  ADD PRIMARY KEY (`shipment_draft_id`,`order_id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_suppliers_name` (`name`(100)),
  ADD KEY `idx_suppliers_phone` (`phone`),
  ADD KEY `idx_suppliers_store_id` (`store_id`(50));

--
-- Indexes for table `supplier_interactions`
--
ALTER TABLE `supplier_interactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `system_config`
--
ALTER TABLE `system_config`
  ADD PRIMARY KEY (`key_name`);

--
-- Indexes for table `tracking_push_log`
--
ALTER TABLE `tracking_push_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_idempotency` (`idempotency_key`),
  ADD KEY `idx_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `translations`
--
ALTER TABLE `translations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_original_hash` (`original_hash`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_notification_preferences`
--
ALTER TABLE `user_notification_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_user_channel_event` (`user_id`,`channel`,`event_type`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`user_id`,`role_id`),
  ADD KEY `role_id` (`role_id`);

--
-- Indexes for table `warehouse_receipts`
--
ALTER TABLE `warehouse_receipts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `received_by` (`received_by`);

--
-- Indexes for table `warehouse_receipt_items`
--
ALTER TABLE `warehouse_receipt_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_item_id` (`order_item_id`),
  ADD KEY `idx_receipt_items_receipt` (`receipt_id`);

--
-- Indexes for table `warehouse_receipt_item_photos`
--
ALTER TABLE `warehouse_receipt_item_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `receipt_item_id` (`receipt_item_id`);

--
-- Indexes for table `warehouse_receipt_photos`
--
ALTER TABLE `warehouse_receipt_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `receipt_id` (`receipt_id`);

--
-- Indexes for table `_migrations`
--
ALTER TABLE `_migrations`
  ADD PRIMARY KEY (`name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `containers`
--
ALTER TABLE `containers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `customer_confirmations`
--
ALTER TABLE `customer_confirmations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_deposits`
--
ALTER TABLE `customer_deposits`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `notification_delivery_log`
--
ALTER TABLE `notification_delivery_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `order_attachments`
--
ALTER TABLE `order_attachments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `shipment_drafts`
--
ALTER TABLE `shipment_drafts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `supplier_interactions`
--
ALTER TABLE `supplier_interactions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tracking_push_log`
--
ALTER TABLE `tracking_push_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `translations`
--
ALTER TABLE `translations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `user_notification_preferences`
--
ALTER TABLE `user_notification_preferences`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouse_receipts`
--
ALTER TABLE `warehouse_receipts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouse_receipt_items`
--
ALTER TABLE `warehouse_receipt_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouse_receipt_item_photos`
--
ALTER TABLE `warehouse_receipt_item_photos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouse_receipt_photos`
--
ALTER TABLE `warehouse_receipt_photos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `customer_confirmations`
--
ALTER TABLE `customer_confirmations`
  ADD CONSTRAINT `customer_confirmations_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_confirmations_ibfk_2` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `customer_deposits`
--
ALTER TABLE `customer_deposits`
  ADD CONSTRAINT `customer_deposits_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_deposits_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notification_delivery_log`
--
ALTER TABLE `notification_delivery_log`
  ADD CONSTRAINT `notification_delivery_log_ibfk_1` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `orders_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `order_attachments`
--
ALTER TABLE `order_attachments`
  ADD CONSTRAINT `order_attachments_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `shipment_drafts`
--
ALTER TABLE `shipment_drafts`
  ADD CONSTRAINT `shipment_drafts_ibfk_1` FOREIGN KEY (`container_id`) REFERENCES `containers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `shipment_draft_orders`
--
ALTER TABLE `shipment_draft_orders`
  ADD CONSTRAINT `shipment_draft_orders_ibfk_1` FOREIGN KEY (`shipment_draft_id`) REFERENCES `shipment_drafts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shipment_draft_orders_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `supplier_interactions`
--
ALTER TABLE `supplier_interactions`
  ADD CONSTRAINT `supplier_interactions_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `supplier_interactions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD CONSTRAINT `supplier_payments_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `supplier_payments_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_notification_preferences`
--
ALTER TABLE `user_notification_preferences`
  ADD CONSTRAINT `user_notification_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `user_roles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_roles_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `warehouse_receipts`
--
ALTER TABLE `warehouse_receipts`
  ADD CONSTRAINT `warehouse_receipts_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warehouse_receipts_ibfk_2` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `warehouse_receipt_items`
--
ALTER TABLE `warehouse_receipt_items`
  ADD CONSTRAINT `warehouse_receipt_items_ibfk_1` FOREIGN KEY (`receipt_id`) REFERENCES `warehouse_receipts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warehouse_receipt_items_ibfk_2` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `warehouse_receipt_item_photos`
--
ALTER TABLE `warehouse_receipt_item_photos`
  ADD CONSTRAINT `warehouse_receipt_item_photos_ibfk_1` FOREIGN KEY (`receipt_item_id`) REFERENCES `warehouse_receipt_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `warehouse_receipt_photos`
--
ALTER TABLE `warehouse_receipt_photos`
  ADD CONSTRAINT `warehouse_receipt_photos_ibfk_1` FOREIGN KEY (`receipt_id`) REFERENCES `warehouse_receipts` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
