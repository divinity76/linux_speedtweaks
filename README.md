# linux_speedtweaks
make linux faster, at expense of crash reliability &amp; security

disables aslr, kaslr, page table isolation, cpu vulnerability mitigations (SPECTRE, Meltdown, l1tf, etcetc) and for filesystems ext2/3/4 and btrfs, it adds stuff like nobarrier,relatime,data=writeback,journal_async_commit,compress=lzo,nodatasum

and installs libeatmydata in /etc/ld.so.preload (provided multiarch is not enabled)

and adjust vm.dirty_{ratio|expire_centisecs} to make the filesystem utilize much more of the available ram (up to 60% of total ram) for asynchronous disk writes.

if there's any more tricks you think the script should perform, or if you find any issues, please report it at https://github.com/divinity76/linux_speedtweaks/issues
