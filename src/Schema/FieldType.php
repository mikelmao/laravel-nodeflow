<?php

namespace Nodeflow\Schema;

enum FieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Select = 'select';
    case Multiselect = 'multiselect';
    case Duration = 'duration';

    public function baseRule(): string
    {
        return match ($this) {
            self::Number => 'numeric',
            self::Boolean => 'boolean',
            self::Multiselect => 'array',
            default => 'string',
        };
    }
}
