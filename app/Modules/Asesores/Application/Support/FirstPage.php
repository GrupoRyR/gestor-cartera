<?php

namespace App\Modules\Asesores\Application\Support;

use Closure;
use Illuminate\Http\Request;

/**
 * Core's paginated reads take the page from the ?page= of the request. A screen
 * that shows one fixed list (the agenda, the commitments of a client file)
 * must not change with that parameter, so it reads the first page whatever
 * the URL says.
 */
final class FirstPage
{
    /**
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    public static function read(Closure $read): mixed
    {
        /** @var Request $request */
        $request = request();
        $page = $request->query->get('page');
        $request->query->remove('page');

        try {
            return $read();
        } finally {
            if ($page !== null) {
                $request->query->set('page', $page);
            }
        }
    }
}
