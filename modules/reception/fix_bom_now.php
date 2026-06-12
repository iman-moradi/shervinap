<?php
header('Content-Type: text/html; charset=utf-8');
echo "<pre>";

function removeBOMFromFile($filepath) {
    if (!file_exists($filepath)) {
        return "File not found: $filepath\n";
    }
    
    $content = file_get_contents($filepath);
    if ($content === false) {
        return "Cannot read file: $filepath\n";
    }
    
    // بررسی وجود BOM
    $bom = substr($content, 0, 3);
    if ($bom == "\xEF\xBB\xBF") {
        $newContent = substr($content, 3);
        if (file_put_contents($filepath, $newContent)) {
            return "✅ BOM removed from: " . basename($filepath) . "\n";
        } else {
            return "❌ Cannot write file: " . basename($filepath) . "\n";
        }
    } else {
        return "ℹ️ No BOM found in: " . basename($filepath) . "\n";
    }
}

// اصلاح فایل‌ها
$files = [
    __DIR__ . '/customer_history_ajax.php',
    __DIR__ . '/customer_history.php'
];

foreach ($files as $file) {
    echo removeBOMFromFile($file);
}

echo "\n✅ عملیات حذف BOM کامل شد.\n";
echo "🔄 لطفاً صفحه را refresh کنید.\n";
echo "</pre>";
?>