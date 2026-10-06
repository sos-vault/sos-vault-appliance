<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Sushi\Sushi;

class SosPlugins extends Model
{
    use Sushi;

    public function getRows(): array
    {
        return self::parseJsonFile($this->sushiCacheReferencePath());
    }

    public static function parseJsonFile(string $jsonFile): array
    {
        if (! is_file($jsonFile)) {
            return [];
        }

        $records = json_decode(file_get_contents($jsonFile), true) ?? [];
        $data = [];

        foreach ($records as $record) {
            $record['name'] = trim(preg_replace("/('|\")/", '', $record['name']));
            $record['short_description'] = trim(preg_replace("/('|\")/", '', $record['short_description']));
            $record['long_description'] = trim(preg_replace("/('|\")/", '', $record['long_description']));
            $record['profiles'] = trim(preg_replace("/('|\")/", '', $record['profiles']));
            $record['options'] = trim(preg_replace("/('|\")/", '', $record['options']));
            $data[] = $record;
        }

        return $data;
    }

    protected function sushiCacheReferencePath()
    {
        return base_path('json/sos_plugins.json');
    }
}
