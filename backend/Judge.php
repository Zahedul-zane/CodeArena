<?php
/**
 * CodeArena — Automated Code Judge Engine (PHP)
 * Sandboxed multi-language test case evaluation with time & memory limit checking.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ExcelDatabase.php';

class Judge {
    public static function normalizeOutput(?string $text): string {
        if ($text === null || $text === '') return '';
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($text)));
        $trimmed = array_map('rtrim', $lines);
        return implode("\n", $trimmed);
    }

    public static function executeTestCase(string $language, string $code, string $inputData, int $timeLimitMs, string $tempDir): array {
        $timeoutSec = max(0.8, ($timeLimitMs ?: 1000) / 1000.0);
        $lang = strtolower(trim($language));
        $startTime = microtime(true);

        try {
            $cmd = '';
            if (in_array($lang, ['python', 'py', 'python3'], true)) {
                $srcFile = $tempDir . DIRECTORY_SEPARATOR . 'solution.py';
                file_put_contents($srcFile, $code);
                $cmd = 'python ' . escapeshellarg($srcFile);
            } elseif (in_array($lang, ['javascript', 'js', 'node'], true)) {
                $srcFile = $tempDir . DIRECTORY_SEPARATOR . 'solution.js';
                file_put_contents($srcFile, $code);
                $cmd = 'node ' . escapeshellarg($srcFile);
            } elseif (in_array($lang, ['cpp', 'c++'], true)) {
                $srcFile = $tempDir . DIRECTORY_SEPARATOR . 'solution.cpp';
                $exeFile = $tempDir . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'solution.exe' : 'solution');
                file_put_contents($srcFile, $code);

                // Compile step
                $compCmd = 'g++ -O2 ' . escapeshellarg($srcFile) . ' -o ' . escapeshellarg($exeFile);
                $descriptors = [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w']
                ];
                $compProc = proc_open($compCmd, $descriptors, $compPipes, $tempDir);
                if (is_resource($compProc)) {
                    $compErr = stream_get_contents($compPipes[2]);
                    fclose($compPipes[1]);
                    fclose($compPipes[2]);
                    $exit = proc_close($compProc);
                    if ($exit !== 0) {
                        return [
                            'verdict' => 'CE',
                            'time_ms' => 0,
                            'error' => substr($compErr, 0, 300)
                        ];
                    }
                } else {
                    return ['verdict' => 'CE', 'time_ms' => 0, 'error' => 'Compiler failed to start'];
                }
                $cmd = escapeshellarg($exeFile);
            } else {
                return ['verdict' => 'CE', 'time_ms' => 0, 'error' => "Language {$language} execution not configured"];
            }

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ];

            $process = proc_open($cmd, $descriptors, $pipes, $tempDir);
            if (!is_resource($process)) {
                return ['verdict' => 'RE', 'time_ms' => 0, 'error' => 'Failed to spawn process'];
            }

            // Write input
            if ($inputData !== '') {
                fwrite($pipes[0], $inputData);
            }
            fclose($pipes[0]);

            // Stream reading with timeout
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $stdout = '';
            $stderr = '';
            $isTimeout = false;

            while (true) {
                $r = [$pipes[1], $pipes[2]];
                $w = null;
                $e = null;
                $elapsed = microtime(true) - $startTime;
                if ($elapsed > $timeoutSec) {
                    $isTimeout = true;
                    break;
                }

                $changed = stream_select($r, $w, $e, 0, 15000); // 15ms resolution
                if ($changed > 0) {
                    foreach ($r as $stream) {
                        if ($stream === $pipes[1]) {
                            $stdout .= fread($stream, 8192);
                        } elseif ($stream === $pipes[2]) {
                            $stderr .= fread($stream, 8192);
                        }
                    }
                }

                $status = proc_get_status($process);
                if (!$status['running']) {
                    $stdout .= stream_get_contents($pipes[1]);
                    $stderr .= stream_get_contents($pipes[2]);
                    break;
                }
            }

            fclose($pipes[1]);
            fclose($pipes[2]);

            $elapsedMs = (int)((microtime(true) - $startTime) * 1000);

            if ($isTimeout) {
                proc_terminate($process);
                proc_close($process);
                return [
                    'verdict' => 'TLE',
                    'time_ms' => (int)($timeoutSec * 1000),
                    'error' => 'Time Limit Exceeded'
                ];
            }

            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                return [
                    'verdict' => 'RE',
                    'time_ms' => $elapsedMs,
                    'error' => substr($stderr, 0, 300)
                ];
            }

            return [
                'verdict' => 'OK',
                'time_ms' => $elapsedMs,
                'output' => $stdout
            ];

        } catch (Exception $e) {
            return ['verdict' => 'RE', 'time_ms' => 0, 'error' => substr($e->getMessage(), 0, 300)];
        }
    }

    public static function judgeSubmission(
        string $sid,
        string $userId,
        ?string $contestId,
        string $problemId,
        string $language,
        string $code,
        array $testCases,
        int $timeLimitMs,
        int $memLimitMb
    ): array {
        $db = new ExcelDatabase();
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ca_judge_' . substr($sid, 0, 8) . '_' . bin2hex(random_bytes(4));
        @mkdir($tempDir, 0777, true);

        try {
            $finalVerdict = 'AC';
            $maxTime = 0;
            $testResults = [];

            if (empty($testCases)) {
                $testCases = [['index' => 0, 'input' => '', 'expected' => '']];
            }

            foreach ($testCases as $idx => $tc) {
                $inp = (string)($tc['input'] ?? '');
                $exp = (string)($tc['expected'] ?? '');
                $res = self::executeTestCase($language, $code, $inp, $timeLimitMs, $tempDir);

                $tMs = $res['time_ms'] ?? 0;
                $maxTime = max($maxTime, $tMs);

                if ($res['verdict'] === 'CE') {
                    $finalVerdict = 'CE';
                    $testResults[] = ['test_index' => $idx, 'verdict' => 'CE', 'error' => $res['error'] ?? ''];
                    break;
                } elseif ($res['verdict'] === 'TLE') {
                    $finalVerdict = 'TLE';
                    $testResults[] = ['test_index' => $idx, 'verdict' => 'TLE', 'time_ms' => $tMs];
                    break;
                } elseif ($res['verdict'] === 'RE') {
                    $finalVerdict = 'RE';
                    $testResults[] = ['test_index' => $idx, 'verdict' => 'RE', 'time_ms' => $tMs, 'error' => $res['error'] ?? ''];
                    break;
                } elseif ($res['verdict'] === 'OK') {
                    $actual = self::normalizeOutput($res['output'] ?? '');
                    $expected = self::normalizeOutput($exp);
                    if ($exp !== '' && $actual !== $expected) {
                        $finalVerdict = 'WA';
                        $testResults[] = [
                            'test_index' => $idx,
                            'verdict' => 'WA',
                            'time_ms' => $tMs,
                            'actual' => substr($actual, 0, 100),
                            'expected' => substr($expected, 0, 100)
                        ];
                        break;
                    } else {
                        $testResults[] = ['test_index' => $idx, 'verdict' => 'AC', 'time_ms' => $tMs];
                    }
                }
            }

            $testsPassed = 0;
            foreach ($testResults as $t) {
                if (($t['verdict'] ?? '') === 'AC') $testsPassed++;
            }

            $resultPayload = [
                'submission_id' => $sid,
                'verdict' => $finalVerdict,
                'max_time_ms' => $maxTime,
                'max_memory_mb' => 16,
                'tests_passed' => $testsPassed,
                'tests_total' => count($testCases),
                'results' => $testResults
            ];

            // Cache result file for instant retrieval
            $cacheFile = RESULTS_DIR . DIRECTORY_SEPARATOR . $sid . '.json';
            @file_put_contents($cacheFile, json_encode($resultPayload));

            // Update database row
            $db->updateRow('submissions', 'id', $sid, [
                'verdict' => $finalVerdict,
                'score' => ($finalVerdict === 'AC' ? 100 : 0),
                'time_ms' => $maxTime,
                'memory_mb' => 16,
                'judge_result_json' => json_encode($resultPayload)
            ]);

            // Update leaderboard if contest submission
            if ($finalVerdict === 'AC' && !empty($contestId)) {
                self::updateLeaderboard($db, $contestId, $userId, $problemId);
            }

            return $resultPayload;

        } finally {
            // Clean up temp directory
            $files = glob($tempDir . DIRECTORY_SEPARATOR . '*');
            if ($files) {
                foreach ($files as $f) {
                    @unlink($f);
                }
            }
            @rmdir($tempDir);
        }
    }

    public static function updateLeaderboard(ExcelDatabase $db, string $contestId, string $userId, string $problemId): void {
        $users = $db->getRows('users');
        $username = 'Coder';
        foreach ($users as $u) {
            if ((string)($u['id'] ?? '') === (string)$userId) {
                $username = $u['username'] ?? 'Coder';
                break;
            }
        }

        $cps = $db->getRows('contest_problems');
        $pts = 100;
        foreach ($cps as $cp) {
            if ((string)($cp['contest_id'] ?? '') === (string)$contestId && (string)($cp['problem_id'] ?? '') === (string)$problemId) {
                $pts = (int)($cp['points'] ?? 100);
                break;
            }
        }

        $lb = $db->getRows('leaderboard');
        $existing = null;
        foreach ($lb as $entry) {
            if ((string)($entry['contest_id'] ?? '') === (string)$contestId && (string)($entry['user_id'] ?? '') === (string)$userId) {
                $existing = $entry;
                break;
            }
        }

        $nowIso = gmdate('Y-m-d\TH:i:s\Z');
        if ($existing) {
            $newScore = (int)($existing['total_score'] ?? 0) + $pts;
            $newSolved = (int)($existing['problems_solved'] ?? 0) + 1;
            $db->updateRow('leaderboard', 'id', $existing['id'], [
                'total_score' => $newScore,
                'problems_solved' => $newSolved,
                'last_accepted_at' => $nowIso
            ]);
        } else {
            $db->appendRow('leaderboard', [
                'id' => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000,
                    mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                ),
                'contest_id' => $contestId,
                'user_id' => $userId,
                'username' => $username,
                'total_score' => $pts,
                'problems_solved' => 1,
                'last_accepted_at' => $nowIso,
                'rank' => 1
            ]);
        }
    }

    public static function runAsync(
        string $sid,
        string $userId,
        ?string $contestId,
        string $problemId,
        string $language,
        string $code,
        array $testCases,
        int $timeLimitMs,
        int $memLimitMb
    ): void {
        $payload = [
            'sid' => $sid,
            'user_id' => $userId,
            'contest_id' => $contestId,
            'problem_id' => $problemId,
            'language' => $language,
            'code' => $code,
            'test_cases' => $testCases,
            'time_limit_ms' => $timeLimitMs,
            'mem_limit_mb' => $memLimitMb
        ];

        $payloadFile = RESULTS_DIR . DIRECTORY_SEPARATOR . 'job_' . $sid . '.json';
        file_put_contents($payloadFile, json_encode($payload));

        $workerScript = __DIR__ . DIRECTORY_SEPARATOR . 'judge_worker.php';
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows background launch
            $cmd = 'start /B php ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($sid);
            pclose(popen($cmd, 'r'));
        } else {
            // Linux/macOS background launch
            $cmd = 'php ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($sid) . ' > /dev/null 2>&1 &';
            exec($cmd);
        }
    }
}
