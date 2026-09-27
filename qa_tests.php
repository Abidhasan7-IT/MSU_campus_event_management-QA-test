<?php
declare(strict_types=1);
$root = __DIR__ . DIRECTORY_SEPARATOR . 'EventManagementSystems';
if (!is_dir($root) && is_dir(__DIR__ . DIRECTORY_SEPARATOR . 'MSU_campus_event_management-main' . DIRECTORY_SEPARATOR . 'EventManagementSystems')) {
    $root = __DIR__ . DIRECTORY_SEPARATOR . 'MSU_campus_event_management-main' . DIRECTORY_SEPARATOR . 'EventManagementSystems';
}
$failures = 0; $warnings = 0; $passes = 0;
function result(string $id, string $status, string $message): void {
    global $failures, $warnings, $passes;
    printf("[%s] %s - %s\n", $status, $id, $message);
    if ($status === 'FAIL') $failures++;
    elseif ($status === 'WARN') $warnings++;
    else $passes++;
}
function readProjectFile(string $relative): string {
    global $root;
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    return is_file($path) ? (string) file_get_contents($path) : '';
}
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') continue;
    exec(PHP_BINARY . ' -l ' . escapeshellarg($file->getPathname()), $output, $exitCode);
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
    result('SYN-' . $file->getFilename(), $exitCode === 0 ? 'PASS' : 'FAIL',
        $exitCode === 0 ? "$rel has valid PHP syntax" : "$rel contains a PHP syntax error");
    $output = [];
}
$sql = readProjectFile('uevent.sql');
if ($sql === '') result('SQL-001', 'FAIL', 'uevent.sql could not be read');
else {
    preg_match_all("/INSERT\s+INTO\s+`?event_type`?.*?VALUES\s*(.*?);/is", $sql, $matches);
    $typeIds = [];
    foreach ($matches[1] ?? [] as $block) {
        preg_match_all("/\(\s*(\d+)\s*,/m", $block, $rows);
        foreach ($rows[1] ?? [] as $id) $typeIds[] = (int)$id;
    }
    $counts = array_count_values($typeIds);
    $duplicates = [];
    foreach ($counts as $id => $count) if ($count > 1) $duplicates[] = $id;
    result('SQL-002', count($duplicates) ? 'FAIL' : 'PASS',
        count($duplicates) ? 'Duplicate event_type IDs: ' . implode(', ', $duplicates) : 'No duplicate event_type IDs detected');
    result('SQL-003', preg_match('/ALTER\s+TABLE\s+`?event_type`?.*?PRIMARY KEY\s*\(\s*`?type_id`?\s*\)/is', $sql) ? 'PASS' : 'WARN',
        preg_match('/ALTER\s+TABLE\s+`?event_type`?.*?PRIMARY KEY\s*\(\s*`?type_id`?\s*\)/is', $sql) ? 'event_type.type_id has a primary key' : 'event_type.type_id is not declared as a primary key');
}
$adminGuard = "if (!isset(\$_SESSION['admin_id']) || !isset(\$_SESSION['username']))";
$adminEndpoints = ['adminPage.php','createEventForm.php','deleteEvent.php','deleteStudent.php','Stu_details.php','Stu_cordinator.php','Staff_cordinator.php','updateStudent.php','updateStaff.php'];
foreach ($adminEndpoints as $file) {
    $src = readProjectFile($file);
    $ok = strpos($src, $adminGuard) !== false || strpos($src, 'requireAdmin') !== false;
    result('AUTH-' . $file, $ok ? 'PASS' : 'FAIL', $ok ? "$file contains an admin authorization guard" : "$file has no visible admin authorization guard");
}
$checks = [
    'viewEvent.php' => '/WHERE\s+events\.event_id\s*=\s*\$id/i',
    'deleteEvent.php' => '/event_id\s*=\s*[\'"]?\$id/i',
    'updateStudent.php' => '/where\s+sid\s*=\s*[\'"]?\$id/i',
    'updateStaff.php' => '/where\s+stid\s*=\s*[\'"]?\$id/i',
];
foreach ($checks as $file => $pattern) {
    $src = readProjectFile($file);
    result('SEC-' . $file, preg_match($pattern, $src) ? 'FAIL' : 'PASS',
        preg_match($pattern, $src) ? "$file interpolates a request-derived ID into SQL" : "$file has no matching direct ID interpolation");
}
$login = readProjectFile('login_form.php');
$plainFallback = strpos($login, 'password_verify') !== false && preg_match('/===\s*\$mypassword/', $login);
result('SEC-PASSWORD', $plainFallback ? 'FAIL' : 'PASS', $plainFallback ? 'login_form.php contains a plaintext password fallback' : 'No plaintext password fallback detected');
$register = readProjectFile('register.php');
$badValidation = preg_match('/!empty\(\$usn\).*?\|\|.*?!empty\(\$name\)/s', $register);
result('VAL-REGISTER', $badValidation ? 'FAIL' : 'PASS', $badValidation ? 'Registration validation uses OR instead of requiring all fields' : 'No obvious OR-based required-field defect detected');
result('SQL-REGISTER-UNIQUE', preg_match('/UNIQUE\s*\(\s*`?usn`?\s*,\s*`?event_id`?\s*\)/i', $sql) ? 'PASS' : 'WARN', preg_match('/UNIQUE\s*\(\s*`?usn`?\s*,\s*`?event_id`?\s*\)/i', $sql) ? 'registered has UNIQUE(usn,event_id)' : 'registered lacks UNIQUE(usn,event_id)');
result('SQL-USN-UNIQUE', (preg_match('/UNIQUE\s*\(\s*`?usn`?\s*\)/i', $sql) || preg_match('/PRIMARY\s+KEY\s*\(\s*`?usn`?\s*\)/i', $sql)) ? 'PASS' : 'WARN', (preg_match('/UNIQUE\s*\(\s*`?usn`?\s*\)/i', $sql) || preg_match('/PRIMARY\s+KEY\s*\(\s*`?usn`?\s*\)/i', $sql)) ? 'participent.usn is constrained as unique' : 'participent.usn is not unique');
foreach (['deleteEvent.php'=>'Event deletion','deleteStudent.php'=>'Student deletion'] as $file=>$label) {
    $usesGet = strpos(readProjectFile($file), '$_GET') !== false;
    result('HTTP-' . $file, $usesGet ? 'FAIL' : 'PASS', $usesGet ? "$label is triggered by GET" : "$label is not triggered by GET");
}
printf("\nQA SUMMARY\nPASS: %d\nFAIL: %d\nWARN: %d\n", $passes, $failures, $warnings);
printf("Overall result: %s\n", $failures === 0 ? 'PASS' : 'FAIL');
exit($failures === 0 ? 0 : 1);
?>
