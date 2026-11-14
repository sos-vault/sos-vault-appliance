<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use App\Models\Sysevent;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\HtmlString;

class ActivityReportBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'activity';
    }

    public static function getLabel(): string
    {
        return 'Activity Report Table';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalDescription('Configure the user activity report table')
            ->schema([
                Placeholder::make('save_notice')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<div class="rounded-lg bg-warning-50 border border-warning-300 text-warning-800 dark:bg-warning-950 dark:border-warning-700 dark:text-warning-200 px-4 py-3 text-sm font-medium">'
                        .'⚠️ Save the report after inserting this block to see results in the View tab.'
                        .'</div>'
                    )),
                TextInput::make('heading')
                    ->default('Activity Report')
                    ->label('Table title')
                    ->required(),
                TextInput::make('subheading')
                    ->default('User activity log')
                    ->label('Description'),
                TextInput::make('email')
                    ->label('User email')
                    ->email()
                    ->required(),
                Select::make('timeframe')
                    ->label('Time frame')
                    ->required()
                    ->default('this_month')
                    ->options([
                        'today' => 'Today',
                        'this_week' => 'This Week',
                        'this_month' => 'This Month',
                        'last_month' => 'Last Month',
                        'this_year' => 'This Year',
                    ]),
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
        $user = User::where('email', $config['email'] ?? '')->first();

        if (! $user) {
            return '';
        }

        [$start, $end] = match ($config['timeframe'] ?? 'this_month') {
            'today' => [Carbon::today(), Carbon::now()],
            'this_week' => [Carbon::now()->startOfWeek(), Carbon::now()],
            'this_month' => [Carbon::now()->startOfMonth(), Carbon::now()],
            'last_month' => [Carbon::now()->subMonth()->startOfMonth(), Carbon::now()->subMonth()->endOfMonth()],
            'this_year' => [Carbon::now()->startOfYear(), Carbon::now()],
            default => [Carbon::now()->startOfMonth(), Carbon::now()],
        };

        $events = Sysevent::where('owner', $user->id)
            ->when(! empty($data['cid']), fn ($q) => $q->where('case_id', $data['cid']))
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at', 'desc')
            ->get();

        $records = $events->map(function (Sysevent $event) use ($user): array {
            $payload = $event->payload ? json_decode($event->payload) : null;

            return [
                'date' => $event->created_at->toDateString(),
                'time' => $event->created_at->toTimeString(),
                'username' => $user->name,
                'type' => $event->type ?? '',
                'status' => $event->status ?? '',
                'filename' => $payload->name ?? '',
                'message' => $payload->message ?? '',
            ];
        })->all();

        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.generic.index', [
            'heading' => $config['heading'] ?? '',
            'subheading' => $config['subheading'] ?? '',
            'records' => $records,
            'headers' => ['Date', 'Time', 'User', 'Event', 'Status', 'File', 'Description'],
            'orders' => ['date', 'time', 'username', 'type', 'status', 'filename', 'message'],
        ])->render();
    }
}
