<?php
function removeBOM($filename) {
    $content = file_get_contents($filename);
    if (substr($content, 0, 3) == "\xEF\xBB\xBF") {
        $content = substr($content, 3);
        file_put_contents($filename, $content);
        echo "BOM removed from: $filename<br>";
        return true;
    }
    echo "No BOM found in: $filename<br>";
    return false;
}

$files = [
    'modules/reception/customer_history_ajax.php',
    'modules/reception/customer_history.php'
];

foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        removeBOM($path);
    } else {
        echo "File not found: $path<br>";
    }
}
echo "Done!";
?>