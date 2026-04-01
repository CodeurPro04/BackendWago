<?php

namespace App\Http\Controllers;

use App\Support\DispatchesSafeEvents;

abstract class Controller
{
    use DispatchesSafeEvents;
}
