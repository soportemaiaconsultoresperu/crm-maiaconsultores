<?php

namespace App\Http\Requests\Content;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Membership payload for one content project (T3).
 *
 * The submitted list is the project's complete membership: the controller
 * replaces the pivot rows with exactly this set, so removing a member is
 * "submit the list without them" rather than a separate endpoint.
 *
 * `members` is optional so the form can legitimately submit an empty
 * assignment (revoking every membership). `distinct` keeps a duplicated id
 * from reaching the pivot, where it would violate the unique constraint.
 *
 * Authorization stays in the controller for the same reason as the other
 * record-level requests: the project is resolved first, a foreign project is
 * reported as 404, and only a reachable project is checked against the
 * `assignMembers` policy ability.
 */
class SyncContentProjectMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'members' => ['nullable', 'array'],
            'members.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'members.array' => 'El listado de miembros no es válido.',
            'members.*.integer' => 'Cada miembro debe ser un usuario del sistema.',
            'members.*.distinct' => 'Un usuario no puede repetirse en el listado de miembros.',
            'members.*.exists' => 'Uno de los usuarios seleccionados no existe.',
        ];
    }
}
