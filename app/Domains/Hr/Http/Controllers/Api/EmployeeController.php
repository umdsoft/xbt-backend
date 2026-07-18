<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Actions\Employees\CreateEmployeeAction;
use App\Domains\Hr\Actions\Employees\DeleteEmployeeAction;
use App\Domains\Hr\Actions\Employees\UpdateEmployeeAction;
use App\Domains\Hr\Actions\Relatives\SaveRelativesAction;
use App\Domains\Hr\Actions\WorkHistory\SaveWorkHistoryAction;
use App\Domains\Hr\DTOs\EmployeeDTO;
use App\Domains\Hr\Http\Requests\SaveRelativesRequest;
use App\Domains\Hr\Http\Requests\SaveWorkHistoryRequest;
use App\Domains\Hr\Http\Requests\StoreEmployeeRequest;
use App\Domains\Hr\Http\Requests\UpdateEmployeeRequest;
use App\Domains\Hr\Models\Employee;
use App\Domains\Hr\Repositories\Contracts\EmployeeRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmployeeController extends HrController
{
    public function __construct(
        private EmployeeRepositoryInterface $repository,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $filterKeys = ['search', 'department_id', 'education_level', 'nationality', 'birth_district_id', 'specialty'];

        $employees = $this->repository->paginate(
            filters: $request->only($filterKeys),
            perPage: (int) $request->input('per_page', 25),
        );

        return response()->json([
            'employees' => $employees,
            'filters' => $request->only($filterKeys),
        ]);
    }

    public function store(
        StoreEmployeeRequest $request,
        CreateEmployeeAction $action,
        SaveWorkHistoryAction $saveWorkHistory,
        SaveRelativesAction $saveRelatives,
    ): JsonResponse {
        $validated = $request->validated();

        // Расм юклаш — МАХФИЙ (private/local) дискда, авторизацияланган маршрут орқали берилади.
        if ($request->hasFile('photo')) {
            $validated['photo_path'] = $request->file('photo')->store('employee-photos', 'local');
        }

        $dto = EmployeeDTO::fromArray($validated);

        // Ходим + меҳнат фаолияти + қариндошлар — битта транзаксияда.
        $workHistory = $validated['work_history'] ?? [];
        $relatives = $validated['relatives'] ?? [];

        $employee = DB::transaction(function () use ($dto, $workHistory, $relatives, $action, $saveWorkHistory, $saveRelatives): Employee {
            $employee = $action->execute($dto);

            if (is_array($workHistory) && count($workHistory) > 0) {
                $saveWorkHistory->execute($employee, $workHistory);
            }

            if (is_array($relatives) && count($relatives) > 0) {
                $saveRelatives->execute($employee, $relatives);
            }

            return $employee;
        });

        return response()->json([
            'message' => 'Ходим муваффақиятли яратилди.',
            'employee' => $employee->fresh(['department', 'position', 'birthRegion', 'birthDistrict']),
        ], 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        $employee->load(['workHistory', 'relatives', 'department', 'position', 'birthRegion', 'birthDistrict']);

        return response()->json([
            'employee' => $employee,
        ]);
    }

    /**
     * Таҳрирлаш формаси учун ходим — махфий майдонлар очиқ (битта авторизацияланган ёзув).
     */
    public function edit(Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $employee->load(['department', 'position', 'birthRegion', 'birthDistrict', 'workHistory', 'relatives']);
        $employee->makeVisible(['jshshir', 'passport_series', 'passport_number']);

        return response()->json([
            'employee' => $employee,
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, UpdateEmployeeAction $action): JsonResponse
    {
        $this->authorize('update', $employee);

        $validated = $request->validated();
        $oldPhoto = $employee->photo_path;

        // Расм юклаш — МАХФИЙ (private/local) дискда. Янги расм бўлмаса, мавжуди сақланади.
        $validated['photo_path'] = $request->hasFile('photo')
            ? $request->file('photo')->store('employee-photos', 'local')
            : $employee->photo_path;

        $dto = EmployeeDTO::fromArray($validated);
        $updated = $action->execute($employee->id, $dto);

        // Эски расмни ўчириш (янгиси юкланган бўлса) — orphan файллар қолмаслиги учун.
        if ($request->hasFile('photo') && $oldPhoto && $oldPhoto !== $validated['photo_path']) {
            Storage::disk('local')->delete($oldPhoto);
        }

        return response()->json([
            'message' => 'Ходим маълумотлари янгиланди.',
            'employee' => $updated,
        ]);
    }

    public function destroy(Employee $employee, DeleteEmployeeAction $action): JsonResponse
    {
        $this->authorize('delete', $employee);

        $action->execute($employee->id);

        return response()->json([
            'message' => 'Ходим архивга ўтказилди.',
        ]);
    }

    /**
     * Ходим расмини авторизация билан бериш (public диск ўрнига).
     */
    public function photo(Employee $employee): BinaryFileResponse
    {
        $this->authorize('view', $employee);

        abort_if($employee->photo_path === null || $employee->photo_path === '', 404);
        abort_unless(Storage::disk('local')->exists($employee->photo_path), 404);

        return response()->file(Storage::disk('local')->path($employee->photo_path));
    }

    /**
     * 3-блок: Меҳнат фаолиятини сақлаш.
     */
    public function saveWorkHistory(
        SaveWorkHistoryRequest $request,
        Employee $employee,
        SaveWorkHistoryAction $action,
    ): JsonResponse {
        $this->authorize('update', $employee);

        $action->execute($employee, $request->validated('work_history'));

        return response()->json([
            'message' => 'Меҳнат фаолияти сақланди.',
            'employee' => $employee->fresh(['workHistory']),
        ]);
    }

    /**
     * 4-блок: Яқин қариндошларни сақлаш.
     */
    public function saveRelatives(
        SaveRelativesRequest $request,
        Employee $employee,
        SaveRelativesAction $action,
    ): JsonResponse {
        $this->authorize('update', $employee);

        $action->execute($employee, $request->validated('relatives'));

        return response()->json([
            'message' => 'Қариндошлар маълумотлари сақланди.',
            'employee' => $employee->fresh(['relatives']),
        ]);
    }
}
