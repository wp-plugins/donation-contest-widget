<?php
include_once('../../../wp-load.php');

$paypalurl = parse_url("https://www.paypal.com/cgi-bin/webscr");
$request = "cmd=_notify-validate";
foreach ($_POST as $key => $value)
{
	$value = urlencode(stripslashes($value));
	$request .= "&".$key."=".$value;
}
$header = "POST ".$paypalurl["path"]." HTTP/1.0\r\n";
$header .= "Content-Type: application/x-www-form-urlencoded\r\n";
$header .= "Content-Length: ".strlen($request)."\r\n\r\n";
$handle = fsockopen("ssl://".$paypalurl["host"], 443, $errno, $errstr, 30);
if ($handle)
{
	fputs ($handle, $header.$request);
	while (!feof($handle))
	{
		$result = fgets ($handle, 1024);
	}
	if (substr(trim($result), 0, 8) == "VERIFIED")
	{		$item_number = stripslashes($_POST['item_number']);
		$item_name = stripslashes($_POST['item_name']);
		$payment_status = stripslashes($_POST['payment_status']);
		$transaction_type = stripslashes($_POST['txn_type']);
		$seller_paypal = stripslashes($_POST['business']);
		$payer_paypal = stripslashes($_POST['payer_email']);
		$gross_total = stripslashes($_POST['mc_gross']);
		$mc_currency = stripslashes($_POST['mc_currency']);
		$first_name = stripslashes($_POST['first_name']);
		$last_name = stripslashes($_POST['last_name']);
		
		if ($transaction_type == "web_accept" && $payment_status == "Completed")
		{
			if (strtolower($seller_paypal) != strtolower($donationcontest->paypal_id)) $payment_status = "Unrecognized";
			if ($mc_currency != $donationcontest->currency) $payment_status = "Unrecognized";
		}
		$sql = "INSERT INTO ".$wpdb->prefix."dc_transactions (
			payer_name, payer_email, gross, currency, payment_status, transaction_type, details, created) VALUES (
			'".mysql_real_escape_string($first_name).' '.mysql_real_escape_string($last_name)."',
			'".mysql_real_escape_string($payer_paypal)."',
			'".floatval($gross_total)."',
			'".$mc_currency."',
			'".$payment_status."',
			'".$transaction_type."',
			'".mysql_real_escape_string($request)."',
			'".time()."'
		)";
		$wpdb->query($sql);
		if ($transaction_type == "web_accept")
		{
			if ($payment_status == "Completed")
			{
				$tags = array("{first_name}", "{last_name}", "{payer_email}", "{donation_gross}", "{donation_currency}", "{transaction_date}");
				$vals = array($first_name, $last_name, $payer_paypal, $gross_total, $mc_currency, date("Y-m-d H:i:s")." (server time)");

				$body = str_replace($tags, $vals, $donationcontest->thankyou_email_body);
				$mail_headers = "Content-Type: text/plain; charset=utf-8\r\n";
				$mail_headers .= "From: ".$donationcontest->from_name." <".$donationcontest->from_email.">\r\n";
				$mail_headers .= "X-Mailer: PHP/".phpversion()."\r\n";
				wp_mail($payer_paypal, $donationcontest->thankyou_email_subject, $body, $mail_headers);

				$body = str_replace($tags, $vals, "Dear Administrator,\r\n\r\nWe would like to inform you that {first_name} {last_name} ({payer_email}) donated {donation_gross}{donation_currency} on {transaction_date}.\r\n\r\nThanks,\r\nDonation Contest Widget");
				$mail_headers = "Content-Type: text/plain; charset=utf-8\r\n";
				$mail_headers .= "From: ".$donationcontest->from_name." <".$donationcontest->from_email.">\r\n";
				$mail_headers .= "X-Mailer: PHP/".phpversion()."\r\n";
				wp_mail($donationcontest->admin_email, "Donation Received", $body, $mail_headers);
			}
			else if ($payment_status == "Failed" || $payment_status == "Pending" || $payment_status == "Processed" || $payment_status == "Unrecognized")
			{
				$tags = array("{first_name}", "{last_name}", "{payer_email}", "{donation_gross}", "{donation_currency}", "{payment_status}", "{transaction_date}");
				$vals = array($first_name, $last_name, $payer_paypal, $gross_total, $mc_currency, $payment_status, date("Y-m-d H:i:s")." (server time)");

				$body = str_replace($tags, $vals, "Dear Administrator,\r\n\r\nWe would like to inform you that {first_name} {last_name} ({payer_email}) donated {donation_gross}{donation_currency} on {transaction_date}. This is non-completed transaction.\r\nPayment ststus: {payment_status}\r\n\r\nDonation was not included into donations list.\r\n\r\nThanks,\r\nDonation Contest Widget");
				$mail_headers = "Content-Type: text/plain; charset=utf-8\r\n";
				$mail_headers .= "From: ".$donationcontest->from_name." <".$donationcontest->from_email.">\r\n";
				$mail_headers .= "X-Mailer: PHP/".phpversion()."\r\n";
				wp_mail($donationcontest->admin_email, "Non-completed donation received", $body, $mail_headers);
			}
		}
	}
}
?>