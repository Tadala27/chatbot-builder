<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CustomVariablesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $variables = [
            // Identity & Authentication
            'npNationalId' => ['data_type' => 'string', 'save_in' => 'user_property', 'description' => 'National ID'],
            'npPassword' => ['data_type' => 'string', 'save_in' => 'user_property', 'is_sensitive' => true, 'description' => 'Password'],

            // Member Informations
            'member' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member'],
            'member_Id' => ['data_type' => 'string', 'save_in' => 'user_property', 'description' => 'Member ID'],
            'MemberBeneficiaries' => ['data_type' => 'json', 'save_in' => 'user_property', 'description' => 'Member beneficiaries list'],
            'MemberClass' => ['data_type' => 'string', 'save_in' => 'user_property', 'description' => 'Member class/category'],
            'memberGender' => ['data_type' => 'string', 'save_in' => 'user_property', 'description' => 'Member gender'],
            'memberId' => ['data_type' => 'string', 'save_in' => 'user_property', 'description' => 'Member ID'],
            'memberName' => ['data_type' => 'string', 'save_in' => 'user_property', 'description' => 'Member name'],
            'memberNumber' => ['data_type' => 'string', 'save_in' => 'user_property', 'description' => 'Member number'],

            // Company Information
            'companyName' => ['data_type' => 'string', 'save_in' => 'user_property', 'description' => 'Company name'],

            // Employment Information
            'DoJ' => ['data_type' => 'date', 'save_in' => 'user_property', 'description' => 'Date of Joining'],
            'DoE' => ['data_type' => 'date', 'save_in' => 'user_property', 'description' => 'Date of Exit'],
            'servicePeriod' => ['data_type' => 'string', 'save_in' => 'user_property', 'description' => 'Service period'],

            // Schemes Information
            'numberofschemes' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'Number of schemes'],

            // Multiple Members Information
            'memberId1' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member ID 1'],
            'memberName1' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member Name 1'],
            'memberId2' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member ID 2'],
            'memberId3' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member ID 3'],
            'memberId4' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member ID 4'],
            'memberName2' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member Name 2'],
            'memberName3' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member Name 3'],
            'memberName4' => ['data_type' => 'string', 'save_in' => 'conversation', 'description' => 'Member Name 4'],

            // Account Balances
            'eebalance' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'Employee balance'],
            'erbalance' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'Employer balance'],
            'accountBalance' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'Total account balance'],
            'Contributionyear' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'Contribution year'],

            // January
            'januaryemployeecontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'January employee contribution'],
            'januaryemployercontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'January employer contribution'],
            'januarytotalcontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'January total contribution'],
            'januarydatepaid' => ['data_type' => 'date', 'save_in' => 'user_property', 'description' => 'January date paid'],

            // February
            'februaryemployeecontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'February employee contribution'],
            'februaryemployercontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'February employer contribution'],
            'februarytotalcontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'February total contribution'],
            'februarydatepaid' => ['data_type' => 'date', 'save_in' => 'user_property', 'description' => 'February date paid'],

            // March
            'marchemployeecontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'March employee contribution'],
            'marchemployercontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'March employer contribution'],
            'marchtotalcontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'March total contribution'],
            'marchdatepaid' => ['data_type' => 'date', 'save_in' => 'user_property', 'description' => 'March date paid'],

            // April
            'aprilemployeecontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'April employee contribution'],
            'aprilemployercontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'April employer contribution'],
            'apriltotalcontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'April total contribution'],
            'aprildatepaid' => ['data_type' => 'date', 'save_in' => 'user_property', 'description' => 'April date paid'],

            // May
            'mayemployeecontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'May employee contribution'],
            'mayemployercontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'May employer contribution'],
            'maytotalcontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'May total contribution'],
            'maydatepaid' => ['data_type' => 'date', 'save_in' => 'user_property', 'description' => 'May date paid'],

            // June
            'juneemployeecontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'June employee contribution'],
            'juneemployercontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'June employer contribution'],
            'junetotalcontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'June total contribution'],
            'junedatepaid' => ['data_type' => 'date', 'save_in' => 'user_property', 'description' => 'June date paid'],

            // July
            'julyemployeecontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'July employee contribution'],
            'julyemployercontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'July employer contribution'],
            'julytotalcontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'July total contribution'],
            'julydatepaid' => ['data_type' => 'date', 'save_in' => 'user_property', 'description' => 'July date paid'],

            // August
            'augustemployeecontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'August employee contribution'],
            'augustemployercontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'August employer contribution'],
            'augusttotalcontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'August total contribution'],
            'augustdatepaid' => ['data_type' => 'date', 'save_in' => 'user_property', 'description' => 'August date paid'],

            // September
            'septemberemployeecontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'September employee contribution'],
            'septemberemployercontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'September employer contribution'],
            'septembertotalcontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'September total contribution'],
            'septemberdatepaid' => ['data_type' => 'date', 'save_in' => 'user_property', 'description' => 'September date paid'],

            // October
            'octoberemployeecontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'October employee contribution'],
            'octoberemployercontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'October employer contribution'],
            'octobertotalcontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'October total contribution'],
            'octoberdatepaid' => ['data_type' => 'date', 'save_in' => 'user_property', 'description' => 'October date paid'],

            // November
            'novemberemployeecontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'November employee contribution'],
            'novemberemployercontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'November employer contribution'],
            'novembertotalcontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'November total contribution'],
            'novemberdatepaid' => ['data_type' => 'date', 'save_in' => 'user_property', 'description' => 'November date paid'],

            // December
            'decemberemployeecontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'December employee contribution'],
            'decemberemployercontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'December employer contribution'],
            'decembertotalcontribution' => ['data_type' => 'number', 'save_in' => 'user_property', 'description' => 'December total contribution'],
            'decemberdatepaid' => ['data_type' => 'date', 'save_in' => 'user_property', 'description' => 'December date paid'],
        ];

        $botId = 1;
        $now = now();

        foreach ($variables as $key => $config) {
            // Generate a display name from the key
            $name = preg_replace('/(?<=[a-z])(?=[A-Z])/u', ' ', $key);
            $name = ucfirst(str_replace('_', ' ', $name));

            DB::table('custom_variables')->insert([
                'bot_id' => $botId,
                'name' => $name,
                'key' => $key,
                'data_type' => $config['data_type'],
                'default_value' => null,
                'save_in' => $config['save_in'],
                'use_in_js' => false,
                'is_sensitive' => $config['is_sensitive'] ?? false,
                'description' => $config['description'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
