<?php

namespace App\Http\Requests\CourseTalks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates exactly the attributes the existing
 * CourseEditionService::syncSessions() contract consumes: an ordered list of
 * sessions where every entry names its topic, while `session_date`, `starts_at`,
 * `ends_at` and `teacher_name` are optional and must be usable when present.
 *
 * The service does **not** wipe the list the way syncTeachers() does: it upserts
 * by array position (`sort_order = index + 1`), so this request never sends a
 * `sort_order` (it is absent from the rules, and `validated()` only returns the
 * keys declared here; the service derives the position itself).
 *
 * One Blade affordance is resolved here and never reaches the service as data:
 * `new_session[...]` is the optional "add another session" slot. It is appended
 * to the list only when it carries a value, so re-submitting the form untouched
 * never fails validation and never adds a blank session.
 *
 * There is deliberately no `remove` affordance: syncSessions() has no delete
 * step, so dropping a row would shift the remaining positions onto the wrong
 * records instead of removing the session. The form copy states that saving
 * updates sessions by position.
 *
 * Genuine type-shape violations are preserved so the declared `array`/`string`/
 * `date`/`date_format` rules report them: a non-array `sessions` payload is left
 * untouched, and non-array entries are kept as submitted instead of being
 * coerced into something the service cannot persist.
 */
class SyncEditionSessionsRequest extends FormRequest
{
    /**
     * `CourseEditionPolicy::manageSessions` is the authority for this surface;
     * the request mirrors its rule (`course-talks.sessions.manage` OR the
     * coarser `course-talks.editions.manage`) so the form is never refused for a
     * user the route would have let in.
     */
    public function authorize(): bool
    {
        return ($this->user()?->can('course-talks.sessions.manage') ?? false)
            || ($this->user()?->can('course-talks.editions.manage') ?? false);
    }

    protected function prepareForValidation(): void
    {
        $sessions = $this->input('sessions');

        // Leave a non-array payload untouched so the `array` rule reports it.
        if (! is_array($sessions)) {
            return;
        }

        // Reindex while keeping non-array entries so the wildcard rules report
        // them, and so the submitted order is exactly the service's positions.
        $submitted = array_map(function ($session) {
            if (is_array($session) && ($session['teacher_id'] ?? null) === '') {
                $session['teacher_id'] = null;
            }

            return $session;
        }, array_values($sessions));

        $newSession = $this->input('new_session');

        if (is_array($newSession) && array_filter($newSession, fn ($value): bool => $value !== null && $value !== '') !== []) {
            if (($newSession['teacher_id'] ?? null) === '') {
                $newSession['teacher_id'] = null;
            }
            $submitted[] = $newSession;
        }

        // Nulling the slot keeps the flashed input consistent with the list that
        // was actually validated: on a failed submit the filled-in new session is
        // rendered (and re-submitted) as a regular list row, not twice.
        $this->merge(['sessions' => $submitted, 'new_session' => null]);
    }

    public function rules(): array
    {
        return [
            'sessions' => ['nullable', 'array'],
            'sessions.*' => ['array'],
            'sessions.*.topic' => ['required', 'string', 'max:255'],
            'sessions.*.session_date' => ['nullable', 'date'],
            'sessions.*.starts_at' => ['nullable', 'date_format:H:i'],
            'sessions.*.ends_at' => ['nullable', 'date_format:H:i'],
            'sessions.*.teacher_id' => ['nullable', 'integer', Rule::exists('course_edition_teachers', 'id')
                ->where('course_edition_id', $this->route('edition')?->id)
                ->whereNull('deleted_at')],
            'sessions.*.teacher_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The exact array CourseEditionService::syncSessions() consumes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sessionsForSync(): array
    {
        return array_map(static fn (array $session): array => [
            'topic' => $session['topic'],
            'session_date' => $session['session_date'] ?? null,
            'starts_at' => $session['starts_at'] ?? null,
            'ends_at' => $session['ends_at'] ?? null,
            'teacher_id' => $session['teacher_id'] ?? null,
            'teacher_name' => $session['teacher_name'] ?? null,
        ], $this->validated('sessions') ?? []);
    }
}
