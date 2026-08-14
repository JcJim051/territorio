<?php

namespace App\Enums;

enum CalendarPublicationResult: string
{
    case Confirmed = 'confirmed';
    case TerminalNoop = 'terminal_noop';
}
