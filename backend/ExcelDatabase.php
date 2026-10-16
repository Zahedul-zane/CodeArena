<?php
/**
 * CodeArena — High-Performance Native Excel Database Engine (PHP)
 * Thread-safe, styled, validated Excel workbook database powered by ZipArchive + OpenXML.
 */

require_once __DIR__ . '/config.php';

class ExcelDatabase {
    const SHEETS = [
        "users",
        "problems",
        "contests",
        "contest_problems",
        "submissions",
        "leaderboard",
        "sessions",
    ];

    const HEADERS = [
        "users" => [
            "id", "username", "email", "password_hash", "role",
            "rating", "created_at", "avatar_color"
        ],
        "problems" => [
            "id", "title", "slug", "difficulty", "time_limit_ms",
            "mem_limit_mb", "statement", "input_format", "output_format",
            "constraints", "sample_input", "sample_output", "tags",
            "test_cases_json", "author_id", "created_at"
        ],
        "contests" => [
            "id", "title", "slug", "start_time", "end_time", "status",
            "description", "is_public", "created_by", "created_at"
        ],
        "contest_problems" => [
            "id", "contest_id", "problem_id", "order", "points"
        ],
        "submissions" => [
            "id", "user_id", "problem_id", "contest_id", "language",
            "code", "verdict", "score", "time_ms", "memory_mb",
            "submitted_at", "judge_result_json"
        ],
        "leaderboard" => [
            "id", "contest_id", "user_id", "username", "total_score",
            "problems_solved", "last_accepted_at", "rank"
        ],
        "sessions" => [
            "token", "user_id", "created_at", "expires_at"
        ],
    ];

    const NUMERIC_COLUMNS = [
        "users" => ["rating" => "int"],
        "problems" => ["time_limit_ms" => "int", "mem_limit_mb" => "int"],
        "contests" => [],
        "contest_problems" => ["order" => "int", "points" => "int"],
        "submissions" => ["score" => "float", "time_ms" => "int", "memory_mb" => "float"],
        "leaderboard" => ["total_score" => "int", "problems_solved" => "int", "rank" => "int"],
        "sessions" => [],
    ];

    const BOOLEAN_COLUMNS = [
        "contests" => ["is_public" => true]
    ];

    const CENTER_COLS = [
        "id", "role", "difficulty", "status", "language", "verdict",
        "created_at", "submitted_at", "start_time", "end_time", "expires_at", "avatar_color"
    ];

    const WRAP_COLS = [
        "statement", "input_format", "output_format", "constraints", "test_cases_json", "code", "judge_result_json"
    ];

    public string $dataFile;
    public string $backupFile;
    private string $lockFile;
    private array $cachedData = [];
    private float $lastMtime = 0.0;

    public function __construct(string $dataFilePath = DATA_FILE) {
        $this->dataFile = $dataFilePath;
        $dir = dirname($this->dataFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $this->backupFile = preg_replace('/\.xlsx$/i', '.backup.xlsx', $this->dataFile);
        $this->lockFile = $this->dataFile . '.lock';
        $this->ensureDatabase();
    }

    private function withLock(callable $fn) {
        $fp = fopen($this->lockFile, 'c+');
        if (!$fp) {
            return $fn();
        }
        flock($fp, LOCK_EX);
        try {
            return $fn();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function ensureDatabase(): void {
        $this->withLock(function() {
            if (!file_exists($this->dataFile)) {
                $sheetsData = [];
                foreach (self::SHEETS as $sheet) {
                    $sheetsData[$sheet] = [
                        'headers' => self::HEADERS[$sheet],
                        'rows' => []
                    ];
                }
                $this->writeXlsx($this->dataFile, $sheetsData);
                $this->createBackup();
                $this->loadIntoCache();
                $this->seedDemoData();
                return;
            }

            try {
                $this->loadIntoCache();
            } catch (Exception $e) {
                if (file_exists($this->backupFile)) {
                    @copy($this->backupFile, $this->dataFile);
                    $this->loadIntoCache();
                } else {
                    throw $e;
                }
            }

            // Verify all sheets exist in cached data
            $needsSave = false;
            foreach (self::SHEETS as $sheet) {
                if (!isset($this->cachedData[$sheet])) {
                    $this->cachedData[$sheet] = [];
                    $needsSave = true;
                }
            }

            if ($needsSave) {
                $this->saveCurrentCache();
            }

            $this->seedDemoData();
        });
    }

    private function createBackup(): void {
        try {
            if (file_exists($this->dataFile)) {
                @copy($this->dataFile, $this->backupFile);
            }
        } catch (Exception $e) {}
    }

    private function syncCheck(): void {
        if (!file_exists($this->dataFile)) return;
        $mtime = (float)filemtime($this->dataFile);
        if ($mtime > $this->lastMtime + 0.001) {
            $this->loadIntoCache();
        }
    }

    public function loadIntoCache(): void {
        if (!file_exists($this->dataFile)) {
            $this->cachedData = [];
            return;
        }

        $parsed = $this->readXlsx($this->dataFile);
        $cleanData = [];
        foreach (self::SHEETS as $sheet) {
            $rawRows = $parsed[$sheet] ?? [];
            if (empty($rawRows)) {
                $cleanData[$sheet] = [];
                continue;
            }

            $headers = array_map(function($h, $idx) {
                return ($h !== null && $h !== '') ? trim((string)$h) : "col_{$idx}";
            }, $rawRows[0], array_keys($rawRows[0]));

            $sheetRows = [];
            $total = count($rawRows);
            for ($r = 1; $r < $total; $r++) {
                $row = $rawRows[$r];
                // Check if row has any non-empty cell
                $hasVal = false;
                foreach ($row as $v) {
                    if ($v !== null && $v !== '') { $hasVal = true; break; }
                }
                if (!$hasVal) continue;

                $rowDict = [];
                foreach ($headers as $cIdx => $h) {
                    $rowDict[$h] = $row[$cIdx] ?? null;
                }
                $sheetRows[] = $this->castRowTypes($sheet, $rowDict);
            }
            $cleanData[$sheet] = $sheetRows;
        }

        $this->cachedData = $cleanData;
        $this->lastMtime = (float)filemtime($this->dataFile);
    }

    private function castRowTypes(string $sheet, array $rowDict): array {
        $numCols = self::NUMERIC_COLUMNS[$sheet] ?? [];
        $boolCols = self::BOOLEAN_COLUMNS[$sheet] ?? [];

        foreach ($numCols as $col => $type) {
            if (array_key_exists($col, $rowDict)) {
                $val = $rowDict[$col];
                if ($val !== null && $val !== '') {
                    $rowDict[$col] = ($type === 'int') ? (int)$val : (float)$val;
                } else {
                    $rowDict[$col] = null;
                }
            }
        }

        foreach ($boolCols as $col => $_) {
            if (array_key_exists($col, $rowDict)) {
                $val = $rowDict[$col];
                if ($val !== null) {
                    if (is_bool($val)) {
                        $rowDict[$col] = $val;
                    } else {
                        $s = strtolower(trim((string)$val));
                        $rowDict[$col] = in_array($s, ['true', '1', 'yes'], true);
                    }
                } else {
                    $rowDict[$col] = true;
                }
            }
        }

        return $rowDict;
    }

    private function saveCurrentCache(): void {
        $sheetsData = [];
        foreach (self::SHEETS as $sheet) {
            $sheetsData[$sheet] = [
                'headers' => self::HEADERS[$sheet],
                'rows' => $this->cachedData[$sheet] ?? []
            ];
        }
        $this->writeXlsx($this->dataFile, $sheetsData);
        $this->lastMtime = (float)filemtime($this->dataFile);
    }

    public function seedDemoData(): void {
        $users = $this->cachedData['users'] ?? [];
        if (!empty($users)) return;

        $adminId = $this->generateUuid();
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->appendRow('users', [
            'id' => $adminId,
            'username' => 'admin',
            'email' => 'admin@codearena.io',
            'password_hash' => hash('sha256', 'admin123' . SECRET_KEY),
            'role' => 'admin',
            'rating' => 2500,
            'created_at' => $now,
            'avatar_color' => '#7c3aed'
        ]);

        $demoProblems = [
            [
                'title' => 'Two Sum',
                'slug' => 'two-sum',
                'difficulty' => 'Easy',
                'time_limit_ms' => 1000,
                'mem_limit_mb' => 256,
                'statement' => 'Given an array of integers nums and an integer target, return indices of the two numbers such that they add up to target.',
                'input_format' => 'First line: n and target. Second line: n space-separated integers.',
                'output_format' => 'Two space-separated 0-based indices.',
                'constraints' => '2 <= n <= 10^4, -10^9 <= nums[i] <= 10^9',
                'sample_input' => "4 9\n2 7 11 15",
                'sample_output' => '0 1',
                'tags' => 'array,hash-map',
                'test_cases' => [
                    ['index' => 0, 'input' => "4 9\n2 7 11 15", 'expected' => '0 1'],
                    ['index' => 1, 'input' => "3 6\n3 2 4", 'expected' => '1 2']
                ]
            ],
            [
                'title' => 'Fibonacci Sequence',
                'slug' => 'fibonacci',
                'difficulty' => 'Easy',
                'time_limit_ms' => 1000,
                'mem_limit_mb' => 256,
                'statement' => 'Print the first n Fibonacci numbers starting from 0.',
                'input_format' => 'Single integer n.',
                'output_format' => 'Space-separated Fibonacci numbers.',
                'constraints' => '1 <= n <= 50',
                'sample_input' => '5',
                'sample_output' => '0 1 1 2 3',
                'tags' => 'dp,math',
                'test_cases' => [
                    ['index' => 0, 'input' => '5', 'expected' => '0 1 1 2 3'],
                    ['index' => 1, 'input' => '1', 'expected' => '0']
                ]
            ],
            [
                'title' => 'Longest Common Subsequence',
                'slug' => 'lcs',
                'difficulty' => 'Medium',
                'time_limit_ms' => 2000,
                'mem_limit_mb' => 256,
                'statement' => 'Find the length of the longest common subsequence between two strings.',
                'input_format' => 'Two lines, each with a string.',
                'output_format' => 'Single integer LCS length.',
                'constraints' => '1 <= |s|, |t| <= 1000',
                'sample_input' => "abcde\nace",
                'sample_output' => '3',
                'tags' => 'dp,string',
                'test_cases' => [
                    ['index' => 0, 'input' => "abcde\nace", 'expected' => '3'],
                    ['index' => 1, 'input' => "abc\nabc", 'expected' => '3']
                ]
            ],
            [
                'title' => 'Maximum Subarray',
                'slug' => 'max-subarray',
                'difficulty' => 'Medium',
                'time_limit_ms' => 1000,
                'mem_limit_mb' => 256,
                'statement' => 'Find the contiguous subarray with the largest sum (Kadanes Algorithm).',
                'input_format' => 'First line: n. Second line: n integers.',
                'output_format' => 'Maximum subarray sum.',
                'constraints' => '1 <= n <= 10^5',
                'sample_input' => "9\n-2 1 -3 4 -1 2 1 -5 4",
                'sample_output' => '6',
                'tags' => 'dp,array',
                'test_cases' => [
                    ['index' => 0, 'input' => "9\n-2 1 -3 4 -1 2 1 -5 4", 'expected' => '6'],
                    ['index' => 1, 'input' => "1\n1", 'expected' => '1']
                ]
            ],
            [
                'title' => 'Binary Search',
                'slug' => 'binary-search',
                'difficulty' => 'Easy',
                'time_limit_ms' => 1000,
                'mem_limit_mb' => 256,
                'statement' => 'Find index of target in sorted array. Return -1 if not found.',
                'input_format' => 'Line 1: n and target. Line 2: n sorted integers.',
                'output_format' => 'Index of target or -1.',
                'constraints' => '1 <= n <= 10^4',
                'sample_input' => "6 9\n1 3 5 7 9 11",
                'sample_output' => '4',
                'tags' => 'binary-search,array',
                'test_cases' => [
                    ['index' => 0, 'input' => "6 9\n1 3 5 7 9 11", 'expected' => '4'],
                    ['index' => 1, 'input' => "3 0\n1 2 3", 'expected' => '-1']
                ]
            ]
        ];

        $pids = [];
        foreach ($demoProblems as $p) {
            $pid = $this->generateUuid();
            $pids[] = $pid;
            $this->appendRow('problems', [
                'id' => $pid,
                'title' => $p['title'],
                'slug' => $p['slug'],
                'difficulty' => $p['difficulty'],
                'time_limit_ms' => $p['time_limit_ms'],
                'mem_limit_mb' => $p['mem_limit_mb'],
                'statement' => $p['statement'],
                'input_format' => $p['input_format'],
                'output_format' => $p['output_format'],
                'constraints' => $p['constraints'],
                'sample_input' => $p['sample_input'],
                'sample_output' => $p['sample_output'],
                'tags' => $p['tags'],
                'test_cases_json' => json_encode($p['test_cases']),
                'author_id' => $adminId,
                'created_at' => $now
            ]);
        }

        $cid = $this->generateUuid();
        $startTime = gmdate('Y-m-d\TH:i:s\Z');
        $endTime = gmdate('Y-m-d\TH:i:s\Z', time() + 3 * 3600);

        $this->appendRow('contests', [
            'id' => $cid,
            'title' => 'CodeArena Round #1',
            'slug' => 'round-1',
            'start_time' => $startTime,
            'end_time' => $endTime,
            'status' => 'ongoing',
            'description' => 'Welcome to CodeArena! Compete, code, and climb the leaderboard.',
            'is_public' => true,
            'created_by' => $adminId,
            'created_at' => $now
        ]);

        $points = [100, 100, 200, 200, 300];
        foreach ($pids as $idx => $pid) {
            $this->appendRow('contest_problems', [
                'id' => $this->generateUuid(),
                'contest_id' => $cid,
                'problem_id' => $pid,
                'order' => $idx + 1,
                'points' => $points[$idx] ?? 100
            ]);
        }
    }

    public function getRows(string $sheetName, ?callable $filterFn = null): array {
        return $this->withLock(function() use ($sheetName, $filterFn) {
            $this->syncCheck();
            $rows = $this->cachedData[$sheetName] ?? [];
            if ($filterFn) {
                return array_values(array_filter($rows, $filterFn));
            }
            return $rows;
        });
    }

    public function getRowBy(string $sheetName, string $key, $value): ?array {
        return $this->withLock(function() use ($sheetName, $key, $value) {
            $this->syncCheck();
            $rows = $this->cachedData[$sheetName] ?? [];
            $strVal = (string)$value;
            foreach ($rows as $r) {
                if (isset($r[$key]) && (string)$r[$key] === $strVal) {
                    return $r;
                }
            }
            return null;
        });
    }

    public function appendRow(string $sheetName, array $rowDict): void {
        $this->withLock(function() use ($sheetName, $rowDict) {
            $this->syncCheck();
            if (!isset($this->cachedData[$sheetName])) {
                $this->cachedData[$sheetName] = [];
            }
            $cleanRow = $this->castRowTypes($sheetName, $rowDict);
            $this->cachedData[$sheetName][] = $cleanRow;
            $this->saveCurrentCache();
        });
    }

    public function updateRow(string $sheetName, string $matchKey, $matchVal, array $updates): bool {
        return $this->withLock(function() use ($sheetName, $matchKey, $matchVal, $updates) {
            $this->syncCheck();
            $rows = &$this->cachedData[$sheetName];
            if (!isset($rows)) return false;

            $strVal = (string)$matchVal;
            $updated = false;
            foreach ($rows as &$r) {
                if (isset($r[$matchKey]) && (string)$r[$matchKey] === $strVal) {
                    foreach ($updates as $k => $v) {
                        $r[$k] = $v;
                    }
                    $r = $this->castRowTypes($sheetName, $r);
                    $updated = true;
                    break;
                }
            }

            if ($updated) {
                $this->saveCurrentCache();
            }
            return $updated;
        });
    }

    public function deleteRow(string $sheetName, string $matchKey, $matchVal): bool {
        return $this->withLock(function() use ($sheetName, $matchKey, $matchVal) {
            $this->syncCheck();
            if (!isset($this->cachedData[$sheetName])) return false;

            $strVal = (string)$matchVal;
            $rows = $this->cachedData[$sheetName];
            $newRows = [];
            $deleted = false;

            foreach ($rows as $r) {
                if (!$deleted && isset($r[$matchKey]) && (string)$r[$matchKey] === $strVal) {
                    $deleted = true;
                    continue;
                }
                $newRows[] = $r;
            }

            if ($deleted) {
                $this->cachedData[$sheetName] = $newRows;
                $this->saveCurrentCache();
            }
            return $deleted;
        });
    }

    public function deleteRowsWhere(string $sheetName, callable $conditionFn): int {
        return $this->withLock(function() use ($sheetName, $conditionFn) {
            $this->syncCheck();
            if (!isset($this->cachedData[$sheetName])) return 0;

            $rows = $this->cachedData[$sheetName];
            $newRows = [];
            $deletedCount = 0;

            foreach ($rows as $r) {
                if ($conditionFn($r)) {
                    $deletedCount++;
                } else {
                    $newRows[] = $r;
                }
            }

            if ($deletedCount > 0) {
                $this->cachedData[$sheetName] = $newRows;
                $this->saveCurrentCache();
            }
            return $deletedCount;
        });
    }

    public function formatAndSave(): bool {
        return $this->withLock(function() {
            $this->syncCheck();
            $this->saveCurrentCache();
            return true;
        });
    }

    public function getStats(): array {
        return $this->withLock(function() {
            $this->syncCheck();
            $fileSize = file_exists($this->dataFile) ? filesize($this->dataFile) : 0;
            $mtime = file_exists($this->dataFile) ? filemtime($this->dataFile) : time();

            $sheetsMeta = [];
            $totalRecords = 0;
            foreach (self::SHEETS as $s) {
                $count = count($this->cachedData[$s] ?? []);
                $totalRecords += $count;
                $sheetsMeta[$s] = [
                    'count' => $count,
                    'columns' => self::HEADERS[$s] ?? []
                ];
            }

            return [
                'file_path' => $this->dataFile,
                'file_name' => basename($this->dataFile),
                'file_size_bytes' => $fileSize,
                'file_size_kb' => round($fileSize / 1024, 2),
                'last_modified' => date('c', $mtime),
                'total_sheets' => count(self::SHEETS),
                'total_records' => $totalRecords,
                'sheets' => $sheetsMeta
            ];
        });
    }

    public function getSheetPreview(string $sheetName, int $limit = 50): array {
        return $this->withLock(function() use ($sheetName, $limit) {
            $this->syncCheck();
            $rows = $this->cachedData[$sheetName] ?? [];
            return [
                'sheet' => $sheetName,
                'headers' => self::HEADERS[$sheetName] ?? [],
                'total' => count($rows),
                'rows' => array_slice($rows, 0, $limit)
            ];
        });
    }

    public function importWorkbookFile(string $uploadedFilePath): array {
        return $this->withLock(function() use ($uploadedFilePath) {
            try {
                $parsed = $this->readXlsx($uploadedFilePath);
            } catch (Exception $e) {
                return [false, "Invalid Excel file format: " . $e->getMessage()];
            }

            $essential = ['users', 'problems', 'contests'];
            foreach ($essential as $s) {
                if (!isset($parsed[$s])) {
                    return [false, "Missing required worksheet '{$s}' in uploaded file."];
                }
            }

            $this->createBackup();

            // Load parsed into cached data with type casting
            $cleanData = [];
            foreach (self::SHEETS as $sheet) {
                $rawRows = $parsed[$sheet] ?? [];
                if (empty($rawRows)) {
                    $cleanData[$sheet] = [];
                    continue;
                }

                $headers = array_map(function($h, $idx) {
                    return ($h !== null && $h !== '') ? trim((string)$h) : "col_{$idx}";
                }, $rawRows[0], array_keys($rawRows[0]));

                $sheetRows = [];
                $total = count($rawRows);
                for ($r = 1; $r < $total; $r++) {
                    $row = $rawRows[$r];
                    $hasVal = false;
                    foreach ($row as $v) {
                        if ($v !== null && $v !== '') { $hasVal = true; break; }
                    }
                    if (!$hasVal) continue;

                    $rowDict = [];
                    foreach ($headers as $cIdx => $h) {
                        $rowDict[$h] = $row[$cIdx] ?? null;
                    }
                    $sheetRows[] = $this->castRowTypes($sheet, $rowDict);
                }
                $cleanData[$sheet] = $sheetRows;
            }

            $this->cachedData = $cleanData;
            $this->saveCurrentCache();

            return [true, "Database successfully imported and formatted."];
        });
    }

    // ── OpenXML Reader & Writer Engines ───────────────────────────

    private function colLetterToIndex(string $col): int {
        $len = strlen($col);
        $idx = 0;
        for ($i = 0; $i < $len; $i++) {
            $idx = $idx * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return $idx - 1;
    }

    private function colIndexToLetters(int $index): string {
        $letters = '';
        $index += 1;
        while ($index > 0) {
            $rem = ($index - 1) % 26;
            $letters = chr(65 + $rem) . $letters;
            $index = (int)(($index - $rem) / 26);
        }
        return $letters;
    }

    private function xmlEscape(string $str): string {
        return htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function generateUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function readXlsx(string $filePath): array {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception("Unable to open XLSX archive: {$filePath}");
        }

        try {
            $workbookXml = $zip->getFromName('xl/workbook.xml');
            if (!$workbookXml) {
                throw new Exception("Missing xl/workbook.xml in XLSX");
            }
            $wb = simplexml_load_string($workbookXml);

            $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
            $relMap = [];
            if ($relsXml) {
                $rels = simplexml_load_string($relsXml);
                foreach ($rels->Relationship as $rel) {
                    $relMap[(string)$rel['Id']] = (string)$rel['Target'];
                }
            }

            // Shared Strings
            $sharedStrings = [];
            $ssXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($ssXml) {
                $ss = simplexml_load_string($ssXml);
                foreach ($ss->si as $item) {
                    if (isset($item->t)) {
                        $sharedStrings[] = (string)$item->t;
                    } elseif (isset($item->r)) {
                        $t = '';
                        foreach ($item->r as $run) {
                            $t .= (string)$run->t;
                        }
                        $sharedStrings[] = $t;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }

            $sheets = [];
            foreach ($wb->sheets->sheet as $s) {
                $sheetName = (string)$s['name'];
                $rId = (string)$s->attributes('r', true)['id'];
                $target = ltrim($relMap[$rId] ?? '', '/');
                if (!str_starts_with($target, 'xl/')) {
                    $target = 'xl/' . $target;
                }

                $sheetXml = $zip->getFromName($target);
                if (!$sheetXml) {
                    $sheets[$sheetName] = [];
                    continue;
                }

                $ws = simplexml_load_string($sheetXml);
                $rows = [];
                if (isset($ws->sheetData->row)) {
                    foreach ($ws->sheetData->row as $rowNode) {
                        $rowCells = [];
                        foreach ($rowNode->c as $c) {
                            $ref = (string)$c['r'];
                            preg_match('/^([A-Z]+)(\d+)$/', $ref, $matches);
                            $colLetters = $matches[1] ?? 'A';
                            $colIndex = $this->colLetterToIndex($colLetters);

                            $type = (string)$c['t'];
                            $val = null;
                            if ($type === 's') {
                                $sIndex = (int)$c->v;
                                $val = $sharedStrings[$sIndex] ?? '';
                            } elseif ($type === 'inlineStr') {
                                $val = (string)($c->is->t ?? '');
                            } elseif ($type === 'b') {
                                $val = ((string)$c->v === '1');
                            } else {
                                $val = isset($c->v) ? (string)$c->v : null;
                            }
                            $rowCells[$colIndex] = $val;
                        }

                        if (!empty($rowCells)) {
                            $maxKey = max(array_keys($rowCells));
                            $denseRow = [];
                            for ($k = 0; $k <= $maxKey; $k++) {
                                $denseRow[$k] = $rowCells[$k] ?? null;
                            }
                            $rows[] = $denseRow;
                        }
                    }
                }
                $sheets[$sheetName] = $rows;
            }

            return $sheets;
        } finally {
            $zip->close();
        }
    }

    private function writeXlsx(string $filePath, array $sheetsData): void {
        $tempFile = $filePath . '.tmp.' . bin2hex(random_bytes(6));
        $zip = new ZipArchive();
        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Cannot create temporary XLSX archive: {$tempFile}");
        }

        $sheetNames = array_keys($sheetsData);
        $numSheets = count($sheetNames);

        // 1. [Content_Types].xml
        $typesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $typesXml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $typesXml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $typesXml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $typesXml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $typesXml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $typesXml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        $typesXml .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        for ($i = 1; $i <= $numSheets; $i++) {
            $typesXml .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $typesXml .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $typesXml);

        // 2. _rels/.rels
        $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $rootRelsXml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $rootRelsXml .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
        $rootRelsXml .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>';
        $rootRelsXml .= '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>';
        $rootRelsXml .= '</Relationships>';
        $zip->addFromString('_rels/.rels', $rootRelsXml);

        // 3. docProps
        $nowIso = gmdate('Y-m-d\TH:i:s\Z');
        $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $coreXml .= '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
        $coreXml .= '<dc:creator>CodeArena Engine</dc:creator>';
        $coreXml .= '<cp:lastModifiedBy>CodeArena Engine</cp:lastModifiedBy>';
        $coreXml .= '<dcterms:created xsi:type="dcterms:W3CDTF">' . $nowIso . '</dcterms:created>';
        $coreXml .= '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $nowIso . '</dcterms:modified>';
        $coreXml .= '</cp:coreProperties>';
        $zip->addFromString('docProps/core.xml', $coreXml);

        $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $appXml .= '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">';
        $appXml .= '<Application>CodeArena PHP Engine</Application>';
        $appXml .= '</Properties>';
        $zip->addFromString('docProps/app.xml', $appXml);

        // 4. xl/styles.xml
        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $stylesXml .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        // Fonts: 0 = regular Segoe UI 10pt (0F172A), 1 = bold Segoe UI 11pt (FFFFFF)
        $stylesXml .= '<fonts count="2">';
        $stylesXml .= '<font><sz val="10"/><color rgb="FF0F172A"/><name val="Segoe UI"/><family val="2"/></font>';
        $stylesXml .= '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/><family val="2"/></font>';
        $stylesXml .= '</fonts>';
        // Fills: 0 = none, 1 = gray125, 2 = Indigo 900 (312E81), 3 = Zebra (F8FAFC)
        $stylesXml .= '<fills count="4">';
        $stylesXml .= '<fill><patternFill patternType="none"/></fill>';
        $stylesXml .= '<fill><patternFill patternType="gray125"/></fill>';
        $stylesXml .= '<fill><patternFill patternType="solid"><fgColor rgb="FF312E81"/></patternFill></fill>';
        $stylesXml .= '<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>';
        $stylesXml .= '</fills>';
        // Borders: 0 = none, 1 = thin slate (E2E8F0)
        $stylesXml .= '<borders count="2">';
        $stylesXml .= '<border><left/><right/><top/><bottom/></border>';
        $stylesXml .= '<border>';
        $stylesXml .= '<left style="thin"><color rgb="FFE2E8F0"/></left>';
        $stylesXml .= '<right style="thin"><color rgb="FFE2E8F0"/></right>';
        $stylesXml .= '<top style="thin"><color rgb="FFE2E8F0"/></top>';
        $stylesXml .= '<bottom style="thin"><color rgb="FFE2E8F0"/></bottom>';
        $stylesXml .= '</border>';
        $stylesXml .= '</borders>';
        $stylesXml .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
        // CellXfs
        $stylesXml .= '<cellXfs count="10">';
        $stylesXml .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
        $stylesXml .= '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>';
        $stylesXml .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>';
        $stylesXml .= '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>';
        $stylesXml .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>';
        $stylesXml .= '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>';
        $stylesXml .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>';
        $stylesXml .= '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>';
        $stylesXml .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>';
        $stylesXml .= '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>';
        $stylesXml .= '</cellXfs>';
        $stylesXml .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
        $stylesXml .= '</styleSheet>';
        $zip->addFromString('xl/styles.xml', $stylesXml);

        // 5. xl/workbook.xml and xl/_rels/workbook.xml.rels
        $wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $wbXml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $wbXml .= '<sheets>';
        $wbRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $wbRelsXml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        $i = 1;
        foreach ($sheetsData as $sheetName => $data) {
            $rId = 'rId' . $i;
            $wbXml .= '<sheet name="' . $this->xmlEscape($sheetName) . '" sheetId="' . $i . '" r:id="' . $rId . '"/>';
            $wbRelsXml .= '<Relationship Id="' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
            $i++;
        }
        $wbRelsXml .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $wbRelsXml .= '</Relationships>';
        $wbXml .= '</sheets>';
        $wbXml .= '</workbook>';
        $zip->addFromString('xl/workbook.xml', $wbXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRelsXml);

        // 6. Each worksheet
        $sheetIndex = 1;
        foreach ($sheetsData as $sheetName => $data) {
            $headers = $data['headers'] ?? [];
            $rows = $data['rows'] ?? [];
            $colCount = count($headers);
            $rowCount = count($rows) + 1; // including header

            $numMap = self::NUMERIC_COLUMNS[$sheetName] ?? [];
            $boolMap = self::BOOLEAN_COLUMNS[$sheetName] ?? [];

            // Calculate column widths
            $colWidths = [];
            foreach ($headers as $cIdx => $h) {
                $maxLen = strlen((string)$h);
                foreach ($rows as $r) {
                    $val = is_array($r) ? ($r[$h] ?? '') : '';
                    $valLen = strlen((string)$val);
                    if ($valLen > $maxLen) $maxLen = $valLen;
                }
                if (in_array($h, self::WRAP_COLS, true)) {
                    $colWidths[$cIdx] = 45;
                } else {
                    $colWidths[$cIdx] = min(max($maxLen + 5, 14), 40);
                }
            }

            $wsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $wsXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
            
            $lastColLetter = $this->colIndexToLetters(max(0, $colCount - 1));
            $wsXml .= '<dimension ref="A1:' . $lastColLetter . max(1, $rowCount) . '"/>';

            // Freeze header pane at A2
            $wsXml .= '<sheetViews><sheetView workbookViewId="0">';
            $wsXml .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>';
            $wsXml .= '</sheetView></sheetViews>';

            if ($colCount > 0) {
                $wsXml .= '<cols>';
                for ($cIdx = 0; $cIdx < $colCount; $cIdx++) {
                    $colNum = $cIdx + 1;
                    $w = $colWidths[$cIdx] ?? 14;
                    $wsXml .= '<col min="' . $colNum . '" max="' . $colNum . '" width="' . $w . '" customWidth="1"/>';
                }
                $wsXml .= '</cols>';
            }

            $wsXml .= '<sheetData>';

            // Header row (ht 28)
            $wsXml .= '<row r="1" ht="28" customHeight="1">';
            for ($cIdx = 0; $cIdx < $colCount; $cIdx++) {
                $cellRef = $this->colIndexToLetters($cIdx) . '1';
                $val = $this->xmlEscape($headers[$cIdx]);
                $wsXml .= '<c r="' . $cellRef . '" s="1" t="inlineStr"><is><t>' . $val . '</t></is></c>';
            }
            $wsXml .= '</row>';

            // Data rows (ht 22)
            $rNum = 2;
            foreach ($rows as $row) {
                $isEven = ($rNum % 2 === 0);
                $wsXml .= '<row r="' . $rNum . '" ht="22" customHeight="1">';

                for ($cIdx = 0; $cIdx < $colCount; $cIdx++) {
                    $colName = $headers[$cIdx];
                    $cellRef = $this->colIndexToLetters($cIdx) . $rNum;
                    $val = is_array($row) ? ($row[$colName] ?? null) : null;

                    $styleId = 2;
                    if (in_array($colName, self::WRAP_COLS, true)) {
                        $styleId = $isEven ? 9 : 8;
                    } elseif (isset($numMap[$colName])) {
                        $styleId = $isEven ? 7 : 6;
                    } elseif (in_array($colName, self::CENTER_COLS, true)) {
                        $styleId = $isEven ? 5 : 4;
                    } else {
                        $styleId = $isEven ? 3 : 2;
                    }

                    if ($val === null || $val === '') {
                        $wsXml .= '<c r="' . $cellRef . '" s="' . $styleId . '"/>';
                    } elseif (isset($numMap[$colName]) && is_numeric($val)) {
                        $wsXml .= '<c r="' . $cellRef . '" s="' . $styleId . '"><v>' . $val . '</v></c>';
                    } elseif (isset($boolMap[$colName]) || is_bool($val)) {
                        $bVal = ($val === true || $val === 1 || $val === '1' || strtolower((string)$val) === 'true') ? 1 : 0;
                        $wsXml .= '<c r="' . $cellRef . '" s="' . $styleId . '" t="b"><v>' . $bVal . '</v></c>';
                    } else {
                        $wsXml .= '<c r="' . $cellRef . '" s="' . $styleId . '" t="inlineStr"><is><t>' . $this->xmlEscape((string)$val) . '</t></is></c>';
                    }
                }
                $wsXml .= '</row>';
                $rNum++;
            }
            $wsXml .= '</sheetData>';

            if ($colCount > 0 && $rowCount > 1) {
                $wsXml .= '<autoFilter ref="A1:' . $lastColLetter . $rowCount . '"/>';
            }

            $wsXml .= '</worksheet>';
            $zip->addFromString('xl/worksheets/sheet' . $sheetIndex . '.xml', $wsXml);
            $sheetIndex++;
        }

        $zip->close();

        // Atomic replace with retries for Windows locks
        $maxRetries = 5;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
                if (@rename($tempFile, $filePath)) {
                    break;
                }
            } catch (Exception $e) {}
            usleep((int)(80000 * pow(2, $attempt)));
        }

        if (file_exists($tempFile)) {
            @unlink($tempFile);
        }
    }
}
