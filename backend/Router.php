<?php
/**
 * CodeArena — RESTful API Router (PHP)
 * Full API handler for all CodeArena features with CORS, Auth, Judging, and Excel DB.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ExcelDatabase.php';
require_once __DIR__ . '/Judge.php';

class Router {
    private ExcelDatabase $db;

    public function __construct() {
        $this->db = new ExcelDatabase();
    }

    public function handleRequest(): void {
        // Send CORS headers
        $this->sendCorsHeaders();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'OPTIONS') {
            http_response_code(200);
            exit(0);
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        // Strip prefix if any (e.g. /api or /)
        $cleanPath = '/' . ltrim($path, '/');
        if (str_starts_with($cleanPath, '/api/')) {
            $apiPath = substr($cleanPath, 4); // Keep leading /
        } elseif ($cleanPath === '/api') {
            $apiPath = '/';
        } else {
            $apiPath = $cleanPath;
        }

        try {
            $this->route($method, $apiPath);
        } catch (Throwable $e) {
            $this->jsonResponse(['error' => 'Server Error', 'detail' => $e->getMessage()], 500);
        }
    }

    private function sendCorsHeaders(): void {
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With");
        header("Access-Control-Max-Age: 86400");
    }

    private function jsonResponse($data, int $status = 200, array $headers = []): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $k => $v) {
            header("{$k}: {$v}");
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit(0);
    }

    private function getJsonBody(): array {
        $raw = file_get_contents('php://input');
        if ($raw !== false && trim($raw) !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) return $data;
        }
        return $_POST;
    }

    private function getToken(): string {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($auth, 'Bearer ')) {
            return trim(substr($auth, 7));
        }
        return trim((string)($_GET['token'] ?? ''));
    }

    private function getCurrentUser(): ?array {
        $token = $this->getToken();
        if ($token === '') return null;

        $sessions = $this->db->getRows('sessions');
        $now = gmdate('Y-m-d\TH:i:s\Z');

        foreach ($sessions as $s) {
            if (($s['token'] ?? '') === $token) {
                $exp = (string)($s['expires_at'] ?? '');
                if ($exp !== '' && strcmp($exp, $now) > 0) {
                    $users = $this->db->getRows('users');
                    foreach ($users as $u) {
                        if ((string)($u['id'] ?? '') === (string)($s['user_id'] ?? '')) {
                            return $u;
                        }
                    }
                }
            }
        }
        return null;
    }

    private function requireAuth(): array {
        $user = $this->getCurrentUser();
        if (!$user) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
        }
        return $user;
    }

    private function requireAdmin(): array {
        $user = $this->requireAuth();
        if (($user['role'] ?? '') !== 'admin') {
            $this->jsonResponse(['error' => 'Forbidden'], 403);
        }
        return $user;
    }

    private function generateToken(): string {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function generateUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function route(string $method, string $path): void {
        // Health & Stats
        if ($method === 'GET' && ($path === '/health' || $path === '/')) {
            $meta = $this->db->getStats();
            $this->jsonResponse([
                'status' => 'ok',
                'time' => gmdate('Y-m-d\TH:i:s\Z'),
                'redis' => false,
                'database' => 'Native OpenXML Excel (contest.xlsx)',
                'file_name' => $meta['file_name'],
                'records' => $meta['total_records'],
                'size_kb' => $meta['file_size_kb'],
                'last_modified' => $meta['last_modified']
            ]);
        }

        if ($method === 'GET' && $path === '/stats') {
            $this->jsonResponse([
                'users' => count($this->db->getRows('users')),
                'problems' => count($this->db->getRows('problems')),
                'contests' => count($this->db->getRows('contests')),
                'submissions' => count($this->db->getRows('submissions'))
            ]);
        }

        // ── Auth Routes ──────────────────────────────────────────

        if ($method === 'POST' && $path === '/auth/register') {
            $body = $this->getJsonBody();
            $username = trim((string)($body['username'] ?? ''));
            $email = trim((string)($body['email'] ?? ''));
            $password = (string)($body['password'] ?? '');

            if ($username === '' || $email === '' || $password === '') {
                $this->jsonResponse(['error' => 'All fields required'], 400);
            }

            $users = $this->db->getRows('users');
            foreach ($users as $u) {
                if (strcasecmp($u['username'] ?? '', $username) === 0) {
                    $this->jsonResponse(['error' => 'Username already taken'], 409);
                }
                if (strcasecmp($u['email'] ?? '', $email) === 0) {
                    $this->jsonResponse(['error' => 'Email already registered'], 409);
                }
            }

            $colors = ['#7c3aed', '#06b6d4', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6'];
            $uid = $this->generateUuid();
            $this->db->appendRow('users', [
                'id' => $uid,
                'username' => $username,
                'email' => $email,
                'password_hash' => hash('sha256', $password . SECRET_KEY),
                'role' => 'user',
                'rating' => 1200,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'avatar_color' => $colors[count($users) % count($colors)]
            ]);

            $this->jsonResponse(['message' => 'Registered successfully'], 201);
        }

        if ($method === 'POST' && $path === '/auth/login') {
            $rawInput = file_get_contents('php://input');
            $body = $this->getJsonBody();
            $username = trim((string)($body['username'] ?? ''));
            $password = (string)($body['password'] ?? '');
            $expectedHash = hash('sha256', $password . SECRET_KEY);

            $users = $this->db->getRows('users');
            $user = null;
            foreach ($users as $u) {
                if (($u['username'] ?? '') === $username && ($u['password_hash'] ?? '') === $expectedHash) {
                    $user = $u;
                    break;
                }
            }

            if (!$user) {
                $this->jsonResponse(['error' => 'Invalid username or password'], 401);
            }

            $token = $this->generateToken();
            $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 7 * 86400);

            // Clear old sessions for this user
            $uid = (string)$user['id'];
            $this->db->deleteRowsWhere('sessions', function($s) use ($uid) {
                return (string)($s['user_id'] ?? '') === $uid;
            });

            // Append new session
            $this->db->appendRow('sessions', [
                'token' => $token,
                'user_id' => $uid,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'expires_at' => $expires
            ]);

            $this->jsonResponse([
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'rating' => $user['rating'],
                    'avatar_color' => $user['avatar_color'] ?? '#7c3aed'
                ]
            ]);
        }

        if ($method === 'POST' && $path === '/auth/logout') {
            $token = $this->getToken();
            if ($token !== '') {
                $this->db->deleteRow('sessions', 'token', $token);
            }
            $this->jsonResponse(['message' => 'Logged out']);
        }

        if ($method === 'GET' && $path === '/auth/me') {
            $u = $this->requireAuth();
            $this->jsonResponse([
                'user' => [
                    'id' => $u['id'],
                    'username' => $u['username'],
                    'email' => $u['email'],
                    'role' => $u['role'],
                    'rating' => $u['rating'],
                    'avatar_color' => $u['avatar_color'] ?? '#7c3aed'
                ]
            ]);
        }

        // ── Problem Routes ───────────────────────────────────────

        if ($method === 'GET' && $path === '/problems') {
            $problems = $this->db->getRows('problems');
            $result = [];
            foreach ($problems as $p) {
                $tags = !empty($p['tags']) ? explode(',', (string)$p['tags']) : [];
                $result[] = [
                    'id' => $p['id'],
                    'title' => $p['title'],
                    'slug' => $p['slug'],
                    'difficulty' => $p['difficulty'] ?? 'Easy',
                    'tags' => $tags,
                    'time_limit_ms' => (int)($p['time_limit_ms'] ?? 1000),
                    'mem_limit_mb' => (int)($p['mem_limit_mb'] ?? 256)
                ];
            }
            $this->jsonResponse($result);
        }

        if ($method === 'GET' && preg_match('#^/problems/([^/]+)$#', $path, $m)) {
            $slug = $m[1];
            $p = $this->db->getRowBy('problems', 'slug', $slug);
            if (!$p) {
                $this->jsonResponse(['error' => 'Problem not found'], 404);
            }
            $result = $p;
            unset($result['test_cases_json']);
            $result['tags'] = !empty($result['tags']) ? explode(',', (string)$result['tags']) : [];
            $this->jsonResponse($result);
        }

        if ($method === 'POST' && $path === '/problems') {
            $admin = $this->requireAdmin();
            $body = $this->getJsonBody();

            $pid = $this->generateUuid();
            $title = (string)($body['title'] ?? 'Problem');
            $slug = !empty($body['slug']) ? (string)$body['slug'] : strtolower(str_replace(' ', '-', $title));
            $tags = $body['tags'] ?? '';
            if (is_array($tags)) $tags = implode(',', $tags);

            $testCases = $body['test_cases'] ?? [];

            $this->db->appendRow('problems', [
                'id' => $pid,
                'title' => $title,
                'slug' => $slug,
                'difficulty' => (string)($body['difficulty'] ?? 'Easy'),
                'time_limit_ms' => (int)($body['time_limit_ms'] ?? 1000),
                'mem_limit_mb' => (int)($body['mem_limit_mb'] ?? 256),
                'statement' => (string)($body['statement'] ?? ''),
                'input_format' => (string)($body['input_format'] ?? ''),
                'output_format' => (string)($body['output_format'] ?? ''),
                'constraints' => (string)($body['constraints'] ?? ''),
                'sample_input' => (string)($body['sample_input'] ?? ''),
                'sample_output' => (string)($body['sample_output'] ?? ''),
                'tags' => (string)$tags,
                'test_cases_json' => json_encode($testCases),
                'author_id' => $admin['id'],
                'created_at' => gmdate('Y-m-d\TH:i:s\Z')
            ]);

            $this->jsonResponse(['id' => $pid, 'slug' => $slug], 201);
        }

        // ── Contest Routes ───────────────────────────────────────

        if ($method === 'GET' && $path === '/contests') {
            $contests = $this->db->getRows('contests');
            $result = [];
            foreach ($contests as $c) {
                $result[] = [
                    'id' => $c['id'],
                    'title' => $c['title'],
                    'slug' => $c['slug'],
                    'start_time' => $c['start_time'],
                    'end_time' => $c['end_time'],
                    'status' => $c['status'],
                    'description' => $c['description']
                ];
            }
            $this->jsonResponse($result);
        }

        if ($method === 'GET' && preg_match('#^/contests/([^/]+)$#', $path, $m)) {
            $slug = $m[1];
            $c = $this->db->getRowBy('contests', 'slug', $slug);
            if (!$c) {
                $this->jsonResponse(['error' => 'Contest not found'], 404);
            }

            $cp = $this->db->getRows('contest_problems');
            $problems = $this->db->getRows('problems');

            $cid = (string)$c['id'];
            $cps = array_filter($cp, function($r) use ($cid) {
                return (string)($r['contest_id'] ?? '') === $cid;
            });
            usort($cps, function($a, $b) {
                return (int)($a['order'] ?? 0) - (int)($b['order'] ?? 0);
            });

            $probList = [];
            foreach ($cps as $cpRow) {
                $pid = (string)($cpRow['problem_id'] ?? '');
                foreach ($problems as $px) {
                    if ((string)($px['id'] ?? '') === $pid) {
                        $probList[] = [
                            'id' => $px['id'],
                            'title' => $px['title'],
                            'slug' => $px['slug'],
                            'difficulty' => $px['difficulty'],
                            'points' => (int)($cpRow['points'] ?? 100),
                            'order' => (int)($cpRow['order'] ?? 1)
                        ];
                        break;
                    }
                }
            }

            $this->jsonResponse([
                'id' => $c['id'],
                'title' => $c['title'],
                'slug' => $c['slug'],
                'start_time' => $c['start_time'],
                'end_time' => $c['end_time'],
                'status' => $c['status'],
                'description' => $c['description'],
                'problems' => $probList
            ]);
        }

        if ($method === 'POST' && $path === '/contests') {
            $admin = $this->requireAdmin();
            $body = $this->getJsonBody();

            $cid = $this->generateUuid();
            $title = (string)($body['title'] ?? 'Contest');
            $slug = !empty($body['slug']) ? (string)$body['slug'] : strtolower(str_replace(' ', '-', $title));

            $this->db->appendRow('contests', [
                'id' => $cid,
                'title' => $title,
                'slug' => $slug,
                'start_time' => (string)($body['start_time'] ?? gmdate('Y-m-d\TH:i:s\Z')),
                'end_time' => (string)($body['end_time'] ?? gmdate('Y-m-d\TH:i:s\Z', time() + 7200)),
                'status' => (string)($body['status'] ?? 'upcoming'),
                'description' => (string)($body['description'] ?? ''),
                'is_public' => !empty($body['is_public']) ? true : true,
                'created_by' => $admin['id'],
                'created_at' => gmdate('Y-m-d\TH:i:s\Z')
            ]);

            $this->jsonResponse(['id' => $cid, 'slug' => $slug], 201);
        }

        // ── Submission & Judge Routes ────────────────────────────

        if ($method === 'POST' && $path === '/submissions') {
            $user = $this->requireAuth();
            $body = $this->getJsonBody();

            $problemSlug = trim((string)($body['problem_slug'] ?? ''));
            $language = trim((string)($body['language'] ?? ''));
            $code = (string)($body['code'] ?? '');
            $contestId = !empty($body['contest_id']) ? (string)$body['contest_id'] : null;

            if ($problemSlug === '' || $language === '' || $code === '') {
                $this->jsonResponse(['error' => 'problem_slug, language, and code required'], 400);
            }

            $problem = $this->db->getRowBy('problems', 'slug', $problemSlug);
            if (!$problem) {
                $this->jsonResponse(['error' => 'Problem not found'], 404);
            }

            $sid = $this->generateUuid();
            $testCases = json_decode((string)($problem['test_cases_json'] ?? '[]'), true) ?: [];
            $timeLimit = (int)($problem['time_limit_ms'] ?? 1000);
            $memLimit = (int)($problem['mem_limit_mb'] ?? 256);
            $verdict = 'PENDING';

            $this->db->appendRow('submissions', [
                'id' => $sid,
                'user_id' => $user['id'],
                'problem_id' => $problem['id'],
                'contest_id' => $contestId,
                'language' => $language,
                'code' => $code,
                'verdict' => $verdict,
                'score' => 0,
                'time_ms' => null,
                'memory_mb' => null,
                'submitted_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'judge_result_json' => null
            ]);

            // Launch background judge worker
            Judge::runAsync(
                $sid,
                $user['id'],
                $contestId,
                $problem['id'],
                $language,
                $code,
                $testCases,
                $timeLimit,
                $memLimit
            );

            $this->jsonResponse(['submission_id' => $sid, 'verdict' => $verdict], 202);
        }

        if ($method === 'GET' && preg_match('#^/submissions/([^/]+)$#', $path, $m)) {
            $this->requireAuth();
            $sid = $m[1];

            // 1. Check in-memory / file cache
            $cacheFile = RESULTS_DIR . DIRECTORY_SEPARATOR . $sid . '.json';
            if (file_exists($cacheFile)) {
                $cached = json_decode(file_get_contents($cacheFile), true);
                if ($cached) {
                    $this->jsonResponse($cached);
                }
            }

            // 2. Check submissions table
            $sub = $this->db->getRowBy('submissions', 'id', $sid);
            if (!$sub) {
                $this->jsonResponse(['error' => 'Submission not found'], 404);
            }

            if (!empty($sub['judge_result_json'])) {
                $decoded = json_decode((string)$sub['judge_result_json'], true);
                if ($decoded) {
                    $this->jsonResponse($decoded);
                }
            }

            $this->jsonResponse([
                'submission_id' => $sub['id'],
                'verdict' => $sub['verdict'] ?? 'PENDING',
                'time_ms' => (int)($sub['time_ms'] ?? 0),
                'memory_mb' => (int)($sub['memory_mb'] ?? 0),
                'language' => $sub['language'] ?? '',
                'submitted_at' => $sub['submitted_at'] ?? ''
            ]);
        }

        if ($method === 'GET' && $path === '/submissions') {
            $subs = $this->db->getRows('submissions');
            $problemSlug = $_GET['problem_slug'] ?? null;
            $userId = $_GET['user_id'] ?? null;
            $limit = (int)($_GET['limit'] ?? 30);

            if ($problemSlug) {
                $p = $this->db->getRowBy('problems', 'slug', $problemSlug);
                if ($p) {
                    $pid = (string)$p['id'];
                    $subs = array_filter($subs, function($s) use ($pid) {
                        return (string)($s['problem_id'] ?? '') === $pid;
                    });
                }
            }

            if ($userId) {
                $uid = (string)$userId;
                $subs = array_filter($subs, function($s) use ($uid) {
                    return (string)($s['user_id'] ?? '') === $uid;
                });
            }

            usort($subs, function($a, $b) {
                return strcmp((string)($b['submitted_at'] ?? ''), (string)($a['submitted_at'] ?? ''));
            });

            $subs = array_slice($subs, 0, $limit);
            $users = $this->db->getRows('users');
            $problems = $this->db->getRows('problems');

            $result = [];
            foreach ($subs as $s) {
                $user = null;
                foreach ($users as $u) {
                    if ((string)($u['id'] ?? '') === (string)($s['user_id'] ?? '')) {
                        $user = $u;
                        break;
                    }
                }

                $prob = null;
                foreach ($problems as $p) {
                    if ((string)($p['id'] ?? '') === (string)($s['problem_id'] ?? '')) {
                        $prob = $p;
                        break;
                    }
                }

                $result[] = [
                    'id' => $s['id'],
                    'verdict' => $s['verdict'] ?? 'PENDING',
                    'language' => $s['language'] ?? '',
                    'time_ms' => $s['time_ms'] ?? null,
                    'memory_mb' => $s['memory_mb'] ?? null,
                    'submitted_at' => $s['submitted_at'] ?? '',
                    'username' => $user['username'] ?? '?',
                    'problem_title' => $prob['title'] ?? '?',
                    'problem_slug' => $prob['slug'] ?? '?'
                ];
            }

            $this->jsonResponse($result);
        }

        // ── Leaderboard ──────────────────────────────────────────

        if ($method === 'GET' && $path === '/leaderboard') {
            $contestSlug = $_GET['contest_slug'] ?? null;
            if ($contestSlug) {
                $c = $this->db->getRowBy('contests', 'slug', $contestSlug);
                if (!$c) {
                    $this->jsonResponse([]);
                }
                $cid = (string)$c['id'];
                $lb = $this->db->getRows('leaderboard', function($r) use ($cid) {
                    return (string)($r['contest_id'] ?? '') === $cid;
                });
                usort($lb, function($a, $b) {
                    $diff = (int)($b['total_score'] ?? 0) - (int)($a['total_score'] ?? 0);
                    if ($diff !== 0) return $diff;
                    return strcmp((string)($a['last_accepted_at'] ?? ''), (string)($b['last_accepted_at'] ?? ''));
                });
                $this->jsonResponse($lb);
            }

            // Global leaderboard
            $users = $this->db->getRows('users');
            $subs = $this->db->getRows('submissions');

            usort($users, function($a, $b) {
                return (int)($b['rating'] ?? 0) - (int)($a['rating'] ?? 0);
            });

            $result = [];
            $rank = 1;
            foreach ($users as $u) {
                $uid = (string)$u['id'];
                $solvedProblems = [];
                foreach ($subs as $s) {
                    if ((string)($s['user_id'] ?? '') === $uid && ($s['verdict'] ?? '') === 'AC') {
                        $solvedProblems[(string)($s['problem_id'] ?? '')] = true;
                    }
                }

                $result[] = [
                    'rank' => $rank++,
                    'username' => $u['username'],
                    'rating' => (int)($u['rating'] ?? 1200),
                    'problems_solved' => count($solvedProblems),
                    'avatar_color' => $u['avatar_color'] ?? '#7c3aed'
                ];
            }

            $this->jsonResponse($result);
        }

        // ── Admin & Excel Management ─────────────────────────────

        if ($method === 'GET' && $path === '/admin/export') {
            $this->requireAdmin();
            $this->db->formatAndSave();

            header('Content-Description: File Transfer');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="CodeArena_Database.xlsx"');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($this->db->dataFile));
            readfile($this->db->dataFile);
            exit(0);
        }

        if ($method === 'GET' && $path === '/admin/db-stats') {
            $this->requireAdmin();
            $this->jsonResponse($this->db->getStats());
        }

        if ($method === 'GET' && preg_match('#^/admin/db-preview/([^/]+)$#', $path, $m)) {
            $this->requireAdmin();
            $sheet = $m[1];
            $stats = $this->db->getStats();
            if (!isset($stats['sheets'][$sheet])) {
                $this->jsonResponse(['error' => "Worksheet '{$sheet}' not found"], 404);
            }
            $limit = (int)($_GET['limit'] ?? 50);
            $this->jsonResponse($this->db->getSheetPreview($sheet, $limit));
        }

        if ($method === 'POST' && $path === '/admin/db-format') {
            $this->requireAdmin();
            $this->db->formatAndSave();
            $this->jsonResponse([
                'message' => 'Excel database successfully formatted and optimized',
                'stats' => $this->db->getStats()
            ]);
        }

        if ($method === 'POST' && $path === '/admin/db-sync') {
            $this->requireAdmin();
            $this->db->loadIntoCache();
            $this->jsonResponse([
                'message' => 'Database synchronized from disk',
                'stats' => $this->db->getStats()
            ]);
        }

        if ($method === 'POST' && $path === '/admin/import') {
            $this->requireAdmin();
            if (empty($_FILES['file']['tmp_name'])) {
                $this->jsonResponse(['error' => 'No file uploaded'], 400);
            }

            $origName = (string)($_FILES['file']['name'] ?? '');
            if (!str_ends_with(strtolower($origName), '.xlsx')) {
                $this->jsonResponse(['error' => 'Only .xlsx files are supported'], 400);
            }

            list($ok, $msg) = $this->db->importWorkbookFile($_FILES['file']['tmp_name']);
            if (!$ok) {
                $this->jsonResponse(['error' => $msg], 400);
            }

            $this->jsonResponse([
                'message' => $msg,
                'stats' => $this->db->getStats()
            ]);
        }

        // Route not found
        $this->jsonResponse(['error' => 'Endpoint not found', 'path' => $path, 'method' => $method], 404);
    }
}
