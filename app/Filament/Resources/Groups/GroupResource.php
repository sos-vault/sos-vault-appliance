<?php

namespace App\Filament\Resources\Groups;

use App\Filament\Resources\Groups\Pages\CreateGroup;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Models\Group;
use App\Models\LocalLicense;
use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Wave\Setting;

class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-users-three-duotone';

    protected static ?int $navigationSort = 2;

    /**
     * Open-core gate: groups exist only on SaaS or on a licensed appliance.
     * Unlicensed appliance hides the entire Groups CRUD — the single-admin
     * baseline has no team-vault concept.
     */
    public static function canAccess(): bool
    {
        return isSaas() || applianceLicensed();
    }

    public static function form(Schema $schema): Schema
    {
        $components = [
            TextInput::make('name')
                ->required()
                ->maxLength(191),
        ];

        if (! isAppliance()) {
            // SaaS form: keeps the manager + plan selectors.
            $components[] = Select::make('owner_id')
                ->label('Manager')
                ->relationship('owner', 'name')
                ->searchable()
                ->preload()
                ->required();
            $components[] = Select::make('plan_id')
                ->label('Plan')
                ->relationship('plan', 'name')
                ->searchable()
                ->preload();
        } else {
            // Appliance form: vault size is set at creation, manager / plan are absent.
            $components[] = TextInput::make('vault_size_mb')
                ->label('Vault Size (MB)')
                ->numeric()
                ->minValue(256)
                ->default(fn () => (int) (Setting::where('key', 'appliance.default_vault_size_mb')->value('value') ?: 500))
                ->required()
                ->helperText('The encrypted disk image that will be provisioned for this group. Members of the group share this single vault.')
                ->visibleOn('create')
                ->dehydrated(false);
        }

        $components[] = TextInput::make('max_members')
            ->label('Max Members')
            ->numeric()
            ->minValue(2)
            ->maxValue(fn (?Group $record) => isAppliance()
                ? max(2, self::seatsAvailableForGroup($record))
                : 1000)
            ->default(fn (?Group $record) => isAppliance()
                ? max(2, self::seatsAvailableForGroup($record))
                : 8)
            ->required()
            ->helperText(function (?Group $record): ?string {
                if (! isAppliance()) {
                    return null;
                }
                $seats = (int) (LocalLicense::current()?->seats ?? 0);
                $allocatedElsewhere = self::seatsAllocatedToOtherGroups($record);
                $available = max(0, $seats - $allocatedElsewhere);

                return "License seats: {$seats}. Already reserved by other groups: {$allocatedElsewhere}. Available for this group: {$available}.";
            });

        return $schema->components($components);
    }

    /**
     * Sum of max_members across every appliance group EXCEPT the one being
     * edited (so editing a group doesn't double-count its own current cap).
     * Used by the Max Members field to keep the per-group ceiling honest
     * against the license seat total.
     */
    public static function seatsAllocatedToOtherGroups(?Group $record): int
    {
        return (int) Group::query()
            ->when($record?->id, fn ($q) => $q->where('id', '!=', $record->id))
            ->sum('max_members');
    }

    /**
     * Seats still available to allocate to this group: license seats minus
     * what other groups have already claimed via their max_members.
     */
    public static function seatsAvailableForGroup(?Group $record): int
    {
        $seats = (int) (LocalLicense::current()?->seats ?? 0);

        return max(0, $seats - self::seatsAllocatedToOtherGroups($record));
    }

    public static function table(Table $table): Table
    {
        $columns = [
            TextColumn::make('name')
                ->searchable()
                ->sortable(),
        ];

        if (! isAppliance()) {
            $columns[] = TextColumn::make('owner.name')
                ->label('Manager')
                ->searchable()
                ->sortable();
            $columns[] = TextColumn::make('plan.name')
                ->label('Plan')
                ->badge()
                ->placeholder('—');
        } else {
            $columns[] = TextColumn::make('vault_size')
                ->label('Vault Size')
                ->getStateUsing(fn (Group $record) => $record->vault
                    ? number_format($record->vault->plan_size).' MB'
                    : '—');
        }

        $columns[] = TextColumn::make('members_count')
            ->label('Members')
            ->suffix(fn (Group $record) => isAppliance()
                ? ' / '.$record->max_members
                : ' / '.($record->max_members - 1))
            ->sortable();

        $columns[] = TextColumn::make('vault_status')
            ->label('Vault')
            ->badge()
            ->getStateUsing(fn (Group $record) => $record->vault
                ? ucfirst(strtolower($record->vault->status))
                : 'None')
            ->color(fn (string $state) => match ($state) {
                'Open' => 'success',
                'Closed' => 'warning',
                default => 'gray',
            });

        $columns[] = TextColumn::make('created_at')
            ->label('Created')
            ->date('Y-m-d')
            ->sortable();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('members')->with(['owner', 'plan', 'vault']))
            ->columns($columns)
            ->recordActions([
                ActionGroup::make(self::buildRecordActions()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function (Collection $records): void {
                            foreach ($records as $record) {
                                if (isAppliance()) {
                                    // Appliance: destroy the vault first, then
                                    // delete every member account. Inline rather
                                    // than calling detachAndCleanup() — that
                                    // would null out group_id before the delete
                                    // and the User::where below would miss
                                    // everyone (same gotcha as the single-record
                                    // appliance Delete action).
                                    if ($record->vault) {
                                        VaultTools::destroyGroupVault($record->vault);
                                        $record->update(['vault_id' => null]);
                                    }
                                    User::where('group_id', $record->id)->delete();
                                } else {
                                    // SaaS: preserve legacy semantics — delete
                                    // every member account except the owner, and
                                    // detach the owner so the row delete cascades
                                    // cleanly.
                                    User::where('group_id', $record->id)
                                        ->where('id', '!=', $record->owner_id)
                                        ->delete();
                                    User::where('id', $record->owner_id)->update(['group_id' => null]);
                                }
                            }
                        }),
                ]),
            ]);
    }

    private static function buildRecordActions(): array
    {
        $actions = [
            EditAction::make(),

            Action::make('addMember')
                ->label('Add Member')
                ->icon('phosphor-user-plus-duotone')
                ->color('success')
                ->visible(fn (Group $record) => ! self::isGroupFull($record))
                ->schema([
                    Select::make('user_id')
                        ->label('User')
                        ->options(fn (Group $record) => self::eligibleNewMembers($record)->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (Group $record, array $data): void {
                    User::where('id', $data['user_id'])->update(['group_id' => $record->id]);
                    Notification::make()->success()->title('Member added')->send();
                }),

            Action::make('viewMembers')
                ->label('View Members')
                ->icon('phosphor-users-duotone')
                ->color('info')
                ->modalHeading(fn (Group $record) => 'Members — '.$record->name)
                ->modalContent(fn (Group $record) => view('filament.group-members', [
                    'members' => $record->members()->with('vault')->get(),
                    'owner_id' => $record->owner_id,
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),

            Action::make('removeMember')
                ->label('Remove Member')
                ->icon('phosphor-user-minus-duotone')
                ->color('danger')
                ->visible(fn (Group $record) => $record->members()
                    ->when(! isAppliance(), fn ($q) => $q->where('id', '!=', $record->owner_id))
                    ->exists())
                ->schema([
                    Select::make('user_id')
                        ->label('Member to remove')
                        ->options(fn (Group $record) => $record->members()
                            ->when(! isAppliance(), fn ($q) => $q->where('id', '!=', $record->owner_id))
                            ->pluck('name', 'id'))
                        ->required(),
                ])
                ->action(function (Group $record, array $data): void {
                    User::where('id', $data['user_id'])
                        ->where('group_id', $record->id)
                        ->update(['group_id' => null]);
                    Notification::make()->success()->title('Member removed from group')->send();
                }),
        ];

        if (! isAppliance()) {
            $actions[] = Action::make('transferManager')
                ->label('Transfer Manager')
                ->icon('phosphor-crown-duotone')
                ->color('warning')
                ->visible(fn (Group $record) => $record->members()->where('id', '!=', $record->owner_id)->exists())
                ->schema([
                    Select::make('new_owner_id')
                        ->label('New Manager')
                        ->options(fn (Group $record) => $record->members()
                            ->where('id', '!=', $record->owner_id)
                            ->pluck('name', 'id'))
                        ->required(),
                ])
                ->action(function (Group $record, array $data): void {
                    $record->update(['owner_id' => $data['new_owner_id']]);
                    Notification::make()->success()->title('Manager transferred')->send();
                })
                ->requiresConfirmation();
        }

        if (isAppliance()) {
            $actions = array_merge($actions, self::applianceVaultActions());
        }

        $actions[] = Action::make('dissolve')
            ->label('Dissolve Group')
            ->icon('phosphor-warning-duotone')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Dissolve Group')
            ->modalDescription(fn () => isAppliance()
                ? 'Detaches all members and DESTROYS the group vault. Member accounts remain but lose vault access until reassigned.'
                : 'All members will be detached from this group. Their user accounts will NOT be deleted. The vault will remain intact.')
            ->action(function (Group $record): void {
                $vaultId = (int) ($record->vault_id ?? 0);
                self::detachAndCleanup($record);
                $payload = (object) ['name' => $record->name, 'message' => 'group dissolved'];
                addEvent($payload, 'DEL_GROUP', 'SUCCESS', 'ACTIVITY', 0, $vaultId, auth()->id() ?? 0, $record->id);
                $record->delete();
                Notification::make()->success()->title('Group dissolved')->send();
            });

        $actions[] = DeleteAction::make()
            ->label('Delete Group & Members')
            ->requiresConfirmation()
            ->modalHeading('Delete Group and Members')
            ->modalDescription(fn () => isAppliance()
                ? 'Permanently deletes the group, the group vault, AND all member user accounts.'
                : 'This will permanently delete the group AND all member user accounts. The group vault will NOT be destroyed — use the Users resource vault actions if needed.')
            ->before(function (Group $record): void {
                $vaultId = (int) ($record->vault_id ?? 0);
                if (isAppliance()) {
                    // Destroy the vault first, then delete the member accounts
                    // (do NOT pre-detach — that would zero out group_id and
                    // the delete-by-group_id below would miss everyone).
                    if ($record->vault) {
                        VaultTools::destroyGroupVault($record->vault);
                        $record->update(['vault_id' => null]);
                    }
                    User::where('group_id', $record->id)->delete();
                } else {
                    User::where('group_id', $record->id)
                        ->where('id', '!=', $record->owner_id)
                        ->delete();
                    User::where('id', $record->owner_id)->update(['group_id' => null]);
                }
                $payload = (object) ['name' => $record->name, 'message' => 'group deleted'];
                addEvent($payload, 'DEL_GROUP', 'SUCCESS', 'ACTIVITY', 0, $vaultId, auth()->id() ?? 0, $record->id);
            });

        return $actions;
    }

    private static function applianceVaultActions(): array
    {
        return [
            Action::make('openVault')
                ->label('Open Vault')
                ->icon('phosphor-lock-open-duotone')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (Group $record) => $record->vault && strtoupper($record->vault->status) !== 'OPEN')
                ->action(function (Group $record): void {
                    $vtools = new VaultTools($record);
                    if ($vtools->openVault()) {
                        Notification::make()->success()->title('Vault opened')->send();
                    } else {
                        Notification::make()->danger()->title('Failed to open vault')->send();
                    }
                }),

            Action::make('closeVault')
                ->label('Close Vault')
                ->icon('phosphor-lock-duotone')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (Group $record) => $record->vault && strtoupper($record->vault->status) === 'OPEN')
                ->action(function (Group $record): void {
                    $vtools = new VaultTools($record);
                    if ($vtools->closeVault(0, true)) {
                        Notification::make()->success()->title('Vault closed')->send();
                    } else {
                        Notification::make()->danger()->title('Failed to close vault')->send();
                    }
                }),

            Action::make('expandVault')
                ->label('Expand Vault')
                ->icon('phosphor-arrows-out-duotone')
                ->color('info')
                ->visible(fn (Group $record) => $record->vault !== null)
                ->schema([
                    TextInput::make('increment')
                        ->label('Size to add (MB)')
                        ->numeric()
                        ->minValue(1)
                        ->required(),
                ])
                ->action(function (Group $record, array $data): void {
                    $vtools = new VaultTools($record);
                    $payload = (object) [
                        'description' => 'Appliance group vault',
                        'plan_id' => 0,
                        'message' => '',
                        'group' => $record->name,
                    ];
                    if ($vtools->expandVault((int) $data['increment'], $payload)) {
                        Notification::make()->success()->title('Vault expanded by '.$data['increment'].' MB')->send();
                    } else {
                        Notification::make()->danger()->title('Failed to expand vault')->send();
                    }
                }),

            Action::make('shrinkVault')
                ->label('Shrink Vault')
                ->icon('phosphor-arrows-in-duotone')
                ->color('warning')
                ->visible(fn (Group $record) => $record->vault !== null)
                ->schema([
                    TextInput::make('decrement')
                        ->label('Size to remove (MB)')
                        ->numeric()
                        ->minValue(1)
                        ->required(),
                ])
                ->action(function (Group $record, array $data): void {
                    $vtools = new VaultTools($record);
                    $payload = (object) [
                        'description' => 'Appliance group vault',
                        'plan_id' => 0,
                        'message' => '',
                        'group' => $record->name,
                    ];
                    if ($vtools->shrinkVault((int) $data['decrement'], $payload)) {
                        Notification::make()->success()->title('Vault shrunk by '.$data['decrement'].' MB')->send();
                    } else {
                        Notification::make()->danger()->title('Failed to shrink vault')->send();
                    }
                }),

            Action::make('viewVault')
                ->label('View Vault')
                ->icon('phosphor-vault-duotone')
                ->color('info')
                ->modalHeading(fn (Group $record) => 'Vault — '.$record->name)
                ->modalContent(fn (Group $record) => self::buildVaultModalContent($record))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->visible(fn (Group $record) => $record->vault !== null),

            Action::make('destroyVault')
                ->label('Delete Vault')
                ->icon('phosphor-trash-duotone')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Delete Vault')
                ->modalDescription('Permanently destroys the group vault and all its contents. The group itself stays in place — admin can re-provision a new vault for it.')
                ->visible(fn (Group $record) => $record->vault !== null)
                ->action(function (Group $record): void {
                    if (VaultTools::destroyGroupVault($record->vault)) {
                        $record->update(['vault_id' => null]);
                        Notification::make()->success()->title('Vault deleted')->send();
                    } else {
                        Notification::make()->danger()->title('Failed to delete vault')->send();
                    }
                }),
        ];
    }

    /**
     * Detach all members and destroy the group's vault. Used by dissolve /
     * delete actions on appliance. SaaS path keeps the vault around so it
     * can be reattached if the manager is later restored to a new team.
     */
    private static function detachAndCleanup(Group $record): void
    {
        if (isAppliance() && $record->vault) {
            VaultTools::destroyGroupVault($record->vault);
            $record->update(['vault_id' => null]);
        }
        User::where('group_id', $record->id)->update(['group_id' => null]);
    }

    private static function isGroupFull(Group $record): bool
    {
        return isAppliance()
            ? $record->members()->count() >= (int) $record->max_members
            : $record->isFull();
    }

    private static function eligibleNewMembers(Group $record): Builder
    {
        $query = User::query()->whereNull('group_id');
        if (isAppliance()) {
            // Appliance: only Team Member role can be added to a group;
            // the admin account never joins a group.
            $query->whereHas('roles', fn ($q) => $q->where('name', 'Team Member'));
        } else {
            $query->where('id', '!=', $record->owner_id);
        }

        return $query;
    }

    public static function buildVaultModalContent(Group $record): View
    {
        $vault = $record->vault;
        $vtools = new VaultTools($record);

        $dfUsage = [];
        $isLuksOpen = $vault ? $vtools->isOpen(soft: true) : false;
        $isMounted = $vault ? $vtools->isMounted() : false;
        $device = $vtools->device ?? null;
        $imageSize = ($device && file_exists($device)) ? filesize($device) : null;

        if ($vault && strtoupper($vault->status) === 'OPEN' && $isMounted) {
            $dfUsage = $vtools->vaultUsage();
        }

        return view('filament.vault-details', [
            'vault' => $vault,
            'dfUsage' => $dfUsage,
            'isLuksOpen' => $isLuksOpen,
            'device' => $device,
            'mountPoint' => null,
            'imageSize' => $imageSize,
        ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGroups::route('/'),
            'create' => CreateGroup::route('/create'),
            'edit' => EditGroup::route('/{record}/edit'),
        ];
    }
}
