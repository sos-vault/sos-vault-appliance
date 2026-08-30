
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

        foreach($files2bookmark as $file) {
            $found = $vtools->find_node_by_attr($tree->nodes, "name", $file, "path","");
            $name = $found->name;
            $path = $found->path;

            if($found->type == "l") {
                $name = basename($found->realpath);
                $path = dirname($found->realpath) . '/';
            }
            $found = $vtools->find_node_by_attr($tree->nodes, "name", $name, "path",$path);

            if($found) {
                $icon = $found->type == "d" ? "phosphor-folder-duotone" : "phosphor-file-duotone";

                $bookmark = Bookmark::create([
                    'user_id'  => $user->id,
                    'case_id'  => $cid,
                    'vault_id' => $vid,
                    'dir_id'   => $did,
                    'name'     => $found->name,
                    'fullpath' => "{$found->path}/",
                    'filetype' => $found->type,
                    'icon'     => $icon,
                ]);
            }
        }

