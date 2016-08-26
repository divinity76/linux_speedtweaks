# linux_speedtweaks
make linux faster, at expense of crash reliability &amp; security

disables aslr, kaslr, for filesystems ext2/3/4 and btrfs, it adds stuff like nobarrier,relatime,data=writeback,journal_async_commit,compress-force=lzo,nodatasum,notreelog
