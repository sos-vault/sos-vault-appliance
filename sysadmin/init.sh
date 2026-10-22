#!/bin/bash

# this script creates an empty key device

here=`pwd`
# KEYDIR is chosen by the installer and exported as SVAULT_KEYDIR; fall back to
# the legacy per-user location for a manual dev run.
dir="${SVAULT_KEYDIR:-$HOME/.config/svaultKey}"
# The app service uid/gid that must own the key device + mount (matches the
# host key service and the remapped container user). Default 1000 for dev.
uid="${SVAULT_UID:-1000}"
gid="${SVAULT_GID:-1000}"
mkdir -p $dir

device="$dir/svault.key"
mountp="$dir/m"

/bin/mkdir -p $dir/.headers

temp=`/bin/mktemp`
# The installer pipes the passphrase in via SVAULT_PASSPHRASE so this runs
# unattended; fall back to an interactive prompt for a manual dev run.
if [ -n "${SVAULT_PASSPHRASE:-}" ]; then
    echo -n "$SVAULT_PASSPHRASE" > $temp
else
    read -s -p "passphrase (never forget this passphrase!!): ";
    echo -n $REPLY > $temp
    echo ""
fi

/bin/dd if=/dev/zero of=$device bs=1M count=20 > /dev/null 2>&1
/bin/sudo /sbin/cryptsetup -y -v --batch-mode --label=$device --key-file=$temp --type luks2 luksFormat $device
if [ $? -ne 0 ]; then echo "A"; exit 1; fi

/bin/sudo chown $uid:$gid $device

/bin/sudo /sbin/cryptsetup --key-file=$temp luksOpen $device svault
if [ $? -ne 0 ]; then echo "B"; exit 1; fi

/bin/sudo /bin/dd if=/dev/zero of=/dev/mapper/svault status=progress > /dev/null 2>&1

/bin/sudo /sbin/mkfs.ext4 /dev/mapper/svault > /dev/null 2>&1
if [ $? -ne 0 ]; then echo "C"; exit 1; fi

/bin/mkdir -p $mountp
/bin/sudo /bin/mount /dev/mapper/svault $mountp
/bin/sudo /bin/chown $uid:$gid $mountp
/bin/sudo /bin/chmod 700 $mountp
/bin/cat > $mountp/script.sh << EOF
#!/bin/bash

dir=\`/bin/dirname \$0\`

for i in 0 1 2 3
do
    datafile="\$dir/.data\$i"
    if [ -s \$datafile ]
    then
        /bin/cat \$datafile | /bin/keyctl padd user svault\${i}:key @u > /dev/null
    fi
done

EOF

/bin/chmod 500 $mountp/script.sh

for i in 0 1 2 3
do
    datafile="$mountp/.data$i"
    /bin/openssl rand -base64 32 |/bin/head -1 > $datafile
    /bin/chmod 600 $datafile
done

# init.sh may run as root (production install) or as the developer (manual dev
# run). Either way the files just written are owned by the CREATING user, but
# the boot service (execStart.sh) runs as the app uid and must read/execute
# script.sh and read the .data* key material. The 500/600 modes grant only the
# owner, so hand the whole inner fs to the app uid — otherwise a non-creating
# uid (the root install case) gets EACCES at boot ("Permission denied").
/bin/sudo /bin/chown -R $uid:$gid $mountp

/bin/sudo /bin/umount /dev/mapper/svault
/bin/sudo /sbin/cryptsetup luksClose svault
/bin/rmdir $dir/m
if [ $? -ne 0 ]; then echo "E"; exit 1; fi

/sbin/cryptsetup -v --key-file=$temp luksHeaderBackup $device --header-backup-file $dir/.headers/.svault.data
if [ $? -ne 0 ]; then echo "D"; exit 1; fi

/bin/rm -f $temp

# The boot service (svaultKey.service) and its sudoers fragment are installed
# by installer.sh step 11 — init.sh only creates the key device.
