<?php
/**
 * Automated tests for the server side. Run from the repository folder:
 *
 *   php tests/run.php
 *
 * No extra software needed. Every test_*.php file in this folder is a list of checks; nothing goes on the
 * network and nothing is written outside a temporary folder that is removed afterwards.
 */
define('RECORDER_TESTING', true);
error_reporting(E_ALL);
set_error_handler(function ($no, $msg, $file, $line) { if (!(error_reporting() & $no)) return false; throw new ErrorException($msg, 0, $no, $file, $line); });

require __DIR__ . '/../server/record.php'; // functions only (RECORDER_TESTING)

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = [];
$GLOBALS['t_name'] = '';

function check($cond, string $what): void
{
    if ($cond) { $GLOBALS['t_pass']++; return; }
    $GLOBALS['t_fail'][] = $GLOBALS['t_name'] . ': ' . $what;
}

function same($got, $want, string $what): void
{
    check($got === $want, $what . ' (got ' . json_encode($got) . ', want ' . json_encode($want) . ')');
}

/** A fresh temporary folder, removed when the tests end. */
function temp_dir(): string
{
    $d = sys_get_temp_dir() . '/sr-test-' . bin2hex(random_bytes(4));
    mkdir($d, 0700, true);
    $GLOBALS['t_dirs'][] = $d;
    return $d;
}

function put(string $path, string $data = 'x'): void
{
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, $data);
}

$files = glob(__DIR__ . '/test_*.php');
foreach ($files as $f) {
    $GLOBALS['t_name'] = basename($f, '.php');
    try {
        require $f;
    } catch (Throwable $e) {
        $GLOBALS['t_fail'][] = $GLOBALS['t_name'] . ': crashed: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    }
}
foreach ($GLOBALS['t_dirs'] ?? [] as $d) {
    if (is_dir($d)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $x) {
            $x->isDir() ? rmdir($x->getPathname()) : unlink($x->getPathname());
        }
        rmdir($d);
    }
}
$n = count($GLOBALS['t_fail']);
foreach ($GLOBALS['t_fail'] as $m) echo "FAIL  $m\n";
printf("%d checks passed, %d failed (%d files)\n", $GLOBALS['t_pass'], $n, count($files));
exit($n ? 1 : 0);
