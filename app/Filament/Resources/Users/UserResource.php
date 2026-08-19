<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Group;
use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use App\Services\AccountSuspensionService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Wave\Plan;
use Wave\Setting;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-users-duotone';

    protected static ?int $navigationSort = 1;

    /**
     * Open-core gate for write operations on user accounts.
     *
     * User management (create / edit / delete / suspend) requires SaaS or an
     * actively-licensed appliance. On an unlicensed appliance (fresh
     * single-admin baseline) OR an appliance whose license has EXPIRED, the
     * Users list stays visible (read-only) but no row can be created, edited,
     * changed, or deleted — existing accounts remain listed so the admin can
     * see who comes back once the license is renewed. LocalLicense::current()
     * already excludes expired rows, so applianceLicensed() is false in both
     * the never-licensed and the expired states.
     *
     * The single-admin baseline still changes its own password / profile via
     * the Wave /settings/profile page, which is independent of this resource.
     */
    public static function canManageUsers(): bool
    {
        return isSaas() || applianceLicensed();
    }

    public static function canCreate(): bool
    {
        return self::canManageUsers();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canManageUsers();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canManageUsers();
    }

    public static function canDeleteAny(): bool
    {
        return self::canManageUsers();
    }

    /**
     * Whether any account other than the admin(s) exists. Used to decide
     * whether a read-only Users list is worth showing on an unlicensed /
     * expired appliance: a freshly installed single-admin baseline has nothing
     * to display, so the resource (and its nav item) is hidden entirely.
     */
    protected static function hasNonAdminUsers(): bool
    {
        return User::whereDoesntHave('roles', fn (Builder $query) => $query->where('name', 'admin'))->exists();
    }

    /**
     * Show the resource when users are manageable (SaaS / licensed appliance),
     * OR — on an unlicensed/expired appliance — when there are existing
     * non-admin accounts to list read-only, OR for the appliance admin itself.
     *
     * The admin always needs this page even on a fresh single-admin appliance:
     * it hosts the per-row vault actions (Expand / Shrink / Open / Close), which
     * are the admin's only way to resize their own LUKS vault — the SaaS
     * disk-plans upsell on the dashboard is hidden on the appliance. Filament
     * uses canViewAny() for both page access and navigation registration.
     */
    public static function canViewAny(): bool
    {
        return self::canManageUsers()
            || self::hasNonAdminUsers()
            || (isAppliance() && (bool) auth()->user()?->hasRole('admin'));
    }

    /**
     * Whether the per-user (personal) vault actions should be available for
     * this row. On SaaS every user has a personal vault. On the appliance only
     * the admin has a personal LUKS vault (non-admin members use the shared
     * group vault, managed from the Groups panel), so the actions show solely
     * for admin rows there.
     */
    public static function personalVaultManageable(User $record): bool
    {
        return ! isAppliance() || $record->hasRole('admin');
    }

    public static function form(Schema $schema): Schema
    {
        $components = [
            TextInput::make('name')
                ->required()
                ->maxLength(191),
            TextInput::make('username')
                ->required()
                ->maxLength(191),
            TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(191),
            FileUpload::make('avatar')
                ->nullable()
                ->dehydrated(fn ($state) => filled($state))
                ->image()
                ->disk('public')
                ->directory('avatars')
                ->visibility('public')
                ->maxSize(2048),
            DateTimePicker::make('email_verified_at'),
            TextInput::make('password')
                ->password()
                ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                ->dehydrated(fn ($state) => filled($state))
                ->required(fn (string $context): bool => $context === 'create'),
        ];

        if (isAppliance()) {
            // Appliance: roles are locked to Team Member (admins are seeded
            // by the installer and not created from this form). Group
            // assignment is mandatory — a member with no group has no vault.
            $components[] = Select::make('roles')
                ->multiple()
                ->relationship('roles', 'name', fn (Builder $query) => $query->where('name', 'Team Member'))
                ->preload()
                ->required()
                ->default(fn () => Role::where('name', 'Team Member')->pluck('id')->toArray())
                ->helperText('All appliance team members share the role of "Team Member".');
            $components[] = Select::make('group_id')
                ->label('Group')
                ->relationship('group', 'name')
                ->preload()
                ->searchable()
                ->required()
                ->helperText('Assign this user to a group — they will share the group\'s vault.');
        } else {
            $components[] = Select::make('roles')
                ->multiple()
                ->relationship('roles', 'name')
                ->preload()
                ->searchable()
                ->required();
            $components[] = DateTimePicker::make('trial_ends_at');
            $components[] = TextInput::make('verification_code')
                ->maxLength(191);
            $components[] = Toggle::make('verified');
        }

        return $schema->components($components);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['vault', 'group', 'roles']))
            ->deferColumnManager(false)
            ->recordUrl(fn (User $record) => self::canManageUsers()
                ? EditUser::getUrl(['record' => $record])
                : null)
            ->columns([
                TextColumn::make('id')
                    ->label('UID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                ImageColumn::make('avatar')
                    ->label('')
                    ->circular()
                    ->getStateUsing(fn (User $record) => $record->avatar())
                    ->width(36)
                    ->height(36)
                    ->toggleable(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('username')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('roles.name')
                    ->label('Plan')
                    ->badge()
                    ->color(fn (string $state): string => match (strtolower($state)) {
                        'admin' => 'danger',
                        'enterprise' => 'warning',
                        'team' => 'success',
                        'basic' => 'primary',
                        'minimal' => 'info',
                        'suspended' => 'danger',
                        default => 'gray',   // free, unknown
                    })
                    ->toggleable(),

                TextColumn::make('group.name')
                    ->label('Group')
                    ->badge()
                    ->color('info')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->getStateUsing(fn (User $record) => $record->email_verified_at !== null)
                    ->trueIcon('phosphor-seal-check-duotone')
                    ->falseIcon('phosphor-seal-warning-duotone')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->tooltip(fn (User $record) => $record->email_verified_at
                        ? 'Verified '.Carbon::parse($record->email_verified_at)->format('Y-m-d')
                        : 'Not verified')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('vault_badge')
                    ->label('Vault')
                    ->badge()
                    ->getStateUsing(fn (User $record) => $record->vault ? ucfirst(strtolower($record->vault->status)) : 'Missing')
                    ->color(fn (string $state) => match ($state) {
                        'Open' => 'success',
                        'Closed' => 'warning',
                        default => 'gray',
                    })
                    ->toggleable()
                    ->action(
                        Action::make('viewVault')
                            ->modalHeading(fn (User $record) => 'Vault — '.$record->name)
                            ->modalContent(fn (User $record) => self::buildVaultModalContent($record))
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Close')
                            ->visible(fn (User $record) => $record->vault !== null)
                    ),

                TextColumn::make('created_at')
                    ->label('Registered')
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('trial_ends_at')
                    ->label('Trial Ends')
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_activity')
                    ->label('Last Active')
                    ->getStateUsing(fn (User $record) => $record->last_activity
                        ? Carbon::createFromTimestamp($record->last_activity)->format('Y-m-d')
                        : '—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->label('Plan')
                    ->relationship('roles', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('group')
                    ->label('Group')
                    ->relationship('group', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->visible(fn () => self::canManageUsers()),

                    Action::make('Impersonate')
                        ->icon('phosphor-user-switch-duotone')
                        ->url(fn (User $record) => route('impersonate', $record))
                        ->visible(fn (User $record) => self::canManageUsers() && auth()->user()->id !== $record->id),

                    Action::make('makeManager')
                        ->label('Make Team Manager')
                        ->icon('phosphor-crown-duotone')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading(fn (User $record) => "Transfer management to {$record->name}")
                        ->modalDescription('The current manager will become a regular member. This cannot be undone without another transfer.')
                        ->visible(function (User $record): bool {
                            if (isAppliance()) {
                                return false;
                            }
                            if (! $record->group_id) {
                                return false;
                            }
                            $group = Group::find($record->group_id);

                            return $group && $group->owner_id !== $record->id;
                        })
                        ->action(function (User $record): void {
                            $group = Group::find($record->group_id);
                            if (! $group) {
                                Notification::make()->danger()->title('Group not found')->send();

                                return;
                            }
                            $group->update(['owner_id' => $record->id]);
                            Notification::make()->success()->title("{$record->name} is now the team manager")->send();
                        }),

                    Action::make('openVault')
                        ->label('Open Vault')
                        ->icon('phosphor-lock-open-duotone')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (User $record) => self::personalVaultManageable($record) && $record->vault !== null)
                        ->action(function (User $record): void {
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
                        ->modalDescription(fn (User $record) => $record->vault?->always_open
                            ? 'This vault is pinned open (always_open). Admin will force-close it now; it will remount on next reboot unless you also unpin it.'
                            : null)
                        ->visible(fn (User $record) => self::personalVaultManageable($record) && $record->vault !== null)
                        ->action(function (User $record): void {
                            $vtools = new VaultTools($record);
                            // Admin override: force past always_open so a pinned
                            // vault (e.g. the admin/self-hosted ingest vault) can
                            // still be closed temporarily from the UI.
                            if ($vtools->closeVault(0, true)) {
                                Notification::make()->success()->title('Vault closed')->send();
                            } else {
                                Notification::make()->danger()->title('Failed to close vault')->send();
                            }
                        }),

                    Action::make('pinVault')
                        ->label('Pin Vault Open')
                        ->icon('phosphor-push-pin-duotone')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading(fn (User $record) => "Pin {$record->name}'s vault open")
                        ->modalDescription('The vault will stay mounted after logout and remount automatically after a reboot. Only use this for demo/public vaults.')
                        ->visible(fn (User $record): bool => self::personalVaultManageable($record) && $record->vault !== null && ! $record->vault->always_open)
                        ->action(function (User $record): void {
                            Vault::where('owner', $record->id)->update(['always_open' => true]);
                            Notification::make()->success()->title('Vault pinned open')->body('Will no longer close on logout.')->send();
                        }),

                    Action::make('unpinVault')
                        ->label('Unpin Vault')
                        ->icon('phosphor-push-pin-slash-duotone')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading(fn (User $record) => "Unpin {$record->name}'s vault")
                        ->modalDescription('The vault will close normally on logout from now on.')
                        ->visible(fn (User $record): bool => self::personalVaultManageable($record) && $record->vault !== null && (bool) $record->vault->always_open)
                        ->action(function (User $record): void {
                            Vault::where('owner', $record->id)->update(['always_open' => false]);
                            Notification::make()->success()->title('Vault unpinned')->body('Will close normally on logout.')->send();
                        }),

                    Action::make('createVault')
                        ->label('Create Vault')
                        ->icon('phosphor-plus-circle-duotone')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->visible(fn (User $record) => self::personalVaultManageable($record)
                            && $record->vault === null
                            && (! $record->group_id || $record->group?->owner_id === $record->id)
                        )
                        ->action(function (User $record): void {
                            if (isAppliance()) {
                                // Appliance admin: provision a personal LUKS vault
                                // (independent of any group the admin owns).
                                // Defaults to 10 GB — keep it large (do NOT lower
                                // to the 500 MB group-vault default).
                                $sizeMb = (int) (Setting::get('appliance.default_vault_size_mb') ?: 10240);
                                $ok = VaultTools::createPersonalVault($record, $sizeMb) !== null;
                            } else {
                                $ok = (new VaultTools($record))->createVault();
                            }
                            if ($ok) {
                                Notification::make()->success()->title('Vault created')->send();
                            } else {
                                Notification::make()->danger()->title('Failed to create vault')->send();
                            }
                        }),

                    Action::make('expandVault')
                        ->label('Expand Vault')
                        ->icon('phosphor-arrows-out-duotone')
                        ->color('info')
                        ->visible(fn (User $record) => self::personalVaultManageable($record) && $record->vault !== null)
                        ->schema([
                            TextInput::make('increment')
                                ->label('Size to add (MB)')
                                ->numeric()
                                ->minValue(1)
                                ->required(),
                        ])
                        ->action(function (User $record, array $data): void {
                            $vtools = new VaultTools($record);

                            $plan = Plan::where('role_id', $record->role_id)->first();
                            $payload = (object) [
                                'description' => $plan?->description ?? ($record->role?->name ?? 'unknown'),
                                'plan_id' => $plan?->id ?? 0,
                                'message' => '',
                            ];

                            $adminLocale = App::getLocale();
                            if ($record->locale && array_key_exists($record->locale, config('app.supported_locales', []))) {
                                App::setLocale($record->locale);
                            }

                            if ($vtools->expandVault((int) $data['increment'], $payload)) {
                                Notification::make()->success()->title('Vault expanded by '.$data['increment'].' MB')->send();
                                // Original: 'Your vault was successfully expanded in X MB by the system administrator.'
                                notifyUser($record, __('notifications.admin_vault_expand_success', ['size' => $data['increment']]), 'success');
                            } else {
                                Notification::make()->danger()->title('Failed to expand vault')->send();
                                // Original: 'Your vault was unsuccessfully expanded in X MB by the system administrator.'
                                notifyUser($record, __('notifications.admin_vault_expand_failed', ['size' => $data['increment']]), 'error');
                            }

                            App::setLocale($adminLocale);
                        }),

                    Action::make('shrinkVault')
                        ->label('Shrink Vault')
                        ->icon('phosphor-arrows-in-duotone')
                        ->color('warning')
                        ->visible(fn (User $record) => self::personalVaultManageable($record) && $record->vault !== null)
                        ->schema([
                            TextInput::make('decrement')
                                ->label('Size to remove (MB)')
                                ->numeric()
                                ->minValue(1)
                                ->required(),
                        ])
                        ->action(function (User $record, array $data): void {
                            $vtools = new VaultTools($record);

                            $plan = Plan::where('role_id', $record->role_id)->first();
                            $payload = (object) [
                                'description' => $plan?->description ?? ($record->role?->name ?? 'unknown'),
                                'plan_id' => $plan?->id ?? 0,
                                'message' => '',
                            ];

                            $adminLocale = App::getLocale();
                            if ($record->locale && array_key_exists($record->locale, config('app.supported_locales', []))) {
                                App::setLocale($record->locale);
                            }

                            if ($vtools->shrinkVault((int) $data['decrement'], $payload)) {
                                Notification::make()->success()->title('Vault shrunk by '.$data['decrement'].' MB')->send();
                                // Original: 'Your vault was successfully shrunk in X MB by the system administrator.'
                                notifyUser($record, __('notifications.admin_vault_shrink_success', ['size' => $data['decrement']]), 'success');
                            } else {
                                Notification::make()->danger()->title('Failed to shrink vault')->send();
                                // Original: 'Your vault was unsuccessfully shrunk in X MB by the system administrator.'
                                notifyUser($record, __('notifications.admin_vault_shrink_failed', ['size' => $data['decrement']]), 'error');
                            }

                            App::setLocale($adminLocale);
                        }),

                    Action::make('viewVaultInfo')
                        ->label('View Vault')
                        ->icon('phosphor-vault-duotone')
                        ->color('info')
                        ->modalHeading(fn (User $record) => 'Vault — '.$record->name)
                        ->modalContent(fn (User $record) => self::buildVaultModalContent($record))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close')
                        ->visible(fn (User $record) => self::personalVaultManageable($record) && $record->vault !== null),

                    Action::make('destroyVault')
                        ->label('Delete Vault')
                        ->icon('phosphor-trash-duotone')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete Vault')
                        ->modalDescription('This will permanently destroy the vault and all its contents. This cannot be undone.')
                        ->visible(fn (User $record) => self::personalVaultManageable($record) && $record->vault !== null)
                        ->action(function (User $record): void {
                            $vtools = new VaultTools($record);
                            if ($vtools->destroyVault()) {
                                Notification::make()->success()->title('Vault deleted')->send();
                            } else {
                                Notification::make()->danger()->title('Failed to delete vault')->send();
                            }
                        }),

                    Action::make('suspendAccount')
                        ->label('Suspend Account')
                        ->icon('phosphor-prohibit-duotone')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(fn (User $record) => "Suspend {$record->name}'s account")
                        ->modalDescription('The user will be notified by email. Their vault will be closed and they will only be able to use the support bot.')
                        ->visible(fn (User $record) => self::canManageUsers() && ! $record->hasRole('suspended'))
                        ->action(function (User $record): void {
                            app(AccountSuspensionService::class)->suspend($record, 'admin_action');
                            Notification::make()->warning()->title("Account suspended for {$record->name}")->send();
                        }),

                    Action::make('reactivateAccount')
                        ->label('Reactivate Account')
                        ->icon('phosphor-check-circle-duotone')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading(fn (User $record) => "Reactivate {$record->name}'s account")
                        ->modalDescription('The user will be notified by email. Their plan role will be restored from their active subscription.')
                        ->visible(fn (User $record) => self::canManageUsers() && $record->hasRole('suspended'))
                        ->action(function (User $record): void {
                            app(AccountSuspensionService::class)->reactivate($record, 'admin_action');
                            Notification::make()->success()->title("Account reactivated for {$record->name}")->send();
                        }),

                    DeleteAction::make()->label('Delete User'),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultPaginationPageOption(50)
            ->persistSortInSession();
    }

    public static function buildVaultModalContent(User $record): View
    {
        $vault = $record->vault;
        $vtools = new VaultTools($record);

        $dfUsage = [];
        $isLuksOpen = $vtools->isOpen(soft: true);
        $isMounted = $vtools->isMounted();
        $device = $vtools->device ?? null;
        $mountPoint = $isMounted ? ($vtools->mountp ?? null) : null;
        $imageSize = ($device && file_exists($device)) ? filesize($device) : null;

        if ($vault && strtoupper($vault->status) === 'OPEN' && $isMounted) {
            $dfUsage = $vtools->vaultUsage();
        }

        return view('filament.vault-details', compact(
            'vault', 'dfUsage', 'isLuksOpen', 'device', 'mountPoint', 'imageSize'
        ));
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
