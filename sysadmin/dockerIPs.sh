#/bin/bash

for d in `sudo docker ps|awk '!/^CONTAINER..*/{printf("%s=%s\n",$2,$1)}'`
do
    name=`echo $d|cut -f1 -d=`
    id=`echo $d|cut -f2 -d=`
    ip=`sudo docker inspect $id|awk -F: '/"IPAddress": "..*"/{print $2}'|sed -e 's/"//g' -e 's/,//'`
    printf "%25s\t%s\t%s\n" $name $id $ip
done
