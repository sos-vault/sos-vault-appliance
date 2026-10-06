#!/bin/bash

# this script removes the key device

dir="$HOME/.config/svaultKey"
device="$dir/svault.key"
stamp=`/bin/date +'%F_%X'`
echo $stamp

/bin/sudo /bin/umount /dev/mapper/svault
/bin/sudo /sbin/cryptsetup luksClose svault
/bin/cp -p $device ~/temp/svault.key.$stamp
/bin/rm -rf $dir
