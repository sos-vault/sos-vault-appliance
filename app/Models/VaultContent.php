<?php

namespace App\Models;

use App\Providers\VaultTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use stdClass;
use Sushi\Sushi;

class VaultContent extends Model
{
    use Sushi;

    protected static array $parameters = [];

    protected $dir = '';

    public function case(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class, 'case_id');
    }

    public static function withParameters(array $params): self
    {
        static::$parameters = $params;

        return new static; // Return a new instance for chaining
    }

    public function getRows(): array
    {
        $response = new stdClass;

        $vid = '';

        isset(self::$parameters['vid']) && $vid = self::$parameters['vid'];
        $uid = auth()->user()->id;

        if (! isset($vid)) {
            return [];
        }

        $vtools = new VaultTools(auth()->user(), $vid);
        if (! isset($vtools)) {
            return [];
        }

        if ($vtools->getVaultId() != $vid) {
            return [];
        }

        $vtools->openVault();

        if (! $vtools->isOpen()) {
            return [];
        }

        $this->dir = $vtools->getMountPoint();

        $tree = $vtools->getContents($this->dir);

        // Sushi does not like objects so make it an Array
        $data = json_decode(json_encode($tree->nodes[0]->nodes), true);

        $gid = auth()->user()->group_id ?? auth()->user()->id;

        // Pre-fetch all cases for this vault in one query to avoid N+1
        $dirPaths = collect($data)
            ->filter(fn ($item) => $item['type'] === 'd')
            ->map(fn ($item) => "{$this->dir}/{$item['name']}")
            ->values()
            ->all();

        $casesByPath = SupportCase::where('group', $gid)
            ->whereIn('path', $dirPaths)
            ->get()
            ->keyBy('path');

        return collect($data)
            ->map(function ($item) use ($casesByPath, $vid) {
                // Sushi does not support Array fields, so you have to move them to new fields
                unset($item['nodes']);

                $item['vault_id'] = $vid;
                $item['case_id'] = '';
                $item['os_version'] = '';
                $item['os_icon'] = '';

                if ($item['type'] == 'd') {
                    $path = "{$this->dir}/{$item['name']}";
                    $case = $casesByPath->get($path);

                    if (isset($case)) {
                        $item['case_id'] = $case->id;
                        $item['os_version'] = 'Linux';
                        $item['os_icon'] = 'simpleicon-linux';
                        if (isset($case->os_version)) {
                            $item['os_version'] = $case->os_version;
                        }
                        if (isset($case->os_icon)) {
                            $item['os_icon'] = $case->os_icon;
                        }
                    }
                }

                return $item;
            })
            ->toArray();
    }
}
