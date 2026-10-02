{{--
    Membership card of one content project (T3). Expected data:
    $project (with `members` loaded), $memberCandidates.

    The submitted `members[]` list is the project's complete membership: saving
    replaces the pivot rows, so removing a member is submitting the list without
    them. The form is only rendered for actors the policy allows to assign
    members; everyone else still sees who belongs to the project.
--}}
@php
    $currentMemberIds = $project->members->pluck('id')->all();
    $selectedIds = old('members', $currentMemberIds);
    $selectedIds = is_array($selectedIds) ? array_map('strval', $selectedIds) : [];
@endphp

<div class="card">
    <div class="card-header">
        <h3 class="card-title mb-0">Miembros del proyecto</h3>
    </div>
    <div class="card-body">
        <ul class="list-group list-group-flush mb-3">
            @forelse ($project->members as $member)
                <li class="list-group-item px-0" data-testid="content-project-member-{{ $member->id }}">
                    <strong>{{ $member->name }}</strong>
                    <div class="small text-secondary">{{ $member->email }}</div>
                </li>
            @empty
                <li class="list-group-item px-0 text-secondary small" data-testid="content-project-members-empty">
                    Este proyecto todavía no tiene miembros asignados.
                </li>
            @endforelse
        </ul>

        @can('assignMembers', $project)
            <form method="POST" action="{{ route('content.projects.members.sync', $project) }}" data-testid="content-project-members-form">
                @csrf

                <p class="small text-secondary">
                    Marcá los usuarios que deben pertenecer al proyecto. Al guardar, la lista reemplaza la membresía actual.
                </p>

                <div class="mb-3" style="max-height: 16rem; overflow-y: auto;">
                    @forelse ($memberCandidates as $candidate)
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="members[]" value="{{ $candidate->id }}"
                                   id="content-project-member-{{ $candidate->id }}"
                                   @checked(in_array((string) $candidate->id, $selectedIds, true))>
                            <label class="form-check-label" for="content-project-member-{{ $candidate->id }}">
                                {{ $candidate->name }}
                                <span class="text-secondary small">{{ $candidate->email }}</span>
                            </label>
                        </div>
                    @empty
                        <p class="text-secondary small mb-0">No hay usuarios activos para asignar.</p>
                    @endforelse
                </div>

                <button type="submit" class="btn btn-primary" data-testid="btn-sync-content-project-members">
                    <i class="bi bi-people me-1" aria-hidden="true"></i> Guardar miembros
                </button>
            </form>
        @else
            <p class="small text-secondary mb-0">No tenés permiso para modificar la membresía de este proyecto.</p>
        @endcan
    </div>
</div>
