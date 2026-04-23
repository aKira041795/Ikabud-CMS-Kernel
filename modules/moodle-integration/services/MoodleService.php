<?php

declare(strict_types=1);

namespace MoodleIntegration\Services;

final class MoodleService
{
    private int $tenantId;

    public function __construct(int $tenantId = 0)
    {
        $this->tenantId = $tenantId > 0 ? $tenantId : \moodleIntegrationCurrentTenantId();
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function settings(): array
    {
        if ($this->tenantId > 0 && function_exists('getModuleSettingsForTenant')) {
            $settings = \getModuleSettingsForTenant('moodle-integration', $this->tenantId);
            return array_merge(\moodleIntegrationSettingsDefaults(), is_array($settings) ? $settings : []);
        }

        return \moodleIntegrationGetSettings();
    }

    public function isConfigured(): bool
    {
        $settings = $this->settings();
        return trim((string)($settings['moodle_url'] ?? '')) !== '' && trim((string)($settings['api_token'] ?? '')) !== '';
    }

    public function getCourses(): array
    {
        $result = $this->request('core_course_get_courses');
        if (empty($result['ok'])) {
            return $result;
        }

        $courses = is_array($result['data']) ? array_values(array_filter($result['data'], 'is_array')) : [];
        return ['ok' => true, 'courses' => $this->filterCoursesForTenant($courses), 'http_code' => (int)($result['http_code'] ?? 200)];
    }

    public function getCourseById(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'error' => 'Invalid course id', 'http_code' => 422];
        }

        $result = $this->request('core_course_get_courses_by_field', [
            'field' => 'id',
            'value' => $id,
        ]);
        if (empty($result['ok'])) {
            return $result;
        }

        $courses = is_array($result['data']['courses'] ?? null) ? $result['data']['courses'] : [];
        $course = isset($courses[0]) && is_array($courses[0]) ? $courses[0] : null;
        if ($course === null) {
            return ['ok' => false, 'error' => 'Course not found', 'http_code' => 404];
        }

        return ['ok' => true, 'course' => $course, 'http_code' => (int)($result['http_code'] ?? 200)];
    }

    public function createUser(array $user): array
    {
        $existing = $this->findUserByEmail((string)($user['email'] ?? ''));
        if (!empty($existing['ok']) && is_array($existing['user'] ?? null)) {
            return ['ok' => true, 'user' => $existing['user'], 'created' => false];
        }

        $username = trim((string)($user['username'] ?? $user['email'] ?? ''));
        $email = trim((string)($user['email'] ?? ''));
        if ($username === '' || $email === '') {
            return ['ok' => false, 'error' => 'username and email are required', 'http_code' => 422];
        }

        $payload = [
            'users' => [[
                'username' => $username,
                'password' => (string)($user['password'] ?? $this->temporaryPassword()),
                'firstname' => trim((string)($user['first_name'] ?? $user['full_name'] ?? 'Learner')),
                'lastname' => trim((string)($user['last_name'] ?? 'User')),
                'email' => $email,
                'auth' => 'manual',
            ]],
        ];

        $result = $this->request('core_user_create_users', $payload);
        if (empty($result['ok'])) {
            return $result;
        }

        $created = is_array($result['data']) && isset($result['data'][0]) && is_array($result['data'][0]) ? $result['data'][0] : null;
        if ($created === null) {
            return ['ok' => false, 'error' => 'Unexpected Moodle user create response', 'http_code' => 502];
        }

        return ['ok' => true, 'user' => $created, 'created' => true];
    }

    public function enrollUser(int $userId, int $courseId): array
    {
        if ($userId <= 0 || $courseId <= 0) {
            return ['ok' => false, 'error' => 'Moodle user id and course id are required', 'http_code' => 422];
        }

        $result = $this->request('enrol_manual_enrol_users', [
            'enrolments' => [[
                'roleid' => 5,
                'userid' => $userId,
                'courseid' => $courseId,
            ]],
        ], true);

        if (empty($result['ok'])) {
            return $result;
        }

        return ['ok' => true, 'http_code' => (int)($result['http_code'] ?? 200)];
    }

    public function unenrollUser(int $userId, int $courseId): array
    {
        if ($userId <= 0 || $courseId <= 0) {
            return ['ok' => false, 'error' => 'Moodle user id and course id are required', 'http_code' => 422];
        }

        $result = $this->request('enrol_manual_unenrol_users', [
            'enrolments' => [[
                'userid' => $userId,
                'courseid' => $courseId,
            ]],
        ], true);

        if (empty($result['ok'])) {
            return $result;
        }

        return ['ok' => true, 'http_code' => (int)($result['http_code'] ?? 200)];
    }

    public function getUserGrades(int $userId, int $courseId): array
    {
        $result = $this->request('gradereport_user_get_grade_items', [
            'courseid' => $courseId,
            'userid' => $userId,
        ]);
        if (empty($result['ok'])) {
            return $result;
        }

        return [
            'ok' => true,
            'grades' => $result['data']['usergrades'] ?? $result['data'] ?? [],
            'http_code' => (int)($result['http_code'] ?? 200),
        ];
    }

    public function getUserProgress(int $userId, int $courseId): array
    {
        $result = $this->request('core_completion_get_course_completion_status', [
            'userid' => $userId,
            'courseid' => $courseId,
        ]);
        if (empty($result['ok']) && (($result['data']['errorcode'] ?? '') === 'nocriteriaset')) {
            return [
                'ok' => true,
                'progress' => [],
                'http_code' => (int)($result['http_code'] ?? 200),
                'no_criteria' => true,
            ];
        }
        if (empty($result['ok'])) {
            return $result;
        }

        return [
            'ok' => true,
            'progress' => $result['data']['completionstatus'] ?? $result['data'] ?? [],
            'http_code' => (int)($result['http_code'] ?? 200),
        ];
    }

    public function resolveOrCreateMoodleUser(array $localUser): array
    {
        $email = trim((string)($localUser['email'] ?? ''));
        if ($email !== '') {
            $existing = $this->findUserByEmail($email);
            if (!empty($existing['ok']) && is_array($existing['user'] ?? null)) {
                return ['ok' => true, 'user' => $existing['user'], 'created' => false];
            }
        }

        return $this->createUser($localUser);
    }

    public function findUserByEmail(string $email): array
    {
        $email = trim($email);
        if ($email === '') {
            return ['ok' => false, 'error' => 'Email required', 'http_code' => 422];
        }

        $result = $this->request('core_user_get_users', [
            'criteria' => [[
                'key' => 'email',
                'value' => $email,
            ]],
        ]);
        if (empty($result['ok'])) {
            return $result;
        }

        $users = is_array($result['data']['users'] ?? null) ? $result['data']['users'] : [];
        return ['ok' => true, 'user' => isset($users[0]) && is_array($users[0]) ? $users[0] : null];
    }

    private function request(string $function, array $params = [], bool $allowEmptyResponse = false): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Moodle integration is not configured', 'http_code' => 503];
        }

        $settings = $this->settings();
        $endpoint = rtrim((string)$settings['moodle_url'], '/') . '/webservice/rest/server.php';
        $formParams = array_merge([
            'wstoken' => (string)$settings['api_token'],
            'moodlewsrestformat' => 'json',
            'wsfunction' => $function,
        ], $this->flattenParams($params));

        $attempt = 0;
        $maxAttempts = 3;
        do {
            $attempt++;
            $ch = curl_init($endpoint);
            if ($ch === false) {
                return ['ok' => false, 'error' => 'curl_init failed', 'http_code' => 0];
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($formParams),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT => 15,
            ]);

            $body = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false) {
                if ($attempt < $maxAttempts) {
                    usleep(150000 * $attempt);
                    continue;
                }
                return ['ok' => false, 'error' => $error !== '' ? $error : 'Moodle request failed', 'http_code' => 0];
            }

            $trimmedBody = trim((string)$body);
            if ($allowEmptyResponse && ($trimmedBody === '' || $trimmedBody === 'null') && $httpCode >= 200 && $httpCode < 300) {
                return ['ok' => true, 'data' => [], 'http_code' => $httpCode];
            }

            $decoded = json_decode((string)$body, true);
            if (!is_array($decoded)) {
                if ($httpCode >= 500 && $attempt < $maxAttempts) {
                    usleep(150000 * $attempt);
                    continue;
                }
                return ['ok' => false, 'error' => 'Moodle returned non-JSON response', 'http_code' => $httpCode];
            }

            if (isset($decoded['exception']) || isset($decoded['errorcode'])) {
                $message = (string)($decoded['message'] ?? $decoded['errorcode'] ?? 'Moodle API error');
                return ['ok' => false, 'error' => $message, 'http_code' => $httpCode > 0 ? $httpCode : 502, 'data' => $decoded];
            }

            if (($httpCode === 429 || $httpCode >= 500) && $attempt < $maxAttempts) {
                usleep(150000 * $attempt);
                continue;
            }

            return ['ok' => $httpCode >= 200 && $httpCode < 300, 'data' => $decoded, 'http_code' => $httpCode];
        } while ($attempt < $maxAttempts);

        return ['ok' => false, 'error' => 'Moodle request exhausted retries', 'http_code' => 504];
    }

    private function filterCoursesForTenant(array $courses): array
    {
        $settings = $this->settings();
        if (($settings['tenant_mode'] ?? 'per_instance') !== 'shared') {
            return $courses;
        }

        $mapRaw = trim((string)($settings['shared_category_map_json'] ?? ''));
        if ($mapRaw === '') {
            return $courses;
        }

        $map = json_decode($mapRaw, true);
        if (!is_array($map)) {
            return $courses;
        }

        $tenantKey = (string)$this->tenantId;
        $categoryId = isset($map[$tenantKey]) ? (int)$map[$tenantKey] : 0;
        if ($categoryId <= 0) {
            return $courses;
        }

        return array_values(array_filter($courses, static function (array $course) use ($categoryId): bool {
            return (int)($course['categoryid'] ?? 0) === $categoryId;
        }));
    }

    private function flattenParams(array $params, string $prefix = ''): array
    {
        $result = [];
        foreach ($params as $key => $value) {
            $compoundKey = $prefix === '' ? (string)$key : $prefix . '[' . $key . ']';
            if (is_array($value)) {
                $result += $this->flattenParams($value, $compoundKey);
                continue;
            }

            $result[$compoundKey] = $value;
        }

        return $result;
    }

    private function temporaryPassword(): string
    {
        return 'Tmp!' . substr(hash('sha256', uniqid('mi_', true)), 0, 10);
    }
}