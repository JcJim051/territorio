<?php

namespace App\Exceptions;

use RuntimeException;

class CalendarPublicationDeferred extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La publicación permanece pendiente hasta que Google Calendar esté disponible.');
    }
}
