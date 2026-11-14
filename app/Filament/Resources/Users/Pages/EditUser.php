<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\Group;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Wave\Plan;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createGroup')
                ->label('Create Team Group')
                ->color('info')
                ->visible(fn (): bool => $this->record->hasRole(['Team', 'Enterprise']) &&
                    ! Group::where('owner_id', $this->record->id)->exists()
                )
                ->action(function (): void {
                    $isEnterprise = $this->record->hasRole('Enterprise');
                    $planName = $isEnterprise ? 'Enterprise' : 'Team';
                    $plan = Plan::where('status', 'available')
                        ->whereEnglishName($planName)
                        ->first();

                    $group = Group::create([
                        'name' => $this->record->name."'s Group",
                        'owner_id' => $this->record->id,
                        'plan_id' => $plan?->id,
                        'max_members' => $isEnterprise ? 20 : 8,
                    ]);

                    if (! $this->record->group_id) {
                        $this->record->update(['group_id' => $group->id]);
                    }

                    Notification::make()->title('Group created successfully')->success()->send();
                })
                ->requiresConfirmation(),

            DeleteAction::make()
                ->after(function (): void {
                    $uid = auth()->id() ?? 0;
                    addEvent((object) ['message' => 'user deleted'], 'DEL_USER', 'SUCCESS', 'ACTIVITY', 0, 0, $uid, $uid);
                }),
        ];
    }
}
