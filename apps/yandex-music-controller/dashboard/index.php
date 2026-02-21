<?php
/**
 * Statistics Dashboard for Action Tracker
 *
 * Protected by HTTP Basic Auth
 */

// Load configuration and classes
$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/ActionTracker.php';
require_once dirname(__DIR__) . '/lib/InstallationTracker.php';
require_once dirname(__DIR__) . '/lib/ErrorTracker.php';

// Read filters from GET (sanitized)
$filterVersion    = isset($_GET['version'])         && $_GET['version'] !== ''         ? substr(trim($_GET['version']), 0, 50)        : null;
$filterIP         = isset($_GET['ip'])              && $_GET['ip'] !== ''              ? substr(trim($_GET['ip']), 0, 45)             : null;
$filterInstallId  = isset($_GET['installation_id']) && $_GET['installation_id'] !== '' ? substr(trim($_GET['installation_id']), 0, 255) : null;
$activeFilters = array_filter(['version' => $filterVersion, 'ip' => $filterIP, 'installation_id' => $filterInstallId]);

try {
    $db = Database::getInstance($config['database']);
    $tracker = new ActionTracker($db);
    $installationTracker = new InstallationTracker($db);

    // Get statistics (filtered)
    $stats = $tracker->getStats($activeFilters);
    $topActions = $tracker->getTopActions(10, $activeFilters);
    $visitorSummary = $tracker->getVisitorSummary($activeFilters);

    // Get installation statistics (filtered)
    $installationStats = $installationTracker->getStats($activeFilters);
    $versionBreakdown = $installationTracker->getVersionBreakdown($activeFilters);
    $osBreakdown = $installationTracker->getOSBreakdown($activeFilters);
    $recentInstallations = $installationTracker->getRecentInstallations(50, $activeFilters);

    // Get error statistics (filtered)
    $errorTracker = new ErrorTracker($db);
    $errorStats = $errorTracker->getStats($activeFilters);
    $recentErrors = $errorTracker->getRecentErrors(50, $activeFilters);

    // Fetch available filter options (always unfiltered, for dropdown population)
    $availableVersions = $db->fetchAll(
        "SELECT DISTINCT plugin_version as version FROM installations WHERE plugin_version IS NOT NULL AND plugin_version != '' ORDER BY plugin_version DESC LIMIT 100"
    );
    $availableIPs = $db->fetchAll(
        "SELECT ip_address FROM (
            SELECT ip_address FROM actions WHERE ip_address IS NOT NULL AND ip_address != ''
            UNION
            SELECT ip_address FROM installations WHERE ip_address IS NOT NULL AND ip_address != ''
            UNION
            SELECT ip_address FROM errors WHERE ip_address IS NOT NULL AND ip_address != ''
        ) c GROUP BY ip_address ORDER BY ip_address LIMIT 500"
    );
    $availableInstallationIds = $db->fetchAll(
        "SELECT installation_id FROM (
            SELECT installation_id FROM installations WHERE installation_id IS NOT NULL AND installation_id != ''
            UNION
            SELECT installation_id FROM errors WHERE installation_id IS NOT NULL AND installation_id != ''
        ) c GROUP BY installation_id ORDER BY installation_id LIMIT 500"
    );

} catch (Exception $e) {
    $stats = [];
    $topActions = [];
    $visitorSummary = [];
    $installationStats = [];
    $versionBreakdown = [];
    $osBreakdown = [];
    $recentInstallations = [];
    $errorStats = [];
    $recentErrors = [];
    $availableVersions = [];
    $availableIPs = [];
    $availableInstallationIds = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Action Tracker Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            /* Light theme colors */
            --bg-primary: #f8fafc;
            --bg-secondary: #ffffff;
            --text-primary: #0f172a;
            --text-secondary: #64748b;
            --accent: #4f46e5;
            --accent-light: #6366f1;
            --accent-subtle: #eef2ff;
            --border: #e5e7eb;
            --hover-bg: #f1f5f9;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --radius-md: 12px;
            --radius-lg: 16px;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg-primary: #0b0c0f;
                --bg-secondary: #10131a;
                --text-primary: #e5e7eb;
                --text-secondary: #9ca3af;
                --accent: #818cf8;
                --accent-light: #a5b4fc;
                --accent-subtle: #1e1b4b;
                --border: #1f2937;
                --hover-bg: #1a1d28;
                --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.3);
                --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.4), 0 2px 4px -1px rgba(0, 0, 0, 0.3);
                --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.5), 0 4px 6px -2px rgba(0, 0, 0, 0.4);
            }
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: var(--bg-primary);
            min-height: 100vh;
            padding: 30px 20px;
            color: var(--text-primary);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Tab Interface */
        .tabs-container {
            margin-bottom: 30px;
        }

        .tabs-header {
            display: flex;
            gap: 8px;
            background: var(--bg-secondary);
            padding: 6px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            width: fit-content;
        }

        .tab-button {
            padding: 10px 24px;
            border: none;
            background: transparent;
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.2s ease;
            letter-spacing: 0.3px;
        }

        .tab-button:hover {
            background: var(--hover-bg);
            color: var(--text-primary);
        }

        .tab-button.active {
            background: var(--accent);
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .tab-panel {
            display: none;
        }

        .tab-panel.active {
            display: block;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--bg-secondary);
            padding: 24px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
            transition: all 0.2s ease;
        }

        @media (prefers-reduced-motion: no-preference) {
            .stat-card:hover {
                transform: translateY(-2px);
                box-shadow: var(--shadow-lg);
                border-color: var(--accent-subtle);
            }
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--accent);
            letter-spacing: -0.5px;
        }

        /* Sections */
        .section {
            background: var(--bg-secondary);
            padding: 30px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
            margin-bottom: 24px;
        }

        h2 {
            font-size: 18px;
            margin-bottom: 20px;
            color: var(--text-primary);
            font-weight: 700;
            letter-spacing: -0.3px;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            text-align: left;
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
        }

        th {
            background: var(--hover-bg);
            font-weight: 700;
            color: var(--text-secondary);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        td {
            font-size: 14px;
            color: var(--text-primary);
        }

        tbody tr {
            transition: background 0.15s ease;
        }

        tbody tr:hover {
            background: var(--hover-bg);
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        .timestamp {
            color: var(--text-secondary);
            font-size: 13px;
        }

        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
            font-size: 14px;
        }

        /* Sortable columns */
        .sortable {
            cursor: pointer;
            user-select: none;
            position: relative;
            padding-right: 24px;
            transition: background 0.15s ease;
        }

        .sortable:hover {
            background: var(--accent-subtle);
        }

        .sortable::after {
            content: '⇅';
            position: absolute;
            right: 8px;
            opacity: 0.3;
            font-size: 11px;
        }

        .sortable.asc::after {
            content: '▲';
            opacity: 1;
            color: var(--accent);
        }

        .sortable.desc::after {
            content: '▼';
            opacity: 1;
            color: var(--accent);
        }

        /* Charts */
        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 20px 16px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }

            .section {
                padding: 20px;
            }

            table {
                font-size: 13px;
            }

            th, td {
                padding: 10px 12px;
            }

            .chart-container {
                height: 250px;
            }

            .stat-value {
                font-size: 28px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .tabs-header {
                width: 100%;
            }

            .tab-button {
                flex: 1;
                text-align: center;
            }

            .stat-value {
                font-size: 24px;
            }
        }

        /* Focus styles for accessibility */
        .tab-button:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 2px;
        }

        .sortable:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: -2px;
        }

        /* Error Grouping Styles */
        /* Primary row with grouped errors */
        .error-group-row.has-grouped {
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .error-group-row.has-grouped:hover {
            background: var(--hover-bg);
        }

        /* Expand indicator (▶ becomes ▼) */
        .expand-indicator {
            display: inline-block;
            margin-left: 8px;
            font-size: 10px;
            color: var(--accent);
            transition: transform 0.2s ease;
        }

        .error-group-row.expanded .expand-indicator {
            transform: rotate(90deg);
        }

        /* Badge showing +N count */
        .grouped-badge {
            display: inline-block;
            margin-left: 8px;
            padding: 3px 10px;
            background: var(--accent-subtle);
            color: var(--accent);
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        /* Show error type variety */
        .error-type-pills {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            margin-top: 4px;
        }

        .error-type-pill {
            padding: 2px 6px;
            background: var(--hover-bg);
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 10px;
            color: var(--text-secondary);
        }

        /* Grouped error rows (children) */
        .grouped-error-row {
            display: none;  /* Hidden by default */
            background: var(--hover-bg);
            border-left: 3px solid var(--accent-subtle);
        }

        .grouped-error-row td {
            padding: 10px 16px;
            opacity: 0.85;
        }

        .grouped-error-row .timestamp {
            padding-left: 32px;  /* Indent */
        }

        .indent-arrow {
            display: inline-block;
            margin-right: 6px;
            color: var(--text-secondary);
        }

        /* Stack trace modal */
        .stack-trace-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.75);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .stack-trace-modal.active {
            display: flex;
        }

        .stack-trace-content {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 30px;
            max-width: 900px;
            max-height: 80vh;
            overflow: auto;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
        }

        .stack-trace-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .stack-trace-header h3 {
            margin: 0;
            font-size: 18px;
            color: var(--text-primary);
        }

        .close-btn {
            background: transparent;
            border: none;
            font-size: 32px;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 0;
            width: 32px;
            height: 32px;
            line-height: 1;
            transition: color 0.2s ease;
        }

        .close-btn:hover {
            color: var(--accent);
        }

        .stack-trace-body {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
            white-space: pre-wrap;
            background: var(--hover-bg);
            padding: 16px;
            border-radius: 8px;
            color: var(--text-primary);
        }

        /* Error message clickable */
        .error-message-truncated {
            cursor: pointer;
            transition: color 0.2s ease;
        }

        .error-message-truncated:hover {
            color: var(--accent);
        }

        @media (max-width: 768px) {
            .error-type-pills {
                font-size: 9px;
            }

            .grouped-badge {
                font-size: 10px;
                padding: 2px 8px;
            }

            .stack-trace-content {
                padding: 20px;
                max-width: 95%;
            }
        }

        /* Filter Bar */
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 12px 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .filter-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            white-space: nowrap;
        }

        .filter-select {
            padding: 7px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 13px;
            min-width: 160px;
            cursor: pointer;
            transition: border-color 0.15s ease;
        }

        .filter-select:hover,
        .filter-select:focus {
            border-color: var(--accent);
            outline: none;
        }

        .filter-select.active {
            border-color: var(--accent);
            background: var(--accent-subtle);
            color: var(--accent);
            font-weight: 600;
        }

        .filter-clear {
            padding: 7px 14px;
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-secondary);
            font-size: 13px;
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .filter-clear:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .filter-active-badge {
            display: inline-block;
            padding: 3px 10px;
            background: var(--accent);
            color: white;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        @media (max-width: 768px) {
            .filter-bar {
                gap: 8px;
            }

            .filter-select {
                min-width: 130px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="tabs-container">
            <div class="tabs-header" role="tablist" aria-label="Dashboard sections">
                <button
                    class="tab-button active"
                    data-tab="actions"
                    role="tab"
                    aria-selected="true"
                    aria-controls="tab-actions"
                    id="tab-btn-actions">
                    Actions
                </button>
                <button
                    class="tab-button"
                    data-tab="installations"
                    role="tab"
                    aria-selected="false"
                    aria-controls="tab-installations"
                    id="tab-btn-installations">
                    Installations
                </button>
                <button
                    class="tab-button"
                    data-tab="errors"
                    role="tab"
                    aria-selected="false"
                    aria-controls="tab-errors"
                    id="tab-btn-errors">
                    Errors
                </button>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <span class="filter-label">Filter:</span>

                <select id="filterVersion" class="filter-select <?= $filterVersion ? 'active' : '' ?>">
                    <option value="">All Versions</option>
                    <?php foreach ($availableVersions as $v): ?>
                    <option value="<?= htmlspecialchars($v['version']) ?>"
                        <?= $filterVersion === $v['version'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($v['version']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <select id="filterIP" class="filter-select <?= $filterIP ? 'active' : '' ?>">
                    <option value="">All IPs</option>
                    <?php foreach ($availableIPs as $row): ?>
                    <option value="<?= htmlspecialchars($row['ip_address']) ?>"
                        <?= $filterIP === $row['ip_address'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($row['ip_address']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <select id="filterInstallationId" class="filter-select <?= $filterInstallId ? 'active' : '' ?>">
                    <option value="">All Installations</option>
                    <?php foreach ($availableInstallationIds as $row): ?>
                    <option value="<?= htmlspecialchars($row['installation_id']) ?>"
                        <?= $filterInstallId === $row['installation_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(substr($row['installation_id'], 0, 20)) ?>…
                    </option>
                    <?php endforeach; ?>
                </select>

                <?php if (!empty($activeFilters)): ?>
                <button id="clearFilters" class="filter-clear">✕ Clear filters</button>
                <span class="filter-active-badge"><?= count($activeFilters) ?> active</span>
                <?php endif; ?>
            </div>

            <!-- Actions Tab -->
            <div id="tab-actions" class="tab-panel active" role="tabpanel" aria-labelledby="tab-btn-actions">
                <?php if (!empty($stats)): ?>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label">Total Actions</div>
                        <div class="stat-value"><?= number_format($stats['total_actions']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Unique Actions</div>
                        <div class="stat-value"><?= number_format($stats['unique_action_types']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Unique Visitors</div>
                        <div class="stat-value"><?= number_format($stats['unique_visitors']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Actions (24h)</div>
                        <div class="stat-value"><?= number_format($stats['actions_24h']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Visitors (24h)</div>
                        <div class="stat-value"><?= number_format($stats['unique_visitors_24h']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Actions (7d)</div>
                        <div class="stat-value"><?= number_format($stats['actions_7d']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Visitors (7d)</div>
                        <div class="stat-value"><?= number_format($stats['unique_visitors_7d']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Actions (30d)</div>
                        <div class="stat-value"><?= number_format($stats['actions_30d']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Visitors (30d)</div>
                        <div class="stat-value"><?= number_format($stats['unique_visitors_30d']) ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($topActions)): ?>
                <div class="section">
                    <h2>Top 10 Most Popular Actions</h2>
                    <table id="topActionsTable">
                        <thead>
                            <tr>
                                <th class="sortable" data-column="0" data-type="string">Action Name</th>
                                <th class="sortable" data-column="1" data-type="number" style="text-align: right;">Total Count</th>
                                <th class="sortable" data-column="2" data-type="number" style="text-align: right;">Unique Visitors</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topActions as $action): ?>
                            <tr>
                                <td><?= htmlspecialchars($action['action_name']) ?></td>
                                <td style="text-align: right;" data-value="<?= $action['total_count'] ?>"><?= number_format($action['total_count']) ?></td>
                                <td style="text-align: right;" data-value="<?= $action['unique_visitors'] ?>"><?= number_format($action['unique_visitors']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if (!empty($visitorSummary)): ?>
                <div class="section">
                    <h2>Visitor Summary</h2>
                    <table id="visitorSummaryTable">
                        <thead>
                            <tr>
                                <th class="sortable" data-column="0" data-type="ip">IP Address</th>
                                <th class="sortable" data-column="1" data-type="string">Actions Used</th>
                                <th class="sortable" data-column="2" data-type="number" style="text-align: right;">Total Actions</th>
                                <th class="sortable" data-column="3" data-type="date">First Executed</th>
                                <th class="sortable" data-column="4" data-type="date">Last Executed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visitorSummary as $visitor): ?>
                            <tr>
                                <td><?= htmlspecialchars($visitor['ip_address']) ?></td>
                                <td style="max-width: 400px; word-wrap: break-word;"><?= htmlspecialchars($visitor['actions_used']) ?></td>
                                <td style="text-align: right;" data-value="<?= $visitor['total_actions'] ?>"><?= number_format($visitor['total_actions']) ?></td>
                                <td class="timestamp" data-value="<?= htmlspecialchars($visitor['first_executed']) ?>"><?= htmlspecialchars($visitor['first_executed']) ?></td>
                                <td class="timestamp" data-value="<?= htmlspecialchars($visitor['last_executed']) ?>"><?= htmlspecialchars($visitor['last_executed']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="section">
                    <div class="no-data">No visitors tracked yet</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Installations Tab -->
            <div id="tab-installations" class="tab-panel" role="tabpanel" aria-labelledby="tab-btn-installations">
                <?php if (!empty($installationStats)): ?>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label">Unique Installations</div>
                        <div class="stat-value"><?= number_format($installationStats['unique_installations']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Installations (24h)</div>
                        <div class="stat-value"><?= number_format($installationStats['installations_24h']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Installations (7d)</div>
                        <div class="stat-value"><?= number_format($installationStats['installations_7d']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Installations (30d)</div>
                        <div class="stat-value"><?= number_format($installationStats['installations_30d']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">YM Detection Rate</div>
                        <div class="stat-value"><?= $installationStats['yandex_music_detection_rate'] ?>%</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">YM Connection Rate</div>
                        <div class="stat-value"><?= $installationStats['yandex_music_connection_rate'] ?>%</div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($versionBreakdown)): ?>
                <div class="section">
                    <h2>Plugin Version Distribution</h2>
                    <div class="chart-container">
                        <canvas id="versionChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($osBreakdown)): ?>
                <div class="section">
                    <h2>Operating System Distribution</h2>
                    <div class="chart-container">
                        <canvas id="osChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($recentInstallations)): ?>
                <div class="section">
                    <h2>Unique Installations (Latest Report per Installation ID)</h2>
                    <table id="installationsTable">
                        <thead>
                            <tr>
                                <th class="sortable" data-column="0" data-type="string">Installation ID</th>
                                <th class="sortable" data-column="1" data-type="ip">IP Address</th>
                                <th class="sortable" data-column="2" data-type="string">Platform</th>
                                <th class="sortable" data-column="3" data-type="string">Plugin Version</th>
                                <th class="sortable" data-column="4" data-type="string">Yandex Music</th>
                                <th class="sortable" data-column="5" data-type="date">Last Report</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentInstallations as $installation): ?>
                            <tr>
                                <td style="font-family: monospace; font-size: 12px;"><?= htmlspecialchars(substr($installation['installation_id'], 0, 16)) ?>...</td>
                                <td><?= htmlspecialchars($installation['ip_address'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($installation['platform']) ?></td>
                                <td><?= htmlspecialchars($installation['plugin_version']) ?></td>
                                <td><?php
                                    if ($installation['yandex_music_connected']) {
                                        echo '✓ Connected';
                                    } elseif (!empty($installation['yandex_music_path'])) {
                                        echo '✓ Detected';
                                    } else {
                                        echo '✗ Not Detected';
                                    }
                                ?></td>
                                <td class="timestamp" data-value="<?= date('Y-m-d H:i:s', $installation['timestamp']) ?>"><?= date('Y-m-d H:i:s', $installation['timestamp']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="section">
                    <div class="no-data">No installation reports yet</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Errors Tab -->
            <div id="tab-errors" class="tab-panel" role="tabpanel" aria-labelledby="tab-btn-errors">
                <?php if (!empty($errorStats)): ?>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label">Total Errors</div>
                        <div class="stat-value"><?= number_format($errorStats['total_errors']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Unique Users Affected</div>
                        <div class="stat-value"><?= number_format($errorStats['unique_users_affected']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Errors (24h)</div>
                        <div class="stat-value"><?= number_format($errorStats['errors_24h']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Users Affected (24h)</div>
                        <div class="stat-value"><?= number_format($errorStats['users_affected_24h']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Users Affected (7d)</div>
                        <div class="stat-value"><?= number_format($errorStats['users_affected_7d']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Users Affected (30d)</div>
                        <div class="stat-value"><?= number_format($errorStats['users_affected_30d']) ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($recentErrors)): ?>
                <div class="section">
                    <h2>Recent Errors</h2>
                    <table id="errorsTable">
                        <thead>
                            <tr>
                                <th class="sortable" data-column="0" data-type="date">Timestamp</th>
                                <th class="sortable" data-column="1" data-type="string">Error Message</th>
                                <th class="sortable" data-column="2" data-type="string">Installation ID</th>
                                <th class="sortable" data-column="3" data-type="string">Platform</th>
                                <th class="sortable" data-column="4" data-type="string">OS Release</th>
                                <th class="sortable" data-column="5" data-type="string">Version</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $groupIndex = 0;
                            foreach ($recentErrors as $group):
                                $primary = $group['primary_error'];
                                $hasGrouped = $group['count'] > 1;
                                $groupId = 'error-group-' . $groupIndex;
                            ?>

                            <!-- Primary row -->
                            <tr class="error-group-row <?= $hasGrouped ? 'has-grouped' : '' ?>"
                                data-group-id="<?= $groupId ?>"
                                data-has-grouped="<?= $hasGrouped ? '1' : '0' ?>">

                                <td class="timestamp" data-value="<?= date('Y-m-d H:i:s', $primary['timestamp']) ?>">
                                    <?= date('Y-m-d H:i:s', $primary['timestamp']) ?>
                                    <?php if ($hasGrouped): ?>
                                        <span class="expand-indicator">▶</span>
                                    <?php endif; ?>
                                </td>

                                <td style="font-family: monospace; font-size: 12px; max-width: 400px; word-wrap: break-word;">
                                    <div>
                                        <?php
                                        $fullMessage = htmlspecialchars($primary['error_message']);
                                        $isTruncated = strlen($fullMessage) > 100;
                                        $stackTrace = !empty($primary['stack_trace']) ? htmlspecialchars($primary['stack_trace'], ENT_QUOTES) : '';
                                        ?>
                                        <span class="<?= $isTruncated ? 'error-message-truncated' : '' ?>"
                                              <?= $isTruncated ? 'data-full-message="' . $fullMessage . '"' : '' ?>
                                              <?= $isTruncated && $stackTrace ? 'data-stack-trace="' . $stackTrace . '"' : '' ?>
                                              <?= $isTruncated ? 'title="Click to view full error message"' : '' ?>>
                                            <?= $isTruncated ? substr($fullMessage, 0, 100) . '...' : $fullMessage ?>
                                        </span>
                                        <?php if ($hasGrouped): ?>
                                            <span class="grouped-badge" title="<?= $group['count'] ?> errors in <?= $group['latest_timestamp'] - $group['earliest_timestamp'] ?>s">
                                                +<?= $group['count'] - 1 ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($hasGrouped && count($group['error_types']) > 1): ?>
                                        <div class="error-type-pills">
                                            <?php foreach (array_slice($group['error_types'], 0, 3) as $type): ?>
                                                <span class="error-type-pill"><?= htmlspecialchars($type) ?></span>
                                            <?php endforeach; ?>
                                            <?php if (count($group['error_types']) > 3): ?>
                                                <span class="error-type-pill">+<?= count($group['error_types']) - 3 ?> more</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td style="font-family: monospace; font-size: 11px;">
                                    <?php
                                    if (empty($primary['installation_id'])) {
                                        echo '<span style="color: var(--text-secondary); font-style: italic;">Not initialized</span>';
                                    } else {
                                        echo htmlspecialchars($primary['installation_id']);
                                    }
                                    ?>
                                </td>

                                <td><?= htmlspecialchars($primary['platform'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($primary['os_release'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($primary['plugin_version'] ?? 'N/A') ?></td>
                            </tr>

                            <!-- Grouped errors (hidden by default) -->
                            <?php if ($hasGrouped): ?>
                                <?php foreach ($group['grouped_errors'] as $error): ?>
                                <tr class="grouped-error-row" data-group-id="<?= $groupId ?>">
                                    <td class="timestamp" data-value="<?= date('Y-m-d H:i:s', $error['timestamp']) ?>">
                                        <span class="indent-arrow">↳</span>
                                        <?= date('Y-m-d H:i:s', $error['timestamp']) ?>
                                    </td>

                                    <td style="font-family: monospace; font-size: 12px; max-width: 400px; word-wrap: break-word;">
                                        <?php
                                        $fullMessage = htmlspecialchars($error['error_message']);
                                        $isTruncated = strlen($fullMessage) > 100;
                                        $stackTrace = !empty($error['stack_trace']) ? htmlspecialchars($error['stack_trace'], ENT_QUOTES) : '';
                                        ?>
                                        <span class="<?= $isTruncated ? 'error-message-truncated' : '' ?>"
                                              <?= $isTruncated ? 'data-full-message="' . $fullMessage . '"' : '' ?>
                                              <?= $isTruncated && $stackTrace ? 'data-stack-trace="' . $stackTrace . '"' : '' ?>
                                              <?= $isTruncated ? 'title="Click to view full error message"' : '' ?>>
                                            <?= $isTruncated ? substr($fullMessage, 0, 100) . '...' : $fullMessage ?>
                                        </span>
                                    </td>

                                    <td style="font-family: monospace; font-size: 11px;">
                                        <?php
                                        if (empty($error['installation_id'])) {
                                            echo '<span style="color: var(--text-secondary); font-style: italic;">Not initialized</span>';
                                        } else {
                                            echo htmlspecialchars($error['installation_id']);
                                        }
                                        ?>
                                    </td>

                                    <td><?= htmlspecialchars($error['platform'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($error['os_release'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($error['plugin_version'] ?? 'N/A') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php
                            $groupIndex++;
                            endforeach;
                            ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="section">
                    <div class="no-data">No errors reported yet</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Tab switching functionality
        function initTabs() {
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabPanels = document.querySelectorAll('.tab-panel');

            // Handle initial hash or default to 'actions'
            const initialTab = window.location.hash.slice(1) || 'actions';
            switchTab(initialTab);

            // Add click handlers to buttons
            tabButtons.forEach(button => {
                button.addEventListener('click', () => {
                    const tabName = button.dataset.tab;
                    switchTab(tabName);
                    window.location.hash = tabName;
                });
            });

            // Handle browser back/forward
            window.addEventListener('hashchange', () => {
                const tabName = window.location.hash.slice(1) || 'actions';
                switchTab(tabName);
            });
        }

        function switchTab(tabName) {
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabPanels = document.querySelectorAll('.tab-panel');

            // Deactivate all tabs
            tabButtons.forEach(btn => {
                btn.classList.remove('active');
                btn.setAttribute('aria-selected', 'false');
            });
            tabPanels.forEach(panel => {
                panel.classList.remove('active');
                panel.setAttribute('aria-hidden', 'true');
            });

            // Activate selected tab
            const targetButton = document.querySelector(`[data-tab="${tabName}"]`);
            const targetPanel = document.getElementById(`tab-${tabName}`);

            if (targetButton && targetPanel) {
                targetButton.classList.add('active');
                targetButton.setAttribute('aria-selected', 'true');
                targetPanel.classList.add('active');
                targetPanel.setAttribute('aria-hidden', 'false');
            }
        }

        // Table sorting functionality
        function sortTable(table, column, type, direction) {
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            rows.sort((a, b) => {
                let aVal = a.cells[column].getAttribute('data-value') || a.cells[column].textContent.trim();
                let bVal = b.cells[column].getAttribute('data-value') || b.cells[column].textContent.trim();

                // Convert values based on type
                if (type === 'number') {
                    aVal = parseFloat(aVal.replace(/,/g, '')) || 0;
                    bVal = parseFloat(bVal.replace(/,/g, '')) || 0;
                } else if (type === 'date') {
                    aVal = new Date(aVal).getTime() || 0;
                    bVal = new Date(bVal).getTime() || 0;
                } else if (type === 'ip') {
                    // Sort IP addresses correctly (e.g., 192.168.1.1)
                    aVal = aVal.split('.').map(num => num.padStart(3, '0')).join('.');
                    bVal = bVal.split('.').map(num => num.padStart(3, '0')).join('.');
                } else {
                    // String comparison (case-insensitive)
                    aVal = aVal.toLowerCase();
                    bVal = bVal.toLowerCase();
                }

                if (aVal < bVal) return direction === 'asc' ? -1 : 1;
                if (aVal > bVal) return direction === 'asc' ? 1 : -1;
                return 0;
            });

            // Re-append rows in sorted order
            rows.forEach(row => tbody.appendChild(row));
        }

        // Filter bar
        function initFilters() {
            ['filterVersion', 'filterIP', 'filterInstallationId'].forEach(id => {
                document.getElementById(id)?.addEventListener('change', applyFilters);
            });
            document.getElementById('clearFilters')?.addEventListener('click', () => {
                ['filterVersion', 'filterIP', 'filterInstallationId'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.value = '';
                });
                applyFilters();
            });
        }

        function applyFilters() {
            const params = new URLSearchParams();
            const version = document.getElementById('filterVersion')?.value;
            const ip = document.getElementById('filterIP')?.value;
            const instId = document.getElementById('filterInstallationId')?.value;
            if (version) params.set('version', version);
            if (ip) params.set('ip', ip);
            if (instId) params.set('installation_id', instId);
            const hash = window.location.hash || '#actions';
            const qs = params.toString();
            window.location.href = (qs ? '?' + qs : window.location.pathname) + hash;
        }

        // Initialize everything on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize tabs
            initTabs();

            // Initialize filters
            initFilters();

            // Initialize sorting for all tables
            document.querySelectorAll('.sortable').forEach(header => {
                header.addEventListener('click', function() {
                    const table = this.closest('table');
                    const column = parseInt(this.getAttribute('data-column'));
                    const type = this.getAttribute('data-type');

                    // Toggle sort direction
                    let direction = 'asc';
                    if (this.classList.contains('asc')) {
                        direction = 'desc';
                    }

                    // Remove sort classes from all headers in this table
                    table.querySelectorAll('.sortable').forEach(h => {
                        h.classList.remove('asc', 'desc');
                    });

                    // Add sort class to clicked header
                    this.classList.add(direction);

                    // Sort the table
                    sortTable(table, column, type, direction);
                });
            });

            // Initialize version breakdown pie chart
            <?php if (!empty($versionBreakdown)): ?>
            const versionLabels = <?php echo json_encode(array_column($versionBreakdown, 'version')); ?>;
            const versionData = <?php echo json_encode(array_column($versionBreakdown, 'count')); ?>;

            const ctx = document.getElementById('versionChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: versionLabels,
                        datasets: [{
                            data: versionData,
                            backgroundColor: [
                                '#4f46e5',
                                '#06b6d4',
                                '#10b981',
                                '#f59e0b',
                                '#ef4444',
                                '#8b5cf6',
                                '#ec4899',
                                '#6366f1'
                            ],
                            borderWidth: 2,
                            borderColor: 'var(--bg-secondary)'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    padding: 15,
                                    font: {
                                        size: 12,
                                        family: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto'
                                    },
                                    color: 'var(--text-primary)'
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = ((value / total) * 100).toFixed(1);
                                        return label + ': ' + value + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }
            <?php endif; ?>

            // Initialize OS distribution pie chart
            <?php if (!empty($osBreakdown)): ?>
            const osLabels = <?php echo json_encode(array_column($osBreakdown, 'os')); ?>;
            const osData = <?php echo json_encode(array_column($osBreakdown, 'count')); ?>;

            const osCtx = document.getElementById('osChart');
            if (osCtx) {
                new Chart(osCtx, {
                    type: 'pie',
                    data: {
                        labels: osLabels,
                        datasets: [{
                            data: osData,
                            backgroundColor: [
                                '#06b6d4',
                                '#4f46e5',
                                '#10b981',
                                '#f59e0b',
                                '#8b5cf6',
                                '#ef4444',
                                '#ec4899',
                                '#6366f1'
                            ],
                            borderWidth: 2,
                            borderColor: 'var(--bg-secondary)'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    padding: 15,
                                    font: {
                                        size: 12,
                                        family: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto'
                                    },
                                    color: 'var(--text-primary)'
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = ((value / total) * 100).toFixed(1);
                                        return label + ': ' + value + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }
            <?php endif; ?>

            // Initialize error grouping
            initErrorGrouping();

            // Setup error message click handlers
            document.querySelectorAll('.error-message-truncated').forEach(span => {
                span.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const fullMessage = this.dataset.fullMessage;
                    const stackTrace = this.dataset.stackTrace || null;
                    if (fullMessage) showErrorMessage(fullMessage, stackTrace);
                });
            });
        });

        // Error grouping expand/collapse
        function initErrorGrouping() {
            const groupRows = document.querySelectorAll('.error-group-row.has-grouped');

            groupRows.forEach(row => {
                row.addEventListener('click', function(e) {
                    // Don't expand if clicking on error message to view details
                    if (e.target.classList.contains('error-message-truncated')) {
                        return;
                    }

                    const groupId = this.dataset.groupId;
                    const childRows = document.querySelectorAll(`.grouped-error-row[data-group-id="${groupId}"]`);
                    const isExpanded = this.classList.contains('expanded');

                    if (isExpanded) {
                        // Collapse
                        this.classList.remove('expanded');
                        childRows.forEach(r => r.style.display = 'none');
                    } else {
                        // Expand
                        this.classList.add('expanded');
                        childRows.forEach(r => r.style.display = 'table-row');
                    }
                });
            });
        }

        // Error message modal
        function showErrorMessage(errorMessage, stackTrace = null) {
            let modal = document.getElementById('errorMessageModal');

            if (!modal) {
                // Create modal on first use
                modal = document.createElement('div');
                modal.id = 'errorMessageModal';
                modal.className = 'stack-trace-modal';
                modal.innerHTML = `
                    <div class="stack-trace-content">
                        <div class="stack-trace-header">
                            <h3>Error Details</h3>
                            <button class="close-btn" onclick="closeErrorMessage()">×</button>
                        </div>
                        <div id="errorMessageContainer"></div>
                    </div>
                `;
                document.body.appendChild(modal);

                // Close on backdrop click
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) closeErrorMessage();
                });

                // Close on Escape key
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && modal.classList.contains('active')) {
                        closeErrorMessage();
                    }
                });
            }

            const container = document.getElementById('errorMessageContainer');
            container.innerHTML = '';

            // Error message section
            const messageSection = document.createElement('div');
            messageSection.style.marginBottom = '20px';

            const messageHeader = document.createElement('h4');
            messageHeader.style.cssText = 'margin: 0 0 10px 0; font-size: 14px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px;';
            messageHeader.textContent = 'Error Message';

            const messageBody = document.createElement('div');
            messageBody.className = 'stack-trace-body';
            messageBody.textContent = errorMessage;

            messageSection.appendChild(messageHeader);
            messageSection.appendChild(messageBody);
            container.appendChild(messageSection);

            // Stack trace section (if available)
            if (stackTrace) {
                const traceSection = document.createElement('div');

                const traceHeader = document.createElement('h4');
                traceHeader.style.cssText = 'margin: 0 0 10px 0; font-size: 14px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px;';
                traceHeader.textContent = 'Stack Trace';

                const traceBody = document.createElement('div');
                traceBody.className = 'stack-trace-body';
                traceBody.textContent = stackTrace;

                traceSection.appendChild(traceHeader);
                traceSection.appendChild(traceBody);
                container.appendChild(traceSection);
            }

            modal.classList.add('active');
        }

        function closeErrorMessage() {
            const modal = document.getElementById('errorMessageModal');
            if (modal) modal.classList.remove('active');
        }
    </script>
</body>
</html>
