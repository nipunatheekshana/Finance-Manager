<?php

namespace App\Enums;

enum AlertSeverity: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Critical = 'critical';
}
