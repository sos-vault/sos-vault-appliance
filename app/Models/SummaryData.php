<?php

// This model uses Sushi to interact with SosService for Filament Tables created by the summary-table component

namespace App\Models;

use App\Providers\SosServiceProvider;
use App\Providers\VaultTools;
use Filament\Forms\Components\RichEditor\Models\Concerns\InteractsWithRichContent;
use Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Sushi\Sushi;

class SummaryData extends Model implements HasRichContent
{
    use InteractsWithRichContent;
    use Sushi;

    protected static array $parameters = [];

    public static function withParameters(array $params): self
    {
        if (isset($params)) {
            static::$parameters = $params;
        }

        return new static;
    }

    public function getRows(): array
    {
        $vid = self::$parameters['vid'];
        $did = self::$parameters['did'];
        $cid = self::$parameters['cid'];
        $type = self::$parameters['type'];
        $indx = self::$parameters['indx'];
        $uid = auth()->user()->id;

        if (! (isset($vid) && isset($did) && isset($cid) && isset($type) && isset($indx))) {
            return [];
        }

        $vtools = new VaultTools(resolveVaultUser($vid, $cid, $did), $vid);
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

        $dtools = new SosServiceProvider($vtools, $vid, $did, $cid);
        $summaryData = [];
        if (isset($dtools)) {
            $summaryData = $dtools->getSummary();
        }

        $records = [];

        if (! isset($summaryData)) {
            return collect($records)->toArray();
        }

        // find all tables...
        $keys = array_keys((array) $summaryData->{$type});
        $tables = explode(',', implode(',', preg_grep("/tableData\d+/", $keys)));

        // get the table based in the index...
        $table = $tables[$indx];

        if (! isset($summaryData->{$type}->{$table})) {
            // return empty
            return collect($records)->toArray();
        }

        switch ($type) {
            case 'host':
                $records[] = (array) $summaryData->{$type}->{$table};
                break;
            case 'conn':
            case 'tcpip':
            case 'packages':
            case 'kernel':
            case 'files':
                $records = json_decode(json_encode($summaryData->{$type}->{$table}), true);
                break;
            case 'inventory':
            case 'firewall':
            case 'errors':
            case 'systemd':
                $records = (array) $summaryData->{$type}->{$table}->data;
                break;
            case 'cpu':
            case 'disk':
                foreach ((array) $summaryData->{$type}->{$table} as $key => $data) {
                    if ($key != 'title' && $key != 'model') {
                        $newData = [];
                        foreach ((array) $data as $key1 => $value) {
                            $newData[$key1] = $value;
                        }
                        $records[] = $newData;
                    }
                }
                break;
            case 'memory':
                $newData = [];
                foreach ((array) $summaryData->{$type}->{$table} as $key => $data) {
                    if ($key != 'title') {
                        foreach ((array) $data as $key1 => $value) {
                            if ($key1 == 'value') {
                                $newData[$key] = $value;
                            }
                        }
                    }
                }
                $records[] = $newData;
                break;
            case 'procs':
            case 'limits':
                // 1.- Fix record length. Find the largest number of fileds and extract the keys
                $N = 0;
                $cols = [];
                foreach ((array) $summaryData->{$type}->{$table} as $pid => $data) {
                    $n = count(get_object_vars($data));
                    if ($n > $N) {
                        $N = $n;
                        $cols = array_keys(get_object_vars($data));
                    }
                }

                foreach ((array) $summaryData->{$type}->{$table} as $pid => $data) {
                    $newData = [];
                    foreach ($cols as $key) {
                        $newData[$key] = isset($data->{$key}) ? $data->{$key} : 'N/A';
                    }
                    $records[] = $newData;
                }
                break;
        }

        // $records = array_slice($records, 0, 10);

        // log::info(var_export($records, true));

        return collect($records)->toArray();
    }
}
