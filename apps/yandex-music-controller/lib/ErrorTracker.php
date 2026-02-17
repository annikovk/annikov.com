<?php
/**
 * ErrorTracker class - Core logic for tracking plugin errors
 */

class ErrorTracker
{
    private Database $db;

    /**
     * @param Database $db Database instance
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Track an error report
     *
     * @param array $data Error data from client
     * @return bool True if successfully tracked
     * @throws InvalidArgumentException If data is invalid
     * @throws RuntimeException If tracking fails
     */
    public function track(array $data): bool
    {
        // Validate required fields
        $this->validateErrorData($data);

        // Normalize and sanitize data
        $normalized = $this->normalizeData($data);

        // Extract metadata (IP, user-agent)
        $metadata = $this->extractMetadata();

        try {
            // Insert error record
            $this->db->execute(
                'INSERT INTO errors (
                    timestamp, ip_address,
                    installation_id, platform, error_message, stack_trace
                ) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    time(),
                    $metadata['ip'],
                    $normalized['installation_id'],
                    $normalized['platform'],
                    $normalized['error_message'],
                    $normalized['stack_trace'],
                ]
            );

            return true;

        } catch (PDOException $e) {
            throw new RuntimeException('Failed to track error');
        }
    }

    /**
     * Get error statistics
     *
     * @return array Statistics data
     */
    public function getStats(): array
    {
        try {
            $stats = [
                'total_errors' => 0,
                'unique_users_affected' => 0,
                'errors_24h' => 0,
                'users_affected_24h' => 0,
                'users_affected_7d' => 0,
                'users_affected_30d' => 0,
            ];

            // Total error reports
            $result = $this->db->fetchOne('SELECT COUNT(*) as count FROM errors');
            $stats['total_errors'] = $result !== null ? (int)$result['count'] : 0;

            // Unique users affected (all time)
            $stats['unique_users_affected'] = $this->getUniqueErroringUsers('all');

            // Errors in last 24 hours
            $cutoff24h = time() - (24 * 3600);
            $result = $this->db->fetchOne(
                'SELECT COUNT(*) as count FROM errors WHERE timestamp >= ?',
                [$cutoff24h]
            );
            $stats['errors_24h'] = $result !== null ? (int)$result['count'] : 0;

            // Unique users affected in different time periods
            $stats['users_affected_24h'] = $this->getUniqueErroringUsers('24h');
            $stats['users_affected_7d'] = $this->getUniqueErroringUsers('7d');
            $stats['users_affected_30d'] = $this->getUniqueErroringUsers('30d');

            return $stats;

        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get unique users that reported errors in a time period
     *
     * @param string $period Time period ('24h', '7d', '30d', or 'all')
     * @return int Count of unique users
     */
    public function getUniqueErroringUsers(string $period): int
    {
        try {
            $sql = 'SELECT COUNT(DISTINCT installation_id) as count
                    FROM errors
                    WHERE installation_id IS NOT NULL AND installation_id != ""';

            $params = [];

            if ($period !== 'all') {
                $cutoffMap = [
                    '24h' => 24 * 3600,
                    '7d' => 7 * 24 * 3600,
                    '30d' => 30 * 24 * 3600,
                ];

                if (isset($cutoffMap[$period])) {
                    $cutoff = time() - $cutoffMap[$period];
                    $sql .= ' AND timestamp >= ?';
                    $params[] = $cutoff;
                }
            }

            $result = $this->db->fetchOne($sql, $params);
            return $result !== null ? (int)$result['count'] : 0;

        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Get recent error reports with joined installation data, grouped by installation_id and time
     *
     * @param int $limit Maximum number of error groups to return
     * @return array Recent error groups
     */
    public function getRecentErrors(int $limit = 50): array
    {
        try {
            // Fetch more errors than limit to have enough data for grouping
            $fetchLimit = $limit * 4;

            // Get recent errors with latest installation data joined.
            // platform comes directly from errors table (e.*).
            // Join installations on (installation_id, platform) when error has platform,
            // otherwise fall back to installation_id only.
            $errors = $this->db->fetchAll(
                'SELECT
                    e.*,
                    i.plugin_version,
                    i.os_release
                FROM errors e
                LEFT JOIN (
                    SELECT installation_id, platform, plugin_version, os_release
                    FROM installations
                    WHERE installation_id != ""
                    GROUP BY installation_id, platform
                    HAVING MAX(timestamp)
                ) i ON e.installation_id = i.installation_id
                    AND e.installation_id != ""
                    AND (
                        (e.platform IS NOT NULL AND e.platform != "" AND i.platform = e.platform)
                        OR (e.platform IS NULL OR e.platform = "")
                    )
                ORDER BY e.id DESC
                LIMIT ?',
                [$fetchLimit]
            );

            // Group errors by installation_id and time proximity (10 seconds)
            $groups = $this->groupErrors($errors);

            // Slice to requested limit after grouping
            return array_slice($groups, 0, $limit);

        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Group errors by installation_id and time proximity (10 seconds)
     *
     * @param array $errors Flat array of errors
     * @return array Grouped errors
     */
    private function groupErrors(array $errors): array
    {
        $groups = [];
        $timeWindow = 10; // seconds

        foreach ($errors as $error) {
            $installationId = $error['installation_id'] ?? '';

            // Don't group errors with empty installation_id
            if (empty($installationId)) {
                // Add as standalone group
                $groups[] = [
                    'primary_error' => $error,
                    'installation_id' => $installationId,
                    'count' => 1,
                    'earliest_timestamp' => $error['timestamp'],
                    'latest_timestamp' => $error['timestamp'],
                    'error_types' => [],
                    'grouped_errors' => []
                ];
                continue;
            }

            // Try to find existing group for this installation_id within time window
            $foundGroup = false;

            foreach ($groups as &$group) {
                // Check if same installation_id
                if ($group['installation_id'] === $installationId) {
                    // Check if within time window
                    $timeDiff = abs($group['latest_timestamp'] - $error['timestamp']);

                    if ($timeDiff <= $timeWindow) {
                        // Add to this group
                        $group['grouped_errors'][] = $error;
                        $group['count']++;
                        $group['latest_timestamp'] = max($group['latest_timestamp'], $error['timestamp']);
                        $group['earliest_timestamp'] = min($group['earliest_timestamp'], $error['timestamp']);

                        // Add error type (first 50 chars) if not already present
                        $errorType = substr($error['error_message'], 0, 50);
                        if (!in_array($errorType, $group['error_types'])) {
                            $group['error_types'][] = $errorType;
                        }

                        $foundGroup = true;
                        break;
                    }
                }
            }
            unset($group); // Break reference

            // If no group found, create new one
            if (!$foundGroup) {
                $errorType = substr($error['error_message'], 0, 50);
                $groups[] = [
                    'primary_error' => $error,
                    'installation_id' => $installationId,
                    'count' => 1,
                    'earliest_timestamp' => $error['timestamp'],
                    'latest_timestamp' => $error['timestamp'],
                    'error_types' => [$errorType],
                    'grouped_errors' => []
                ];
            }
        }

        return $groups;
    }

    /**
     * Get error breakdown grouped by error message pattern
     *
     * @return array Array of error patterns with counts
     */
    public function getErrorBreakdown(): array
    {
        try {
            // Group similar errors by first 100 characters
            return $this->db->fetchAll(
                'SELECT
                    LEFT(error_message, 100) as error_pattern,
                    COUNT(*) as count,
                    COUNT(DISTINCT installation_id) as unique_users
                FROM errors
                GROUP BY error_pattern
                ORDER BY count DESC
                LIMIT 10'
            );
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Validate error data
     *
     * @param array $data Error data
     * @return void
     * @throws InvalidArgumentException If validation fails
     */
    private function validateErrorData(array $data): void
    {
        // error_message is required
        if (!isset($data['error_message']) || !is_string($data['error_message']) || empty(trim($data['error_message']))) {
            throw new InvalidArgumentException('error_message is required and must be a non-empty string');
        }

        // installation_id can be empty string (valid when not initialized)
        if (isset($data['installation_id']) && !is_string($data['installation_id'])) {
            throw new InvalidArgumentException('installation_id must be a string');
        }

        // stack_trace is optional
        if (isset($data['stack_trace']) && !is_string($data['stack_trace'])) {
            throw new InvalidArgumentException('stack_trace must be a string');
        }

        // platform is optional
        if (isset($data['platform']) && !is_string($data['platform'])) {
            throw new InvalidArgumentException('platform must be a string');
        }
    }

    /**
     * Normalize and sanitize error data
     *
     * @param array $data Raw error data
     * @return array Normalized data
     */
    private function normalizeData(array $data): array
    {
        return [
            'installation_id' => isset($data['installation_id']) ? substr($data['installation_id'], 0, 255) : '',
            'platform' => isset($data['platform']) ? substr(trim($data['platform']), 0, 50) : null,
            'error_message' => substr(trim($data['error_message']), 0, 2000),
            'stack_trace' => isset($data['stack_trace']) ? substr($data['stack_trace'], 0, 10000) : null,
        ];
    }

    /**
     * Extract request metadata
     *
     * @return array Metadata (ip)
     */
    private function extractMetadata(): array
    {
        return [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ];
    }
}
