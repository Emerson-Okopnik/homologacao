<?php

namespace App\Domain\Rules\Enums;

enum DecisionType: string
{
    case Classification = 'CLASSIFICATION';
    case FastTrack = 'FAST_TRACK';
    case Requirements = 'REQUIREMENTS';
    case Deadline = 'DEADLINE';
}
