<?php

namespace App\Filament\Resources\Groups\Pages;

use App\Filament\Resources\Groups\GroupResource;
use App\Providers\VaultTools;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateGroup extends CreateRecord
{
    protected static string $resource = GroupResource::class;

    /**
     * Appliance branch: as soon as the group row is persisted, provision
     * the LUKS-backed group vault. If provisioning fails, the group row
     * is rolled back so the admin doesn't end up with an empty group.
     * Emits ADD_GROUP via addEvent; ADD_VAULT is emitted by createGroupVault.
     */
    protected function afterCreate(): void
    {
        if (! isAppliance()) {
            return;
        }

        $sizeMb = (int) ($this->data['vault_size_mb'] ?? 0);
        if ($sizeMb < 256) {
            $sizeMb = 10240;
        }

        $vault = VaultTools::createGroupVault($this->record, $sizeMb);

        if (! $vault) {
            $this->record->delete();
            Notification::make()
                ->danger()
                ->title('Vault provisioning failed')
                ->body('The group was rolled back. Check the application log for details.')
                ->send();
            $this->halt();

            return;
        }

        $payload = (object) [
            'name' => $this->record->name,
            'size_mb' => $sizeMb,
            'max_members' => $this->record->max_members,
        ];
        addEvent($payload, 'ADD_GROUP', 'SUCCESS', 'ACTIVITY', 0, $vault->id, auth()->id() ?? 0, $this->record->id);
    }
}
