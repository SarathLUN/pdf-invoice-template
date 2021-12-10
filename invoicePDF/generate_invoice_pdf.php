<?php

date_default_timezone_set('Asia/Bangkok');
ini_set("memory_limit", "1024M");
parse_str($argv[1], $output);
//print_r($output);
$base_path = $output['path'];
//echo $base_path;
$current_day = $output['day'];

// include write log function
include('/opt/csm/report/ISOreport/audit_log.php');

//create function
function toMoney($val, $symbol = '$', $r = 2)
{
    $n = $val;
    $c = is_float($n) ? 1 : number_format((float)$n, $r);
    $d = '.';
    $t = ',';
    $sign = ($n < 0) ? '-' : '';
    $i = $n = number_format(abs($n), $r);
    $j = (($j = strlen($i)) > 3) ? $j % 3 : 0;
    return $symbol . $sign . ($j ? substr($i, 0, $j) + $t : '') . preg_replace('/(\d{3})(?=\d)/', "$1" + $t, substr($i, $j));
}

function toMoney_format($val, $symbol = '', $r = 2)
{
    $n = $val;
    $c = is_float($n) ? 1 : number_format((float)$n, $r);
    $d = '.';
    $t = ',';
    $sign = ($n < 0) ? '-' : '';
    $i = $n = number_format(abs($n), $r);
    $j = (($j = strlen($i)) > 3) ? $j % 3 : 0;
    return $symbol . $sign . ($j ? substr($i, 0, $j) + $t : '') . preg_replace('/(\d{3})(?=\d)/', "$1" + $t, substr($i, $j));
}

// start DB connection
$server = 'hostname';
$mydb = 'database';
// Connect to MSSQL
//start function generate invoice PDF
$link = mssql_connect($server, 'username', 'password');
$selected = mssql_select_db($mydb, $link) or die("Couldn't open database $mydb");

// 1. Daily Role Over: call function to generate PDF invoice
// query invoice to loop in here

//$q_daily_invoice = 'exec dp_xt_list_invoices_to_send;';
$q_daily_invoice = "exec dp_xt_list_invoices_to_pdf '" . $current_day . "'"; // for production
//$q_daily_invoice = "exec dp_xt_list_invoices_to_pdf '2020.06.06'"; // for testing
$daily_invoice = mssql_query($q_daily_invoice);

//print_r(mssql_num_rows($daily_invoice));
//exit();
$gen_inv = 0;
while ($row = mssql_fetch_array($daily_invoice)) {
    $invoice_number = $row[0];
    $customer_id = $row[1];
    $template = $row[6];
    echo $customer_id . "_" . $invoice_number . ".pdf\n";
    generatePDFinvoice($customer_id, $invoice_number, $template, $base_path);

    // tony: write log on each invoice:
    $q = "exec wc_invoice_print " . $invoice_number . ";";
    $r = mssql_query($q);
    $inv_detail = mssql_fetch_array($r);

    if (file_exists($base_path .date_create_from_format("Y.m.d", $current_day)->format("Y-m-d").'_'. $invoice_number . '.pdf')) {
        $log_content = time() . ",generate retail-enterprise PDF invoice," . $customer_id . "," . $invoice_number . ",\$" . $inv_detail['value'] . ",1,success";
        $gen_inv = $gen_inv + 1;
    } else {
        $log_content = time() . ",generate retail-enterprise PDF invoice," . $customer_id . "," . $invoice_number . ",\$" . $inv_detail['value'] . ",0,fail";
    }

    write_log($log_content, $base_path, $current_day);
}

// after all summary the result
$sum_log = "---> There are " . $gen_inv . "/" . mssql_num_rows($daily_invoice) . " retail-enterprise invoices have been generated as PDF <---\n";
write_log($sum_log, $base_path, $current_day);
//echo $sum_log;

// 2. specify invoice to re-generate PDF file
//generatePDFinvoice(19440, 504171, 'gpon', '../');
//echo "done\n";
//exit();

//create function to generate PDF invoice
function generatePDFinvoice($varCustomerID, $varInvoiceNumber, $template, $target_dir)
{
    // start query data
    //declare the SQL statement that will query the database
    //$query = "SELECT * FROM dbo.account where id between 201 and 213";
    $query = "exec invoice_print_balancika " . $varInvoiceNumber . ",2,1," . $varCustomerID . ";";

    $customer_name_in_khmer = "exec wc_get_cust_name_khmer " . $varCustomerID . ";";
    $customer_name_in_english = "exec wc_invoice_get " . $varInvoiceNumber . ";";// invoice number;
    $cus_add_khmer = "exec wc_get_cust_addr_khmer " . $varCustomerID . ";";// customer_id;
    $net_total_in_kh = "exec wc_invoice_get " . $varInvoiceNumber . ";";// net total in kh  modifield by boe ;
    $cus_name_english_invd = "exec wc_invoice_get_inv_detail " . $varInvoiceNumber . ";";// Panha: take customer name from invoice detail

    $cus_name_khmer = mssql_query($customer_name_in_khmer) or die('A error occured: ' . mysql_error());

    $cus_khmer_name = mssql_fetch_array($cus_name_khmer);

    $customer_name_english = mssql_query($customer_name_in_english) or die('A error occured: ' . mysql_error());

    $cus_enlish_name = mssql_fetch_array($customer_name_english);
    $cus_english_invd = mssql_query($cus_name_english_invd) or die('A error occured: ' . mysql_error());
    $cus_enlish_name_invd = mssql_fetch_array($cus_english_invd);

    // address in khmer
    $cus_khmer_address = mssql_query($cus_add_khmer) or die('A error occured: ' . mysql_error());

    $cuskhmer_address = mssql_fetch_array($cus_khmer_address);
    // end
    // phone number
    $phone = "exec wc_get_cust_phone " . $varCustomerID . ";";
    $phone_number = mssql_query($phone) or die('A error occured: ' . mysql_error());

    $cus_phone = mssql_fetch_array($phone_number);
    // end phone
    //invoice number
    $invioce_number = "exec wc_invoice_print " . $varInvoiceNumber . ";";
    $inv_num = mssql_query($invioce_number) or die('A error occured: ' . mysql_error());

    $inv_number = mssql_fetch_array($inv_num);

    // net total in kh : modifield by boe
    $net_total_kh = mssql_query($net_total_in_kh) or die('A error occured: ' . mysql_error());
    $net_total_kh_invd = mssql_fetch_array($net_total_kh);
    //end invoice
    $result = mssql_query($query) or die('A error occured: ' . mysql_error());

    // @Tony: this library require absolute path to resource files such as css, js, images
    // thus we need to declare global for multiple usage
    $package_path = "/opt/csm/report/ISOreport/invoicePDF/";

    // start render html
    $html = '
<!DOCTYPE html>
<html>
<head>
    <title>Print report</title>
    <meta charset="utf-8">
    <link rel="stylesheet" href="' . $package_path . 'css/table.css">
    <style>
        @page {
            margin: 40px;
        }
    </style>
</head>

<body style="margin-top:0">
';

    //start table header
    if ($template == "ezecom") {
        $html .= '
            <table border="0" width="100%">
                <tbody>
                <tr>
                    <td width="50%" valign="top">
                        <table  width="100%">
                            <tr>
                                <td width="50%">
                                    <img style="border: 0px solid ; float: left; width: 250px; height: 40px; margin-top:20px" alt="Logo" 
                                    src="' . $package_path . 'images/ezecom_bs2.jpg">
                                </td>        
                                <td width="50%">
                                    <p style="border: 0px solid ; float: left; width: 150px; height: 30px;"> </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                    <td align="right" valign="bottom" width="50%">
                        <span class="small">
                            <img style="width: 600px; height: 180px;" alt="address" src="' . $package_path . 'images/address_ezecom_new.jpg">
                        </span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" width="100%">
                        <hr>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" align="center">
                        <span style="font-family: khmerfef2; font-size: 25px;">វិក្កយបត្រអាករ</span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" align="center">
                        <small style="font-family: arial; font-size: 20px;">TAX INVOICE</small>
                    </td>
                </tr>
                </tbody>
            </table>';


    }
    if ($template == "gpon") {

        $html .= '
            <table border="0" width="100%">
                <tbody>
                <tr>
                    <td width="50%" valign="top">
                        <span class="small">
                            <img style="width: 300px;" alt="Logo" src="' . $package_path . 'images/telcotech_logo.jpg">
                        </span>
                    </td>
                    <td align="right" valign="bottom" width="50%">
                        <span class="small">
                            <img style="width: 300px;" alt="address" src="' . $package_path . 'images/address_tel.jpg">
                        </span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" width="100%">
                        <hr>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" align="center">
                        <span style="font-family: khmerfef2; font-size: 25px;">វិក្កយបត្រអាករ</span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" align="center">
                        <small style="font-family: arial; font-size: 20px;">TAX INVOICE</small>
                    </td>
                </tr>
                </tbody>
            </table>';
        //end table header
    }
    if ($template == "wholesale") {

        $html .= '
            <table border="0" width="100%">
                <tbody>
                <tr>
                    <td width="50%" valign="top">
                        <span class="small">
                            <img style="width: 300px;" alt="Logo" src="' . $package_path . 'images/ezecom_telcotech_logo.jpg">
                        </span>
                    </td>
                    <td align="right" valign="bottom" width="50%">
                        <span class="small">
                            <img style="width: 300px;" alt="address" src="' . $package_path . 'images/address_tel.jpg">
                        </span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" width="100%">
                        <hr>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" align="center">
                        <span style="font-family: khmerfef2; font-size: 25px;">វិក្កយបត្រអាករ</span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" align="center">
                        <small style="font-family: arial; font-size: 20px;">TAX INVOICE</small>
                    </td>
                </tr>
                </tbody>
            </table>';
        //end table header
    }

    //start table customer info
    $html .= '<table cellspacing="0" cellpadding="5px" width="100%" style="margin: 0px;">';
    $html .= '<tbody>';
    $html .= '
    <tr>
        <td style="font-family: khmerfef1; font-size: 12px; width:25%; font-weight: bold;"><span>អតិថិជន/Customer:</span></td>
        <td style="width:55%;"></td>
        <td style="font-family: khmerfef1; font-size: 12px; width: 20%;">លេខរៀងវិក្កយបត្រ៖ <br> Invoice Number:</td>
        <td>' . $inv_number['id'] . ' </td> 
    </tr>';

    $date = date_create_from_format('Y.m.d', $cus_enlish_name['transaction_date']);
    $html .= '
    <tr>
        <td style="font-family: khmerfef1; font-size: 12px; width:25%;">ឈ្មោះក្រុមហ៊ុន ឬ អតិថិជន៖ <br/> Customer Name:</td>
        <td style="font-family: khmerfef1; font-size: 12px; width:55%;"> ' . $cus_khmer_name['name_in_khmer'] . ' <br/> ' . $cus_enlish_name_invd['description'] . '</td>
        <td style="font-family: khmerfef1; font-size: 12px; width:20%;">កាលបរិច្ឆេទ៖ <br/>Issued on:</td>
        <td>' . date_format($date, 'd/m/Y') . '</td>
    </tr>
    ';
    $html .= '
    <tr>
        <td style="font-family: khmerfef1; font-size: 12px; width:25%;">លេខរៀងអតិថិជន៖ <br/>Customer ID</td>
        <td style="font-family: khmerfef1; font-size: 12px; width:55%;">' . $cus_enlish_name['customer_id'] . '</td>
    </tr>
    ';
    $html .= '
    <tr>
        <td style="font-family: khmerfef1; font-size: 12px; width:25%;">អាសយដ្ឋាន​អតិថិជន៖ <br/> Customer Address</td>
        <td style="font-family: khmerfef1; font-size: 12px; width:55%;">' . @$cuskhmer_address['addr_in_khmer'] . '<br>' .
        $cus_enlish_name['vat_address'] . '</td>
    </tr>
    ';
    $html .= '
    <tr>
        <td style="font-family: khmerfef1; font-size: 12px; width:25%;">ទូរស័ព្ទលេខ៖ <br> Telephone Number</td>
        <td style="font-family: khmerfef1; font-size: 12px; width:55%;">' . $cus_phone['phone'] . '</td>
    </tr>
    ';
    $html .= '
    <tr>
        <td style="font-family: khmerfef1; font-size: 12px; width:25%;">លេខអត្តសញ្ញាណកម្ម អតប៖ <br/> Customer VAT</td>
        <td style="font-family: khmerfef1; font-size: 12px; width:55%;">' . $cus_enlish_name['vat_number'] . '</td>
    </tr>
    ';
    $html .= '</tbody>';
    $html .= '</table>';
    //end table customer info

    //start invoice item
    $html .= '<div style="margin-top: 10px;">';
    $html .= '<table id="A45" width="100%" cellspacing="1" cellpadding="3" class="list" border="1" style="font-family: khmerfef1; font-size: 12px;">';
    $html .= '<thead>';
    $html .= '<tr>';

    $html .= '<th>ល.រ<br>N<sup>o</sup></th>';
    $html .= '<th align="center">បរិយាយមុខទំនិញ<br>Description</th>';
    $html .= '<th align="center">បរិមាណ<br>Quantity</th>';
    $html .= '<th align="center">ថ្លៃឯកតា<br>Unit Price</th>';
    $html .= '<th align="center">ថ្លៃទំនិញ<br>Price</th>';

    $html .= '</tr>';
    $html .= '</thead>';
    $html .= '<tbody>';
    while ($records = mssql_fetch_array($result)) {
        $html .= '<tr>';
        $html .= '<td align="left">' . $records['Number'] . '</td>';
        $html .= '<td align="left">' . $records['description'] . '</td>';
        $html .= '<td align="right">' . $records['quantity'] . '</td>';

        $str = $records['Number'];
        $get_num = str_replace(' ', '_', $str);
        $get_numm = str_replace('&nbsp;', 'table', $get_num);

        if (toMoney($records['unit_price']) == "$0.00") {
            $html .= '<td align="right" id="row' . $get_numm . '"></td>';
        } else {
            $html .= '<td align="right" id="row' . $get_numm . '">' . toMoney($records['unit_price']) . '</td>';
        }

        if ($records['description'] == "&nbsp;") {
            $html .= '<td class="small" align="right" id="last' . $get_numm . '"></td>';
        } else {
            //$html .= '<td class="small" align="right" id="last' . $get_numm . '">' . toMoney($records['price']) . '</td>';
            if ($records['Number'] == 1) {
                $html .= '<td class="small" align="right" id="last' . $get_numm . '"></td>';
            } else {
                $html .= '<td class="small" align="right" id="last' . $get_numm . '">' . toMoney($records['price']) . '</td>';
            }
        }
        $html .= '</tr>';
    }
    $html .= '</tbody>';
    $html .= '</table>';
    $html .= '</div>';
    // end invoice item


//  invoice total
    $html .= '<table border="0"> style="width:100%;font-size:23px !important;"';
    $html .= '<tr>';
    $html .= '<td align="left" style="font-family: khmerfef1; font-size: 12px;width:70%">';

    $html .= '<table>';
    $html .= '<tr><td style="font-size:23px;font-family: khmerfef1;"><strong>សម្គាល់ / Note:</strong></td></tr>';
    if ($template == "ezecom") {
        $html .= '<tr><td style="font-size:23px;"><span style="font-size:23px;font-family: khmerfef1;">១ - សូមបង់ប្រាក់ជូនទៅក្រុមហ៊ុន  អុីហ្សីខម</span> <br>';
        $html .= 'Please make payment to "Ezecom Co Ltd"<br></td></tr>';
    } else {
        $html .= '<tr><td style="font-size:23px;"><span style="font-size:23px;font-family: khmerfef1;">១ - សូមបង់ប្រាក់ជូនទៅក្រុមហ៊ុន  តែលកូថេក</span> <br>';
        $html .= 'Please make payment to "Telcotech Ltd"<br></td></tr>';
    }

    $html .= '<tr><td style="width:70%;font-size:23px;"><span style="font-size:23px;font-family: khmerfef1;">សំរាប់មធ្យោបាយក្នុងការបង់ប្រាក់ផ្សេងទៀត សូមចូលទៅកាន់គេហទំព័រ <br></span> "https://www.ezecom.com.kh/customer-service#payment"<br/>For more payment option please go to "https://www.ezecom.com.kh/customer-service#payment"</td></tr>';
    $html .= '<tr><td style="font-size:23px;"><span style="font-size:23px;font-family: khmerfef1;">២ - សូមបង្ហាញលេខសំគាល់របស់លោកអ្នក នៅពេលបង់ប្រាក់​</span><br>';
    $html .= 'Please state your Customer ID number when you make payment​</span></td></tr>';
    $html .= '<tr><td style="font-size:23px;"><span style="font-size:23px;font-family: khmerfef1;">៣ - សូមអញ្ជើញមកបង់ប្រាក់ក្នុងរយះពេល ៧ ថ្ងៃ បន្ទាប់ពីថ្ងៃចេញវិក្កយបត្រនេះ ​</span><br>';
    $html .= 'Invoice due within 7 days after the date​​ of invoice issued</td></tr>';

    $html .= '<tr><td style="font-size:23px;"><span style="font-size:23px;font-family: khmerfef1;">៤ - វិក្កយបត្រនេះចេញដោយប្រព័ន្ធស្វ័យប្រវត្ដិ ដោយមិនតំរូវអោយមានហត្ថលេខា​នោះទេ​</span></br></br>';
    $html .= '<br>This is an electronically generated invoice, no authorized signature is required.</td></tr>';

    /*      add note with tax for exchange rate
                      Modifield by Lay BOE
    */

    $html .= '<tr><td style="font-size:23px;"><span style="font-size:23px;font-family: khmerfef1;">៥ - អត្រាប្តូរប្រាក់ពីដុល្លាទៅប្រាក់រៀល គឺជាអត្រាដែលបានមកពីអត្រាប្តូរប្រាក់របស់អគ្គនាយកដ្ឋានពន្ធដារ ពីខែមុន។ 1ដុល្លា= ' . $net_total_kh_invd['exchange_rate'] . ' រៀល​</span></br></br>';
    $html .= '<br> Exchange rate of US dollar to Riel currency is the rate come from the exchange rate published by General Department of Taxation
            from the last month. 1 USD= ' . $net_total_kh_invd['exchange_rate'] . ' Riel</td></tr>';

    //   end modifield
    $html .= '</table>';

    $html .= "</td>";


    $html .= '<td style="width:27%;">';
    $html .= '<table style="float: right;border:1px solid #888888;background-color:rgb(227,227,227);width:100%    " cellspacing="2" cellpadding="0">';
    $html .= '<tbody>';
    $html .= '<tr></tr>';
    $html .= '<tr>';
    $html .= '<td style="font-family: khmerfef1; font-size: 23px;"><strong style="font-size:23px">សរុប</strong></td>';
    $html .= '<td rowspan="2" align="right" style="border-bottom: 1px solid rgba(51,51,51,0.54);font-size:23px;">$' .
        toMoney_format($inv_number['net_value']) .
        '</td>';
    $html .= '</tr>';
    $html .= '<tr style="border-collapse: collapse">';
    $html .= '<td style="border-bottom: 1px solid rgba(51, 51, 51, 0.54);"><span style="font-size:23px">Sub Total</span></td>';
    $html .= '</tr>';
    $html .= '<tr></tr>';
    $html .= '<tr>';
    $html .= '<td style="font-family: khmerfef1; font-size: 22px;"><strong style="font-size:23px">អាករលើតម្លៃបន្ថែម ១០%</strong></td>';
    $html .= '<td rowspan="2" align="right" style="border-bottom: 1px solid rgba(51,51,51,0.54);font-size:23px;">$' . toMoney_format($inv_number['vat']) .
        '</td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td style="border-bottom: 1px solid rgba(51, 51, 51, 0.54);"><span style="font-size:22px">VAT (10%)</span></td>';
    $html .= '</tr>';
    $html .= '<tr></tr>';
    $html .= '<tr style="border-bottom: 1px solid rgba(51,51,51,0.54);">';
    $html .= '<td style="font-family: khmerfef1; font-size: 23px;"><strong style="font-size:23px">សរុបរួម</strong></td>';
    $html .= '<td rowspan="2" align="right" style="border-bottom: 1px solid rgba(51,51,51,0.54);font-size:22px;">$' . toMoney_format($inv_number['value']) . '</td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td style="border-bottom: 1px solid rgba(51, 51, 51, 0.54);"><span style="font-size:22px">Grand Total (USD)</span></td>';
    $html .= '</tr>';

    // Modify by boe show total in kh

// 15-07-2019 update by BOE
//if exchange reate == null set net value to N/A
    $net_value_kh = '';
    if ($net_total_kh_invd['exchange_rate'] == null) {
        $net_value_kh = "N/A";
    } else {
        $net_value_kh = number_format($net_total_kh_invd['net_value_khmer']) . "<span>៛</span>";
    }
    if ($net_total_kh_invd['net_value_khmer'] > 0) {
        $html .= '<tr>';
        $html .= '<td style="font-family: khmerfef1; font-size: 23px;"><strong style="font-size:23px">សរុបជារៀល</strong></td>';
        $html .= '<td rowspan="2" align="right" style="font-family: khmerfef1;font-size:22px;">' . $net_value_kh . '</td>';
        $html .= '</tr>';
        $html .= '<tr>';
        $html .= '<td><span style="font-size:22px">Grand Total (KHR)</span></td>';
        $html .= '</tr>';
    }// End Modify

    $html .= '</tbody>';
    $html .= '</table>';

    // input stamp
    $html .= '<br/><div>';
    if ($template == "ezecom") {
        $html .= '<img alt="Stamp" src="' . $package_path . 'images/ezecom_stamp.jpg">';
    } else {
        $html .= '<img alt="Stamp" src="' . $package_path . 'images/Stamp-Image-TCT0001.jpg">';
    }
    $html .= '</div>';
    // end stamp

    $html .= '</td>';

    $html .= '</tr>';
    $html .= '</table>';

//==============================================================

    require_once __DIR__ . '/vendor/autoload.php';

    $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
    $mpdf = new mPDF('utf-8', 'A4');
    $mpdf->WriteHTML($html);

    $mpdf->Output($target_dir .date_format($date, 'Y-m-d').'_'. $varInvoiceNumber . '.pdf', 'F'); // for production


}// end function generatePDFinvoice

?>
