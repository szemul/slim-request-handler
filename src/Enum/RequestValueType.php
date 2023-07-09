<?php
declare(strict_types=1);

namespace Szemul\SlimRequestHandler\Enum;

enum RequestValueType: string
{
    case TYPE_STRING = 'string';
    case TYPE_INT    = 'int';
    case TYPE_FLOAT  = 'float';
    case TYPE_BOOL   = 'bool';
}
