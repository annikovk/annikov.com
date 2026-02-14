<?php
/**
 * InstallationTracker class - Core logic for tracking plugin installations
 */

class InstallationTracker
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
     * Track an installation report
     *
     * @param array $data Installation data from client
     * @return bool True if successfully tracked
     * @throws InvalidArgumentException If data is invalid
     * @throws RuntimeException If tracking fails
     */
    public function track(array $data): bool
    {
        // Validate required fields
        $this->validateInstallationData($data);

        // Normalize and sanitize data
        $normalized = $this->normalizeData($data);

        // Extract metadata (IP, user-agent)
        $metadata = $this->extractMetadata();

        try {
            // Insert installation record
            $this->db->execute(
                'INSERT INTO installations (
                    timestamp, ip_address, user_agent,
                    platform, os_version, os_release, plugin_version, node_version,
                    yandex_music_connected, yandex_music_path,
                    stream_deck_version, stream_deck_language,
                    installation_id, extra_data
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    time(),
                    $metadata['ip'],
                    $metadata['user_agent'],
                    $normalized['platform'],
                    $normalized['os_version'],
                    $normalized['os_release'],
                    $normalized['plugin_version'],
                    $normalized['node_version'],
                    $normalized['yandex_music_connected'] ? 1 : 0,
                    $normalized['yandex_music_path'],
                    $normalized['stream_deck_version'],
                    $normalized['stream_deck_language'],
                    $normalized['installation_id'],
                    $normalized['extra_data'] ? json_encode($normalized['extra_data']) : null,
                ]
            );

            return true;

        } catch (PDOException $e) {
            throw new RuntimeException('Failed to track installation');
        }
    }

    /**
     * Get installation statistics
     *
     * @return array Statistics data
     */
    public function getStats(): array
    {
        try {
            $stats = [
                'total_installations' => 0,
                'unique_installations' => 0,
                'installations_24h' => 0,
                'installations_7d' => 0,
                'installations_30d' => 0,
                'platform_breakdown' => [],
                'yandex_music_detection_rate' => 0,
            ];

            // Total installation reports
            $result = $this->db->fetchOne('SELECT COUNT(*) as count FROM installations');
            $stats['total_installations'] = $result !== null ? (int)$result['count'] : 0;

            // Unique installations (based on device IDs with no overlap)
            $stats['unique_installations'] = $this->getUniqueInstallationCount();

            // Unique installations in last 24 hours
            $cutoff24h = time() - (24 * 3600);
            $result = $this->db->fetchOne(
                'SELECT COUNT(DISTINCT installation_id) as count
                 FROM installations
                 WHERE timestamp >= ? AND installation_id IS NOT NULL AND installation_id != ""',
                [$cutoff24h]
            );
            $stats['installations_24h'] = $result !== null ? (int)$result['count'] : 0;

            // Unique installations in last 7 days
            $cutoff7d = time() - (7 * 24 * 3600);
            $result = $this->db->fetchOne(
                'SELECT COUNT(DISTINCT installation_id) as count
                 FROM installations
                 WHERE timestamp >= ? AND installation_id IS NOT NULL AND installation_id != ""',
                [$cutoff7d]
            );
            $stats['installations_7d'] = $result !== null ? (int)$result['count'] : 0;

            // Unique installations in last 30 days
            $cutoff30d = time() - (30 * 24 * 3600);
            $result = $this->db->fetchOne(
                'SELECT COUNT(DISTINCT installation_id) as count
                 FROM installations
                 WHERE timestamp >= ? AND installation_id IS NOT NULL AND installation_id != ""',
                [$cutoff30d]
            );
            $stats['installations_30d'] = $result !== null ? (int)$result['count'] : 0;

            // Platform breakdown
            $stats['platform_breakdown'] = $this->db->fetchAll(
                'SELECT platform, COUNT(*) as count FROM installations GROUP BY platform ORDER BY count DESC'
            );

            // Yandex Music detection rate
            $totalInstalls = $stats['total_installations'];
            if ($totalInstalls > 0) {
                $result = $this->db->fetchOne(
                    'SELECT COUNT(*) as count FROM installations WHERE yandex_music_connected = 1'
                );
                $connected = $result !== null ? (int)$result['count'] : 0;
                $stats['yandex_music_detection_rate'] = round(($connected / $totalInstalls) * 100, 1);
            }

            return $stats;

        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get unique installation count
     * Counts distinct installation_ids (each installation_id represents one unique installation)
     *
     * @return int Unique installation count
     */
    public function getUniqueInstallationCount(): int
    {
        try {
            $result = $this->db->fetchOne(
                'SELECT COUNT(DISTINCT installation_id) as count
                 FROM installations
                 WHERE installation_id IS NOT NULL AND installation_id != ""'
            );
            return $result !== null ? (int)$result['count'] : 0;

        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Get recent installation reports (latest report for each unique installation_id)
     *
     * @param int $limit Maximum number of installations to return
     * @return array Recent installations
     */
    public function getRecentInstallations(int $limit = 50): array
    {
        try {
            // Get the latest report for each installation_id
            return $this->db->fetchAll(
                'SELECT i1.*
                 FROM installations i1
                 INNER JOIN (
                     SELECT installation_id, MAX(id) as max_id
                     FROM installations
                     WHERE installation_id IS NOT NULL AND installation_id != ""
                     GROUP BY installation_id
                 ) i2 ON i1.id = i2.max_id
                 ORDER BY i1.id DESC
                 LIMIT ?',
                [$limit]
            );
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get plugin version breakdown for pie chart (counts latest report per installation_id)
     *
     * @return array Array of ['version' => 'x.x.x', 'count' => N]
     */
    public function getVersionBreakdown(): array
    {
        try {
            return $this->db->fetchAll(
                'SELECT plugin_version as version, COUNT(*) as count
                 FROM (
                     SELECT i1.plugin_version
                     FROM installations i1
                     INNER JOIN (
                         SELECT installation_id, MAX(id) as max_id
                         FROM installations
                         WHERE installation_id IS NOT NULL AND installation_id != ""
                         GROUP BY installation_id
                     ) i2 ON i1.id = i2.max_id
                 ) latest
                 GROUP BY plugin_version
                 ORDER BY count DESC'
            );
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get OS distribution breakdown for pie chart (counts latest report per installation_id)
     * OS is combination of platform and os_release
     *
     * @return array Array of ['os' => 'platform os_release', 'count' => N]
     */
    public function getOSBreakdown(): array
    {
        try {
            return $this->db->fetchAll(
                'SELECT
                    CONCAT(platform, \' \', COALESCE(os_release, \'unknown\')) as os,
                    COUNT(*) as count
                 FROM (
                     SELECT i1.platform, i1.os_release
                     FROM installations i1
                     INNER JOIN (
                         SELECT installation_id, MAX(id) as max_id
                         FROM installations
                         WHERE installation_id IS NOT NULL AND installation_id != ""
                         GROUP BY installation_id
                     ) i2 ON i1.id = i2.max_id
                 ) latest
                 GROUP BY os
                 ORDER BY count DESC'
            );
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get installations grouped by IP address
     *
     * @return array Installations per IP
     */
    public function getInstallationsByIP(): array
    {
        try {
            return $this->db->fetchAll(
                'SELECT
                    ip_address,
                    COUNT(*) as installation_count,
                    GROUP_CONCAT(DISTINCT plugin_version ORDER BY plugin_version SEPARATOR \', \') AS versions,
                    FROM_UNIXTIME(MIN(timestamp)) AS first_reported,
                    FROM_UNIXTIME(MAX(timestamp)) AS last_reported
                 FROM installations
                 WHERE ip_address IS NOT NULL
                 GROUP BY ip_address
                 ORDER BY installation_count DESC'
            );
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Validate installation data
     *
     * @param array $data Installation data
     * @return void
     * @throws InvalidArgumentException If validation fails
     */
    private function validateInstallationData(array $data): void
    {
        $required = [
            'platform',
            'osVersion',
            'osRelease',
            'pluginVersion',
            'nodeVersion',
            'yandexMusicConnected',
            'installation_id'
        ];

        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }

        // Validate types
        if (!is_string($data['platform']) || empty($data['platform'])) {
            throw new InvalidArgumentException('Invalid platform');
        }

        if (!is_bool($data['yandexMusicConnected'])) {
            throw new InvalidArgumentException('Invalid yandexMusicConnected (must be boolean)');
        }

        if (!is_string($data['installation_id']) || empty($data['installation_id'])) {
            throw new InvalidArgumentException('Invalid installation_id (must be non-empty string)');
        }
    }

    /**
     * Normalize and sanitize installation data
     *
     * @param array $data Raw installation data
     * @return array Normalized data
     */
    private function normalizeData(array $data): array
    {
        // Known fields
        $knownFields = [
            'platform',
            'osVersion',
            'osRelease',
            'pluginVersion',
            'nodeVersion',
            'yandexMusicConnected',
            'yandexMusicPath',
            'streamDeckVersion',
            'streamDeckLanguage',
            'installation_id'
        ];

        $normalized = [
            'platform' => substr($data['platform'], 0, 50),
            'os_version' => isset($data['osVersion']) ? substr($data['osVersion'], 0, 100) : null,
            'os_release' => isset($data['osRelease']) ? substr($data['osRelease'], 0, 100) : null,
            'plugin_version' => substr($data['pluginVersion'], 0, 50),
            'node_version' => isset($data['nodeVersion']) ? substr($data['nodeVersion'], 0, 50) : null,
            'yandex_music_connected' => (bool)$data['yandexMusicConnected'],
            'yandex_music_path' => isset($data['yandexMusicPath']) ? substr($data['yandexMusicPath'], 0, 500) : null,
            'stream_deck_version' => isset($data['streamDeckVersion']) ? substr($data['streamDeckVersion'], 0, 50) : null,
            'stream_deck_language' => isset($data['streamDeckLanguage']) ? substr($data['streamDeckLanguage'], 0, 20) : null,
            'installation_id' => substr($data['installation_id'], 0, 255),
            'extra_data' => null,
        ];

        // Extract unknown fields into extra_data
        $extraData = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, $knownFields)) {
                $extraData[$key] = $value;
            }
        }

        if (!empty($extraData)) {
            $normalized['extra_data'] = $extraData;
        }

        return $normalized;
    }

    /**
     * Extract request metadata
     *
     * @return array Metadata (ip, user_agent)
     */
    private function extractMetadata(): array
    {
        return [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];
    }
}
