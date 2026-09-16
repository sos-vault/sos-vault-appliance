<?php
use App\Models\ApiKey;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;

middleware('auth');
name('settings.assistant');

new class extends Component implements HasActions, HasForms, Tables\Contracts\HasTable
{
    use InteractsWithActions;
    use InteractsWithForms, Tables\Concerns\InteractsWithTable;

    // variables for (b)rowing keys
    public $keys = [];

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
        $this->refreshKeys();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->label(__('settings.assistant_key_label'))
                    ->required(),
            ])
            ->statePath('data');
    }

    public function add()
    {

        $state = $this->form->getState();
        $this->validate();

        $apiKey = auth()->user()->createApiKey(Str::slug($state['key']));

        Notification::make()
            ->title(__('settings.assistant_create_success'))
            ->success()
            ->send();

        $this->form->fill();

        $this->refreshKeys();
    }

    public function table(Table $table): Table
    {
        return $table->query(ApiKey::query()->where('user_id', auth()->user()->id))
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('created_at')->label(__('settings.assistant_table_created')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->slideOver()
                    ->modalWidth('md')
                    ->schema([
                        TextInput::make('name'),
                        TextInput::make('key'),
                        // ...
                    ]),
                EditAction::make()
                    ->slideOver()
                    ->modalWidth('md')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        // ...
                    ]),
                DeleteAction::make(),
            ]);
    }

    public function refreshKeys()
    {
        $this->keys = auth()->user()->apiKeys;
    }
}

?>

<x-layouts.app>
    @volt('settings.assistant')
        <div class="relative">
            <x-app.settings-layout
                :title="__('settings.assistant_title')"
                :description="__('settings.assistant_description')"
            >
                <div class="flex flex-col">
                    Sorry but there is no assistant support yet.
                </div>
            </x-app.settings-layout>
        </div>
    @endvolt
</x-layouts.app>
