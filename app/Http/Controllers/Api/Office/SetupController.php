<?php

namespace App\Http\Controllers\Api\Office;

use App\Http\Controllers\Controller;
use App\Models\AdmissionPeriod;
use App\Models\Program;
use App\Models\Requirement;
use App\Services\AdmissionSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Programs & requirements" screen (FR-09). Admission personnel only. */
class SetupController extends Controller
{
    public function __construct(private AdmissionSetupService $setup) {}

    public function updateProgram(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate(['slots' => ['required', 'integer', 'between:1,2000']], [
            'slots.between' => 'Slots must be between 1 and 2,000.',
        ]);
        $program = $this->setup->setSlots($program, $data['slots']);

        return response()->json(['data' => ['code' => $program->code, 'name' => $program->name, 'slots' => $program->slots]]);
    }

    public function storeProgram(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'slots' => ['required', 'integer', 'between:1,2000'],
        ]);
        $program = $this->setup->addProgram($data['code'], $data['name'], $data['slots']);

        return response()->json(['data' => ['code' => $program->code, 'name' => $program->name, 'slots' => $program->slots]], 201);
    }

    public function updateRequirement(Request $request, string $key): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $requirement = Requirement::admission()->where('key', $key)->firstOrFail();
        $this->setup->setRequirementActive($requirement, $data['active']);

        return response()->json(['data' => ['key' => $requirement->key, 'active' => $requirement->is_active]]);
    }

    public function updatePeriod(Request $request): JsonResponse
    {
        $data = $request->validate(['deadline' => ['required', 'date_format:Y-m-d']]);
        $period = AdmissionPeriod::current();
        $this->setup->setDeadline($period, $data['deadline']);

        return response()->json(['data' => [
            'deadline' => $period->application_deadline->toDateString(),
            'period' => $period->label,
            'inactiveDocs' => Requirement::admission()->where('is_active', false)->pluck('key')->all(),
        ]]);
    }
}
