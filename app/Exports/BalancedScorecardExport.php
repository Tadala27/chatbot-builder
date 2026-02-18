<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class BalancedScorecardExport implements WithMultipleSheets
{
    protected $perspectives;
    protected $metadata;

    public function __construct($perspectives, $metadata = [])
    {
        $this->perspectives = $perspectives;
        $this->metadata = $metadata;
    }

    public function sheets(): array
    {
        return [
            new TemplateInstructionsSheet(),
            new TemplateBalancedScorecardSheet($this->perspectives, $this->metadata),
        ];
    }
}

class TemplateInstructionsSheet implements FromCollection, WithTitle, WithStyles, WithColumnWidths, WithEvents
{
    public function collection()
    {
        return collect([
            ['', '', 'BSC Template: Full Scorecard Upload Instructions', '', '', '', ''],
            ['', '', '', '', '', '', ''],
            ['', '', 'How to Use This Template', '', '', '', ''],
            ['', '', '', '', '', '', ''],
            ['', '', 'Instructions', '', '', 'Important Notes', ''],
            ['', '', '1. Fill in all four perspectives in the Balanced Scorecard sheet', '', '', '1. Do not change the structure or column headers', ''],
            ['', '', '2. Each perspective should have its goals and objectives', '', '', '2. Goal weights will be distributed evenly within each perspective', ''],
            ['', '', '3. Upload the entire file - system will process all perspectives', '', '', '3. Objective weights must sum to 100% for each goal', ''],
            ['', '', '4. Provide clear, measurable objectives', '', '', '4. Use the dropdown values where indicated', ''],
            ['', '', '5. Set realistic target values', '', '', '5. Remove sample data before uploading', ''],
            ['', '', '', '', '', '', ''],
            ['', '', 'Target Type Options:', '', '', 'Objective Type Options:', ''],
            ['', '', '- Numeric (for counts, numbers)', '', '', '- Continuous (evaluated every quarter)', ''],
            ['', '', '- Percentage (for rates, ratios)', '', '', '- Absolute (one-time, specific quarter)', ''],
            ['', '', '- Currency/Monetary (for financial targets)', '', '', '', ''],
            ['', '', '- Yes/No (for binary outcomes)', '', '', '', ''],
            ['', '', '', '', '', '', ''],
            ['', '', 'Behaviour/Direction Options:', '', '', '', ''],
            ['', '', '- "The higher the Better" (for targets you want to maximize)', '', '', '', ''],
            ['', '', '- "The lower the Better" (for targets you want to minimize)', '', '', '', ''],
        ]);
    }

    public function title(): string
    {
        return 'Instructions';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 5,
            'B' => 5,
            'C' => 60,
            'D' => 5,
            'E' => 5,
            'F' => 60,
            'G' => 5,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Set background color
                $sheet->getStyle('A1:G20')->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F4F4F6']],
                ]);

                // Title
                $sheet->mergeCells('C1:F1');
                $sheet->getStyle('C1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '002060']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                // Section headers
                $sheet->mergeCells('C3:F3');
                $sheet->getStyle('C3')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '002060']],
                ]);

                // Column headers
                $sheet->getStyle('C5:F5')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E1F2']],
                ]);

                // Subsection headers
                foreach ([12, 18] as $row) {
                    $sheet->getStyle("C{$row}:F{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 11],
                    ]);
                }

                // Content
                $sheet->getStyle('C1:F20')->applyFromArray([
                    'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
                ]);
            },
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [];
    }
}

class TemplateBalancedScorecardSheet implements FromCollection, WithTitle, WithStyles, WithColumnWidths, WithEvents
{
    protected $perspectives;
    protected $metadata;

    public function __construct($perspectives, $metadata)
    {
        $this->perspectives = $perspectives;
        $this->metadata = $metadata;
    }

    public function collection()
    {
        $data = collect([
            ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', 'BALANCED SCORECARD - ALL PERSPECTIVES', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', 'Company Name:', '', '', $this->metadata['company_name'] ?? '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', 'Employee No.:', '', '', $this->metadata['employee_no'] ?? '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', 'Employee Name:', '', '', $this->metadata['employee_name'] ?? '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', 'Position:', '', '', $this->metadata['position'] ?? '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', 'Business Unit:', '', '', $this->metadata['business_unit'] ?? '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', 'Financial Year:', '', '', $this->metadata['financial_year'] ?? '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', '', '', '', '', 'Please remove sample data before uploading', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', 'Perspective', '', 'Perspective Weight(%)', '', 'Goals', '', 'Goal Weight(%)', '', 'Objectives', 'Objective Weight(%)', '', 'Initiatives', '', 'Objective Type', '', 'Target Type', '', 'Behaviour', '', 'Target'],
            ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
        ]);

        $startRow = 16; // Data starts at row 16

        // Add sample data for each perspective
        foreach ($this->perspectives as $index => $perspective) {
            $perspectiveName = $perspective['name'] ?? "Perspective " . ($index + 1);
            $perspectiveWeight = $perspective['weight'] ?? 25;
            $currentRow = $data->count() + 1; // Excel row number

            // Perspective row with goal
            $data->push([
                '',
                '',
                $perspectiveName,
                '',
                $perspectiveWeight,
                '',
                'Sample Goal 1',
                '',
                $perspectiveWeight, // Goal weight equals perspective weight initially
                '',
                'Sample Objective 1',
                $perspectiveWeight, // Objective weight equals goal weight initially
                '',
                'Initiative description',
                '',
                'Continuous',
                '',
                'Percentage',
                '',
                'The higher the Better',
                '',
                '75'
            ]);
            $data->push(['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '']);
            $data->push(['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '']);
        }

        // Add TOTALS row
        $totalRow = $data->count() + 1;
        $data->push([
            '',
            '',
            'TOTALS',
            '',
            '=SUM(E16:E' . ($totalRow - 1) . ')', // Sum all perspective weights
            '',
            '',
            '',
            '=SUM(I16:I' . ($totalRow - 1) . ')', // Sum all goal weights
            '',
            '',
            '=SUM(L16:L' . ($totalRow - 1) . ')', // Sum all objective weights
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            ''
        ]);

        return $data;
    }

    public function title(): string
    {
        return 'Balanced Scorecard';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 5,
            'B' => 5,
            'C' => 25,   // Perspective
            'D' => 5,
            'E' => 15,   // Perspective Weight
            'F' => 5,
            'G' => 30,   // Goals
            'H' => 5,
            'I' => 12,   // Goal Weight
            'J' => 5,
            'K' => 35,   // Objectives
            'L' => 12,   // Objective Weight
            'M' => 5,
            'N' => 40,   // Initiatives
            'O' => 5,
            'P' => 20,   // Objective Type
            'Q' => 5,
            'R' => 20,   // Target Type
            'S' => 5,
            'T' => 25,   // Behaviour
            'U' => 5,
            'V' => 15,   // Target
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Header
                $sheet->mergeCells('C3:V3');
                $sheet->getStyle('C3')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '002060']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                // Metadata rows
                foreach (range(5, 10) as $row) {
                    $sheet->getStyle("C{$row}:D{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '002060']],
                    ]);
                    $sheet->mergeCells("E{$row}:J{$row}");
                }

                // Notice
                $sheet->mergeCells('G13:J13');
                $sheet->getStyle('G13')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FF0000']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                // Column headers
                $headers = ['C14', 'E14', 'G14', 'I14', 'K14', 'L14', 'N14', 'P14', 'R14', 'T14', 'V14'];
                foreach ($headers as $header) {
                    $sheet->getStyle($header)->applyFromArray([
                        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                    ]);
                }

                // Data rows - add light borders
                $lastRow = $sheet->getHighestRow();
                $sheet->getStyle("C16:V{$lastRow}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D0D0D0']]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                ]);

                // Perspective cells
                for ($row = 16; $row <= $lastRow; $row += 3) {
                    $sheet->getStyle("C{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 12],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFEB9C']],
                    ]);
                    // Highlight perspective weight cell
                    $sheet->getStyle("E{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 11],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    ]);
                }

                // Add data validation dropdowns
                $this->addDropdownValidations($sheet, $lastRow);

                // Add conditional formatting for weight validation
                $this->addWeightValidation($sheet, $lastRow);
            },
        ];
    }

    /**
     * Add dropdown data validations
     */
    private function addDropdownValidations($sheet, $lastRow)
    {
        // Objective Type dropdown (Column P, rows 16 onwards)
        $objectiveTypeValidation = $sheet->getCell('P16')->getDataValidation();
        $objectiveTypeValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $objectiveTypeValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_INFORMATION);
        $objectiveTypeValidation->setAllowBlank(false);
        $objectiveTypeValidation->setShowInputMessage(true);
        $objectiveTypeValidation->setShowErrorMessage(true);
        $objectiveTypeValidation->setShowDropDown(true);
        $objectiveTypeValidation->setErrorTitle('Invalid Input');
        $objectiveTypeValidation->setError('Please select a value from the dropdown');
        $objectiveTypeValidation->setPromptTitle('Objective Type');
        $objectiveTypeValidation->setPrompt('Select the type of objective');
        $objectiveTypeValidation->setFormula1('"Continuous,Absolute - Q1,Absolute - Q2,Absolute - Q3,Absolute - Q4"');

        // Apply to all data rows in column P
        for ($row = 16; $row <= $lastRow; $row++) {
            $sheet->getCell("P{$row}")->setDataValidation(clone $objectiveTypeValidation);
        }

        // Target Type dropdown (Column R, rows 16 onwards)
        $targetTypeValidation = $sheet->getCell('R16')->getDataValidation();
        $targetTypeValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $targetTypeValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_INFORMATION);
        $targetTypeValidation->setAllowBlank(false);
        $targetTypeValidation->setShowInputMessage(true);
        $targetTypeValidation->setShowErrorMessage(true);
        $targetTypeValidation->setShowDropDown(true);
        $targetTypeValidation->setErrorTitle('Invalid Input');
        $targetTypeValidation->setError('Please select a value from the dropdown');
        $targetTypeValidation->setPromptTitle('Target Type');
        $targetTypeValidation->setPrompt('Select the type of target measurement');
        $targetTypeValidation->setFormula1('"Numeric,Percentage,Currency/Monetary,Yes/No"');

        // Apply to all data rows in column R
        for ($row = 16; $row <= $lastRow; $row++) {
            $sheet->getCell("R{$row}")->setDataValidation(clone $targetTypeValidation);
        }

        // Behaviour dropdown (Column T, rows 16 onwards)
        $behaviourValidation = $sheet->getCell('T16')->getDataValidation();
        $behaviourValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $behaviourValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_INFORMATION);
        $behaviourValidation->setAllowBlank(false);
        $behaviourValidation->setShowInputMessage(true);
        $behaviourValidation->setShowErrorMessage(true);
        $behaviourValidation->setShowDropDown(true);
        $behaviourValidation->setErrorTitle('Invalid Input');
        $behaviourValidation->setError('Please select a value from the dropdown');
        $behaviourValidation->setPromptTitle('Behaviour');
        $behaviourValidation->setPrompt('Select the target direction');
        $behaviourValidation->setFormula1('"The higher the Better,The lower the Better"');

        // Apply to all data rows in column T
        for ($row = 16; $row <= $lastRow; $row++) {
            $sheet->getCell("T{$row}")->setDataValidation(clone $behaviourValidation);
        }
    }

    /**
     * Add conditional formatting for weight validation
     */
    private function addWeightValidation($sheet, $lastRow)
    {
        // Style the TOTALS row
        $totalRow = $lastRow;
        $sheet->getStyle("C{$totalRow}:V{$totalRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E1F2']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM]],
        ]);

        // Highlight the total weight cells
        foreach (['E', 'I', 'L'] as $col) {
            $sheet->getStyle("{$col}{$totalRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FF0000']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFF00']],
            ]);
        }

        // Add comments to weight columns explaining the validation rules

        // For Perspective Weight column (E) - add comment to header
        $sheet->getComment('E14')
            ->getText()
            ->createTextRun(
                "⚠️ VALIDATION RULE:\n\n" .
                    "All perspective weights MUST add up to 100%\n\n" .
                    "Check the TOTALS row to verify."
            );
        $sheet->getComment('E14')->setWidth('300pt');
        $sheet->getComment('E14')->setHeight('100pt');

        // For Goal Weight column (I) - add comment to header
        $sheet->getComment('I14')
            ->getText()
            ->createTextRun(
                "⚠️ VALIDATION RULE:\n\n" .
                    "Goals under each perspective must add up to that perspective's weight\n\n" .
                    "Example: If perspective = 40%, all its goals = 40%\n\n" .
                    "Add more goal rows below each perspective to distribute the weight."
            );
        $sheet->getComment('I14')->setWidth('350pt');
        $sheet->getComment('I14')->setHeight('120pt');

        // For Objective Weight column (L) - add comment to header
        $sheet->getComment('L14')
            ->getText()
            ->createTextRun(
                "⚠️ VALIDATION RULE:\n\n" .
                    "Objectives under each goal must add up to that goal's weight\n\n" .
                    "Example: If goal = 20%, all its objectives = 20%\n\n" .
                    "Add more objective rows below each goal to distribute the weight."
            );
        $sheet->getComment('L14')->setWidth('350pt');
        $sheet->getComment('L14')->setHeight('120pt');

        // Add background colors to weight columns for visual guidance
        for ($row = 16; $row < $totalRow; $row++) {
            // Perspective weight cells (only every 3rd row has perspective)
            if (($row - 16) % 3 === 0) {
                $sheet->getStyle("E{$row}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']],
                ]);
            }

            // Goal weight cells
            $sheet->getStyle("I{$row}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E7E6E6']],
            ]);

            // Objective weight cells
            if ($sheet->getCell("K{$row}")->getValue()) {
                $sheet->getStyle("L{$row}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']],
                ]);
            }
        }
    }

    public function styles(Worksheet $sheet)
    {
        return [];
    }
}
