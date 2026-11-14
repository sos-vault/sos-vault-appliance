<x-filament::section class="fi-prose">
    <div class="filament-rich-content">
        <h3>{{ $heading }}</h3>
        <p class="text-sm text-muted">{{ $subheading }}</p>

        @if(isset($records) && count($records))
            <table class="w-full table-auto border-collapse">

                @if(isset($headers) && count($headers))
                    <thead>
                        <tr>
                            @foreach($headers as $col)
                                <th class="text-left">{{ $col }}</th>
                            @endforeach
                        </tr>
                    </thead>
                @endif

                <tbody>
                    @foreach($records as $row)
                        <tr>
                            @foreach($orders as $col)
                                <td>{{ $row[$col] }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p>No data available.</p>
        @endif
    </div>
</x-filament::section >
