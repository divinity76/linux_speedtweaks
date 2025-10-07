<?php
declare (strict_types = 1);
init();
$simulation = !in_array('--no-test', $argv);
if ($simulation) {
	echo "Warning, only running a simulation, no actions will be performed. " . PHP_EOL;
	echo "will create simulation.Fstab, simulation.01-disable-aslr.conf, simulation.grub, simulation.ld.so.preload, etc." . PHP_EOL;
	echo 'to actually apply system settings, run "' . $argv[0] . ' --no-test"' . PHP_EOL;
}
if (!$simulation && posix_getuid() !== 0) {
	die("err<r: this script must run as root! but running as uid " . posix_getuid());
}
$tweaks = new linux_speedtweaks();
$tweaks->disable_ASLR();
$tweaks->disable_KASLR();
$tweaks->disable_PTI();
$tweaks->filesystem_tweaks_etc_fstab();
$tweaks->Install_global_eat_my_data();
$tweaks->disable_l1tf_mitigations();
$tweaks->misc_kernel_options(); //cba coming up with the names for all this stuff
$tweaks->adjust_vm_dirty();
echo 'finished. all speedtweaks applied. you should now reboot your computer.' . PHP_EOL;
if ($simulation) {
	echo '(not really, this was a simulation)' . PHP_EOL;
}
return;
class linux_speedtweaks
{
	public function misc_kernel_options()
	{
		//cba coming up with the names for all this stuff
		$this->add_kernel_boot_parameter("noretpotline");
		$this->add_kernel_boot_parameter("noibrs"); // no restricted indirect branch speculation
		$this->add_kernel_boot_parameter("noibpb"); // no indirect branch prediction barrier
		$this->add_kernel_boot_parameter("nospectre_v2");
		$this->add_kernel_boot_parameter("nospectre_v1");
		$this->add_kernel_boot_parameter("nospec_store_bypass_disable");
		$this->add_kernel_boot_parameter("no_stf_barrier");
		$this->add_kernel_boot_parameter("mds=off");
		$this->add_kernel_boot_parameter("mitigations=off");
	}
	public function filesystem_tweaks_etc_fstab()
	{
		// /etc/fstab
		global $simulation;
		if (!$simulation && !is_writable('/etc/fstab')) {
			echo 'warning: /etc/fstab is not writable! will not modify filesystem mount parameters.' . PHP_EOL;
			return false;
		} elseif ($simulation && !is_readable('/etc/fstab')) {
			echo 'warning: /etc/fstab is not readable! will not modify filesystem mount parameters' . PHP_EOL;
			return false;
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
			++$fooi;
			if ($fooi !== 1) { // sometimes i just wanna use goto...
				echo "finished line." . PHP_EOL;
			}
			echo 'processing line ' . $key . ': ' . $type . ': ' . $matches['mountpoint'] . PHP_EOL;
			if (!findParitalStringInArray($supportedTypes, $type)) {
				echo "ignoring unsupported filesystem type " . $type . ' on line ' . $key . PHP_EOL;
				continue;
			}
			if ($matches['mountpoint'] === '/boot') {
				echo 'ignoring /boot on line ' . $key . ' (hardcoded to ignore /boot partition).... ' . PHP_EOL;
				continue;
			}
			$options = explode(",", $matches['options']);

			if (!in_array('noatime', $options) && !in_array('relatime', $options)) {
				if ($this->is_filesystem_mount_option_supported($type, 'relatime')) {
					echo 'adding relatime.. ';
					$options[] = 'relatime'; // what about lazytime?
				}
			}
			if (!findParitalStringInArray($options, 'barrier')) {
				if ($this->is_filesystem_mount_option_supported($type, 'nobarrier')) {
					echo 'adding nobarrier.. ';
					$options[] = 'nobarrier';
				}
			}
			if ($type === 'ext2' || $type === 'ext3' || $type === 'ext4') {
				// if (!findParitalStringInArray($options, 'data=')) {
				// 	if ($this->is_filesystem_mount_option_supported($type, 'data=writeback')) {
				// 		echo 'adding data=writeback.. ';
				// 		$options[] = 'data=writeback';
				// 	}
				// }

				if (!findParitalStringInArray($options, 'auto_da_alloc')) {
					if ($this->is_filesystem_mount_option_supported($type, 'noauto_da_alloc')) {
						echo 'adding noauto_da_alloc.. ';
						$options[] = 'noauto_da_alloc';
					}
				}
				if (in_array('journal_checksum', $options) && !in_array('journal_async_commit', $options)) {
					if ($this->is_filesystem_mount_option_supported($type, 'journal_checksum,journal_async_commit')) {
						echo 'adding journal_async_commit.. ';
						$options[] = 'journal_async_commit';
					}
				}
			} elseif ($type === 'btrfs') {
				if (!findParitalStringInArray($options, 'flushoncommit')) {
					if ($this->is_filesystem_mount_option_supported($type, 'noflushoncommit')) {
						echo "adding noflushoncommit.. ";
						$options[] = 'noflushoncommit';
					}
				}
				if (!findParitalStringInArray($options, 'compress')) {
					if ($this->is_filesystem_mount_option_supported($type, 'compress=lzo')) {
						echo "adding compress=lzo.. ";
						$options[] = 'compress=lzo';
					}
				}
				if (!findParitalStringInArray($options, 'datasum') && !findParitalStringInArray($options, 'datacow')) {
					if ($this->is_filesystem_mount_option_supported($type, 'nodatasum')) {
						echo "adding nodatasum.. ";
						$options[] = 'nodatasum';
					}
				}
				// notreelog disabled because it may make things slower. see issue #2
				// if (! findParitalStringInArray ( $options, 'treelog' )) {
				// echo "adding notreelog.. ";
				// $options [] = 'notreelog';
				// }
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
		return true;
	}
	public function disable_l1tf_mitigations()
	{
		$this->add_kernel_boot_parameter("kvm-intel.vmentry_l1d_flush=never");
		$this->add_kernel_boot_parameter("l1tf=off");
	}
	public function disable_ASLR()
	{
		// /etc/sysctl.d/01-disable-aslr.conf
		global $simulation;
		if ($this->is_sysctld_configured('kernel.randomize_va_space')) {
			echo "custom aslr settings detected, skipping." . PHP_EOL;
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
	}
	public function disable_KASLR()
	{
		echo "disabling kaslr...";
		$this->add_kernel_boot_parameter("nokaslr");
	}
	public function disable_PTI()
	{
		echo "disabling pti...";
		$this->add_kernel_boot_parameter("nopti");
	}
	public function adjust_vm_dirty()
	{
		global $simulation;
		if (!$this->is_sysctld_configured('vm.dirty_ratio')) {
			echo 'settings vm.dirty_ratio=60' . PHP_EOL;
			if ($simulation) {
				ex::file_put_contents('simulation.39-vm-dirty-ratio.conf', 'vm.dirty_ratio=60');
			} else {
				ex::file_put_contents('/etc/sysctl.d/39-vm-dirty-ratio.conf', 'vm.dirty_ratio=60');
			}
		} else {
			echo "custom vm.dirty_ratio settings detected, skipping.." . PHP_EOL;
		}
		if (!$this->is_sysctld_configured('vm.dirty_expire_centisecs')) {
			echo 'settings vm.dirty_expire_centisecs=3000 ( 30 seconds )' . PHP_EOL;
			if ($simulation) {
				ex::file_put_contents('simulation.39-vm-dirty-expire-centisecs.conf', 'vm.dirty_expire_centisecs=3000');
			} else {
				ex::file_put_contents('/etc/sysctl.d/39-vm-dirty-expire-centisecs.conf', 'vm.dirty_expire_centisecs=3000');
			}
		} else {
			echo "custom vm.dirty_expire_centisecs settings detected, skipping.." . PHP_EOL;
		}
	}
	public function Install_global_eat_my_data()
	{
		// /etc/ld.so.preload enable global libeatmydata
		global $simulation;
		$output = array();
		$ret = 0;
		exec('dpkg --print-foreign-architectures', $output, $ret);
		if ($ret !== 0) {
			echo 'dpkg did not return 0. presumably not installed. thus failed to check for multiarch. will not attempt global libeatmydata.' . PHP_EOL;
			return;
		}
		foreach ($output as $line) {
			if (strlen(trim($line)) > 0) {
				echo 'multiarch is enabled. will not attempt global libeatmydata', PHP_EOL;
				return;
			}
		}
		unset($line, $ret, $output);
		echo "installing eatmydata... ", PHP_EOL;
		$cmd = 'apt-get -y install eatmydata';
		if ($simulation) {
			$cmd .= ' --dry-run';
		}
		system($cmd);
		unset($cmd);
		$output = array();
		$ret = 0;
		exec('ldconfig -p | grep libeatmydata', $output, $ret);
		// WARNING: Technically, several linux filesystems (including btrfs) allows
		// newlines in filepaths... which would break this code..
		// but if you put a newline in /usr/lib/x86_64-linux-gnu/libeatmydata.so
		// then....****YOU
		// actually, given that both newlines and = and > may be legal in filenames, there's no reliable way to parse the output of ldconfig -p, except the last element
		if (count($output) < 1) {
			echo "libeatmydata not found. will not attempt global libeatmydata.", PHP_EOL;
			return;
		}
		$shortest = trim($output[0]);
		foreach ($output as $line) {
			$line = trim($line);
			if (strlen($line) < 1) {
				continue;
			}
			if (strlen($line) < strlen($shortest)) {
				$shortest = $line;
			}
		}
		unset($output, $ret, $line);
		$thepos = strrpos($shortest, '=>');
		assert(is_int($thepos));
		$line = trim(substr($shortest, $thepos + strlen('=>')));
		if (!file_exists($line)) {
			echo 'ERROR: detected libeatmydata path as ', $line, ' BUT IT DOES NOT EXIST. will not attempt global libeatmydata, something is very wrong here.', PHP_EOL;
			return;
		}
		unset($output, $ret, $shortest, $thepos);
		echo "detected libeatmydata: ", $line, PHP_EOL;
		$str = '';
		if (file_exists('/etc/ld.so.preload')) {
			if (!is_readable('/etc/ld.so.preload')) {
				echo '/etc/ld.so.preload does exist, but is not readable, so will not attempt global libeatmydata.', PHP_EOL;
				return;
			}
			$str = file_get_contents('/etc/ld.so.preload', false);
			if (false === $str) {
				echo "error: failed to read /etc/ld.so.preload. will not attempt global libeatmydata.", PHP_EOL;
				return;
			}
		} else {
			// /etc/ld.so.preload does not exist.
		}
		if (false !== strpos($str, 'libeatmydata')) {
			echo "libeatmydata is already globally installed.", PHP_EOL;
			return;
		}
		$str .= "\n" . $line;
		$str = trim($str);
		if ($simulation) {
			ex::file_put_contents('simulation.ld.so.preload', $str);
		} else {
			ex::file_put_contents('/etc/ld.so.preload', $str);
		}
		echo "libeatmydata globally installed.", PHP_EOL;
		return;
	}
	private function is_sysctld_configured(string $config): bool
	{
		assert(is_readable('/etc/sysctl.d/'));
		$blacklist = array();
		$files = glob('/etc/sysctl.d/*.conf');
		foreach ($files as $file) {
			if (in_array(basename($file), $blacklist, true)) {
				continue; // blacklisted
			}
			$file = file_get_contents($file);
			if (false !== strpos($file, $config)) {
				return true;
			}
		}
		return false;
	}
	private function add_kernel_boot_parameter(string $boot_parameter): bool
	{
		// /etc/default/grub
		global $simulation;
		$boot_parameter_name = trim(strtr($boot_parameter, array('no' => '', '=0' => '', '=1' => '')));
		if (!is_readable('/etc/default/grub')) {
			echo '/etc/default/grub is not readable, will skip kernel boot parameter "' . $boot_parameter . '".' . PHP_EOL;
			return false;
		}
		if (!$simulation && !is_writable('/etc/default/grub')) {
			echo '/etc/default/grub is not writable, will skip kernel boot parameter "' . $boot_parameter . '".' . PHP_EOL;
			return false;
		}
		$file = null;
		if ($simulation && is_readable('simulation.etc.default.grub')) {
			$file = "simulation.etc.default.grub";
		} else {
			$file = '/etc/default/grub';
		}
		echo "adding kernel boot parameter {$boot_parameter}...";
		$lines = ex::file($file, FILE_IGNORE_NEW_LINES);
		$lines = trimlines($lines);
		$templines = '';
		foreach ($lines as $line) {
			if (strlen($line) < 1 || $line[0] === '#') {
				continue;
			}
			$templines .= $line . "\n";
		}
		if (false !== stripos($templines, $boot_parameter_name)) {
			echo 'custom ' . $boot_parameter_name . ' settings already detected, skipping.' . PHP_EOL;
			return false;
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
			$options .= ' ' . $boot_parameter;
			$updatedLine = 'GRUB_CMDLINE_LINUX_DEFAULT' . $matches[1] . '=' . $matches[2] . '"' . $options . '"';
			// var_dump($updatedLine);
			$lines[$key] = $updatedLine;
		}
		if (!$found) {
			echo 'error, could not find GRUB_CMDLINE_LINUX_DEFAULT in /etc/default/grub , skipping ' . $boot_parameter . '..' . PHP_EOL;
			return false;
		}
		$data = implode("\n", $lines);
		if ($simulation) {
			ex::file_put_contents('simulation.etc.default.grub', $data);
		} else {
			ex::file_put_contents('/etc/default/grub', $data);
		}
		echo "added {$boot_parameter}. running update-grub... " . PHP_EOL;
		if ($simulation) {
			echo "(update-grub not executed because this is a simulation..) " . PHP_EOL;
		} else {
			system("update-grub");
		}
		echo PHP_EOL . 'done.' . PHP_EOL;
		return true;
	}
	private function is_filesystem_mount_option_supported(string $filesystem, string $option): bool
	{
		static $cache = array();
		if (isset($cache[$filesystem][$option])) {
			return $cache[$filesystem][$option];
		}
		$blacklist = ['data=writeback'];
		if (in_array($option, $blacklist, true)) {
			return false;
		}
		echo "checking if filesystem \"{$filesystem}\" support option \"\{$option}\"..";
		// btrfs is a bitch and needs over 100MB even for an empty partition...
		$size = (50000000 * 4) - 1;
		$tmpdiskh = tmpfile();
		fseek($tmpdiskh, $size - 1);
		fwrite($tmpdiskh, "\x00", 1);
		rewind($tmpdiskh);
		$tmpdisk = stream_get_meta_data($tmpdiskh)['uri'];
		$tmpdiskname = basename($tmpdisk);
		passthru("mkfs.{$filesystem} " . escapeshellarg($tmpdisk), $ret);
		if ($ret !== 0) {
			$ret = false;
			echo "warning, could not create test filesystem! hence cannot test mount option.\n";
		} else {
			mkdir($tmpdiskname);
			passthru("mount " . escapeshellarg($tmpdisk) . " " . escapeshellarg(realpath($tmpdiskname)), $ret);
			if ($ret !== 0) {
				$ret = false;
				echo "warning, could not mount filesystem at all (even with no special options)\n";
				rmdir($tmpdiskname);
			} else {
				msleep(0.1);
				passthru("umount " . escapeshellarg(realpath($tmpdiskname)), $ret);
				assert($ret === 0);
				$cmd = implode(" ", array(
					'mount',
					'-o ' . escapeshellarg($option),
					escapeshellarg($tmpdisk),
					escapeshellarg(realpath($tmpdiskname))
				));
				passthru($cmd, $ret);
				if ($ret === 0) {
					$ret = true; // final
					msleep(0.1);
					passthru("umount " . escapeshellarg(realpath($tmpdiskname)), $ret2);
					assert($ret2 === 0);
				} else {
					$ret = false; // final
				}
				rmdir($tmpdiskname);
			}
		}
		$cache[$filesystem][$option] = $ret;
		fclose($tmpdiskh);
		return $ret;
	}
}
class ex
{
	private static function _return_var_dump()
	{
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
	if (!(error_reporting() & $errno)) {
		// This error code is not included in error_reporting
		return;
	}
	throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
function init()
{
	error_reporting(E_ALL);
	set_error_handler("xhhb_exception_error_handler");
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
/**
* Delays execution of the script by the given time.
* @param mixed $time Time to pause script execution. Can be expressed
* as an integer or a decimal.
* @example msleep(1.5); // delay for 1.5 seconds
* @example msleep(.1); // delay for 100 milliseconds
*/
function msleep(float $time)
{
    usleep((int)($time * 1000000));
}
