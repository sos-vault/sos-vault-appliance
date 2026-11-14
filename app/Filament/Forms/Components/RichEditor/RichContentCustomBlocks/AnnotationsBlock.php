<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use App\Models\Annotation;
use App\Models\ContentsRequest;
use App\Models\User;
use App\Providers\VaultTools;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\HtmlString;

class AnnotationsBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'annotations';
    }

    public static function getLabel(): string
    {
        return 'Annotations Table';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalDescription('Configure the annotations table')
            ->schema([
                Placeholder::make('save_notice')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<div class="rounded-lg bg-warning-50 border border-warning-300 text-warning-800 dark:bg-warning-950 dark:border-warning-700 dark:text-warning-200 px-4 py-3 text-sm font-medium">'
                        .'⚠️ Save the report after inserting this block to see results in the View tab.'
                        .'</div>'
                    )),
                TextInput::make('heading')
                    ->default('Annotations')
                    ->label('Table title')
                    ->required(),
                TextInput::make('subheading')
                    ->default('File annotations for this case')
                    ->label('Description'),
            ]);
    }

    public static function toPreviewHtml(array $config): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.generic.preview', [
            'heading' => $config['heading'] ?? '',
            'subheading' => $config['subheading'] ?? '',
        ])->render();
    }

    public static function toHtml(array $config, array $data): string
    {
        if (empty($data['cid']) || empty($data['vid'])) {
            return '';
        }

        $vid = $data['vid'];
        $cid = $data['cid'];

        $fileIds = ContentsRequest::where('case_id', $cid)->pluck('file_id');

        $annotations = Annotation::where('vault_id', $vid)
            ->whereIn('file_id', $fileIds)
            ->whereNotNull('acetate')
            ->where('acetate', '!=', '')
            ->get();

        // Bulk-load user names from annotation owner IDs
        $ownerIds = $annotations->pluck('owner')->filter()->unique()->values()->all();
        $userNames = User::whereIn('id', $ownerIds)->pluck('name', 'id');

        // Initialize VaultTools once for filename lookups
        $vtools = null;

        try {
            $vtools = new VaultTools(resolveVaultUser($vid, $cid), $vid);

            if ((string) $vtools->getVaultId() !== (string) $vid) {
                $vtools = null;
            } else {
                $vtools->openVault();

                if (! $vtools->isOpen()) {
                    $vtools = null;
                }
            }
        } catch (\Throwable) {
            $vtools = null;
        }

        $filenameCache = [];
        $records = [];

        foreach ($annotations as $annotation) {
            $notes = self::extractNotesFromAcetate($annotation->acetate ?? '');

            $fid = $annotation->file_id;
            $did = $annotation->dir_id;
            $cacheKey = "{$did}_{$fid}";

            if (! array_key_exists($cacheKey, $filenameCache)) {
                $filename = "File #{$fid}";

                if ($vtools !== null) {
                    try {
                        $fileNode = $vtools->getFilePathById($vid, $did, $fid);

                        if ($fileNode && isset($fileNode->name)) {
                            $filename = $fileNode->name;
                        }
                    } catch (\Throwable) {
                        // keep default
                    }
                }

                $filenameCache[$cacheKey] = $filename;
            }

            $username = $userNames->get($annotation->owner, '—');

            foreach ($notes as $note) {
                $records[] = [
                    'date' => $note['date'] ?? '',
                    'time' => $note['time'] ?? '',
                    'username' => $username,
                    'filename' => $filenameCache[$cacheKey],
                    'note' => $note['note'] ?? '',
                ];
            }
        }

        usort($records, fn ($a, $b) => strcmp($a['date'].$a['time'], $b['date'].$b['time']));

        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.generic.index', [
            'heading' => $config['heading'] ?? '',
            'subheading' => $config['subheading'] ?? '',
            'records' => $records,
            'headers' => ['Date', 'Time', 'User', 'File', 'Note'],
            'orders' => ['date', 'time', 'username', 'filename', 'note'],
        ])->render();
    }

    private static function extractNotesFromAcetate(string $acetate): array
    {
        $decoded = json_decode($acetate, true);

        if (! $decoded || ! isset($decoded['node']['childNodes'])) {
            return [];
        }

        $notes = [];

        foreach ($decoded['node']['childNodes'] as $child) {
            if (
                isset($child['tagName']) && $child['tagName'] === 'DIV'
                && isset($child['className']) && str_contains((string) $child['className'], 'note')
            ) {
                $note = self::extractSingleNote($child);

                if (! empty($note['note'])) {
                    $notes[] = $note;
                }
            }
        }

        return $notes;
    }

    private static function extractSingleNote(array $noteDiv): array
    {
        $flat = self::flattenDescendants($noteDiv);

        $result = ['date' => '', 'time' => '', 'uid' => '', 'filename' => '', 'note' => ''];

        foreach ($flat as $node) {
            $id = $node['id'] ?? ($node['attributes']['id'] ?? '');

            if (! $id) {
                continue;
            }

            if (str_starts_with($id, 'date_')) {
                $result['date'] = $node['value'] ?? ($node['attributes']['value'] ?? '');
            } elseif (str_starts_with($id, 'time_')) {
                $result['time'] = $node['value'] ?? ($node['attributes']['value'] ?? '');
            } elseif (str_starts_with($id, 'uid_')) {
                $result['uid'] = $node['value'] ?? ($node['attributes']['value'] ?? '');
            } elseif (str_starts_with($id, 'name_')) {
                $val = $node['value'] ?? ($node['attributes']['value'] ?? '');

                if ($val && $val !== 'null' && $val !== 'undefined') {
                    $result['filename'] = $val;
                }
            } elseif (str_starts_with($id, 'noteText')) {
                $result['note'] = $node['value'] ?? ($node['innerText'] ?? ($node['textContent'] ?? ''));
            }
        }

        return $result;
    }

    private static function flattenDescendants(array $node): array
    {
        $result = [$node];

        foreach ($node['childNodes'] ?? [] as $child) {
            foreach (self::flattenDescendants($child) as $descendant) {
                $result[] = $descendant;
            }
        }

        return $result;
    }
}
