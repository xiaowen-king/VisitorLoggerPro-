<?php
/**
 * 从网站根目录的备份文件恢复访客记录
 */

// 禁用输出压缩
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
if (ini_get('zlib.output_compression')) {
    ini_set('zlib.output_compression', 'Off');
}
while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: text/html; charset=UTF-8');
header('Content-Encoding: none');
header('Cache-Control: no-cache, no-store, must-revalidate');

// 数据库配置
$db_config = array(
    'host' => 'localhost',
    'port' => 3306,
    'user' => 'blog_ybyq_wang',
    'password' => 'WXX336699',
    'charset' => 'utf8mb4',
    'database' => 'blog_ybyq_wang'
);

// 表名配置
$table_prefix = 'typecho_';
$table_to_restore = 'visitor_log';
$backup_file = $_SERVER['DOCUMENT_ROOT'] . '/blog_ybyq_wang_2025-06-19_02-00-03_mysql_data.sql';

// 显示HTML头部
echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>从网站根目录恢复访客记录</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; max-width: 1000px; margin: 0 auto; line-height: 1.6; }
        h1 { color: #333; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow: auto; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .info { background: #f0f8ff; padding: 10px; border-left: 5px solid #1e90ff; margin: 10px 0; }
        .code { font-family: monospace; background: #f5f5f5; padding: 10px; border-radius: 5px; }
        progress { width: 100%; height: 20px; }
    </style>
</head>
<body>
    <h1>从网站根目录恢复访客记录</h1>
    <div class="info">
        将备份文件放在网站根目录是绕过open_basedir限制的好方法。
    </div>
';

function showMessage($message, $type = 'normal') {
    echo '<p class="' . $type . '">' . htmlspecialchars($message) . '</p>' . PHP_EOL;
    flush();
}

// 检查备份文件
if (!file_exists($backup_file)) {
    showMessage("错误: 备份文件不存在: {$backup_file}", 'error');
    showMessage("请确保您已将备份文件复制到网站根目录，文件名为 'blog_ybyq_wang_2025-06-19_02-00-03_mysql_data.sql'", 'info');
    exit;
}

try {
    // 连接数据库
    showMessage("正在连接数据库...");
    $pdo = new PDO(
        "mysql:host={$db_config['host']};port={$db_config['port']};dbname={$db_config['database']};charset={$db_config['charset']}",
        $db_config['user'],
        $db_config['password'],
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        )
    );
    showMessage("数据库连接成功", 'success');

    // 获取文件大小和当前记录数
    $filesize = filesize($backup_file);
    showMessage("备份文件大小: " . round($filesize / 1024 / 1024, 2) . " MB");
    
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `{$table_prefix}{$table_to_restore}`");
        $current_count = $stmt->fetchColumn();
        showMessage("当前表中有 {$current_count} 条记录");
    } catch (PDOException $e) {
        $current_count = 0;
        showMessage("表可能不存在或无法访问: " . $e->getMessage(), 'error');
    }
    
    // 开始处理备份文件
    showMessage("开始处理备份文件...");
    
    // 读取文件内容
    $backup_content = file_get_contents($backup_file);
    if ($backup_content === false) {
        throw new Exception("无法读取备份文件，可能是文件太大或权限问题");
    }
    
    showMessage("备份文件读取成功，开始分析...");
    
    // 查找CREATE TABLE语句
    $full_table_name = $table_prefix . $table_to_restore;
    $create_table = '';
    
    if (preg_match('/CREATE\s+TABLE\s+(?:`|")?'.$full_table_name.'(?:`|")?\s+\([^;]*;/is', $backup_content, $matches)) {
        $create_table = $matches[0];
        showMessage("找到表结构定义", 'success');
    } else {
        showMessage("未找到表结构定义，将只恢复数据", 'info');
    }
    
    // 查找INSERT语句
    showMessage("正在查找INSERT语句...");
    $insert_statements = array();
    
    // 尝试多种匹配方式
    $patterns = [
        '/INSERT\s+INTO\s+(?:`|")?'.$full_table_name.'(?:`|")?.*?VALUES\s*\([^;]*;/is',
        '/INSERT\s+INTO\s+(?:`|")?'.$full_table_name.'(?:`|")?\s+[^;]*;/is'
    ];
    
    foreach ($patterns as $pattern) {
        preg_match_all($pattern, $backup_content, $matches);
        if (!empty($matches[0])) {
            $insert_statements = $matches[0];
            showMessage("找到 " . count($insert_statements) . " 条INSERT语句", 'success');
            break;
        }
    }
    
    if (empty($insert_statements)) {
        throw new Exception("在备份文件中找不到 {$full_table_name} 表的INSERT语句");
    }
    
    // 如果表不存在且有CREATE TABLE语句，创建表
    if (!empty($create_table)) {
        try {
            $tableExists = false;
            $tables = $pdo->query("SHOW TABLES LIKE '{$full_table_name}'")->fetchAll();
            foreach ($tables as $table) {
                if (in_array($full_table_name, $table)) {
                    $tableExists = true;
                    break;
                }
            }
            
            if (!$tableExists) {
                showMessage("表不存在，将创建表结构...");
                $pdo->exec($create_table);
                showMessage("表结构创建成功", 'success');
            } else {
                showMessage("表已存在，跳过创建表结构", 'info');
            }
        } catch (PDOException $e) {
            showMessage("创建表失败: " . $e->getMessage(), 'error');
        }
    }
    
    // 开始导入数据
    $success_count = 0;
    $error_count = 0;
    
    showMessage("开始导入数据，共 " . count($insert_statements) . " 条...");
    echo '<progress id="progressBar" max="'.count($insert_statements).'" value="0"></progress>';
    
    // 使用事务提高性能
    $pdo->beginTransaction();
    
    foreach ($insert_statements as $index => $sql) {
        try {
            $pdo->exec($sql);
            $success_count++;
            
            // 每100条显示一次进度
            if ($success_count % 100 == 0 || $success_count == count($insert_statements)) {
                echo '<script>document.getElementById("progressBar").value = '.$success_count.';</script>';
                showMessage("已处理 {$success_count} / " . count($insert_statements) . " 条记录");
                flush();
            }
            
            // 每1000条提交一次事务
            if ($success_count % 1000 == 0) {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        } catch (PDOException $e) {
            $error_count++;
            // 只显示前5个错误
            if ($error_count <= 5) {
                showMessage("导入错误 #{$error_count}: " . $e->getMessage(), 'error');
            }
        }
    }
    
    // 提交剩余事务
    $pdo->commit();
    
    // 获取恢复后的记录数
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM `{$full_table_name}`");
    $new_count = $stmt->fetchColumn();
    $added_records = $new_count - $current_count;
    
    showMessage("恢复完成！", 'success');
    showMessage("成功导入 {$success_count} 条记录，失败 {$error_count} 条记录", $error_count > 0 ? 'error' : 'success');
    showMessage("表中总记录数: {$new_count} (增加了 {$added_records} 条记录)", 'success');
    
} catch (Exception $e) {
    showMessage("执行过程中发生错误: " . $e->getMessage(), 'error');
}

echo '<div class="info">
    <strong>提示：</strong>处理完成后，请记得删除以下文件以确保安全：
    <ul>
        <li>此PHP脚本 - restore-from-root.php</li>
        <li>备份文件 - blog_ybyq_wang_2025-06-19_02-00-03_mysql_data.sql</li>
    </ul>
</div>';

echo '</body></html>'; 