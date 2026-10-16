<?php

namespace App\Modules\Cartera\Http\Controllers;

use App\Modules\Cartera\Application\Commitments\CommitmentInboxFilter;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /cartera/compromisos (cartera.compromisos.index): the inbox where the
 * analyst sees every payment commitment of the clients in her view, SGX ones
 * included, filtered by status, advisor and due date.
 */
final class CommitmentInboxController
{
    public function __invoke(Request $request, CompromisoService $commitments, CarteraRepository $cartera, CurrentCompany $company): View
    {
        $filter = CommitmentInboxFilter::fromInput($request->query());

        // Advisor codes repeat across companies, so the names are keyed by both.
        $advisorNames = [];
        $advisorOptions = [];

        foreach ($cartera->advisors() as $advisor) {
            $advisorNames["{$advisor->company->value}:{$advisor->code}"] = $advisor->name;
            $advisorOptions[$advisor->code] = $company->isGroup() ? "{$advisor->name} ({$advisor->company->value})" : $advisor->name;
        }

        return view('cartera::compromisos.index', [
            'filter' => $filter,
            'commitments' => $commitments->paginate($filter->toQuery(), (int) config('cartera.por_pagina')),
            'advisorNames' => $advisorNames,
            'advisorOptions' => $advisorOptions,
            'isGroup' => $company->isGroup(),
        ]);
    }
}
