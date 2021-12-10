<?php

date_default_timezone_set('Asia/Bangkok');
ini_set("memory_limit", "1024M");
parse_str($argv[1], $output);  // for production
//print_r($output);
$base_path = $output['path'];  // for production
//$base_path = '/opt/csm/report/ISOreport/test_pdf';  // for testing
//echo $base_path;
$current_day = $output['day'];  // for production
//$current_day = '2020.09.07';  // for testing

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

// include write log function
$package_path = "/opt/csm/report/ISOreport/";
include($package_path . 'audit_log.php');

// start DB connection
$server = 'hostname';
$mydb = 'database';
// Connect to MSSQL
//start function generate invoice PDF
$link = mssql_connect($server, 'username', 'password');
$selected = mssql_select_db($mydb, $link) or die("Couldn't open database $mydb");



// start DB connection for logs
$dbname = 'db';
$dbuser = 'user';
$dbpass = 'password';
$dbhost = 'host';

$report2_dmc_submit_conn = mysqli_connect($dbhost, $dbuser, $dbpass) or die("Unable to Connect to '$dbhost'");
mysqli_select_db($report2_dmc_submit_conn, $dbname) or die("Could not open the db '$dbname'");

// 1. Daily Role Over: call function to generate PDF invoice
// query invoice to loop in here

//$q_daily_invoice = 'exec dp_xt_list_invoices_to_send;';
$q_daily_credit_note = "exec wfa_daily_credit_notes_issued '" . $current_day . "'"; // for production
//$q_daily_invoice = "exec dp_xt_list_invoices_to_pdf '2020.06.06'"; // for testing
$daily_credit_note = mssql_query($q_daily_credit_note);

//print_r(mssql_num_rows($daily_credit_note));
//exit();

// query daily credit notes and loop to generate pdf files
$gen_cn = 0;
while ($row = mssql_fetch_array($daily_credit_note)) {
    $txn_number = $row[0];
    $customer_id = $row[1];

    echo $customer_id . "_" . $txn_number . ".pdf\n";
   generateCreditNotePdf($customer_id, $txn_number, $base_path);

    // tony: write log on each credit note:
    $q = "exec wc_transaction_print_get " . $txn_number . ";";
    $r = mssql_query($q);
    $cn_detail = mssql_fetch_array($r);
    $cn_num = "CN".$txn_number;

    $status = 0 ;
    if (file_exists($base_path . date_create_from_format("Y.m.d", $current_day)->format("Y-m-d") . '_' . $cn_num . '.pdf')) {
        $log_content = time() . ",generate retail-enterprise PDF credit note," . $customer_id . "," . $cn_num . ",\$" . $cn_detail['value'] . ",1,success";
        $gen_cn = $gen_cn + 1;
        $status = 1;
    } else {
        $log_content = time() . ",generate retail-enterprise PDF credit note," . $customer_id . "," . $cn_num . ",\$" . $cn_detail['value'] . ",0,fail";
    }


    // ====================== for store logs and invoice less than 0 ====================================================
    $log = "INSERT INTO bs2_credit_note_log (customer_id,credit_number,amount,status,issue_date) 
                    VALUES ($customer_id , '".$cn_num."' , ".$cn_detail['value'].", $status , '".date_create_from_format("Y.m.d", $current_day)->format("Y-m-d")."')" ;
    // insert to database logs
    mysqli_query($report2_dmc_submit_conn , $log);

    // ====================== end store logs and invoice less than 0 ====================================================

    write_log($log_content, $base_path, $current_day);

}
echo "done\n";

mysqli_close($report2_dmc_submit_conn);

// 2. specify invoice to re-generate PDF file for testing
/*generatePDFinvoice(20685, 439999, 'gpon', '/opt/csm/report/ISOreport/test_pdf');
echo "done\n";
exit();*/

//create function to generate PDF invoice
function generateCreditNotePdf($varCustomerID, $varTransactionId, $target_dir)
{
    $query = "exec ws_credit_note_print_detail " . $varTransactionId;

    $customer_name_in_khmer = "exec wc_get_cust_name_khmer " . $varCustomerID . ";";
    $customer_name_in_english = "exec wc_transaction_print_get " . $varTransactionId . ";";
    $cus_add_khmer = "exec wc_get_cust_addr_khmer " . $varCustomerID . ";";
    $net_total_in_kh = "exec ws_credit_note_total_amount_kh " . $varTransactionId . ";";

    $cus_name_khmer = mssql_query($customer_name_in_khmer) or die('A error occured: ' . mysql_error());

    $cus_khmer_name = mssql_fetch_array($cus_name_khmer);

    $customer_name_english = mssql_query($customer_name_in_english) or die('A error occured: ' . mysql_error());

    $cus_enlish_name = mssql_fetch_array($customer_name_english);

    // address in khmer
    $cus_khmer_address = mssql_query($cus_add_khmer) or die('A error occured: ' . mysql_error());

    $cuskhmer_address = mssql_fetch_array($cus_khmer_address);
    // end address in khmer
    // phone number
    $phone = "exec wc_get_cust_phone " . $varCustomerID . ";";
    $phone_number = mssql_query($phone) or die('A error occured: ' . mysql_error());

    $cus_phone = mssql_fetch_array($phone_number);
    // end phone
    //transaction number
    $transaction_id = "exec ws_credit_note_total_amount " . $varTransactionId . ";";
    $tran_id = mssql_query($transaction_id) or die('A error occured: ' . mysql_error());

    $transac_id = mssql_fetch_array($tran_id);

    // net total in kh : modified by boe
    $net_total_kh = mssql_query($net_total_in_kh) or die('A error occured: ' . mysql_error());
    $net_total_kh_invd = mssql_fetch_array($net_total_kh);
    //end transaction
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
                        <span style="font-family: khmerfef2; font-size: 25px;">លិខិតឥណទាន</span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" align="center">
                        <small style="font-family: arial; font-size: 20px;">Credit Note</small>
                    </td>
                </tr>
                </tbody>
            </table>';


    //start table customer info
    $html .= '<table cellspacing="0" cellpadding="5px" width="100%" style="margin: 0px;">';
    $html .= '<tbody>';
    $html .= '
    <tr>
        <td style="font-family: khmerfef1; font-size: 12px; width:25%; font-weight: bold;"><span>អតិថិជន/Customer:</span></td>
        <td style="width:55%;"></td>
        <td style="font-family: khmerfef1; font-size: 12px; width: 20%;">លេខរៀងឥណទាន៖ <br> Credit Note No:</td>
        <td>CN' . $cus_enlish_name['id'] . ' </td> 
    </tr>';

    $date = date_create_from_format('d M Y H:i:s', $cus_enlish_name['transaction_date']);

    $html .= '
    <tr style="border-spacing: 2px;">
        <td style="font-family: khmerfef1; font-size: 12px; width:25%;">ឈ្មោះក្រុមហ៊ុន ឬ អតិថិជន៖ <br/> Customer Name:</td>
        <td style="font-family: khmerfef1; font-size: 12px; width:55%;"> ' . $cus_khmer_name['name_in_khmer'] . ' <br/> ' . $cus_enlish_name['customer_name'] . '</td>
        <td style="font-family: khmerfef1; font-size: 12px; width:20%;">កាលបរិច្ឆេទ៖ <br/>Issued on:</td>
        <td>' . date_format($date, "Y.m.d") . '</td>
    </tr>
    ';
    $html .= '
    <tr style="border-spacing: 2px;">
        <td style="font-family: khmerfef1; font-size: 12px; width:25%;">លេខរៀងអតិថិជន៖ <br/>Customer ID</td>
        <td style="font-family: khmerfef1; font-size: 12px; width:55%;">' . $cus_enlish_name['customer_id'] . '</td>
    </tr>
    ';
    $html .= '
    <tr style="border-spacing: 2px;">
        <td style="font-family: khmerfef1; font-size: 12px; width:25%;">អាសយដ្ឋាន​អតិថិជន៖ <br/> Customer Address</td>
        <td style="font-family: khmerfef1; font-size: 12px; width:55%;">' . @$cuskhmer_address['addr_in_khmer'] . '<br>' .
        $cus_enlish_name['vat_address'] . '</td>
    </tr>
    ';
    $html .= '
    <tr style="border-spacing: 2px;">
        <td style="font-family: khmerfef1; font-size: 12px; width:25%;">ទូរស័ព្ទលេខ៖ <br> Telephone No</td>
        <td style="font-family: khmerfef1; font-size: 12px; width:55%;">' . $cus_phone['phone'] . '</td>
    </tr>
    ';
    $html .= '
    <tr style="border-spacing: 2px;">
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

    $html .= '<th>ល.រ<br>N<sup>o</sup></th>'; // col#1
    $html .= '<th align="center">បរិយាយមុខទំនិញ<br>Description</th>'; // col#2
    $html .= '<th align="center">បរិមាណ<br>Quantity</th>'; // col#3
    $html .= '<th align="center">ថ្លៃឯកតា<br>Unit Price</th>'; // col#4
    $html .= '<th align="center">ថ្លៃទំនិញ<br>Price</th>'; // col#5

    $html .= '</tr>';
    $html .= '</thead>';
    $html .= '<tbody>';
    while ($records = mssql_fetch_array($result)) {
        $html .= '<tr>'; // start row
        $html .= '<td align="left">' . $records['No'] . '</td>'; // col#1
        $html .= '<td align="left">' . $records['description'] . '</td>'; // col#2
        $html .= '<td align="right"></td>'; // col#3

        // prepare html table row id to apply style
        $str = $records['No'];
        $get_num = str_replace(' ', '_', $str);
        $get_numm = str_replace('&nbsp;', 'table', $get_num);
        // end prepare html table row id

        $html .= '<td align="right" id="row' . $get_numm . '">' . toMoney($records['unit_price']) . '</td>'; // col#4
        $html .= '<td class="small" align="right" id="last' . $get_numm . '">' . toMoney($records['price']) . '</td>'; // col#5

        $html .= '</tr>'; // end row
    }
    $html .= '</tbody>';
    $html .= '</table>';
    $html .= '</div>';
    // end invoice item


//  invoice total
    $html .= '<table border="0">';
    $html .= '<tr>';

    $html .= '<td align="left" style="font-family: khmerfef1; font-size: 15px;width:70%;">';

    $html .= '<table style="border-spacing: 2px;margin-right: 15px;">';
    $html .= '<tr><td style="font-size:15px;font-family: khmerfef1;"><strong>សម្គាល់ / Note:</strong></td></tr>';
    $html .= '<tr><td style="font-size:15px;font-family: khmerfef1;"><span style="font-size:15px;font-family: khmerfef1;">១ - អត្រាប្តូរប្រាក់ពីដុល្លាទៅប្រាក់រៀល គឺជាអត្រាដែលបានមកពីអត្រាប្តូរប្រាក់របស់ធនាគារជាតិប្រចាំថ្ងៃ (យឺតមួយថ្ងៃ) ។ 1ដុល្លា= ' . $net_total_kh_invd['exchange_rate'] . ' រៀល​</span></br></br>';
    $html .= '<br>Exchange rate of US dollar to Riel currency is the rate come from the exchange rate published by NBC (National Bank of Cambodia) as daily (late one day). 1 USD=​​​ ' . $net_total_kh_invd['exchange_rate'] . ' Riel</td>';
    $html .= '</tr>';
    $html .= '</table>';

    $html .= '</td>';


    $html .= '<td style="width:27%;">';
    $html .= '<table style="float: right;border:1px solid #888888;background-color:rgb(227,227,227);width:100% ;margin-top: -4px;margin-right: -3px;" cellspacing="2" cellpadding="0">';
    $html .= '<tbody>';
    $html .= '<tr></tr>';
    $html .= '<tr style="border-spacing: 2px;">';
    $html .= '<td style="font-family: khmerfef1; font-size: 17px;"><strong style="font-size:17px;font-family: khmerfef1;">សរុប</strong></td>';
    $html .= '<td rowspan="2" align="right" style="border-bottom: 1px solid rgba(51,51,51,0.54);font-size:17px;">' . $transac_id['value_before_vat'] .
        '</td>';

    $html .= '</tr>';
    $html .= '<tr style="border-collapse: collapse">';
    $html .= '<td style="border-bottom: 1px solid rgba(51, 51, 51, 0.54);"><span style="font-size:17px">Sub Total</span></td>';
    $html .= '</tr>';
    $html .= '<tr></tr>';
    $html .= '<tr>';
    $html .= '<td style="font-family: khmerfef1; font-size: 17px;"><strong style="font-size:17px;font-family: khmerfef1;">អាករលើតម្លៃបន្ថែម ១០%</strong></td>';
    $html .= '<td rowspan="2" align="right" style="border-bottom: 1px solid rgba(51,51,51,0.54);font-size:17px;">' . $transac_id['vat'] .
        '</td>';

    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td style="border-bottom: 1px solid rgba(51, 51, 51, 0.54);"><span style="font-size:16px">VAT (10%)</span></td>';
    $html .= '</tr>';
    $html .= '<tr></tr>';
    $html .= '<tr style="border-bottom: 1px solid rgba(51,51,51,0.54);">';
    $html .= '<td style="font-family: khmerfef1; font-size: 17px;"><strong style="font-size:17px;font-family: khmerfef1;">សរុបរួម</strong></td>';
    $html .= '<td rowspan="2" align="right" style="border-bottom: 1px solid rgba(51,51,51,0.54);font-size:16px;">' . $transac_id['value_after_vat'] . '</td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td style="border-bottom: 1px solid rgba(51, 51, 51, 0.54);"><span style="font-size:16px">Grand Total (USD)</span></td>';
    $html .= '</tr>';

    $net_value_kh = '';
    if ($net_total_kh_invd['exchange_rate'] == null) {
        $net_value_kh = "N/A";
    } else {
        $net_value_kh = number_format($net_total_kh_invd['credit_khmer']) . "<span>៛</span>";
    }
    if ($net_total_kh_invd['credit_khmer'] > 0) {
        $html .= '<tr>';
        $html .= '<td style="font-family: khmerfef1; font-size: 17px;"><strong style="font-size:17px;font-family: khmerfef1;">សរុបរួម</strong></td>';
        $html .= '<td rowspan="2" align="right" style="font-family: khmerfef1;font-size:16px;">' . $net_value_kh . '</td>';
        $html .= '</tr>';
        $html .= '<tr>';
        $html .= '<td><span style="font-size:16px">Grand Total (KHR)</span></td>';
        $html .= '</tr>';
    }

    $html .= '</tbody>';
    $html .= '</table>';

    $html .= '</td>';

    $html .= '</tr>';
    $html .= '<tr >';

    // buyer
    $html .= '
                <td>
                    <table style="padding-top:11%;">
                            <tr>
                                <td style="text-align:left;">
                                
                            </tr>
                        
                            <tr>
                            <td><hr style="border:1px solid #000;"></td>
                        </tr>
                        <tr>
                            <td><strong style="font-family: khmerfef1;">ហត្ថលេខា និងឈ្មោះអ្នកទិញ</strong></td>
                        </tr>
                        <tr>
                            <td>Customer' . "'" . 's Signature & Name</td>
                        </tr>
                    </table>
                </td>
              ';
    //end buyer

    //seller
    $html .= '
                <td>
                    <table>
                        <tr>
                              <td style="text-align:left;"><img style="float: left;width:295px;height:125px;" alt="address" src="' . $package_path . 'images/ezecom_stamp.jpg"></td>
                        </tr>
                        <tr>
                            <td><b>Mr. Dy Chetra (CFO) </b></td>
                        </tr>
                        <tr>
                            <td><hr style="border:1px solid #000;"></td>
                        </tr>
                        <tr>
                            <td><strong style="font-family: khmerfef1;">ហត្ថលេខា និងឈ្មោះអ្នកលក់</strong></td>
                        </tr>
                        <tr>
                            <td>Seller' . "'" . 's Signature & Name</td>
                        </tr>
                    </table>
                </td>
              ';


    //end seller

    $html .= '</tr>';
    $html .= '</table>';

//==============================================================

    require_once __DIR__ . '/vendor/autoload.php';

    $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
    $mpdf = new mPDF('utf-8', 'A4');
    $mpdf->WriteHTML($html);
    $new_file_name = date_format($date, "Y-m-d").'_CN'.$varTransactionId;
    $mpdf->Output($target_dir . '/' . $new_file_name . '.pdf', 'F'); // for production


}// end function generatePDFinvoice

?>
