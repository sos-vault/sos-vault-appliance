<?php

namespace App\Filament\Resources\Sysevents\Pages;

use App\Filament\Resources\Sysevents\SyseventResource;
use App\Models\Sysevent;
use App\Services\SiemForwarder;
use Filament\Resources\Pages\ListRecords;

class ListSysevents extends ListRecords
{
    protected static string $resource = SyseventResource::class;

    /**
     * Per-request memo so the "Send to SIEM" record-action visibility check runs
     * SiemForwarder::isEnabled() (which decrypts settings) once per render rather
     * than once per row. Private, so Livewire does not persist it across polls —
     * it resets each request and reflects a fresh enabled state.
     */
    private ?bool $siemEnabled = null;

    /**
     * Delivery traces keyed by event id. Public so it survives the table's 10s
     * poll: an open "Send to SIEM" modal re-renders on each poll, and this cache
     * ensures the event is sent once (on the user's click) and never re-sent.
     *
     * @var array<int, array{ok: bool, steps: array}>
     */
    public array $siemForwardTraces = [];

    public function siemEnabled(): bool
    {
        return $this->siemEnabled ??= app(SiemForwarder::class)->isEnabled();
    }

    /**
     * @return array{ok: bool, steps: array}
     */
    public function siemForwardResult(Sysevent $record): array
    {
        return $this->siemForwardTraces[$record->id] ??= app(SiemForwarder::class)->test($record);
    }
}
