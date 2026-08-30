<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use App\Providers\SosServiceProvider;
use App\Providers\VaultTools;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

abstract class SosCustomBlock extends RichContentCustomBlock
{
    public static function toHtml(array $config, array $data): string
    {
        $required = ['vid', 'did', 'cid', 'type', 'indx'];
        foreach ($required as $k) {
            if (empty($data[$k]) && ($data[$k] ?? null) !== 0) {
                Log::warning("CustomBlock missing data key: {$k}");

                return '';
            }
        }

        $vid = $data['vid'];
        $did = $data['did'];
        $cid = $data['cid'];
        $type = $data['type'];
        $indx = $data['indx'];

        $cpid = '';
        if (isset($config['pid'])) {
            $cpid = $config['pid'];
        }

        $vtools = new VaultTools(resolveVaultUser($vid, $cid, $did), $vid);
        if (! isset($vtools)) {
            return '';
        }

        if ((string) $vtools->getVaultId() !== (string) $vid) {
            return '';
        }

        $vtools->openVault();

        if (! $vtools->isOpen()) {
            return '';
        }

        $summaryData = [];
        $dtools = new SosServiceProvider($vtools, $vid, $did, $cid);
        if (isset($dtools)) {
            $summaryData = $dtools->getSummary();
        }

        $keys = array_keys((array) $summaryData->{$type});
        $tables = explode(',', implode(',', preg_grep("/tableData\d+/", $keys)));
        $headers = explode(',', implode(',', preg_grep("/tableHeaders\d+/", $keys)));
        $orders = explode(',', implode(',', preg_grep("/tableOrder\d+/", $keys)));

        $table = $tables[$indx];
        $header = $headers[$indx];
        $order = $orders[$indx];

        if ($type == 'procs' && isset($cpid)) {
            $order = [
                'Command',
                'PID',
                'PPID',
                'USER',
                'STAT',
                '%CPU',
                'PRI',
                'NI',
                'VSZ',
                'threads',
                'fd-nr',
            ];
            $header = [
                'COMMAND',
                'PID',
                'PPID',
                'USER',
                'STATE',
                '%CPU',
                'PRIO',
                'NICE',
                'VMEM',
                'THREADS',
                'FILES',
            ];
        }

        if ($type == 'disk') {
            $order = [
                'point',
                'size',
                'used',
                'pused',
                'iused',
                'ipused',
                'dtype',
                'fstype',
            ];
            $header = [
                'mount point',
                'disk size',
                'disk use',
                '% use',
                'inodes use',
                '% inodes use',
                'disk type',
                'fs type',
            ];
        }

        $records = [];

        switch ($type) {
            case 'host':
                // make this table vertical with no headers and just two columns name and value
                foreach ((array) $summaryData->{$type}->{$table} as $name => $value) {
                    if ($name == 'icon') {
                        continue;
                    }
                    $records[] = [
                        'name' => $name,
                        'value' => $value,
                    ];
                }

                return view('filament.forms.components.rich-editor.rich-content-custom-blocks.generic.index', [
                    'heading' => $config['heading'],
                    'subheading' => $config['subheading'],
                    'records' => $records,
                    'headers' => [],
                    'orders' => ['name', 'value'],
                ])->render();

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
                $records = (array) $summaryData->{$type}->{$table}->data;
                break;
            case 'cpu':
            case 'disk':
                $percentages = ['pused', 'ipused'];
                $strings = ['point', 'label', 'dtype', 'fstype'];
                foreach ((array) $summaryData->{$type}->{$table} as $key => $data) {
                    if ($key != 'title' && $key != 'model') {
                        $newData = [];
                        foreach ((array) $data as $key1 => $value) {
                            if ($type == 'cpu') {
                                $newData[$key1] = Number::percentage(floatval($value), precision: 2);
                            }
                            if ($type == 'disk') {
                                if (in_array($key1, $order)) {
                                    if (in_array($key1, $percentages)) {
                                        $newData[$key1] = Number::percentage(floatval($value), precision: 2);
                                    } elseif (in_array($key1, $strings)) {
                                        $newData[$key1] = $value;
                                    } else {
                                        $newData[$key1] = Number::fileSize(floatval($value), precision: 2);
                                    }
                                }
                            }
                        }
                        $records[] = $newData;
                    }
                }
                break;
            case 'memory':
                $newData = [];
                $percentages = ['pused', 'pfree', 'pbuff'];
                foreach ((array) $summaryData->{$type}->{$table} as $key => $data) {
                    if ($key != 'title') {
                        foreach ((array) $data as $key1 => $value) {
                            if ($key1 == 'value') {
                                if (in_array($key, $percentages)) {
                                    $newData[$key] = Number::percentage($value, precision: 2);
                                } else {
                                    $newData[$key] = Number::fileSize($value, precision: 2);
                                }
                            }
                        }
                    }
                }
                $records[] = $newData;
                break;
            case 'procs':
                if (isset($cpid)) {
                    foreach ((array) $summaryData->{$type}->{$table} as $pid => $data) {
                        if ($pid == $cpid) {
                            $newData = [];
                            foreach ($order as $key) {
                                if ($key == 'VSZ') {
                                    $newData[$key] = isset($data->{$key}) ? Number::fileSize($data->{$key}) : 'N/A';
                                } else {
                                    $newData[$key] = isset($data->{$key}) ? $data->{$key} : 'N/A';
                                }
                            }
                            $records[] = $newData;
                        }
                    }

                    return view('filament.forms.components.rich-editor.rich-content-custom-blocks.generic.index', [
                        'heading' => $config['heading'],
                        'subheading' => $config['subheading'],
                        'records' => $records,
                        'headers' => $header,
                        'orders' => $order,
                    ])->render();
                }
                break;
        }

        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.generic.index', [
            'heading' => $config['heading'],
            'subheading' => $config['subheading'],
            'records' => $records,
            'headers' => is_array($header) ? $header : $summaryData->{$type}->{$header},
            'orders' => is_array($order) ? $order : $summaryData->{$type}->{$order},
        ])->render();
    }
}
