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

try {
    $db = Database::getInstance($config['database']);
    $tracker = new ActionTracker($db);

    // Get statistics
    $stats = $tracker->getStats();
    $topActions = $tracker->getTopActions(10);
    $recentActions = $tracker->getRecentActions(100);

    // Handle CSV export
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="action-tracker-export-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Action Name', 'Timestamp', 'Date', 'IP Address']);

        foreach ($recentActions as $action) {
            fputcsv($output, [
                $action['action_name'],
                $action['timestamp'],
                date('Y-m-d H:i:s', $action['timestamp']),
                $action['ip_address'] ?? 'N/A'
            ]);
        }

        fclose($output);
        exit;
    }

} catch (Exception $e) {
    $stats = [];
    $topActions = [];
    $recentActions = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Action Tracker Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        h1 {
            font-size: 28px;
            color: #667eea;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #666;
            font-size: 14px;
        }

        .actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .btn {
            background: #667eea;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: background 0.3s;
        }

        .btn:hover {
            background: #5568d3;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }

        .stat-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
        }

        .section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        h2 {
            font-size: 20px;
            margin-bottom: 20px;
            color: #333;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #666;
            font-size: 14px;
        }

        td {
            font-size: 14px;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .timestamp {
            color: #666;
            font-size: 12px;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Action Tracker Dashboard</h1>
            <p class="subtitle">Yandex Music Controller Analytics</p>
            <div class="actions">
                <button class="btn" onclick="location.reload()">Refresh</button>
                <a href="?export=csv" class="btn btn-secondary">Export CSV</a>
                <button class="btn btn-secondary" onclick="toggleAutoRefresh()">Auto-Refresh: <span id="autoRefreshStatus">OFF</span></button>
            </div>
        </div>

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
            <table>
                <thead>
                    <tr>
                        <th>Action Name</th>
                        <th style="text-align: right;">Total Count</th>
                        <th style="text-align: right;">Unique Visitors</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topActions as $action): ?>
                    <tr>
                        <td><?= htmlspecialchars($action['action_name']) ?></td>
                        <td style="text-align: right;"><?= number_format($action['total_count']) ?></td>
                        <td style="text-align: right;"><?= number_format($action['unique_visitors']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($recentActions)): ?>
        <div class="section">
            <h2>Recent 100 Actions</h2>
            <table>
                <thead>
                    <tr>
                        <th>Action Name</th>
                        <th>Timestamp</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentActions as $action): ?>
                    <tr>
                        <td><?= htmlspecialchars($action['action_name']) ?></td>
                        <td class="timestamp"><?= date('Y-m-d H:i:s', $action['timestamp']) ?></td>
                        <td><?= htmlspecialchars($action['ip_address'] ?? 'N/A') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="section">
            <div class="no-data">No actions tracked yet</div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        let autoRefreshInterval = null;

        function toggleAutoRefresh() {
            if (autoRefreshInterval === null) {
                autoRefreshInterval = setInterval(() => {
                    location.reload();
                }, 30000); // 30 seconds
                document.getElementById('autoRefreshStatus').textContent = 'ON';
            } else {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
                document.getElementById('autoRefreshStatus').textContent = 'OFF';
            }
        }
    </script>
</body>
</html>
