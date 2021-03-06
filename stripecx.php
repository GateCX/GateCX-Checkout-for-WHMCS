<?php
/*
**********************************************

     *** GateCX Checkout for WHMCS ***

File:					stripecx.php
File version:			0.0.3
Date:					27-10-2015

Copyright (C) NetDistrict 2014 - 2016
All Rights Reserved
**********************************************
*/

use Illuminate\Database\Capsule\Manager as Capsule;

function stripecx_config() {
	
	$pdo = Capsule::connection()->getPdo();
	
	$result = $pdo->query("SHOW TABLES LIKE 'stripecx_transactions'");
	$row = $result->fetch(PDO::FETCH_ASSOC);
		
	if($row!=false) $db_table = 'Uninstall';
	else $db_table = 'Install';

	$configarray = array(
		"FriendlyName" => array("Type" => "System", "Value"=>"GateCX"),
		"private-key" => array("FriendlyName" => "Private Key", "Type" => "text", "Size" => "20", ),
		"public-key" => array("FriendlyName" => "Public Key", "Type" => "text", "Size" => "20", ),
		"validatezip" => array("FriendlyName" => "Validate ZIP Code", "Type" => "yesno", "Description" => "Specify whether Checkout should validate the billing ZIP code.", ),
		"rememberme" => array("FriendlyName" => "Remember Me", "Type" => "yesno", "Description" => "Specify whether to include the option to \"Remember Me\" for future purchases (true or false).", ),
		"sendfailedmail" => array("FriendlyName" => "Send Failed Mail", "Type" => "yesno", "Description" => "Send an e-mail to the client when the credit card payment fails (recommended).", ),
		"image-url" => array("FriendlyName" => "Image URL", "Type" => "text", "Size" => "100%", "Description" => "<br />A URL pointing to a square image of your brand or product. The recommended minimum size is 128x128px",),
		"setup" => array("FriendlyName" => "Transaction table", "Description" => "<input class=\"btn btn-default btn-sm\" type=button onClick=\"location.href='?stripecx=".$db_table."'\" value='".$db_table."'> Tables must be installed in order to use this gateway.<br /><small style=\"float:right;\">Copyright &copy; 2014 - 2016 <a href=\"http://www.netdistrict.co.uk\">NetDistrict</a>. All rights reserved.</small>",),
    );
	return $configarray;
	
}

function stripecx_link($params) {
	global $whmcs;
	
	$pdo = Capsule::connection()->getPdo();
	
	# Invoice Variables
	$invoiceid = $params['invoiceid'];
	$description = $params["description"];
    $amount = $params['amount']; # Format: ##.##
    $currency = $params['currency']; # Currency Code

	# Client Variables
	$email = $params['clientdetails']['email'];
	$postcode = $params['clientdetails']['postcode'];

	# System Variables
	$companyname = $params['companyname'];
	$systemurl = $params['systemurl'];
	
	# Config Options
	if ($params['validatezip'] == 'on') { $zipCodeValidation = 'true'; } else  { $zipCodeValidation = 'false'; }
	if ($params['rememberme'] == 'on') { $RememberMe = 'true'; } else  { $RememberMe = 'false'; }
	
	# Redirect If Checkout From Cart
	$cart = $_REQUEST['a'];
	if ($cart == 'complete') {
		header('Location: viewinvoice.php?id='.$invoiceid.'&stripecx');
	} 
	
	# Auto-Start StripeCX?
	if (isset($_REQUEST['stripecx'])) {
		$start_sx = true;	
	} else {
		$start_sx = false;	
	}
	
	$sql = "SELECT * FROM stripecx_transactions WHERE invoice_id = '$invoiceid'";
	$result = $pdo->query($sql);
	$row = $result->fetch(PDO::FETCH_ASSOC);
	
	if ($row){
		$pending_trans = "
		<script>
			$(document).ready(function() {
				$('select[name=\"gateway\"]').attr('disabled', 'disabled');
				$('#StripeCXbutton').attr('disabled', 'disabled');
				$('#StripeCXbutton').text('".$whmcs->get_lang('clientareapending')."');
			});
			
			function submit() {
					
			}
		</script>
		
		";
	} else { $pending_trans = NULL; }

	# Enter your code submit to the gateway...
	
	$code = "
	<script src=\"https://checkout.stripe.com/v2/checkout.js\"></script>
	<script src=\"assets/js/jquery.js\"></script>
	
	".$pending_trans."
	<form>
	<button id=\"StripeCXbutton\">".$params['langpaynow']."</button>
</form>
	<script>
	$ = jQuery;		
	
		var handler = StripeCheckout.configure({
			key: '".$params['public-key']."',
			image: '".$params['image-url']."',
			currency: '".$currency."',
			email: '".$email."',
			zipCode: '".$zipCodeValidation."',
			allowRememberMe: '".$RememberMe."',
			description: '".str_replace($companyname." - ","",$description)."',
			token: function(token) {
				// Use the token to create the charge with a server-side script.
				// You can access the token ID with `token.id`
				
				$('select[name=\"gateway\"]').attr('disabled', 'disabled');
				$('#StripeCXbutton').attr('disabled', 'disabled');
				$('#StripeCXbutton').text('".$whmcs->get_lang('clientareapending')."');
				
				storeTransaction('".$invoiceid."',token.id, '".$currency."', '".$amount*100 ."');
			}
		});
		
		document.getElementById('StripeCXbutton').addEventListener('click', function(e) {
			// Open Checkout with further options
			handler.open({
				name: '".$companyname."',
				description: '".str_replace($companyname." - ","",$description)."',
				amount: ".$amount*100 ."
			});
			e.preventDefault();
		});
		  
		function storeTransaction(sid,stoken,currency, amount) {
			  
			url = document.URL;
			url = url.substring(0, url.lastIndexOf('/') + 1);
			  
			$.ajax({
				data: { id: sid, token: stoken, currency: currency, amount: amount },
				url: url+'modules/gateways/stripecx/callback.php',
				method: \"POST\",
				success: function(data) {
				}
			});
		}
	</script>
	";
	
	if ($start_sx == true) {
		$code = $code ."
		<script>
			document.getElementById('StripeCXbutton').click();
		</script>
		";	
	}
	
	return $code;
}

function stripecx_refund($params) {

    # Invoice Variables
	$invoiceid = $params['invoiceid'];
	$transid = $params['transid']; # Transaction ID of Original Payment
	$amount = $params['amount']; # Format: ##.##
    $currency = $params['currency']; # Currency Code

	# Perform Refund Here & Generate $results Array, eg:
	$refund= json_decode(stripecx_createrefund($params, $transid, $amount));
	
	$refundid = $refund->refunds->data[0]->id;
	$logdata = "Transaction ID: ".$refundid."\r\nInvoice ID: ".$invoiceid."\r\nStatus: charge.send";
	
	# Return Results
	if ($refundid != NULL) {
		return array("status"=>"success","transid"=>$refundid,"rawdata"=>$logdata);
	} else {
		return array("status"=>"error","rawdata"=>$refund->error->message);
	}
}

function stripecx_createrefund($params,$transid,$amount)
{	
	// Check if custom amount is set
	if ($amount != NULL) $data = '&amount='.$amount*100;
	else $data = NULL;

	// Send refund request
    $ch = curl_init('https://api.stripe.com/v1/charges/'.$transid.'/refund');

	curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Client: GateCX v0.0.3'));
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_USERPWD, $params['private-key'] . ":" . NULL);
	curl_setopt($ch, CURLOPT_TIMEOUT, 5);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
	curl_setopt($ch, CURLOPT_CAINFO, realpath(dirname(__FILE__) . "/stripecx/cacert.pem"));

	$return = curl_exec($ch);
	curl_close($ch);
	
    return $return;
}

if ($_REQUEST['stripecx'] == 'Install') {
	// Install required database table
	$pdo = Capsule::connection()->getPdo();
	
	$pdo->beginTransaction();
		 
	try {			
		$query = "CREATE TABLE IF NOT EXISTS `stripecx_transactions` (
			`id` int(5) NOT NULL AUTO_INCREMENT,
			`invoice_id` int(32) NOT NULL,
			`transaction_id` varchar(512) NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `id` (`id`)
		) ENGINE=InnoDB  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;";
				
		$statement = $pdo->prepare($query);
		$statement->execute();
	
		// COMMIT QUERYS			 
		$pdo->commit();
			
	} catch (\Exception $e) {
			
		$pdo->rollBack();			
		logActivity('Error during GateCX activation: '.$e->getMessage());
	}
}

if ($_REQUEST['stripecx'] == 'Uninstall') {
	// Remove required database table
	$pdo = Capsule::connection()->getPdo();

	$pdo->beginTransaction();
		 
	try {	
			
		$query = "DROP TABLE IF EXISTS `stripecx_transactions`;";	
					
		$statement = $pdo->prepare($query);
		$statement->execute();
		
		// COMMIT QUERYS			 
		$pdo->commit();
			
	} catch (\Exception $e) {
			
		$pdo->rollBack();			
		logActivity('Error during GateCX deactivation: '.$e->getMessage());
		
	}

}
?>