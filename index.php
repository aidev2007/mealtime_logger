<?php
session_start();

// === 設置者向け: パスワード設定 ===
// 下記の定数を任意のパスワードに変更してください。
const MEALTIME_PASSWORD = 'your-password';
// ==============================

// CSVファイルのパスを設定
$csvPath = 'mealtime_log.csv';


// CSVファイルが存在しない場合は作成（ヘッダー付き）
if (!file_exists($csvPath)) {
    $result = file_put_contents($csvPath, "start_time,end_time\n");
    if ($result === false) {
        error_log("Failed to create CSV file: " . $csvPath);
    }
}

// CSVデータを読み込む関数
function readMealData($csvPath) {
    $data = [];
    if (($handle = fopen($csvPath, "r")) !== FALSE) {
        // ヘッダー行を読み飛ばす
        fgetcsv($handle);
        
        // データ行を読み込む
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (isset($row[0]) && $row[0] !== '') {
                $data[] = [
                    'start_time' => $row[0],
                    'end_time' => isset($row[1]) ? $row[1] : ''
                ];
            }
        }
        fclose($handle);
    }
    return array_reverse($data); // 新しいもの順
}

// === バックアップ関数追加 ===
function backupCsv($csvPath) {
    $bak1 = $csvPath . '.bak1';
    $bak2 = $csvPath . '.bak2';
    $bak3 = $csvPath . '.bak3';
    // 古いバックアップを順にローテーション
    if (file_exists($bak3)) {
        unlink($bak3);
    }
    if (file_exists($bak2)) {
        rename($bak2, $bak3);
    }
    if (file_exists($bak1)) {
        rename($bak1, $bak2);
    }
    if (file_exists($csvPath)) {
        copy($csvPath, $bak1);
    }
}

// 統計計算
function calculateStats($mealData) {
    $intervals = [];
    $durations = [];
    
    // 最新30件を対象
    $recentData = array_slice($mealData, 0, 30);
    
    for ($i = 0; $i < count($recentData); $i++) {
        $current = $recentData[$i];
        
        // 食事時間の計算
        if (!empty($current['end_time'])) {
            $start = new DateTime($current['start_time']);
            $end = new DateTime($current['end_time']);
            $duration = $end->getTimestamp() - $start->getTimestamp();
            $durations[] = $duration;
        }
        
        // 間隔の計算
        if ($i < count($recentData) - 1) {
            $next = $recentData[$i + 1];
            $currentStart = new DateTime($current['start_time']);
            $nextStart = new DateTime($next['start_time']);
            $interval = $currentStart->getTimestamp() - $nextStart->getTimestamp();
            $intervals[] = $interval;
        }
    }
    
    $stats = [
        'interval_avg' => !empty($intervals) ? array_sum($intervals) / count($intervals) : 0,
        'interval_median' => !empty($intervals) ? getMedian($intervals) : 0,
        'duration_avg' => !empty($durations) ? array_sum($durations) / count($durations) : 0,
        'duration_median' => !empty($durations) ? getMedian($durations) : 0
    ];
    
    return $stats;
}

function getMedian($arr) {
    sort($arr);
    $count = count($arr);
    if ($count == 0) return 0;
    if ($count % 2 == 0) {
        return ($arr[$count/2 - 1] + $arr[$count/2]) / 2;
    } else {
        return $arr[floor($count/2)];
    }
}

function formatTime($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

// 食事データを取得
$mealData = readMealData($csvPath);
$stats = calculateStats($mealData);

// デバッグ情報
$debug_info = [
    'csv_exists' => file_exists($csvPath),
    'csv_size' => file_exists($csvPath) ? filesize($csvPath) : 'N/A',
    'csv_path' => $csvPath,
    'csv_content' => file_exists($csvPath) ? file_get_contents($csvPath) : 'N/A',
    'meal_data_count' => is_array($mealData) ? count($mealData) : 0,
    'meal_data' => $mealData
];

// POSTリクエストの処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'authenticate') {
        $password = $_POST['password'] ?? '';
        if ($password === MEALTIME_PASSWORD) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'パスワードが正しくありません']);
        }
        exit;
    }
    
    if ($action === 'logout') {
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    if ($action === 'start_meal') {
        $authToken = $_POST['auth_token'] ?? '';
        if ($authToken !== 'authenticated') {
            echo json_encode(['status' => 'error', 'message' => '認証が必要です']);
            exit;
        }
        $startTime = date('Y-m-d\TH:i:s');
        
        // === バックアップ作成 ===
        backupCsv($csvPath);
        // CSVに新しい行を追加（開始時間のみ）
        $fp = fopen($csvPath, 'a');
        fputcsv($fp, [$startTime, '']);
        fclose($fp);
        
        echo json_encode(['status' => 'success', 'start_time' => $startTime]);
        exit;
    }
    
    if ($action === 'end_meal') {
        $endTime = date('Y-m-d\TH:i:s');
        
        // === バックアップ作成 ===
        backupCsv($csvPath);
        // CSVの最後の行を更新（終了時間を追加または上書き）
        $lines = file($csvPath);
        if (count($lines) > 1) {
            $lastLine = trim($lines[count($lines) - 1]);
            $data = str_getcsv($lastLine);
            $data[1] = $endTime; // 終了時間を上書き
            
            $lines[count($lines) - 1] = implode(',', $data) . "\n";
            file_put_contents($csvPath, implode('', $lines));
        }
        
        echo json_encode(['status' => 'success', 'end_time' => $endTime]);
        exit;
    }

    if ($action === 'undo_last_log') {
        $authToken = $_POST['auth_token'] ?? '';
        if ($authToken !== 'authenticated') {
            echo json_encode(['status' => 'error', 'message' => '認証が必要です']);
            exit;
        }

        // === バックアップ作成 ===
        backupCsv($csvPath);
        $lines = file($csvPath);
        if (count($lines) > 1) {
            $lastLine = trim($lines[count($lines) - 1]);
            $data = str_getcsv($lastLine);
            
            if (empty($data[1])) {
                // end_timeが未設定の場合、最後の行を削除
                array_pop($lines);
            } else {
                // end_timeが設定されている場合、end_timeをクリア
                $data[1] = '';
                $lines[count($lines) - 1] = implode(',', $data) . "\n";
            }
            
            file_put_contents($csvPath, implode('', $lines));
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => '取り消すログがありません']);
        }
        exit;
    }
}

// デバッグ情報
error_log("CSV Path: " . $csvPath);
error_log("Meal Data: " . print_r($mealData, true));
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>食事時間ログ</title>
    <link rel="icon" type="image/svg+xml" href='data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y=".9em" font-size="90">🍛</text></svg>'>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Debug Info:
    <?php echo json_encode($debug_info, JSON_PRETTY_PRINT); ?>
    -->
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #ffd89a 0%, #fad0c4 100%);
            min-height: 100vh;
            padding: 10px 10px;
        }

        .container {
            margin: 10px;
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            padding: 30px;
            text-align: center;
            padding: 20px;
        }

        .header h1 {
            font-size: 2.5em;
            /*margin-bottom: 10px;*/
            padding: 5px;
            border-radius: 15px;
            /*background-color: #26cffe;*/
            background-color: rgba(192,192,192,0.25);
        }

        .tabs {
            display: flex;
            /*background: #f8f9fa;*/
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            /*border-bottom: 1px solid #dee2e6;*/
        }

        .tab {
            flex: 1;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            background: #f8f9fa;
            border: none;
            font-size: 1.1em;
            transition: all 0.3s ease;
            color: #6c757d;
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
            border-right: 1px solid #cccccc;
            /*border: 1px solid red;*/
        }

        .tab.active {
            background: white;
            color: #495057;
            /*border-bottom: 3px solid #007bff;*/
            border-bottom: 3px solid #ffffff;
            border-left: 1px solid #ffffff;
        }

        .tab:hover {
            /*background: #e9ecef;*/
            background: #ffffff;
        }

        .tab-content {
            padding: 30px;
            /*min-height: 400px;*/
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        h2 {
          font-size: 1.3em;
          font-weight: 400;
          color: #54717e;
          padding: 0px 5px;
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 25px;
            font-size: 1.1em;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            margin: 0px 5px;
            min-width: 140px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        input#password {
            margin-bottom: 20px;
        }

/*
        .time-display {
            font-size: 2.5em;
            text-align: center;
            margin: 30px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            font-weight: bold;
        }
*/

        /* 時間表示の色定義 */
        .insufficient {
          color: #888888;
        }

        .short {
            color: #33C888;
        }

        .medium {
            color: #EF963C;
        }

        .long {
            color: #dc3545;
        }

        /* 日付表示の色定義 */
        .date-color-1 {
           color: #863A14;
        }

        .date-color-2 {
            color: #278614;
        }

        .date-color-3 {
            color: #146086;
        }

        .date-color-4 {
            color: #731486;
        }

/*
        .time-display.insufficient {
            color: #28a745;
        }

        .time-display.short {
            color: #00FFFF;
        }

        .time-display.medium {
            color: #ffc107;
        }

        .time-display.long {
            color: #dc3545;
        }
*/

        .meal-start-time,
        .meal-end-time {
            margin: 5px 0;
            color: #666;
        }

        .meal-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            position: relative;
        }

        .meal-table th,
        .meal-table td {
            padding: 5px;
            text-align: center;
            border-bottom: 1px solid #dee2e6;
        }

        .meal-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }

        .meal-table tr:hover {
            background: #f8f9fa;
        }
        
        .stats-grid {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 20px;
            justify-content: center;
        }

        .stat-card {
            background: linear-gradient(135deg, #ffd89a11 0%, #fad0c466 100%);
            color: #3c8da7;
            padding: 15px 15px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            min-width: 250px;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .stat-card .item-name {
          width: 200px;
        }

        .stat-card .stat-methods {
          width: 140px;
        
        }

        .stat-card .stat-value {
          width: 90%;
          font-size: 1.5em;
          padding-top: 4px;
        }
                        
        .stat-card h3 {
            margin-bottom: 10px;
            font-size: 1.1em;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .stat-value {
            font-size: 1.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-card p {
            font-size: 0.9em;
            opacity: 0.9;
        }

        .input-section {
            /*text-align: center;*/
        }

        .status-info {
            background: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            color: #1976d2;
            text-align: center;
        }

        .status-info.meal-in-progress {
            background: #FFEDDF;
            border-color: #FFD0AC;
        }

        .status-info .insufficient {
            color: #888888;
        }

        .status-info .short {
            color: #28a745;
        }

        .status-info .medium {
            color: #ffc107;
        }

        .status-info .long {
            color: #dc3545;
        }

        #elapsed-time {
            font-size: 2.5em;
            /*text-align: center;*/
            /*margin: 30px 0;*/
            /*padding: 20px;*/
            /*background: #f8f9fa;*/
            /*border-radius: 10px;*/
            font-weight: bold;
        }

        #meal-controls {
            text-align: center;
        }

        #auth-controls {
            text-align: center;
        }

        .password-input {
            margin: 20px 0;
            text-align: center;
        }

        .password-input input {
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1em;
            /*margin: 10px 0;*/
        }

        .auth-message {
            margin: 20px 0;
            color: #666;
        }

        .error-message {
            color: #dc3545;
            margin: 10px 0;
            text-align: center;
        }

        .undo-button {
            display: none; /* 古いスタイルを非表示に */
        }

        input::placeholder {
          color: #ccc; /* 薄いグレーにする例 */
        }


        @media (max-width: 768px) {
            .container {
                border-radius: 10px;
            }

            .header {
                padding: 15px;
            }

            .header h1 {
                font-size: 2em;
            }

            .tab-content {
                padding: 10px;
                padding-bottom: 30px;
            }

/*
            .time-display {
                font-size: 2em;
            }
*/

            .meal-table {
                font-size: 0.9em;
            }

            .stat-card {
                flex: 0 1 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-utensils"></i> 食事時間ログ</h1>
        </div>

        <div class="tabs">
            <button class="tab" onclick="showTab('list')">
                <i class="fas fa-list"></i> 履歴
            </button>
            <button class="tab active" onclick="showTab('input')">
                <!--<i class="fas fa-pen-to-square"></i> 入力-->
                <i class="fa-solid fa-circle-play"></i> 記録
            </button>
            <button class="tab" onclick="showTab('stats')">
                <i class="fas fa-chart-bar"></i> 統計
            </button>
            <button class="tab" onclick="showTab('auth')">
                <!-- <i class="fas fa-lock"></i> 認証 -->
<!--                <i class="fa-solid fa-lock"></i> 権限-->
                <i class="fas fa-lock" id="auth-icon"></i> 権限
            </button>
        </div>

        <div class="tab-content">
            <!-- 履歴タブ -->
            <div id="list" class="tab-pane">
                <h2><i class="fas fa-history"></i> 食事履歴（直近30件）</h2>
                <table class="meal-table">
                    <thead>
                        <tr>
                            <th>食事開始日時</th>
                            <th>食事時間</th>
                            <th>前回からの間隔</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // 表示用に30件、間隔計算用に31件取得
                        $displayData = array_slice($mealData, 0, 30);
                        $intervalData = array_slice($mealData, 0, 31);
                        
                        $currentDate = null;
                        $dateColorIndex = 0;
                        
                        for ($i = 0; $i < count($displayData); $i++) {
                            $meal = $displayData[$i];
                            $startTime = new DateTime($meal['start_time']);
                            
                            // 日付の色分け
                            $mealDate = $startTime->format('Y-m-d');
                            if ($currentDate !== $mealDate) {
                                $currentDate = $mealDate;
                                $dateColorIndex = ($dateColorIndex + 1) % 4;
                            }
                            $dateColorClass = 'date-color-' . ($dateColorIndex + 1);
                            
                            // 食事時間の計算と色分け
                            $duration = '';
                            $durationClass = '';
                            if (!empty($meal['end_time'])) {
                                $endTime = new DateTime($meal['end_time']);
                                $diff = $endTime->getTimestamp() - $startTime->getTimestamp();
                                $duration = formatTime($diff);
                                
                                // 食事時間の色分け（15分未満、～30分、～60分、60分以上）
                                if ($diff < 15 * 60) {
                                    $durationClass = 'insufficient';
                                } else if ($diff < 30 * 60) {
                                    $durationClass = 'short';
                                } else if ($diff < 60 * 60) {
                                    $durationClass = 'medium';
                                } else {
                                    $durationClass = 'long';
                                }
                            } else {
                                $duration = '--:--:--';
                            }
                            
                            // 前回からの間隔と色分け
                            $interval = '';
                            $intervalClass = '';
                            if ($i < count($intervalData) - 1) {
                                $prevMeal = $intervalData[$i + 1];
                                $prevStart = new DateTime($prevMeal['start_time']);
                                $intervalSeconds = $startTime->getTimestamp() - $prevStart->getTimestamp();
                                $interval = formatTime($intervalSeconds);
                                
                                // 間隔の色分け（5時間未満、－、～16時間、16時間以上）
                                if ($intervalSeconds < 5 * 60 * 60) {
                                    $intervalClass = 'insufficient';
                                } else if ($intervalSeconds < 16 * 60 * 60) {
                                    $intervalClass = 'short';
                                } else if ($intervalSeconds < 16 * 60 * 60) {
                                    $intervalClass = 'medium';
                                } else {
                                    $intervalClass = 'long';
                                }
                            } else {
                                $interval = '初回';
                            }
                            
                            echo "<tr>";
                            echo "<td class='{$dateColorClass}'>" . $startTime->format('m/d H:i:s') . "</td>";
                            echo "<td class='{$durationClass}'>" . $duration . "</td>";
                            echo "<td class='{$intervalClass}'>" . $interval . "</td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- 入力タブ -->
            <div id="input" class="tab-pane active">
                <div class="input-section">
                    <h2><i class="fas fa-utensils"></i> 食事記録</h2>
                    
                    <div class="status-info">
                        <p id="elapsed-time-info">前回食事開始からの経過時間 </p>
                        <p id="elapsed-time">計算中...</p>
<!--
                        <p id="elapsed-time" class="insufficient">00:05:12</p>
                        <p id="elapsed-time" class="short"       >00:15:12</p>
                        <p id="elapsed-time" class="medium"      >00:35:12</p>
                        <p id="elapsed-time" class="long"        >01:15:12</p>
-->
                    </div>
                    
                    <div id="meal-controls">
                        <?php if (is_array($mealData) && count($mealData) > 0): ?>
                        <p class="meal-start-time">
                            直近の食事開始時刻: <?php echo date('m/d H:i:s', strtotime($mealData[0]['start_time'])); ?>
                        </p>
                        <p class="meal-end-time" style="margin-bottom: 20px;">
                            直近の食事終了時刻: <?php echo !empty($mealData[0]['end_time']) ? date('m/d H:i:s', strtotime($mealData[0]['end_time'])) : '--/-- --:--:--'; ?>
                        </p>
                        <?php endif; ?>
                        <button class="btn" onclick="startMeal()" style="margin-top: 10px;" id="start-meal-btn" disabled>
                            <i class="fa-solid fa-circle-play"></i> 食事開始
                        </button>
                        <button class="btn" onclick="endMeal()" id="end-meal-btn" disabled>
                            <i class="fas fa-circle-stop"></i> 食事終了
                        </button>
                        <?php if (is_array($mealData) && count($mealData) > 0): ?>
                        <button class="btn btn-danger" onclick="undoLastLog()" style="margin-top: 10px;" id="undo-btn" disabled>
                            <i class="fas fa-undo"></i> 直前のログを取り消す
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 統計タブ -->
            <div id="stats" class="tab-pane">
                <h2><i class="fas fa-chart-line"></i> 食事統計（直近30件）</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="item-name"><i class="fas fa-clock"></i> 食事間隔</div>
                        <div class="stat-methods">平均</div>
                        <div class="stat-value"><?php echo formatTime($stats['interval_avg']); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="item-name"><i class="fas fa-clock"></i> 食事間隔</div>
                        <div class="stat-methods">中央値</div>
                        <div class="stat-value"><?php echo formatTime($stats['interval_median']); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="item-name"><i class="fas fa-hourglass-half"></i> 食事時間</div>
                        <div class="stat-methods">平均</div>
                        <div class="stat-value"><?php echo formatTime($stats['duration_avg']); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="item-name"><i class="fas fa-hourglass-half"></i> 食事時間</div>
                        <div class="stat-methods">中央値</div>
                        <div class="stat-value"><?php echo formatTime($stats['duration_median']); ?></div>
                    </div>
                </div>
            </div>

            <!-- 認証タブ -->
            <div id="auth" class="tab-pane">
                <div class="input-section">
                    <!--<h2><i class="fas fa-lock"></i> 認証</h2>-->
<!--                    <h2><i class="fas fa-lock"></i> 権限</h2>-->
                    <h2><i class="fas fa-lock"></i> 権限</h2>

                    <div id="auth-controls">
                        <div id="authenticated-section" style="display: none;">
                            <p class="auth-message">開錠済み：操作が許可されています</p>
                            <button class="btn btn-danger" onclick="logout()">
                                <i class="fas fa-lock"></i> 施錠
                            </button>
                        </div>
                        <div id="unauthenticated-section">
                            <div id="password-input" class="password-input">
                                <p class="auth-message">未施錠：閲覧のみ可能です</p>
                                <input type="password" id="password" placeholder="パスワードを入力" onkeypress="handleKeyPress(event)">
                                <button class="btn" onclick="authenticate()">
                                    <i class="fas fa-unlock"></i> 開錠
                                </button>
                            </div>
                            <div id="auth-error" class="error-message"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // 認証状態の管理
        function isAuthenticated() {
            return localStorage.getItem('mealtime_auth') === 'authenticated';
        }

        function setAuthenticated(authenticated) {
            if (authenticated) {
                localStorage.setItem('mealtime_auth', 'authenticated');
            } else {
                localStorage.removeItem('mealtime_auth');
            }
            updateAuthUI();
        }

        function updateAuthUI() {
            const authenticated = isAuthenticated();
            const authIcon = document.getElementById('auth-icon');
            const startMealBtn = document.getElementById('start-meal-btn');
            const endMealBtn = document.getElementById('end-meal-btn');
            const undoBtn = document.getElementById('undo-btn');
            const authenticatedSection = document.getElementById('authenticated-section');
            const unauthenticatedSection = document.getElementById('unauthenticated-section');

            if (authenticated) {
                authIcon.className = 'fas fa-unlock';
                if (startMealBtn) startMealBtn.disabled = false;
                if (endMealBtn) endMealBtn.disabled = false;
                if (undoBtn) undoBtn.disabled = false;
                authenticatedSection.style.display = 'block';
                unauthenticatedSection.style.display = 'none';
            } else {
                authIcon.className = 'fas fa-lock';
                if (startMealBtn) startMealBtn.disabled = true;
                if (endMealBtn) endMealBtn.disabled = true;
                if (undoBtn) undoBtn.disabled = true;
                authenticatedSection.style.display = 'none';
                unauthenticatedSection.style.display = 'block';
            }
        }

        // タブの順序を定義
        const tabOrder = ['list', 'input', 'stats', 'auth'];
        let currentTabIndex = 0;

        function showTab(tabName) {
            // すべてのタブとタブコンテンツを非アクティブにする
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
            
            // 選択されたタブをアクティブにする
            const tabButton = document.querySelector(`.tab[onclick="showTab('${tabName}')"]`);
            if (tabButton) {
                tabButton.classList.add('active');
            }
            document.getElementById(tabName).classList.add('active');

            // 現在のタブインデックスを更新
            currentTabIndex = tabOrder.indexOf(tabName);
        }

        // キーボードイベントのハンドラを追加
        document.addEventListener('keydown', function(event) {
            // パスワード入力フィールドにフォーカスがある場合は無視
            if (document.activeElement.tagName === 'INPUT') {
                return;
            }

            if (event.key === 'PageUp') {
                event.preventDefault();
                currentTabIndex = (currentTabIndex - 1 + tabOrder.length) % tabOrder.length;
                showTab(tabOrder[currentTabIndex]);
            } else if (event.key === 'PageDown') {
                event.preventDefault();
                currentTabIndex = (currentTabIndex + 1) % tabOrder.length;
                showTab(tabOrder[currentTabIndex]);
            }
        });

        // 初期タブのインデックスを設定
        document.addEventListener('DOMContentLoaded', function() {
            const activeTab = document.querySelector('.tab.active');
            if (activeTab) {
                const tabName = activeTab.getAttribute('onclick').match(/'([^']+)'/)[1];
                currentTabIndex = tabOrder.indexOf(tabName);
            }
            // 認証状態を復元
            updateAuthUI();
        });

        function startMeal() {
            if (!isAuthenticated()) {
                alert('認証が必要です');
                return;
            }

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=start_meal&auth_token=authenticated'
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    location.reload();
                }
            });
        }

        function endMeal() {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=end_meal'
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    location.reload();
                }
            });
        }

        function authenticate() {
            const password = document.getElementById('password').value;
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=authenticate&password=${encodeURIComponent(password)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    setAuthenticated(true);
                    document.getElementById('password').value = '';
                    document.getElementById('auth-error').textContent = '';
                } else {
                    document.getElementById('auth-error').textContent = data.message;
                }
            });
        }

        function logout() {
            setAuthenticated(false);
        }

        function updateElapsedTime() {
            <?php if (!empty($mealData)): ?>
                const lastMealTime = new Date('<?php echo $mealData[0]['start_time']; ?>').getTime();
                const now = new Date().getTime();
                const elapsed = Math.floor((now - lastMealTime) / 1000);
                
                const hours = Math.floor(elapsed / 3600);
                const minutes = Math.floor((elapsed % 3600) / 60);
                const seconds = elapsed % 60;
                
                const display = document.getElementById('elapsed-time');
                const infoDisplay = document.getElementById('elapsed-time-info');
                
                // 食事中かどうかを判定
                const isMealInProgress = <?php echo empty($mealData[0]['end_time']) ? 'true' : 'false'; ?>;
                
                if (isMealInProgress) {
                    infoDisplay.textContent = '食事開始からの経過時間';
                    document.querySelector('.status-info').classList.add('meal-in-progress');
                } else {
                    infoDisplay.textContent = '前回食事開始からの経過時間';
                    document.querySelector('.status-info').classList.remove('meal-in-progress');
                }
                
                display.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                
                // 色分け（食事中かどうかで基準を変更）
                if (isMealInProgress) {
                    // 食事中の色分け（15分未満、～30分、～60分、60分以上）
                    if (elapsed < 15 * 60) {
                        display.className = 'insufficient';
                    } else if (elapsed < 30 * 60) {
                        display.className = 'short';
                    } else if (elapsed < 60 * 60) {
                        display.className = 'medium';
                    } else {
                        display.className = 'long';
                    }
                } else {
                    // 食事中でない場合の色分け（5時間未満、～16時間、16時間以上）
                    if (elapsed < 5 * 60 * 60) {
                        display.className = 'insufficient';
                    } else if (elapsed < 16 * 60 * 60) {
                        display.className = 'short';
                    } else {
                        display.className = 'long';
                    }
                }
            <?php endif; ?>
        }

        // 毎秒更新
        setInterval(() => {
            updateElapsedTime();
        }, 1000);

        // 初回実行
        updateElapsedTime();

        function handleKeyPress(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                authenticate();
            }
        }

        function undoLastLog() {
            if (!isAuthenticated()) {
                alert('認証が必要です');
                return;
            }

            if (!confirm('直前のログを取り消しますか？')) {
                return;
            }

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=undo_last_log&auth_token=authenticated'
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    location.reload();
                } else {
                    alert(data.message);
                }
            });
        }
    </script>
</body>
</html>