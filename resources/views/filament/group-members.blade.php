<div class="space-y-2 text-sm">
    @if($members->isEmpty())
        <p class="text-gray-500 italic">No members in this group.</p>
    @else
        <table class="w-full text-left">
            <thead>
                <tr class="border-b border-gray-200 text-xs font-medium text-gray-500 uppercase tracking-wide">
                    <th class="pb-2 pr-4">Name</th>
                    <th class="pb-2 pr-4">Email</th>
                    <th class="pb-2 pr-4">Role</th>
                    <th class="pb-2">Vault</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($members as $member)
                    <tr>
                        <td class="py-2 pr-4 font-medium text-gray-900">
                            {{ $member->name }}
                            @if($member->id === $owner_id)
                                <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-semibold bg-amber-100 text-amber-800">Manager</span>
                            @endif
                        </td>
                        <td class="py-2 pr-4 text-gray-600">{{ $member->email }}</td>
                        <td class="py-2 pr-4">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">
                                {{ $member->roles->first()?->name ?? '—' }}
                            </span>
                        </td>
                        <td class="py-2">
                            @if($member->vault)
                                @php $status = ucfirst(strtolower($member->vault->status)); @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold
                                    {{ $status === 'Open' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                                    {{ $status }}
                                </span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
