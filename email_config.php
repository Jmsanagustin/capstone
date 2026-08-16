<?php
// FILE: email_config.php
// Loads the email sending function and handles PHPMailer requirements.

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP; 

// ---------------------------------------------------------------------------------
// --- PHPMailer Includes: Crucial for resolving "Class not found" error ---
// ---------------------------------------------------------------------------------
// NOTE: Adjust paths if your folder structure differs. 
// Using __DIR__ ensures the path is relative to this file.
require_once __DIR__ . '/phpmailer/Exception.php';  
require_once __DIR__ . '/phpmailer/PHPMailer.php';  
require_once __DIR__ . '/phpmailer/SMTP.php';  
// ---------------------------------------------------------------------------------


/**
 * Sends login credentials to the newly registered student/faculty via SMTP.
 * This function should ONLY be called when a NEW account is created.
 */
function send_credentials_email($recipient_email, $student_name, $username, $temp_password) {
    
    // --- SMTP CONFIGURATION (SECURITY NOTE: These should be loaded from a secure external config file) ---
    $smtp_host = 'smtp.gmail.com'; 
    $smtp_port = 465; 
    $smtp_username = 'johnmichaelsanagustin2@gmail.com'; 
    $smtp_password = 'qgfqehpswgbmuwwn'; 
    $sender_email = 'johnmichaelsanagustin2@gmail.com'; 
    $sender_name = 'DFCAMCLP Program Head Office';

    $mail_subject = "DFCAMCLP: Your New Account Credentials";
    
    // --- Email Body Template (Plain Text) ---
    $body = "Dear $student_name,\n\n"
          . "Welcome to Dr. Filemon C. Aguilar Memorial College Of Las Pinas- IT Campus.\n\n"
          . "Your account has been successfully created by the Office.\n\n"
          . "==========================================\n"
          . "Your Login Credentials:\n"
          . "Username (ID): $username\n"
          . "Temporary Password: $temp_password\n"
          . "==========================================\n\n"
          . "Please log in immediately and change your temporary password.\n"
          . "If you encounter any issues, please contact the administration.\n\n"
          . "Regards,\n"
          . $sender_name;

    // --- PHPMailer Execution ---
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; 
        $mail->Port = $smtp_port;

        // Recipients
        $mail->setFrom($sender_email, $sender_name);
        $mail->addAddress($recipient_email, $student_name);

        // Content
        $mail->isHTML(false); 
        $mail->Subject = $mail_subject;
        $mail->Body = $body;

        $mail->send();
        return true; 
    } catch (Exception $e) {
        // Log the error but allow the script to continue
        error_log("Credential Email failed for $recipient_email. Mailer Error: {$e->getMessage()}");
        return false; 
    }
}