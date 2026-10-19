<?php

namespace App\Modules\Bancos\Http\Controllers;

use App\Modules\Bancos\Application\Access\VisibleCompanies;
use App\Modules\Bancos\Application\Import\StatementImport;
use App\Modules\Bancos\Domain\Movement\ImportSource;
use App\Modules\Bancos\Domain\Statement\UnrecognizedStatement;
use App\Modules\Bancos\Http\Requests\UploadStatementRequest;
use App\Modules\Bancos\Infrastructure\Files\UnreadableFile;
use App\Modules\Bancos\Infrastructure\Persistence\BankLoad;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The statements loaded so far (from the folder, the app and Treasury's API)
 * and the form to upload one by hand.
 */
final class LoadController
{
    private const int PER_PAGE = 25;

    public function __construct(
        private readonly VisibleCompanies $companies,
        private readonly StatementImport $import,
    ) {}

    public function index(): View
    {
        $available = $this->companies->available();

        return view('bancos::cargas.index', [
            'loads' => BankLoad::query()
                ->with('user')
                ->whereIn('company', $this->companies->inView())
                ->latest('id')
                ->paginate(self::PER_PAGE),
            'companies' => array_combine(
                array_map(fn (CompanyCode $code): string => $code->value, $available),
                array_map(fn (CompanyCode $code): string => $code->label(), $available),
            ),
            'banks' => Bank::options(),
        ]);
    }

    public function show(BankLoad $load): View
    {
        abort_unless($this->companies->canSee($load->company), 404);

        return view('bancos::cargas.show', ['load' => $load->loadMissing('user')]);
    }

    public function store(UploadStatementRequest $request): RedirectResponse
    {
        $file = $request->statement();
        $userId = $request->user()?->getAuthIdentifier();

        try {
            $result = $this->import->fromFile(
                path: (string) $file->getRealPath(),
                bank: $request->bank(),
                company: $request->company(),
                source: ImportSource::App,
                fileName: $file->getClientOriginalName(),
                userId: is_numeric($userId) ? (int) $userId : null,
                extension: $file->getClientOriginalExtension(),
            );
        } catch (UnreadableFile|UnrecognizedStatement $e) {
            return redirect()->route('bancos.cargas.index')
                ->withErrors(['archivo' => $e->getMessage()])
                ->withInput($request->except('archivo'));
        }

        $message = __('bancos::cargas.resultado.cargado', [
            'nuevos' => number_format($result->inserted(), 0, ',', '.'),
            'repetidos' => number_format($result->duplicates(), 0, ',', '.'),
            'banco' => $request->bank()->label(),
            'empresa' => $request->company()->value,
        ]);

        if ($result->hasErrors()) {
            return redirect()->route('bancos.cargas.show', $result->load)
                ->with('status', $message)
                ->with('error', __('bancos::cargas.resultado.con_errores', ['errores' => $result->errorCount()]));
        }

        return redirect()->route('bancos.cargas.index')->with('status', $message);
    }
}
