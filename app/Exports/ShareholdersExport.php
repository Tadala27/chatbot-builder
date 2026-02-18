<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class ShareholdersExport implements FromCollection, WithHeadings, WithStyles, WithColumnWidths, WithTitle
{
    protected $shareholders;
    protected $period;        // ← Changed from $dateRange
    protected $totals;

    public function __construct($shareholders, $period, $totals)
    {
        $this->shareholders = $shareholders;
        $this->period = $period;           // ← Now receives full period string
        $this->totals = $totals;
    }

    public function collection()
    {
        $data = $this->shareholders->map(function ($shareholder) {
            return [
                $shareholder->member_number . "\n" .
                    $shareholder->user->first_name . ' ' . $shareholder->user->last_name,

                $shareholder->user->email . "\n" .
                    ($shareholder->user->phone ?: 'N/A'),

                $shareholder->totals ? $shareholder->totals->total_contributions : 0,

                $shareholder->share_units ?: 0,

                $shareholder->share_value ?: 0,

                $shareholder->status,

                date('d M Y', strtotime($shareholder->date_joined)),
            ];
        });

        $data->push([
            'TOTALS',
            $this->totals['count'] . ' Shareholders',
            $this->totals['total_contributions'],
            '',
            $this->totals['total_share_value'],
            'Active: ' . $this->totals['active'],
            '',
        ]);

        return $data;
    }

    public function headings(): array
    {
        $headers = [];

        // Title
        $headers[] = ['Shareholders Report', '', '', '', '', '', ''];

        // Period info (All FY, specific FY, date range, or month)
        if ($this->period) {
            $headers[] = ['Period: ' . $this->period, '', '', '', '', '', ''];
        }

        // Blank row
        $headers[] = ['', '', '', '', '', '', ''];

        // Column headers
        $headers[] = [
            'MEMBER',
            'CONTACT',
            'CONTRIBUTIONS',
            'SHARE UNITS',
            'SHARE VALUE',
            'STATUS',
            'DATE JOINED'
        ];

        return $headers;
    }

    public function styles(Worksheet $sheet)
    {
        $headerRow = $this->period ? 4 : 3;  // ← Use $this->period
        $dataStartRow = $headerRow + 1;
        $dataEndRow = $dataStartRow + $this->shareholders->count();
        $totalsRow = $dataEndRow + 1;

        // Title
        $sheet->mergeCells('A1:G1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1976D2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // Period row
        if ($this->period) {
            $sheet->mergeCells('A2:G2');
            $sheet->getStyle('A2')->applyFromArray([
                'font' => ['size' => 10, 'color' => ['rgb' => '666666']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }

        // Header row
        $sheet->getStyle('A' . $headerRow . ':G' . $headerRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '666666']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F5F5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(30);

        // Data rows
        for ($row = $dataStartRow; $row < $totalsRow; $row++) {
            $sheet->getStyle('A' . $row . ':G' . $row)->getAlignment()->setWrapText(true);
            $sheet->getStyle('A' . $row . ':G' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getStyle('A' . $row . ':G' . $row)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]],
            ]);

            // Contributions column (green, bold)
            $sheet->getStyle('C' . $row)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '4CAF50']],
                'numberFormat' => ['formatCode' => '_("MWK"* #,##0.00_);_("MWK"* (#,##0.00);_("MWK"* "-"??_);_(@_)']
            ]);

            // Share Value column (currency)
            $sheet->getStyle('E' . $row)->applyFromArray([
                'numberFormat' => ['formatCode' => '_("MWK"* #,##0.00_);_("MWK"* (#,##0.00);_("MWK"* "-"??_);_(@_)']
            ]);

            $sheet->getRowDimension($row)->setRowHeight(40);
        }

        // Totals row
        $sheet->getStyle('A' . $totalsRow . ':G' . $totalsRow)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F5F5']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]],
        ]);

        $sheet->getStyle('C' . $totalsRow)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '4CAF50']],
            'numberFormat' => ['formatCode' => '_("MWK"* #,##0.00_);_("MWK"* (#,##0.00);_("MWK"* "-"??_);_(@_)']
        ]);

        $sheet->getStyle('E' . $totalsRow)->applyFromArray([
            'font' => ['bold' => true],
            'numberFormat' => ['formatCode' => '_("MWK"* #,##0.00_);_("MWK"* (#,##0.00);_("MWK"* "-"??_);_(@_)']
        ]);

        return [];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 22,
            'B' => 25,
            'C' => 15,
            'D' => 12,
            'E' => 15,
            'F' => 12,
            'G' => 15,
        ];
    }

    public function title(): string
    {
        return 'Shareholders';
    }
}
