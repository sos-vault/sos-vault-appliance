<?php
    use App\Models\FileList;
    use App\Models\Bookmark;

    use Filament\Actions\Contracts\HasActions;
    use Filament\Actions\Concerns\InteractsWithActions;

    use Filament\Notifications\Notification;

    use Filament\Schemas\Schema;
    use Filament\Schemas\Concerns\InteractsWithSchemas;
    use Filament\Schemas\Contracts\HasSchemas;
    use Filament\Schemas\Components\Section;
    use Filament\Forms\Components\Select;
    use Filament\Forms\Components\TextInput;

    use Illuminate\Support\Facades\Log;
    use Illuminate\Validation\ValidationException;

    use Livewire\Volt\Component;
    use Livewire\Attributes\On;

    new class extends Component implements HasSchemas, HasActions
    {
        use InteractsWithActions, InteractsWithSchemas;

        public ?array $record = [];
        public $fileData;
        public $fileList;
        public $newFileList;
        public $oldFileList;

        public $vid;
        public $cid;
        public $did;

        public $name;
        public $icon;
        public $fullpath;
        public $filetype;

        public $modalWidth = "2xl";

        public function mount($fileData)
        {
            if(isset($fileData) && !empty($fileData) && is_array($fileData)) {
                $this->fileData = $fileData;

                $this->vid      = $fileData['vid'];
                $this->cid      = $fileData['cid'];
                $this->did      = $fileData['did'];
                $this->name     = $fileData['name'];
                $this->icon     = $fileData['icon'];
                $this->fullpath = $fileData['fullpath'];
                $this->filetype = $fileData['filetype'];

                $this->modalWidth = "sm";

                // ok now lets show a context menu
                $this->dispatch('trigger-open-add-filelist-modal');
            }
        }

        public function addFileToFileList()  {
            $filelist = [];


            if(!isset($this->newFileList) && !isset($this->oldFileList)) {
                throw ValidationException::withMessages([
                    'newFileList' => ['A new name is required when no file list is selected.'],
                ]);
            }

            if(!isset($this->newFileList) && isset($this->oldFileList) && !empty($this->oldFileList)) {
                // FileLists are case-independent and identified by name; the
                // selected list may not have a row for the current case yet, so
                // find-or-create the current-case row for that name.
                $filelist = FileList::firstOrCreate(
                    [
                        'user_id'  => auth()->user()->id,
                        'vault_id' => $this->vid,
                        'case_id'  => $this->cid,
                        'dir_id'   => $this->did,
                        'name'     => $this->oldFileList,
                    ],
                    [
                        'title' => $this->oldFileList,
                    ]
                );

            } else if(isset($this->newFileList) && !isset($this->oldFileList) && !empty($this->newFileList)) {
                // create new File List

                $this->validate();

                $filelist = FileList::where('user_id', auth()->user()->id)
                    ->where('vault_id', $this->vid)
                    ->where('name', $this->newFileList)
                    ->first();

                if(isset($filelist) || !empty($filelist)) {
                    $this->newFileList = "";
                    throw ValidationException::withMessages([
                        'newFileList' => ['A file list with that same name already exists.'],
                    ]);
                }

                $filelist = FileList::create([
                    'user_id'  => auth()->user()->id,
                    'case_id'  => $this->cid,
                    'vault_id' => $this->vid,
                    'dir_id'   => $this->did,
                    'name'     => $this->newFileList,
                    'title'    => $this->newFileList,
                ]);

                if(!isset($filelist) || empty($filelist)) {
                    Notification::make()
                        ->title("Could not create File List '{$this->newFileList}'")
                        ->icon('phosphor-bell-ringing-duotone')
                        ->iconColor('danger')
                        ->body('Please try with another name.')
                        ->send();
                    $this->closefileListContextMenu();
                    return;
                }

            } else {
                Notification::make()
                    ->title("Could not add file to File List")
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->body('Please try with another name.')
                    ->send();
                $this->closefileListContextMenu();
                return;
            }

            // check if this file is already in the (case-independent) file list:
            // look across every same-named list in this vault.
            $sameNamedIds = FileList::where('user_id', auth()->user()->id)
                ->where('vault_id', $this->vid)
                ->where('name', $filelist->name)
                ->pluck('id');

            $bookmark = Bookmark::where('name', $this->name)
                ->where('user_id', auth()->user()->id)
                ->where('vault_id', $this->vid)
                ->whereIn('filelist_id', $sameNamedIds)
                ->where('fullpath', $this->fullpath)
                ->where('filetype', $this->filetype)
                ->first();

            if(isset($bookmark) && !empty($bookmark)) {
                Notification::make()
                    ->title("File {$this->name} is already in '{$filelist->name}' File List")
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('warning')
                    ->send();
                $this->closefileListContextMenu();
                return;
            }

            $bookmark = Bookmark::create([
                'user_id'     => auth()->user()->id,
                'case_id'     => $this->cid,
                'vault_id'    => $this->vid,
                'dir_id'      => $this->did,
                'filelist_id' => $filelist->id,
                'name'        => $this->name,
                'fullpath'    => $this->fullpath,
                'filetype'    => $this->filetype,
                'icon'        => $this->icon,
            ]);

            if(!isset($bookmark) || empty($bookmark)) {
                Notification::make()
                    ->title("Could not add the file to File List '{$filelist->name}'")
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->body('Please try with again.')
                    ->send();
                $this->closefileListContextMenu();
                return;
            }

            Notification::make()
                ->title("File added to File List {$this->newFileList}")
                ->icon('phosphor-bell-ringing-duotone')
                ->iconColor('primary')
                ->send();

            $this->newFileList = "";
            $this->oldFileList = "";

            $this->closefileListContextMenu();
        }

        public function closefileListContextMenu()  {
            $this->dispatch('close-modal', id: 'fileListContextMenu');
        }

        public function schema(Schema $schema): Schema
        {
            $filelists = FileList::where('user_id', auth()->user()->id)
                ->where('vault_id', $this->vid)
                ->get()
                ->toArray();

            return $schema
                ->components([
                     Section::make('Select a File List')
                    ->description('Add file to an existing File List')
                    ->visible(fn (): bool => !empty($filelists))
                    ->schema([
                        Select::make('oldFileList')
                            ->label('Existing File Lists')
                            ->loadingMessage('Loading File Lists...')
                            ->noSearchResultsMessage('No File Lists found.')
                            ->options(FileList::where('user_id', auth()->user()->id)
                                ->where('vault_id', $this->vid)
                                ->orderBy('name')
                                ->pluck('name', 'name')
                            )
                            ->searchable(),
                    ]),
                     Section::make('Create a new a File List')
                    ->description('Add file to a new File List')
                    ->schema([
                        TextInput::make('newFileList')
                            ->label('Name')
                            ->placeholder('Type name')
                            ->type('search')
                            ->alphaDash()
                            ->maxLength(16)
                            ->minLength(3)
                            ->columnSpan(2),
                    ]),
                ]);
        }
    }

?>

<x-app.container>
    @volt('file-list-context-menu')

        <x-filament::modal id="fileListContextMenu" icon="{{ $icon }}" icon-color="primary" alignment="start" width="{{ $modalWidth }}">
            <div class="flex flex-col justify-center items-center gap-8">
                <x-slot name="heading">
                    {{ __('vault.filelist_add_heading', ['name' => $name]) }}
                </x-slot>

                {{-- when the modal opens it trigers the set events and when it closes it triggers the unset event--}}
                <div x-data="{ fileData: null }" x-on:close-modal.window="if ($event.detail.id === 'fileListContextMenu') window.dispatchEvent(new CustomEvent('livewire:unset-filelist-modal-open')); ">

                <div x-data="{ fileData: null }" x-on:open-modal.window="if ($event.detail.id === 'fileListContextMenu') window.dispatchEvent(new CustomEvent('livewire:set-filelist-modal-open')); ">

                    <input type="hidden" name="fileData" x-model="fileData">
                </div>

                <x-slot name="description">
                    {{ __('vault.filelist_description') }}
                </x-slot>

                {{ $this->schema }}

                <div class="flex justify-center items-center gap-8 mt-4">
                    <x-filament::button wire:click="closefileListContextMenu" color="gray" class="w-24">
                        {{ __('vault.filelist_cancel') }}
                    </x-filament::button>

                    <x-filament::button wire:click="addFileToFileList" color="primary" class="w-24">
                        {{ __('vault.filelist_add_button') }}
                    </x-filament::button>

                </div>
            </div>
        </x-filament::modal >

    @endvolt
</x-app.container>
