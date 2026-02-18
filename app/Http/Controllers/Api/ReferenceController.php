<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class ReferenceController extends Controller
{
    public function services()
    {
        return response()->json([
            'services' => [
                ['key' => 'exterior', 'title' => 'Exterieur uniquement', 'price' => 2000],
                ['key' => 'interior', 'title' => 'Interieur uniquement', 'price' => 2500],
                ['key' => 'complete', 'title' => 'Lavage complet', 'price' => 4000],
            ],
            'vehicles' => ['Berline', 'Compacte', 'SUV'],
        ]);
    }
}
