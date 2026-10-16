<?php
/**
 * CodeArena — Background Judge Worker (CLI)
 * Evaluates pending submissions asynchronously.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Judge.php';

$sid = $argv[1] ?? '';
if (empty($sid)) {
    // Check if there are any jobs in RESULTS_DIR
    $jobFiles = glob(RESULTS_DIR . DIRECTORY_SEPARATOR . 'job_*.json');
    if (empty($jobFiles)) {
        exit(0);
    }
    foreach ($jobFiles as $jf) {
        $data = json_decode(file_get_contents($jf), true);
        @unlink($jf);
        if ($data && !empty($data['sid'])) {
            Judge::judgeSubmission(
                $data['sid'],
                $data['user_id'],
                $data['contest_id'],
                $data['problem_id'],
                $data['language'],
                $data['code'],
                $data['test_cases'],
                (int)($data['time_limit_ms'] ?? 1000),
                (int)($data['mem_limit_mb'] ?? 256)
            );
        }
    }
    exit(0);
}

$jobFile = RESULTS_DIR . DIRECTORY_SEPARATOR . 'job_' . $sid . '.json';
if (!file_exists($jobFile)) {
    exit(0);
}

$raw = file_get_contents($jobFile);
@unlink($jobFile);
$data = json_decode($raw, true);

if ($data && !empty($data['sid'])) {
    Judge::judgeSubmission(
        $data['sid'],
        $data['user_id'],
        $data['contest_id'],
        $data['problem_id'],
        $data['language'],
        $data['code'],
        $data['test_cases'],
        (int)($data['time_limit_ms'] ?? 1000),
        (int)($data['mem_limit_mb'] ?? 256)
    );
}
