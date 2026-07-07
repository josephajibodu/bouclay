<?php

namespace App\Http\Controllers\Docs;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class ApiDocsController extends Controller
{
    public function __invoke(): View
    {
        return view('docs.api', [
            'specUrl' => route('docs.api.openapi'),
        ]);
    }
}
