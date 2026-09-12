<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The SYSTEM author the automatic academic document generation is attributed to.
 *
 * The eligibility job runs asynchronously with no user, so the generated
 * document has no human author. Two properties of the domain force a real
 * account instead of a null author:
 *
 * 1. `CourseDocumentGenerationService` registers the generated PDF as a
 *    `documents` row, and `documents.uploaded_by` is a NOT NULL reference to
 *    `users` (a table shared by the whole CRM, which this module does not
 *    change). Without an account there is no author to write and the private
 *    file cannot be registered.
 * 2. `generateDocument()` authorizes through `Gate::forUser($actor)`, and the
 *    module deliberately does not bypass that control. The system author
 *    therefore HOLDS EXACTLY ONE ABILITY — `course-talks.documents.generate`,
 *    the same one an operator needs — and no role at all. It cannot reach any
 *    other screen or action, including through the `admin` role's `Gate::before`
 *    bypass, which it must not have.
 *
 * The account is explicitly non-human and has no usable credentials: the name
 * says what it is, the password is a random string nobody is told, and
 * `is_active = false` means `EnsureUserIsActive` refuses it every authenticated
 * request. It exists to be named in the audit trail, not to log in.
 *
 * Seeding it is idempotent: `updateOrCreate` keeps one row per configured email
 * and the permission/role syncs are absolute, so a re-seed converges instead of
 * accumulating grants.
 */
class CourseSystemAuthorSeeder extends Seeder
{
    /**
     * Default address, also the `config/courses.php` default. The `.invalid`
     * TLD is reserved by RFC 2606 and can never resolve, so no message addressed
     * to this account can leave the system.
     */
    public const EMAIL = 'sistema.certificados@crm-maia.invalid';

    public const NAME = 'Sistema (generación automática de certificados)';

    public const PERMISSION = 'course-talks.documents.generate';

    public function run(): void
    {
        $email = trim((string) config('courses.system_author_email'));

        if ($email === '') {
            throw new \RuntimeException('courses.system_author_email must be configured to seed the system author.');
        }

        $author = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => self::NAME,
                'password' => Hash::make(Str::random(64)),
                'is_active' => false,
            ],
        );

        // Exactly one ability and no role: the generation gate the service asks
        // for, and nothing that a role would widen.
        $author->syncRoles([]);
        $author->syncPermissions([self::PERMISSION]);
    }
}
