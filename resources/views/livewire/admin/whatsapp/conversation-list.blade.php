<div>
    {{-- Filter form: posts to itself so Livewire can rebuild the query. --}}
    <form wire:submit.prevent class="card mb-3">
        <div class="card-header"><h3 class="card-title mb-0">Filtros</h3></div>
        <div class="card-body row g-2">
            <div class="col-md-3">
                <label class="form-label" for="status">Estado</label>
                <select wire:model.live="statusFilter" id="status" class="form-select">
                    <option value="">Todos</option>
                    <option value="open">Abiertas</option>
                    <option value="closed">Cerradas</option>
                    <option value="archived">Archivadas</option>
                    <option value="opted_out">Con opt-out</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="assigned_to">Asignado a</label>
                <select wire:model.live="assignedToFilter" id="assigned_to" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="phone_number">Teléfono</label>
                <input wire:model.live.debounce.300ms="phoneFilter"
                       id="phone_number" type="text" class="form-control"
                       placeholder="Buscar por teléfono (contiene)" />
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="button" class="btn btn-outline-secondary"
                        wire:click="$set('statusFilter', null); $set('assignedToFilter', null); $set('phoneFilter', null);">
                    Limpiar
                </button>
            </div>
        </div>
    </form>

    <x-table title="Conversaciones">
        @slot('headers')
            <tr>
                <th>Contacto</th>
                <th>Teléfono</th>
                <th>Último mensaje</th>
                <th>Últ. mov.</th>
                <th>Estado</th>
                <th>Asignado a</th>
                <th class="text-end">Acciones</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($conversations as $conversation)
                @php
                    $lastMessage = $conversation->messages()->latest('id')->first();
                    $snippet = $lastMessage !== null
                        ? \Illuminate\Support\Str::limit((string) ($lastMessage->body ?? ''), 60)
                        : '—';
                @endphp
                <tr wire:key="conv-{{ $conversation->id }}">
                    <td>{{ $conversation->contact_name ?: '—' }}</td>
                    <td><code>{{ $conversation->phone_number }}</code></td>
                    <td class="text-muted">{{ $snippet }}</td>
                    <td>{{ optional($conversation->last_message_at)->format('Y-m-d H:i') ?: '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $conversation->status === 'open' ? 'success' : ($conversation->status === 'opted_out' ? 'danger' : 'secondary') }}">
                            {{ $conversation->status }}
                        </span>
                    </td>
                    <td>{{ $conversation->assignee?->name ?? '—' }}</td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('whatsapp.conversations.show', $conversation) }}"
                           class="btn btn-sm btn-outline-primary">
                            Abrir
                        </a>
                        @if ($canAssign && $conversation->status === 'open')
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    wire:click="assignConversation({{ $conversation->id }}, {{ $assignedToFilter ?? 'null' }})"
                                    wire:confirm="¿Reasignar la conversación al usuario filtrado?">
                                Asignar
                            </button>
                        @endif
                        @if ($canSend && $conversation->status === 'open')
                            <button type="button" class="btn btn-sm btn-outline-warning"
                                    wire:click="closeConversation({{ $conversation->id }})"
                                    wire:confirm="¿Cerrar la conversación?">
                                Cerrar
                            </button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted">
                        No hay conversaciones para los filtros seleccionados.
                    </td>
                </tr>
            @endforelse

            @if (method_exists($conversations, 'links'))
                <tr>
                    <td colspan="7">{{ $conversations->links() }}</td>
                </tr>
            @endif
        @endslot
    </x-table>
</div>