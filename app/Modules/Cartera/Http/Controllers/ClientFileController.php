<?php

namespace App\Modules\Cartera\Http\Controllers;

use App\Modules\Cartera\Application\Clients\LoadClientFile;
use Illuminate\Contracts\View\View;

/**
 * GET /cartera/clientes/{client} (cartera.clientes.show): the client file.
 * A client that does not exist, or is out of the user's reach, is a 404.
 */
final class ClientFileController
{
    public function __invoke(int $client, LoadClientFile $files): View
    {
        $file = $files->handle($client) ?? abort(404);

        return view('cartera::clientes.show', ['file' => $file]);
    }
}
