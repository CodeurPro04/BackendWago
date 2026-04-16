<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class ReferenceController extends Controller
{
    public function services()
    {
        return response()->json([
            'services' => [
                ['key' => 'exterior', 'title' => 'Exterieur uniquement', 'price' => 5000],
                ['key' => 'complete', 'title' => 'Lavage complet', 'price' => 10000],
            ],
            'vehicles' => ['Berline', 'Compacte', 'SUV'],
        ]);
    }
}
