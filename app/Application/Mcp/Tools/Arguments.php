<?php

namespace App\Application\Mcp\Tools;

use App\Application\Mcp\McpToolException;
use Illuminate\Support\Facades\Validator;

/** Validates tool arguments with Laravel rules and turns failures into tool errors. */
final class Arguments
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $attributes  Names to use in messages, e.g. ['name' => 'title'].
     * @return array<string, mixed>
     */
    public static function validate(array $arguments, array $rules, array $attributes = []): array
    {
        $validator = Validator::make($arguments, $rules, [], $attributes);
        if ($validator->fails()) {
            throw new McpToolException(implode(' ', $validator->errors()->all()));
        }

        return $validator->validated();
    }
}
