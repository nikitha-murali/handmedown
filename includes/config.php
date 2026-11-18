<?php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (!isset($conn)) {
    $serverName = getenv('HMD_DB_HOST') ?: 'localhost';
    $dBUsername = getenv('HMD_DB_USER') ?: 'root';
    $dBPassword = getenv('HMD_DB_PASSWORD');
    if ($dBPassword === false) {
        $dBPassword = '';
    }
    $dBName = getenv('HMD_DB_NAME') ?: 'web';
    $dbPort = getenv('HMD_DB_PORT');
    $dbPort = ($dbPort !== false && ctype_digit((string) $dbPort)) ? (int) $dbPort : 3306;
    $conn = null;
    $hmd_db_connected = false;

    if (function_exists('mysqli_report')) {
        mysqli_report(MYSQLI_REPORT_OFF);
    }
    if (class_exists('mysqli')) {
        try {
            $candidate = @new mysqli($serverName, $dBUsername, $dBPassword, $dBName, $dbPort);
            if (!$candidate->connect_errno) {
                @$candidate->set_charset('utf8mb4');
                $conn = $candidate;
                $hmd_db_connected = true;
            } else {
                @$candidate->close();
            }
        } catch (Throwable $exception) {
            $conn = null;
            $hmd_db_connected = false;
        }
    }
}
