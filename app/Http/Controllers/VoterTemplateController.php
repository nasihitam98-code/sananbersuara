<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Voter;
use App\Services\Voters\VoterImport;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Template Excel import pemilih (dengan satu baris contoh).
 */
class VoterTemplateController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->can('create', Voter::class), 403);

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(VoterImport::HEADERS));
        $writer->addRow(Row::fromValues(['Budi Santoso', $user->unit?->code ?? '01', 'Jl. Mawar 12', 'L', '31-12-1970', '', '']));
        $writer->close();

        return response()->download($path, 'template-data-pemilih.xlsx')->deleteFileAfterSend();
    }
}
