<?php

namespace Database\Seeders;

use App\Models\BuiltInFunction;
use Illuminate\Database\Seeder;

class BuiltInFunctionsSeeder extends Seeder
{
    public function run(): void
    {
        $functions = [
            // Date/Time Functions
            [
                'name' => 'now',
                'category' => 'date_time',
                'description' => 'Returns the current date and time',
                'syntax' => 'now()',
                'parameters' => [],
                'return_type' => 'datetime',
                'examples' => [
                    ['input' => 'now()', 'output' => '2026-02-15 10:30:00']
                ],
            ],
            [
                'name' => 'today',
                'category' => 'date_time',
                'description' => 'Returns the current date',
                'syntax' => 'today()',
                'parameters' => [],
                'return_type' => 'date',
                'examples' => [
                    ['input' => 'today()', 'output' => '2026-02-15']
                ],
            ],
            [
                'name' => 'addDays',
                'category' => 'date_time',
                'description' => 'Adds specified number of days to a date',
                'syntax' => 'addDays(date, days)',
                'parameters' => ['date', 'days'],
                'return_type' => 'date',
                'examples' => [
                    ['input' => 'addDays("2026-02-15", 5)', 'output' => '2026-02-20']
                ],
            ],
            [
                'name' => 'formatDate',
                'category' => 'date_time',
                'description' => 'Formats a date according to the specified format',
                'syntax' => 'formatDate(date, format)',
                'parameters' => ['date', 'format'],
                'return_type' => 'string',
                'examples' => [
                    ['input' => 'formatDate("2026-02-15", "F j, Y")', 'output' => 'February 15, 2026']
                ],
            ],
            [
                'name' => 'dayOfWeek',
                'category' => 'date_time',
                'description' => 'Returns the day of the week for a date',
                'syntax' => 'dayOfWeek(date)',
                'parameters' => ['date'],
                'return_type' => 'string',
                'examples' => [
                    ['input' => 'dayOfWeek("2026-02-15")', 'output' => 'Sunday']
                ],
            ],

            // String Functions
            [
                'name' => 'upper',
                'category' => 'string',
                'description' => 'Converts text to uppercase',
                'syntax' => 'upper(text)',
                'parameters' => ['text'],
                'return_type' => 'string',
                'examples' => [
                    ['input' => 'upper("hello")', 'output' => 'HELLO']
                ],
            ],
            [
                'name' => 'lower',
                'category' => 'string',
                'description' => 'Converts text to lowercase',
                'syntax' => 'lower(text)',
                'parameters' => ['text'],
                'return_type' => 'string',
                'examples' => [
                    ['input' => 'lower("HELLO")', 'output' => 'hello']
                ],
            ],
            [
                'name' => 'capitalize',
                'category' => 'string',
                'description' => 'Capitalizes the first letter of text',
                'syntax' => 'capitalize(text)',
                'parameters' => ['text'],
                'return_type' => 'string',
                'examples' => [
                    ['input' => 'capitalize("hello world")', 'output' => 'Hello world']
                ],
            ],
            [
                'name' => 'titleCase',
                'category' => 'string',
                'description' => 'Converts text to title case',
                'syntax' => 'titleCase(text)',
                'parameters' => ['text'],
                'return_type' => 'string',
                'examples' => [
                    ['input' => 'titleCase("hello world")', 'output' => 'Hello World']
                ],
            ],
            [
                'name' => 'trim',
                'category' => 'string',
                'description' => 'Removes whitespace from both ends of text',
                'syntax' => 'trim(text)',
                'parameters' => ['text'],
                'return_type' => 'string',
                'examples' => [
                    ['input' => 'trim("  hello  ")', 'output' => 'hello']
                ],
            ],
            [
                'name' => 'length',
                'category' => 'string',
                'description' => 'Returns the length of text',
                'syntax' => 'length(text)',
                'parameters' => ['text'],
                'return_type' => 'number',
                'examples' => [
                    ['input' => 'length("hello")', 'output' => '5']
                ],
            ],
            [
                'name' => 'substring',
                'category' => 'string',
                'description' => 'Extracts a portion of text',
                'syntax' => 'substring(text, start, length)',
                'parameters' => ['text', 'start', 'length'],
                'return_type' => 'string',
                'examples' => [
                    ['input' => 'substring("hello world", 0, 5)', 'output' => 'hello']
                ],
            ],
            [
                'name' => 'replace',
                'category' => 'string',
                'description' => 'Replaces occurrences of a substring',
                'syntax' => 'replace(text, find, replace)',
                'parameters' => ['text', 'find', 'replace'],
                'return_type' => 'string',
                'examples' => [
                    ['input' => 'replace("hello world", "world", "there")', 'output' => 'hello there']
                ],
            ],
            [
                'name' => 'contains',
                'category' => 'string',
                'description' => 'Checks if text contains a substring',
                'syntax' => 'contains(text, substring)',
                'parameters' => ['text', 'substring'],
                'return_type' => 'boolean',
                'examples' => [
                    ['input' => 'contains("hello world", "world")', 'output' => 'true']
                ],
            ],
            [
                'name' => 'startsWith',
                'category' => 'string',
                'description' => 'Checks if text starts with a prefix',
                'syntax' => 'startsWith(text, prefix)',
                'parameters' => ['text', 'prefix'],
                'return_type' => 'boolean',
                'examples' => [
                    ['input' => 'startsWith("hello world", "hello")', 'output' => 'true']
                ],
            ],
            [
                'name' => 'endsWith',
                'category' => 'string',
                'description' => 'Checks if text ends with a suffix',
                'syntax' => 'endsWith(text, suffix)',
                'parameters' => ['text', 'suffix'],
                'return_type' => 'boolean',
                'examples' => [
                    ['input' => 'endsWith("hello world", "world")', 'output' => 'true']
                ],
            ],
            [
                'name' => 'split',
                'category' => 'string',
                'description' => 'Splits text into an array',
                'syntax' => 'split(text, delimiter)',
                'parameters' => ['text', 'delimiter'],
                'return_type' => 'array',
                'examples' => [
                    ['input' => 'split("a,b,c", ",")', 'output' => '["a", "b", "c"]']
                ],
            ],
            [
                'name' => 'join',
                'category' => 'string',
                'description' => 'Joins array elements into a string',
                'syntax' => 'join(array, delimiter)',
                'parameters' => ['array', 'delimiter'],
                'return_type' => 'string',
                'examples' => [
                    ['input' => 'join(["a", "b", "c"], ",")', 'output' => 'a,b,c']
                ],
            ],

            // Logical Functions
            [
                'name' => 'if',
                'category' => 'logical',
                'description' => 'Returns one of two values based on a condition',
                'syntax' => 'if(condition, trueValue, falseValue)',
                'parameters' => ['condition', 'trueValue', 'falseValue'],
                'return_type' => 'any',
                'examples' => [
                    ['input' => 'if(true, "yes", "no")', 'output' => 'yes']
                ],
            ],
            [
                'name' => 'isEmpty',
                'category' => 'logical',
                'description' => 'Checks if a value is empty',
                'syntax' => 'isEmpty(value)',
                'parameters' => ['value'],
                'return_type' => 'boolean',
                'examples' => [
                    ['input' => 'isEmpty("")', 'output' => 'true']
                ],
            ],
            [
                'name' => 'isNotEmpty',
                'category' => 'logical',
                'description' => 'Checks if a value is not empty',
                'syntax' => 'isNotEmpty(value)',
                'parameters' => ['value'],
                'return_type' => 'boolean',
                'examples' => [
                    ['input' => 'isNotEmpty("hello")', 'output' => 'true']
                ],
            ],
            [
                'name' => 'and',
                'category' => 'logical',
                'description' => 'Returns true if all conditions are true',
                'syntax' => 'and(condition1, condition2)',
                'parameters' => ['condition1', 'condition2'],
                'return_type' => 'boolean',
                'examples' => [
                    ['input' => 'and(true, true)', 'output' => 'true']
                ],
            ],
            [
                'name' => 'or',
                'category' => 'logical',
                'description' => 'Returns true if any condition is true',
                'syntax' => 'or(condition1, condition2)',
                'parameters' => ['condition1', 'condition2'],
                'return_type' => 'boolean',
                'examples' => [
                    ['input' => 'or(true, false)', 'output' => 'true']
                ],
            ],
            [
                'name' => 'not',
                'category' => 'logical',
                'description' => 'Inverts a boolean value',
                'syntax' => 'not(condition)',
                'parameters' => ['condition'],
                'return_type' => 'boolean',
                'examples' => [
                    ['input' => 'not(true)', 'output' => 'false']
                ],
            ],

            // Formatting Functions
            [
                'name' => 'formatNumber',
                'category' => 'formatting',
                'description' => 'Formats a number with decimals',
                'syntax' => 'formatNumber(number, decimals)',
                'parameters' => ['number', 'decimals'],
                'return_type' => 'string',
                'examples' => [
                    ['input' => 'formatNumber(1234.567, 2)', 'output' => '1,234.57']
                ],
            ],
            [
                'name' => 'formatCurrency',
                'category' => 'formatting',
                'description' => 'Formats a number as currency',
                'syntax' => 'formatCurrency(number)',
                'parameters' => ['number'],
                'return_type' => 'string',
                'examples' => [
                    ['input' => 'formatCurrency(1234.56)', 'output' => '$1,234.56']
                ],
            ],
            [
                'name' => 'formatPhone',
                'category' => 'formatting',
                'description' => 'Formats a phone number',
                'syntax' => 'formatPhone(phoneNumber)',
                'parameters' => ['phoneNumber'],
                'return_type' => 'string',
                'examples' => [
                    ['input' => 'formatPhone("1234567890")', 'output' => '(123) 456-7890']
                ],
            ],
            [
                'name' => 'formatPercentage',
                'category' => 'formatting',
                'description' => 'Formats a number as a percentage',
                'syntax' => 'formatPercentage(number)',
                'parameters' => ['number'],
                'return_type' => 'string',
                'examples' => [
                    ['input' => 'formatPercentage(0.85)', 'output' => '85%']
                ],
            ],

            // Math Functions
            [
                'name' => 'add',
                'category' => 'math',
                'description' => 'Adds two numbers',
                'syntax' => 'add(a, b)',
                'parameters' => ['a', 'b'],
                'return_type' => 'number',
                'examples' => [
                    ['input' => 'add(5, 3)', 'output' => '8']
                ],
            ],
            [
                'name' => 'subtract',
                'category' => 'math',
                'description' => 'Subtracts two numbers',
                'syntax' => 'subtract(a, b)',
                'parameters' => ['a', 'b'],
                'return_type' => 'number',
                'examples' => [
                    ['input' => 'subtract(10, 3)', 'output' => '7']
                ],
            ],
            [
                'name' => 'multiply',
                'category' => 'math',
                'description' => 'Multiplies two numbers',
                'syntax' => 'multiply(a, b)',
                'parameters' => ['a', 'b'],
                'return_type' => 'number',
                'examples' => [
                    ['input' => 'multiply(4, 5)', 'output' => '20']
                ],
            ],
            [
                'name' => 'divide',
                'category' => 'math',
                'description' => 'Divides two numbers',
                'syntax' => 'divide(a, b)',
                'parameters' => ['a', 'b'],
                'return_type' => 'number',
                'examples' => [
                    ['input' => 'divide(10, 2)', 'output' => '5']
                ],
            ],
            [
                'name' => 'round',
                'category' => 'math',
                'description' => 'Rounds a number to specified decimal places',
                'syntax' => 'round(number, decimals)',
                'parameters' => ['number', 'decimals'],
                'return_type' => 'number',
                'examples' => [
                    ['input' => 'round(3.14159, 2)', 'output' => '3.14']
                ],
            ],
            [
                'name' => 'floor',
                'category' => 'math',
                'description' => 'Rounds a number down to the nearest integer',
                'syntax' => 'floor(number)',
                'parameters' => ['number'],
                'return_type' => 'number',
                'examples' => [
                    ['input' => 'floor(3.7)', 'output' => '3']
                ],
            ],
            [
                'name' => 'ceil',
                'category' => 'math',
                'description' => 'Rounds a number up to the nearest integer',
                'syntax' => 'ceil(number)',
                'parameters' => ['number'],
                'return_type' => 'number',
                'examples' => [
                    ['input' => 'ceil(3.2)', 'output' => '4']
                ],
            ],
            [
                'name' => 'min',
                'category' => 'math',
                'description' => 'Returns the smallest of the given numbers',
                'syntax' => 'min(numbers...)',
                'parameters' => ['numbers...'],
                'return_type' => 'number',
                'examples' => [
                    ['input' => 'min(5, 2, 8, 1)', 'output' => '1']
                ],
            ],
            [
                'name' => 'max',
                'category' => 'math',
                'description' => 'Returns the largest of the given numbers',
                'syntax' => 'max(numbers...)',
                'parameters' => ['numbers...'],
                'return_type' => 'number',
                'examples' => [
                    ['input' => 'max(5, 2, 8, 1)', 'output' => '8']
                ],
            ],
            [
                'name' => 'random',
                'category' => 'math',
                'description' => 'Generates a random number between min and max',
                'syntax' => 'random(min, max)',
                'parameters' => ['min', 'max'],
                'return_type' => 'number',
                'examples' => [
                    ['input' => 'random(1, 10)', 'output' => '7 (example)']
                ],
            ],

            // Array Functions
            [
                'name' => 'arrayLength',
                'category' => 'array',
                'description' => 'Returns the number of elements in an array',
                'syntax' => 'arrayLength(array)',
                'parameters' => ['array'],
                'return_type' => 'number',
                'examples' => [
                    ['input' => 'arrayLength([1, 2, 3])', 'output' => '3']
                ],
            ],
            [
                'name' => 'first',
                'category' => 'array',
                'description' => 'Returns the first element of an array',
                'syntax' => 'first(array)',
                'parameters' => ['array'],
                'return_type' => 'any',
                'examples' => [
                    ['input' => 'first([1, 2, 3])', 'output' => '1']
                ],
            ],
            [
                'name' => 'last',
                'category' => 'array',
                'description' => 'Returns the last element of an array',
                'syntax' => 'last(array)',
                'parameters' => ['array'],
                'return_type' => 'any',
                'examples' => [
                    ['input' => 'last([1, 2, 3])', 'output' => '3']
                ],
            ],
            [
                'name' => 'indexOf',
                'category' => 'array',
                'description' => 'Returns the index of an element in an array',
                'syntax' => 'indexOf(array, item)',
                'parameters' => ['array', 'item'],
                'return_type' => 'number',
                'examples' => [
                    ['input' => 'indexOf([1, 2, 3], 2)', 'output' => '1']
                ],
            ],
        ];

        foreach ($functions as $function) {
            BuiltInFunction::create($function);
        }
    }
}