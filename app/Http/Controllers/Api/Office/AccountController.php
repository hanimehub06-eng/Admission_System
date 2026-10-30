<?php

namespace App\Http\Controllers\Api\Office;

use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\College;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Super admin: manage admission personnel, clinic and college dean accounts. */
class AccountController extends Controller
{
    private const MANAGED = [Role::Admission, Role::Clinic, Role::CollegeDean];

    /** Tasks an Admission Personnel account can be assigned (the panel shows only their screens). */
    public const TASKS = ['personnel' => 'Admission Personnel', 'proctor' => 'Exam Proctor', 'exam_office' => 'Exam Office'];

    public function index(): JsonResponse
    {
        $users = User::role(array_map(fn (Role $r) => $r->value, self::MANAGED), 'api')
            ->with('deanOf')->orderBy('name')->get();

        return response()->json(['data' => $users->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'employee_no' => $u->employee_id,
            'role' => $u->primaryRole()?->value, 'role_label' => $u->primaryRole()?->label(),
            'college' => $u->deanOf?->code, 'active' => (bool) $u->is_active, 'task' => $u->task,
            'last_login_at' => $u->last_login_at?->toIso8601String(),
        ])->all()]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $roles = array_map(fn (Role $r) => $r->value, self::MANAGED);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            // EMS's official ID: links the account for the nightly ems:sync-staff check
            'employee_no' => ['nullable', 'regex:/^\d{2}-\d{5}$/', Rule::unique('users', 'employee_id')],
            'role' => ['required', Rule::in($roles)],
            'college' => ['required_if:role,college_dean', 'nullable', 'string', Rule::exists('colleges', 'code')],
        ]);

        $password = Str::password(16);
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $password]);
        if ($data['employee_no'] ?? null) {
            $user->forceFill(['employee_id' => $data['employee_no'], 'source' => 'ems'])->save();
        }
        $user->assignRole($data['role']);
        if ($data['role'] === Role::CollegeDean->value) {
            College::where('code', strtoupper($data['college']))->update(['dean_user_id' => $user->id]);
        }
        $audit->record('account.created', 'Created '.Role::from($data['role'])->label()." account for {$user->email}", $user);

        // Shown once: the super admin hands it to the new staff member
        return response()->json(['data' => ['id' => $user->id, 'temporary_password' => $password]], 201);
    }

    public function setTask(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        $task = $request->validate(['task' => ['present', 'nullable', Rule::in(array_keys(self::TASKS))]])['task'];
        if ($user->primaryRole() !== Role::Admission) {
            throw new BusinessRuleException('Only admission personnel accounts are given a task.');
        }
        $user->forceFill(['task' => $task])->save();
        $audit->record('account.task', $task ? "Assigned {$user->name} ({$user->email}) the task: ".self::TASKS[$task] : "Removed the task of {$user->name} ({$user->email})", $user, meta: ['task' => $task]);

        return response()->json(['data' => ['id' => $user->id, 'task' => $task]]);
    }

    public function setActive(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        $active = $request->validate(['active' => ['required', 'boolean']])['active'];
        if (! in_array($user->primaryRole(), self::MANAGED, true)) {
            throw new BusinessRuleException('Only admission, clinic and college dean accounts can be changed here.');
        }
        $user->forceFill(['is_active' => $active])->save();
        $audit->record($active ? 'account.enabled' : 'account.disabled', ($active ? 'Enabled' : 'Disabled')." account {$user->email}", $user);

        return response()->json(['data' => ['id' => $user->id, 'active' => $active]]);
    }
}
