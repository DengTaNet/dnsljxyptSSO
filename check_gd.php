<?php
echo "=== GD 扩展检查 ===\n\n";

if (extension_loaded('gd')) {
    echo "✅ GD 扩展已加载\n\n";
    echo "--- GD 配置信息 ---\n";
    $gdinfo = gd_info();
    foreach ($gdinfo as $key => $val) {
        echo "  $key: " . ($val ? '是' : '否') . "\n";
    }
} else {
    echo "❌ GD 扩展未加载\n";
}
