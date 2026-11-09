<?php
// A disposable second process for the synthetic family QA contention test.
if (PHP_SAPI !== 'cli' || count($argv) !== 3 || !preg_match('/^[1-9][0-9]*$/D', $argv[1])) {
    exit(2);
}
define('PHPUNIT_UTIL', true);
require_once dirname(__DIR__, 5) . '/vendor/autoload.php';
require_once dirname(__DIR__, 5) . '/public/lib/phpunit/bootstrap.php';

$courseid = (int)$argv[1];
$ready = $argv[2];
if (!is_file($ready) || !str_starts_with(realpath($ready), realpath($CFG->dataroot) . DIRECTORY_SEPARATOR)) {
    exit(3);
}
\report_reportsflow\local\regulatory_completion_preservation_service::with_course_barriers(
    [$courseid], static function() use ($ready): void {
        file_put_contents($ready, 'HELD', LOCK_EX);
        sleep(36);
    });
