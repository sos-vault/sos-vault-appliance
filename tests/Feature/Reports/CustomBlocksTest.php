<?php

/**
 * RichEditor Custom Blocks — Feature Tests
 *
 * Covers:
 *  AnnotationsBlock
 *    - getId / getLabel contracts
 *    - toPreviewHtml includes heading and subheading
 *    - toHtml returns '' when cid or vid is missing
 *    - toHtml returns empty-records table when no annotations exist
 *    - toHtml parses a single note from acetate JSON
 *    - toHtml uses annotation->owner for the username (uid_ input is unreliable)
 *    - toHtml falls back to "File #N" when VaultTools is unavailable
 *    - toHtml extracts multiple notes from one acetate record
 *    - toHtml aggregates notes across multiple annotation records
 *    - toHtml sorts records by date + time ascending
 *    - toHtml ignores note divs that carry no note text
 *    - invalid / empty acetate JSON is handled gracefully
 *
 *  ActivityReportBlock
 *    - getId / getLabel contracts
 *    - toPreviewHtml includes heading and subheading
 *    - toHtml returns '' when the user email is not found
 *    - toHtml builds records from sysevents within the given timeframe
 *    - toHtml returns empty-records table when no events match
 *
 *  SosCustomBlock-derived blocks (Host, Cpu, Mem, Disk, Proc, TcpSockets, UnixSockets)
 *    - getId / getLabel contracts for each block
 *    - toHtml returns '' when any required data key is missing
 */

use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ActivityReportBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\AnnotationsBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\CpuBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\DiskBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\HostBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\MemBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ProcBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\TcpSocketsBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\UnixSocketsBlock;
use App\Models\Annotation;
use App\Models\ContentsRequest;
use App\Models\Sysevent;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a minimal domJSON acetate payload that represents one sticky note.
 *
 * @param  array<string,string>  $fields  Keys: date, time, uid, name, note. id suffix is used as the note number.
 */
function makeAcetate(array $fields, int $num = 1): string
{
    return json_encode([
        'node' => [
            'id' => 'acetate1',
            'tagName' => 'DIV',
            'childNodes' => [
                [
                    'tagName' => 'DIV',
                    'className' => 'note absolute top-10 left-10',
                    'id' => "note{$num}",
                    'childNodes' => [
                        ['tagName' => 'INPUT',    'id' => "date_{$num}",     'value' => $fields['date'] ?? '',      'childNodes' => []],
                        ['tagName' => 'INPUT',    'id' => "time_{$num}",     'value' => $fields['time'] ?? '',      'childNodes' => []],
                        ['tagName' => 'INPUT',    'id' => "uid_{$num}",      'value' => $fields['uid'] ?? 'undefined', 'childNodes' => []],
                        ['tagName' => 'INPUT',    'id' => "name_{$num}",     'value' => $fields['name'] ?? 'null', 'childNodes' => []],
                        ['tagName' => 'TEXTAREA', 'id' => "noteText{$num}",  'value' => $fields['note'] ?? '',      'childNodes' => []],
                    ],
                ],
                ['tagName' => 'PRE', 'id' => 'pre1', 'childNodes' => []],
            ],
        ],
    ]);
}

/**
 * Build an acetate with two note divs.
 */
function makeAcetateTwoNotes(array $note1, array $note2): string
{
    return json_encode([
        'node' => [
            'id' => 'acetate1',
            'tagName' => 'DIV',
            'childNodes' => [
                [
                    'tagName' => 'DIV',
                    'className' => 'note absolute top-10 left-10',
                    'id' => 'note1',
                    'childNodes' => [
                        ['tagName' => 'INPUT',    'id' => 'date_1',     'value' => $note1['date'] ?? '',      'childNodes' => []],
                        ['tagName' => 'INPUT',    'id' => 'time_1',     'value' => $note1['time'] ?? '',      'childNodes' => []],
                        ['tagName' => 'INPUT',    'id' => 'uid_1',      'value' => 'undefined',               'childNodes' => []],
                        ['tagName' => 'INPUT',    'id' => 'name_1',     'value' => 'null',                    'childNodes' => []],
                        ['tagName' => 'TEXTAREA', 'id' => 'noteText1',  'value' => $note1['note'] ?? '',      'childNodes' => []],
                    ],
                ],
                [
                    'tagName' => 'DIV',
                    'className' => 'note absolute top-20 left-20',
                    'id' => 'note2',
                    'childNodes' => [
                        ['tagName' => 'INPUT',    'id' => 'date_2',     'value' => $note2['date'] ?? '',      'childNodes' => []],
                        ['tagName' => 'INPUT',    'id' => 'time_2',     'value' => $note2['time'] ?? '',      'childNodes' => []],
                        ['tagName' => 'INPUT',    'id' => 'uid_2',      'value' => 'undefined',               'childNodes' => []],
                        ['tagName' => 'INPUT',    'id' => 'name_2',     'value' => 'null',                    'childNodes' => []],
                        ['tagName' => 'TEXTAREA', 'id' => 'noteText2',  'value' => $note2['note'] ?? '',      'childNodes' => []],
                    ],
                ],
                ['tagName' => 'PRE', 'id' => 'pre1', 'childNodes' => []],
            ],
        ],
    ]);
}

$defaultConfig = ['heading' => 'Annotations', 'subheading' => 'Notes'];

// ---------------------------------------------------------------------------
// AnnotationsBlock — getId / getLabel
// ---------------------------------------------------------------------------

it('AnnotationsBlock getId returns annotations', function () {
    expect(AnnotationsBlock::getId())->toBe('annotations');
});

it('AnnotationsBlock getLabel returns Annotations Table', function () {
    expect(AnnotationsBlock::getLabel())->toBe('Annotations Table');
});

// ---------------------------------------------------------------------------
// AnnotationsBlock — toPreviewHtml
// ---------------------------------------------------------------------------

it('AnnotationsBlock toPreviewHtml includes heading and subheading', function () {
    $html = AnnotationsBlock::toPreviewHtml(['heading' => 'My Heading', 'subheading' => 'My Sub']);

    expect($html)->toContain('My Heading')
        ->and($html)->toContain('My Sub');
});

// ---------------------------------------------------------------------------
// AnnotationsBlock — toHtml guards
// ---------------------------------------------------------------------------

it('AnnotationsBlock toHtml returns empty string when cid is missing', function () {
    expect(AnnotationsBlock::toHtml(['heading' => 'X'], ['vid' => 1]))->toBe('');
});

it('AnnotationsBlock toHtml returns empty string when vid is missing', function () {
    expect(AnnotationsBlock::toHtml(['heading' => 'X'], ['cid' => 1]))->toBe('');
});

it('AnnotationsBlock toHtml returns empty string when both vid and cid are missing', function () {
    expect(AnnotationsBlock::toHtml(['heading' => 'X'], []))->toBe('');
});

// ---------------------------------------------------------------------------
// AnnotationsBlock — toHtml with no annotations
// ---------------------------------------------------------------------------

it('AnnotationsBlock toHtml renders table with no records when no annotations exist', function () {
    $html = AnnotationsBlock::toHtml(['heading' => 'Empty', 'subheading' => 'Sub'], ['vid' => 99, 'cid' => 99]);

    expect($html)->toContain('Empty')->and($html)->toContain('Sub');
});

// ---------------------------------------------------------------------------
// AnnotationsBlock — toHtml with a single note
// ---------------------------------------------------------------------------

it('AnnotationsBlock toHtml parses a single note from acetate', function () {
    $this->seed(RolesTableSeeder::class);

    $user = User::factory()->create(['name' => 'Alice']);

    $cr = ContentsRequest::factory()->create(['case_id' => 10, 'file_id' => 200, 'vault_id' => 5]);

    Annotation::factory()->create([
        'vault_id' => 5,
        'dir_id' => 1,
        'file_id' => 200,
        'owner' => $user->id,
        'acetate' => makeAcetate(['date' => '2024-03-01', 'time' => '09:00:00', 'note' => 'Check this line']),
    ]);

    $html = AnnotationsBlock::toHtml(
        ['heading' => 'Notes', 'subheading' => 'Sub'],
        ['vid' => 5, 'cid' => 10]
    );

    expect($html)
        ->toContain('Alice')
        ->toContain('2024-03-01')
        ->toContain('09:00:00')
        ->toContain('Check this line')
        ->toContain('File #200');         // VaultTools unavailable → fallback filename
});

// ---------------------------------------------------------------------------
// AnnotationsBlock — username comes from annotation->owner, not uid_ input
// ---------------------------------------------------------------------------

it('AnnotationsBlock toHtml uses annotation owner for username, not uid_ input', function () {
    $this->seed(RolesTableSeeder::class);

    $user = User::factory()->create(['name' => 'Bob Owner']);

    ContentsRequest::factory()->create(['case_id' => 11, 'file_id' => 300, 'vault_id' => 6]);

    Annotation::factory()->create([
        'vault_id' => 6,
        'dir_id' => 1,
        'file_id' => 300,
        'owner' => $user->id,
        // uid_ value is 'undefined' — must NOT be used for username
        'acetate' => makeAcetate(['date' => '2024-03-02', 'time' => '10:00:00', 'uid' => 'undefined', 'note' => 'Owner note']),
    ]);

    $html = AnnotationsBlock::toHtml(
        ['heading' => 'Notes', 'subheading' => ''],
        ['vid' => 6, 'cid' => 11]
    );

    expect($html)->toContain('Bob Owner');
});

// ---------------------------------------------------------------------------
// AnnotationsBlock — multiple notes in a single acetate record
// ---------------------------------------------------------------------------

it('AnnotationsBlock toHtml extracts multiple notes from one acetate', function () {
    $this->seed(RolesTableSeeder::class);

    $user = User::factory()->create(['name' => 'Carol']);

    ContentsRequest::factory()->create(['case_id' => 12, 'file_id' => 400, 'vault_id' => 7]);

    Annotation::factory()->create([
        'vault_id' => 7,
        'dir_id' => 1,
        'file_id' => 400,
        'owner' => $user->id,
        'acetate' => makeAcetateTwoNotes(
            ['date' => '2024-04-01', 'time' => '08:00:00', 'note' => 'First note'],
            ['date' => '2024-04-01', 'time' => '09:00:00', 'note' => 'Second note'],
        ),
    ]);

    $html = AnnotationsBlock::toHtml(
        ['heading' => 'Notes', 'subheading' => ''],
        ['vid' => 7, 'cid' => 12]
    );

    expect($html)
        ->toContain('First note')
        ->toContain('Second note');
});

// ---------------------------------------------------------------------------
// AnnotationsBlock — notes aggregated across multiple annotation records
// ---------------------------------------------------------------------------

it('AnnotationsBlock toHtml aggregates notes from multiple annotation records', function () {
    $this->seed(RolesTableSeeder::class);

    $user = User::factory()->create(['name' => 'Dave']);

    ContentsRequest::factory()->create(['case_id' => 13, 'file_id' => 501, 'vault_id' => 8]);
    ContentsRequest::factory()->create(['case_id' => 13, 'file_id' => 502, 'vault_id' => 8]);

    Annotation::factory()->create([
        'vault_id' => 8, 'dir_id' => 1, 'file_id' => 501, 'owner' => $user->id,
        'acetate' => makeAcetate(['date' => '2024-05-01', 'time' => '10:00:00', 'note' => 'Note on file 501']),
    ]);

    Annotation::factory()->create([
        'vault_id' => 8, 'dir_id' => 1, 'file_id' => 502, 'owner' => $user->id,
        'acetate' => makeAcetate(['date' => '2024-05-02', 'time' => '11:00:00', 'note' => 'Note on file 502']),
    ]);

    $html = AnnotationsBlock::toHtml(
        ['heading' => 'Notes', 'subheading' => ''],
        ['vid' => 8, 'cid' => 13]
    );

    expect($html)
        ->toContain('Note on file 501')
        ->toContain('Note on file 502');
});

// ---------------------------------------------------------------------------
// AnnotationsBlock — annotations from a different vault/case are excluded
// ---------------------------------------------------------------------------

it('AnnotationsBlock toHtml excludes annotations from a different vault', function () {
    $this->seed(RolesTableSeeder::class);

    $user = User::factory()->create(['name' => 'Eve']);

    // Only a ContentsRequest for case 14 / file 600 / vault 9
    ContentsRequest::factory()->create(['case_id' => 14, 'file_id' => 600, 'vault_id' => 9]);

    // Annotation that belongs to a DIFFERENT vault
    Annotation::factory()->create([
        'vault_id' => 99, 'dir_id' => 1, 'file_id' => 600, 'owner' => $user->id,
        'acetate' => makeAcetate(['date' => '2024-06-01', 'time' => '08:00:00', 'note' => 'Should be excluded']),
    ]);

    $html = AnnotationsBlock::toHtml(
        ['heading' => 'Notes', 'subheading' => ''],
        ['vid' => 9, 'cid' => 14]
    );

    expect($html)->not->toContain('Should be excluded');
});

// ---------------------------------------------------------------------------
// AnnotationsBlock — records are sorted by date + time ascending
// ---------------------------------------------------------------------------

it('AnnotationsBlock toHtml sorts records by date and time ascending', function () {
    $this->seed(RolesTableSeeder::class);

    $user = User::factory()->create(['name' => 'Frank']);

    ContentsRequest::factory()->create(['case_id' => 15, 'file_id' => 700, 'vault_id' => 10]);

    Annotation::factory()->create([
        'vault_id' => 10, 'dir_id' => 1, 'file_id' => 700, 'owner' => $user->id,
        'acetate' => makeAcetateTwoNotes(
            ['date' => '2024-07-02', 'time' => '12:00:00', 'note' => 'Later note'],
            ['date' => '2024-07-01', 'time' => '08:00:00', 'note' => 'Earlier note'],
        ),
    ]);

    $html = AnnotationsBlock::toHtml(
        ['heading' => 'Notes', 'subheading' => ''],
        ['vid' => 10, 'cid' => 15]
    );

    $posEarlier = strpos($html, 'Earlier note');
    $posLater = strpos($html, 'Later note');

    expect($posEarlier)->toBeLessThan($posLater);
});

// ---------------------------------------------------------------------------
// AnnotationsBlock — note divs with no note text are skipped
// ---------------------------------------------------------------------------

it('AnnotationsBlock toHtml skips note divs that carry no note text', function () {
    $this->seed(RolesTableSeeder::class);

    $user = User::factory()->create(['name' => 'Grace']);

    ContentsRequest::factory()->create(['case_id' => 16, 'file_id' => 800, 'vault_id' => 11]);

    Annotation::factory()->create([
        'vault_id' => 11, 'dir_id' => 1, 'file_id' => 800, 'owner' => $user->id,
        'acetate' => makeAcetate(['date' => '2024-08-01', 'time' => '09:00:00', 'note' => '']),
    ]);

    $html = AnnotationsBlock::toHtml(
        ['heading' => 'Notes', 'subheading' => ''],
        ['vid' => 11, 'cid' => 16]
    );

    // Grace appears as a user but no note rows should be rendered for the empty note
    expect($html)->not->toContain('Grace');
});

// ---------------------------------------------------------------------------
// AnnotationsBlock — graceful handling of invalid acetate JSON
// ---------------------------------------------------------------------------

it('AnnotationsBlock toHtml handles invalid acetate JSON gracefully', function () {
    $this->seed(RolesTableSeeder::class);

    $user = User::factory()->create(['name' => 'Henry']);

    ContentsRequest::factory()->create(['case_id' => 17, 'file_id' => 900, 'vault_id' => 12]);

    Annotation::factory()->create([
        'vault_id' => 12, 'dir_id' => 1, 'file_id' => 900, 'owner' => $user->id,
        'acetate' => 'NOT VALID JSON {{{{',
    ]);

    // Should not throw; the malformed record is silently ignored
    $html = AnnotationsBlock::toHtml(
        ['heading' => 'Notes', 'subheading' => ''],
        ['vid' => 12, 'cid' => 17]
    );

    expect($html)->toBeString()->not->toContain('Henry');
});

// ---------------------------------------------------------------------------
// AnnotationsBlock — annotations with null/empty acetate are skipped
// ---------------------------------------------------------------------------

it('AnnotationsBlock toHtml skips annotations with null or empty acetate', function () {
    $this->seed(RolesTableSeeder::class);

    $user = User::factory()->create(['name' => 'Iris']);

    ContentsRequest::factory()->create(['case_id' => 18, 'file_id' => 1000, 'vault_id' => 13]);

    // null acetate
    Annotation::factory()->create([
        'vault_id' => 13, 'dir_id' => 1, 'file_id' => 1000, 'owner' => $user->id,
        'acetate' => null,
    ]);

    // empty string acetate
    Annotation::factory()->create([
        'vault_id' => 13, 'dir_id' => 1, 'file_id' => 1000, 'owner' => $user->id,
        'acetate' => '',
    ]);

    $html = AnnotationsBlock::toHtml(
        ['heading' => 'Notes', 'subheading' => ''],
        ['vid' => 13, 'cid' => 18]
    );

    expect($html)->toBeString()->not->toContain('Iris');
});

// ===========================================================================
// ActivityReportBlock
// ===========================================================================

it('ActivityReportBlock getId returns activity', function () {
    expect(ActivityReportBlock::getId())->toBe('activity');
});

it('ActivityReportBlock getLabel returns Activity Report Table', function () {
    expect(ActivityReportBlock::getLabel())->toBe('Activity Report Table');
});

it('ActivityReportBlock toPreviewHtml includes heading and subheading', function () {
    $html = ActivityReportBlock::toPreviewHtml(['heading' => 'Activity', 'subheading' => 'Desc']);

    expect($html)->toContain('Activity')->and($html)->toContain('Desc');
});

it('ActivityReportBlock toHtml returns empty string when user email is not found', function () {
    $html = ActivityReportBlock::toHtml(
        ['heading' => 'X', 'subheading' => '', 'email' => 'nobody@example.com', 'timeframe' => 'this_month'],
        ['cid' => 1]
    );

    expect($html)->toBe('');
});

it('ActivityReportBlock toHtml builds records from matching sysevents', function () {
    $this->seed(RolesTableSeeder::class);

    $user = User::factory()->create(['email' => 'jane@example.com', 'name' => 'Jane']);

    Sysevent::factory()->create([
        'owner' => $user->id,
        'case_id' => 20,
        'type' => 'OPEN_FILE',
        'status' => 'SUCCESS',
        'payload' => json_encode(['name' => 'messages', 'message' => 'opened']),
        'created_at' => now(),
    ]);

    $html = ActivityReportBlock::toHtml(
        ['heading' => 'Activity', 'subheading' => '', 'email' => 'jane@example.com', 'timeframe' => 'this_month'],
        ['cid' => 20]
    );

    expect($html)
        ->toContain('Jane')
        ->toContain('OPEN_FILE')
        ->toContain('SUCCESS')
        ->toContain('messages');
});

it('ActivityReportBlock toHtml returns empty records table when no events match timeframe', function () {
    $this->seed(RolesTableSeeder::class);

    $user = User::factory()->create(['email' => 'noevents@example.com', 'name' => 'NoEvents']);

    $html = ActivityReportBlock::toHtml(
        ['heading' => 'Activity', 'subheading' => '', 'email' => 'noevents@example.com', 'timeframe' => 'today'],
        ['cid' => 999]
    );

    // Returns a rendered table (not empty string) but with no data rows
    expect($html)->toBeString()->toContain('Activity');
});

it('ActivityReportBlock toHtml excludes events outside the given timeframe', function () {
    $this->seed(RolesTableSeeder::class);

    $user = User::factory()->create(['email' => 'old@example.com', 'name' => 'OldUser']);

    // Event created 2 months ago — outside 'this_month'
    Sysevent::factory()->create([
        'owner' => $user->id,
        'case_id' => 21,
        'type' => 'OLD_EVENT',
        'status' => 'SUCCESS',
        'payload' => null,
        'created_at' => now()->subMonths(2),
    ]);

    $html = ActivityReportBlock::toHtml(
        ['heading' => 'Activity', 'subheading' => '', 'email' => 'old@example.com', 'timeframe' => 'this_month'],
        ['cid' => 21]
    );

    expect($html)->not->toContain('OLD_EVENT');
});

// ===========================================================================
// SosCustomBlock-derived blocks — getId / getLabel contracts
// ===========================================================================

it('HostBlock getId returns host', function () {
    expect(HostBlock::getId())->toBe('host');
});

it('HostBlock getLabel returns Host Info Table', function () {
    expect(HostBlock::getLabel())->toBe('Host Info Table');
});

it('CpuBlock getId returns cpu', function () {
    expect(CpuBlock::getId())->toBe('cpu');
});

it('CpuBlock getLabel returns Cpu Table', function () {
    expect(CpuBlock::getLabel())->toBe('Cpu Table');
});

it('MemBlock getId returns memory', function () {
    expect(MemBlock::getId())->toBe('memory');
});

it('MemBlock getLabel returns Memory Table', function () {
    expect(MemBlock::getLabel())->toBe('Memory Table');
});

it('DiskBlock getId returns disk', function () {
    expect(DiskBlock::getId())->toBe('disk');
});

it('DiskBlock getLabel returns Disk Table', function () {
    expect(DiskBlock::getLabel())->toBe('Disk Table');
});

it('ProcBlock getId returns proc', function () {
    expect(ProcBlock::getId())->toBe('proc');
});

it('ProcBlock getLabel returns Single Process Table', function () {
    expect(ProcBlock::getLabel())->toBe('Single Process Table');
});

it('TcpSocketsBlock getId returns tcpsockets', function () {
    expect(TcpSocketsBlock::getId())->toBe('tcpsockets');
});

it('TcpSocketsBlock getLabel returns TCP sockets Table', function () {
    expect(TcpSocketsBlock::getLabel())->toBe('TCP sockets Table');
});

it('UnixSocketsBlock getId returns unixsockets', function () {
    expect(UnixSocketsBlock::getId())->toBe('unixsockets');
});

it('UnixSocketsBlock getLabel returns Unix sockets Table', function () {
    expect(UnixSocketsBlock::getLabel())->toBe('Unix sockets Table');
});

// ===========================================================================
// SosCustomBlock-derived blocks — toHtml returns '' on missing data keys
// ===========================================================================

$sosBlocks = [
    'HostBlock' => HostBlock::class,
    'CpuBlock' => CpuBlock::class,
    'MemBlock' => MemBlock::class,
    'DiskBlock' => DiskBlock::class,
    'ProcBlock' => ProcBlock::class,
    'TcpSocketsBlock' => TcpSocketsBlock::class,
    'UnixSocketsBlock' => UnixSocketsBlock::class,
];

foreach ($sosBlocks as $name => $class) {
    it("{$name} toHtml returns empty string when vid is missing", function () use ($class) {
        $result = $class::toHtml(
            ['heading' => 'X'],
            ['did' => 1, 'cid' => 1, 'type' => 'host', 'indx' => 0]
        );
        expect($result)->toBe('');
    });

    it("{$name} toHtml returns empty string when did is missing", function () use ($class) {
        $result = $class::toHtml(
            ['heading' => 'X'],
            ['vid' => 1, 'cid' => 1, 'type' => 'host', 'indx' => 0]
        );
        expect($result)->toBe('');
    });

    it("{$name} toHtml returns empty string when cid is missing", function () use ($class) {
        $result = $class::toHtml(
            ['heading' => 'X'],
            ['vid' => 1, 'did' => 1, 'type' => 'host', 'indx' => 0]
        );
        expect($result)->toBe('');
    });
}
