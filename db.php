<?php
// REMOVED: session_start(); <-- No longer needed here

$servername = 'localhost';
$username = 'u396426316_JMSA';
$password = '1HOSTINGERr';
$dbname = 'u396426316_capstoneproj';

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    // Set the PDO error mode to exception
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Optional: Set default fetch mode to associative array
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); 
    // Optional: Set character set to utf8mb4 for better Unicode support
    $conn->exec("SET NAMES 'utf8mb4'"); 

} catch (PDOException $e) {
    // Stop script and show a generic error in production
    error_log('Connection failed: ' . $e->getMessage()); // Log detailed error
    die('Database connection failed. Please try again later.'); // Show generic message
}

// The $conn variable is now ready to be used by the file that includes this one.
// NO CLOSING PHP TAG HERE TO PREVENT WHITESPACE ISSUES