<?php
/**
 * Applicant-facing session bootstrap.
 *
 * Runs the same kernel as config.php (DB connection, security headers, helper
 * functions) but under a separate session cookie so an applicant and a staff
 * member can be logged in at the same time in the same browser without one
 * session overwriting the other.
 *
 * Required boilerplate for applicant pages that need a logged-in applicant:
 *   require_once 'applicant_config.php';
 *   if (!isset($_SESSION['applicant_id'])) {
 *       header("Location: applicant-login.php");
 *       exit();
 *   }
 *   if (!checkSessionTimeout()) {
 *       header("Location: applicant-login.php");
 *       exit();
 *   }
 */

define('APP_SESSION_NAME', 'applicant_session');
require_once __DIR__ . '/config.php';

// Restricts post-login/register redirects to local careers pages, preventing open-redirect abuse
function sanitizeApplicantRedirect($path) {
    if (!is_string($path) || $path === '') {
        return 'careers.php';
    }
    if (!preg_match('/^(careers\.php|careers-job\.php\?id=\d+|apply\.php\?job_posting_id=\d+|applicant-dashboard\.php)$/', $path)) {
        return 'careers.php';
    }
    return $path;
}
