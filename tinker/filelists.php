
        $vid = "53";
        $did = "173671";
        $cid = "545";

        $user = \App\Models\User::where('id', 1)->first();

        $vtools = new \App\Providers\VaultTools($user, $vid);

        $dir = $vtools->getDirById($did);

        if(!$dir) {
            \Log::error("directory not found");
            return null;
        }

        $mountp = $vtools->getMountPoint();
        $filepath = "{$mountp}/{$dir->name}";

        $tree = $vtools->getContents($filepath);

        if (!$tree) {
            \Log::error("directory cannot be read");
            return null;
        }

        $files2bookmark = [
            'df',
            'ps',
            'netstat',
            'pstree',
            'lsof',
            'uptime',
            'free',
        ];

        $fileLists = [
            'Process' => ['ps', 'pstree', 'lsof'],
            'Disks'   => ['mount','findmnt','df'],
            'Memory'  => ['free', 'swapon_--bytes_--show'],
            'CPU'     => ['lscpu', 'pupower_frequency-info'],
            'Network' => ['netstat','nstat_-zas','ip_route','ip_addr'],
            'System'  => ['uptime','date','hostname'.'uname'],
        ];


        foreach($fileLists as $filelist => $files) {

            $fileList = FileList::create([
                'user_id'  => $user->id,
                'case_id'  => $cid,
                'vault_id' => $vid,
                'dir_id'   => $did,
                'name'     => $filelist,
                'title'    => $filelist,
                'statis'   => "available",
                'enabled'  => 1,
                'icon'     => "phosphor-files-duotone",
            ]);

            foreach($files as $file) {
                $found = $vtools->find_node_by_attr($tree->nodes, "name", $file, "path","");

                if($found) {
                    $name = $found->name;
                    $path = $found->path;

                    if($found->type == "l") {
                        $name = basename($found->realpath);
                        $path = dirname($found->realpath);
                    }
                    $found = $vtools->find_node_by_attr($tree->nodes, "name", $name);

                    if($found) {
                        $icon = $found->type == "d" ? "phosphor-folder-duotone" : "phosphor-file-duotone";

                        $bookmark = Bookmark::create([
                            'user_id'  => $user->id,
                            'case_id'  => $cid,
                            'vault_id' => $vid,
                            'dir_id'   => $did,
                            'name'     => $found->name,
                            'fullpath' => $found->path,
                            'filetype' => $found->type,
                            'icon'     => $icon,
                            'filelist_id' => $fileList->id,
                        ]);

                /*
                        $x = [
                            'user_id'  => $user->id,
                            'case_id'  => $cid,
                            'vault_id' => $vid,
                            'dir_id'   => $did,
                            'name'     => $found->name,
                            'fullpath' => $found->path,
                            'filetype' => $found->type,
                            'icon'     => $icon,
                            'filelist' => $fileList->id,
                        ];
                        var_dump($x);
                */
                    }
                }
            }
        }

