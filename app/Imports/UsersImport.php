<?php

declare(strict_types=1);

namespace App\Imports;

use App\Models\Role;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Support\RoleAssignmentGuard;
use App\Support\SpreadsheetSafety;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Throwable;

/**
 * Imports users into the importer's organization.
 *
 * Columns: name, email, role (base role: member, manager or admin; blank =
 * member), roles (custom role names or slugs, separated by ";" or ","). The
 * same columns the user export writes, so an export can be re-imported.
 *
 * Passwords never travel in the file: any password column is ignored (with a
 * warning). Each new user gets a long random password nobody knows and, by
 * default, an email with a set-password link. With invitations off, the
 * account is created and left pending: the user signs in by requesting a
 * link via "Forgot your password?".
 *
 * Every row goes through RoleAssignmentGuard, the same privilege-escalation
 * check as the user form: a non-admin importer can only create plain members
 * with roles no wider than their own permissions. A violation rejects that
 * row. An email that already belongs to any account, or repeats earlier in
 * the file, is skipped with a warning.
 */
final class UsersImport implements ToCollection, WithHeadingRow
{
    private const BASE_ROLES = ['member', 'manager', 'admin'];

    private int $imported = 0;

    private int $skipped = 0;

    /** @var array<int, array{row: int, errors: array<int, string>}> */
    private array $errors = [];

    /** @var array<int, array{row: int, warnings: array<int, string>}> */
    private array $warnings = [];

    /** @var array<string, true> */
    private array $seenEmails = [];

    private bool $passwordColumnReported = false;

    public function __construct(
        private readonly User $importer,
        private readonly bool $sendInvites = true,
    ) {}

    /**
     * @param  Collection<int, Collection<string, mixed>>  $rows
     */
    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $data = $row->toArray();

            if (! $this->passwordColumnReported && array_key_exists('password', $data)) {
                $this->passwordColumnReported = true;
                $this->warn($rowNumber, 'The password column was ignored: passwords are never imported. New users set their own via the emailed link.');
            }

            $name = trim((string) ($data['name'] ?? ''));
            $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
            $baseRole = mb_strtolower(trim((string) ($data['role'] ?? ''))) ?: 'member';
            $roleNames = $this->splitRoles((string) ($data['roles'] ?? ''));

            if ($name === '' && $email === '' && $roleNames === []) {
                continue;
            }

            $validator = Validator::make(
                ['name' => $name, 'email' => $email, 'role' => $baseRole],
                [
                    'name' => 'required|string|max:255',
                    'email' => 'required|email|max:255',
                    'role' => 'required|in:'.implode(',', self::BASE_ROLES),
                ],
            );

            if ($validator->fails()) {
                $this->error($rowNumber, $validator->errors()->all());

                continue;
            }

            if (isset($this->seenEmails[$email])) {
                $this->skipped++;
                $this->warn($rowNumber, "Email '{$email}' appears earlier in this file; row skipped.");

                continue;
            }
            $this->seenEmails[$email] = true;

            if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                $this->skipped++;
                $this->warn($rowNumber, "A user with email '{$email}' already exists; row skipped.");

                continue;
            }

            [$roleIds, $unknown] = $this->resolveRoles($roleNames);
            if ($unknown !== []) {
                $this->error($rowNumber, array_map(fn ($r) => "Unknown role '{$r}'.", $unknown));

                continue;
            }

            $violation = RoleAssignmentGuard::violation($roleIds, $this->importer, $baseRole);
            if ($violation !== null) {
                $this->error($rowNumber, [$violation]);

                continue;
            }

            $this->createUser($rowNumber, $name, $email, $baseRole, $roleIds);
        }
    }

    /**
     * @return array{imported: int, skipped: int, errors: array<int, mixed>, warnings: array<int, mixed>}
     */
    public function getStats(): array
    {
        return [
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param  array<int, int>  $roleIds
     */
    private function createUser(int $rowNumber, string $name, string $email, string $baseRole, array $roleIds): void
    {
        $user = DB::transaction(function () use ($name, $email, $baseRole, $roleIds) {
            $user = User::create([
                'name' => mb_substr((string) SpreadsheetSafety::sanitiseImport($name), 0, 255),
                'email' => $email,
                // Random and never revealed: the user sets their own password
                // through the invitation / reset link.
                'password' => Hash::make(Str::password(40)),
                'organization_id' => $this->importer->organization_id,
                'role' => $baseRole,
            ]);

            if ($roleIds !== []) {
                $user->roles()->sync($roleIds);
            }

            return $user;
        });

        $this->imported++;

        if (! $this->sendInvites) {
            return;
        }

        try {
            $token = Password::broker()->createToken($user);
            $user->notify(new AccountInvitation($token, (string) ($this->importer->organization?->name ?? config('app.name'))));
        } catch (Throwable $e) {
            Log::warning('User import invitation could not be sent', [
                'organization_id' => $this->importer->organization_id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            $this->warn($rowNumber, "User created, but the invitation email to '{$email}' could not be sent. They can use \"Forgot your password?\" to sign in.");
        }
    }

    /**
     * Match role names/slugs against this organization's roles and the
     * system roles (never another organization's).
     *
     * @param  array<int, string>  $names
     * @return array{0: array<int, int>, 1: array<int, string>}
     */
    private function resolveRoles(array $names): array
    {
        if ($names === []) {
            return [[], []];
        }

        $roles = Role::query()
            ->where(function ($q) {
                $q->where('organization_id', $this->importer->organization_id)
                    ->orWhereNull('organization_id');
            })
            ->get(['id', 'name', 'slug', 'organization_id']);

        $ids = [];
        $unknown = [];
        foreach ($names as $name) {
            $needle = mb_strtolower($name);
            $match = $roles->first(fn (Role $role) => mb_strtolower((string) $role->name) === $needle && $role->organization_id !== null)
                ?? $roles->first(fn (Role $role) => mb_strtolower((string) $role->name) === $needle || mb_strtolower((string) $role->slug) === $needle);

            if ($match === null) {
                $unknown[] = $name;
            } else {
                $ids[] = $match->id;
            }
        }

        return [array_values(array_unique($ids)), $unknown];
    }

    /**
     * @return array<int, string>
     */
    private function splitRoles(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[;,]/', $value) ?: []),
            fn (string $name) => $name !== '',
        ));
    }

    /**
     * @param  array<int, string>  $messages
     */
    private function error(int $row, array $messages): void
    {
        $this->errors[] = ['row' => $row, 'errors' => $messages];
    }

    private function warn(int $row, string $message): void
    {
        foreach ($this->warnings as $i => $entry) {
            if ($entry['row'] === $row) {
                $this->warnings[$i]['warnings'][] = $message;

                return;
            }
        }

        $this->warnings[] = ['row' => $row, 'warnings' => [$message]];
    }
}
