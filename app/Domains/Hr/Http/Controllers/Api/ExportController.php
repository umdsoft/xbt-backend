<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Exports\MalumotnomaDocxExporter;
use App\Domains\Hr\Models\Employee;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends HrController
{
    public function __construct(
        private MalumotnomaDocxExporter $exporter,
    ) {}

    /**
     * Битта ходимнинг Маълумотномасини .docx сифатида юклаб олиш.
     *
     * Route-model binding + tenant global scope → бошқа hokimlik ходими 404.
     * kadrlar.export рухсати + айни ходимни кўриш ҳуқуқи талаб қилинади.
     */
    public function downloadMalumotnoma(Employee $employee): BinaryFileResponse
    {
        $this->authorize('export', Employee::class);
        $this->authorize('view', $employee);

        $path = $this->exporter->export($employee);
        $filename = $this->exporter->generateFilename($employee);

        return response()->download($path, $filename)->deleteFileAfterSend();
    }
}
