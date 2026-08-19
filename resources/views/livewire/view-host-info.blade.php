<?php
    // This component creates a Filament Schema for the host summary component defined in SosDataProvider->summaryData
    // record is provided by upper summary-table-modal component

    use Livewire\Volt\Component;

    use Filament\Schemas\Schema;
    use Filament\Schemas\Concerns\InteractsWithSchemas;
    use Filament\Schemas\Contracts\HasSchemas;
    use Filament\Infolists\Components\TextEntry;
    use Filament\Schemas\Components\Grid;
    use Filament\Support\Enums\TextSize;
    use Filament\Schemas\Components\Section;


    new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public $record;

        public function viewHostInfo(Schema $schema): Schema
        {
            return $schema
                ->record($this->record)
                ->components([
                    Section::make(__('vault.host_system_info'))
                    ->collapsible()
                    ->columns(1)
                    ->schema([
                        Grid::make([
                            'default' => 3,
                        ])
                        ->schema([
                            TextEntry::make('hostname')
                                ->size(TextSize::Large)
                                ->color('info')
                                ->label(__('vault.host_hostname')),

                            TextEntry::make('os version')
                                ->size(TextSize::Large)
                                ->color('info')
                                ->icon(fn ($record): string => $record['icon'])
                                ->iconColor('info')
                                ->label(__('vault.host_os_version')),

                            TextEntry::make('kernel')
                                ->size(TextSize::Large)
                                ->color('info')
                                ->label(__('vault.host_kernel')),

                            TextEntry::make('sos version')
                                ->size(TextSize::Large)
                                ->label(__('vault.host_sos_version')),

                            TextEntry::make('runlevel')
                                ->size(TextSize::Large)
                                ->label(__('vault.host_runlevel')),

                            TextEntry::make('load average')
                                ->size(TextSize::Large)
                                ->label(__('vault.host_load_average')),
                        ]),
                    ]),

                    Section::make(__('vault.host_network_settings'))
                    ->collapsible()
                    ->columns(1)
                    ->schema([
                        Grid::make(3)
                        ->schema([
                            TextEntry::make('nic')
                                ->size(TextSize::Large)
                                ->color('info')
                                ->label(__('vault.host_interface')),

                            TextEntry::make('linked')
                                ->size(TextSize::Large)
                                ->color('info')
                                ->label(__('vault.host_link_up')),

                            TextEntry::make('mtu')
                                ->size(TextSize::Large)
                                ->color('info')
                                ->label(__('vault.host_mtu')),

                            TextEntry::make('speed')
                                ->size(TextSize::Large)
                                ->color('info')
                                ->label(__('vault.host_speed')),

                            TextEntry::make('duplex')
                                ->size(TextSize::Large)
                                ->color('info')
                                ->label(__('vault.host_duplex')),

                            TextEntry::make('mac')
                                ->size(TextSize::Large)
                                ->color('info')
                                ->label(__('vault.host_mac')),

                            TextEntry::make('type')
                                ->size(TextSize::Large)
                                ->label(__('vault.host_type')),

                            TextEntry::make('port')
                                ->size(TextSize::Large)
                                ->label(__('vault.host_port')),

                            TextEntry::make('connection')
                                ->size(TextSize::Large)
                                ->label(__('vault.host_connection')),

                            TextEntry::make('ip4')
                                ->size(TextSize::Large)
                                ->color('info')
                                ->label(__('vault.host_ip')),

                            TextEntry::make('gateway')
                                ->size(TextSize::Large)
                                ->color('info')
                                ->label(__('vault.host_gateway')),

                            TextEntry::make('dns servers')
                                ->size(TextSize::Large)
                                ->color('info')
                                ->label(__('vault.host_dns_servers')),

                            TextEntry::make('dns domain')
                                ->size(TextSize::Large)
                                ->label(__('vault.host_dns_domain')),

                            TextEntry::make('dhcp server')
                                ->size(TextSize::Large)
                                ->color('info')
                                ->label(__('vault.host_dhcp_server')),

                            TextEntry::make('ntp server')
                                ->size(TextSize::Large)
                                ->color('info')
                                ->label(__('vault.host_ntp_server')),

                            /*
                            TextEntry::make('smtp server')
                                ->size(TextSize::Large)
                                ->label('SMTP server'),
                            */
                        ]),
                    ]),

                    Section::make(__('vault.host_date_info'))
                    ->collapsible()
                    ->columns(1)
                    ->schema([
                        Grid::make(3)
                        ->schema([
                            TextEntry::make('time zone')
                                ->size(TextSize::Large)
                                ->label(__('vault.host_time_zone')),

                            TextEntry::make('system time')
                                ->size(TextSize::Large)
                                ->label(__('vault.host_system_time')),

                            TextEntry::make('universal time')
                                ->size(TextSize::Large)
                                ->label(__('vault.host_universal_time')),

                            TextEntry::make('boot time')
                                ->size(TextSize::Large)
                                ->label(__('vault.host_boot_time')),

                            TextEntry::make('uptime')
                                ->size(TextSize::Large)
                                ->color('info')
                                ->label(__('vault.host_uptime')),
                        ]),
                    ]),

                ]);
        }
    }
?>

<div>
    @volt('view-host-info')
        {{ $this->viewHostInfo }}
    @endvolt
</div>
