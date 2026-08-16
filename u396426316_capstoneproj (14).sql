-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Dec 10, 2025 at 02:29 AM
-- Server version: 11.8.3-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u396426316_capstoneproj`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `attendance_id` int(11) NOT NULL,
  `enrollment_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `status` enum('Present','Absent','Late','Excused') NOT NULL DEFAULT 'Present',
  `remarks` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `professor_sid` bigint(20) NOT NULL,
  `academic_year` varchar(10) NOT NULL,
  `semester` varchar(50) NOT NULL,
  `section` varchar(50) DEFAULT NULL,
  `midterm_submission_start` datetime DEFAULT NULL COMMENT 'Timestamp when midterm grades were first submitted',
  `midterm_finalized_at` datetime DEFAULT NULL COMMENT 'Timestamp when midterm grades were locked',
  `final_submission_start` datetime DEFAULT NULL COMMENT 'Timestamp when final grades were first submitted',
  `final_finalized_at` datetime DEFAULT NULL COMMENT 'Timestamp when final grades were locked',
  `finalization_date` datetime DEFAULT NULL,
  `is_finalized` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`class_id`, `subject_id`, `professor_sid`, `academic_year`, `semester`, `section`, `midterm_submission_start`, `midterm_finalized_at`, `final_submission_start`, `final_finalized_at`, `finalization_date`, `is_finalized`) VALUES
(27, 500, 2205070561, '2025-2026', '1st Semester', 'BSIS-11', '2025-12-07 02:36:20', '2025-12-07 02:51:52', '2025-12-08 04:38:11', '2025-12-08 04:39:47', NULL, 0),
(28, 506, 2205070561, '2025-2026', '1st Semester', 'BSIS-11', '2025-12-08 05:07:47', '2025-12-08 05:08:12', '2025-12-09 13:12:06', '2025-12-09 13:13:09', NULL, 0),
(29, 501, 2205070561, '2025-2026', '1st Semester', 'BSIS-11', '2025-12-09 23:32:17', '2025-12-09 23:34:48', NULL, NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `class_schedules`
--

CREATE TABLE `class_schedules` (
  `schedule_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `room` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `conversations`
--

CREATE TABLE `conversations` (
  `conversation_id` int(11) NOT NULL,
  `user_1_id` bigint(20) NOT NULL,
  `user_2_id` bigint(20) NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user_1_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `user_2_hidden` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `conversations`
--

INSERT INTO `conversations` (`conversation_id`, `user_1_id`, `user_2_id`, `updated_at`, `user_1_hidden`, `user_2_hidden`) VALUES
(15, 18, 20, '2025-12-04 21:30:26', 0, 0),
(16, 20, 69696969, '2025-12-09 15:51:14', 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `enrollment`
--

CREATE TABLE `enrollment` (
  `enrollment_id` int(11) NOT NULL,
  `student_sid` bigint(20) NOT NULL,
  `class_id` int(11) NOT NULL,
  `date_enrolled` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `enrollment`
--

INSERT INTO `enrollment` (`enrollment_id`, `student_sid`, `class_id`, `date_enrolled`) VALUES
(98, 2205070554, 27, '2025-12-07 02:20:16'),
(99, 2205070554, 28, '2025-12-08 05:04:49'),
(102, 220123456, 27, '2025-12-09 23:23:00'),
(103, 220123456, 28, '2025-12-09 23:23:00'),
(104, 220123456, 29, '2025-12-09 23:28:53'),
(105, 69696969, 29, '2025-12-09 23:28:53');

-- --------------------------------------------------------

--
-- Table structure for table `grades`
--

CREATE TABLE `grades` (
  `grade_id` int(11) NOT NULL,
  `student_id_string` varchar(50) NOT NULL COMMENT 'Old SID/Student Code (String)',
  `subject_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `term` enum('Midterm','Final') NOT NULL DEFAULT 'Final',
  `term_grade` decimal(5,2) DEFAULT NULL COMMENT 'The 100% calculated grade for this specific term (Midterm or Final)',
  `final_grade` decimal(4,2) DEFAULT NULL,
  `status` enum('Pending PH Approval','Approved by PH','Pending Registrar','Acknowledged by Registrar') NOT NULL DEFAULT 'Pending PH Approval',
  `program_head_id` bigint(20) DEFAULT NULL,
  `review_date` datetime DEFAULT NULL,
  `ph_rejection_reason` text DEFAULT NULL,
  `ph_notation` text DEFAULT NULL,
  `teacher_id` bigint(20) DEFAULT NULL,
  `remarks` varchar(20) DEFAULT NULL,
  `submission_date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `grades`
--

INSERT INTO `grades` (`grade_id`, `student_id_string`, `subject_id`, `class_id`, `term`, `term_grade`, `final_grade`, `status`, `program_head_id`, `review_date`, `ph_rejection_reason`, `ph_notation`, `teacher_id`, `remarks`, `submission_date`) VALUES
(28, '2205070554', 500, 27, 'Midterm', 82.56, 82.56, 'Acknowledged by Registrar', 18, '2025-12-08 04:01:13', NULL, NULL, 2205070561, 'Passed', '2025-12-08 04:01:13'),
(29, '2205070554', 500, 27, 'Final', 93.29, 89.00, 'Acknowledged by Registrar', 18, '2025-12-08 04:47:22', NULL, NULL, 2205070561, 'Passed', '2025-12-08 04:47:22'),
(30, '2205070554', 506, 28, 'Midterm', 84.96, 84.96, 'Acknowledged by Registrar', 18, '2025-12-08 05:38:18', NULL, NULL, 2205070561, 'Passed', '2025-12-08 05:38:18'),
(32, '2205070554', 506, 28, 'Final', 92.50, 89.48, 'Acknowledged by Registrar', 18, '2025-12-09 23:17:59', NULL, NULL, 2205070561, 'Passed', '2025-12-09 23:17:59'),
(33, '220123456', 501, 29, 'Midterm', 92.35, 92.35, 'Acknowledged by Registrar', 18, '2025-12-09 23:36:17', NULL, NULL, 2205070561, 'Passed', '2025-12-09 23:36:17'),
(34, '69696969', 501, 29, 'Midterm', 92.90, 92.90, 'Acknowledged by Registrar', 18, '2025-12-09 23:36:17', NULL, NULL, 2205070561, 'Passed', '2025-12-09 23:36:17');

-- --------------------------------------------------------

--
-- Table structure for table `grade_archives`
--

CREATE TABLE `grade_archives` (
  `archive_id` int(11) NOT NULL,
  `student_sid` varchar(255) NOT NULL,
  `class_id` int(11) NOT NULL,
  `subject_code` varchar(100) DEFAULT NULL,
  `subject_name` varchar(255) DEFAULT NULL,
  `final_grade` decimal(5,2) NOT NULL,
  `gwa` decimal(3,2) NOT NULL,
  `academic_term` varchar(100) NOT NULL,
  `date_archived` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `grade_components`
--

CREATE TABLE `grade_components` (
  `component_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `term` enum('Midterm','Final') NOT NULL DEFAULT 'Midterm',
  `component_type` enum('Lecture','Laboratory') NOT NULL DEFAULT 'Lecture',
  `component_name` varchar(100) NOT NULL,
  `weight` decimal(5,4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `grade_components`
--

INSERT INTO `grade_components` (`component_id`, `class_id`, `term`, `component_type`, `component_name`, `weight`) VALUES
(139, 27, 'Midterm', 'Lecture', 'QUIZ', 0.1500),
(140, 27, 'Midterm', 'Lecture', 'CLASS PARTICIPATION', 0.1500),
(141, 27, 'Midterm', 'Lecture', 'PERFORMANCE TASK', 0.3000),
(142, 27, 'Midterm', 'Lecture', 'MAJOR EXAM', 0.4000),
(143, 27, 'Final', 'Lecture', 'QUIZ', 0.1500),
(144, 27, 'Final', 'Lecture', 'CLASS PARTICIPATION', 0.1500),
(145, 27, 'Final', 'Lecture', 'PERFORMANCE TASK', 0.3000),
(146, 27, 'Final', 'Lecture', 'MAJOR EXAM', 0.4000),
(147, 28, 'Midterm', 'Lecture', 'QUIZ', 0.1500),
(148, 28, 'Midterm', 'Lecture', 'CLASS PARTICIPATION', 0.1500),
(149, 28, 'Midterm', 'Lecture', 'PERFORMANCE TASK', 0.3000),
(150, 28, 'Midterm', 'Lecture', 'MAJOR EXAM', 0.4000),
(151, 28, 'Final', 'Lecture', 'QUIZ', 0.1500),
(152, 28, 'Final', 'Lecture', 'CLASS PARTICIPATION', 0.1500),
(153, 28, 'Final', 'Lecture', 'PERFORMANCE TASK', 0.3000),
(154, 28, 'Final', 'Lecture', 'MAJOR EXAM', 0.4000),
(155, 29, 'Midterm', 'Lecture', 'QUIZ', 0.1500),
(156, 29, 'Midterm', 'Lecture', 'CLASS PARTICIPATION', 0.1500),
(157, 29, 'Midterm', 'Lecture', 'PERFORMANCE TASK', 0.3000),
(158, 29, 'Midterm', 'Lecture', 'MAJOR EXAM', 0.4000),
(159, 29, 'Final', 'Lecture', 'QUIZ', 0.1500),
(160, 29, 'Final', 'Lecture', 'CLASS PARTICIPATION', 0.1500),
(161, 29, 'Final', 'Lecture', 'PERFORMANCE TASK', 0.3000),
(162, 29, 'Final', 'Lecture', 'MAJOR EXAM', 0.4000);

-- --------------------------------------------------------

--
-- Table structure for table `interventions`
--

CREATE TABLE `interventions` (
  `intervention_id` int(11) NOT NULL,
  `student_sid` bigint(20) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `type` varchar(100) NOT NULL,
  `task_description` text NOT NULL,
  `commit_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('Open','In-Progress','Closed') DEFAULT 'Open',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `intervention_dismissals`
--

CREATE TABLE `intervention_dismissals` (
  `dismissal_id` int(11) NOT NULL,
  `professor_sid` bigint(20) NOT NULL,
  `student_sid` bigint(20) NOT NULL,
  `class_id` int(11) NOT NULL,
  `alert_type` enum('failing','missing','failing_item') NOT NULL,
  `dismissed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `message_id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `sender_id` bigint(20) NOT NULL,
  `receiver_id` bigint(20) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `sent_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`message_id`, `conversation_id`, `sender_id`, `receiver_id`, `subject`, `body`, `is_read`, `sent_at`) VALUES
(56, 15, 20, 18, '', 'HELLO', 1, '2025-12-04 21:30:26');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_sid` bigint(20) NOT NULL,
  `title` varchar(100) NOT NULL,
  `message` varchar(255) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `profile_change_requests`
--

CREATE TABLE `profile_change_requests` (
  `request_id` int(11) NOT NULL,
  `student_sid` bigint(20) NOT NULL,
  `new_first_name` varchar(50) DEFAULT NULL,
  `new_last_name` varchar(50) DEFAULT NULL,
  `new_middle_name` varchar(50) DEFAULT NULL,
  `new_email` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `submitted_at` timestamp NULL DEFAULT current_timestamp(),
  `reviewed_by_sid` bigint(20) DEFAULT NULL COMMENT 'ID of the Program Head or Registrar who reviewed it',
  `review_date` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `profile_change_requests`
--

INSERT INTO `profile_change_requests` (`request_id`, `student_sid`, `new_first_name`, `new_last_name`, `new_middle_name`, `new_email`, `status`, `submitted_at`, `reviewed_by_sid`, `review_date`, `rejection_reason`) VALUES
(2, 2205070554, 'Juan', 'Dela Cruz', 'Carlos', 'jdelacruz@dfcamclp.edu.ph', 'Rejected', '2025-12-06 03:46:06', 20, '2025-12-06 04:10:32', 'Rejected by Registrar without further notes.'),
(3, 2205070554, 'Juan', 'Dela Cruz', 'Carlos', 'jdelacruz@dfcamclp.edu.ph', 'Approved', '2025-12-06 04:29:28', 20, '2025-12-06 04:29:52', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `raw_scores`
--

CREATE TABLE `raw_scores` (
  `score_id` int(11) NOT NULL,
  `enrollment_id` int(11) NOT NULL,
  `component_id` int(11) NOT NULL,
  `item_name` varchar(100) NOT NULL DEFAULT 'Item',
  `score` decimal(5,2) NOT NULL,
  `max_score` decimal(5,2) DEFAULT 100.00,
  `score_status` enum('Graded','INC') NOT NULL DEFAULT 'Graded',
  `date_recorded` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `raw_scores`
--

INSERT INTO `raw_scores` (`score_id`, `enrollment_id`, `component_id`, `item_name`, `score`, `max_score`, `score_status`, `date_recorded`) VALUES
(141, 98, 140, 'CP', 15.00, 20.00, 'Graded', '2025-12-07 02:21:55'),
(142, 98, 139, 'Q1', 4.00, 5.00, 'Graded', '2025-12-07 02:22:18'),
(143, 98, 139, 'Q2', 9.00, 10.00, 'Graded', '2025-12-07 02:22:27'),
(144, 98, 139, 'Q3', 3.00, 5.00, 'Graded', '2025-12-07 02:22:35'),
(145, 98, 141, 'PT', 8.00, 10.00, 'Graded', '2025-12-07 02:22:07'),
(146, 98, 139, 'Q4', 10.00, 30.00, 'Graded', '2025-12-07 02:34:13'),
(147, 98, 142, 'MAJOR', 30.00, 60.00, 'Graded', '2025-12-07 02:36:20'),
(151, 98, 144, 'PT', 20.00, 20.00, 'Graded', '2025-12-08 04:36:06'),
(152, 98, 145, 'CP', 18.00, 20.00, 'Graded', '2025-12-08 04:36:47'),
(153, 98, 143, 'Q1', 15.00, 20.00, 'Graded', '2025-12-08 04:37:10'),
(154, 98, 146, 'MAJOR', 50.00, 60.00, 'Graded', '2025-12-08 04:38:11'),
(155, 99, 148, 'CP', 45.00, 50.00, 'Graded', '2025-12-08 05:06:06'),
(156, 99, 149, 'PT', 50.00, 70.00, 'Graded', '2025-12-08 05:06:32'),
(157, 99, 147, 'Q1', 5.00, 5.00, 'Graded', '2025-12-08 05:06:45'),
(158, 99, 150, 'MAJOR', 30.00, 60.00, 'Graded', '2025-12-08 05:07:47'),
(159, 99, 152, 'CP', 26.00, 30.00, 'Graded', '2025-12-09 13:10:46'),
(161, 99, 151, 'Q1', 3.00, 5.00, 'Graded', '2025-12-09 13:11:17'),
(163, 99, 151, 'Q2', 20.00, 20.00, 'Graded', '2025-12-09 13:11:31'),
(165, 99, 153, 'PT', 40.00, 50.00, 'Graded', '2025-12-09 13:10:58'),
(167, 99, 154, 'MAJOR', 90.00, 100.00, 'Graded', '2025-12-09 13:12:06'),
(169, 104, 155, 'Q1', 15.00, 20.00, 'Graded', '2025-12-09 23:31:30'),
(170, 105, 155, 'Q1', 19.00, 20.00, 'Graded', '2025-12-09 23:31:30'),
(171, 104, 155, 'Q2', 3.00, 5.00, 'Graded', '2025-12-09 23:31:43'),
(172, 105, 155, 'Q2', 2.00, 5.00, 'Graded', '2025-12-09 23:31:43'),
(173, 104, 157, 'PT', 5.00, 5.00, 'Graded', '2025-12-09 23:31:17'),
(174, 105, 157, 'PT', 4.00, 5.00, 'Graded', '2025-12-09 23:31:17'),
(175, 104, 156, 'CP', 15.00, 20.00, 'Graded', '2025-12-09 23:31:03'),
(176, 105, 156, 'CP', 20.00, 20.00, 'Graded', '2025-12-09 23:31:03'),
(177, 104, 158, 'MAJOR', 50.00, 60.00, 'Graded', '2025-12-09 23:32:17'),
(178, 105, 158, 'MAJOR', 55.00, 60.00, 'Graded', '2025-12-09 23:32:17');

-- --------------------------------------------------------

--
-- Table structure for table `student_academic_history`
--

CREATE TABLE `student_academic_history` (
  `history_id` int(11) NOT NULL,
  `student_sid` bigint(20) NOT NULL,
  `academic_year` varchar(10) NOT NULL,
  `semester` varchar(50) NOT NULL,
  `cumulative_gwa` decimal(4,2) DEFAULT NULL COMMENT 'Cumulative General Weighted Average/GPA',
  `fail_count` int(11) DEFAULT 0 COMMENT 'Total count of failed subjects up to this point',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subject`
--

CREATE TABLE `subject` (
  `subject_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `program_offered` enum('BSIS','BSCPE','ALL') NOT NULL DEFAULT 'BSIS',
  `has_lab` tinyint(1) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `semester` int(11) NOT NULL,
  `year_level` int(11) NOT NULL,
  `program_head_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subject`
--

INSERT INTO `subject` (`subject_id`, `subject_code`, `subject_name`, `program_offered`, `has_lab`, `description`, `semester`, `year_level`, `program_head_id`) VALUES
(102, 'FIL 01', 'Komunikasyon sa Akademikong Filipino', 'ALL', 0, '3 Units', 1, 1, 0),
(103, 'IS 01', 'Introduction to Information Systems', 'BSIS', 0, '3 Units', 1, 1, 0),
(104, 'CS 01', 'Computer Programming 1', 'BSIS', 0, '3 Units', 1, 1, 0),
(105, 'MATH 01', 'College Algebra', 'ALL', 0, '3 Units', 1, 1, 0),
(107, 'PE 01', 'Physical Education 1', 'ALL', 0, '2 Units', 1, 1, 0),
(108, 'NSTP 01', 'National Service Training Program 1', 'ALL', 0, '3 Units', 1, 1, 0),
(109, 'ENG 02', 'Writing in the Discipline', 'BSIS', 0, '3 Units', 2, 1, 0),
(110, 'FIL 02', 'Pagbasa at Pagsulat Tungo sa Pananaliksik', 'BSIS', 0, '3 Units', 2, 1, 0),
(111, 'IS 02', 'Discrete Mathematics', 'BSIS', 0, '3 Units', 2, 1, 0),
(112, 'CS 02', 'Computer Programming 2', 'BSIS', 0, '3 Units', 2, 1, 0),
(113, 'MATH 02', 'Plane and Spherical Trigonometry', 'BSIS', 0, '3 Units', 2, 1, 0),
(114, 'CHEM 01', 'General Chemistry', 'ALL', 0, '3 Units', 2, 1, 0),
(115, 'PE 02', 'Physical Education 2', 'ALL', 0, '2 Units', 2, 1, 0),
(116, 'NSTP 02', 'National Service Training Program 2', 'ALL', 0, '3 Units', 2, 1, 0),
(201, 'ENG 03', 'Speech and Oral Communication', 'BSIS', 0, '3 Units', 1, 2, 0),
(202, 'HUM 01', 'Introduction to the Humanities', 'BSIS', 0, '3 Units', 1, 2, 0),
(203, 'MATH 03', 'Mathematics in the Modern World', 'BSIS', 0, '3 Units', 1, 2, 0),
(204, 'IS 03', 'Application Prototyping', 'BSIS', 0, '3 Units', 1, 2, 0),
(205, 'IS 04', 'Business Process Modeling', 'BSIS', 0, '3 Units', 1, 2, 0),
(206, 'CS 03', 'Data Structures and Algorithms', 'BSIS', 0, '3 Units', 1, 2, 0),
(207, 'PE 03', 'Physical Education 3', 'ALL', 0, '2 Units', 1, 2, 0),
(208, 'SOC SCI 01', 'General Psychology', 'BSIS', 0, '3 Units', 2, 2, 0),
(209, 'SOC SCI 02', 'Society and Culture with Family Planning', 'BSIS', 0, '3 Units', 2, 2, 0),
(210, 'HUM 02', 'World Literature', 'BSIS', 0, '3 Units', 2, 2, 0),
(211, 'IS 05', 'IS and the Enterprise Architecture', 'BSIS', 0, '3 Units', 2, 2, 0),
(212, 'IS 06', 'System Analysis and Design', 'BSIS', 0, '3 Units', 2, 2, 0),
(213, 'CS 04', 'Fundamentals of Database Systems', 'BSIS', 0, '3 Units', 2, 2, 0),
(214, 'PE 04', 'Physical Education 4', 'ALL', 0, '2 Units', 2, 2, 0),
(301, 'HIS 01', 'Philippine History', 'BSIS', 0, '3 Units', 1, 3, 0),
(302, 'ECON 01', 'Basic Economics and Agrarian Reform', 'BSIS', 0, '3 Units', 1, 3, 0),
(303, 'IS 07', 'IT Infrastructure and Network Technologies', 'BSIS', 0, '3 Units', 1, 3, 0),
(304, 'IS 08', 'Web Applications and Design', 'BSIS', 0, '3 Units', 1, 3, 0),
(305, 'IS 09', 'Human Computer Interaction', 'BSIS', 0, '3 Units', 1, 3, 0),
(306, 'IS 10', 'Information Assurance and Security', 'BSIS', 0, '3 Units', 1, 3, 0),
(307, 'IS 11', 'Computer Accounting and Financial Systems', 'BSIS', 0, '3 Units', 1, 3, 0),
(308, 'CS 05', 'Operating Systems', 'BSIS', 0, '3 Units', 1, 3, 0),
(309, 'PHI 01', 'Logic and Philosophy', 'BSIS', 0, '3 Units', 2, 3, 0),
(310, 'POL SCI 01', 'Philippine Government and New Constitution', 'BSIS', 0, '3 Units', 2, 3, 0),
(311, 'IS 12', 'Professional Ethics in IT/IS Management', 'BSIS', 0, '3 Units', 2, 3, 0),
(312, 'IS 13', 'Service Management', 'BSIS', 0, '3 Units', 2, 3, 0),
(313, 'IS 14', 'Project Management 1', 'BSIS', 0, '3 Units', 2, 3, 0),
(314, 'IS 15', 'IS Strategy, Management and Acquisition', 'BSIS', 0, '3 Units', 2, 3, 0),
(315, 'IS 16', 'Distributed Database Systems', 'BSIS', 0, '3 Units', 2, 3, 0),
(316, 'CS 06', 'Data Communications and Networking', 'BSIS', 0, '3 Units', 2, 3, 0),
(401, 'RIZAL', 'Life and Works of Jose Rizal', 'ALL', 0, '3 Units', 1, 4, 0),
(402, 'IS 17', 'IS Research Methods', 'BSIS', 0, '3 Units', 1, 4, 0),
(403, 'IS 18', 'Advanced Database Management', 'BSIS', 0, '3 Units', 1, 4, 0),
(404, 'IS 19', 'Project Management 2', 'BSIS', 0, '3 Units', 1, 4, 0),
(405, 'IS 20', 'IS Audit and Controls', 'BSIS', 0, '3 Units', 1, 4, 0),
(406, 'IS 21', 'Business Intelligence and Analytics', 'BSIS', 0, '3 Units', 1, 4, 0),
(407, 'IS 22', 'Thesis 1 (Project Proposal)', 'BSIS', 0, '3 Units', 1, 4, 0),
(408, 'IS 23', 'Practicum', 'BSIS', 0, '6 Units (300 hours)', 2, 4, 0),
(409, 'IS 24', 'Thesis 2 (Project Implementation)', 'BSIS', 0, '3 Units', 2, 4, 0),
(500, 'CAP101', 'Evaluation of Business Performance', 'BSIS', 0, '3 Units', 1, 4, 18),
(501, 'DM104', 'Rizal\'s Life and Works', 'ALL', 0, '3 Units', 1, 4, 18),
(502, 'GE111', 'IT Security and Management', 'BSIS', 0, '3 Units', 1, 4, 18),
(503, 'IS108', 'IS Project Management 1', 'BSIS', 0, '3 Units', 1, 4, 18),
(504, 'FIL102', 'Professional Elective 3 (IT Audit and Controls)', 'BSIS', 0, '3 Units', 1, 4, 18),
(505, 'IS106', 'Evaluation of Business Performance', 'BSIS', 0, '3 Units', 1, 4, 18),
(506, 'ADVOZ', 'Advanced Elective Course', 'BSIS', 0, '3 Units', 1, 4, 18),
(600, 'MAT211E', 'Differential Equation', 'BSCPE', 0, '3 Units', 1, 2, 19),
(601, 'CPE211L', 'Object Oriented Programming', 'BSCPE', 1, '4 Units', 1, 2, 19),
(602, 'EE211', 'Fundamentals of Electrical Circuits', 'BSCPE', 0, '3 Units', 1, 2, 19);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `sid` bigint(20) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('student','professor','Registrar','Programhead') NOT NULL DEFAULT 'student',
  `is_verified` tinyint(1) DEFAULT 0,
  `verification_token` varchar(64) DEFAULT NULL,
  `login_token` varchar(255) DEFAULT NULL,
  `login_token_expires` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `token_creation_time` datetime DEFAULT NULL,
  `reset_token` varchar(128) DEFAULT NULL,
  `reset_token_expiry` datetime DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`sid`, `username`, `email`, `password`, `role`, `is_verified`, `verification_token`, `login_token`, `login_token_expires`, `created_at`, `token_creation_time`, `reset_token`, `reset_token_expiry`, `must_change_password`) VALUES
(18, 'PROG', 'jayemagustin330@gmail.com', '$2y$10$CgsoV.Xd2zlUXKBqa8BUHerK.LhAe89YuFzPDJ.ZpLuYTf8LhAI9e', 'Programhead', 1, NULL, NULL, NULL, '2025-10-27 17:00:26', NULL, NULL, NULL, 0),
(19, 'BSCPH', 'bscph_head@example.com', '$2y$10$CgsoV.Xd2zlUXKBqa8BUHerK.LhAe89YuFzPDJ.ZpLuYTf8LhAI9e', 'Programhead', 1, NULL, NULL, NULL, '2025-11-13 23:15:17', NULL, NULL, NULL, 0),
(20, 'REGISTAR', 'johnmichaelsanagustin7@gmail.com', '$2y$10$/y2u1NfwHffc4hW2kFQFQ.s8ySNc6NBm7B3WtiGSC46qDLwFPUBk2', 'Registrar', 1, NULL, NULL, NULL, '2025-10-28 01:55:15', NULL, NULL, NULL, 0),
(69696969, '69696969', 'cypress.antonkenneth@dfcamclp.edu.ph', '$2y$10$UphYDNnVLPIvx4Aiga0.E.87EZMaiU2b7ydYFhL4fsKYOQ0UwyKee', 'student', 1, NULL, NULL, NULL, '2025-12-09 10:40:01', NULL, NULL, NULL, 0),
(220123456, '220123456', 'sanagustin.johnmichael@dfcamclp.edu.ph', '$2y$10$WKm2kKQmZ8d03M0igB4UouHWb.GYD4zJoKZ3xOKidHZ2bLQNviuna', 'student', 1, NULL, NULL, NULL, '2025-12-09 23:23:00', NULL, NULL, NULL, 0),
(2201070600, '2201070600', 'bscpe_student1@example.com', '$2y$10$m/PqoyQP6lXeqd6Q7yAlmeZn1YmZYDep5WFE.8cc3eWtbXC/hcYZK', 'student', 1, NULL, NULL, NULL, '2025-11-13 23:15:17', NULL, NULL, NULL, 0),
(2201070601, '2201070601', 'bscpe_student2@example.com', '$2y$10$m/PqoyQP6lXeqd6Q7yAlmeZn1YmZYDep5WFE.8cc3eWtbXC/hcYZK', 'student', 1, NULL, NULL, NULL, '2025-11-13 23:15:17', NULL, NULL, NULL, 0),
(2205070553, '2205070553', 'msantos@dfcamclp.edu.ph', '$2y$10$T43BdTzvLrclLtTbRVzZ4ucZsGsNwz9U4uqfemwleKkjwhe9SGnLi', 'student', 1, NULL, NULL, NULL, '2025-12-06 03:45:03', NULL, NULL, NULL, 1),
(2205070554, '2205070554', 'jdelacruz@dfcamclp.edu.ph', '$2y$10$3Ixp8a9vUKBJ3g/1X3Sps.pjd0K7k7Vgvp2gQ.DhiqKevxBd5PTWi', 'student', 1, NULL, NULL, NULL, '2025-12-06 03:45:00', NULL, '74c1d884afb2aaa37095a9c032ceba0e1f2169697fc8e99415faa3ed87a9e606', '2025-12-09 01:40:01', 0),
(2205070561, '1234567890', 'j.doe@dfcamclp.edu.ph', '$2y$10$Dfk7ZwlWmEojKXTeywujpuOvW/qzwYEUcGRuQe7qSbCI2O2EAbNZi', 'professor', 1, NULL, NULL, NULL, '2025-12-06 03:37:44', NULL, NULL, NULL, 0),
(2205070562, '1234567891', 'jsmith@dfcamclp.edu.ph', '$2y$10$04xui5DQg6RjH8fLv/anCur3UggfYRRe3UFIyovD9iPYYMgaQrnlm', 'professor', 1, NULL, NULL, NULL, '2025-12-06 03:37:46', NULL, NULL, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `user_profiles`
--

CREATE TABLE `user_profiles` (
  `profile_id` int(11) NOT NULL,
  `sid` bigint(20) NOT NULL,
  `student_id` varchar(20) DEFAULT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `program` varchar(100) DEFAULT NULL,
  `section` char(1) DEFAULT NULL,
  `academic_year` enum('1st Year','2nd Year','3rd Year','4th Year','5th Year') NOT NULL DEFAULT '1st Year'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_profiles`
--

INSERT INTO `user_profiles` (`profile_id`, `sid`, `student_id`, `first_name`, `middle_name`, `last_name`, `program`, `section`, `academic_year`) VALUES
(13, 18, NULL, 'JOHN', 'RONALD', 'ZAPANTA', 'BSIS', '', ''),
(15, 20, NULL, 'RE', 'GIS', 'TAR', NULL, '1', '1st Year'),
(71, 19, 'BSCPH', 'Anna', NULL, 'Dela Cruz', 'BSCPE', NULL, '4th Year'),
(73, 2201070600, '2201070600', 'Chris', NULL, 'Gomez', 'BSCPE', 'A', '2nd Year'),
(74, 2201070601, '2201070601', 'Diana', NULL, 'Ramos', 'BSCPE', 'A', '2nd Year'),
(176, 2205070561, '1234567890', 'Jane', NULL, 'Doe', NULL, NULL, '1st Year'),
(177, 2205070562, '1234567891', 'John', NULL, 'Smith', NULL, NULL, '1st Year'),
(178, 2205070554, '2205070554', 'Juan', 'Carlos', 'Dela Cruz', 'BSIS', '1', '1st Year'),
(179, 2205070553, '2205070553', 'Maria', NULL, 'Santos', 'BSIS', '2', '2nd Year'),
(180, 69696969, '69696969', 'Anton Kenneth', 'Pukeku', 'Cypress', 'BSIS', '1', '1st Year'),
(181, 220123456, '220123456', 'John Michael', 'Matuguina', 'San Agustin', 'BSIS', '1', '1st Year');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`attendance_id`),
  ADD UNIQUE KEY `uk_student_class_date` (`enrollment_id`,`attendance_date`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`class_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `classes_ibfk_2` (`professor_sid`);

--
-- Indexes for table `class_schedules`
--
ALTER TABLE `class_schedules`
  ADD PRIMARY KEY (`schedule_id`),
  ADD KEY `class_id` (`class_id`);

--
-- Indexes for table `conversations`
--
ALTER TABLE `conversations`
  ADD PRIMARY KEY (`conversation_id`),
  ADD KEY `user_1_id` (`user_1_id`),
  ADD KEY `user_2_id` (`user_2_id`);

--
-- Indexes for table `enrollment`
--
ALTER TABLE `enrollment`
  ADD PRIMARY KEY (`enrollment_id`),
  ADD UNIQUE KEY `uk_student_class` (`student_sid`,`class_id`),
  ADD KEY `class_id` (`class_id`);

--
-- Indexes for table `grades`
--
ALTER TABLE `grades`
  ADD PRIMARY KEY (`grade_id`),
  ADD KEY `approval_status` (`status`),
  ADD KEY `fk_prof_submit` (`teacher_id`),
  ADD KEY `fk_grades_program_head_new` (`program_head_id`);

--
-- Indexes for table `grade_archives`
--
ALTER TABLE `grade_archives`
  ADD PRIMARY KEY (`archive_id`);

--
-- Indexes for table `grade_components`
--
ALTER TABLE `grade_components`
  ADD PRIMARY KEY (`component_id`),
  ADD KEY `class_id` (`class_id`);

--
-- Indexes for table `interventions`
--
ALTER TABLE `interventions`
  ADD PRIMARY KEY (`intervention_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `interventions_ibfk_1` (`student_sid`);

--
-- Indexes for table `intervention_dismissals`
--
ALTER TABLE `intervention_dismissals`
  ADD PRIMARY KEY (`dismissal_id`),
  ADD UNIQUE KEY `unique_alert` (`professor_sid`,`student_sid`,`class_id`,`alert_type`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `conversation_id` (`conversation_id`),
  ADD KEY `sender_id` (`sender_id`),
  ADD KEY `receiver_id` (`receiver_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_sid` (`user_sid`,`is_read`);

--
-- Indexes for table `profile_change_requests`
--
ALTER TABLE `profile_change_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `fk_request_student` (`student_sid`),
  ADD KEY `fk_request_reviewer` (`reviewed_by_sid`);

--
-- Indexes for table `raw_scores`
--
ALTER TABLE `raw_scores`
  ADD PRIMARY KEY (`score_id`),
  ADD UNIQUE KEY `uk_student_score_item` (`enrollment_id`,`component_id`,`item_name`),
  ADD KEY `component_id` (`component_id`);

--
-- Indexes for table `student_academic_history`
--
ALTER TABLE `student_academic_history`
  ADD PRIMARY KEY (`history_id`),
  ADD UNIQUE KEY `uk_student_year_sem` (`student_sid`,`academic_year`,`semester`),
  ADD KEY `fk_history_student` (`student_sid`);

--
-- Indexes for table `subject`
--
ALTER TABLE `subject`
  ADD PRIMARY KEY (`subject_id`),
  ADD UNIQUE KEY `subject_code` (`subject_code`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`sid`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `verification_token` (`verification_token`);

--
-- Indexes for table `user_profiles`
--
ALTER TABLE `user_profiles`
  ADD PRIMARY KEY (`profile_id`),
  ADD UNIQUE KEY `sid` (`sid`),
  ADD UNIQUE KEY `student_id` (`student_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `attendance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `class_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `class_schedules`
--
ALTER TABLE `class_schedules`
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `conversations`
--
ALTER TABLE `conversations`
  MODIFY `conversation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `enrollment`
--
ALTER TABLE `enrollment`
  MODIFY `enrollment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=106;

--
-- AUTO_INCREMENT for table `grades`
--
ALTER TABLE `grades`
  MODIFY `grade_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `grade_archives`
--
ALTER TABLE `grade_archives`
  MODIFY `archive_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grade_components`
--
ALTER TABLE `grade_components`
  MODIFY `component_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=163;

--
-- AUTO_INCREMENT for table `interventions`
--
ALTER TABLE `interventions`
  MODIFY `intervention_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `intervention_dismissals`
--
ALTER TABLE `intervention_dismissals`
  MODIFY `dismissal_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=57;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `profile_change_requests`
--
ALTER TABLE `profile_change_requests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `raw_scores`
--
ALTER TABLE `raw_scores`
  MODIFY `score_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=179;

--
-- AUTO_INCREMENT for table `student_academic_history`
--
ALTER TABLE `student_academic_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subject`
--
ALTER TABLE `subject`
  MODIFY `subject_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=603;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `sid` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2205070563;

--
-- AUTO_INCREMENT for table `user_profiles`
--
ALTER TABLE `user_profiles`
  MODIFY `profile_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=182;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_att_enrollment` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollment` (`enrollment_id`) ON DELETE CASCADE;

--
-- Constraints for table `classes`
--
ALTER TABLE `classes`
  ADD CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subject` (`subject_id`),
  ADD CONSTRAINT `classes_ibfk_2` FOREIGN KEY (`professor_sid`) REFERENCES `users` (`sid`);

--
-- Constraints for table `class_schedules`
--
ALTER TABLE `class_schedules`
  ADD CONSTRAINT `class_schedules_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`);

--
-- Constraints for table `conversations`
--
ALTER TABLE `conversations`
  ADD CONSTRAINT `conversations_ibfk_1` FOREIGN KEY (`user_1_id`) REFERENCES `users` (`sid`) ON DELETE CASCADE,
  ADD CONSTRAINT `conversations_ibfk_2` FOREIGN KEY (`user_2_id`) REFERENCES `users` (`sid`) ON DELETE CASCADE;

--
-- Constraints for table `enrollment`
--
ALTER TABLE `enrollment`
  ADD CONSTRAINT `enrollment_ibfk_1` FOREIGN KEY (`student_sid`) REFERENCES `users` (`sid`),
  ADD CONSTRAINT `enrollment_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`);

--
-- Constraints for table `grades`
--
ALTER TABLE `grades`
  ADD CONSTRAINT `fk_grades_program_head_new` FOREIGN KEY (`program_head_id`) REFERENCES `users` (`sid`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_grades_teacher_new` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`sid`) ON DELETE SET NULL;

--
-- Constraints for table `grade_components`
--
ALTER TABLE `grade_components`
  ADD CONSTRAINT `grade_components_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`);

--
-- Constraints for table `interventions`
--
ALTER TABLE `interventions`
  ADD CONSTRAINT `interventions_ibfk_1` FOREIGN KEY (`student_sid`) REFERENCES `users` (`sid`),
  ADD CONSTRAINT `interventions_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`);

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `fk_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`sid`),
  ADD CONSTRAINT `fk_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`sid`),
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`conversation_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`sid`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_3` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`sid`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_sid`) REFERENCES `users` (`sid`) ON DELETE CASCADE;

--
-- Constraints for table `profile_change_requests`
--
ALTER TABLE `profile_change_requests`
  ADD CONSTRAINT `profile_change_requests_ibfk_1` FOREIGN KEY (`student_sid`) REFERENCES `users` (`sid`) ON DELETE CASCADE,
  ADD CONSTRAINT `profile_change_requests_ibfk_2` FOREIGN KEY (`reviewed_by_sid`) REFERENCES `users` (`sid`) ON DELETE SET NULL;

--
-- Constraints for table `raw_scores`
--
ALTER TABLE `raw_scores`
  ADD CONSTRAINT `raw_scores_ibfk_1` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollment` (`enrollment_id`),
  ADD CONSTRAINT `raw_scores_ibfk_2` FOREIGN KEY (`component_id`) REFERENCES `grade_components` (`component_id`);

--
-- Constraints for table `student_academic_history`
--
ALTER TABLE `student_academic_history`
  ADD CONSTRAINT `fk_history_student` FOREIGN KEY (`student_sid`) REFERENCES `users` (`sid`) ON DELETE CASCADE;

--
-- Constraints for table `user_profiles`
--
ALTER TABLE `user_profiles`
  ADD CONSTRAINT `FK_User_Profile` FOREIGN KEY (`sid`) REFERENCES `users` (`sid`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `user_profiles_ibfk_1` FOREIGN KEY (`sid`) REFERENCES `users` (`sid`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
