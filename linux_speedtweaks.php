<?php
declare(strict_types = 1);
init();
$simulation = ! in_array('--no-test', $argv);
if ($simulation) {
    echo "Warning, only running a simulation, no actions will be performed. " . PHP_EOL;
    echo "will create simulation.Fstab, simulation.01-disable-aslr.conf, simulation.grub , etc." . PHP_EOL;
    echo 'to actually apply system settings, run "' . $argv[0] . ' --no-test"' . PHP_EOL;
}
if (! $simulation && posix_getuid() !== 0) {
    die("error: this script must run as root! but running as uid " . posix_getuid());
}
(function () {
    global $simulation;
    if (! $simulation && ! is_writable('/etc/fstab')) {
        die('error: /etc/fstab is not writable!');
    } elseif ($simulation && ! is_readable('/etc/fstab')) {
        die('error: /etc/fstab is not readable!');
    }
    echo "fixing /etc/fstab..." . PHP_EOL;
    $lines = ex::file('/etc/fstab', FILE_IGNORE_NEW_LINES);
    $lines = trimlines($lines);
    $fooi = 0;
    foreach ($lines as $key => $line) {
        if (strlen($line) < 1 || $line[0] === '#') {
            continue;
        }
        $matches = [];
        // # <file system> <mount point> <type> <options> <dump> <pass>
        $one = preg_match('/^(?P<filesystem>\S+?)(?P<spaces1>\s+?)(?P<mountpoint>\S+?)(?P<spaces2>\s+?)(?P<type>\S+?)(?P<spaces3>\s+?)(?P<options>\S+?)(?P<spaces4>\s+?)(?P<dump>\S+?)(?P<spaces5>\s+?)(?P<pass>\S+)$/i', $line, $matches);
        if ($one !== 1) {
            // var_dump ( 'one', $one, 'matches', $matches, 'key', $key );
            echo "WARNING: could not understand line " . $key . ".  (invalid format?) ignoring." . PHP_EOL;
            continue;
        }
        // var_dump ( 'matches', $matches );
        $type = $matches['type'];
        $supportedTypes = array(
            'ext2',
            'ext3',
            'ext4',
            'btrfs'
        );
        ++ $fooi;
        if ($fooi !== 1) { // sometimes i just wanna use goto...
            echo "finished line." . PHP_EOL;
        }
        echo 'processing line ' . $key . ': ' . $type . ': ' . $matches['mountpoint'] . PHP_EOL;
        if (! findParitalStringInArray($supportedTypes, $type)) {
            echo "ignoring unsupported filesystem type " . $type . ' on line ' . $key . PHP_EOL;
            continue;
        }
        if ($matches['mountpoint'] === '/boot') {
            echo 'ignoring /boot on line ' . $key . ' (hardcoded to ignore /boot partition).... ' . PHP_EOL;
            continue;
        }
        $options = explode(",", $matches['options']);
        
        if (! in_array('noatime', $options) && ! in_array('relatime', $options)) {
            echo 'adding relatime.. ';
            $options[] = 'relatime'; // what about lazytime?
        }
        if (! findParitalStringInArray($options, 'barrier')) {
            echo 'adding nobarrier.. ';
            $options[] = 'nobarrier';
        }
        if ($type === 'ext2' || $type === 'ext3' || $type === 'ext4') {
            if (! findParitalStringInArray($options, 'data=')) {
                echo 'adding data=writeback.. ';
                $options[] = 'data=writeback';
            }
            if (in_array('journal_checksum', $options) && ! in_array('journal_async_commit', $options)) {
                echo 'adding journal_async_commit.. ';
                $options[] = 'journal_async_commit';
            }
        } elseif ($type === 'btrfs') {
            if (! findParitalStringInArray($options, 'compress')) {
                echo "adding compress-force=lzo.. ";
                $options[] = 'compress-force=lzo';
            }
            if (! findParitalStringInArray($options, 'datasum') && ! findParitalStringInArray($options, 'datacow')) {
                echo "adding nodatasum.. ";
                $options[] = 'nodatasum';
            }
            if (! findParitalStringInArray($options, 'treelog')) {
                echo "adding notreelog.. ";
                $options[] = 'notreelog';
            }
        } else {
            throw new LogicException('unreachable code reached! should never happen...');
        }
        $options = implode(',', $options);
        $line = $matches['filesystem'] . $matches['spaces1'] . $matches['mountpoint'] . $matches['spaces2'] . $matches['type'] . $matches['spaces3'] . $options . $matches['spaces4'] . $matches['dump'] . $matches['spaces5'] . $matches['pass'];
        // var_dump($line);
        $lines[$key] = $line;
    }
    if ($fooi !== 0) {
        echo "finished line." . PHP_EOL;
    }
    $data = implode("\n", $lines);
    if ($simulation) {
        ex::file_put_contents('simulation.fstab', $data);
    } else {
        ex::file_put_contents('/etc/fstab', $data);
    }
    echo "finished with /etc/fstab." . PHP_EOL;
})();
(function () {
    global $simulation;
    if (file_exists('/etc/sysctl.d/01-disable-aslr.conf')) {
        echo "aslr already disabled, skipping" . PHP_EOL;
        return;
    }
    echo "disabling ASLR..";
    $data = 'kernel.randomize_va_space = 0';
    if ($simulation) {
        ex::file_put_contents('simulation.01-disable-aslr.conf', $data);
    } else {
        ex::file_put_contents('/etc/sysctl.d/01-disable-aslr.conf', $data);
    }
    echo 'done.' . PHP_EOL;
})();
(function () {
    global $simulation;
    if (! is_readable('/etc/default/grub')) {
        echo '/etc/default/grub is not readable, will skip nokaslr.' . PHP_EOL;
        return;
    }
    if (! $simulation && ! is_writable('/etc/default/grub')) {
        echo '/etc/default/grub is not writable, will skip nokaslr.' . PHP_EOL;
        return;
    }
    echo "disabling kaslr...";
    $lines = ex::file('/etc/default/grub', FILE_IGNORE_NEW_LINES);
    $lines = trimlines($lines);
    $templines = '';
    foreach ($lines as $line) {
        if (strlen($line) < 1 || $line[0] === '#') {
            continue;
        }
        $templines .= $line . "\n";
    }
    if (false !== stripos($templines, 'kaslr')) { // should ignore lines starting with #, like #nokaslr.
        echo 'custom kaslr settings already detected, skipping.' . PHP_EOL;
        return;
    }
    unset($line, $templines);
    $found = false;
    foreach ($lines as $key => $line) {
        if (strlen($line) < 1 || $line[0] === '#') {
            continue;
        }
        
        if (strpos($line, 'GRUB_CMDLINE_LINUX_DEFAULT') !== 0) {
            continue;
        }
        $found = true;
        $matches = array();
        $one = preg_match('/^GRUB_CMDLINE_LINUX_DEFAULT(\s*)\=(\s*)\"(.*)\"$/i', $line, $matches);
        if ($one !== 1) {
            // var_dump ( 'one', $one, 'matches', $matches, 'key', $key );
            echo "WARNING: could not understand line " . $key . ". ignoring. (invalid format?)" . PHP_EOL;
            continue;
        }
        // $options = explode(' ', $matches[3]);//preg_split("\s+",$matches[3]); ?
        $options = $matches[3];
        $options .= ' nokaslr';
        $updatedLine = 'GRUB_CMDLINE_LINUX_DEFAULT' . $matches[1] . '=' . $matches[2] . '"' . $options . '"';
        // var_dump($updatedLine);
        $lines[$key] = $updatedLine;
    }
    if (! $found) {
        echo 'error, could not find GRUB_CMDLINE_LINUX_DEFAULT in /etc/default/grub , skipping nokaslr..' . PHP_EOL;
        return;
    }
    $data = implode("\n", $lines);
    if ($simulation) {
        ex::file_put_contents('simulation.grub', $data);
    } else {
        ex::file_put_contents('/etc/default/grub', $data);
    }
    echo "added nokaslr. running update-grub... " . PHP_EOL;
    if ($simulation) {
        echo "(update-grub not executed because this is a simulation..) " . PHP_EOL;
    } else {
        system("update-grub");
    }
    echo PHP_EOL . 'done.' . PHP_EOL;
})();
echo 'finished. all speedtweaks applied. you should now reboot your computer.' . PHP_EOL;
if ($simulation) {
    echo '(not really, this was a simulation)' . PHP_EOL;
}
die();
class ex
{
    private static function _return_var_dump(/*...*/){
        $args = func_get_args();
        ob_start();
        call_user_func_array('var_dump', $args);
        return ob_get_clean();
    }
    static function file(string $filename, int $flags = 0, /*resource*/ $context = null): array
    {
        $args = func_get_args();
        $ret = call_user_func_array('file', $args);
        if (false === $ret) {
            throw new RuntimeException('file() failed.   last error: ' . self::_return_var_dump(error_get_last()));
        }
        return $ret;
    }
    static function file_put_contents(string $filename, /*mixed*/ $data, int $flags = 0, /*resource*/ $context = null): int
    {
        $args = func_get_args();
        $min = false;
        if (is_array($data)) {
            $min = strlen(implode('', $data));
        } elseif (is_string($data)) {
            $min = strlen($data);
        } else {
            // probably a resource, given that stream_get_meta_data often can't be trusted
            // im not even going to try...
            $min = false;
        }
        $ret = call_user_func_array('file_put_contents', $args);
        if ($min === false) {
            return $ret;
        }
        if ($min !== $ret) {
            throw new RuntimeException('file_put_contents() failed. tried to write ' . self::_return_var_dump($min) . ' bytes, but could only write ' . self::_return_var_dump($ret) . ' bytes. full disk?  last error: ' . self::_return_var_dump(error_get_last()));
        }
        return $ret;
    }
}
function xhhb_exception_error_handler($errno, $errstr, $errfile, $errline)
{
    if (! (error_reporting() & $errno)) {
        // This error code is not included in error_reporting
        return;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
function xhhb_assert_handler($file, $line, $code, $desc = null)
{
    $errstr = 'Assertion failed at ' . $file . ':' . $line . ' ' . $desc . ' code: ' . $code;
    throw new ErrorException($errstr, 0, 1, $file, $line);
}
function init()
{
    error_reporting(E_ALL);
    ini_set('auto_detect_line_endings', '1');
    set_error_handler("xhhb_exception_error_handler");
    assert_options(ASSERT_ACTIVE, 1);
    assert_options(ASSERT_WARNING, 0);
    assert_options(ASSERT_QUIET_EVAL, 1);
    assert_options(ASSERT_CALLBACK, 'xhhb_assert_handler');
}
function findParitalStringInArray(array $arr, string $needle): bool
{
    foreach ($arr as $word) {
        if (false !== stripos($word, $needle)) {
            return true;
        }
    }
    return false;
}
function trimlines(array $lines): array
{
    foreach ($lines as &$line) {
        $line = trim($line);
    }
    return $lines;
}

