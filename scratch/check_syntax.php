<?php
$content = file_get_contents('c:/laragon/www/form/script.js');

// Remove template literals string contents simple regex
$clean = preg_replace_callback('/`([^`\\\\]|\\\\.)*`/s', function($m) {
    return '`' . str_repeat(' ', strlen($m[0]) - 2) . '`';
}, $content);

// Remove normal single and double quote strings
$clean = preg_replace_callback('/"([^"\\\\]|\\\\.)*"/s', function($m) {
    return '"' . str_repeat(' ', strlen($m[0]) - 2) . '"';
}, $clean);
$clean = preg_replace_callback("/'([^'\\\\]|\\\\.)*'/s", function($m) {
    return "'" . str_repeat(' ', strlen($m[0]) - 2) . "'";
}, $clean);

// Remove single line comments
$clean = preg_replace('#//.*#', '', $clean);

$stack = [];
$lines = explode("\n", $clean);

for ($l = 0; $l < count($lines); $l++) {
    $line = $lines[$l];
    for ($c = 0; $c < strlen($line); $c++) {
        $ch = $line[$c];
        if ($ch === '(' || $ch === '{' || $ch === '[') {
            $stack[] = ['ch' => $ch, 'line' => $l + 1, 'col' => $c + 1];
        } elseif ($ch === ')' || $ch === '}' || $ch === ']') {
            if (empty($stack)) {
                echo "ERROR: Unmatched closing '$ch' at line " . ($l + 1) . " col " . ($c + 1) . "\n";
            } else {
                $top = array_pop($stack);
                $expected = [')' => '(', '}' => '{', ']' => '['][$ch];
                if ($top['ch'] !== $expected) {
                    echo "ERROR: Mismatched '$ch' at line " . ($l + 1) . " col " . ($c + 1) . ". Expected closing for '$top[ch]' from line $top[line] col $top[col]\n";
                }
            }
        }
    }
}

if (!empty($stack)) {
    echo "ERROR: Unclosed brackets:\n";
    foreach ($stack as $item) {
        echo " - Unclosed '$item[ch]' at line $item[line] col $item[col]\n";
    }
} else {
    echo "PERFECT: All brackets match 100% cleanly!\n";
}
