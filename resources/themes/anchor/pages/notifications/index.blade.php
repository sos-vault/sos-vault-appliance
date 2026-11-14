<?php

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

    use Illuminate\Support\Facades\Log;

    middleware('auth');
    name('notifications');

    new class extends Component implements HasForms, HasTable, HasActions
    {
        use InteractsWithActions;
        use InteractsWithTable;
        use InteractsWithForms;

        public ?array $data = [];

		public $notifications_count;

		public function mount(){
			$this->updateNotifications();
		}

		public function delete($id){
			$notification = auth()->user()->notifications()->where('id', $id)->first();
			if ($notification){
				$notification->delete();
			}
			$this->updateNotifications();
            $this->dispatch('refreshComponents');
		}

		public function updateNotifications(){
			$unreadNotifications = auth()->user()->unreadNotifications->all();

            $this->data = [];
            foreach($unreadNotifications as $notification) {
                if(isset($notification)) {
                    $record = [];

                    if(isset($notification['data']) && !empty($notification['data'])) {
                        $record = [
                          'id'              => $notification['id'],
                          'type'            => $notification['type'],
                          'notifiable_type' => $notification['notifiable_type'],
                          'notifiable_id'   => $notification['notifiable_id'],
                          'read_at'         => isset($notification['read_at']) ? Carbon::parse($notification['read_at'])->format('Y-m-d H:i:s') : "",
                          'created_at'      => Carbon::parse($notification['created_at'])->format('Y-m-d H:i:s'),
                          'updated_at'      => Carbon::parse($notification['updated_at'])->format('Y-m-d H:i:s'),
                          'icon'            => isset($notification['data']['icon']) ? preg_replace("|/*storage/|", "", $notification['data']['icon']) : 'avatars/default.png',
                          'status'          => $notification['data']['status'] ?? "success",
                          'message'         => $notification['data']['body'] ?? ($notification['data']['message'] ?? ""),
                          'link'            => $notification['data']['link'] ?? null,
                          'from'            => $notification['data']['user']['name'] ?? config('app.name'),
                        ];
                        $this->data[] = $record;
                    }
                }
            }

			$this->notifications_count = count($this->data);
        }

        public function table(Table $table): Table
        {
            return $table
                ->records(fn (): array => $this->data)
                ->emptyStateHeading(__('notifications.page_empty_heading'))
                ->emptyStateDescription(__('notifications.page_empty_description'))
                ->emptyStateIcon('phosphor-empty-duotone')
                ->columns([
                    Split::make([
                        TextColumn::make('status')
                            ->badge()
                            ->grow(false)
                            ->color(fn ($state): string => $state),
                        ImageColumn::make('icon')
                            ->imageSize(40)
                            ->disk('public')
                            ->circular()
                            ->grow(false)
                            ->defaultImageUrl(url('/storage/avatars/default.png')),
                        TextColumn::make('from')
                            ->formatStateUsing(fn ($state): string => __('notifications.page_from', ['name' => $state]))
                            ->color('primary')
                            ->description(fn ($record): string => $record['message'])
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
                            $this->delete($record['id']);
                        })
                ])
                ->defaultSort('created_at', 'desc');
        }
    }
?>

<x-layouts.app>
	@volt('notifications')
		<x-app.container>
            @script
            <script>
                $wire.on('refreshComponents', () => {
                    $wire.$refresh();
                });
            </script>
            @endscript

            <x-filament::section
                :description="__('notifications.page_description')"
                :heading="__('notifications.page_heading')"
                icon="phosphor-bell-duotone" icon-color="primary" icon-size="lg"
                :contained="false" >

                <div class="overflow-x-auto border rounded-lg">
                    {{ $this->table }}
                </div>

            </x-filament::section>
		</x-app.container>
	@endvolt

</x-layouts.app>
