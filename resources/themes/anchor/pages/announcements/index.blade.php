<?php
    use App\Models\Announcement;

    use Filament\Forms\Form;
    use Filament\Forms\Concerns\InteractsWithForms;
    use Filament\Forms\Contracts\HasForms;

    use Filament\Tables\Table;
    use Filament\Tables\Concerns\InteractsWithTable;
    use Filament\Tables\Contracts\HasTable;
    use Filament\Tables\Columns\TextColumn;
    use Filament\Tables\Columns\ImageColumn;

    use Filament\Actions\Action;
    use Filament\Actions\Concerns\InteractsWithActions;
    use Filament\Actions\Contracts\HasActions;
    use Filament\Tables\Actions\ActionGroup;

    use Filament\Tables\Columns\Layout\Split;

    use Livewire\Volt\Component;
    use function Laravel\Folio\{middleware, name};
    use Carbon\Carbon;

    use Illuminate\Support\HtmlString;

    middleware('auth');
    name('announcements');

    new class extends Component implements HasForms, HasTable, HasActions
    {
        use InteractsWithActions;
        use InteractsWithTable;
        use InteractsWithForms;

        public ?array $data = [];

		public $announcements_count;

		public function addToReaded($id){
            $user = auth()->user();
            Announcement::whereDoesntHave('users', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })->get()
            ->pluck('id')
            ->tap(function ($missingAnnouncements) use ($user) {
                $user->announcements()->attach($missingAnnouncements->toArray());
            });

            $this->dispatch('refreshComponents');
        }

        public function table(Table $table): Table
        {
            return $table
                ->query(Announcement::query()->whereDoesntHave('users', function ($query) {
                    $query->where('users.id', auth()->id());
                })
                ->latest('created_at'))
                ->emptyStateHeading(__('notifications.ann_empty_heading'))
                ->emptyStateDescription(__('notifications.ann_empty_description'))
                ->emptyStateIcon('phosphor-empty-duotone')
                ->paginated(false)
                ->columns([
                    Split::make([
                        TextColumn::make('title')
                            ->url(fn ($record) => url("/announcements/{$record->id}"))
                            ->description(fn ($record): string => $record->description)
                            ->openUrlInNewTab(false) // set to true if you want it to open in a new tab
                            ->color('primary')
                            ->grow(true)
                            ->weight('bold'),
                        TextColumn::make('created_at')
                            ->grow(false)
                            ->dateTime('F, jS h:i A'),
                    ])
                    ->visibleFrom('md'),
                ])
                ->recordActions([
                    Action::make('read')
                        ->label(__('notifications.page_mark_as_read'))
                        ->icon('phosphor-check-duotone')
                        ->button()
                        ->outlined()
                        ->action(function ($record) {
                            $this->addToReaded($record['id']);
                        })
                ])
                ->defaultSort('created_at', 'desc');
        }
    }
?>

<x-layouts.app>
    @volt('announcements')
        <x-app.container>
            <x-filament::section
                :description="__('notifications.ann_description')"
                :heading="__('notifications.ann_heading')"
                icon="phosphor-megaphone-duotone" icon-color="primary" icon-size="lg"
                :contained="false" >

                <div class="overflow-x-auto border rounded-lg">
                    {{ $this->table }}
                </div>

            </x-filament::section>
        </x-app.container>
    @endvolt
</x-layouts.app>

