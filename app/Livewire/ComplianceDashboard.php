<?php

namespace App\Livewire;

use App\Models\AnalysisRun;
use App\Models\ComplianceFinding;
use App\Models\SupportCase;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use App\Services\Compliance\ComplianceAnalysisService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\ViewAction;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ComplianceDashboard extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    #[Locked]
    public $vid;

    #[Locked]
    public $did;

    #[Locked]
    public $caseid;

    public ?AnalysisRun $run = null;

    public function mount($vid, $did, $caseid): void
    {
        $this->vid = $vid;
        $this->did = $did;
        $this->caseid = $caseid;
        $this->run = AnalysisRun::latestForCase($this->caseid)->first();
    }

    public function render()
    {
        return view('livewire.compliance-dashboard');
    }

    /** @return array<int, array{ruleset: string, label: string, score: ?float, color: string}> */
    public function scores(): array
    {
        $labels = [
            'cis' => __('compliance.ruleset_cis'),
            'stig' => __('compliance.ruleset_stig'),
            'docker_bench' => __('compliance.ruleset_docker_bench'),
            'exposure' => __('compliance.ruleset_exposure'),
        ];

        $summary = $this->run?->summary ?? [];

        $scores = [];
        foreach ($labels as $ruleset => $label) {
            $score = $summary[$ruleset]['score'] ?? null;
            $scores[] = [
                'ruleset' => $ruleset,
                'label' => $label,
                'score' => $score,
                'color' => $this->scoreColor($score),
            ];
        }

        return $scores;
    }

    private function scoreColor(?float $score): string
    {
        return match (true) {
            $score === null => 'gray',
            $score >= 90 => 'success',
            $score >= 70 => 'warning',
            $score >= 50 => 'orange',
            default => 'danger',
        };
    }

    // Synchronous, on-demand run — used only for the "run analysis now" empty
    // state. The automatic path (ComplianceAnalysisRequested) is queued so it
    // never blocks the upload request, but a queued dispatch here would leave
    // the user staring at an empty table with no immediate feedback.
    public function runAnalysisNow(): void
    {
        $case = SupportCase::find($this->caseid);

        if (! $case) {
            Notification::make()
                ->title(__('compliance.run_now_case_not_found'))
                ->danger()
                ->send();

            return;
        }

        $vtools = new VaultTools(auth()->user(), $this->vid);
        $dtools = new DataTools($vtools, $this->vid, $this->did);

        $this->run = app(ComplianceAnalysisService::class)->analyzeCase($case, $dtools, $vtools, 'manual');

        $this->resetTable();

        Notification::make()
            ->title(__('compliance.run_now_success'))
            ->success()
            ->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ComplianceFinding::query()->where('analysis_run_id', $this->run?->id ?? 0))
            ->emptyStateHeading(__('compliance.table_empty_heading'))
            ->emptyStateDescription(__('compliance.table_empty_description'))
            ->emptyStateIcon('phosphor-shield-chevron-duotone')
            ->headerActions([
                Action::make('runAnalysisNow')
                    ->label(__('compliance.action_run_now'))
                    ->icon('phosphor-play-circle-duotone')
                    ->visible(fn () => ! $this->run)
                    ->action(fn () => $this->runAnalysisNow()),
            ])
            ->columns([
                TextColumn::make('rule_id')->label(__('compliance.column_rule_id'))->searchable()->sortable(),
                TextColumn::make('title')->label(__('compliance.column_title'))->searchable()->wrap(),
                TextColumn::make('category')->label(__('compliance.column_category'))->badge()->color('gray'),
                TextColumn::make('severity')
                    ->label(__('compliance.column_severity'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'INFO' => 'gray',
                        'LOW' => 'info',
                        'MEDIUM' => 'warning',
                        'HIGH' => 'orange',
                        default => 'danger',
                    }),
                IconColumn::make('status')
                    ->label(__('compliance.column_status'))
                    ->icon(fn (string $state) => match ($state) {
                        'pass' => Heroicon::OutlinedCheckCircle,
                        'fail' => Heroicon::OutlinedXCircle,
                        'not_applicable' => Heroicon::OutlinedMinusCircle,
                        default => Heroicon::OutlinedExclamationTriangle,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'pass' => 'success',
                        'fail' => 'danger',
                        'not_applicable' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('matched_path')->label(__('compliance.column_matched_path'))->searchable()->limit(60),
            ])
            ->filters([
                SelectFilter::make('ruleset')
                    ->label(__('compliance.filter_ruleset'))
                    ->options([
                        'cis' => __('compliance.ruleset_cis'),
                        'stig' => __('compliance.ruleset_stig'),
                        'docker_bench' => __('compliance.ruleset_docker_bench'),
                        'exposure' => __('compliance.ruleset_exposure'),
                    ]),
                SelectFilter::make('severity')
                    ->label(__('compliance.filter_severity'))
                    ->options(collect(ComplianceFinding::SEVERITIES)->mapWithKeys(fn ($s) => [$s => __("compliance.severity_{$s}")])),
                SelectFilter::make('status')
                    ->label(__('compliance.filter_status'))
                    ->options(collect(ComplianceFinding::STATUSES)->mapWithKeys(fn ($s) => [$s => __("compliance.status_{$s}")])),
            ])
            ->recordActions([
                ViewAction::make()
                    ->modalWidth('3xl')
                    ->modalHeading(fn (ComplianceFinding $record) => $record->title)
                    ->schema(fn (ComplianceFinding $record): array => [
                        TextEntry::make('description')
                            ->label(__('compliance.field_description'))
                            ->default('—')
                            ->columnSpanFull(),
                        TextEntry::make('remediation')
                            ->label(__('compliance.field_remediation'))
                            ->default('—')
                            ->columnSpanFull(),
                        TextEntry::make('reference_url')
                            ->label(__('compliance.field_reference_url'))
                            ->url(fn (ComplianceFinding $record) => $record->reference_url)
                            ->openUrlInNewTab()
                            ->default('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
