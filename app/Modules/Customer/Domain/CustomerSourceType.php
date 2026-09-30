<?php

namespace App\Modules\Customer\Domain;

enum CustomerSourceType: string
{
    case Agent = 'agent';
    case Direct = 'direct';
}
