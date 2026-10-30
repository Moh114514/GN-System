<?php

namespace App\Modules\Customer\Domain;

enum CustomerSourceType: string
{
    case Direct = 'direct';
    case Agent = 'agent';
}
