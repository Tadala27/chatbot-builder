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

class PaymentsExport implements FromCollection, WithHeadings, WithStyles, WithColumnWidths, WithTitle
{
    protected $payments;
    protected $metadata;
    protected $totals;

    public function __construct($payments, $metadata, $totals)
    {
        $this->payments = $payments;
        $this->metadata = $metadata;
        $this->totals = $totals;
    }

    public function collection()
    {
        $data = $this->payments->map(function ($payment) {
            return [
                // Transaction (Receipt + Date)
                ($payment->receipt_number ?: 'N/A') . "\n" . $payment->transaction_date->format('d M Y'),

                // Shareholder (Name + Member Number)
                $payment->shareholder->user->first_name . ' ' . $payment->shareholder->user->last_name . "\n" .
                    $payment->shareholder->member_number,

                // Amount
                $payment->amount,

                // Payment Type
                $payment->payment_type,

                // Payment Method (with reference if available)
                $payment->payment_method .
                    ($payment->bank_reference ? "\nRef: " . $payment->bank_reference : ''),

                // Status
                $payment->is_verified ? 'Verified' : 'Pending',
            ];
        });

        // Add totals row
        $data->push([
            'TOTALS',
            $this->totals['count'] . ' Payments',
            $this->totals['total_amount'],
            '',
            '',
            '',
        ]);

        return $data;
    }

    public function headings(): array
    {
        $headers = [];

        // Title
        $headers[] = ['Payment History Report', '', '', '', '', ''];

        // Period / Financial Year info
        if (isset($this->metadata['financial_year']) && !empty($this->metadata['financial_year']['name'])) {
            $fy = $this->metadata['financial_year'];
            $periodText = 'Financial Year: ' . $fy['name'];
            if (!empty($fy['start_date']) && !empty($fy['end_date'])) {
                $periodText .= ' (' . $fy['start_date'] . ' - ' . $fy['end_date'] . ')';
            }
            $headers[] = [$periodText, '', '', '', '', ''];
        } elseif (isset($this->metadata['date_range']) && $this->metadata['date_range']) {
            $headers[] = ['Period: ' . $this->metadata['date_range'], '', '', '', '', ''];
        }

        // Blank row
        $headers[] = ['', '', '', '', '', ''];

        // Column headers
        $headers[] = [
            'TRANSACTION',
            'SHAREHOLDER',
            'AMOUNT',
            'TYPE',
            'METHOD',
            'STATUS'
        ];

        return $headers;
    }

    public function styles(Worksheet $sheet)
    {
        $hasPeriod = isset($this->metadata['financial_year']) ||
            (isset($this->metadata['date_range']) && $this->metadata['date_range']);
        $headerRow = $hasPeriod ? 4 : 3;
        $dataStartRow = $headerRow + 1;
        $dataEndRow = $dataStartRow + $this->payments->count();
        $totalsRow = $dataEndRow + 1;

        // Title
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1976D2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // Period info
        if ($hasPeriod) {
            $sheet->mergeCells('A2:F2');
            $sheet->getStyle('A2')->applyFromArray([
                'font' => ['size' => 10, 'color' => ['rgb' => '666666']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }

        // Header row
        $sheet->getStyle('A' . $headerRow . ':F' . $headerRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '666666']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F5F5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(30);

        // Data rows
        for ($row = $dataStartRow; $row < $totalsRow; $row++) {
            $sheet->getStyle('A' . $row . ':F' . $row)->getAlignment()->setWrapText(true);
            $sheet->getStyle('A' . $row . ':F' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]],
            ]);

            // Amount column (green, bold)
            $sheet->getStyle('C' . $row)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '4CAF50']],
                'numberFormat' => ['formatCode' => '_("MWK"* #,##0.00_);_("MWK"* (#,##0.00);_("MWK"* "-"??_);_(@_)']
            ]);

            $sheet->getRowDimension($row)->setRowHeight(40);
        }

        // Totals row
        $sheet->getStyle('A' . $totalsRow . ':F' . $totalsRow)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F5F5']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]],
        ]);

        $sheet->getStyle('C' . $totalsRow)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '4CAF50']],
            'numberFormat' => ['formatCode' => '_("MWK"* #,##0.00_);_("MWK"* (#,##0.00);_("MWK"* "-"??_);_(@_)']
        ]);

        return [];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 18,
            'B' => 22,
            'C' => 13,
            'D' => 18,
            'E' => 18,
            'F' => 12,
        ];
    }

    public function title(): string
    {
        return 'Payments';
    }
}
