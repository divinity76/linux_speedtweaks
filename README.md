# linux_speedtweaks
make linux faster, at expense of crash reliability &amp; security

disables aslr, kaslr, for filesystems ext2/3/4 and btrfs, it adds stuff like nobarrier,relatime,data=writeback,journal_async_commit,compress-force=lzo,nodatasum

and installs libeatmydata in /etc/ld.so.preload

if there's any more tricks you think the script should perform, or if you find any issues, please report it at https://github.com/divinity76/linux_speedtweaks/issues
