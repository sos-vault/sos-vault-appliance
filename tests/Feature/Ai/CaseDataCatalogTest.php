<?php

use App\Services\Ai\CaseDataCatalog;

beforeEach(function () {
    $this->catalog = new CaseDataCatalog;
});

it('declares at least the two representative sources', function () {
    expect($this->catalog->ids())->toContain('processes', 'network');
});

it('gives every source the required schema keys', function () {
    foreach ($this->catalog->all() as $id => $src) {
        expect($src)
            ->toHaveKeys(['file', 'title', 'purpose', 'shape', 'fields', 'joins', 'answers'], "source [$id]");

        expect($src['shape'])->toBeIn(['object', 'array']);
        expect($src['fields'])->toBeArray()->not->toBeEmpty("source [$id] fields");
        expect($src['answers'])->toBeArray()->not->toBeEmpty("source [$id] answers");
        expect($src['file'])->toStartWith('.')->toEndWith('.json');

        // keyed_by is null (structured record or array) or names the map key.
        $keyedBy = $src['keyed_by'] ?? null;
        expect($keyedBy === null || (is_string($keyedBy) && $keyedBy !== ''))
            ->toBeTrue("source [$id] keyed_by must be null or a non-empty string");

        // an array is a list of rows, never a keyed map.
        if ($src['shape'] === 'array') {
            expect($keyedBy)->toBeNull("source [$id]: array shape cannot set keyed_by");
        }
    }
});

it('only declares joins that reference a real, declared source', function () {
    $ids = $this->catalog->ids();

    foreach ($this->catalog->joins() as $edge) {
        expect($edge['to'])->toBeIn($ids, "join {$edge['from']} -> {$edge['to']}");
        expect($edge['on'])->not->toBe('', "join {$edge['from']} -> {$edge['to']} needs a key");
    }
});

it('resolves sources by id and by filename', function () {
    $byId = $this->catalog->get('network');
    $byFile = $this->catalog->forFile('.networkData.json');

    expect($byId)->not->toBeNull()
        ->and($byId['id'])->toBe('network')
        ->and($byFile['id'])->toBe('network');

    expect($this->catalog->get('nope'))->toBeNull();
    expect($this->catalog->forFile('.nope.json'))->toBeNull();
});

it('exposes a compact index without dumping field dictionaries', function () {
    $index = $this->catalog->index();

    expect($index)->not->toBeEmpty();
    foreach ($index as $entry) {
        expect($entry)->toHaveKeys(['id', 'title', 'purpose', 'answers'])
            ->and($entry)->not->toHaveKey('fields'); // the map stays light
    }
});

// Drift guard: a catalog entry that names a file no generator produces is a lie
// that would send the model (or the fetch tool) after data that never exists.
it('backs every declared file with a DataTools generator', function () {
    $dataTools = file_get_contents(base_path('app/Providers/DataTools.php'));

    foreach ($this->catalog->all() as $id => $src) {
        // str_contains keeps the failure message pointed at the offending source;
        // expect()->toContain() would treat a second arg as another needle.
        expect(str_contains($dataTools, $src['file']))->toBeTrue(
            "no generator in DataTools.php writes {$src['file']} for catalog source [$id]"
        );
    }
});
