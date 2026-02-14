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

try {
    $db = Database::getInstance($config['database']);
    $tracker = new ActionTracker($db);
    $installationTracker = new InstallationTracker($db);

    // Get statistics
    $stats = $tracker->getStats();
    $topActions = $tracker->getTopActions(10);
    $visitorSummary = $tracker->getVisitorSummary();

    // Get installation statistics
    $installationStats = $installationTracker->getStats();
    $versionBreakdown = $installationTracker->getVersionBreakdown();
    $osBreakdown = $installationTracker->getOSBreakdown();
    $recentInstallations = $installationTracker->getRecentInstallations(50);

} catch (Exception $e) {
    $stats = [];
    $topActions = [];
    $visitorSummary = [];
    $installationStats = [];
    $versionBreakdown = [];
    $osBreakdown = [];
    $recentInstallations = [];
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
                        <div class="stat-label">Total Reports</div>
                        <div class="stat-value"><?= number_format($installationStats['total_installations']) ?></div>
                    </div>
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
                                <td><?= $installation['yandex_music_connected'] ? '✓ Connected' : '✗ Not Connected' ?></td>
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

        // Initialize everything on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize tabs
            initTabs();

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
        });
    </script>
</body>
</html>
